<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

/** One database-scoped master at a time; network requests never run inside a transaction. */
final readonly class AlipayReconciler
{
    public function __construct(private PDO $db, private AlipayPayments $payments) {}

    public function run(int $now, int $limit = 10): array
    {
        if ($limit<1 || $limit>50 || $this->db->inTransaction()) { throw new Problem('reconciliation_arguments_invalid'); }
        $name = 'd2s:alipay:'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$name]);
        if ((int)$lock->fetchColumn() !== 1) { return ['busy'=>true,'processed'=>0,'failed'=>0]; }
        try {
            // A failed/unresolved attempt cools down for one minute. Oldest attempts run first.
            $select = $this->db->prepare("SELECT o.id,COALESCE(MAX(e.received_at),o.created_at) AS checked_at
                FROM portal_orders o LEFT JOIN portal_payment_events e ON e.order_id=o.id AND e.event_type LIKE 'QUERY:%'
                WHERE o.status='pending' AND o.payment_method='alipay_pc'
                GROUP BY o.id,o.created_at HAVING checked_at<=?
                ORDER BY checked_at,o.id LIMIT ".$limit);
            $select->execute([$now-60]);
            $ids = $select->fetchAll(PDO::FETCH_COLUMN);
            $processed = 0;
            $failed = 0;
            foreach ($ids as $id) {
                try {
                    $this->payments->closeExpired($id,['request_path'=>'/cli/alipay-reconcile'],$now);
                    $processed++;
                } catch (\Throwable) {
                    // The next run retries. Never translate transport/signature/audit failure to cancellation.
                    $failed++;
                }
            }
            return ['busy'=>false,'processed'=>$processed,'failed'=>$failed];
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }
}
