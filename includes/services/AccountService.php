<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use PDOException;

final readonly class AccountService
{
    public function __construct(private PDO $db, private VerificationService $verification, private ?array $deliveryConfiguration = null)
    {
    }

    public function register(array $input, array $context, int $now): array
    {
        try {
            if (($input['privacy_accepted'] ?? null) !== true || ($input['terms_accepted'] ?? null) !== true) {
                throw new Problem('legal_consent_required');
            }
            $username = AccountInput::username($input['username']);
            $kind = $input['kind'];
            $target = AccountInput::contact($kind, $input['contact']);
            $region = $kind === 'phone' ? 'CN' : 'INTL';
            $password = AccountInput::password($input['password']);
            if ($kind === 'phone' && !DeliveryGateway::available('phone',$this->deliveryConfiguration)) {
                throw new Problem('phone_registration_unavailable', 503);
            }
        } catch (Problem $error) {
            Audit::user($this->db, null, null, 'REGISTER', 'FAILURE', $context, $now);
            throw $error;
        }
        try {
            $result = Database::transaction($this->db, function (PDO $db) use ($input, $username, $kind, $target, $region, $password, $context, $now): array {
                $policies = (new SiteContent($db))->registrationPolicies();
                foreach ($policies as $policy) {
                    if (!preg_match('/^[A-Za-z0-9._+-]{1,40}$/D',$policy['version'])) {
                        throw new Problem('legal_policy_unavailable', 503);
                    }
                }
                if (!$this->verification->consume($input['challenge_id'], $kind, $target, 'register', $input['code'], null, $now)) {
                    Audit::user($db, null, $kind === 'email' ? $target : null, 'REGISTER', 'FAILURE', $context, $now);
                    return ['error' => 'verification_invalid'];
                }
                $insert = $db->prepare("INSERT INTO portal_users
                    (username,password_hash,status,region,created_at,updated_at) VALUES (?,?,'active',?,?,?)");
                $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), $region, $now, $now]);
                $id = (int)$db->lastInsertId();
                ReferralProgram::bind($db,$id,(string)($input['invite_code']??''),$now);
                $contact = $db->prepare('INSERT INTO portal_contacts (user_id,kind,value,verified_at,created_at) VALUES (?,?,?,?,?)');
                $contact->execute([$id, $kind, $target, $now, $now]);
                $snapshot = $db->prepare('INSERT INTO portal_registration_records
                    (user_id,email,contact_kind,contact_value,register_ip,register_time,register_user_agent,
                    contact_verified,contact_verified_at,email_verified,email_verified_at)
                    VALUES (?,?,?,?,?,?,?,1,?,?,?)');
                $snapshot->execute([$id, $kind === 'email' ? $target : null, $kind, $target,
                    $context['source_ip'] ?? null, $now, $context['user_agent'] ?? null,
                    $now, $kind === 'email' ? 1 : 0, $kind === 'email' ? $now : null]);
                (new PolicyConsents($db))->record($id,$policies,$context,$now);
                Audit::user($db, $id, $kind === 'email' ? $target : null, 'REGISTER', 'SUCCESS', $context, $now);
                return ['id' => $id, 'username' => $username, 'region' => $region, 'status' => 'active',
                    'privacy_version' => $policies[0]['version'], 'terms_version' => $policies[1]['version']];
            });
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            Audit::user($this->db, null, null, 'REGISTER', 'FAILURE', $context, $now);
            throw new Problem('account_or_contact_exists', 409);
        }
        if (isset($result['error'])) {
            throw new Problem($result['error']);
        }
        return $result;
    }

    public function login(string $realm, string $identifier, string $password, array $context, int $now): array
    {
        if (!in_array($realm, ['user', 'admin'], true)) {
            throw new Problem('session_realm_invalid');
        }
        $result = Database::transaction($this->db, function (PDO $db) use ($realm, $identifier, $password, $context, $now): array {
            $ipAllowed = RateLimiter::take($db, "login:ip:$realm:" . ($context['source_ip'] ?? 'unknown'), 30, 600, $now);
            $owner = $this->findOwner($realm, $identifier, true);
            $identity = $owner === null ? 'unknown:' . strtolower(substr($identifier, 0, 254)) : 'id:' . $owner['id'];
            $accountKey = "login:account:$realm:$identity";
            $accountAllowed = RateLimiter::take($db, $accountKey, 5, 900, $now);
            $email = $owner === null ? null : ($realm === 'admin' ? $owner['email'] : Audit::email($db, (int)$owner['id']));
            $id = $owner === null ? null : (int)$owner['id'];
            if (!$ipAllowed || !$accountAllowed) {
                $this->loginAudit($realm, $id, $email, 'BLOCKED', $context, $now);
                return ['error' => 'login_temporarily_locked', 'status' => 429];
            }
            if ($owner === null || strlen($password) > 64 || !password_verify($password, $owner['password_hash'])) {
                // Run a password hash even for unknown users to reduce obvious timing differences.
                if ($owner === null) {
                    password_verify($password, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT));
                }
                $this->loginAudit($realm, $id, $email, 'FAILURE', $context, $now);
                return ['error' => 'invalid_credentials', 'status' => 401];
            }
            $allowed = $owner['status'] === 'active';
            if ($realm === 'user') {
                $verified = $db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');
                $verified->execute([$id]);
                $restriction = $db->prepare('SELECT login_blocked FROM portal_user_controls WHERE user_id=?');
                $restriction->execute([$id]);
                $allowed = $allowed && (int)$verified->fetchColumn() > 0 && (int)$restriction->fetchColumn() !== 1;
            }
            if (!$allowed) {
                $this->loginAudit($realm, $id, $email, 'BLOCKED', $context, $now);
                return ['error' => 'account_restricted_or_unverified', 'status' => 403];
            }
            RateLimiter::clear($db, $accountKey);
            $missingPolicies = $realm === 'user' ? (new PolicyConsents($db))->missing($id) : [];
            $consentRequired = $missingPolicies !== [];
            $session = (new WebSessions($db))->create($realm, $owner, $now, $consentRequired);
            $this->loginAudit($realm, $id, $email, 'SUCCESS', $context, $now);
            return $session + ['account' => ['id' => $id, 'username' => $owner['username'], 'realm' => $realm],
                'consent_required' => $missingPolicies !== [], 'consent_versions' => array_map(
                    static fn(array $policy): array => ['type'=>$policy['type'],'version'=>$policy['version'],
                        'effective_date'=>$policy['effective_date']], $missingPolicies)];
        });
        if (isset($result['error'])) {
            throw new Problem($result['error'], $result['status']);
        }
        return $result;
    }

    public function resetPassword(string $kind, string $contact, string $challenge, string $code, string $password, array $context, int $now): void
    {
        $target = AccountInput::contact($kind, $contact);
        AccountInput::password($password);
        $result = Database::transaction($this->db, function (PDO $db) use ($kind, $target, $challenge, $code, $password, $context, $now): array {
            if (!$this->verification->consume($challenge, $kind, $target, 'password_reset', $code, null, $now)) {
                Audit::user($db, null, $kind === 'email' ? $target : null, 'PASSWORD_RESET', 'FAILURE', $context, $now);
                return ['error' => 'verification_invalid'];
            }
            $owner = $this->findOwner('user', $target, true);
            if ($owner === null) {
                Audit::user($db, null, null, 'PASSWORD_RESET', 'FAILURE', $context, $now);
                return ['error' => 'account_unavailable'];
            }
            $id = (int)$owner['id'];
            $update = $db->prepare('UPDATE portal_users SET password_hash=?,password_changed_at=?,session_version=session_version+1,updated_at=? WHERE id=?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $now, $now, $id]);
            $db->prepare('DELETE FROM portal_web_sessions WHERE user_id=?')->execute([$id]);
            $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=?')->execute([$id]);
            Audit::user($db, $id, Audit::email($db, $id), 'PASSWORD_RESET', 'SUCCESS', $context, $now);
            return [];
        });
        if (isset($result['error'])) {
            throw new Problem($result['error']);
        }
    }

    public function bindContact(int $userId, string $kind, string $contact, string $challenge, string $code, string $password, array $context, int $now): void
    {
        $target = AccountInput::contact($kind, $contact);
        try {
            $result = Database::transaction($this->db, function (PDO $db) use ($userId, $kind, $target, $challenge, $code, $password, $context, $now): array {
                $owner = $db->prepare('SELECT id,password_hash FROM portal_users WHERE id=? FOR UPDATE');
                $owner->execute([$userId]);
                $user = $owner->fetch();
                if ($user === false || ($kind === 'phone' && !DeliveryGateway::available('phone',$this->deliveryConfiguration))) {
                    throw new Problem('contact_binding_unavailable');
                }
                if (!password_verify($password, $user['password_hash'])) {
                    Audit::user($db, $userId, Audit::email($db, $userId), 'PROFILE_UPDATE', 'FAILURE', $context, $now);
                    return ['error' => 'invalid_credentials'];
                }
                if (!$this->verification->consume($challenge, $kind, $target, 'bind_contact', $code, $userId, $now)) {
                    Audit::user($db, $userId, Audit::email($db, $userId), 'PROFILE_UPDATE', 'FAILURE', $context, $now);
                    return ['error' => 'verification_invalid'];
                }
                $existing = $db->prepare('SELECT value FROM portal_contacts WHERE user_id=? AND kind=?');
                $existing->execute([$userId, $kind]);
                if ($existing->fetchColumn() !== false) {
                    $update = $db->prepare('UPDATE portal_contacts SET value=?,verified_at=? WHERE user_id=? AND kind=?');
                    $update->execute([$target, $now, $userId, $kind]);
                } else {
                    $insert = $db->prepare('INSERT INTO portal_contacts (user_id,kind,value,verified_at,created_at) VALUES (?,?,?,?,?)');
                    $insert->execute([$userId, $kind, $target, $now, $now]);
                }
                $version = $db->prepare('UPDATE portal_users SET session_version=session_version+1,updated_at=? WHERE id=?');
                $version->execute([$now, $userId]);
                $sessions = $db->prepare('DELETE FROM portal_web_sessions WHERE user_id=?');
                $sessions->execute([$userId]);
                Audit::user($db, $userId, Audit::email($db, $userId), 'PROFILE_UPDATE', 'SUCCESS', $context, $now);
                return [];
            });
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            throw new Problem('account_or_contact_exists', 409);
        }
        if (isset($result['error'])) {
            throw new Problem($result['error']);
        }
    }

    public function updateProfile(int $userId, string $displayName, string $region, array $context, int $now): array
    {
        $displayName = AccountInput::displayName($displayName);
        $region = AccountInput::region($region);
        return Database::transaction($this->db, function (PDO $db) use ($userId, $displayName, $region, $context, $now): array {
            $query = $db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');
            $query->execute([$userId]);
            $status = $query->fetchColumn();
            if ($status !== 'active') {
                throw new Problem('account_unavailable', 404);
            }
            $db->prepare('UPDATE portal_users SET display_name=?,region=?,updated_at=? WHERE id=?')
                ->execute([$displayName, $region, $now, $userId]);
            Audit::user($db, $userId, Audit::email($db, $userId), 'PROFILE_UPDATE', 'SUCCESS', $context, $now);
            return ['display_name' => $displayName, 'region' => $region];
        });
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, array $context, int $now): void
    {
        AccountInput::password($newPassword);
        $result = Database::transaction($this->db, function (PDO $db) use ($userId, $currentPassword, $newPassword, $context, $now): array {
            $query = $db->prepare('SELECT status,password_hash,session_version FROM portal_users WHERE id=? FOR UPDATE');
            $query->execute([$userId]);
            $user = $query->fetch();
            if ($user === false || $user['status'] !== 'active') {
                throw new Problem('account_unavailable', 404);
            }
            $email = Audit::email($db, $userId);
            if (strlen($currentPassword) > 64 || !password_verify($currentPassword, $user['password_hash'])) {
                Audit::user($db, $userId, $email, 'PASSWORD_RESET', 'FAILURE', $context, $now);
                return ['error' => 'invalid_credentials', 'status' => 403];
            }
            if (password_verify($newPassword, $user['password_hash'])) {
                Audit::user($db, $userId, $email, 'PASSWORD_RESET', 'FAILURE', $context, $now);
                return ['error' => 'password_unchanged', 'status' => 409];
            }
            $db->prepare('UPDATE portal_users SET password_hash=?,password_changed_at=?,session_version=session_version+1,updated_at=? WHERE id=?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $now, $now, $userId]);
            $db->prepare('DELETE FROM portal_web_sessions WHERE user_id=?')->execute([$userId]);
            $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=?')->execute([$userId]);
            Audit::user($db, $userId, $email, 'PASSWORD_RESET', 'SUCCESS', $context, $now);
            return [];
        });
        if (isset($result['error'])) {
            throw new Problem($result['error'], $result['status']);
        }
    }

    public function acceptCurrentPolicies(int $userId, array $context, int $now): array
    {
        return Database::transaction($this->db,function(PDO $db)use($userId,$context,$now):array{
            $query=$db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');
            $query->execute([$userId]);
            if($query->fetchColumn()!=='active')throw new Problem('account_unavailable',404);
            $consents=new PolicyConsents($db);
            $missing=$consents->missing($userId);
            if($missing!==[]){
                $consents->record($userId,$missing,$context,$now);
                Audit::user($db,$userId,Audit::email($db,$userId),'PROFILE_UPDATE','SUCCESS',$context,$now);
            }
            return ['accepted'=>array_map(static fn(array $policy): array => ['type'=>$policy['type'],
                'version'=>$policy['version'],'effective_date'=>$policy['effective_date']],$missing)];
        });
    }

    public function profile(int $userId): array
    {
        $select = $this->db->prepare('SELECT id,username,display_name,status,region FROM portal_users WHERE id=?');
        $select->execute([$userId]);
        $user = $select->fetch();
        if ($user === false) {
            throw new Problem('account_unavailable', 404);
        }
        $contacts = $this->db->prepare('SELECT kind,value,verified_at FROM portal_contacts WHERE user_id=? ORDER BY kind');
        $contacts->execute([$userId]);
        $user['contacts'] = $contacts->fetchAll();
        return $user;
    }

    private function findOwner(string $realm, string $identifier, bool $lock): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        if ($realm === 'admin') {
            $select = $this->db->prepare('SELECT * FROM portal_admins WHERE username=?' . $suffix);
            $select->execute([strtolower(trim($identifier))]);
        } else {
            try {
                [$kind, $value] = AccountInput::identifier($identifier);
            } catch (Problem) {
                return null;
            }
            if ($kind === 'username') {
                $select = $this->db->prepare('SELECT * FROM portal_users WHERE username=?' . $suffix);
                $select->execute([$value]);
            } else {
                $select = $this->db->prepare('SELECT u.* FROM portal_users u JOIN portal_contacts c ON c.user_id=u.id
                    WHERE c.kind=? AND c.value=? AND c.verified_at IS NOT NULL' . $suffix);
                $select->execute([$kind, $value]);
            }
        }
        $row = $select->fetch();
        return $row === false ? null : $row;
    }

    private function loginAudit(string $realm, ?int $id, ?string $email, string $result, array $context, int $now): void
    {
        if ($realm === 'admin') {
            Audit::admin($this->db, $id, $email ?? '', 'LOGIN_ADMIN', null, $context, $result, [], $now);
        } else {
            Audit::user($this->db, $id, $email, 'LOGIN', $result, $context, $now);
        }
    }
}
