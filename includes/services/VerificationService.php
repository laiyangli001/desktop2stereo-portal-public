<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use LogicException;
use PDO;

final readonly class VerificationService
{
    public function __construct(private PDO $db, private SecretBox $secrets, private ?array $deliveryConfiguration = null)
    {
    }

    public function issue(string $kind, string $target, string $purpose, ?int $userId, array $context, int $now): array
    {
        $target = AccountInput::contact($kind, $target);
        if (!in_array($purpose, ['register', 'password_reset', 'bind_contact'], true) ||
            ($purpose === 'bind_contact') !== ($userId !== null)) {
            throw new Problem('verification_purpose_invalid');
        }
        if ($kind === 'phone' && !DeliveryGateway::available('phone',$this->deliveryConfiguration)) {
            throw new Problem('phone_registration_unavailable', 503);
        }
        if ($kind === 'email' && !DeliveryGateway::available('email',$this->deliveryConfiguration)) {
            throw new Problem('email_delivery_unavailable', 503);
        }
        $result = Database::transaction($this->db, function (PDO $db) use ($kind, $target, $purpose, $userId, $context, $now): array {

            // Always lock quotas in this order to serialize concurrent sends.
            $global = RateLimiter::take($db, 'verification:global', 1000, 86400, $now);
            $ip = RateLimiter::take($db, 'verification:ip:' . ($context['source_ip'] ?? 'unknown'), 20, 3600, $now);
            $destination = RateLimiter::take($db, "verification:target:$kind:$target", 5, 3600, $now, 60);
            if (!$global || !$ip || !$destination) {
                return ['error' => 'verification_rate_limited'];
            }
            $id = bin2hex(random_bytes(16));
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expire = $now + 600;
            $cancel = $db->prepare('UPDATE portal_verifications SET consumed_at=? WHERE kind=? AND target=? AND purpose=? AND consumed_at IS NULL');
            $cancel->execute([$now, $kind, $target, $purpose]);
            $insert = $db->prepare('INSERT INTO portal_verifications
                (id,user_id,kind,target,purpose,code_hash,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?)');
            $insert->execute([$id, $userId, $kind, $target, $purpose,
                $this->secrets->digest("$id|$kind|$target|$purpose|$code"), $now, $expire]);
            $queueId = bin2hex(random_bytes(16));
            $payload = $this->secrets->encrypt(json_encode([
                'kind' => $kind, 'target' => $target, 'purpose' => $purpose, 'code' => $code,
            ], JSON_THROW_ON_ERROR), 'delivery:' . $queueId);
            $outbox = $db->prepare("INSERT INTO portal_delivery_outbox
                (id,verification_id,channel,payload_cipher,status,available_at,expires_at) VALUES (?,?,?,?,'pending',?,?)");
            $outbox->execute([$queueId, $id, $kind, $payload, $now, $expire]);
            return ['challenge_id' => $id, 'expires_in' => 600, 'retry_after' => 60];
        });
        if (isset($result['error'])) {
            throw new Problem($result['error'], 429);
        }
        return $result;
    }

    /** Wrong-code attempts must be committed by the caller even when returning an error. */
    public function consume(string $id, string $kind, string $target, string $purpose, string $code, ?int $userId, int $now): bool
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('verification_requires_transaction');
        }
        $select = $this->db->prepare('SELECT * FROM portal_verifications WHERE id=? FOR UPDATE');
        $select->execute([$id]);
        $row = $select->fetch();
        if ($row === false || $row['consumed_at'] !== null || (int)$row['expires_at'] <= $now || (int)$row['attempts'] >= 5) {
            return false;
        }
        $increment = $this->db->prepare('UPDATE portal_verifications SET attempts=attempts+1 WHERE id=?');
        $increment->execute([$id]);
        $matches = $row['kind'] === $kind && $row['target'] === $target && $row['purpose'] === $purpose &&
            ($row['user_id'] === null ? $userId === null : (int)$row['user_id'] === $userId) &&
            preg_match('/^[0-9]{6}$/D', $code) &&
            hash_equals($row['code_hash'], $this->secrets->digest("$id|$kind|$target|$purpose|$code"));
        if (!$matches) {
            return false;
        }
        $consume = $this->db->prepare('UPDATE portal_verifications SET consumed_at=? WHERE id=?');
        $consume->execute([$now, $id]);
        return true;
    }
}
