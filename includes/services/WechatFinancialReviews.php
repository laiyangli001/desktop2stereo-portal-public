<?php
declare(strict_types=1);
namespace D2S\services;

use Closure;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Recover reviewed payments only from signed platform and immutable local financial evidence. */
final readonly class WechatFinancialReviews
{
    public function __construct(private PDO $db, private WechatProfiles $profiles, private ?Closure $transport = null) {}

    public function records(int $adminId, string $orderId, array $context, int $now, int $page = 1): array
    {
        $email = $this->admin($adminId);
        if ($page<1 || $page>100000) { throw new Problem('page_invalid'); }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$email,$orderId,$context,$now,$page): array {
            $order = $this->order($orderId);
            $select = $db->prepare('SELECT id,action,payment_id,reason,result_status,evidence_hash,operator_id,created_at
                FROM portal_wechat_payment_resolutions WHERE order_id=? ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET '.(($page-1)*50));
            $select->execute([$orderId]); $history = $select->fetchAll();
            $select = $db->prepare('SELECT COUNT(*) FROM portal_wechat_payment_resolutions WHERE order_id=?');
            $select->execute([$orderId]); $total = (int)$select->fetchColumn();
            Audit::admin($db,$adminId,$email,'VIEW_WECHAT_PAYMENT_REVIEW',$orderId,$context,'SUCCESS',['page'=>$page,'count'=>count($history)],$now);
            return ['order'=>array_intersect_key($order,array_flip(['id','order_no','product','status','currency','amount','refunded_amount'])),
                'receipts'=>array_values($this->receipts($orderId)),'fulfillment'=>$this->fulfillment($orderId),
                'wallet_recharge'=>$this->walletRecharge($orderId),
                'history'=>$history,'total'=>$total,'page'=>$page,'per_page'=>50];
        });
    }

    public function resolve(int $adminId, string $orderId, string $action, ?string $paymentId, string $reason, string $key, array $context, int $now): array
    {
        $email = $this->admin($adminId);
        $reason = trim($reason);
        if (!in_array($action,['retry_fulfillment','close_case'],true) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key) ||
            !mb_check_encoding($reason,'UTF-8') || mb_strlen($reason,'UTF-8')<1 || mb_strlen($reason,'UTF-8')>256 ||
            preg_match('/[\x00-\x1f\x7f]/',$reason) ||
            ($action === 'retry_fulfillment' && (!is_string($paymentId) || !preg_match('/^[a-f0-9]{64}$/D',$paymentId))) ||
            ($action === 'close_case' && $paymentId !== null)) { throw new Problem('payment_review_input_invalid'); }
        $order = $this->order($orderId);
        $existing = $this->existing($orderId,$key,$action,$paymentId,$reason);
        if ($existing !== null) { return $existing; }
        if ($order['status'] !== 'payment_review') { throw new Problem('order_not_in_review',409); }
        $id = bin2hex(random_bytes(16));
        Audit::admin($this->db,$adminId,$email,'RESOLVE_WECHAT_PAYMENT',$orderId,$context,'PENDING',
            ['resolution_id'=>$id,'phase'=>'intent','action'=>$action,'payment_id'=>$paymentId],$now);
        try {
            $facts = $this->receipts($orderId);
            $binding = $this->fulfillment($orderId);
            $walletBinding=$this->walletRecharge($orderId);
            $verifiedId = $action === 'retry_fulfillment' ? $paymentId : ($walletBinding['wechat_payment_id'] ?? ($binding['payment_id'] ?? null));
            $proof = null;
            if ($verifiedId !== null) {
                if (!isset($facts[$verifiedId])) { throw new Problem('payment_review_evidence_invalid',409); }
                if ($facts[$verifiedId]['refunded'] < $facts[$verifiedId]['amount']) {
                    $proof = $this->verifyTrade($orderId,$verifiedId,$now);
                }
            }
            return Database::transaction($this->db,function (PDO $db) use ($adminId,$email,$order,$orderId,$action,$paymentId,$reason,$key,$context,$now,$id,$verifiedId,$proof): array {
                $select = $db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE'); $select->execute([$order['user_id']]);
                $userStatus = $select->fetchColumn();
                $select = $db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE'); $select->execute([$orderId]);
                $current = $select->fetch();
                $existing = $this->existing($orderId,$key,$action,$paymentId,$reason);
                if ($existing !== null) { return $existing; }
                if ($current['status'] !== 'payment_review') { throw new Problem('order_not_in_review',409); }
                $facts = $this->receipts($orderId);
                if ($facts === []) { throw new Problem('payment_review_evidence_invalid',409); }
                foreach ($facts as $receipt) {
                    if ($receipt['currency'] !== 'CNY' || $receipt['amount'] !== (int)$current['amount'] ||
                        $receipt['refunded']>$receipt['amount']) { throw new Problem('payment_review_evidence_invalid',409); }
                    if ($receipt['unresolved']>0) { throw new Problem('payment_review_refunds_pending',409); }
                }
                $binding = $this->fulfillment($orderId);
                $walletBinding = $this->walletRecharge($orderId);
                $status = 'refunded';
                $refunded = (int)$current['amount'];
                if ($action === 'retry_fulfillment') {
                    if ($binding !== null || $walletBinding !== null || !isset($facts[$paymentId]) || $facts[$paymentId]['refunded'] !== 0 ||
                        $verifiedId !== $paymentId || $proof === null || $proof['state'] !== 'SUCCESS') { throw new Problem('payment_review_cannot_fulfill',409); }
                    $this->othersRefunded($facts,$paymentId);
                    $select = $db->prepare('SELECT login_blocked,purchase_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');
                    $select->execute([$current['user_id']]); $controls = $select->fetch();
                    $select = $db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL'); $select->execute([$current['user_id']]);
                    if ($userStatus !== 'active' || (int)$select->fetchColumn() === 0 ||
                        ($controls && ((int)$controls['login_blocked'] || (int)$controls['purchase_blocked']))) {
                        throw new Problem('account_restricted_or_unverified',403);
                    }
                    if ($current['product']==='wallet_recharge') {
                        if (!WalletRecharges::creditExternal($db,$current,'wechat_native',$paymentId,$now)) {
                            throw new Problem('payment_review_cannot_fulfill',409);
                        }
                    } else {
                        if (!OrderFulfillment::grant($db,$current,$now)) { throw new Problem('payment_review_cannot_fulfill',409); }
                        $db->prepare('INSERT INTO portal_wechat_fulfillments (payment_id,order_id) VALUES (?,?)')->execute([$paymentId,$orderId]);
                    }
                    $status = 'paid'; $refunded = 0;
                    Audit::user($db,(int)$current['user_id'],$current['email'],'PURCHASE','SUCCESS',$context,$now);
                } elseif ($current['product']==='wallet_recharge') {
                    if ($walletBinding === null) {
                        $this->othersRefunded($facts,null);
                    } else {
                        $mainId=$walletBinding['wechat_payment_id'];
                        if (!is_string($mainId)||!isset($facts[$mainId]))throw new Problem('payment_fulfillment_review_required',409);
                        $this->othersRefunded($facts,$mainId);
                        $refunded=$facts[$mainId]['refunded'];
                        if ($refunded<$facts[$mainId]['amount']){
                            if($verifiedId!==$mainId||$proof===null)throw new Problem('payment_review_evidence_invalid',409);
                            $status=$refunded===0?'paid':'partially_refunded';
                        }
                    }
                } elseif ($binding === null) {
                    $this->othersRefunded($facts,null);
                    // No fulfillment belongs to this order; never revoke a different order's entitlement.
                } else {
                    $mainId = $binding['payment_id'];
                    if (!is_string($mainId) || !isset($facts[$mainId])) { throw new Problem('payment_fulfillment_review_required',409); }
                    $this->othersRefunded($facts,$mainId);
                    $refunded = $facts[$mainId]['refunded'];
                    if ($refunded < $facts[$mainId]['amount']) {
                        if ($verifiedId !== $mainId || $proof === null) { throw new Problem('payment_review_evidence_invalid',409); }
                        $status = $refunded === 0 ? 'paid':'partially_refunded';
                    } else {
                        $db->prepare("UPDATE portal_licenses SET status='revoked',session_version=session_version+1,updated_at=? WHERE id=? AND status<>'revoked'")
                            ->execute([$now,$binding['license_id']]);
                        $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=(SELECT product FROM portal_licenses WHERE id=?)')->execute([$current['user_id'],$binding['license_id']]);
                    }
                }
                $db->prepare('UPDATE portal_orders SET status=?,refunded_amount=?,updated_at=? WHERE id=?')->execute([$status,$refunded,$now,$orderId]);
                $evidence = ['receipts'=>array_values($facts),'verified_payment_id'=>$proof === null ? null:$verifiedId,
                    'provider_status'=>$proof['state'] ?? null,'fulfillment'=>$binding,'wallet_recharge'=>$walletBinding];
                $json = json_encode($evidence,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $db->prepare('INSERT INTO portal_wechat_payment_resolutions
                    (id,order_id,action,payment_id,idempotency_key,reason,result_status,evidence_hash,details_json,operator_id,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$orderId,$action,$paymentId,$key,$reason,$status,hash('sha256',$json),$json,$adminId,$now]);
                Audit::admin($db,$adminId,$email,'RESOLVE_WECHAT_PAYMENT',$orderId,$context,'SUCCESS',
                    ['resolution_id'=>$id,'phase'=>'result','action'=>$action,'status'=>$status],$now);
                return ['id'=>$id,'order_id'=>$orderId,'status'=>$status,'action'=>$action];
            });
        } catch (\Throwable $error) {
            Audit::admin($this->db,$adminId,$email,'RESOLVE_WECHAT_PAYMENT',$orderId,$context,'FAILURE',
                ['resolution_id'=>$id,'phase'=>'result','action'=>$action],$now);
            throw $error;
        }
    }

    private function verifyTrade(string $orderId,string $paymentId,int $now): array
    {
        $q=$this->db->prepare('SELECT * FROM portal_wechat_receipts WHERE id=? AND order_id=?');$q->execute([$paymentId,$orderId]);$payment=$q->fetch();
        if(!$payment)throw new Problem('payment_not_found',404);
        $original=$this->profiles->forOrder($orderId);$archive=$this->profiles->request($orderId);$config=$archive['config'];
        foreach(['app_id','mchid']as$key)if($payment[$key]!==$original['config'][$key]||$payment[$key]!==$config[$key])throw new Problem('wechat_payment_mismatch');
        $order=$this->order($orderId);$order['amount']=(int)$order['amount'];
        $proof=(new WechatClient($config,$now,$this->transport,$archive['verification_keys']))->query($order,$now);
        if(!in_array($proof['state'],['SUCCESS','REFUND'],true)||$proof['transaction_id']!==$payment['transaction_id']||
            ($payment['payer_total']!==null&&$proof['payer_total']!==null&&(int)$payment['payer_total']!==$proof['payer_total'])){
            throw new Problem('payment_review_platform_unconfirmed',409);
        }
        if($proof['state']==='REFUND'){
            $q=$this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM portal_wechat_refund_receipts WHERE payment_id=?');$q->execute([$paymentId]);
            if((int)$q->fetchColumn()===0)throw new Problem('payment_review_platform_unconfirmed',409);
        }
        return $proof;
    }

    private function receipts(string $orderId): array
    {
        $select = $this->db->prepare('SELECT p.id,p.amount,p.currency,
            COALESCE((SELECT SUM(f.amount) FROM portal_wechat_refund_receipts f WHERE f.payment_id=p.id),0) AS refunded,
            (SELECT COUNT(*) FROM portal_wechat_refund_requests r LEFT JOIN portal_wechat_refund_receipts f ON f.id=r.id
                LEFT JOIN portal_wechat_refund_cancellations c ON c.refund_id=r.id WHERE r.payment_id=p.id AND f.id IS NULL AND c.refund_id IS NULL) AS unresolved
            FROM portal_wechat_receipts p WHERE p.order_id=? ORDER BY p.id');
        $select->execute([$orderId]); $result = [];
        foreach ($select->fetchAll() as $row) {
            foreach (['amount','refunded','unresolved'] as $key) { $row[$key] = (int)$row[$key]; }
            $result[$row['id']] = $row;
        }
        return $result;
    }

    private function othersRefunded(array $facts, ?string $except): void
    {
        foreach ($facts as $id=>$receipt) {
            if ($id !== $except && $receipt['refunded'] !== $receipt['amount']) { throw new Problem('payment_review_receipts_unresolved',409); }
        }
    }

    private function fulfillment(string $orderId): ?array
    {
        $select = $this->db->prepare('SELECT f.license_id,p.payment_id FROM portal_order_fulfillments f
            LEFT JOIN portal_wechat_fulfillments p ON p.order_id=f.id WHERE f.id=?'); $select->execute([$orderId]);
        return $select->fetch() ?: null;
    }

    private function walletRecharge(string $orderId): ?array
    {
        $select=$this->db->prepare('SELECT * FROM portal_wallet_recharge_fulfillments WHERE order_id=?');
        $select->execute([$orderId]);return$select->fetch()?:null;
    }

    private function existing(string $orderId, string $key, string $action, ?string $paymentId, string $reason): ?array
    {
        $select = $this->db->prepare('SELECT * FROM portal_wechat_payment_resolutions WHERE order_id=? AND idempotency_key=?'.($this->db->inTransaction()?' FOR SHARE':'')); $select->execute([$orderId,$key]);
        $row = $select->fetch();
        if (!$row) { return null; }
        if ($row['action'] !== $action || $row['payment_id'] !== $paymentId || $row['reason'] !== $reason) { throw new Problem('idempotency_conflict',409); }
        return ['id'=>$row['id'],'order_id'=>$orderId,'status'=>$row['result_status'],'action'=>$action];
    }

    private function order(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Problem('order_not_found',404); }
        $select = $this->db->prepare("SELECT * FROM portal_orders WHERE id=? AND payment_method='wechat_native' AND (product='wallet_recharge' OR EXISTS(SELECT 1 FROM portal_products p WHERE p.code=portal_orders.product))"); $select->execute([$id]);
        $row = $select->fetch();
        if (!$row) { throw new Problem('order_not_found',404); }
        $row['merchant_order_no']=OrderNumbers::merchant($this->db,$id,'wechat_native');
        return $row;
    }

    private function admin(int $id): string
    {
        Permissions::require($this->db,$id,'orders.resolve');
        $select = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'"); $select->execute([$id]);
        $email = $select->fetchColumn();
        if (!is_string($email)) { throw new Problem('admin_auth_invalid',401); }
        return $email;
    }
}
