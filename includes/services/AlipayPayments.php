<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class AlipayPayments
{
    public function __construct(private PDO $db, private PaymentProfiles $profiles, private ?Closure $transport = null) {}

    public function notify(string $raw, array $context, int $now): array
    {
        $untrusted = AlipayRSA::form($raw);
        if (($untrusted['notify_type'] ?? '') !== 'trade_status_sync') {
            // Unknown products are not enabled. Verify before recording minimal metadata.
            (new AlipayClient($this->profiles->current()))->notification($raw);
            $type = substr(preg_replace('/[^A-Za-z0-9_.-]/','',$untrusted['notify_type'] ?? ''),0,64);
            $this->event(hash('sha256',random_bytes(32)),null,'UNSUPPORTED:'.substr($type,0,52),
                hash('sha256',$type),$context,'REJECTED',$now);
            throw new Problem('alipay_notification_unsupported');
        }
        $id=OrderNumbers::resolveMerchant($this->db,$untrusted['out_trade_no']??'','alipay_pc');
        if($id===null)throw new Problem('alipay_order_invalid');
        $config = $this->profiles->read($id);
        $proof = (new AlipayClient($config))->notification($raw);
        foreach (['notify_id','trade_no'] as $key) {
            if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$proof[$key] ?? '')) { throw new Problem('alipay_notification_invalid'); }
        }
        if (!in_array($proof['trade_status'] ?? '',['TRADE_SUCCESS','TRADE_FINISHED','WAIT_BUYER_PAY','TRADE_CLOSED'],true) ||
            (isset($proof['currency']) && $proof['currency'] !== 'CNY')) { throw new Problem('alipay_payment_mismatch'); }
        $amount = Money::decimal($proof['total_amount'] ?? '');
        if ($amount>10000000000) { throw new Problem('alipay_payment_mismatch'); }
        $eventId = hash('sha256',$config['environment'].'|'.$config['app_id'].'|'.$proof['notify_id']);
        return $this->settle($id,$config,$proof,$amount,$eventId,$context,$now);
    }

    /** Query with the immutable merchant snapshot; no public caller can submit a trusted proof. */
    public function query(string $id, array $context, int $now): array
    {
        $config = $this->profiles->read($id);
        try {
            $proof = (new AlipayClient($config,$this->transport))->call('alipay.trade.query',['out_trade_no'=>OrderNumbers::merchant($this->db,$id,'alipay_pc')],$now);
            $this->identity($config,$proof);
            if (($proof['code'] ?? null) !== '10000') {
                $state = ($proof['sub_code'] ?? null) === 'ACQ.TRADE_NOT_EXIST' ? 'NOT_FOUND':'UNKNOWN';
                $this->queryEvent($id,$state,$context,$now);
                return ['status'=>$this->status($id),'provider_status'=>$state];
            }
            if (($proof['out_trade_no'] ?? null) !== OrderNumbers::merchant($this->db,$id,'alipay_pc') ||
                !in_array($proof['trade_status'] ?? null,['WAIT_BUYER_PAY','TRADE_CLOSED','TRADE_SUCCESS','TRADE_FINISHED'],true) ||
                (isset($proof['currency']) && $proof['currency'] !== 'CNY') ||
                (isset($proof['trans_currency']) && $proof['trans_currency'] !== 'CNY')) {
                throw new Problem('alipay_payment_mismatch');
            }
            $amount = Money::decimal($proof['total_amount'] ?? '');
            if ($amount>10000000000) { throw new Problem('alipay_payment_mismatch'); }
            $trade = $proof['trade_no'] ?? '';
            if (!is_string($trade) || ($trade !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$trade)) ||
                ($trade === '' && in_array($proof['trade_status'],['TRADE_SUCCESS','TRADE_FINISHED'],true))) {
                throw new Problem('alipay_payment_mismatch');
            }
            $proof['trade_no'] = $trade;
            $proof['buyer_id'] = $proof['buyer_open_id'] ?? $proof['buyer_user_id'] ?? null;
            $result = $this->settle($id,$config,$proof,$amount,hash('sha256',random_bytes(32)),$context,$now,'QUERY:');
            return $result + ['provider_status'=>$proof['trade_status'],'trade_no'=>$trade];
        } catch (\Throwable $error) {
            // Record only bounded operation metadata, never the provider body or credentials.
            $this->queryEvent($id,'FAILED',$context,$now);
            throw $error;
        }
    }

    /** Only a verified provider close can release the local pending-order slot. */
    public function closeExpired(string $id, array $context, int $now): array
    {
        return $this->close($id,$context,$now,true);
    }

    public function closePending(string $id, array $context, int $now): array
    {
        if ($this->status($id) !== 'pending') { throw new Problem('order_not_cancellable',409); }
        return $this->close($id,$context,$now,false);
    }

    private function close(string $id, array $context, int $now, bool $expiredOnly): array
    {
        $queried = $this->query($id,$context,$now);
        if (($queried['provider_status'] ?? '') !== 'WAIT_BUYER_PAY' || $this->status($id) !== 'pending') { return $queried; }
        $select = $this->db->prepare('SELECT expires_at,amount FROM portal_orders WHERE id=?');
        $select->execute([$id]);
        $order = $select->fetch();
        if ($expiredOnly && (int)$order['expires_at']>$now) { return $queried; }
        $config = $this->profiles->read($id);
        try {
            $proof = (new AlipayClient($config,$this->transport))->call('alipay.trade.close',['out_trade_no'=>OrderNumbers::merchant($this->db,$id,'alipay_pc')],$now);
            $this->identity($config,$proof);
            if (($proof['code'] ?? null) !== '10000') {
                // Payment may have won the race, or close may already have succeeded.
                return $this->query($id,$context,$now);
            }
            if (($proof['out_trade_no'] ?? null) !== OrderNumbers::merchant($this->db,$id,'alipay_pc') ||
                !is_string($proof['trade_no'] ?? null) ||
                !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$proof['trade_no']) ||
                (($queried['trade_no'] ?? '') !== '' && $proof['trade_no'] !== $queried['trade_no'])) {
                throw new Problem('alipay_close_unknown',502);
            }
            $proof['trade_status'] = 'TRADE_CLOSED';
            return $this->settle($id,$config,$proof,(int)$order['amount'],hash('sha256',random_bytes(32)),$context,$now,'CLOSE:')
                + ['provider_status'=>'TRADE_CLOSED'];
        } catch (\Throwable $error) {
            $this->queryEvent($id,'CLOSE_FAILED',$context,$now);
            throw $error;
        }
    }

    private function identity(array $config, array $proof): void
    {
        // These fields are not guaranteed by query/close. The signed merchant request
        // and verified response establish context; reject contradictory optional fields.
        foreach (['app_id','auth_app_id','seller_id'] as $key) {
            $expected = $key === 'seller_id' ? $config['seller_id']:$config['app_id'];
            if (isset($proof[$key]) && $proof[$key] !== $expected) { throw new Problem('alipay_identity_mismatch'); }
        }
    }

    private function status(string $id): string
    {
        $select = $this->db->prepare("SELECT status FROM portal_orders WHERE id=? AND payment_method='alipay_pc'");
        $select->execute([$id]);
        $status = $select->fetchColumn();
        if (!is_string($status)) { throw new Problem('alipay_order_invalid'); }
        return $status;
    }

    private function queryEvent(string $id, string $state, array $context, int $now): void
    {
        $this->event(hash('sha256',random_bytes(32)),$id,'QUERY:'.$state,hash('sha256',$state),$context,'PENDING',$now);
    }

    private function settle(string $id, array $config, array $proof, int $amount, string $eventId, array $context, int $now, string $prefix = ''): array
    {
        $eventHash = hash('sha256',json_encode([$id,$proof['trade_no'],$proof['trade_status'],$amount,'CNY'],JSON_THROW_ON_ERROR));
        $tradeId = hash('sha256',$config['environment'].'|'.$proof['trade_no']);
        $select = $this->db->prepare("SELECT user_id FROM portal_orders WHERE id=? AND payment_method='alipay_pc'");
        $select->execute([$id]);
        $userId = $select->fetchColumn();
        if ($userId === false) { throw new Problem('alipay_order_invalid'); }
        return Database::transaction($this->db,function (PDO $db) use ($userId,$id,$config,$proof,$amount,$eventId,$eventHash,$tradeId,$context,$now,$prefix): array {
            $select = $db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');
            $select->execute([$userId]);
            $userStatus = $select->fetchColumn();
            $select = $db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');
            $select->execute([$id]);
            $order = $select->fetch();
            if ($order['payment_method'] !== 'alipay_pc' || $order['currency'] !== 'CNY' || (int)$order['amount'] !== $amount ||
                ($order['product']!=='wallet_recharge'&&!(new ProductCatalog($db))->exists($order['product']))) {
                throw new Problem('alipay_payment_mismatch');
            }
            $select = $db->prepare('SELECT event_hash FROM portal_payment_events WHERE id=?');
            $select->execute([$eventId]);
            $existing = $select->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals($existing,$eventHash)) { throw new Problem('alipay_event_conflict'); }
                return ['status'=>$order['status'],'duplicate'=>true];
            }
            $this->event($eventId,$id,$prefix.$proof['trade_status'],$eventHash,$context,'VERIFIED',$now);
            if ($proof['trade_status'] === 'WAIT_BUYER_PAY') { return ['status'=>$order['status']]; }
            if ($proof['trade_status'] === 'TRADE_CLOSED') {
                if ($order['status'] === 'pending') {
                    $db->prepare("UPDATE portal_orders SET status='cancelled',pending_user_id=NULL,updated_at=? WHERE id=?")->execute([$now,$id]);
                }
                return ['closed'=>true,'status'=>$order['status'] === 'pending' ? 'cancelled':$order['status']];
            }
            $select = $db->prepare('SELECT * FROM portal_payments WHERE id=?');
            $select->execute([$tradeId]);
            $receipt = $select->fetch();
            if ($receipt !== false) {
                if ($receipt['order_id'] !== $id || (int)$receipt['amount'] !== $amount || $receipt['app_id'] !== $config['app_id'] ||
                    $receipt['seller_id'] !== $config['seller_id']) { throw new Problem('alipay_trade_conflict'); }
                // A later notification must not resurrect an already refunded/revoked authorization.
                return ['status'=>$order['status'],'duplicate'=>true];
            }
            $buyer = $proof['buyer_id'] ?? null;
            if ($buyer !== null && (strlen($buyer)>128 || !preg_match('/^[A-Za-z0-9_-]+$/D',$buyer))) { throw new Problem('alipay_notification_invalid'); }
            $payerHash = $this->profiles->payerHash($config['environment'],$buyer);
            $db->prepare('INSERT INTO portal_payments (id,order_id,environment,app_id,seller_id,trade_no,amount,currency,payer_hash,received_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$tradeId,$id,$config['environment'],$config['app_id'],$config['seller_id'],
                    $proof['trade_no'],$amount,'CNY',$payerHash,$now]);
            $control = $db->prepare('SELECT login_blocked,purchase_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');
            $control->execute([$userId]);
            $restricted = $control->fetch();
            $verified = $db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');
            $verified->execute([$userId]);
            $allowed = $userStatus === 'active' && (int)$verified->fetchColumn()>0 &&
                ($restricted === false || (!(int)$restricted['login_blocked'] && !(int)$restricted['purchase_blocked']));
            $recharge = $order['product'] === 'wallet_recharge';
            $fulfilled = $order['status'] === 'pending' && $allowed &&
                ($recharge ? WalletRecharges::creditExternal($db,$order,'alipay_pc',$tradeId,$now) : OrderFulfillment::grant($db,$order,$now));
            if ($fulfilled && !$recharge) {
                $db->prepare('INSERT INTO portal_payment_fulfillments (payment_id,order_id) VALUES (?,?)')->execute([$tradeId,$id]);
            }
            $status = $fulfilled ? 'paid':'payment_review';
            if (!$fulfilled) TransactionNotifications::record($db,$order,'PAYMENT_REVIEW','payment_review:'.$tradeId,$amount,'unchanged',$now);
            $db->prepare('UPDATE portal_orders SET status=?,pending_user_id=NULL,paid_at=COALESCE(paid_at,?),payment_account_identifier=COALESCE(payment_account_identifier,?),updated_at=? WHERE id=?')
                ->execute([$status,$now,$payerHash,$now,$id]);
            Audit::user($db,(int)$userId,$order['email'],'PURCHASE',$fulfilled ? 'SUCCESS':'BLOCKED',$context,$now);
            return ['status'=>$status];
        });
    }

    private function event(string $id, ?string $orderId, string $type, string $hash, array $context, string $result, int $now): void
    {
        $this->db->prepare('INSERT INTO portal_payment_events (id,order_id,event_type,event_hash,source_ip,result,received_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id,$orderId,$type,$hash,$context['source_ip'] ?? null,$result,$now]);
    }
}
