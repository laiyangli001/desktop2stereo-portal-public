<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\repositories\Database;
use PDO;
use Throwable;

final readonly class DeliveryWorker
{
    public function __construct(private PDO $db, private SecretBox $secrets, private MessageSender $sender)
    {
    }

    public function once(int $now): bool
    {
        $job = Database::transaction($this->db, static function (PDO $db) use ($now): ?array {
            $expire = $db->prepare("UPDATE portal_delivery_outbox
                SET status='expired',payload_cipher=NULL,lease_id=NULL,lease_until=NULL WHERE expires_at<=? AND payload_cipher IS NOT NULL");
            $expire->execute([$now]);
            $select = $db->prepare("SELECT q.* FROM portal_delivery_outbox q
                JOIN portal_verifications v ON v.id=q.verification_id
                WHERE q.available_at<=? AND q.expires_at>? AND q.attempts<5
                AND v.consumed_at IS NULL
                AND (q.status IN ('pending','retry') OR (q.status='processing' AND q.lease_until<=?))
                ORDER BY q.available_at,q.id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $select->execute([$now, $now, $now]);
            $job = $select->fetch();
            if ($job === false) {
                return null;
            }
            $job['lease_id'] = bin2hex(random_bytes(16));
            $job['attempts'] = (int)$job['attempts'] + 1;
            $claim = $db->prepare("UPDATE portal_delivery_outbox SET status='processing',attempts=?,lease_id=?,lease_until=? WHERE id=?");
            $claim->execute([$job['attempts'], $job['lease_id'], $now + 60, $job['id']]);
            return $job;
        });
        if ($job === null) {
            return false;
        }
        try {
            $message = json_decode($this->secrets->decrypt($job['payload_cipher'], 'delivery:' . $job['id']), true, 32, JSON_THROW_ON_ERROR);
            $this->sender->send($message);
            $done = $this->db->prepare("UPDATE portal_delivery_outbox SET status='sent',payload_cipher=NULL,
                last_error=NULL,lease_id=NULL,lease_until=NULL WHERE id=? AND lease_id=?");
            $done->execute([$job['id'], $job['lease_id']]);
        } catch (Throwable) {
            // Provider exceptions may contain recipient or request content; never persist them.
            $terminal = $job['attempts'] >= 5;
            $failed = $this->db->prepare("UPDATE portal_delivery_outbox SET status=?,available_at=?,last_error='delivery_failed',
                payload_cipher=IF(?,NULL,payload_cipher),lease_id=NULL,lease_until=NULL WHERE id=? AND lease_id=?");
            $failed->execute([$terminal ? 'failed' : 'retry', $now + min(300, 30 * (2 ** $job['attempts'])),
                $terminal ? 1 : 0, $job['id'], $job['lease_id']]);
        }
        return true;
    }
}
