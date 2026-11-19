<?php
declare(strict_types=1);
namespace D2S\services;

use Closure;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ExternalPayments
{
    public function __construct(private PDO $db,private SecretBox $secrets,private ?Closure $transport=null) {}

    public function captureConfiguration(string $orderId,string $provider,string $currency,?string $id,int $now): void
    {
        if(!$this->db->inTransaction())throw new \RuntimeException('payment_profile_transaction_required');
        $choice=(new PaymentConfigurations($this->db,$this->secrets))->resolve($provider,$currency,$id);
        if($choice===null)throw new Problem('payment_method_unavailable',409);
        $cipher=$this->secrets->encrypt(json_encode($choice['configuration'],JSON_THROW_ON_ERROR),'external-order:'.$orderId);
        $this->db->prepare('INSERT INTO portal_external_checkouts(order_id,configuration_id,provider,config_cipher,created_at,updated_at) VALUES(?,?,?,?,?,?)')
            ->execute([$orderId,$choice['id'],$provider,$cipher,$now,$now]);
        $this->db->prepare('UPDATE portal_orders SET payment_configuration_id=? WHERE id=?')->execute([$choice['id'],$orderId]);
    }

    public function open(int $userId,string $orderId,int $now): array
    {
        $checkout=Database::transaction($this->db,function(PDO $db)use($userId,$orderId,$now):array{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$userId]);
            $q=$db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$order=$q->fetch();
            if(!$order||(int)$order['user_id']!==$userId)throw new Problem('order_not_found',404);
            if($order['status']!=='pending'||(int)$order['expires_at']<=$now)throw new Problem('order_not_payable',409);
            (new Commerce($db))->assertPurchaseAllowed($userId,$order['product']);
            $q=$db->prepare('SELECT * FROM portal_external_checkouts WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);$row=$q->fetch();
            if(!$row)throw new Problem('payment_attempt_not_found',404);
            if($row['checkout_url']===null){
                if($row['provider']==='stripe'&&(int)$order['expires_at']-$now<1800)throw new Problem('payment_checkout_expiring',409);
                $db->prepare("UPDATE portal_external_checkouts SET status='opening',updated_at=? WHERE order_id=?")->execute([$now,$orderId]);
            }
            return $row;
        });
        $order=$this->order($orderId);
        if($checkout['checkout_url']!==null)return ['provider'=>$checkout['provider'],'checkout_url'=>$checkout['checkout_url']];
        $config=$this->requestConfiguration($checkout);
        $url=rtrim(getenv('D2S_APP_URL')?:'', '/');
        $opened=(new InternationalClient($checkout['provider'],$config,$this->transport))->create($order,$url.'/payments/return?order='.$orderId,$url.'/shop?product='.rawurlencode($order['product']));
        Database::transaction($this->db,function(PDO $db)use($checkout,$opened,$orderId,$now):void{
            $q=$db->prepare('SELECT provider_order_id FROM portal_external_checkouts WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);$existing=$q->fetchColumn();
            if($existing!==null&&$existing!==$opened['id'])throw new Problem('payment_checkout_conflict',409);
            $db->prepare("UPDATE portal_external_checkouts SET provider_order_id=?,checkout_url=?,status=IF(status='paid',status,'opened'),updated_at=? WHERE order_id=?")
                ->execute([$opened['id'],$opened['url'],$now,$orderId]);
        });
        return ['provider'=>$checkout['provider'],'checkout_url'=>$opened['url']];
    }

    public function confirm(int $userId,string $orderId,int $now): array
    {
        $order=$this->order($orderId);
        if((int)$order['user_id']!==$userId)throw new Problem('order_not_found',404);
        $this->reconcile($orderId,$now,true);
        return ['id'=>$orderId,'status'=>$this->order($orderId)['status']];
    }

    public function reconcile(string $orderId,int $now,bool $capture=false): void
    {
        $checkout=$this->checkout($orderId);
        if($checkout['provider_order_id']===null)return;
        $client=new InternationalClient($checkout['provider'],$this->requestConfiguration($checkout),$this->transport);
        $proof=$client->inspect($checkout['provider_order_id']);
        if($proof['order_id']!==$orderId)throw new Problem('payment_reference_mismatch',502);
        if($capture&&$checkout['provider']==='paypal'&&$proof['status']==='approved'&&(int)$this->order($orderId)['expires_at']>$now){
            $client->capture($checkout['provider_order_id'],$orderId);$proof=$client->inspect($checkout['provider_order_id']);
            if($proof['order_id']!==$orderId)throw new Problem('payment_reference_mismatch',502);
        }
        if($proof['status']==='paid')$this->settlePayment($checkout,$proof,$now);
        elseif($proof['status']==='expired'||($checkout['provider']==='paypal'&&(int)$this->order($orderId)['expires_at']<=$now)){
            $order=$this->order($orderId);
            Database::transaction($this->db,function(PDO $db)use($order,$orderId,$now):void{
                $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$order['user_id']]);
                $q=$db->prepare('SELECT status FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);
                if($q->fetchColumn()==='pending')$db->prepare("UPDATE portal_orders SET status='cancelled',pending_user_id=NULL,updated_at=? WHERE id=?")->execute([$now,$orderId]);
            });
        }
    }

    private function settlePayment(array $checkout,array $proof,int $now): void
    {
        if(!is_string($proof['payment_id'])||!preg_match('/^[A-Za-z0-9_-]{5,255}$/D',$proof['payment_id'])||
            !is_int($proof['amount'])||$proof['amount']<=0||$proof['amount']>1000000000000||
            !preg_match('/^[A-Z]{3}$/D',(string)$proof['currency']))throw new Problem('payment_proof_invalid',502);
        $order=$this->order($checkout['order_id']);
        Database::transaction($this->db,function(PDO $db)use($checkout,$proof,$order,$now):void{
            $q=$db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$order['user_id']]);$userStatus=$q->fetchColumn();
            $q=$db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$order['id']]);$locked=$q->fetch();
            $id=hash('sha256',$checkout['configuration_id'].':'.$proof['payment_id']);
            $q=$db->prepare('SELECT order_id,amount,currency FROM portal_external_payments WHERE id=?');$q->execute([$id]);$old=$q->fetch();
            if($old){
                if($old['order_id']!==$order['id']||(int)$old['amount']!==$proof['amount']||$old['currency']!==$proof['currency'])throw new Problem('payment_receipt_conflict',409);
                return;
            }
            $q=$db->prepare('SELECT COUNT(*) FROM portal_external_payments WHERE order_id=?');$q->execute([$order['id']]);$additional=(int)$q->fetchColumn()>0;
            $db->prepare('INSERT INTO portal_external_payments(id,order_id,configuration_id,provider,provider_payment_id,currency,amount,received_at) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$id,$order['id'],$checkout['configuration_id'],$checkout['provider'],$proof['payment_id'],$proof['currency'],$proof['amount'],$now]);
            $db->prepare("UPDATE portal_external_checkouts SET provider_payment_id=?,status='paid',updated_at=? WHERE order_id=?")->execute([$proof['payment_id'],$now,$order['id']]);
            $q=$db->prepare('SELECT purchase_blocked FROM portal_user_controls WHERE user_id=?');$q->execute([$order['user_id']]);$blocked=(int)$q->fetchColumn()===1;
            $valid=!$additional&&$locked['status']==='pending'&&(int)$locked['expires_at']>$now&&$userStatus==='active'&&!$blocked&&
                $proof['amount']===(int)$locked['amount']&&$proof['currency']===$locked['currency'];
            if($valid)$valid=OrderFulfillment::grant($db,$locked,$now);
            $status=$valid?'paid':'payment_review';
            $db->prepare('UPDATE portal_orders SET status=?,pending_user_id=NULL,paid_at=COALESCE(paid_at,?),updated_at=? WHERE id=?')->execute([$status,$now,$now,$order['id']]);
            if(!$valid)TransactionNotifications::record($db,$locked,'PAYMENT_REVIEW','external_review:'.$id,(int)$locked['amount'],'unchanged',$now);
        });
    }

    public function webhook(string $provider,string $configurationId,array $headers,string $body,int $now): array
    {
        Backoffice::hexId($configurationId);
        if(strlen($body)>1048576)throw new Problem('payment_event_too_large',413);
        if(!in_array($provider,['stripe','paypal'],true))throw new Problem('payment_provider_invalid');
        try{$hint=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('payment_event_invalid');}
        $object=$provider==='stripe'?($hint['data']['object']??[]):($hint['resource']??[]);
        $checkout=null;
        $orderId=$object['client_reference_id']??$object['metadata']['order_id']??null;
        if(is_string($orderId)&&preg_match('/^[a-f0-9]{32}$/D',$orderId)){
            $q=$this->db->prepare('SELECT * FROM portal_external_checkouts WHERE order_id=? AND configuration_id=?');$q->execute([$orderId,$configurationId]);$checkout=$q->fetch()?:null;
        }
        if($checkout===null){
            $providerOrder=$provider==='stripe'?($object['id']??''):($object['supplementary_data']['related_ids']['order_id']??$object['id']??'');
            $q=$this->db->prepare('SELECT * FROM portal_external_checkouts WHERE configuration_id=? AND provider_order_id=?');$q->execute([$configurationId,$providerOrder]);$checkout=$q->fetch()?:null;
        }
        $paymentHint=is_string($object['payment_intent']??null)?$object['payment_intent']:null;
        if($provider==='paypal'){
            foreach($object['links']??[]as$link){
                if(($link['rel']??'')==='up'&&preg_match('#/v2/payments/captures/([A-Z0-9]+)$#D',(string)($link['href']??''),$parts))$paymentHint=$parts[1];
            }
        }
        if($checkout===null&&$paymentHint!==null){
            $q=$this->db->prepare('SELECT c.* FROM portal_external_checkouts c JOIN portal_external_payments p ON p.order_id=c.order_id WHERE p.configuration_id=? AND p.provider_payment_id=? LIMIT 1');
            $q->execute([$configurationId,$paymentHint]);$checkout=$q->fetch()?:null;
        }
        $current=(new PaymentConfigurations($this->db,$this->secrets))->configuration($configurationId);
        if($current['provider']!==$provider)throw new Problem('payment_provider_invalid');
        $config=$checkout?$this->requestConfiguration($checkout):$current['configuration'];
        $client=new InternationalClient($provider,$config,$this->transport);
        try{$event=$client->webhook($headers,$body,$now);}
        catch(Problem $error){
            if(!$checkout||$error->error!=='payment_signature_invalid')throw$error;
            $archive=$this->archive($checkout);
            $client=new InternationalClient($provider,$archive,$this->transport);$event=$client->webhook($headers,$body,$now);
        }
        $eventId=hash('sha256',$configurationId.':'.$event['id']);
        $q=$this->db->prepare('SELECT 1 FROM portal_external_events WHERE id=?');$q->execute([$eventId]);if($q->fetchColumn())return ['accepted'=>true,'duplicate'=>true];
        $type=(string)($provider==='stripe'?($event['type']??''):($event['event_type']??''));
        $result='ignored';
        if($checkout&&($provider==='stripe'&&in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)||
            $provider==='paypal'&&in_array($type,['CHECKOUT.ORDER.APPROVED','PAYMENT.CAPTURE.COMPLETED'],true))){
            $this->reconcile($checkout['order_id'],$now,$provider==='paypal'&&$type==='CHECKOUT.ORDER.APPROVED');$result='processed';
        }elseif($provider==='stripe'&&in_array($type,['refund.updated','refund.created','charge.refunded'],true)||
            $provider==='paypal'&&$type==='PAYMENT.CAPTURE.REFUNDED'){
            $object=$provider==='stripe'?$event['data']['object']:$event['resource'];
            $ids=$type==='charge.refunded'?array_column($object['refunds']['data']??[],'id'):[$object['id']??''];
            foreach($ids as$id){
                if(!is_string($id))continue;$proof=$client->refundDetails($id);
                if($proof['status']==='succeeded'){$this->settleRefund($configurationId,$proof,$now);$result='processed';}
            }
        }
        $this->db->prepare('INSERT IGNORE INTO portal_external_events(id,configuration_id,order_id,provider,event_type,result,event_hash,received_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$eventId,$configurationId,$checkout['order_id']??null,$provider,substr($type,0,100),$result,hash('sha256',$body),$now]);
        return ['accepted'=>true];
    }

    public function requestRefund(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'refunds.manage');
        $id=Backoffice::hexId($input['request_id']??null);
        $paymentId=$input['payment_id']??'';
        if(!is_string($paymentId)||!preg_match('/^[a-f0-9]{64}$/D',$paymentId))throw new Problem('payment_reference_invalid');
        $amount=$input['amount']??null;if(!is_int($amount)||$amount<=0||$amount>1000000000000)throw new Problem('amount_invalid');
        $reason=Backoffice::text($input,'reason',500,true);
        $q=$this->db->prepare('SELECT p.*,o.user_id FROM portal_external_payments p JOIN portal_orders o ON o.id=p.order_id WHERE p.id=?');$q->execute([$paymentId]);$payment=$q->fetch();
        if(!$payment)throw new Problem('payment_not_found',404);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$id,$paymentId,$amount,$reason,$payment,$context,$now):array{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$payment['user_id']]);
            $db->prepare('SELECT id FROM portal_orders WHERE id=? FOR UPDATE')->execute([$payment['order_id']]);
            $q=$db->prepare('SELECT * FROM portal_external_payments WHERE id=? FOR UPDATE');$q->execute([$paymentId]);$receipt=$q->fetch();
            $q=$db->prepare('SELECT * FROM portal_external_refunds WHERE id=?');$q->execute([$id]);$old=$q->fetch();
            if($old){if($old['payment_id']!==$paymentId||(int)$old['amount']!==$amount||$old['reason']!==$reason)throw new Problem('idempotency_conflict',409);return ['id'=>$id,'status'=>$old['status']];}
            $q=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM portal_external_refunds WHERE payment_id=? AND status IN('pending','submitted','review')");
            $q->execute([$paymentId]);$held=(int)$q->fetchColumn();
            if($amount>(int)$receipt['amount']-(int)$receipt['refunded_amount']-$held)throw new Problem('refund_amount_exceeds_available',409);
            $db->prepare('INSERT INTO portal_external_refunds(id,payment_id,amount,reason,requested_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$paymentId,$amount,$reason,$adminId,$now,$now]);
            (new Backoffice($db))->audit($adminId,'REQUEST_EXTERNAL_REFUND',$id,['payment_id'=>$paymentId,'amount'=>$amount,'reason'=>$reason],$context,$now);
            return ['id'=>$id,'status'=>'pending'];
        });
    }

    public function workRefund(string $id,int $now): void
    {
        Backoffice::hexId($id);
        $q=$this->db->prepare('SELECT r.*,p.order_id,p.configuration_id,p.provider,p.provider_payment_id,p.currency FROM portal_external_refunds r JOIN portal_external_payments p ON p.id=r.payment_id WHERE r.id=?');
        $q->execute([$id]);$row=$q->fetch();if(!$row||in_array($row['status'],['succeeded','failed'],true))return;
        $checkout=$this->checkout($row['order_id']);$client=new InternationalClient($row['provider'],$this->requestConfiguration($checkout),$this->transport);
        if($row['provider_refund_id']===null){
            if($row['sent_at']!==null&&$now-(int)$row['sent_at']>18000){
                $this->db->prepare("UPDATE portal_external_refunds SET status='review',last_error='idempotency_window_expired',updated_at=? WHERE id=?")->execute([$now,$id]);return;
            }
            $this->db->prepare('UPDATE portal_external_refunds SET sent_at=COALESCE(sent_at,?),updated_at=? WHERE id=?')->execute([$now,$now,$id]);
            $response=$client->refund($row['provider_payment_id'],(int)$row['amount'],$row['currency'],$id);
            $this->db->prepare("UPDATE portal_external_refunds SET provider_refund_id=?,status='submitted',updated_at=? WHERE id=? AND provider_refund_id IS NULL")->execute([$response['id'],$now,$id]);
            $row['provider_refund_id']=$response['id'];
        }
        $proof=$client->refundDetails($row['provider_refund_id']);
        if($proof['payment_id']!==$row['provider_payment_id']||$proof['amount']!==(int)$row['amount']||$proof['currency']!==$row['currency'])throw new Problem('refund_proof_mismatch',502);
        if($proof['status']==='succeeded')$this->settleRefund($row['configuration_id'],$proof,$now);
        elseif($proof['status']==='failed')$this->db->prepare("UPDATE portal_external_refunds SET status='failed',updated_at=? WHERE id=?")->execute([$now,$id]);
    }

    private function settleRefund(string $configurationId,array $proof,int $now): void
    {
        if(!is_string($proof['id']??null)||!preg_match('/^[A-Za-z0-9_-]{5,255}$/D',$proof['id'])||
            !is_int($proof['amount']??null)||$proof['amount']<=0)throw new Problem('refund_proof_invalid',502);
        $q=$this->db->prepare('SELECT p.*,o.user_id FROM portal_external_payments p JOIN portal_orders o ON o.id=p.order_id WHERE p.configuration_id=? AND p.provider_payment_id=?');
        $q->execute([$configurationId,$proof['payment_id']]);$receipt=$q->fetch();
        if(!$receipt)throw new Problem('refund_payment_not_found',409);
        Database::transaction($this->db,function(PDO $db)use($configurationId,$proof,$receipt,$now):void{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$receipt['user_id']]);
            $q=$db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$receipt['order_id']]);$order=$q->fetch();
            $q=$db->prepare('SELECT * FROM portal_external_payments WHERE id=? FOR UPDATE');$q->execute([$receipt['id']]);$payment=$q->fetch();
            $id=hash('sha256',$configurationId.':'.$proof['id']);
            $q=$db->prepare('SELECT payment_id,amount,currency FROM portal_external_refund_receipts WHERE id=?');$q->execute([$id]);$old=$q->fetch();
            if($old){
                if($old['payment_id']!==$payment['id']||(int)$old['amount']!==$proof['amount']||$old['currency']!==$proof['currency'])throw new Problem('refund_receipt_conflict',409);
                $db->prepare("UPDATE portal_external_refunds SET status='succeeded',updated_at=? WHERE payment_id=? AND provider_refund_id=? AND amount=?")
                    ->execute([$now,$payment['id'],$proof['id'],$proof['amount']]);
                return;
            }
            if(isset($proof['request_id'])&&is_string($proof['request_id'])&&preg_match('/^[a-f0-9]{32}$/D',$proof['request_id'])){
                $db->prepare('UPDATE portal_external_refunds SET provider_refund_id=COALESCE(provider_refund_id,?) WHERE id=? AND payment_id=? AND amount=?')
                    ->execute([$proof['id'],$proof['request_id'],$payment['id'],$proof['amount']]);
            }
            if($payment['currency']!==$proof['currency']||(int)$payment['refunded_amount']+$proof['amount']>(int)$payment['amount'])throw new Problem('refund_amount_invalid',502);
            $db->prepare('INSERT INTO portal_external_refund_receipts(id,payment_id,configuration_id,provider_refund_id,amount,currency,received_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$id,$payment['id'],$configurationId,$proof['id'],$proof['amount'],$proof['currency'],$now]);
            $db->prepare('UPDATE portal_external_payments SET refunded_amount=refunded_amount+? WHERE id=?')->execute([$proof['amount'],$payment['id']]);
            $db->prepare("UPDATE portal_external_refunds SET status='succeeded',updated_at=? WHERE payment_id=? AND provider_refund_id=? AND amount=?")->execute([$now,$payment['id'],$proof['id'],$proof['amount']]);
            // Keep mismatched receipts under review; refunding them must not invent a valid sale.
            if($order['status']==='payment_review')return;
            $q=$db->prepare('SELECT SUM(refunded_amount) FROM portal_external_payments WHERE order_id=?');$q->execute([$order['id']]);$total=min((int)$order['amount'],(int)$q->fetchColumn());
            $full=$total>=(int)$order['amount'];$effect='unchanged';
            if($full){
                $q=$db->prepare('SELECT license_id FROM portal_order_fulfillments WHERE id=?');$q->execute([$order['id']]);$license=$q->fetchColumn();
                if($license!==false){
                    $db->prepare("UPDATE portal_licenses SET status='revoked',session_version=session_version+1,updated_at=? WHERE id=? AND status='active'")->execute([$now,$license]);
                    $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=(SELECT product FROM portal_licenses WHERE id=?)')->execute([$order['user_id'],$license]);$effect='revoked';
                }
            }
            $db->prepare('UPDATE portal_orders SET refunded_amount=?,status=?,pending_user_id=NULL,updated_at=? WHERE id=?')->execute([$total,$full?'refunded':'partially_refunded',$now,$order['id']]);
            TransactionNotifications::record($db,$order,'REFUND_CONFIRMED','external_refund:'.$id,min($proof['amount'],(int)$order['amount']),$effect,$now);
        });
    }

    public function overview(int $adminId,string $orderId): array
    {
        Permissions::require($this->db,$adminId,'orders.read');Backoffice::hexId($orderId);
        $q=$this->db->prepare('SELECT id,provider,provider_payment_id,currency,amount,refunded_amount,received_at FROM portal_external_payments WHERE order_id=? ORDER BY received_at DESC');
        $q->execute([$orderId]);$payments=$q->fetchAll();
        $q=$this->db->prepare('SELECT r.id,r.payment_id,r.amount,r.status,r.reason,r.provider_refund_id,r.last_error,r.created_at,r.updated_at FROM portal_external_refunds r JOIN portal_external_payments p ON p.id=r.payment_id WHERE p.order_id=? ORDER BY r.created_at DESC');
        $q->execute([$orderId]);$refunds=$q->fetchAll();
        $q=$this->db->prepare('SELECT c.provider,c.configuration_id,c.provider_order_id,c.status,c.created_at,c.updated_at,p.label FROM portal_external_checkouts c JOIN portal_payment_configurations p ON p.id=c.configuration_id WHERE c.order_id=?');
        $q->execute([$orderId]);return ['payments'=>$payments,'refunds'=>$refunds,'checkout'=>$q->fetch()?:null];
    }

    public function run(int $now): array
    {
        $expired=$this->db->prepare("SELECT o.id,o.user_id FROM portal_orders o JOIN portal_external_checkouts c ON c.order_id=o.id
            WHERE o.status='pending' AND o.expires_at<=? AND c.status='created' AND c.provider_order_id IS NULL LIMIT 100");
        $expired->execute([$now]);$cancelled=0;
        foreach($expired->fetchAll()as$target){
            $cancelled+=Database::transaction($this->db,function(PDO $db)use($target,$now):int{
                $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$target['user_id']]);
                $q=$db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$target['id']]);$order=$q->fetch();
                $q=$db->prepare('SELECT status,provider_order_id FROM portal_external_checkouts WHERE order_id=? FOR UPDATE');$q->execute([$target['id']]);$checkout=$q->fetch();
                if($order['status']!=='pending'||(int)$order['expires_at']>$now||$checkout['status']!=='created'||$checkout['provider_order_id']!==null)return 0;
                $db->prepare("UPDATE portal_orders SET status='cancelled',pending_user_id=NULL,updated_at=? WHERE id=?")->execute([$now,$target['id']]);return 1;
            });
        }
        $orders=$this->db->query("SELECT c.order_id FROM portal_external_checkouts c JOIN portal_orders o ON o.id=c.order_id WHERE o.status IN('pending','payment_review') AND c.provider_order_id IS NOT NULL ORDER BY c.updated_at LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        $reconciled=0;$failed=0;
        foreach($orders as$id){try{$this->reconcile($id,$now,(int)$this->order($id)['expires_at']>$now);$reconciled++;}catch(Problem){$failed++;}}
        $ids=$this->db->query("SELECT id FROM portal_external_refunds WHERE status IN('pending','submitted','review') ORDER BY updated_at LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as$id){try{$this->workRefund($id,$now);}catch(Problem $error){$failed++;$this->db->prepare('UPDATE portal_external_refunds SET last_error=?,updated_at=? WHERE id=?')->execute([$error->error,$now,$id]);}}
        return ['reconciled'=>$reconciled,'cancelled_unopened'=>$cancelled,'refunds_checked'=>count($ids),'failed'=>$failed];
    }

    private function order(string $id): array
    {
        Backoffice::hexId($id);$q=$this->db->prepare('SELECT * FROM portal_orders WHERE id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new Problem('order_not_found',404);return$row;
    }

    private function checkout(string $id): array
    {
        Backoffice::hexId($id);$q=$this->db->prepare('SELECT * FROM portal_external_checkouts WHERE order_id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new Problem('payment_attempt_not_found',404);return$row;
    }

    private function archive(array $checkout): array
    {
        return json_decode($this->secrets->decrypt($checkout['config_cipher'],'external-order:'.$checkout['order_id']),true,32,JSON_THROW_ON_ERROR);
    }

    private function requestConfiguration(array $checkout): array
    {
        $archive=$this->archive($checkout);
        $current=(new PaymentConfigurations($this->db,$this->secrets))->configuration($checkout['configuration_id']);
        $candidate=$current['configuration'];
        $fields=$checkout['provider']==='paypal'?['environment','client_id','merchant_id']:['environment','account_id'];
        foreach($fields as$field)if(($archive[$field]??null)!==($candidate[$field]??null))return$archive;
        return $candidate;
    }
}
