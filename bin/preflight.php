<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\DeploymentPreflight;

$retentionValues=[];
foreach(['DSN','USER','PASSWORD']as$name){
    $value=getenv('D2S_RETENTION_DB_'.$name);
    $retentionValues[]=is_string($value)&&$value!=='';
}
$retentionDb=null;
if($retentionValues===[true,true,true]){
    try{$retentionDb=Database::fromEnvironment('D2S_RETENTION_DB_');}catch(Throwable){$retentionDb=null;}
}
$bootstrap=getenv('D2S_BOOTSTRAP_PASSWORD');
$allowedOrigins=getenv('D2S_ALLOWED_ORIGINS') ?: (getenv('D2S_APP_URL') ?: '');
$intEnvironment=static function(string $name,int $default):int{
    $value=getenv($name);
    return is_string($value)&&$value!==''?(int)$value:$default;
};
try{
    $db=Database::fromEnvironment();
    $config=[
        'release'=>getenv('D2S_RELEASE') ?: '',
        'release_root'=>D2S_ROOT,
        'release_manifest'=>getenv('D2S_RELEASE_MANIFEST') ?: D2S_ROOT.'/release-manifest.json',
        'order_clock_max_rollback'=>$intEnvironment('D2S_ORDER_CLOCK_MAX_ROLLBACK_SECONDS',5),
        'master_key'=>getenv('D2S_MASTER_KEY') ?: '',
        'app_url'=>getenv('D2S_APP_URL') ?: '',
        'allowed_origins'=>$allowedOrigins,
        'trusted_proxy_cidrs'=>getenv('D2S_TRUSTED_PROXY_CIDRS') ?: '',
        'trusted_proxy_required'=>getenv('D2S_TRUSTED_PROXY_REQUIRED') === 'true',
        'ntp_servers'=>getenv('D2S_NTP_SERVERS') ?: '',
        'ntp_max_offset_ms'=>$intEnvironment('D2S_NTP_MAX_OFFSET_MS',1000),
        'ntp_timeout_ms'=>$intEnvironment('D2S_NTP_TIMEOUT_MS',1200),
        'ntp_required'=>getenv('D2S_NTP_REQUIRED') === 'true',
        'ticket_storage'=>getenv('D2S_TICKET_STORAGE') ?: D2S_ROOT.'/storage/tickets',
        'manifest_storage'=>getenv('D2S_RETENTION_MANIFEST_DIR') ?: D2S_ROOT.'/storage/retention-manifests',
        'release_cache'=>getenv('D2S_RELEASE_CACHE') ?: D2S_ROOT.'/storage/cache/github-release.json',
        'task_lock_dir'=>getenv('D2S_TASK_LOCK_DIR') ?: D2S_ROOT.'/storage/locks',
        'task_log_dir'=>getenv('D2S_TASK_LOG_DIR') ?: D2S_ROOT.'/storage/logs/tasks',
        'retention_configured'=>$retentionValues===[true,true,true],'retention_db'=>$retentionDb,
        'bootstrap_password_present'=>is_string($bootstrap)&&$bootstrap!=='',
        'checked_at'=>time(),
    ];
    $ntpQuorum=getenv('D2S_NTP_QUORUM');
    if(is_string($ntpQuorum)&&$ntpQuorum!=='')$config['ntp_quorum']=(int)$ntpQuorum;
    $backupDsn=getenv('D2S_BACKUP_DB_DSN');
    if(is_string($backupDsn)&&$backupDsn!==''){
        try{
            $config['backup_db']=Database::fromEnvironment('D2S_BACKUP_DB_');
        }catch(Throwable){
            $config['backup_db']=null;
        }
    }
    $result=DeploymentPreflight::run($db,$config,D2S_ROOT.'/database/migrations');
    fwrite(STDOUT,json_encode(['success'=>$result['ok'],'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
    exit($result['ok']?0:1);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>'preflight_failed','detail'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
