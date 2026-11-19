<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class AlipayRefundReconciler
{
    public function __construct(private PDO $db, private AlipayRefunds $refunds) {}

    public function run(int $now, int $limit = 10): array
    {
        if ($limit<1 || $limit>50 || $this->db->inTransaction()) { throw new Problem('reconciliation_arguments_invalid'); }
        $name = 'd2s:refunds:'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$name]);
        $result = ['busy'=>false,'processed'=>0,'confirmed'=>0,'pending'=>0,'cancelled'=>0,'failed'=>0];
        if ((int)$lock->fetchColumn() !== 1) { return array_replace($result,['busy'=>true]); }
        try {
            $select = $this->db->prepare("SELECT s.id FROM portal_refund_states s WHERE s.status<>'succeeded' AND s.next_attempt_at<=? AND s.lease_until<=?
                AND NOT EXISTS (SELECT 1 FROM portal_refund_cancellations c WHERE c.refund_id=s.id)
                ORDER BY next_attempt_at,id LIMIT ".$limit);
            $select->execute([$now,$now]);
            foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    $state = $this->refunds->process($id,['request_path'=>'/cli/alipay-refunds'],$now);
                    $result['processed']++;
                    $result[$state['status'] === 'succeeded' ? 'confirmed':($state['status'] === 'cancelled' ? 'cancelled':'pending')]++;
                } catch (\Throwable) { $result['failed']++; }
            }
            return $result;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }
}
