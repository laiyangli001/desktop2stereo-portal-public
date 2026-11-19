<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Cancel only unsent intentions; sent/ambiguous requests keep their financial reservation. */
final readonly class RefundReviews
{
    public function __construct(private PDO $db) {}

    public function records(int $adminId, string $refundId, array $context, int $now, int $page = 1): array
    {
        $email = $this->admin($adminId);
        if ($page<1 || $page>100000) { throw new Problem('page_invalid'); }
        return Database::transaction($this->db,function(PDO $db) use($adminId,$email,$refundId,$context,$now,$page): array {
            $refund = $this->load($refundId);
            $q = $db->prepare('SELECT id,action,reason,result_mode,operator_id,created_at,evidence_hash
                FROM portal_refund_review_actions WHERE refund_id=? ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET '.(($page-1)*50));
            $q->execute([$refundId]); $rows = $q->fetchAll();
            $q = $db->prepare('SELECT COUNT(*) FROM portal_refund_review_actions WHERE refund_id=?');$q->execute([$refundId]);
            $total = (int)$q->fetchColumn();
            Audit::admin($db,$adminId,$email,'VIEW_REFUND_REVIEW',$refundId,$context,'SUCCESS',['page'=>$page,'count'=>count($rows)],$now);
            return ['refund'=>$refund,'actions'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>50];
        });
    }

    public function act(int $adminId, string $refundId, string $action, string $reason, string $key, array $context, int $now): array
    {
        $email = $this->admin($adminId); $reason = trim($reason);
        if (!in_array($action,['cancel_unsent','pause_retry','resume_retry'],true) ||
            !preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key) || !mb_check_encoding($reason,'UTF-8') ||
            mb_strlen($reason,'UTF-8')<1 || mb_strlen($reason,'UTF-8')>256 || preg_match('/[\x00-\x1f\x7f]/',$reason)) {
            throw new Problem('refund_review_input_invalid');
        }
        $refund = $this->load($refundId);
        try {
            return Database::transaction($this->db,function(PDO $db) use($adminId,$email,$refund,$refundId,$action,$reason,$key,$context,$now): array {
                $q = $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$refund['user_id']]);
                $q = $db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$refund['order_id']]);$order=$q->fetch();
                $q = $db->prepare('SELECT * FROM portal_refund_states WHERE id=? FOR UPDATE');$q->execute([$refundId]);$state=$q->fetch();
                $q = $db->prepare('SELECT * FROM portal_refund_review_actions WHERE refund_id=? AND idempotency_key=?');$q->execute([$refundId,$key]);
                $old = $q->fetch();
                if ($old) {
                    if ($old['action']!==$action || $old['reason']!==$reason) { throw new Problem('idempotency_conflict',409); }
                    return ['id'=>$old['id'],'refund_id'=>$refundId,'result_mode'=>$old['result_mode']];
                }
                $current = $this->load($refundId);
                if ($current['cancelled'] || $state['status']==='succeeded' || $current['confirmed']) { throw new Problem('refund_review_terminal',409); }
                if ((int)$state['lease_until']>$now) { throw new Problem('refund_review_busy',409); }
                $id = bin2hex(random_bytes(16));
                $mode = match($action) {'cancel_unsent'=>'cancelled','pause_retry'=>'query_only',default=>'automatic'};
                if ($action==='cancel_unsent' && (!in_array($state['status'],['pending','unknown'],true) || $state['last_sent_at']!==null)) {
                    throw new Problem('refund_already_attempted',409);
                }
                if ($action!=='cancel_unsent' && $mode===$current['retry_mode']) { throw new Problem('refund_review_mode_unchanged',409); }
                $evidence = json_encode(['status'=>$state['status'],'attempts'=>(int)$state['attempts'],'last_sent_at'=>$state['last_sent_at'],
                    'lease_until'=>(int)$state['lease_until'],'retry_mode'=>$current['retry_mode'],
                    'payment_id'=>$current['payment_id'],'amount'=>(int)$current['amount'],'confirmed'=>false],JSON_THROW_ON_ERROR);
                $db->prepare('INSERT INTO portal_refund_review_actions
                    (id,refund_id,action,reason,idempotency_key,result_mode,operator_id,evidence_hash,details_json,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$refundId,$action,$reason,$key,$mode,$adminId,hash('sha256',$evidence),$evidence,$now]);
                if ($action==='cancel_unsent') {
                    $db->prepare('INSERT INTO portal_refund_cancellations (refund_id,action_id,created_at) VALUES (?,?,?)')->execute([$refundId,$id,$now]);
                    WalletRecharges::releaseRefund($db,$refundId,$now);
                    if ($order['status']==='refund_pending') {
                        $q=$db->prepare('SELECT payment_id FROM portal_payment_fulfillments WHERE order_id=?');$q->execute([$order['id']]);
                        $main=$q->fetchColumn();
                        if ($main===$current['payment_id']) {
                            $q=$db->prepare('SELECT COUNT(*) FROM portal_refund_requests r
                                LEFT JOIN portal_refund_cancellations c ON c.refund_id=r.id
                                LEFT JOIN portal_refund_receipts f ON f.id=r.id
                                WHERE r.payment_id=? AND c.refund_id IS NULL AND f.id IS NULL');$q->execute([$main]);
                            if ((int)$q->fetchColumn()===0) {
                                $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM portal_refund_receipts WHERE payment_id=?');$q->execute([$main]);
                                $amount=(int)$q->fetchColumn();
                                if ($amount!==(int)$order['refunded_amount'] || $amount>(int)$order['amount']) { throw new Problem('refund_review_evidence_invalid',409); }
                                $status=$amount===0?'paid':($amount===(int)$order['amount']?'refunded':'partially_refunded');
                                $db->prepare('UPDATE portal_orders SET status=?,updated_at=? WHERE id=?')->execute([$status,$now,$order['id']]);
                            }
                        }
                    }
                } else {
                    $db->prepare('INSERT INTO portal_refund_controls (refund_id,retry_mode,updated_at) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE retry_mode=?,updated_at=?')->execute([$refundId,$mode,$now,$mode,$now]);
                }
                // An expired worker cannot send or complete using a stale claim after manual review.
                $db->prepare('UPDATE portal_refund_states SET lease_token=NULL,lease_until=0,updated_at=? WHERE id=?')->execute([$now,$refundId]);
                Audit::admin($db,$adminId,$email,'REVIEW_REFUND',$refundId,$context,'SUCCESS',
                    ['action_id'=>$id,'action'=>$action,'mode'=>$mode,'order_id'=>$current['order_id'],'amount'=>(int)$current['amount']],$now);
                return ['id'=>$id,'refund_id'=>$refundId,'result_mode'=>$mode];
            });
        } catch (\Throwable $error) {
            Audit::admin($this->db,$adminId,$email,'REVIEW_REFUND',$refundId,$context,'FAILURE',['action'=>$action],$now);
            throw $error;
        }
    }

    private function load(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Problem('refund_not_found',404); }
        $q = $this->db->prepare("SELECT r.id,r.order_id,r.payment_id,r.amount,o.user_id,s.status,s.attempts,s.last_sent_at,s.lease_until,
            COALESCE(t.retry_mode,'automatic') AS retry_mode,
            (c.refund_id IS NOT NULL) AS cancelled,(f.id IS NOT NULL) AS confirmed
            FROM portal_refund_requests r JOIN portal_refund_states s ON s.id=r.id JOIN portal_orders o ON o.id=r.order_id
            LEFT JOIN portal_refund_controls t ON t.refund_id=r.id
            LEFT JOIN portal_refund_cancellations c ON c.refund_id=r.id LEFT JOIN portal_refund_receipts f ON f.id=r.id WHERE r.id=?");
        $q->execute([$id]);$row=$q->fetch();
        if (!$row) { throw new Problem('refund_not_found',404); }
        foreach(['amount','user_id','attempts','lease_until'] as $key) $row[$key]=(int)$row[$key];
        $row['cancelled']=(bool)$row['cancelled'];$row['confirmed']=(bool)$row['confirmed'];
        return $row;
    }

    private function admin(int $id): string
    {
        Permissions::require($this->db,$id,'refunds.resolve');
        $q=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$id]);$email=$q->fetchColumn();
        if (!is_string($email)) { throw new Problem('admin_auth_invalid',401); }
        return $email;
    }
}
