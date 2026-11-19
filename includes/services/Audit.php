<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final class Audit
{
    public const MINIMUM_RETENTION_DAYS = 366;

    public static function context(array $server): array
    {
        $remote = filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null;
        $trusted = self::trustedProxies();
        $proxied = $remote !== null && self::trustedAddress($remote, $trusted);
        $ip = $proxied ? self::forwardedAddress($server,$trusted) : $remote;
        $destination = filter_var($server['SERVER_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null;
        $port = static function (mixed $value): ?int {
            return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) ?: null;
        };
        return [
            'source_ip' => $ip,
            // A proxy TCP source port is not the user's source port and must remain NULL.
            'source_port' => $proxied ? null : $port($server['REMOTE_PORT'] ?? null),
            'destination_ip' => $destination,
            'destination_port' => $port($server['SERVER_PORT'] ?? null),
            'user_agent' => mb_strcut($server['HTTP_USER_AGENT'] ?? '', 0, 1024, 'UTF-8'),
            'request_path' => mb_strcut(parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', 0, 2048, 'UTF-8'),
        ];
    }

    public static function proxyPolicy(string $raw): array
    {
        $networks=[];$invalid=[];$entries=0;
        foreach(explode(',',$raw)as$entry){
            $entry=trim($entry);
            if($entry==='')continue;
            $entries++;
            [$address,$prefix]=str_contains($entry,'/')?explode('/',$entry,2)+[1,'']:[$entry,''];
            $packed=filter_var($address,FILTER_VALIDATE_IP)!==false?inet_pton($address):false;
            if($packed===false){
                $invalid[]=$entry;
                continue;
            }
            $maximum=strlen($packed)*8;
            $bits=$prefix===''?$maximum:filter_var($prefix,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$maximum]]);
            if($bits===false){
                $invalid[]=$entry;
                continue;
            }
            $networks[]=[$packed,(int)$bits];
        }
        return ['networks'=>$networks,'entries'=>$entries,'invalid'=>$invalid];
    }

    private static function trustedProxies(): array
    {
        return self::proxyPolicy(getenv('D2S_TRUSTED_PROXY_CIDRS') ?: '')['networks'];
    }

    private static function forwardedAddress(array $server, array $trusted): ?string
    {
        $eo = trim((string)($server['HTTP_EO_CONNECTING_IP'] ?? ''));
        if (filter_var($eo,FILTER_VALIDATE_IP)) {
            return $eo;
        }
        $chain = [];
        foreach (explode(',',(string)($server['HTTP_X_FORWARDED_FOR'] ?? '')) as $entry) {
            $candidate = trim($entry);
            if (filter_var($candidate,FILTER_VALIDATE_IP)) {
                $chain[] = $candidate;
            }
        }
        for ($index=count($chain)-1;$index>=0;$index--) {
            if (!self::trustedAddress($chain[$index],$trusted)) {
                return $chain[$index];
            }
        }
        return $chain[0] ?? null;
    }

    private static function trustedAddress(string $address, array $networks): bool
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($networks as [$network,$prefix]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $whole = intdiv($prefix,8);
            $bits = $prefix%8;
            if ($whole>0 && substr($packed,0,$whole)!==substr($network,0,$whole)) {
                continue;
            }
            if ($bits===0 || ((ord($packed[$whole]) ^ ord($network[$whole])) & (0xFF << (8-$bits))) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function user(PDO $db, ?int $userId, ?string $email, string $action, string $result, array $context, int $now): void
    {
        if (!in_array($action, ['REGISTER','LOGIN','LOGOUT','PURCHASE','DOWNLOAD','PASSWORD_RESET','PROFILE_UPDATE','WITHDRAWAL'], true) ||
            !in_array($result, ['SUCCESS','FAILURE','BLOCKED'], true)) {
            throw new Problem('audit_event_invalid');
        }
        $ip = $context['source_ip'] ?? null;
        $hash = hash('sha256', json_encode([$userId, $action, $now, $ip, $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $query = $db->prepare('INSERT INTO portal_user_events
            (user_id,email,action_type,event_time,source_ip,source_port,destination_ip,destination_port,
            user_agent,device_fingerprint,request_path,result_status,record_hash,hash_version)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
        $device = $context['device_fingerprint'] ?? null;
        if ($device !== null && (!is_string($device) || !preg_match('/^[a-f0-9]{64}$/D',$device))) {
            throw new Problem('device_hash_invalid');
        }
        // Browser actions leave the device hash null; validated client events retain it.
        $query->execute([
            $userId, $email, $action, $now, $ip, $context['source_port'] ?? null,
            $context['destination_ip'] ?? null, $context['destination_port'] ?? null,
            $context['user_agent'] ?? null, $device, $context['request_path'] ?? '/', $result, $hash,
        ]);
        if ($action === 'LOGIN') self::login($db, 'user', $userId, $result, $context, $now);
    }

    public static function admin(PDO $db, ?int $id, string $email, string $operation, ?string $target, array $context, string $result, array $details, int $now): void
    {
        $query = $db->prepare('INSERT INTO portal_admin_audit
            (operator_id,operator_email,operation_type,operation_time,target_resource,source_ip,result,details_json)
            VALUES (?,?,?,?,?,?,?,?)');
        $query->execute([$id, $email, $operation, $now, $target, $context['source_ip'] ?? null, $result,
            json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        if ($operation === 'LOGIN_ADMIN') self::login($db, 'admin', $id, $result, $context, $now);
    }

    private static function login(PDO $db, string $realm, ?int $id, string $result, array $context, int $now): void
    {
        $ip=filter_var($context['source_ip']??'',FILTER_VALIDATE_IP)?:null;
        $db->prepare('INSERT INTO portal_login_history(realm,user_id,admin_id,outcome,source_ip,user_agent,created_at)
            VALUES(?,?,?,?,?,?,?)')->execute([$realm,$realm==='user'?$id:null,$realm==='admin'?$id:null,$result,
                $ip,mb_strcut((string)($context['user_agent']??''),0,512,'UTF-8'),$now]);
        if($id!==null&&$result==='SUCCESS'){
            $table=$realm==='admin'?'portal_admins':'portal_users';
            // MySQL evaluates single-table assignments from left to right.
            $db->prepare("UPDATE $table SET
                last_login_ip=IF(last_login_at IS NULL OR last_login_at<=?,?,last_login_ip),
                last_login_at=IF(last_login_at IS NULL OR last_login_at<=?,?,last_login_at),
                login_count=login_count+1 WHERE id=?")->execute([$now,$ip,$now,$now,$id]);
        }
    }

    public static function email(PDO $db, int $userId): ?string
    {
        $query = $db->prepare("SELECT value FROM portal_contacts WHERE user_id=? AND kind='email' AND verified_at IS NOT NULL");
        $query->execute([$userId]);
        $value = $query->fetchColumn();
        return $value === false ? null : $value;
    }
}
