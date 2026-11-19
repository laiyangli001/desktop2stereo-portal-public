<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class AuditRecords
{
    public const RESOURCE_TYPES = ['access_log','registration','admin_audit'];
    private const ACTIONS = ['REGISTER','LOGIN','LOGOUT','PURCHASE','DOWNLOAD','PASSWORD_RESET','PROFILE_UPDATE','WITHDRAWAL'];

    public function __construct(private PDO $db)
    {
    }

    public static function normalizeFilters(string $resource, array $input, int $now): array
    {
        if (!in_array($resource,self::RESOURCE_TYPES,true)) {
            throw new Problem('audit_resource_invalid');
        }
        $integer = static function (mixed $value, int $default = 0): int {
            if ($value === null || $value === '') {
                return $default;
            }
            if (is_int($value)) {
                return $value;
            }
            if (!is_string($value) || !preg_match('/^[0-9]{1,12}$/D',$value)) {
                throw new Problem('audit_time_invalid');
            }
            return (int)$value;
        };
        $filters = [
            'start_time'=>$integer($input['start_time'] ?? null),
            'end_time'=>$integer($input['end_time'] ?? null),
        ];
        if ($filters['start_time'] < 0 || $filters['end_time'] < 0 ||
            ($filters['start_time'] > 0 && $filters['end_time'] > 0 && $filters['start_time'] > $filters['end_time']) ||
            $filters['end_time'] > $now + 300) {
            throw new Problem('audit_time_invalid');
        }
        if ($resource === 'access_log') {
            $userId = trim((string)($input['user_id'] ?? ''));
            if ($userId !== '' && (!preg_match('/^[1-9][0-9]{0,18}$/D',$userId))) {
                throw new Problem('audit_filter_invalid');
            }
            $email = trim((string)($input['email'] ?? ''));
            $sourceIp = trim((string)($input['source_ip'] ?? ''));
            $action = strtoupper(trim((string)($input['action_type'] ?? '')));
            if (mb_strlen($email,'UTF-8') > 254 || mb_strlen($sourceIp,'UTF-8') > 64 ||
                ($action !== '' && !in_array($action,self::ACTIONS,true))) {
                throw new Problem('audit_filter_invalid');
            }
            $filters += ['user_id'=>$userId === '' ? null : $userId,'email'=>$email,'source_ip'=>$sourceIp,
                'action_type'=>$action];
        } elseif ($resource === 'admin_audit') {
            $operator = trim((string)($input['operator_email'] ?? ''));
            $operation = strtoupper(trim((string)($input['operation_type'] ?? '')));
            if (mb_strlen($operator,'UTF-8') > 254 || mb_strlen($operation,'UTF-8') > 64) {
                throw new Problem('audit_filter_invalid');
            }
            $filters += ['operator_email'=>$operator,'operation_type'=>$operation];
        }
        return $filters;
    }

    public function page(int $adminId, string $resource, array $input, int $page, int $pageSize, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'audit.read');
        $filters = self::normalizeFilters($resource,$input,$now);
        $page = max(1,$page);
        $pageSize = max(1,min(100,$pageSize));
        [$where,$parameters] = $this->where($resource,$filters);
        $table = $this->table($resource);
        $count = $this->db->prepare("SELECT COUNT(*) FROM $table $where");
        $count->execute($parameters);
        $offset = ($page-1)*$pageSize;
        $query = $this->db->prepare("SELECT * FROM $table $where ORDER BY id DESC LIMIT $pageSize OFFSET $offset");
        $query->execute($parameters);
        $rows = $query->fetchAll();
        $operation = $resource === 'access_log' ? 'VIEW_USER_LOG' : ($resource === 'registration' ? 'VIEW_REGISTRATION' : 'VIEW_ADMIN_AUDIT');
        $this->adminAudit($adminId,$operation,$table,$context,$filters + ['page'=>$page,'page_size'=>$pageSize],$now);
        return ['items'=>$rows,'page'=>$page,'per_page'=>$pageSize,'total'=>(int)$count->fetchColumn(),'filters'=>$filters];
    }

    public function verifyUserLogHash(int $adminId, string $id, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'audit.read');
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id)) {
            throw new Problem('audit_log_id_invalid');
        }
        $query = $this->db->prepare('SELECT * FROM portal_user_events WHERE id=?');
        $query->execute([$id]);
        $row = $query->fetch();
        if ($row === false) {
            throw new Problem('audit_log_not_found',404);
        }
        $hash = hash('sha256',json_encode([
            $row['user_id'] === null ? null : (int)$row['user_id'],
            $row['action_type'],
            (int)$row['event_time'],
            $row['source_ip'],
            $row['result_status'],
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $consistent = hash_equals($row['record_hash'],$hash);
        $this->adminAudit($adminId,'VERIFY_USER_LOG_HASH',$id,$context,['consistent'=>$consistent],$now);
        return ['id'=>(int)$id,'consistent'=>$consistent,'stored_hash'=>$row['record_hash'],'computed_hash'=>$hash];
    }

    public function requestExport(int $adminId, string $resource, array $input, array $context, int $now): array
    {
        Permissions::requireAny($this->db,$adminId,['audit.export.request','audit.export.approve']);
        $filters = self::normalizeFilters($resource,$input,$now);
        if ($filters['start_time'] < 1 || $filters['end_time'] < 1) {
            throw new Problem('export_time_range_required');
        }
        if ($filters['end_time']-$filters['start_time'] > 366*86400) {
            throw new Problem('export_range_too_large');
        }
        ksort($filters);
        $canonical = json_encode($filters,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256',$canonical);
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$resource,$filters,$canonical,$hash,$context,$now): array {
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_audit_export_requests
                (id,requester_id,resource_type,filters_json,filter_hash,status,requested_at,expires_at)
                VALUES (?,?,?,?,?,\'APPROVED\',?,?)')
                ->execute([$id,$adminId,$resource,$canonical,$hash,$now,$now+86400]);
            $email = $this->adminEmail($db,$adminId);
            $db->prepare('INSERT INTO portal_audit_export_actions
                (request_id,actor_id,actor_email,action_type,result,details_json,source_ip,created_at)
                VALUES (?,?,?,\'REQUEST\',\'SUCCESS\',?,?,?)')
                ->execute([$id,$adminId,$email,json_encode(['filters'=>$filters],JSON_THROW_ON_ERROR),
                    $context['source_ip'] ?? null,$now]);
            Audit::admin($db,$adminId,$email,'REQUEST_AUDIT_EXPORT',$resource,$context,'SUCCESS',
                ['request_id'=>$id,'resource_type'=>$resource,'filters'=>$filters],$now);
            return ['id'=>$id,'status'=>'APPROVED','expires_at'=>$now+86400,'resource_type'=>$resource,'filters'=>$filters,'filter_hash'=>$hash,'requested_at'=>$now];
        });
    }

    public function exports(int $adminId, bool $approverView): array
    {
        if ($approverView) {
            Permissions::require($this->db,$adminId,'audit.export.approve');
            $query = $this->db->query('SELECT r.*,a.username AS requester_username,d.username AS decided_username
                FROM portal_audit_export_requests r JOIN portal_admins a ON a.id=r.requester_id
                LEFT JOIN portal_admins d ON d.id=r.decided_by ORDER BY r.requested_at DESC,r.id DESC LIMIT 100');
        } else {
            Permissions::requireAny($this->db,$adminId,['audit.export.request','audit.export.approve']);
            $query = $this->db->prepare('SELECT r.*,a.username AS requester_username,d.username AS decided_username
                FROM portal_audit_export_requests r JOIN portal_admins a ON a.id=r.requester_id
                LEFT JOIN portal_admins d ON d.id=r.decided_by WHERE r.requester_id=?
                ORDER BY r.requested_at DESC,r.id DESC LIMIT 100');
            $query->execute([$adminId]);
        }
        return $query->fetchAll();
    }

    public function decideExport(int $adminId, string $requestId, string $decision, string $reason, array $context, int $now): array
    {
        Permissions::requireAny($this->db,$adminId,['audit.export.request','audit.export.approve']);
        throw new Problem('export_approval_not_required',410);
    }

    public function beginDownload(int $adminId, string $requestId, array $context, int $now): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$requestId)) {
            throw new Problem('export_request_invalid');
        }
        Permissions::requireAny($this->db,$adminId,['audit.export.request','audit.export.approve']);
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$requestId,$context,$now): array {
            $query = $db->prepare('SELECT * FROM portal_audit_export_requests WHERE id=? AND requester_id=? FOR UPDATE');
            $query->execute([$requestId,$adminId]);
            $request = $query->fetch();
            if ($request === false) {
                throw new Problem('export_request_not_found',404);
            }
            if (!in_array($request['status'],['APPROVED','PENDING'],true)) {
                throw new Problem('export_not_approved',403);
            }
            if ($request['expires_at'] !== null && (int)$request['expires_at'] <= $now) {
                $db->prepare("UPDATE portal_audit_export_requests SET status='EXPIRED' WHERE id=?")->execute([$requestId]);
                throw new Problem('export_approval_expired',410);
            }
            $filters = json_decode($request['filters_json'],true,16,JSON_THROW_ON_ERROR);
            $db->prepare('UPDATE portal_audit_export_requests SET download_count=download_count+1,last_downloaded_at=? WHERE id=?')
                ->execute([$now,$requestId]);
            $email = $this->adminEmail($db,$adminId);
            $operation = match ($request['resource_type']) {
                'access_log'=>'EXPORT_LOG',
                'registration'=>'EXPORT_REGISTRATION',
                default=>'EXPORT_ADMIN_AUDIT',
            };
            Audit::admin($db,$adminId,$email,$operation,$requestId,$context,'STARTED',
                ['request_id'=>$requestId,'resource_type'=>$request['resource_type'],'filters'=>$filters],$now);
            return $request + ['filters'=>$filters,'operation_type'=>$operation,'admin_email'=>$email];
        });
    }

    public function finishDownload(int $adminId, array $download, int $rows, array $context, int $now): void
    {
        $this->adminAudit($adminId,$download['operation_type'],$download['id'],$context,
            ['request_id'=>$download['id'],'resource_type'=>$download['resource_type'],'row_count'=>$rows],$now);
    }

    public function streamRows(string $resource, array $filters, int $afterId, int $limit): array
    {
        [$where,$parameters] = $this->where($resource,$filters);
        $where .= $where === '' ? ' WHERE id>?' : ' AND id>?';
        $parameters[] = $afterId;
        $table = $this->table($resource);
        $query = $this->db->prepare("SELECT * FROM $table $where ORDER BY id LIMIT $limit");
        $query->execute($parameters);
        return $query->fetchAll();
    }

    public static function filename(string $resource, int $now): string
    {
        $prefix = ['access_log'=>'access_log','registration'=>'registration_records','admin_audit'=>'admin_audit'][$resource];
        return $prefix.'_'.gmdate('Ymd_His',$now).'.csv';
    }

    public static function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string)$value;
        $trimmed = ltrim($text," \t\r\n");
        if ($trimmed !== '' && str_contains("=+-@'",$trimmed[0])) {
            return "'".$text;
        }
        return $text;
    }

    public static function csvTime(mixed $value): string
    {
        $seconds = $value === null ? 0 : (int)$value;
        return $seconds > 0 ? gmdate('Y-m-d\TH:i:s\Z',$seconds) : '';
    }

    private function where(string $resource, array $filters): array
    {
        $parts = [];
        $parameters = [];
        if (($filters['start_time'] ?? 0) > 0) {
            $column = $resource === 'registration' ? 'register_time' : ($resource === 'access_log' ? 'event_time' : 'operation_time');
            $parts[] = "$column>=?";
            $parameters[] = $filters['start_time'];
        }
        if (($filters['end_time'] ?? 0) > 0) {
            $column = $resource === 'registration' ? 'register_time' : ($resource === 'access_log' ? 'event_time' : 'operation_time');
            $parts[] = "$column<=?";
            $parameters[] = $filters['end_time'];
        }
        if ($resource === 'access_log') {
            foreach (['user_id'=>'user_id','email'=>'email','source_ip'=>'source_ip','action_type'=>'action_type'] as $key=>$column) {
                if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                    $parts[] = "$column=?";
                    $parameters[] = $filters[$key];
                }
            }
        } elseif ($resource === 'admin_audit') {
            foreach (['operator_email'=>'operator_email','operation_type'=>'operation_type'] as $key=>$column) {
                if (($filters[$key] ?? '') !== '') {
                    $parts[] = "$column=?";
                    $parameters[] = $filters[$key];
                }
            }
        }
        return [$parts === [] ? '' : ' WHERE '.implode(' AND ',$parts),$parameters];
    }

    private function table(string $resource): string
    {
        return match ($resource) {
            'access_log'=>'portal_user_events',
            'registration'=>'portal_registration_records',
            'admin_audit'=>'portal_admin_audit',
            default=>throw new Problem('audit_resource_invalid'),
        };
    }

    private function adminAudit(int $adminId, string $operation, string $target, array $context, array $details, int $now): void
    {
        Database::transaction($this->db,function (PDO $db) use ($adminId,$operation,$target,$context,$details,$now): void {
            Audit::admin($db,$adminId,$this->adminEmail($db,$adminId),$operation,$target,$context,'SUCCESS',$details,$now);
        });
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
