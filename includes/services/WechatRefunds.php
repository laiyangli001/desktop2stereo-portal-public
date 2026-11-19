<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Refund intentions and successful receipts are immutable; delivery leases are not payment proof. */
final readonly class WechatRefunds
{
    public function __construct(private PDO $db,private WechatProfiles $profiles,private ?Closure $transport=null) {}

    public function request(int $adminId,string $paymentId,mixed $amount,string $reason,string $key,array $context,int $now,?string $orderId=null): array
    {
        Permissions::require($this->db,$adminId,'refunds.manage');$email=$this->email($adminId);$amount=Money::positive($amount);$reason=trim($reason);
        if(!preg_match('/^[a-f0-9]{64}$/D',$paymentId)||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key)||
            !mb_check_encoding($reason,'UTF-8')||mb_strlen($reason)<1||mb_strlen($reason)>80||preg_match('/[\x00-\x1f\x7f]/',$reason)){
            throw new Problem('refund_input_invalid');
        }
        $select=$this->db->prepare("SELECT p.*,o.user_id,o.product FROM portal_wechat_receipts p JOIN portal_orders o ON o.id=p.order_id
            WHERE p.id=? AND o.payment_method='wechat_native' AND (o.product='wallet_recharge' OR EXISTS(SELECT 1 FROM portal_products item WHERE item.code=o.product))");
        $select->execute([$paymentId]);$payment=$select->fetch();
        if(!$payment||($orderId!==null&&$payment['order_id']!==$orderId))throw new Problem('payment_not_found',404);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$email,$payment,$paymentId,$amount,$reason,$key,$context,$now):array{
            $order=$this->lock((int)$payment['user_id'],$payment['order_id']);
            $select=$db->prepare('SELECT id,amount,reason FROM portal_wechat_refund_requests WHERE payment_id=? AND idempotency_key=? FOR SHARE');
            $select->execute([$paymentId,$key]);$old=$select->fetch();
            if($old){
                if((int)$old['amount']!==$amount||$old['reason']!==$reason)throw new Problem('idempotency_conflict',409);
                return $this->view($old['id']);
            }
            if(!in_array($order['status'],['paid','partially_refunded','refund_pending','payment_review','refunded'],true))throw new Problem('order_not_refundable',409);
            $sum=$db->prepare('SELECT COALESCE(SUM(r.amount),0) FROM portal_wechat_refund_requests r WHERE r.payment_id=? AND NOT EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations c WHERE c.refund_id=r.id)');$sum->execute([$paymentId]);
            if($amount>(int)$payment['amount']-(int)$sum->fetchColumn())throw new Problem('refund_amount_exceeded',409);
            $pending=$db->prepare("SELECT COUNT(*) FROM portal_wechat_refund_requests r JOIN portal_wechat_refund_states s ON s.id=r.id
                WHERE r.payment_id=? AND s.status<>'succeeded' AND NOT EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations c WHERE c.refund_id=r.id)");$pending->execute([$paymentId]);
            if((int)$pending->fetchColumn()>0)throw new Problem('refund_pending',409);
            $binding=$payment['product']==='wallet_recharge'?null:$this->binding($payment['order_id']);
            $id=bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_wechat_refund_requests (id,order_id,payment_id,idempotency_key,amount,reason,operator_id,created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$payment['order_id'],$paymentId,$key,$amount,$reason,$adminId,$now]);
            $db->prepare("INSERT INTO portal_wechat_refund_states (id,status,next_attempt_at,updated_at) VALUES (?,'pending',?,?)")->execute([$id,$now,$now]);
            if($payment['product']==='wallet_recharge'){
                WalletRecharges::reserveRefund($db,$id,$payment['order_id'],'wechat_native',$paymentId,$amount,$now);
            }elseif($binding&&$binding['payment_id']===$paymentId)$db->prepare("UPDATE portal_orders SET status='refund_pending',updated_at=? WHERE id=? AND status IN ('paid','partially_refunded')")
                ->execute([$now,$payment['order_id']]);
            Audit::admin($db,$adminId,$email,'REQUEST_WECHAT_REFUND',$id,$context,'PENDING',
                ['payment_id'=>$paymentId,'order_id'=>$payment['order_id'],'amount'=>$amount,'currency'=>'CNY'],$now);
            return $this->view($id);
        });
    }

    public function process(string $id,array $context,int $now,?int $adminId=null): array
    {
        $email='';
        if($adminId!==null){Permissions::require($this->db,$adminId,'refunds.manage');$email=$this->email($adminId);}
        $request=$this->load($id);$token=bin2hex(random_bytes(16));
        $claim=Database::transaction($this->db,function(PDO $db)use($request,$id,$token,$context,$now,$adminId,$email):?array{
            $this->lock((int)$request['user_id'],$request['order_id']);
            $select=$db->prepare('SELECT * FROM portal_wechat_refund_states WHERE id=? FOR UPDATE');$select->execute([$id]);$state=$select->fetch();
            if($this->cancelled($id)||$state['status']==='succeeded'||(int)$state['next_attempt_at']>$now||(int)$state['lease_until']>$now)return null;
            $db->prepare("UPDATE portal_wechat_refund_states SET status='unknown',attempts=attempts+1,lease_token=?,lease_until=?,next_attempt_at=?,updated_at=? WHERE id=?")
                ->execute([$token,$now+120,$now+60,$now,$id]);
            Audit::admin($db,$adminId,$email,'PROCESS_WECHAT_REFUND',$id,$context,'PENDING',
                ['phase'=>'intent','approved_by'=>(int)$request['operator_id'],'automatic'=>$adminId===null],$now);
            return $state;
        });
        if($claim===null)return $this->view($id);
        try{
            $archive=$this->profiles->forOrder($request['order_id']);$current=$this->profiles->request($request['order_id']);
            if($archive['config']['app_id']!==$request['app_id']||$archive['config']['mchid']!==$request['mchid'])throw new Problem('wechat_refund_mismatch');
            $client=new WechatClient($current['config'],$now,$this->transport,$current['verification_keys']);
            // A timed-out submission always resumes with this exact R+ID query, never a new refund number.
            $proof=$client->refundQuery($request,$now);
            if($proof['state']==='SUCCESS')return $this->complete($request,$proof,$token,$context,$now,$adminId,$email);
            if($proof['state']!=='NOT_FOUND')return $this->pending($id,$token,strtolower($proof['state']),$context,$now,$adminId,$email);
            if($claim['retry_mode']==='query_only')return $this->pending($id,$token,'unknown',$context,$now,$adminId,$email);
            $trade=$client->query(['id'=>$request['order_id'],'merchant_order_no'=>$request['merchant_order_no'],'amount'=>$request['paid_amount'],'payment_method'=>'wechat_native','currency'=>'CNY'],$now);
            if(!in_array($trade['state'],['SUCCESS','REFUND'],true)||$trade['transaction_id']!==$request['transaction_id'])return $this->pending($id,$token,'unknown',$context,$now,$adminId,$email);
            $confirmed=$this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM portal_wechat_refund_receipts WHERE payment_id=?');$confirmed->execute([$request['payment_id']]);
            // Untracked external refunds require review instead of a fresh automatic refund.
            if($trade['state']==='REFUND'&&(int)$confirmed->fetchColumn()===0)return $this->pending($id,$token,'unknown',$context,$now,$adminId,$email);
            $sent=$this->db->prepare('UPDATE portal_wechat_refund_states SET last_sent_at=? WHERE id=? AND lease_token=? AND lease_until>?');
            $sent->execute([$now,$id,$token,$now]);
            if($sent->rowCount()!==1){
                $current=$this->view($id);if($current['status']==='succeeded')return $current;
                throw new Problem('refund_lease_lost',409);
            }
            $reply=$client->refund($request,$now,$archive['id']);
            if($reply['state']==='SUCCESS'){
                $proof=$client->refundQuery($request,$now);
                if($proof['state']==='SUCCESS')return $this->complete($request,$proof,$token,$context,$now,$adminId,$email);
                return $this->pending($id,$token,$proof['state']==='NOT_FOUND'?'unknown':strtolower($proof['state']),$context,$now,$adminId,$email);
            }
            return $this->pending($id,$token,strtolower($reply['state']),$context,$now,$adminId,$email);
        }catch(\Throwable $error){
            $this->pending($id,$token,'unknown',$context,$now,$adminId,$email,'FAILURE');throw $error;
        }
    }

    public function notify(string $profile,array $headers,string $raw,array $context,int $now): array
    {
        $authenticated=$this->profiles->notification($profile,$headers,$raw,$now);
        $event=$authenticated['event'];$config=$authenticated['config'];$data=$event['data'];
        if(!in_array($event['event_type'],['REFUND.SUCCESS','REFUND.ABNORMAL','REFUND.CLOSED'],true))throw new Problem('wechat_notification_unsupported');
        if(!is_string($data['out_refund_no']??null)||!preg_match('/^R([a-f0-9]{32})$/D',$data['out_refund_no'],$match))throw new Problem('wechat_refund_mismatch');
        $request=$this->load($match[1]);$bound=$this->profiles->forOrder($request['order_id']);
        if(!hash_equals($bound['id'],$profile)||$config['app_id']!==$request['app_id']||$config['mchid']!==$request['mchid']||
            ($data['mchid']??null)!==$config['mchid']||($data['refund_status']??null)!==substr($event['event_type'],7)||
            (isset($data['status'])&&$data['status']!==$data['refund_status']))throw new Problem('wechat_refund_mismatch');
        if(!is_array($data['amount']??null)||!is_int($data['amount']['payer_total']??null)||!is_int($data['amount']['payer_refund']??null)){
            throw new Problem('wechat_refund_mismatch');
        }
        // Native refund notifications omit currency; only the immutable CNY receipt supplies it.
        if(!array_key_exists('currency',$data['amount']))$data['amount']['currency']=$request['currency'];
        $data['status']=$data['refund_status'];
        $proof=WechatClient::normalizeRefund($data,$request,$config['app_id'],$config['mchid']);
        $eventId=hash('sha256',$config['mchid'].'|refund|'.$event['id']);
        $hash=hash('sha256',json_encode([$request['id'],$proof],JSON_THROW_ON_ERROR));
        return Database::transaction($this->db,function(PDO $db)use($request,$proof,$eventId,$hash,$context,$now):array{
            $this->lock((int)$request['user_id'],$request['order_id']);
            $q=$db->prepare('SELECT status FROM portal_wechat_refund_states WHERE id=? FOR UPDATE');$q->execute([$request['id']]);$status=$q->fetchColumn();
            if($this->cancelled($request['id']))throw new Problem('refund_review_terminal',409);
            $q=$db->prepare('SELECT event_hash FROM portal_wechat_events WHERE id=? FOR SHARE');$q->execute([$eventId]);$old=$q->fetchColumn();
            if($old!==false){
                if(!hash_equals($old,$hash))throw new Problem('wechat_refund_event_conflict');
                return $this->view($request['id']);
            }
            $db->prepare('INSERT INTO portal_wechat_events (id,order_id,event_type,event_hash,source_ip,result,received_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$eventId,$request['order_id'],'REFUND:'.$proof['state'],$hash,$context['source_ip']??null,'VERIFIED',$now]);
            Audit::admin($db,null,'','RECEIVE_WECHAT_REFUND',$request['id'],$context,$proof['state']==='SUCCESS'?'SUCCESS':'PENDING',
                ['source'=>'provider_notification','approved_by'=>(int)$request['operator_id'],'platform_status'=>$proof['state'],
                    'amount'=>$request['amount'],'payer_refund'=>$proof['payer_refund'],'event_id'=>$eventId],$now);
            if($proof['state']==='SUCCESS')return $this->completeLocked($request,$proof,null,$context,$now,null,'');
            if($status==='succeeded'){
                $q=$db->prepare('SELECT provider_refund_id FROM portal_wechat_refund_receipts WHERE id=? FOR SHARE');$q->execute([$request['id']]);
                if($q->fetchColumn()!==$proof['refund_id'])throw new Problem('wechat_refund_conflict');
                return $this->view($request['id']);
            }
            $db->prepare("UPDATE portal_wechat_refund_states SET status=?,retry_mode='query_only',lease_token=NULL,lease_until=0,next_attempt_at=?,updated_at=? WHERE id=?")
                ->execute([strtolower($proof['state']),$now+60,$now,$request['id']]);
            return $this->view($request['id']);
        });
    }

    public function view(string $id): array
    {
        return array_intersect_key($this->load($id),array_flip(['id','order_id','payment_id','amount','reason','status','retry_mode','created_at','next_attempt_at']));
    }

    public function records(int $adminId,string $orderId,array $context,int $now,int $page=1): array
    {
        Permissions::require($this->db,$adminId,'refunds.manage');$email=$this->email($adminId);
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId))throw new Problem('order_not_found',404);
        if($page<1||$page>100000)throw new Problem('page_invalid');
        $order=$this->db->prepare("SELECT id FROM portal_orders WHERE id=? AND payment_method='wechat_native' AND EXISTS(SELECT 1 FROM portal_products item WHERE item.code=portal_orders.product)");
        $order->execute([$orderId]);if(!$order->fetchColumn())throw new Problem('order_not_found',404);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$email,$orderId,$context,$now,$page):array{
            $p=$db->prepare('SELECT p.id,p.amount,p.currency,p.payer_total,p.received_at,
                COALESCE((SELECT SUM(c.amount) FROM portal_wechat_refund_receipts c WHERE c.payment_id=p.id),0) AS confirmed_refunded,
                COALESCE((SELECT SUM(r.amount) FROM portal_wechat_refund_requests r WHERE r.payment_id=p.id AND NOT EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations c WHERE c.refund_id=r.id)),0) AS reserved_amount,
                EXISTS(SELECT 1 FROM portal_wechat_refund_requests r JOIN portal_wechat_refund_states s ON s.id=r.id WHERE r.payment_id=p.id AND s.status<>\'succeeded\' AND NOT EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations c WHERE c.refund_id=r.id)) AS has_pending,
                EXISTS(SELECT 1 FROM portal_wechat_fulfillments f WHERE f.payment_id=p.id) AS fulfills_license
                FROM portal_wechat_receipts p WHERE p.order_id=? ORDER BY p.received_at,p.id');$p->execute([$orderId]);
            $r=$db->prepare('SELECT r.id,r.payment_id,r.amount,r.reason,r.operator_id,r.created_at,IF(EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations x WHERE x.refund_id=r.id),\'cancelled\',s.status) AS status,s.retry_mode,s.attempts,s.last_sent_at,s.next_attempt_at,s.lease_until,
                c.payer_refund FROM portal_wechat_refund_requests r JOIN portal_wechat_refund_states s ON s.id=r.id
                LEFT JOIN portal_wechat_refund_receipts c ON c.id=r.id WHERE r.order_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT 50 OFFSET '.(($page-1)*50));$r->execute([$orderId]);
            $count=$db->prepare('SELECT COUNT(*) FROM portal_wechat_refund_requests WHERE order_id=?');$count->execute([$orderId]);
            $rows=$r->fetchAll();
            Audit::admin($db,$adminId,$email,'VIEW_WECHAT_REFUNDS',$orderId,$context,'SUCCESS',['page'=>$page,'count'=>count($rows)],$now);
            return ['payments'=>$p->fetchAll(),'refunds'=>$rows,'total'=>(int)$count->fetchColumn(),'page'=>$page,'per_page'=>50];
        });
    }

    private function load(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('refund_not_found',404);
        $q=$this->db->prepare('SELECT r.*,o.product,s.status,s.retry_mode,s.next_attempt_at,EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations c WHERE c.refund_id=r.id) AS cancelled,p.transaction_id,p.amount AS paid_amount,p.payer_total,p.currency,p.app_id,p.mchid,o.user_id
            FROM portal_wechat_refund_requests r JOIN portal_wechat_refund_states s ON s.id=r.id
            JOIN portal_wechat_receipts p ON p.id=r.payment_id JOIN portal_orders o ON o.id=r.order_id WHERE r.id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new Problem('refund_not_found',404);
        foreach(['amount','paid_amount']as$field)$row[$field]=(int)$row[$field];
        if($row['payer_total']!==null)$row['payer_total']=(int)$row['payer_total'];
        if($row['cancelled'])$row['status']='cancelled';
        $row['merchant_order_no']=OrderNumbers::merchant($this->db,$row['order_id'],'wechat_native');
        return $row;
    }

    private function lock(int $userId,string $orderId): array
    {
        $q=$this->db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$userId]);
        $q=$this->db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$order=$q->fetch();
        if(!$order||(int)$order['user_id']!==$userId||$order['payment_method']!=='wechat_native')throw new Problem('wechat_refund_mismatch');
        return $order;
    }

    private function binding(string $orderId): array|false
    {
        $q=$this->db->prepare('SELECT f.license_id,w.payment_id FROM portal_order_fulfillments f LEFT JOIN portal_wechat_fulfillments w ON w.order_id=f.id WHERE f.id=?');
        $q->execute([$orderId]);$binding=$q->fetch();
        if($binding&&$binding['payment_id']===null)throw new Problem('payment_fulfillment_review_required',409);
        return $binding;
    }

    private function complete(array $request,array $proof,string $token,array $context,int $now,?int $adminId,string $email): array
    {
        return Database::transaction($this->db,fn():array=>$this->completeLocked($request,$proof,$token,$context,$now,$adminId,$email));
    }

    /** Caller owns the transaction; notifications and leased queries share this confirmation. */
    private function completeLocked(array $request,array $proof,?string $token,array $context,int $now,?int $adminId,string $email): array
    {
            $db=$this->db;
            $id=$request['id'];$order=$this->lock((int)$request['user_id'],$request['order_id']);
            $q=$db->prepare('SELECT * FROM portal_wechat_refund_states WHERE id=? FOR UPDATE');$q->execute([$id]);$state=$q->fetch();
            if($this->cancelled($id))throw new Problem('refund_review_terminal',409);
            if($state['status']==='succeeded'){
                $q=$db->prepare('SELECT * FROM portal_wechat_refund_receipts WHERE id=? FOR SHARE');$q->execute([$id]);$saved=$q->fetch();
                if(!$saved||$saved['payment_id']!==$request['payment_id']||$saved['provider_refund_id']!==$proof['refund_id']||
                    (int)$saved['amount']!==$request['amount']||($saved['payer_refund']!==null&&$proof['payer_refund']!==null&&(int)$saved['payer_refund']!==$proof['payer_refund'])){
                    throw new Problem('wechat_refund_conflict');
                }
                return $this->view($id);
            }
            if($token!==null&&$state['lease_token']!==$token)return $this->view($id);
            $sum=$db->prepare('SELECT COALESCE(SUM(amount),0) AS gross,COALESCE(SUM(payer_refund),0) AS cash FROM portal_wechat_refund_receipts WHERE payment_id=?');
            $sum->execute([$request['payment_id']]);$totals=$sum->fetch();$gross=(int)$totals['gross']+$request['amount'];
            if($gross>$request['paid_amount']||($request['payer_total']!==null&&(int)$totals['cash']+($proof['payer_refund']??0)>$request['payer_total']))throw new Problem('refund_amount_exceeded',409);
            $hash=hash('sha256',json_encode([$id,$request['payment_id'],$proof['refund_id'],$request['amount'],$proof['payer_refund'],'SUCCESS'],JSON_THROW_ON_ERROR));
            $db->prepare('INSERT INTO portal_wechat_refund_receipts (id,payment_id,mchid,provider_refund_id,amount,payer_refund,proof_hash,confirmed_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$request['payment_id'],$request['mchid'],$proof['refund_id'],$request['amount'],$proof['payer_refund'],$hash,$now]);
            $db->prepare("UPDATE portal_wechat_refund_states SET status='succeeded',lease_token=NULL,lease_until=0,updated_at=? WHERE id=?")->execute([$now,$id]);
            $binding=$request['product']==='wallet_recharge'?null:$this->binding($request['order_id']);$effect='unchanged';
            if($request['product']==='wallet_recharge'){
                WalletRecharges::completeRefund($db,$id,$now);
                $full=$gross===$request['paid_amount'];
                $db->prepare("UPDATE portal_orders SET refunded_amount=?,status=IF(status='payment_review',status,?),updated_at=? WHERE id=?")
                    ->execute([$gross,$full?'refunded':'partially_refunded',$now,$request['order_id']]);
            }elseif($binding&&$binding['payment_id']===$request['payment_id']){
                $full=$gross===$request['paid_amount'];
                $db->prepare("UPDATE portal_orders SET refunded_amount=?,status=IF(status='payment_review',status,?),updated_at=? WHERE id=?")
                    ->execute([$gross,$full?'refunded':'partially_refunded',$now,$request['order_id']]);
                if($full){
                    $effect='revoked';
                    $db->prepare("UPDATE portal_licenses SET status='revoked',session_version=session_version+1,updated_at=? WHERE id=? AND user_id=?")
                        ->execute([$now,$binding['license_id'],$request['user_id']]);
                    $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=(SELECT product FROM portal_licenses WHERE id=?)')->execute([$request['user_id'],$binding['license_id']]);
                }
            }
            TransactionNotifications::record($db,['id'=>$request['order_id'],'user_id'=>$request['user_id'],'currency'=>'CNY','amount'=>$request['paid_amount']],
                'REFUND_CONFIRMED','wechat_refund:'.$id,$request['amount'],$effect,$now);
            Audit::admin($db,$adminId,$email,'CONFIRM_WECHAT_REFUND',$id,$context,'SUCCESS',
                ['payment_id'=>$request['payment_id'],'amount'=>$request['amount'],'payer_refund'=>$proof['payer_refund'],'approved_by'=>(int)$request['operator_id'],'automatic'=>$adminId===null],$now);
            return $this->view($id);
    }

    private function pending(string $id,string $token,string $status,array $context,int $now,?int $adminId,string $email,string $result='PENDING'): array
    {
        Database::transaction($this->db,function(PDO $db)use($id,$token,$status,$context,$now,$adminId,$email,$result):void{
            $q=$db->prepare("UPDATE portal_wechat_refund_states SET status=?,retry_mode=IF(?,'query_only',retry_mode),lease_token=NULL,lease_until=0,updated_at=? WHERE id=? AND lease_token=?");
            $q->execute([$status,(int)in_array($status,['abnormal','closed'],true),$now,$id,$token]);
            if($q->rowCount()>0)Audit::admin($db,$adminId,$email,'PROCESS_WECHAT_REFUND',$id,$context,$result,['phase'=>'result','status'=>$status,'automatic'=>$adminId===null],$now);
        });
        return $this->view($id);
    }

    /** Current read under the account/order lock serializes cancellation with worker claims. */
    private function cancelled(string $id): bool
    {
        $q=$this->db->prepare('SELECT refund_id FROM portal_wechat_refund_cancellations WHERE refund_id=? FOR SHARE');$q->execute([$id]);
        return $q->fetchColumn()!==false;
    }

    private function email(int $id): string
    {
        $q=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$id]);$email=$q->fetchColumn();
        if(!is_string($email))throw new Problem('admin_auth_invalid',401);return $email;
    }
}
