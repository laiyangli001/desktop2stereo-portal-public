<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use RuntimeException;

/** Caller owns the transaction and the account/order locks; no external effects. */
final class OrderFulfillment
{
    public static function grant(PDO $db, array $order, int $now): bool
    {
        if (!$db->inTransaction()) { throw new RuntimeException('settlement_transaction_required'); }
        $product=$order['product'];
        if($product==='wallet_recharge'||!(new ProductCatalog($db))->exists($product))return false;
        $select = $db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');
        $select->execute([$order['user_id'],$product]);
        $license = $select->fetch();
        if ($license !== false && ($license['kind'] === 'formal' || $license['status'] !== 'active')) { return false; }
        if ($license === false) {
            $id = bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO portal_licenses
                (id,user_id,product,kind,status,trial_started_at,expires_at,created_at,updated_at)
                VALUES (?,?,?,'formal','active',NULL,NULL,?,?)")->execute([$id,$order['user_id'],$product,$now,$now]);
        } else {
            $id = $license['id'];
            $db->prepare("UPDATE portal_licenses SET kind='formal',expires_at=NULL,updated_at=? WHERE id=?")
                ->execute([$now,$id]);
        }
        $db->prepare('INSERT INTO portal_order_fulfillments (id,license_id,fulfilled_at) VALUES (?,?,?)')->execute([$order['id'],$id,$now]);
        ReferralProgram::accrue($db,$order,$now);
        TransactionNotifications::record($db,$order,'ORDER_PAID','order_paid:'.$order['id'],(int)$order['amount'],'activated',$now);
        return true;
    }
}
