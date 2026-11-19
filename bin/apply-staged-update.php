<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\Migrator;
use D2S\services\OnlineUpdate;
use D2S\services\ReleaseInstaller;
use D2S\services\OnlineUpdateTasks;

$options=getopt('',['release:','backup:','deployment-root:','health-url:','task-id:','rollback']);
$absolute=static function(string $path):bool{
    return $path!==''&&!str_contains($path,"\0")
        &&(str_starts_with($path,DIRECTORY_SEPARATOR)||preg_match('/^[A-Za-z]:[\\\\\/]/D',$path));
};
if(array_key_exists('rollback',$options)){
    $root=(string)($options['deployment-root']??getenv('D2S_DEPLOYMENT_ROOT')?:'');
    if(!$absolute($root)){
        fwrite(STDERR,"usage: php bin/apply-staged-update.php --rollback --deployment-root=<absolute-release-root>\n");
        exit(2);
    }
    try{
        $installer=new ReleaseInstaller(
            OnlineUpdate::fromEnvironment(),
            $root,
            static fn():array=>[],
            static fn():array=>['ok'=>true],
        );
        $result=$installer->rollback(time());
        echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    }catch(Throwable $error){
        fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
        exit(1);
    }
    exit(0);
}

$release=(string)($options['release']??'');
$backup=(string)($options['backup']??'');
$root=(string)($options['deployment-root']??getenv('D2S_DEPLOYMENT_ROOT')?:'');
$healthUrl=(string)($options['health-url']??getenv('D2S_UPDATE_HEALTH_URL')?:'');
if(!preg_match('/^[a-f0-9]{40}$/D',$release)||!$absolute($backup)||!$absolute($root)||$healthUrl===''){
    fwrite(STDERR,"usage: php bin/apply-staged-update.php --release=<40-hex-sha> --backup=<absolute-private-backup-set> --deployment-root=<absolute-release-root> --health-url=<https-status-url>\n");
    exit(2);
}

$checkHealth=static function(string $rootPath,string $expectedRelease)use($healthUrl): array{
    $parts=parse_url($healthUrl);
    if(!is_array($parts)||($parts['scheme']??null)!=='https'||!is_string($parts['host']??null)
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
        ||(isset($parts['port'])&&(int)$parts['port']!==443)){
        throw new RuntimeException('update_health_url_invalid');
    }
    $body='';
    $curl=curl_init($healthUrl);
    if($curl===false)throw new RuntimeException('update_health_unavailable');
    $options=[
        CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'Desktop2Stereo-Portal-Installer',
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
            if(strlen($body)+strlen($chunk)>1048576)return 0;
            $body.=$chunk;return strlen($chunk);
        },
    ];
    $ca=getenv('D2S_CA_BUNDLE');
    if(is_string($ca)&&$ca!=='')$options[CURLOPT_CAINFO]=$ca;
    curl_setopt_array($curl,$options);
    $ok=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    $errno=curl_errno($curl);
    curl_close($curl);
    if($ok===false||$errno!==0||$status!==200)throw new RuntimeException('update_health_unavailable');
    try{$payload=json_decode($body,true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){throw new RuntimeException('update_health_invalid');}
    $data=$payload['data']??null;
    if(($payload['success']??false)!==true||!is_array($data)
        ||($data['version']??null)!==$expectedRelease||($data['database_ready']??false)!==true
        ||($data['release_manifest_ready']??false)!==true){
        throw new RuntimeException('update_health_failed');
    }
    return ['ok'=>true,'release'=>$expectedRelease,'database_ready'=>true,
        'release_root_hash'=>$data['release_root_hash']??null];
};

$restartService=static function():void{
    $service=trim((string)getenv('D2S_UPDATE_RESTART_SERVICE'));
    if(!preg_match('/^[A-Za-z0-9_.@:-]{1,128}\.service$/D',$service))throw new RuntimeException('update_restart_not_configured');
    $pipes=[];
    $process=@proc_open(['/usr/bin/sudo','-n','/usr/bin/systemctl','restart',$service],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))throw new RuntimeException('update_service_restart_failed');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1],2048);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2],2048);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException('update_service_restart_failed:'.trim((string)($stderr?:$stdout)));
};
$healthAfterRestart=static function(string $currentRoot,string $expectedRelease,int $now)use($restartService,$checkHealth):array{
    $restartService();
    return $checkHealth($currentRoot,$expectedRelease);
};
$progress=null;
$taskId=$options['task-id']??null;
if($taskId!==null){
    if(!is_string($taskId)||!preg_match('/^[a-f0-9]{32}$/D',$taskId))throw new RuntimeException('update_install_task_invalid');
    $tasks=new OnlineUpdateTasks();
    $progress=static function(array $state)use($tasks,$taskId):void{
        $tasks->update($taskId,array_replace($state,['status'=>($state['phase']??null)==='installed'?'completed':'running']));
    };
}

try{
    $installer=new ReleaseInstaller(
        OnlineUpdate::fromEnvironment(),
        $root,
        static function(string $candidateRoot,string $expectedRelease,int $now): array{
            $pdo=Database::fromEnvironment('D2S_MIGRATION_DB_');
            return (new Migrator($pdo,$candidateRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'))->run();
        },
        static fn(string $currentRoot,string $expectedRelease,int $now):array=>$healthAfterRestart($currentRoot,$expectedRelease,$now),
        $progress,
    );
    $result=$installer->install($release,$backup,time());
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
