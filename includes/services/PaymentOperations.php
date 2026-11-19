<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class PaymentOperations
{
    public function __construct(private PDO $db, private AlipayPayments $payments, private ?WechatPayments $wechat = null) {}

    public function orders(int $adminId, int $page, array $context, int $now, ?string $status = null): array
    {
        Permissions::require($this->db,$adminId,'orders.read');
        if ($page<1 || $page>100000) { throw new Problem('page_invalid'); }
        $filter = match ($status) {
            null => '', 'payment_review' => " WHERE o.status='payment_review'", 'refund_pending' => " WHERE o.status='refund_pending'",
            default => throw new Problem('order_status_invalid'),
        };
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$page,$context,$now,$status,$filter): array {
            $rows = $db->query('SELECT o.id,o.order_no,o.user_id,o.email,o.product,o.currency,o.amount,o.payment_method,
                o.status,o.created_at,o.paid_at,o.expires_at,o.refunded_amount,revision.title AS product_title
                FROM portal_orders o
                LEFT JOIN portal_products p ON p.code=o.product
                LEFT JOIN portal_product_translations head ON head.product_code=p.code AND head.locale_code=p.source_locale
                LEFT JOIN portal_product_translation_revisions revision ON revision.id=head.published_revision_id
                '.$filter.' ORDER BY o.created_at DESC,o.id DESC LIMIT 50 OFFSET '.(($page-1)*50))->fetchAll();
            $total = (int)$db->query('SELECT COUNT(*) FROM portal_orders o'.$filter)->fetchColumn();
            Audit::admin($db,$adminId,$this->email($adminId),'VIEW_ORDERS',null,$context,'SUCCESS',['page'=>$page,'count'=>count($rows),'status'=>$status],$now);
            return ['orders'=>$rows,'page'=>$page,'total'=>$total,'per_page'=>50];
        });
    }

    public function operate(int $adminId, string $id, string $operation, array $context, int $now): array
    {
        if (!in_array($operation,['query','close'],true)) { throw new Problem('payment_operation_invalid'); }
        Permissions::require($this->db,$adminId,$operation === 'query' ? 'orders.reconcile':'orders.close');
        $email = $this->email($adminId);
        $type = $operation === 'query' ? 'QUERY_PAYMENT':'CLOSE_ORDER';
        $reference = bin2hex(random_bytes(16));
        // Persist intent before external I/O. A failed audit cannot initiate a provider operation.
        $provider = Database::transaction($this->db,function (PDO $db) use ($adminId,$id,$operation,$context,$now,$email,$type,$reference): string {
            $query = $db->prepare('SELECT status,payment_method FROM portal_orders WHERE id=?');
            $query->execute([$id]);
            $row = $query->fetch();
            if (!$row) { throw new Problem('order_not_found',404); }
            if (!in_array($row['payment_method'],['alipay_pc','wechat_native'],true)) { throw new Problem('payment_method_unavailable',409); }
            if ($operation === 'close' && $row['status'] !== 'pending') { throw new Problem('order_not_cancellable',409); }
            Audit::admin($db,$adminId,$email,$type,$id,$context,'PENDING',['operation_id'=>$reference,'phase'=>'intent','payment_method'=>$row['payment_method']],$now);
            return $row['payment_method'];
        });
        try {
            $processor = $provider === 'wechat_native'
                ? ($this->wechat ?? new WechatPayments($this->db,new WechatProfiles($this->db,SecretBox::fromEnvironment())))
                : $this->payments;
            $result = $operation === 'query' ? $processor->query($id,$context,$now) : $processor->closePending($id,$context,$now);
        } catch (\Throwable $error) {
            Audit::admin($this->db,$adminId,$email,$type,$id,$context,'FAILURE',['operation_id'=>$reference,'phase'=>'result','payment_method'=>$provider],$now);
            throw $error;
        }
        $unknown = in_array($result['provider_status'] ?? '',['UNKNOWN','NOT_FOUND'],true) ||
            in_array($result['close_status'] ?? '',['unknown','pending'],true);
        Audit::admin($this->db,$adminId,$email,$type,$id,$context,$unknown ? 'PENDING':'SUCCESS',
            ['operation_id'=>$reference,'phase'=>'result','payment_method'=>$provider,'status'=>$result['status'] ?? null,
                'provider_status'=>$result['provider_status'] ?? null,'close_status'=>$result['close_status'] ?? null],$now);
        return $result;
    }

    private function email(int $id): string
    {
        $select = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $select->execute([$id]);
        $email = $select->fetchColumn();
        if (!is_string($email)) { throw new Problem('admin_auth_invalid',401); }
        return $email;
    }
}
