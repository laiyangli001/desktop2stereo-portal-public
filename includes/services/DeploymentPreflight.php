<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use Throwable;

/** Read-only deployment checks. It never changes the database or runtime directories. */
final class DeploymentPreflight
{
    public static function run(PDO $db, array $config, string $releaseDirectory): array
    {
        $checks=[];
        $add=static function(string $code,bool $ok,string $detail='')use(&$checks):void{
            $checks[]=['code'=>$code,'ok'=>$ok,'detail'=>$detail];
        };
        $add('php_version',PHP_VERSION_ID>=80400,PHP_VERSION);
        $missing=[];
        foreach(['PDO','pdo_mysql','openssl','sodium','mbstring','curl','fileinfo','intl'] as$extension){
            if(!extension_loaded($extension))$missing[]=$extension;
        }
        $add('php_extensions',$missing===[],implode(',',$missing));
        $release=(string)($config['release']??'');
        $add('release_sha',(bool)preg_match('/^[a-f0-9]{40}$/D',$release),$release);
        $clockRollback=(int)($config['order_clock_max_rollback']??-1);
        $add('order_clock_rollback_policy',$clockRollback>=0&&$clockRollback<=86400,(string)$clockRollback);
        $masterKey=(string)($config['master_key']??'');
        try{
            new SecretBox($masterKey);
            $add('master_key',true);
        }catch(Throwable){
            $add('master_key',false);
        }
        $appUrl=(string)($config['app_url']??'');
        $add('https_app_url',str_starts_with($appUrl,'https://')&&filter_var($appUrl,FILTER_VALIDATE_URL)!==false);
        $origins=array_values(array_filter(array_map('trim',explode(',',(string)($config['allowed_origins']??'')))));
        $badOrigins=array_filter($origins,static fn(string $origin):bool=>!str_starts_with($origin,'https://'));
        $add('https_origins',$origins!==[]&&$badOrigins===[],implode(',',$badOrigins));
        $proxy=Audit::proxyPolicy((string)($config['trusted_proxy_cidrs']??''));
        $proxyRequired=(bool)($config['trusted_proxy_required']??false);
        $add('trusted_proxy_cidrs',$proxy['invalid']===[]&&(!$proxyRequired||$proxy['entries']>0),
            $proxy['invalid']===[]?(string)$proxy['entries']:implode(',',$proxy['invalid']));
        $ntp=NtpProbe::parseServers((string)($config['ntp_servers']??''));
        $ntpRequired=(bool)($config['ntp_required']??false);
        $ntpServersOk=$ntp['invalid']===[]&&(!$ntpRequired||$ntp['endpoints']!==[]);
        $ntpServerDetail=$ntp['invalid']!==[]?implode(',',$ntp['invalid']):
            ($ntp['endpoints']===[]?($ntpRequired?'required_missing':'not_required'):(string)count($ntp['endpoints']));
        $add('ntp_servers',$ntpServersOk,$ntpServerDetail);
        $ntpMaxOffset=(int)($config['ntp_max_offset_ms']??1000);
        $ntpQuorum=(int)($config['ntp_quorum']??min(2,max(1,count($ntp['endpoints']))));
        $ntpTimeout=(int)($config['ntp_timeout_ms']??1200);
        $ntpPolicyOk=false;$ntpPolicyDetail='';
        try{
            NtpProbe::validatePolicy($ntp['endpoints'],$ntpMaxOffset,$ntpQuorum,$ntpTimeout,!$ntpRequired);
            $ntpPolicyOk=true;
            $ntpPolicyDetail=$ntp['endpoints']===[]?'disabled':
                'quorum='.$ntpQuorum.',max_offset_ms='.$ntpMaxOffset.',timeout_ms='.$ntpTimeout;
        }catch(Throwable){
            $ntpPolicyDetail='quorum='.$ntpQuorum.',max_offset_ms='.$ntpMaxOffset.',timeout_ms='.$ntpTimeout;
        }
        $add('ntp_policy',$ntpPolicyOk,$ntpPolicyDetail);
        $add('migrations',Health::ready($db,$releaseDirectory));
        $releaseRoot=self::absolutePath((string)($config['release_root']??D2S_ROOT));
        $manifestPath=self::absolutePath((string)($config['release_manifest']??$releaseRoot.DIRECTORY_SEPARATOR.'release-manifest.json'));
        $manifest=ReleaseManifest::verify($releaseRoot,$manifestPath,$release);
        $add('release_manifest',$manifest['ok'],$manifest['ok']?(string)$manifest['root_hash']:implode(',',$manifest['errors']));
        $database=$db->query('SELECT DATABASE()')->fetchColumn();
        $add('database_selected',is_string($database)&&$database!=='',(string)$database);
        $user=$db->query('SELECT CURRENT_USER()')->fetchColumn();
        $add('non_root_database_user',is_string($user)&&!str_starts_with(strtolower($user),'root@'),(string)$user);
        $adminCount=(int)$db->query("SELECT COUNT(*) FROM portal_admins WHERE status='active'")->fetchColumn();
        $add('active_admin',$adminCount>=1,(string)$adminCount);
        $public=realpath(D2S_ROOT.DIRECTORY_SEPARATOR.'public');
        foreach(['ticket_storage'=>'ticket_storage','manifest_storage'=>'manifest_storage']as$configKey=>$code){
            $path=self::absolutePath((string)($config[$configKey]??''));
            $resolved=realpath($path);
            $outside=$resolved!==false&&$public!==false&&!($resolved===$public||str_starts_with($resolved,$public.DIRECTORY_SEPARATOR));
            $add($code,$resolved!==false&&is_dir($resolved)&&$outside&&is_writable($resolved),$path);
        }
        $ticket=realpath(self::absolutePath((string)($config['ticket_storage']??'')));
        $manifest=realpath(self::absolutePath((string)($config['manifest_storage']??'')));
        $separate=$ticket!==false&&$manifest!==false&&
            !str_starts_with($manifest,$ticket.DIRECTORY_SEPARATOR)&&!str_starts_with($ticket,$manifest.DIRECTORY_SEPARATOR);
        $add('storage_separation',$separate);
        foreach(['task_lock_dir'=>'task_lock_storage','task_log_dir'=>'task_log_storage']as$configKey=>$code){
            $path=self::absolutePath((string)($config[$configKey]??''));
            $resolved=realpath($path);
            $outside=$resolved!==false&&$public!==false&&!($resolved===$public||str_starts_with($resolved,$public.DIRECTORY_SEPARATOR));
            $add($code,$resolved!==false&&is_dir($resolved)&&$outside&&is_writable($resolved),$path);
        }
        $taskLocks=realpath(self::absolutePath((string)($config['task_lock_dir']??'')));
        $taskLogs=realpath(self::absolutePath((string)($config['task_log_dir']??'')));
        $taskSeparate=$taskLocks!==false&&$taskLogs!==false&&
            !str_starts_with($taskLogs,$taskLocks.DIRECTORY_SEPARATOR)&&!str_starts_with($taskLocks,$taskLogs.DIRECTORY_SEPARATOR);
        $add('task_storage_separation',$taskSeparate);
        $cache=self::absolutePath((string)($config['release_cache']??''));
        $cacheDirectory=dirname($cache);
        $cacheResolved=realpath($cacheDirectory);
        $cacheOutside=$public!==false&&$cacheResolved!==false&&
            !($cacheResolved===$public||str_starts_with($cacheResolved,$public.DIRECTORY_SEPARATOR));
        $cacheReady=is_dir($cacheDirectory)&&is_writable($cacheDirectory)&&(!file_exists($cache)||is_readable($cache));
        $add('release_cache',$cacheReady&&$cacheOutside,$cache);
        $add('retention_database',(bool)($config['retention_configured']??false));
        $retentionDb=$config['retention_db']??null;
        $retentionConnected=$retentionDb instanceof PDO;
        $retentionUser='';
        if($retentionConnected){
            try{
                $retentionDb->query('SELECT 1')->fetchColumn();
                $retentionUser=(string)$retentionDb->query('SELECT CURRENT_USER()')->fetchColumn();
            }catch(Throwable){
                $retentionConnected=false;
            }
        }
        $add('retention_connection',$retentionConnected,$retentionUser);
        $retentionNonRoot=$retentionConnected&&!str_starts_with(strtolower($retentionUser),'root@');
        $add('retention_non_root',$retentionNonRoot,$retentionUser);
        $retentionPrivileges=false;
        if($retentionConnected){
            $grants=$retentionDb->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
            $hasGrant=static function(string $table,array $privileges)use($grants):bool{
                foreach($grants as$grant){
                    if(!is_string($grant)||!str_contains($grant,$table))continue;
                    $all=true;
                    foreach($privileges as$privilege)if(!preg_match('/\b'.$privilege.'\b/',$grant)){$all=false;break;}
                    if($all)return true;
                }
                return false;
            };
            $retentionPrivileges=$hasGrant('portal_support_tickets',['SELECT','DELETE'])&&
                $hasGrant('portal_admin_audit',['SELECT','INSERT']);
        }
        $add('retention_privileges',$retentionPrivileges);
        $backupDb=array_key_exists('backup_db',$config)?$config['backup_db']:$db;
        $backupConnected=$backupDb instanceof PDO;
        $backupUser='';
        if($backupConnected){
            try{
                $backupDb->query('SELECT 1')->fetchColumn();
                $backupUser=(string)$backupDb->query('SELECT CURRENT_USER()')->fetchColumn();
            }catch(Throwable){
                $backupConnected=false;
            }
        }
        $add('backup_connection',$backupConnected,$backupUser);
        $add('backup_non_root',$backupConnected&&!str_starts_with(strtolower($backupUser),'root@'),$backupUser);
        $backupRead=false;
        $backupDatabase='';
        if($backupConnected){
            $backupDatabase=(string)$backupDb->query('SELECT DATABASE()')->fetchColumn();
            $grants=$backupDb->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
            $hasRead=static function(string $table)use($grants,$backupDatabase):bool{
                foreach($grants as$grant){
                    if(!is_string($grant)||!preg_match('/\bSELECT\b/',$grant))continue;
                    if(str_contains($grant,$table)||
                        ($backupDatabase!==''&&str_contains($grant,'`'.$backupDatabase.'`.*')))return true;
                }
                return false;
            };
            $backupRead=$backupDatabase!==''&&$hasRead('portal_support_attachments')&&
                $hasRead('portal_settings')&&$hasRead('portal_migrations');
        }
        $add('backup_database',$backupDatabase!== '',$backupDatabase);
        $add('backup_read',$backupRead);
        $add('bootstrap_password_removed',!($config['bootstrap_password_present']??false));
        return [
            'ok'=>array_reduce($checks,static fn(bool $ok,array $check):bool=>$ok&&$check['ok'],true),
            'checked_at'=>(int)($config['checked_at']??time()),
            'release'=>$release,
            'checks'=>$checks,
        ];
    }

    private static function absolutePath(string $path): string
    {
        if(!str_starts_with($path,DIRECTORY_SEPARATOR)&&!preg_match('#^[A-Za-z]:[\\\\/]#D',$path)){
            $path=D2S_ROOT.DIRECTORY_SEPARATOR.$path;
        }
        return rtrim($path,DIRECTORY_SEPARATOR);
    }
}
