<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class WechatPayments
{
    public function __construct(private PDO $db,private WechatProfiles $profiles,private ?Closure $transport=null) {}

    public function notify(string $profile,array $headers,string $raw,array $context,int $now): array
    {
        try{$authenticated=$this->profiles->notification($profile,$headers,$raw,$now);}
        catch(Problem $error){
            if($error->error==='wechat_notification_unsupported'){
                $this->event(hash('sha256',random_bytes(32)),null,'UNSUPPORTED',hash('sha256','UNSUPPORTED'),$context,'REJECTED',$now);
            }
            throw $error;
        }
        $config=$authenticated['config'];$envelope=$authenticated['event'];
        if($envelope['event_type']!=='TRANSACTION.SUCCESS'){
            $this->event(hash('sha256',random_bytes(32)),null,'WRONG_ENDPOINT',hash('sha256',$envelope['event_type']),$context,'REJECTED',$now);
            throw new Problem('wechat_notification_unsupported');
        }
        $data=$envelope['data'];$number=$data['out_trade_no']??null;
        $id=is_string($number)?OrderNumbers::resolveMerchant($this->db,$number,'wechat_native'):null;
        if($id===null)throw new Problem('wechat_order_invalid');
        $bound=$this->profiles->forOrder($id);
        if(!hash_equals($bound['id'],$profile))throw new Problem('wechat_profile_order_mismatch');
        $order=$this->order($id);
        $proof=WechatClient::normalizeTransaction($data,$order,$config['app_id'],$config['mchid']);
        if($proof['state']!=='SUCCESS')throw new Problem('wechat_notification_state_mismatch');
        $eventId=hash('sha256',$config['mchid'].'|'.$envelope['id']);
        return $this->settle($order,$config,$proof,$eventId,'NOTIFY:SUCCESS',$context,$now);
    }

    public function query(string $id,array $context,int $now): array
    {
        $order=$this->order($id);$archive=$this->profiles->request($id);$config=$archive['config'];
        try{
            $proof=(new WechatClient($config,$now,$this->transport,$archive['verification_keys']))->query($order,$now);
            if($proof['state']==='NOT_FOUND'){
                $this->event(hash('sha256',random_bytes(32)),$id,'QUERY:NOT_FOUND',hash('sha256','NOT_FOUND'),$context,'PENDING',$now);
                return ['status'=>$this->order($id)['status'],'provider_status'=>'NOT_FOUND'];
            }
            $result=$this->settle($order,$config,$proof,hash('sha256',random_bytes(32)),'QUERY:'.$proof['state'],$context,$now);
            return $result+['provider_status'=>$proof['state']];
        }catch(\Throwable $error){
            $this->event(hash('sha256',random_bytes(32)),$id,'QUERY:FAILED',hash('sha256','FAILED'),$context,'PENDING',$now);
            throw $error;
        }
    }

    public function closeExpired(string $id,array $context,int $now): array
    {
        return $this->close($id,$context,$now,true);
    }

    public function closePending(string $id,array $context,int $now): array
    {
        if($this->order($id)['status']!=='pending')throw new Problem('order_not_cancellable',409);
        return $this->close($id,$context,$now,false);
    }

    private function close(string $id,array $context,int $now,bool $expiredOnly): array
    {
        return WechatOrderLock::run($this->db,$id,function()use($id,$context,$now,$expiredOnly):array{
            $before=$this->query($id,$context,$now);$order=$this->order($id);
            if(($before['provider_status']??'')!=='NOTPAY'||$order['status']!=='pending'||
                ($expiredOnly&&(int)$order['expires_at']>$now))return $before;
            $archive=$this->profiles->request($id);
            // This committed event precedes external I/O; failure cannot issue a close.
            $this->event(hash('sha256',random_bytes(32)),$id,'CLOSE:REQUESTED',hash('sha256','REQUESTED'),$context,'PENDING',$now);
            $accepted=false;
            try{
                (new WechatClient($archive['config'],$now,$this->transport,$archive['verification_keys']))->close($order,$now);
                $accepted=true;
                $this->event(hash('sha256',random_bytes(32)),$id,'CLOSE:ACKNOWLEDGED',hash('sha256','ACKNOWLEDGED'),$context,'PENDING',$now);
            }catch(\Throwable){
                $this->event(hash('sha256',random_bytes(32)),$id,'CLOSE:FAILED',hash('sha256','FAILED'),$context,'PENDING',$now);
            }
            // Even a signed 204 is not a fabricated CLOSED proof. Payment may have won.
            try{$after=$this->query($id,$context,$now);}
            catch(\Throwable){
                $this->event(hash('sha256',random_bytes(32)),$id,'CLOSE:RECHECK_FAILED',hash('sha256','RECHECK_FAILED'),$context,'PENDING',$now);
                throw new Problem('wechat_close_unknown',502);
            }
            if(in_array($after['provider_status']??'',['CLOSED','SUCCESS','REFUND'],true))return $after+['close_status'=>'resolved'];
            return $after+['close_status'=>$accepted?'pending':'unknown'];
        });
    }

    private function settle(array $snapshot,array $config,array $proof,string $eventId,string $eventType,array $context,int $now): array
    {
        $payerHash=$this->profiles->payerHash($config['app_id'],$config['mchid'],$proof['payer_identifier']);
        $hash=hash('sha256',json_encode([$snapshot['id'],$config['app_id'],$config['mchid'],$proof['state'],
            $proof['transaction_id'],$proof['amount'],$proof['currency'],$proof['payer_total'],$payerHash],JSON_THROW_ON_ERROR));
        return Database::transaction($this->db,function(PDO $db)use($snapshot,$config,$proof,$eventId,$eventType,$context,$now,$payerHash,$hash):array{
            $user=$db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');$user->execute([$snapshot['user_id']]);$userStatus=$user->fetchColumn();
            $select=$db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$select->execute([$snapshot['id']]);$order=$select->fetch();
            if(!$order||$order['payment_method']!=='wechat_native'||($order['product']!=='wallet_recharge'&&!(new ProductCatalog($db))->exists($order['product']))||$order['currency']!=='CNY'||
                (int)$order['amount']!==(int)$snapshot['amount'])throw new Problem('wechat_payment_mismatch');
            $existing=$db->prepare('SELECT event_hash FROM portal_wechat_events WHERE id=?');$existing->execute([$eventId]);$old=$existing->fetchColumn();
            if($old!==false){
                if(!hash_equals($old,$hash))throw new Problem('wechat_event_conflict');
                return ['status'=>$order['status'],'duplicate'=>true];
            }
            $this->event($eventId,$order['id'],$eventType,$hash,$context,'VERIFIED',$now);
            if($proof['state']==='NOTPAY')return ['status'=>$order['status']];
            if($proof['state']==='CLOSED'){
                if($order['status']==='pending'){
                    $db->prepare("UPDATE portal_orders SET status='cancelled',pending_user_id=NULL,updated_at=? WHERE id=?")->execute([$now,$order['id']]);
                }
                return ['status'=>$order['status']==='pending'?'cancelled':$order['status']];
            }
            $paymentId=hash('sha256',$config['mchid'].'|'.$proof['transaction_id']);
            $select=$db->prepare('SELECT * FROM portal_wechat_receipts WHERE id=?');$select->execute([$paymentId]);$receipt=$select->fetch();
            if($receipt!==false){
                if($receipt['order_id']!==$order['id']||$receipt['app_id']!==$config['app_id']||$receipt['mchid']!==$config['mchid']||
                    (int)$receipt['amount']!==$proof['amount']||
                    ($receipt['payer_total']!==null&&$proof['payer_total']!==null&&(int)$receipt['payer_total']!==$proof['payer_total'])||
                    ($receipt['payer_hash']!==null&&$payerHash!==null&&!hash_equals($receipt['payer_hash'],$payerHash))){
                    throw new Problem('wechat_trade_conflict');
                }
                if($proof['state']==='REFUND'&&in_array($order['status'],['pending','paid','partially_refunded'],true)){
                    TransactionNotifications::record($db,$order,'PAYMENT_REVIEW','wechat_review:'.$paymentId,(int)$order['amount'],'unchanged',$now);
                    Audit::user($db,(int)$order['user_id'],$order['email'],'PURCHASE','BLOCKED',$context,$now);
                    $db->prepare("UPDATE portal_orders SET status='payment_review',pending_user_id=NULL,updated_at=? WHERE id=?")->execute([$now,$order['id']]);
                    return ['status'=>'payment_review','duplicate'=>true];
                }
                // Success replay cannot grant again or undo a confirmed refund/revocation.
                return ['status'=>$order['status'],'duplicate'=>true];
            }
            $db->prepare('INSERT INTO portal_wechat_receipts (id,order_id,app_id,mchid,transaction_id,amount,currency,provider_state,payer_total,payer_hash,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$paymentId,$order['id'],$config['app_id'],$config['mchid'],$proof['transaction_id'],$proof['amount'],'CNY',$proof['state'],$proof['payer_total'],$payerHash,$now]);
            $controls=$db->prepare('SELECT login_blocked,purchase_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');
            $controls->execute([$order['user_id']]);$control=$controls->fetch();
            $verified=$db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');$verified->execute([$order['user_id']]);
            $allowed=$userStatus==='active'&&(int)$verified->fetchColumn()>0&&
                (!$control||(!(int)$control['login_blocked']&&!(int)$control['purchase_blocked']));
            $recharge=$order['product']==='wallet_recharge';
            $fulfilled=$proof['state']==='SUCCESS'&&$order['status']==='pending'&&$allowed&&
                ($recharge?WalletRecharges::creditExternal($db,$order,'wechat_native',$paymentId,$now):OrderFulfillment::grant($db,$order,$now));
            if($fulfilled&&!$recharge)$db->prepare('INSERT INTO portal_wechat_fulfillments (payment_id,order_id) VALUES (?,?)')->execute([$paymentId,$order['id']]);
            $status=$fulfilled?'paid':'payment_review';
            if(!$fulfilled)TransactionNotifications::record($db,$order,'PAYMENT_REVIEW','wechat_review:'.$paymentId,(int)$order['amount'],'unchanged',$now);
            $db->prepare('UPDATE portal_orders SET status=?,pending_user_id=NULL,paid_at=COALESCE(paid_at,?),payment_account_identifier=COALESCE(payment_account_identifier,?),updated_at=? WHERE id=?')
                ->execute([$status,$now,$payerHash,$now,$order['id']]);
            Audit::user($db,(int)$order['user_id'],$order['email'],'PURCHASE',$fulfilled?'SUCCESS':'BLOCKED',$context,$now);
            return ['status'=>$status];
        });
    }

    private function order(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('wechat_order_invalid');
        $select=$this->db->prepare("SELECT * FROM portal_orders WHERE id=? AND payment_method='wechat_native' AND (product='wallet_recharge' OR EXISTS(SELECT 1 FROM portal_products p WHERE p.code=portal_orders.product))");$select->execute([$id]);$row=$select->fetch();
        if(!$row)throw new Problem('wechat_order_invalid');
        $row['amount']=(int)$row['amount'];
        $row['merchant_order_no']=OrderNumbers::merchant($this->db,$id,'wechat_native');return $row;
    }

    private function event(string $id,?string $order,string $type,string $hash,array $context,string $result,int $now): void
    {
        $this->db->prepare('INSERT INTO portal_wechat_events (id,order_id,event_type,event_hash,source_ip,result,received_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id,$order,$type,$hash,$context['source_ip']??null,$result,$now]);
    }
}
