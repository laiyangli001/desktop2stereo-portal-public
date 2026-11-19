<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class AccountControls
{
    public function __construct(private PDO $db)
    {
    }

    public function search(int $adminId, string $query): array
    {
        Permissions::requireAny($this->db,$adminId,['users.read','accounts.restrict']);
        $query = trim($query);
        if (mb_strlen($query,'UTF-8') > 254) {
            throw new Problem('account_query_invalid');
        }
        if (preg_match('/^[1-9][0-9]*$/D',$query)) {
            $statement = $this->db->prepare('SELECT id,username,display_name,status,region,created_at FROM portal_users
                WHERE id=? LIMIT 50');
            $statement->execute([(int)$query]);
        } elseif ($query !== '') {
            $statement = $this->db->prepare("SELECT DISTINCT u.id,u.username,u.display_name,u.status,u.region,u.created_at
                FROM portal_users u LEFT JOIN portal_contacts c ON c.user_id=u.id
                WHERE u.username LIKE ? OR c.value LIKE ? ORDER BY u.id DESC LIMIT 50");
            $needle = '%'.$query.'%';
            $statement->execute([$needle,$needle]);
        } else {
            $statement = $this->db->query('SELECT id,username,display_name,status,region,created_at FROM portal_users
                ORDER BY id DESC LIMIT 50');
        }
        return $statement->fetchAll();
    }

    public function view(int $adminId, int $userId): array
    {
        Permissions::requireAny($this->db,$adminId,['users.read','accounts.restrict']);
        $query = $this->db->prepare('SELECT u.id,u.username,u.display_name,u.status,u.region,u.created_at,
            c.login_blocked,c.purchase_blocked,c.reason,c.updated_at,c.updated_by
            FROM portal_users u LEFT JOIN portal_user_controls c ON c.user_id=u.id WHERE u.id=?');
        $query->execute([$userId]);
        $user = $query->fetch();
        if ($user === false) {
            throw new Problem('user_not_found',404);
        }
        $contacts = $this->db->prepare('SELECT kind,value,verified_at FROM portal_contacts WHERE user_id=? ORDER BY kind');
        $contacts->execute([$userId]);
        $user['contacts'] = $contacts->fetchAll();
        $identity = $this->db->prepare('SELECT status,document_type,document_country,requested_by,verified_by,
            requested_at,verified_at,updated_at,decision_reason
            FROM portal_identity_verifications WHERE user_id=?');
        $identity->execute([$userId]);
        $user['identity'] = $identity->fetch() ?: [
            'status'=>'NOT_REQUIRED','document_type'=>null,'document_country'=>null,
            'requested_by'=>null,'verified_by'=>null,'requested_at'=>null,'verified_at'=>null,
            'updated_at'=>null,'decision_reason'=>null,
        ];
        $user['login_blocked'] = (bool)($user['login_blocked'] ?? false);
        $user['purchase_blocked'] = (bool)($user['purchase_blocked'] ?? false);
        return $user;
    }

    public function apply(int $adminId, int $userId, ?bool $loginBlocked, ?bool $purchaseBlocked, bool $forceLogout, string $reason, bool $confirmed, array $context, int $now): array
    {
        if (!$confirmed) {
            throw new Problem('explicit_confirmation_required');
        }
        $reason = trim($reason);
        if (mb_strlen($reason,'UTF-8') < 1 || mb_strlen($reason,'UTF-8') > 500 ||
            ($loginBlocked === null && $purchaseBlocked === null && !$forceLogout)) {
            throw new Problem('emergency_control_invalid');
        }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$userId,$loginBlocked,$purchaseBlocked,$forceLogout,$reason,$context,$now): array {
            Permissions::require($db,$adminId,'accounts.restrict');
            $userQuery = $db->prepare('SELECT id,session_version FROM portal_users WHERE id=? FOR UPDATE');
            $userQuery->execute([$userId]);
            $user = $userQuery->fetch();
            if ($user === false) {
                throw new Problem('user_not_found',404);
            }
            $controlQuery = $db->prepare('SELECT * FROM portal_user_controls WHERE user_id=? FOR UPDATE');
            $controlQuery->execute([$userId]);
            $before = $controlQuery->fetch() ?: ['login_blocked'=>0,'purchase_blocked'=>0,'reason'=>'','updated_at'=>0];
            $nextLogin = $loginBlocked ?? (bool)$before['login_blocked'];
            $nextPurchase = $purchaseBlocked ?? (bool)$before['purchase_blocked'];
            $db->prepare('INSERT INTO portal_user_controls
                (user_id,login_blocked,purchase_blocked,reason,updated_at,updated_by) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE login_blocked=VALUES(login_blocked),purchase_blocked=VALUES(purchase_blocked),
                reason=VALUES(reason),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by)')
                ->execute([$userId,(int)$nextLogin,(int)$nextPurchase,$reason,$now,$adminId]);
            $revoked = 0;
            if ($forceLogout) {
                $db->prepare('UPDATE portal_users SET session_version=session_version+1,updated_at=? WHERE id=?')
                    ->execute([$now,$userId]);
                $db->prepare('UPDATE portal_licenses SET session_version=session_version+1,updated_at=? WHERE user_id=?')
                    ->execute([$now,$userId]);
                $web = $db->prepare('DELETE FROM portal_web_sessions WHERE user_id=?');
                $web->execute([$userId]);
                $revoked += $web->rowCount();
                $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=?')->execute([$userId]);
            }
            $operation = ($nextLogin || $nextPurchase || $forceLogout) ? 'EMERGENCY_LOCK' : 'EMERGENCY_UNLOCK';
            $after = ['login_blocked'=>$nextLogin,'purchase_blocked'=>$nextPurchase,'reason'=>$reason];
            $db->prepare('INSERT INTO portal_user_restriction_events
                (user_id,operator_id,operator_email,action_type,before_json,after_json,reason,source_ip,created_at)
                VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$userId,$adminId,$this->adminEmail($db,$adminId),$operation,
                    json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),
                    $reason,$context['source_ip'] ?? null,$now]);
            Audit::admin($db,$adminId,$this->adminEmail($db,$adminId),$operation,(string)$userId,$context,'SUCCESS',[
                'before'=>['login_blocked'=>(bool)$before['login_blocked'],'purchase_blocked'=>(bool)$before['purchase_blocked']],
                'after'=>['login_blocked'=>$nextLogin,'purchase_blocked'=>$nextPurchase],
                'force_logout'=>$forceLogout,'web_sessions_revoked'=>$revoked,'reason'=>$reason,
            ],$now);
            return ['user_id'=>$userId,'login_blocked'=>$nextLogin,'purchase_blocked'=>$nextPurchase,
                'force_logout'=>$forceLogout,'revoked_sessions'=>$revoked,'operation_type'=>$operation];
        });
    }

    public function previewBatch(int $adminId, array $input, array $context, int $now): array
    {
        [$selector,$actions] = $this->batchInput($input);
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$selector,$actions,$context,$now): array {
            Permissions::require($db,$adminId,'accounts.restrict');
            $ids = $this->collect($db,$selector);
            if (count($ids) > 10000) {
                throw new Problem('batch_match_limit_exceeded',422);
            }
            $id = bin2hex(random_bytes(16));
            $selectionHash = hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
            $db->prepare('INSERT INTO portal_account_batch_previews
                (id,admin_id,selector_json,action_json,user_ids_json,selection_hash,match_count,created_at,expires_at)
                VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$adminId,json_encode($selector,JSON_THROW_ON_ERROR),json_encode($actions,JSON_THROW_ON_ERROR),
                    json_encode($ids,JSON_THROW_ON_ERROR),$selectionHash,count($ids),$now,$now+300]);
            Audit::admin($db,$adminId,$this->adminEmail($db,$adminId),'BATCH_EMERGENCY_PREVIEW','user_accounts:batch',
                $context,'SUCCESS',['selector'=>$selector,'actions'=>$actions,'match_count'=>count($ids),'expires_at'=>$now+300],$now);
            return ['preview_id'=>$id,'match_count'=>count($ids),'expires_at'=>$now+300,'selection_hash'=>$selectionHash];
        });
    }

    public function applyBatch(int $adminId, string $previewId, int $confirmationCount, bool $confirmed, array $context, int $now): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$previewId) || !$confirmed || $confirmationCount < 1) {
            throw new Problem('explicit_confirmation_required');
        }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$previewId,$confirmationCount,$context,$now): array {
            Permissions::require($db,$adminId,'accounts.restrict');
            $query = $db->prepare('SELECT * FROM portal_account_batch_previews
                WHERE id=? AND admin_id=? AND consumed_at IS NULL FOR UPDATE');
            $query->execute([$previewId,$adminId]);
            $preview = $query->fetch();
            if ($preview === false || (int)$preview['expires_at'] <= $now || (int)$preview['match_count'] !== $confirmationCount) {
                throw new Problem('batch_preview_invalid_or_expired',409);
            }
            $selector = json_decode($preview['selector_json'],true,16,JSON_THROW_ON_ERROR);
            $actions = json_decode($preview['action_json'],true,16,JSON_THROW_ON_ERROR);
            $original = json_decode($preview['user_ids_json'],true,16,JSON_THROW_ON_ERROR);
            $current = $this->collect($db,$selector);
            if ($current !== $original || hash('sha256',json_encode($current,JSON_THROW_ON_ERROR)) !== $preview['selection_hash']) {
                throw new Problem('batch_selection_changed',409);
            }
            $email = $this->adminEmail($db,$adminId);
            foreach ($current as $userId) {
                $control = $db->prepare('SELECT * FROM portal_user_controls WHERE user_id=? FOR UPDATE');
                $control->execute([$userId]);
                $before = $control->fetch() ?: ['login_blocked'=>0,'purchase_blocked'=>0,'reason'=>'','updated_at'=>0];
                $login = $actions['login_blocked'] ?? (bool)$before['login_blocked'];
                $purchase = $actions['purchase_blocked'] ?? (bool)$before['purchase_blocked'];
                $db->prepare('INSERT INTO portal_user_controls
                    (user_id,login_blocked,purchase_blocked,reason,updated_at,updated_by) VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE login_blocked=VALUES(login_blocked),purchase_blocked=VALUES(purchase_blocked),
                    reason=VALUES(reason),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by)')
                    ->execute([$userId,(int)$login,(int)$purchase,$actions['reason'],$now,$adminId]);
                $operation = ($login || $purchase) ? 'EMERGENCY_LOCK' : 'EMERGENCY_UNLOCK';
                $db->prepare('INSERT INTO portal_user_restriction_events
                    (user_id,operator_id,operator_email,action_type,before_json,after_json,reason,source_ip,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$userId,$adminId,$email,$operation,json_encode($before,JSON_THROW_ON_ERROR),
                        json_encode(['login_blocked'=>$login,'purchase_blocked'=>$purchase,'reason'=>$actions['reason']],JSON_THROW_ON_ERROR),
                        $actions['reason'],$context['source_ip'] ?? null,$now]);
            }
            $consume = $db->prepare('UPDATE portal_account_batch_previews SET consumed_at=? WHERE id=? AND consumed_at IS NULL');
            $consume->execute([$now,$previewId]);
            if ($consume->rowCount() !== 1) {
                throw new Problem('batch_preview_already_consumed',409);
            }
            $operation = (($actions['login_blocked'] ?? false) || ($actions['purchase_blocked'] ?? false))
                ? 'EMERGENCY_LOCK' : 'EMERGENCY_UNLOCK';
            Audit::admin($db,$adminId,$email,$operation,'user_accounts:batch',$context,'SUCCESS',[
                'preview_id'=>$previewId,'selector'=>$selector,'actions'=>$actions,'affected_accounts'=>count($current),
            ],$now);
            return ['affected_accounts'=>count($current),'operation_type'=>$operation];
        });
    }

    private function batchInput(array $input): array
    {
        $selector = [
            'ip_cidr'=>trim((string)($input['selector']['ip_cidr'] ?? '')),
            'email_domain'=>strtolower(trim((string)($input['selector']['email_domain'] ?? ''))),
            'register_from'=>(int)($input['selector']['register_from'] ?? 0),
            'register_until'=>(int)($input['selector']['register_until'] ?? 0),
        ];
        if ($selector['ip_cidr'] === '' && $selector['email_domain'] === '' &&
            $selector['register_from'] <= 0 && $selector['register_until'] <= 0) {
            throw new Problem('batch_selector_required');
        }
        if ($selector['ip_cidr'] !== '') {
            try {
                $this->parseCidr($selector['ip_cidr']);
            } catch (Problem) {
                throw new Problem('batch_ip_range_invalid');
            }
        }
        $domain = ltrim($selector['email_domain'],'@');
        if ($domain !== '' && (!str_contains($domain,'.') || strlen($domain) > 253 ||
            !preg_match('/^[a-z0-9.-]+$/D',$domain) || str_contains($domain,'..'))) {
            throw new Problem('batch_email_domain_invalid');
        }
        $selector['email_domain'] = $domain;
        if ($selector['register_from'] > 0 && $selector['register_until'] > 0 &&
            $selector['register_from'] > $selector['register_until']) {
            throw new Problem('batch_time_range_invalid');
        }
        $login = array_key_exists('login_blocked',$input['actions'] ?? []) ? (bool)$input['actions']['login_blocked'] : null;
        $purchase = array_key_exists('purchase_blocked',$input['actions'] ?? []) ? (bool)$input['actions']['purchase_blocked'] : null;
        $reason = trim((string)($input['actions']['reason'] ?? ''));
        if ($login === null && $purchase === null) {
            throw new Problem('batch_action_required');
        }
        if (mb_strlen($reason,'UTF-8') < 1 || mb_strlen($reason,'UTF-8') > 500) {
            throw new Problem('batch_reason_invalid');
        }
        return [$selector,['login_blocked'=>$login,'purchase_blocked'=>$purchase,'reason'=>$reason]];
    }

    private function collect(PDO $db, array $selector): array
    {
        $where = [];
        $parameters = [];
        if ($selector['register_from'] > 0) {
            $where[] = 'r.register_time>=?';
            $parameters[] = $selector['register_from'];
        }
        if ($selector['register_until'] > 0) {
            $where[] = 'r.register_time<=?';
            $parameters[] = $selector['register_until'];
        }
        if ($selector['email_domain'] !== '') {
            $where[] = 'LOWER(r.email) LIKE ?';
            $parameters[] = '%@'.$selector['email_domain'];
        }
        $sql = 'SELECT DISTINCT u.id,r.register_ip FROM portal_users u
            JOIN portal_registration_records r ON r.user_id=u.id';
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ',$where);
        }
        $sql .= ' ORDER BY u.id DESC LIMIT 50001';
        $query = $db->prepare($sql);
        $query->execute($parameters);
        $rows = $query->fetchAll();
        if (count($rows) > 50000) {
            throw new Problem('batch_selection_too_broad',422);
        }
        $cidr = null;
        if ($selector['ip_cidr'] !== '') {
            $cidr = $this->parseCidr($selector['ip_cidr']);
        }
        $ids = [];
        foreach ($rows as $row) {
            if ($cidr !== null && !$this->contains($cidr[0],$cidr[1],(string)$row['register_ip'])) {
                continue;
            }
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    private function parseCidr(string $value): array
    {
        $value = trim($value);
        [$address,$prefix] = str_contains($value,'/') ? explode('/',$value,2) + [1,''] : [$value,''];
        if (filter_var($address,FILTER_VALIDATE_IP) === false) {
            throw new Problem('batch_ip_range_invalid');
        }
        $packed = inet_pton($address);
        if (!is_string($packed)) {
            throw new Problem('batch_ip_range_invalid');
        }
        $maximum = strlen($packed)*8;
        $bits = $prefix === '' ? $maximum : filter_var($prefix,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$maximum]]);
        if ($bits === false) {
            throw new Problem('batch_ip_range_invalid');
        }
        return [$packed,(int)$bits];
    }

    private function contains(string $network, int $prefix, string $address): bool
    {
        $ip = @inet_pton($address);
        if ($ip === false || strlen($ip) !== strlen($network) || $prefix < 0 || $prefix > strlen($network)*8) {
            return false;
        }
        $bytes = intdiv($prefix,8);
        $bits = $prefix % 8;
        if ($bytes > 0 && substr($ip,0,$bytes) !== substr($network,0,$bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8-$bits)) & 0xFF;
        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    private function adminEmail(PDO $db, int $adminId): string
    {
        $query = $db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$adminId]);
        $email = $query->fetchColumn();
        if ($email === false) {
            throw new Problem('administrator_inactive',403);
        }
        return (string)$email;
    }
}
