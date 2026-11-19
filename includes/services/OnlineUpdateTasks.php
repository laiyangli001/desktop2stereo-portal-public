<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

final readonly class OnlineUpdateTasks
{
    private const UNIT_PREFIX='desktop2stereo-update-worker@';

    public function __construct(private string $root=D2S_ROOT,private ?string $directory=null) {}

    public function enabled(): bool
    {
        if(PHP_OS_FAMILY!=='Linux'||getenv('D2S_UPDATE_INSTALL_ENABLED')!=='1')return false;
        $deployment=(string)(getenv('D2S_DEPLOYMENT_ROOT')?:'');
        $backup=(string)(getenv('D2S_UPDATE_BACKUP_DIR')?:'');
        $tasks=(string)(getenv('D2S_UPDATE_TASK_DIR')?:'');
        $staging=(string)(getenv('D2S_UPDATE_STAGING_DIR')?:'');
        $candidates=(string)(getenv('D2S_UPDATE_CANDIDATE_DIR')?:'');
        $service=trim((string)(getenv('D2S_UPDATE_RESTART_SERVICE')?:''));
        if(!$this->absolute($deployment)||!$this->absolute($backup)||!$this->absolute($tasks)
            ||!$this->absolute($staging)||!$this->absolute($candidates)
            ||!preg_match('/^[A-Za-z0-9_.@:-]{1,128}\\.service$/D',$service)
            ||!$this->healthUrlConfigured((string)(getenv('D2S_UPDATE_HEALTH_URL')?:'')))return false;
        $root=realpath($deployment);
        $current=realpath($deployment.DIRECTORY_SEPARATOR.'current');
        $releases=realpath($deployment.DIRECTORY_SEPARATOR.'releases');
        if($root===false||$current===false||$releases===false
            ||!is_link($deployment.DIRECTORY_SEPARATOR.'current')
            ||!str_starts_with($current,rtrim($releases,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))return false;
        if(!is_executable('/usr/bin/sudo')||!is_executable('/usr/bin/systemctl')
            ||!is_executable('/usr/local/sbin/d2s-start-update-task'))return false;
        try{$this->taskDirectory();}catch(RuntimeException){return false;}
        foreach(['/etc/systemd/system/desktop2stereo-update-worker@.service',
            '/usr/lib/systemd/system/desktop2stereo-update-worker@.service',
            '/lib/systemd/system/desktop2stereo-update-worker@.service']as$unit){
            if(is_file($unit))return true;
        }
        return false;
    }

    public function start(string $release): array
    {
        if(!$this->enabled())throw new RuntimeException('update_install_unavailable');
        if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_prepare_input_invalid');
        (new OnlineUpdate($this->root))->preparedCandidate($release);

        $taskId=bin2hex(random_bytes(16));
        $task=[
            'format'=>'d2s-update-task-v1','task_id'=>$taskId,'release'=>$release,'operation'=>'install',
            'status'=>'queued','phase'=>'queued','percent'=>0,'created_at'=>time(),'updated_at'=>time(),
        ];
        return $this->launch($taskId,$task);
    }

    public function startGitHubDownload(string $release,string $repository,int $adminId): array
    {
        if(!$this->enabled())throw new RuntimeException('update_install_unavailable');
        if(!preg_match('/^[a-f0-9]{40}$/D',$release)||$adminId<1)throw new RuntimeException('update_github_selection_changed');
        $source=new GitHubUpdateSource(new OnlineUpdate($this->root),null,$repository);
        $repository=$source->repository();
        if($this->hasActiveGitHubDownload())throw new RuntimeException('update_download_in_progress');

        $taskId=bin2hex(random_bytes(16));
        $task=[
            'format'=>'d2s-update-task-v1','task_id'=>$taskId,'release'=>$release,'operation'=>'download',
            'repository'=>$repository,'admin_id'=>$adminId,
            'status'=>'queued','phase'=>'queued','percent'=>0,'created_at'=>time(),'updated_at'=>time(),
        ];
        return $this->launch($taskId,$task);
    }

    private function launch(string $taskId,array $task): array
    {
        $this->write($taskId,$task);
        $command=['/usr/bin/sudo','-n','/usr/local/sbin/d2s-start-update-task',$taskId];
        $pipes=[];
        $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){
            $this->update($taskId,['status'=>'failed','phase'=>'failed','percent'=>100,'error'=>'update_install_launcher_failed']);
            throw new RuntimeException('update_install_launcher_failed');
        }
        fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1],2048);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2],2048);fclose($pipes[2]);
        $exit=proc_close($process);
        if($exit!==0){
            $this->update($taskId,['status'=>'failed','phase'=>'failed','percent'=>100,
                'error'=>'update_install_launcher_failed','launcher_output'=>trim((string)($stderr?:$stdout))]);
            throw new RuntimeException('update_install_launcher_failed');
        }
        return $this->get($taskId);
    }

    private function hasActiveGitHubDownload(): bool
    {
        $files=glob($this->taskDirectory().DIRECTORY_SEPARATOR.'*.json')?:[];
        foreach($files as$file){
            if(is_link($file)||!preg_match('/^[a-f0-9]{32}\.json$/D',basename($file)))continue;
            try{$task=$this->get(substr(basename($file),0,32));}catch(RuntimeException){continue;}
            if(($task['operation']??null)==='download'&&in_array($task['status']??null,['queued','running'],true))return true;
        }
        return false;
    }

    public function latest(): ?array
    {
        if(!$this->enabled())return null;
        $files=glob($this->taskDirectory().DIRECTORY_SEPARATOR.'*.json')?:[];
        usort($files,static fn(string $a,string $b):int=>(filemtime($b)?:0)<=>(filemtime($a)?:0));
        foreach(array_slice($files,0,20)as$file){
            if(is_link($file)||!preg_match('/^[a-f0-9]{32}\.json$/D',basename($file)))continue;
            try{$task=$this->get(substr(basename($file),0,32));}catch(RuntimeException){continue;}
            if(($task['operation']??'install')==='download')continue;
            return $task;
        }
        return null;
    }

    public function get(string $taskId): array
    {
        $path=$this->path($taskId);
        if(!is_file($path)||is_link($path))throw new RuntimeException('update_install_task_not_found');
        $size=filesize($path);
        if(!is_int($size)||$size>131072)throw new RuntimeException('update_install_task_invalid');
        try{$task=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new RuntimeException('update_install_task_invalid');}
        if(!is_array($task)||($task['format']??null)!=='d2s-update-task-v1'||($task['task_id']??null)!==$taskId){
            throw new RuntimeException('update_install_task_invalid');
        }
        return $task;
    }

    public function update(string $taskId,array $changes): array
    {
        $task=array_replace($this->get($taskId),$changes,['updated_at'=>time()]);
        $this->write($taskId,$task);
        return $task;
    }

    private function write(string $taskId,array $task): void
    {
        $path=$this->path($taskId);
        $directory=dirname($path);
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory)){
            throw new RuntimeException('update_install_task_storage_unavailable');
        }
        if(is_link($directory)||is_link($path))throw new RuntimeException('update_install_task_storage_unavailable');
        $encoded=json_encode($task,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $temporary=$path.'.tmp-'.bin2hex(random_bytes(5));
        if(file_put_contents($temporary,$encoded,LOCK_EX)!==strlen($encoded)){
            @unlink($temporary);
            throw new RuntimeException('update_install_task_storage_unavailable');
        }
        @chmod($temporary,0660);
        if(DIRECTORY_SEPARATOR==='\\'&&file_exists($path))@unlink($path);
        if(!rename($temporary,$path)){
            @unlink($temporary);
            throw new RuntimeException('update_install_task_storage_unavailable');
        }
    }

    private function path(string $taskId): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$taskId))throw new RuntimeException('update_install_task_invalid');
        return $this->taskDirectory().DIRECTORY_SEPARATOR.$taskId.'.json';
    }

    private function taskDirectory(): string
    {
        $configured=$this->directory??(getenv('D2S_UPDATE_TASK_DIR')?:'');
        if(!$this->absolute($configured))throw new RuntimeException('update_install_task_storage_unavailable');
        if(!is_dir($configured)&&!mkdir($configured,0770,true)&&!is_dir($configured)){
            throw new RuntimeException('update_install_task_storage_unavailable');
        }
        $real=realpath($configured);
        $public=realpath($this->root.DIRECTORY_SEPARATOR.'public');
        if($real===false||is_link($configured)||$public===false
            ||str_starts_with(strtolower($real.DIRECTORY_SEPARATOR),strtolower(rtrim($public,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))){
            throw new RuntimeException('update_install_task_storage_unavailable');
        }
        return $real;
    }

    private function healthUrlConfigured(string $url): bool
    {
        $parts=parse_url($url);
        return is_array($parts)&&($parts['scheme']??null)==='https'&&is_string($parts['host']??null)
            &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['fragment'])
            &&(!isset($parts['port'])||(int)$parts['port']===443);
    }

    private function absolute(string $path): bool
    {
        if(DIRECTORY_SEPARATOR==='\\')return preg_match('/^[A-Za-z]:[\\\\\/]/D',$path)===1;
        return str_starts_with($path,'/');
    }
}
