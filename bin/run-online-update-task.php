<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\GitHubUpdateSource;
use D2S\services\OnlineUpdate;
use D2S\services\OnlineUpdateTasks;
use D2S\services\ReleaseManifest;

$options=getopt('',['task-id:']);
$taskId=$options['task-id']??null;
if(!is_string($taskId)||!preg_match('/^[a-f0-9]{32}$/D',$taskId)){
    fwrite(STDERR,"update_install_task_invalid\n");
    exit(2);
}

$tasks=new OnlineUpdateTasks();
try{
    $task=$tasks->get($taskId);
    if(($task['status']??null)!=='queued')throw new RuntimeException('update_install_task_state_invalid');
    $release=(string)($task['release']??'');
    if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_install_task_invalid');
    $operation=$task['operation']??'install';
    if($operation==='download'){
        runGitHubDownload($taskId,$release,(string)($task['repository']??''),$tasks);
        echo "update_download_complete\n";
        return;
    }
    if($operation!=='install')throw new RuntimeException('update_install_task_invalid');

    $tasks->update($taskId,['status'=>'running','phase'=>'validating_current_release','percent'=>5]);
    $deploymentRoot=(string)(getenv('D2S_DEPLOYMENT_ROOT')?:'');
    if($deploymentRoot===''||!str_starts_with($deploymentRoot,DIRECTORY_SEPARATOR)){
        throw new RuntimeException('update_install_root_invalid');
    }
    $current=realpath($deploymentRoot.DIRECTORY_SEPARATOR.'current');
    if($current===false||!is_dir($current))throw new RuntimeException('update_install_current_invalid');
    $manifestPath=$current.DIRECTORY_SEPARATOR.'release-manifest.json';
    try{$manifestData=json_decode((string)file_get_contents($manifestPath),true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){throw new RuntimeException('update_install_current_invalid');}
    $currentRelease=is_array($manifestData)?(string)($manifestData['release']??''):'';
    if(!preg_match('/^[a-f0-9]{40}$/D',$currentRelease))throw new RuntimeException('update_install_current_invalid');
    $manifest=ReleaseManifest::verify($current,$manifestPath,$currentRelease);
    if(!$manifest['ok'])throw new RuntimeException('update_install_current_invalid');

    $backupRoot=(string)getenv('D2S_UPDATE_BACKUP_DIR');
    if($backupRoot===''||!str_starts_with($backupRoot,DIRECTORY_SEPARATOR)){
        throw new RuntimeException('update_install_backup_unavailable');
    }
    if(!is_dir($backupRoot)&&!mkdir($backupRoot,0750,true)&&!is_dir($backupRoot)){
        throw new RuntimeException('update_install_backup_unavailable');
    }
    if(is_link($backupRoot))throw new RuntimeException('update_install_backup_unavailable');
    $backupRoot=realpath($backupRoot);
    if($backupRoot===false)throw new RuntimeException('update_install_backup_unavailable');
    $backupDirectory=$backupRoot.DIRECTORY_SEPARATOR.'update-'.$currentRelease.'-'.gmdate('YmdHis').'-'.$taskId;
    putenv('D2S_BACKUP_RELEASE='.$currentRelease);
    $tasks->update($taskId,['phase'=>'creating_backup','percent'=>12]);
    $backupOutput=run([PHP_BINARY,D2S_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'backup.php',
        '--output='.$backupDirectory]);
    if($backupOutput['exit']!==0)throw new RuntimeException('update_install_backup_failed');

    $tasks->update($taskId,['phase'=>'installing_release','percent'=>25,'backup'=>$backupDirectory]);
    $command=[
        PHP_BINARY,D2S_ROOT.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'apply-staged-update.php',
        '--release='.$release,'--backup='.$backupDirectory,'--deployment-root='.$deploymentRoot,
        '--health-url='.(string)getenv('D2S_UPDATE_HEALTH_URL'),'--task-id='.$taskId,
    ];
    $result=run($command);
    if($result['exit']!==0){
        $error=extractError($result['stderr'],$result['stdout']);
        throw new RuntimeException($error);
    }
    $payload=json_decode($result['stdout'],true,16,JSON_THROW_ON_ERROR);
    if(($payload['success']??false)!==true)throw new RuntimeException('update_install_failed');
    $tasks->update($taskId,['status'=>'completed','phase'=>'complete','percent'=>100,'result'=>$payload['data']??[]]);
    echo "update_install_complete\n";
}catch(Throwable $error){
    $message=$error->getMessage();
    $safe=preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$message)?$message:'update_install_failed';
    try{$tasks->update($taskId,['status'=>'failed','phase'=>'failed','percent'=>100,'error'=>$safe]);}catch(Throwable){}
    fwrite(STDERR,$safe."\n");
    exit(1);
}

function run(array $command): array
{
    $pipes=[];
    $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process))return ['exit'=>127,'stdout'=>'','stderr'=>'update_process_start_failed'];
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1],1048576);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2],1048576);fclose($pipes[2]);
    return ['exit'=>proc_close($process),'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}

function runGitHubDownload(string $taskId,string $release,string $repository,OnlineUpdateTasks $tasks): void
{
    $source=new GitHubUpdateSource(new OnlineUpdate(D2S_ROOT),null,$repository);
    $started=microtime(true);$lastAt=0.0;$lastBytes=0;$lastPhase='';
    $tasks->update($taskId,['status'=>'running','phase'=>'downloading_source','percent'=>1,'download_bytes'=>0,
        'download_total_bytes'=>null,'download_speed_bytes_per_second'=>0]);
    $progress=static function(string $phase,int $bytes,?int $total)use($taskId,$tasks,$started,&$lastAt,&$lastBytes,&$lastPhase):void{
        $now=microtime(true);
        if($phase===$lastPhase&&$bytes-$lastBytes<524288&&$now-$lastAt<1.0)return;
        $percent=$phase==='staging_source'?95:($total!==null&&$total>0?min(89,5+(int)floor(($bytes/$total)*80)):null);
        $tasks->update($taskId,[
            'phase'=>$phase,'percent'=>$percent,'download_bytes'=>$bytes,'download_total_bytes'=>$total,
            'download_speed_bytes_per_second'=>(int)round($bytes/max(0.001,$now-$started)),
        ]);
        $lastAt=$now;$lastBytes=$bytes;$lastPhase=$phase;
    };
    $result=$source->downloadPinned($release,$progress);
    $tasks->update($taskId,[
        'status'=>'completed','phase'=>'complete','percent'=>100,'result'=>$result,
        'download_bytes'=>$result['artifact_size'],'download_total_bytes'=>$result['artifact_size'],
    ]);
}

function extractError(string $stderr,string $stdout): string
{
    foreach([$stderr,$stdout]as$text){
        $lines=array_reverse(preg_split('/\r?\n/',$text)?:[]);
        foreach($lines as$line){
            $decoded=json_decode($line,true);
            $error=is_array($decoded)?($decoded['error']??null):null;
            if(is_string($error)&&preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$error))return $error;
        }
    }
    return 'update_install_failed';
}
