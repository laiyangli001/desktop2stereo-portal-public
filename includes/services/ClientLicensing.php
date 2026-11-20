<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ClientLicensing
{
    public function __construct(private PDO $db, private LicenseCredentials $credentials)
    {
    }

    public function login(string $identifier, string $password, string $deviceHash, string $publicKey, array $context, int $now, string $product = 'desktop2stereo'): array
    {
        ProductCatalog::code($product);
        self::device($deviceHash);
        CoreKeyWrapper::deviceKey($publicKey);
        $context['device_fingerprint'] = $deviceHash;
        $result = Database::transaction($this->db,function (PDO $db) use ($identifier,$password,$deviceHash,$publicKey,$context,$now,$product): array {
            $ipAllowed = RateLimiter::take($db,'login:ip:user:' . ($context['source_ip'] ?? 'unknown'),30,600,$now);
            $user = $this->findUser($identifier);
            $id = $user === null ? null : (int)$user['id'];
            $email = $id === null ? null : Audit::email($db,$id);
            $identity = $id === null ? 'unknown:' . strtolower(substr($identifier,0,254)) : 'id:' . $id;
            $bucket = 'login:account:user:' . $identity;
            $allowed = RateLimiter::take($db,$bucket,5,900,$now);
            $error = null;
            if (!$ipAllowed || !$allowed) {
                $error = ['error'=>'login_temporarily_locked','status'=>429];
            } elseif ($user === null || strlen($password)>64 || !password_verify($password,$user['password_hash'])) {
                if ($user === null) {
                    password_verify($password,password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT));
                }
                $error = ['error'=>'invalid_credentials','status'=>401];
            } elseif (!$this->userAllowed($user)) {
                $error = ['error'=>'account_restricted_or_unverified','status'=>403];
            }
            if ($error !== null) {
                Audit::user($db,$id,$email,'LOGIN',$error['status']===401 ? 'FAILURE':'BLOCKED',$context,$now);
                return $error;
            }
            $select = $db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');
            $select->execute([$id,$product]);
            $license = $select->fetch();
            if ($license === false) {
                $productQuery = $db->prepare('SELECT code FROM portal_products WHERE code=? AND enabled=1 AND deleted_at IS NULL');
                $productQuery->execute([$product]);
                if ($productQuery->fetchColumn() === false) { throw new Problem('product_not_found',404); }
                $license = ['id'=>bin2hex(random_bytes(16)),'user_id'=>$id,'product'=>$product,'kind'=>'trial','status'=>'active',
                    'trial_started_at'=>$now,'expires_at'=>$now+30*86400,'session_version'=>0];
                $db->prepare('INSERT INTO portal_licenses
                    (id,user_id,product,kind,status,trial_started_at,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$license['id'],$id,$product,'trial','active',$now,$license['expires_at'],$now,$now]);
            }
            $unavailable = $this->licenseError($license,$now);
            if ($unavailable !== null) {
                Audit::user($db,$id,$email,'LOGIN','BLOCKED',$context,$now);
                return $unavailable;
            }
            $version = (int)$license['session_version']+1;
            // Signing and resource wrapping happen before any commit; missing secrets cannot start a trial.
            $this->credentials->registerSigningKey($db,$now);
            $bundle = $this->credentials->issue($license,$deviceHash,$publicKey,$now,$version,
                (new PlatformSettings($db))->heartbeatConfiguration());
            $db->prepare('INSERT INTO portal_devices (user_id,device_hash,public_key,first_seen_at,last_seen_at)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE public_key=?,last_seen_at=?')
                ->execute([$id,$deviceHash,$publicKey,$now,$now,$publicKey,$now]);
            $token = bin2hex(random_bytes(32));
            $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=?')->execute([$id,$product]);
            $db->prepare('INSERT INTO portal_client_sessions
                (user_id,product,public_key,token_hash,device_hash,user_version,session_version,created_at,verified_at,expires_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$product,$publicKey,hash('sha256',$token),$deviceHash,
                    $user['session_version'],$version,$now,$now,$bundle['offline_expires_at']]);
            $db->prepare('UPDATE portal_licenses SET session_version=?,updated_at=? WHERE id=?')
                ->execute([$version,$now,$license['id']]);
            $this->record($id,$license,$deviceHash,$bundle,$now);
            RateLimiter::clear($db,$bucket);
            Audit::user($db,$id,$email,'LOGIN','SUCCESS',$context,$now);
            return $bundle + ['access_token'=>$token,'token_type'=>'Bearer','session_version'=>$version,
                'user_id'=>$id,'license'=>$this->view($license)];
        });
        return self::result($result);
    }

    public function heartbeat(string $token, string $deviceHash, int $now): array
    {
        self::device($deviceHash);
        return Database::transaction($this->db,function (PDO $db) use ($token,$deviceHash,$now): array {
            [$user,$session,$license] = $this->session($token,$deviceHash,$now);
            $publicKey = $session['public_key'];
            $this->credentials->registerSigningKey($db,$now);
            $bundle = $this->credentials->issue($license,$deviceHash,$publicKey,$now,(int)$session['session_version'],
                (new PlatformSettings($db))->heartbeatConfiguration());
            $db->prepare('UPDATE portal_client_sessions SET verified_at=?,expires_at=? WHERE user_id=? AND product=?')
                ->execute([$now,$bundle['offline_expires_at'],$user['id'],$license['product']]);
            $db->prepare('UPDATE portal_devices SET last_seen_at=? WHERE user_id=? AND device_hash=?')
                ->execute([$now,$user['id'],$deviceHash]);
            $this->record((int)$user['id'],$license,$deviceHash,$bundle,$now);
            return $bundle + ['session_version'=>(int)$session['session_version'],'license'=>$this->view($license)];
        });
    }

    public function logout(string $token, string $deviceHash, array $context, int $now): void
    {
        self::device($deviceHash);
        Database::transaction($this->db,function (PDO $db) use ($token,$deviceHash,$context,$now): void {
            [$user,$session] = $this->session($token,$deviceHash,$now);
            $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=?')->execute([$user['id'],$session['product']]);
            $context['device_fingerprint'] = $deviceHash;
            Audit::user($db,(int)$user['id'],Audit::email($db,(int)$user['id']),'LOGOUT','SUCCESS',$context,$now);
        });
    }

    public function forUser(int $userId, string $product = 'desktop2stereo'): ?array
    {
        $select = $this->db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=?');
        $select->execute([$userId,ProductCatalog::code($product)]);
        $row = $select->fetch();
        return $row === false ? null : $this->view($row);
    }

    public function devices(int $userId, string $product = 'desktop2stereo'): array
    {
        return (new LicenseOverview($this->db))->devices($userId,$product);
    }

    private function session(string $token, string $deviceHash, int $now): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) {
            throw new Problem('client_session_invalid',401);
        }
        $hash = hash('sha256',$token);
        // Resolve first, then always lock user before license/session, matching password reset and login.
        $select = $this->db->prepare('SELECT user_id,product FROM portal_client_sessions WHERE token_hash=?');
        $select->execute([$hash]);
        $resolved = $select->fetch();
        if ($resolved === false) { throw new Problem('client_session_replaced',401); }
        $id = $resolved['user_id'];
        $product = $resolved['product'];
        $select = $this->db->prepare('SELECT * FROM portal_users WHERE id=? FOR UPDATE');
        $select->execute([$id]);
        $user = $select->fetch();
        $select = $this->db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');
        $select->execute([$id,$product]);
        $license = $select->fetch();
        $select = $this->db->prepare('SELECT * FROM portal_client_sessions WHERE user_id=? AND product=? FOR UPDATE');
        $select->execute([$id,$product]);
        $session = $select->fetch();
        if ($session === false || !hash_equals($session['token_hash'],$hash) ||
            $session['device_hash'] !== $deviceHash || $user === false ||
            (int)$session['user_version'] !== (int)$user['session_version'] ||
            $license === false || (int)$session['session_version'] !== (int)$license['session_version']) {
            throw new Problem('client_session_replaced',401);
        }
        if (!$this->userAllowed($user)) { throw new Problem('account_restricted_or_unverified',403); }
        if ((int)$session['expires_at'] <= $now || $now < (int)$session['verified_at']) {
            throw new Problem('client_session_expired',401);
        }
        self::result($this->licenseError($license,$now) ?? []);
        return [$user,$session,$license];
    }

    private function findUser(string $identifier): ?array
    {
        try { [$kind,$value] = AccountInput::identifier($identifier); }
        catch (Problem) { return null; }
        if ($kind === 'username') {
            $select = $this->db->prepare('SELECT * FROM portal_users WHERE username=? FOR UPDATE');
            $select->execute([$value]);
        } else {
            $select = $this->db->prepare('SELECT u.* FROM portal_users u JOIN portal_contacts c ON c.user_id=u.id
                WHERE c.kind=? AND c.value=? AND c.verified_at IS NOT NULL FOR UPDATE');
            $select->execute([$kind,$value]);
        }
        return $select->fetch() ?: null;
    }

    private function userAllowed(array $user): bool
    {
        $verified = $this->db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');
        $verified->execute([$user['id']]);
        $control = $this->db->prepare('SELECT login_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');
        $control->execute([$user['id']]);
        return $user['status'] === 'active' && (int)$verified->fetchColumn()>0 && (int)$control->fetchColumn() !== 1;
    }

    private function licenseError(array $license, int $now): ?array
    {
        if ($license['status'] !== 'active') { return ['error'=>'license_disabled','status'=>403]; }
        if ($license['expires_at'] !== null && (int)$license['expires_at'] <= $now) {
            return ['error'=>'license_expired','status'=>403];
        }
        if ($now < (int)$license['trial_started_at']) { return ['error'=>'server_time_invalid','status'=>503]; }
        return null;
    }

    private function record(int $userId, array $license, string $device, array $bundle, int $now): void
    {
        $this->db->prepare('INSERT INTO portal_license_credentials
            (id,user_id,license_id,device_hash,key_id,token_hash,issued_at,expires_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$bundle['offline_id'],$userId,$license['id'],$device,$bundle['key_id'],
                hash('sha256',$bundle['offline_token']),$now,$bundle['offline_expires_at']]);
    }

    private function view(array $license): array
    {
        return ['id'=>$license['id'],'product'=>$license['product'],'kind'=>$license['kind'],'status'=>$license['status'],
            'trial_started_at'=>$license['trial_started_at'] === null ? null : (int)$license['trial_started_at'],
            'expires_at'=>$license['expires_at'] === null ? null : (int)$license['expires_at']];
    }

    private static function device(string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new Problem('device_hash_invalid'); }
    }

    private static function result(array $result): array
    {
        if (isset($result['error'])) { throw new Problem($result['error'],$result['status']); }
        return $result;
    }
}
