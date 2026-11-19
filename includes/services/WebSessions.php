<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class WebSessions
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $realm, array $owner, int $now, bool $consentRequired = false): array
    {
        if (!in_array($realm, ['user', 'admin'], true)) {
            throw new Problem('session_realm_invalid');
        }
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $expires = $now + ($realm === 'admin' ? 28800 : 43200);
        $query = $this->db->prepare('INSERT INTO portal_web_sessions
            (token_hash,realm,user_id,admin_id,csrf_hash,session_version,consent_required,created_at,expires_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $query->execute([hash('sha256', $token), $realm,
            $realm === 'user' ? $owner['id'] : null, $realm === 'admin' ? $owner['id'] : null,
            hash('sha256', $csrf), $owner['session_version'], $realm === 'user' && $consentRequired ? 1 : 0,
            $now, $expires]);
        return ['token' => $token, 'csrf_token' => $csrf, 'expires_at' => $expires];
    }

    public function authenticate(string $realm, string $token, ?string $csrf, bool $write, int $now,
        bool $allowPendingConsent = false): array
    {
        if (!in_array($realm, ['user', 'admin'], true) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new Problem('authentication_required', 401);
        }
        $table = $realm === 'user' ? 'portal_users' : 'portal_admins';
        $ownerColumn = $realm === 'user' ? 'user_id' : 'admin_id';
        $query = $this->db->prepare("SELECT s.*, u.username, u.status AS owner_status, u.session_version AS owner_version
            FROM portal_web_sessions s JOIN $table u ON u.id=s.$ownerColumn
            WHERE s.token_hash=? AND s.realm=?");
        $query->execute([hash('sha256', $token), $realm]);
        $row = $query->fetch();
        if ($row === false || (int)$row['expires_at'] <= $now || $row['owner_status'] !== 'active' ||
            (int)$row['session_version'] !== (int)$row['owner_version']) {
            throw new Problem('session_invalid', 401);
        }
        if ($realm === 'user') {
            $blocked = $this->db->prepare('SELECT login_blocked FROM portal_user_controls WHERE user_id=?');
            $blocked->execute([$row['user_id']]);
            if ((int)$blocked->fetchColumn() === 1) {
                throw new Problem('account_restricted', 403);
            }
        }
        if ($write && (!is_string($csrf) || !hash_equals($row['csrf_hash'], hash('sha256', $csrf)))) {
            throw new Problem('csrf_invalid', 403);
        }
        if ($realm === 'user' && (int)$row['consent_required'] === 1 && !$allowPendingConsent) {
            throw new Problem('legal_reconsent_required',409);
        }
        $row['id'] = (int)$row[$ownerColumn];
        return $row;
    }

    public function revoke(string $token): void
    {
        $query = $this->db->prepare('DELETE FROM portal_web_sessions WHERE token_hash=?');
        $query->execute([hash('sha256', $token)]);
    }

    public function clearConsentRequirement(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) {
            return;
        }
        $query = $this->db->prepare("UPDATE portal_web_sessions SET consent_required=0 WHERE token_hash=? AND realm='user'");
        $query->execute([hash('sha256', $token)]);
    }
}
