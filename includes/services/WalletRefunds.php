<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class WalletRefunds
{
    public function __construct(private PDO $db) {}

    public function request(int $adminId, string $orderId, mixed $amount, string $reason, string $key, array $context, int $now): array
    {
        $email = $this->admin($adminId);
        $amount = Money::positive($amount);
        $reason = trim($reason);
        if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key) || !mb_check_encoding($reason,'UTF-8') ||
            mb_strlen($reason,'UTF-8')<1 || mb_strlen($reason,'UTF-8')>256 || preg_match('/[\x00-\x1f\x7f]/',$reason)) {
            throw new Problem('refund_input_invalid');
        }
        $source = $this->order($orderId);
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$email,$source,$orderId,$amount,$reason,$key,$context,$now): array {
            $select = $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');
            $select->execute([$source['user_id']]);
            $select = $db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');
            $select->execute([$orderId]);
            $order = $select->fetch();
            $select = $db->prepare('SELECT * FROM portal_wallet_refunds WHERE order_id=? AND idempotency_key=?');
            $select->execute([$orderId,$key]);
            $existing = $select->fetch();
            if ($existing) {
                if ((int)$existing['amount'] !== $amount || $existing['reason'] !== $reason) { throw new Problem('idempotency_conflict',409); }
                return $this->view($existing);
            }
            if (!in_array($order['status'],['paid','partially_refunded'],true)) { throw new Problem('order_not_refundable',409); }
            $this->purchase($order);
            $select = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM portal_wallet_refunds WHERE order_id=?');
            $select->execute([$orderId]);
            $refunded = (int)$select->fetchColumn();
            if ($refunded !== (int)$order['refunded_amount']) { throw new Problem('wallet_refund_history_mismatch',409); }
            if ($amount>(int)$order['amount']-$refunded) { throw new Problem('refund_amount_exceeded',409); }
            $select = $db->prepare('SELECT balance FROM portal_wallets WHERE user_id=? AND currency=? FOR UPDATE');
            $select->execute([$order['user_id'],$order['currency']]);
            $balance = $select->fetchColumn();
            if ($balance === false) { throw new Problem('wallet_purchase_not_found',409); }
            $balance = (int)$balance;
            if ($balance>Money::MAXIMUM-$amount) { throw new Problem('wallet_balance_limit',409); }
            $select = $db->prepare('SELECT license_id FROM portal_order_fulfillments WHERE id=?');
            $select->execute([$orderId]);
            $licenseId = $select->fetchColumn();
            if (!is_string($licenseId)) { throw new Problem('payment_fulfillment_review_required',409); }
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_wallet_refunds (id,order_id,user_id,currency,idempotency_key,amount,reason,operator_id,created_at)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id,$orderId,$order['user_id'],$order['currency'],$key,$amount,$reason,$adminId,$now]);
            $db->prepare('UPDATE portal_wallets SET balance=?,updated_at=? WHERE user_id=? AND currency=?')
                ->execute([$balance+$amount,$now,$order['user_id'],$order['currency']]);
            $db->prepare("INSERT INTO portal_wallet_entries (user_id,currency,amount,balance_after,reference,order_id,event_type,created_at)
                VALUES (?,?,?,?,?,?,'REFUND',?)")->execute([$order['user_id'],$order['currency'],$amount,$balance+$amount,'refund:'.$id,$orderId,$now]);
            $total = $refunded+$amount;
            $full = $total === (int)$order['amount'];
            $db->prepare('UPDATE portal_orders SET refunded_amount=?,status=?,updated_at=? WHERE id=?')
                ->execute([$total,$full ? 'refunded':'partially_refunded',$now,$orderId]);
            if ($full) {
                $db->prepare("UPDATE portal_licenses SET status='revoked',session_version=session_version+1,updated_at=? WHERE id=?")
                    ->execute([$now,$licenseId]);
                $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=(SELECT product FROM portal_licenses WHERE id=?)')->execute([$order['user_id'],$licenseId]);
            }
            TransactionNotifications::record($db,$order,'REFUND_CONFIRMED','wallet_refund:'.$id,$amount,$full?'revoked':'unchanged',$now);
            Audit::admin($db,$adminId,$email,'REFUND_WALLET',$id,$context,'SUCCESS',
                ['order_id'=>$orderId,'amount'=>$amount,'currency'=>$order['currency'],'balance_after'=>$balance+$amount,'full'=>$full],$now);
            return ['id'=>$id,'order_id'=>$orderId,'amount'=>$amount,'currency'=>$order['currency'],'reason'=>$reason,
                'status'=>'succeeded','created_at'=>$now];
        });
    }

    public function records(int $adminId, string $orderId, array $context, int $now, int $page = 1): array
    {
        $email = $this->admin($adminId);
        if ($page<1 || $page>100000) { throw new Problem('page_invalid'); }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$email,$orderId,$context,$now,$page): array {
            $order = $this->order($orderId);
            $payments = [];
            if (in_array($order['status'],['paid','partially_refunded','refunded'],true)) {
                $this->purchase($order);
                $payments[] = ['id'=>$orderId,'amount'=>(int)$order['amount'],'currency'=>$order['currency'],
                    'reserved_amount'=>(int)$order['refunded_amount'],'confirmed_refunded'=>(int)$order['refunded_amount'],
                    'fulfills_license'=>1,'has_pending'=>0];
            }
            $select = $db->prepare('SELECT id,order_id,amount,currency,reason,operator_id,created_at FROM portal_wallet_refunds WHERE order_id=?
                ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET '.(($page-1)*50));
            $select->execute([$orderId]);
            $refunds = array_map($this->view(...),$select->fetchAll());
            $select = $db->prepare('SELECT COUNT(*) FROM portal_wallet_refunds WHERE order_id=?');
            $select->execute([$orderId]);
            $total = (int)$select->fetchColumn();
            Audit::admin($db,$adminId,$email,'VIEW_WALLET_REFUNDS',$orderId,$context,'SUCCESS',['page'=>$page,'count'=>count($refunds)],$now);
            return ['payments'=>$payments,'refunds'=>$refunds,'total'=>$total,'page'=>$page,'per_page'=>50];
        });
    }

    private function purchase(array $order): void
    {
        $select = $this->db->prepare('SELECT user_id,currency,amount,order_id,event_type FROM portal_wallet_entries WHERE reference=?');
        $select->execute(['purchase:'.$order['id']]);
        $entry = $select->fetch();
        if (!$entry || (int)$entry['user_id'] !== (int)$order['user_id'] || $entry['currency'] !== $order['currency'] ||
            (int)$entry['amount'] !== -(int)$order['amount'] || $entry['order_id'] !== $order['id'] || $entry['event_type'] !== 'PURCHASE') {
            throw new Problem('wallet_purchase_not_found',409);
        }
    }

    private function order(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Problem('order_not_found',404); }
        $select = $this->db->prepare("SELECT * FROM portal_orders WHERE id=? AND payment_method='wallet'");
        $select->execute([$id]); $row = $select->fetch();
        if (!$row) { throw new Problem('order_not_found',404); }
        return $row;
    }

    private function admin(int $id): string
    {
        Permissions::require($this->db,$id,'refunds.manage');
        $select = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $select->execute([$id]); $email = $select->fetchColumn();
        if (!is_string($email)) { throw new Problem('admin_auth_invalid',401); }
        return $email;
    }

    private function view(array $row): array
    {
        return array_intersect_key($row,array_flip(['id','order_id','amount','currency','reason','operator_id','created_at']))+['status'=>'succeeded'];
    }
}
