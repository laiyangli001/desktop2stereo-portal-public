<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Immutable refund intentions/receipts; mutable leased delivery state never proves a refund. */
final readonly class AlipayRefunds
{
    public function __construct(private PDO $db, private PaymentProfiles $profiles, private ?Closure $transport = null) {}

    public function request(int $adminId, string $paymentId, mixed $amount, string $reason, string $key, array $context, int $now, ?string $orderId = null): array
    {
        Permissions::require($this->db,$adminId,'refunds.manage');
        $email = $this->adminEmail($adminId);
        $amount = Money::positive($amount);
        $reason = trim($reason);
        if (!preg_match('/^[a-f0-9]{64}$/D',$paymentId) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key) ||
            !mb_check_encoding($reason,'UTF-8') || mb_strlen($reason,'UTF-8')<1 || mb_strlen($reason,'UTF-8')>256 ||
            preg_match('/[\x00-\x1f\x7f]/',$reason)) { throw new Problem('refund_input_invalid'); }
        $select = $this->db->prepare('SELECT p.*,o.user_id,o.product FROM portal_payments p JOIN portal_orders o ON o.id=p.order_id WHERE p.id=?');
        $select->execute([$paymentId]);
        $payment = $select->fetch();
        if (!$payment || ($orderId !== null && $payment['order_id'] !== $orderId)) { throw new Problem('payment_not_found',404); }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$email,$payment,$paymentId,$amount,$reason,$key,$context,$now): array {
            $this->lockOrder((int)$payment['user_id'],$payment['order_id']);
            $select = $db->prepare('SELECT id,amount,reason FROM portal_refund_requests WHERE payment_id=? AND idempotency_key=?');
            $select->execute([$paymentId,$key]);
            $existing = $select->fetch();
            if ($existing) {
                if ((int)$existing['amount'] !== $amount || $existing['reason'] !== $reason) { throw new Problem('idempotency_conflict',409); }
                return $this->view($existing['id']);
            }
            $select = $db->prepare('SELECT COALESCE(SUM(r.amount),0) FROM portal_refund_requests r LEFT JOIN portal_refund_cancellations c ON c.refund_id=r.id WHERE r.payment_id=? AND c.refund_id IS NULL');
            $select->execute([$paymentId]);
            if ($amount>(int)$payment['amount']-(int)$select->fetchColumn()) { throw new Problem('refund_amount_exceeded',409); }
            $select = $db->prepare("SELECT COUNT(*) FROM portal_refund_requests r JOIN portal_refund_states s ON s.id=r.id
                WHERE r.payment_id=? AND s.status<>'succeeded' AND NOT EXISTS (SELECT 1 FROM portal_refund_cancellations c WHERE c.refund_id=r.id)");
            $select->execute([$paymentId]);
            if ((int)$select->fetchColumn()>0) { throw new Problem('refund_pending',409); }
            $select = $db->prepare('SELECT MAX(s.last_sent_at) FROM portal_refund_requests r JOIN portal_refund_states s ON s.id=r.id WHERE r.payment_id=?');
            $select->execute([$paymentId]);
            $last = $select->fetchColumn();
            if ($last !== null && (int)$last>$now-3) { throw new Problem('refund_rate_limited',429); }
            $select = $db->prepare('SELECT f.id,p.payment_id FROM portal_order_fulfillments f LEFT JOIN portal_payment_fulfillments p ON p.order_id=f.id WHERE f.id=?');
            $select->execute([$payment['order_id']]);
            $binding = $payment['product'] === 'wallet_recharge' ? null : $select->fetch();
            if ($binding && $binding['payment_id'] === null) { throw new Problem('payment_fulfillment_review_required',409); }
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_refund_requests (id,order_id,payment_id,idempotency_key,amount,reason,operator_id,created_at)
                VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$payment['order_id'],$paymentId,$key,$amount,$reason,$adminId,$now]);
            $db->prepare("INSERT INTO portal_refund_states (id,status,next_attempt_at,updated_at) VALUES (?,'pending',?,?)")->execute([$id,$now,$now]);
            if ($payment['product'] === 'wallet_recharge') {
                WalletRecharges::reserveRefund($db,$id,$payment['order_id'],'alipay_pc',$paymentId,$amount,$now);
            } elseif ($binding && $binding['payment_id'] === $paymentId) {
                $db->prepare("UPDATE portal_orders SET status='refund_pending',updated_at=? WHERE id=? AND status IN ('paid','partially_refunded')")
                    ->execute([$now,$payment['order_id']]);
            }
            Audit::admin($db,$adminId,$email,'REQUEST_REFUND',$id,$context,'PENDING',
                ['payment_id'=>$paymentId,'order_id'=>$payment['order_id'],'amount'=>$amount,'currency'=>'CNY'],$now);
            return $this->view($id);
        });
    }

    public function records(int $adminId, string $orderId, array $context, int $now, int $page = 1): array
    {
        Permissions::require($this->db,$adminId,'refunds.manage');
        $email = $this->adminEmail($adminId);
        if ($page<1 || $page>100000) { throw new Problem('page_invalid'); }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$email,$orderId,$context,$now,$page): array {
            $select = $db->prepare('SELECT p.id,p.amount,p.currency,p.received_at,COALESCE(SUM(r.amount),0) AS confirmed_refunded,
                (SELECT COALESCE(SUM(q.amount),0) FROM portal_refund_requests q WHERE q.payment_id=p.id AND NOT EXISTS (SELECT 1 FROM portal_refund_cancellations c WHERE c.refund_id=q.id)) AS reserved_amount,
                EXISTS(SELECT 1 FROM portal_payment_fulfillments f WHERE f.payment_id=p.id) AS fulfills_license,
                EXISTS(SELECT 1 FROM portal_refund_requests q JOIN portal_refund_states s ON s.id=q.id WHERE q.payment_id=p.id AND s.status<>\'succeeded\' AND NOT EXISTS (SELECT 1 FROM portal_refund_cancellations c WHERE c.refund_id=q.id)) AS has_pending
                FROM portal_payments p LEFT JOIN portal_refund_receipts r ON r.payment_id=p.id
                WHERE p.order_id=? GROUP BY p.id,p.amount,p.currency,p.received_at ORDER BY p.received_at,p.id');
            $select->execute([$orderId]);
            $payments = $select->fetchAll();
            $select = $db->prepare('SELECT r.id,r.payment_id,r.amount,r.reason,r.operator_id,r.created_at,s.next_attempt_at,s.attempts,s.last_sent_at,s.lease_until,
                CASE WHEN c.refund_id IS NOT NULL THEN \'cancelled\' ELSE s.status END AS status,
                COALESCE(t.retry_mode,\'automatic\') AS retry_mode
                FROM portal_refund_requests r JOIN portal_refund_states s ON s.id=r.id
                LEFT JOIN portal_refund_controls t ON t.refund_id=r.id LEFT JOIN portal_refund_cancellations c ON c.refund_id=r.id WHERE r.order_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT 50 OFFSET '.(($page-1)*50));
            $select->execute([$orderId]);
            $refunds = $select->fetchAll();
            Audit::admin($db,$adminId,$email,'VIEW_REFUNDS',$orderId,$context,'SUCCESS',['count'=>count($refunds)],$now);
            $total = $db->prepare('SELECT COUNT(*) FROM portal_refund_requests WHERE order_id=?');
            $total->execute([$orderId]);
            return ['payments'=>$payments,'refunds'=>$refunds,'page'=>$page,'total'=>(int)$total->fetchColumn(),'per_page'=>50];
        });
    }

    /** Requests have already been approved/audited. Workers may only advance these saved intentions. */
    public function process(string $id, array $context, int $now, ?int $adminId = null): array
    {
        $email = '';
        if ($adminId !== null) {
            Permissions::require($this->db,$adminId,'refunds.manage');
            $email = $this->adminEmail($adminId);
        }
        $request = $this->load($id);
        $token = bin2hex(random_bytes(16));
        $claimed = Database::transaction($this->db,function (PDO $db) use ($request,$id,$token,$context,$now,$adminId,$email): ?array {
            $order=$this->lockOrder((int)$request['user_id'],$request['order_id']);
            $select = $db->prepare('SELECT * FROM portal_refund_states WHERE id=? FOR UPDATE');
            $select->execute([$id]);
            $state = $select->fetch();
            $current = $this->load($id);
            if ($current['status'] === 'cancelled' || $state['status'] === 'succeeded' || (int)$state['next_attempt_at']>$now || (int)$state['lease_until']>$now) { return null; }
            $db->prepare("UPDATE portal_refund_states SET status='unknown',attempts=attempts+1,lease_token=?,lease_until=?,next_attempt_at=?,updated_at=? WHERE id=?")
                ->execute([$token,$now+120,$now+60,$now,$id]);
            Audit::admin($db,$adminId,$email,'PROCESS_REFUND',$id,$context,'PENDING',
                ['phase'=>'intent','automatic'=>$adminId === null,'approved_by'=>(int)$request['operator_id']],$now);
            return ['attempts'=>(int)$state['attempts'],'query_only'=>$current['retry_mode'] === 'query_only'];
        });
        if ($claimed === null) { return $this->view($id); }
        try {
            $config = $this->profiles->read($request['order_id']);
            foreach (['environment','app_id','seller_id'] as $key) {
                if ($config[$key] !== $request[$key]) { throw new Problem('alipay_identity_mismatch'); }
            }
            $client = new AlipayClient($config,$this->transport);
            if ($claimed['attempts']>0 || $claimed['query_only']) {
                $proof = $client->call('alipay.trade.fastpay.refund.query',[
                    'trade_no'=>$request['trade_no'],'out_request_no'=>'D2SR'.$id,
                ],$now);
                $this->identity($proof,$request);
                if (($proof['code'] ?? null) !== '10000') { return $this->pending($id,$token,$context,$now,$adminId,$email); }
                if (($proof['refund_status'] ?? '') === 'REFUND_SUCCESS') {
                    $this->match($proof,$request,true);
                    return $this->complete($request,$proof,$token,$context,$now,$adminId,$email);
                }
                $this->optionalMatch($proof,$request);
                if ($claimed['query_only']) { return $this->pending($id,$token,$context,$now,$adminId,$email); }
                if (($proof['refund_status'] ?? '') !== '') { return $this->pending($id,$token,$context,$now,$adminId,$email); }
                // A missing refund status is not success. Before any identical retry, verify the original trade.
                $trade = $client->call('alipay.trade.query',['trade_no'=>$request['trade_no']],$now);
                $this->identity($trade,$request);
                if (($trade['code'] ?? null) !== '10000') { return $this->pending($id,$token,$context,$now,$adminId,$email); }
                $this->matchTrade($trade,$request);
                if (($trade['trade_status'] ?? '') !== 'TRADE_SUCCESS') { return $this->pending($id,$token,$context,$now,$adminId,$email); }
            }
            $sent = $this->db->prepare('UPDATE portal_refund_states SET last_sent_at=? WHERE id=? AND lease_token=? AND lease_until>?');
            $sent->execute([$now,$id,$token,$now]);
            if ($sent->rowCount() !== 1) { throw new Problem('refund_lease_lost',409); }
            $amount = (int)$request['amount'];
            $proof = $client->call('alipay.trade.refund',[
                'trade_no'=>$request['trade_no'],'out_trade_no'=>OrderNumbers::merchant($this->db,$request['order_id'],'alipay_pc'),'out_request_no'=>'D2SR'.$id,
                'refund_amount'=>intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT),'refund_reason'=>$request['reason'],
            ],$now);
            $this->identity($proof,$request);
            if (($proof['code'] ?? null) === '10000') {
                $this->match($proof,$request,false);
                // refund_fee is cumulative. Even fund_change=Y is reconciled by this exact request ID.
            }
            return $this->pending($id,$token,$context,$now,$adminId,$email);
        } catch (\Throwable $error) {
            $this->pending($id,$token,$context,$now,$adminId,$email,'FAILURE');
            throw $error;
        }
    }

    public function view(string $id): array
    {
        $request = $this->load($id);
        return array_intersect_key($request,array_flip(['id','order_id','payment_id','amount','reason','status','retry_mode','created_at','next_attempt_at']));
    }

    private function load(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Problem('refund_not_found',404); }
        $select = $this->db->prepare('SELECT r.*,o.product,CASE WHEN c.refund_id IS NOT NULL THEN \'cancelled\' ELSE s.status END AS status,
            COALESCE(t.retry_mode,\'automatic\') AS retry_mode,s.attempts,s.next_attempt_at,s.lease_until,p.trade_no,p.currency,p.environment,p.app_id,p.seller_id,
            p.amount AS paid_amount,o.user_id FROM portal_refund_requests r JOIN portal_refund_states s ON s.id=r.id
            JOIN portal_payments p ON p.id=r.payment_id JOIN portal_orders o ON o.id=r.order_id
            LEFT JOIN portal_refund_controls t ON t.refund_id=r.id LEFT JOIN portal_refund_cancellations c ON c.refund_id=r.id WHERE r.id=?');
        $select->execute([$id]);
        $request = $select->fetch();
        if (!$request) { throw new Problem('refund_not_found',404); }
        return $request;
    }

    private function lockOrder(int $userId, string $orderId): array
    {
        $select = $this->db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');
        $select->execute([$userId]);
        $select = $this->db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');
        $select->execute([$orderId]);$order=$select->fetch();
        if($order===false)throw new Problem('order_not_found',404);
        return $order;
    }

    private function identity(array $proof, array $request): void
    {
        foreach (['app_id','auth_app_id','seller_id'] as $key) {
            if (isset($proof[$key]) && $proof[$key] !== $request[$key === 'seller_id' ? 'seller_id':'app_id']) {
                throw new Problem('alipay_identity_mismatch');
            }
        }
        foreach (['currency','trans_currency','refund_currency'] as $key) {
            if (isset($proof[$key]) && $proof[$key] !== 'CNY') { throw new Problem('alipay_refund_mismatch'); }
        }
    }

    private function optionalMatch(array $proof, array $request): void
    {
        foreach (['out_trade_no'=>OrderNumbers::merchant($this->db,$request['order_id'],'alipay_pc'),'trade_no'=>$request['trade_no'],'out_request_no'=>'D2SR'.$request['id']] as $key=>$value) {
            if (isset($proof[$key]) && $proof[$key] !== $value) { throw new Problem('alipay_refund_mismatch'); }
        }
        foreach (['refund_amount'=>(int)$request['amount'],'total_amount'=>(int)$request['paid_amount']] as $key=>$value) {
            if (isset($proof[$key]) && Money::decimal($proof[$key]) !== $value) { throw new Problem('alipay_refund_mismatch'); }
        }
    }

    private function matchTrade(array $proof, array $request): void
    {
        if (($proof['out_trade_no'] ?? null) !== OrderNumbers::merchant($this->db,$request['order_id'],'alipay_pc') || ($proof['trade_no'] ?? null) !== $request['trade_no'] ||
            Money::decimal($proof['total_amount'] ?? '') !== (int)$request['paid_amount']) { throw new Problem('alipay_refund_mismatch'); }
    }

    private function match(array $proof, array $request, bool $query): void
    {
        $this->optionalMatch($proof,$request);
        if (($proof['out_trade_no'] ?? null) !== OrderNumbers::merchant($this->db,$request['order_id'],'alipay_pc') || ($proof['trade_no'] ?? null) !== $request['trade_no']) {
            throw new Problem('alipay_refund_mismatch');
        }
        if ($query) {
            if (($proof['out_request_no'] ?? null) !== 'D2SR'.$request['id'] ||
                Money::decimal($proof['refund_amount'] ?? '') !== (int)$request['amount'] ||
                Money::decimal($proof['total_amount'] ?? '') !== (int)$request['paid_amount']) { throw new Problem('alipay_refund_mismatch'); }
        } else {
            $fee = $proof['refund_fee'] ?? '';
            $cumulative = in_array($fee,['0','0.00'],true) ? 0 : Money::decimal($fee);
            if ($cumulative>(int)$request['paid_amount'] ||
                (($proof['fund_change'] ?? '') === 'Y' && $cumulative<(int)$request['amount'])) { throw new Problem('alipay_refund_mismatch'); }
        }
    }

    private function complete(array $request, array $proof, string $token, array $context, int $now, ?int $adminId, string $email): array
    {
        $id = $request['id'];
        return Database::transaction($this->db,function (PDO $db) use ($request,$proof,$token,$context,$now,$adminId,$email,$id): array {
            $order=$this->lockOrder((int)$request['user_id'],$request['order_id']);
            $select = $db->prepare('SELECT status,lease_token FROM portal_refund_states WHERE id=? FOR UPDATE');
            $select->execute([$id]);
            $state = $select->fetch();
            if ($state['status'] === 'succeeded' || $state['lease_token'] !== $token) { return $this->view($id); }
            $hash = hash('sha256',json_encode([$request['payment_id'],'D2SR'.$id,(int)$request['amount'],'REFUND_SUCCESS'],JSON_THROW_ON_ERROR));
            $db->prepare('INSERT INTO portal_refund_receipts (id,payment_id,amount,proof_hash,confirmed_at) VALUES (?,?,?,?,?)')
                ->execute([$id,$request['payment_id'],$request['amount'],$hash,$now]);
            $db->prepare("UPDATE portal_refund_states SET status='succeeded',lease_token=NULL,lease_until=0,updated_at=? WHERE id=?")->execute([$now,$id]);
            $select = $db->prepare('SELECT f.license_id FROM portal_payment_fulfillments p JOIN portal_order_fulfillments f ON f.id=p.order_id
                WHERE p.payment_id=? AND p.order_id=?');
            $select->execute([$request['payment_id'],$request['order_id']]);
            $licenseId = $select->fetchColumn();
            $effect='unchanged';
            if ($order['product'] === 'wallet_recharge') {
                $select = $db->prepare('SELECT SUM(amount) FROM portal_refund_receipts WHERE payment_id=?');
                $select->execute([$request['payment_id']]);
                $refunded = (int)$select->fetchColumn();
                if ($refunded>(int)$request['paid_amount']) { throw new Problem('refund_amount_exceeded',409); }
                WalletRecharges::completeRefund($db,$id,$now);
                $full = $refunded === (int)$request['paid_amount'];
                $db->prepare("UPDATE portal_orders SET refunded_amount=?,status=IF(status='payment_review',status,?),updated_at=? WHERE id=?")
                    ->execute([$refunded,$full ? 'refunded':'partially_refunded',$now,$request['order_id']]);
            } elseif ($licenseId !== false) {
                $select = $db->prepare('SELECT SUM(amount) FROM portal_refund_receipts WHERE payment_id=?');
                $select->execute([$request['payment_id']]);
                $refunded = (int)$select->fetchColumn();
                if ($refunded>(int)$request['paid_amount']) { throw new Problem('refund_amount_exceeded',409); }
                $full = $refunded === (int)$request['paid_amount'];
                $db->prepare("UPDATE portal_orders SET refunded_amount=?,status=IF(status='payment_review',status,?),updated_at=? WHERE id=?")
                    ->execute([$refunded,$full ? 'refunded':'partially_refunded',$now,$request['order_id']]);
                if ($full) {
                    $effect='revoked';
                    $db->prepare("UPDATE portal_licenses SET status='revoked',session_version=session_version+1,updated_at=? WHERE id=?")
                        ->execute([$now,$licenseId]);
                    $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=(SELECT product FROM portal_licenses WHERE id=?)')->execute([$request['user_id'],$licenseId]);
                }
            }
            TransactionNotifications::record($db,['id'=>$request['order_id'],'user_id'=>$request['user_id'],
                'currency'=>$request['currency'],'amount'=>(int)$request['paid_amount']], 'REFUND_CONFIRMED','alipay_refund:'.$id,(int)$request['amount'],$effect,$now);
            Audit::admin($db,$adminId,$email,'CONFIRM_REFUND',$id,$context,'SUCCESS',
                ['payment_id'=>$request['payment_id'],'order_id'=>$request['order_id'],'amount'=>(int)$request['amount'],
                    'automatic'=>$adminId === null,'approved_by'=>(int)$request['operator_id']],$now);
            return $this->view($id);
        });
    }

    private function pending(string $id, string $token, array $context, int $now, ?int $adminId, string $email, string $result = 'PENDING'): array
    {
        Database::transaction($this->db,function (PDO $db) use ($id,$token,$context,$now,$adminId,$email,$result): void {
            $updated = $db->prepare('UPDATE portal_refund_states SET lease_token=NULL,lease_until=0,updated_at=? WHERE id=? AND lease_token=?');
            $updated->execute([$now,$id,$token]);
            if ($updated->rowCount()>0) {
                Audit::admin($db,$adminId,$email,'PROCESS_REFUND',$id,$context,$result,['phase'=>'result','automatic'=>$adminId === null],$now);
            }
        });
        return $this->view($id);
    }

    private function adminEmail(int $id): string
    {
        $select = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $select->execute([$id]);
        $email = $select->fetchColumn();
        if (!is_string($email)) { throw new Problem('admin_auth_invalid',401); }
        return $email;
    }
}
