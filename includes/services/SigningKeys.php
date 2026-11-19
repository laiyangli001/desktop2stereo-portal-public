<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use RuntimeException;

final readonly class SigningKeys
{
    public function __construct(private PDO $db, private LicenseSigner $configured)
    {
    }

    /** Caller holds a transaction. This lock remains held through credential insertion. */
    public function assertSigning(int $now): void
    {
        $active = $this->lockActive();
        if ($active === null) {
            $count = (int)$this->db->query('SELECT COUNT(*) FROM portal_signing_keys')->fetchColumn();
            if ($count !== 0) {
                throw new RuntimeException('signing_registry_incomplete');
            }
            $this->register($now);
            $this->db->prepare("UPDATE portal_signing_keys SET status='active' WHERE key_id=?")
                ->execute([$this->configured->keyId]);
            $this->setActive($this->configured->keyId,$now);
            Audit::admin($this->db,null,'system','INITIALIZE_SIGNING_KEY',$this->configured->keyId,
                [],'SUCCESS',['fingerprint'=>$this->fingerprint()],$now);
        } elseif ($active !== $this->configured->keyId) {
            // A stale deployment must not silently rotate the globally selected signing key.
            throw new RuntimeException('configured_signing_key_not_active');
        }
        $this->assertStored();
    }

    public function publicKeys(): array
    {
        $rows = $this->db->query("SELECT key_id,public_jwk FROM portal_signing_keys WHERE status<>'retired' ORDER BY key_id")->fetchAll();
        if ($rows === []) {
            if ((int)$this->db->query('SELECT COUNT(*) FROM portal_signing_keys')->fetchColumn() !== 0) {
                throw new RuntimeException('signing_registry_incomplete');
            }
            return [$this->configured->jwk()];
        }
        return array_map(static fn (array $row): array => self::decode($row['public_jwk'],$row['key_id']),$rows);
    }

    public function view(int $adminId, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'keys.manage');
        $rows = $this->db->query('SELECT key_id,status,created_at,retired_at FROM portal_signing_keys ORDER BY created_at,key_id')->fetchAll();
        Audit::admin($this->db,$adminId,$this->adminEmail($adminId),'VIEW_SIGNING_KEYS','signing-keys',$context,'SUCCESS',[],$now);
        return ['configured_key_id'=>$this->configured->keyId,'configured_fingerprint'=>$this->fingerprint(),'keys'=>$rows];
    }

    /** The private key is injected out of band. The API can only activate that configured key. */
    public function activate(int $adminId, string $confirmKeyId, string $confirmFingerprint, array $context, int $now): void
    {
        Permissions::require($this->db,$adminId,'keys.manage');
        if ($confirmKeyId !== $this->configured->keyId || !hash_equals($this->fingerprint(),$confirmFingerprint)) {
            throw new Problem('signing_key_confirmation_invalid');
        }
        Database::transaction($this->db,function () use ($adminId,$context,$now): void {
            $previous = $this->lockActive();
            $this->register($now);
            $this->db->exec("UPDATE portal_signing_keys SET status='verify_only' WHERE status='active'");
            $this->db->prepare("UPDATE portal_signing_keys SET status='active' WHERE key_id=?")->execute([$this->configured->keyId]);
            $this->setActive($this->configured->keyId,$now);
            Audit::admin($this->db,$adminId,$this->adminEmail($adminId),'ACTIVATE_SIGNING_KEY',$this->configured->keyId,
                $context,'SUCCESS',['previous_key_id'=>$previous,'fingerprint'=>$this->fingerprint()],$now);
        });
    }

    public function retire(int $adminId, string $keyId, array $context, int $now): void
    {
        Permissions::require($this->db,$adminId,'keys.manage');
        Database::transaction($this->db,function () use ($adminId,$keyId,$context,$now): void {
            $active = $this->lockActive();
            if ($keyId === $active || $keyId === $this->configured->keyId) {
                throw new Problem('active_signing_key_cannot_retire',409);
            }
            $query = $this->db->prepare('SELECT status FROM portal_signing_keys WHERE key_id=? FOR UPDATE');
            $query->execute([$keyId]);
            $status = $query->fetchColumn();
            if ($status === false) { throw new Problem('signing_key_not_found',404); }
            $query = $this->db->prepare('SELECT MAX(expires_at) FROM portal_license_credentials WHERE key_id=?');
            $query->execute([$keyId]);
            $deadline = $query->fetchColumn();
            if ($deadline !== null && $deadline !== false && (int)$deadline > $now) {
                throw new Problem('signing_key_still_in_use',409);
            }
            $this->db->prepare("UPDATE portal_signing_keys SET status='retired',retired_at=COALESCE(retired_at,?) WHERE key_id=?")
                ->execute([$now,$keyId]);
            Audit::admin($this->db,$adminId,$this->adminEmail($adminId),'RETIRE_SIGNING_KEY',$keyId,$context,'SUCCESS',[],$now);
        });
    }

    private function lockActive(): ?string
    {
        if (!$this->db->inTransaction()) { throw new RuntimeException('signing_registry_transaction_required'); }
        $this->db->exec("INSERT INTO portal_settings (name,value_json,updated_at) VALUES ('licensing.active_key','null',0)
            ON DUPLICATE KEY UPDATE name=name");
        $raw = $this->db->query("SELECT value_json FROM portal_settings WHERE name='licensing.active_key' FOR UPDATE")->fetchColumn();
        $active = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if ($active !== null && (!is_string($active) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$active))) {
            throw new RuntimeException('signing_registry_corrupt');
        }
        return $active;
    }

    private function setActive(string $keyId, int $now): void
    {
        $this->db->prepare("UPDATE portal_settings SET value_json=?,updated_at=? WHERE name='licensing.active_key'")
            ->execute([json_encode($keyId,JSON_THROW_ON_ERROR),$now]);
    }

    private function register(int $now): void
    {
        $jwk = json_encode($this->configured->jwk(),JSON_THROW_ON_ERROR);
        $this->db->prepare("INSERT INTO portal_signing_keys (key_id,public_jwk,status,created_at)
            VALUES (?,?,'verify_only',?) ON DUPLICATE KEY UPDATE key_id=key_id")
            ->execute([$this->configured->keyId,$jwk,$now]);
        $this->assertStored(false);
    }

    private function assertStored(bool $mustBeActive = true): void
    {
        $query = $this->db->prepare('SELECT public_jwk,status FROM portal_signing_keys WHERE key_id=?');
        $query->execute([$this->configured->keyId]);
        $row = $query->fetch();
        if ($row === false || $row['status'] === 'retired' || ($mustBeActive && $row['status'] !== 'active') ||
            self::decode($row['public_jwk'],$this->configured->keyId) !== $this->configured->jwk()) {
            throw new RuntimeException('signing_key_identity_conflict');
        }
    }

    private function fingerprint(): string
    {
        return hash('sha256',json_encode($this->configured->jwk(),JSON_THROW_ON_ERROR));
    }

    private static function decode(string $raw, string $keyId): array
    {
        $jwk = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($jwk) || array_keys($jwk) !== ['kty','crv','x','y','use','alg','kid'] ||
            $jwk['kty'] !== 'EC' || $jwk['crv'] !== 'P-256' || $jwk['use'] !== 'sig' ||
            $jwk['alg'] !== 'ES256' || $jwk['kid'] !== $keyId ||
            !is_string($jwk['x']) || !is_string($jwk['y']) ||
            !preg_match('/^[A-Za-z0-9_-]{43}$/D',$jwk['x']) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$jwk['y'])) {
            throw new RuntimeException('signing_registry_corrupt');
        }
        return $jwk;
    }

    private function adminEmail(int $adminId): string
    {
        $query = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$adminId]);
        $email = $query->fetchColumn();
        if (!is_string($email)) { throw new Problem('administrator_unavailable',403); }
        return $email;
    }
}
