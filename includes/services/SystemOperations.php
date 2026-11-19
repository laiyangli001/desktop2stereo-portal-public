<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class SystemOperations
{
    public function __construct(
        private PDO $db,
        private string $root=D2S_ROOT,
        private ?\Closure $updateFetcher=null,
        private ?\Closure $githubRequester=null,
    ) {}

    public function maintenance(int $adminId,?int $now=null): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $extensions=[];
        foreach(['pdo_mysql','openssl','sodium','mbstring','curl','gd','fileinfo','intl']as$name)$extensions[]=['name'=>$name,'ready'=>extension_loaded($name)];
        $free=is_dir($this->root.'/storage')?disk_free_space($this->root.'/storage'):false;
        $tools=[];
        foreach(['backup'=>'bin/backup.php','verify-backup'=>'bin/verify-backup.php','preflight'=>'bin/preflight.php',
            'prune-support'=>'bin/prune-support.php','prune-login-history'=>'bin/prune-login-history.php']as$name=>$script){
            $tools[]=['name'=>$name,'script'=>$script,'available'=>is_file($this->root.'/'.$script)];
        }
        return ['environment'=>[
            'php_version'=>PHP_VERSION,'database_version'=>$this->db->query('SELECT VERSION()')->fetchColumn(),
            'tables'=>(int)$this->db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn(),
            'migrations'=>(int)$this->db->query('SELECT COUNT(*) FROM portal_migrations')->fetchColumn(),
            'storage_writable'=>is_writable($this->root.'/storage'),'storage_free_bytes'=>$free===false?null:(int)$free,
            'geoip'=>(new IpCountry())->metadata(),
        ],'extensions'=>$extensions,'tools'=>$tools,
            'data'=>(new DataMaintenance($this->db,$this->root))->inspect($now??time())];
    }

    public function upgrade(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $rows=$this->db->query('SELECT version,checksum,status,applied_at FROM portal_migrations ORDER BY version')->fetchAll();
        $migrations=self::migrationStatus($rows,$this->root.'/database/migrations');
        $configured=getenv('D2S_RELEASE')?:null;
        $head=ReleaseManifest::gitHead($this->root);
        $manifestPath=getenv('D2S_RELEASE_MANIFEST')?:$this->root.'/release-manifest.json';
        $manifestRelease=$this->manifestRelease($manifestPath);
        $release=$manifestRelease?:($configured?:$head);
        $releaseSource=$manifestRelease?'release_manifest':($configured?'environment':($head?'git_baseline':'unknown'));
        $manifest=['state'=>'unconfigured','errors'=>[],'file_count'=>null,'root_hash'=>null];
        if(is_file($manifestPath)){
            if(!is_string($release)||!preg_match('/^[a-f0-9]{40}$/D',$release)){
                $manifest['state']='invalid';$manifest['errors']=['release_identity_required'];
            }else{
                $verified=ReleaseManifest::verify($this->root,$manifestPath,$release);
                $manifest=['state'=>$verified['ok']?'verified':'invalid','errors'=>$verified['errors'],
                    'file_count'=>$verified['file_count'],'root_hash'=>$verified['root_hash']];
            }
        }elseif(getenv('D2S_RELEASE_MANIFEST')){
            $manifest['state']='missing';$manifest['errors']=['release_manifest_unreadable'];
        }
        try{$githubRepository=$this->github()->repository();}
        catch(\RuntimeException){$githubRepository=null;}
        return ['github_repository'=>$githubRepository,'release'=>$release,'git_baseline'=>$head,'release_source'=>$releaseSource,
            'manifest'=>$manifest,'migrations'=>$migrations,'online_update_enabled'=>true,
            'online_update_install_enabled'=>(new OnlineUpdateTasks($this->root))->enabled(),
            'online_update_task'=>(new OnlineUpdateTasks($this->root))->latest(),
            'online_update'=>(new OnlineUpdate($this->root))->status(),
            'tools'=>['preflight'=>is_file($this->root.'/bin/preflight.php'),'migrate'=>is_file($this->root.'/bin/migrate.php'),
                'verify_manifest'=>is_file($this->root.'/bin/verify-release-manifest.php'),
                'verify_staged_update'=>is_file($this->root.'/bin/verify-staged-update.php'),
                'prepare_staged_update'=>is_file($this->root.'/bin/prepare-staged-update.php'),
                'apply_staged_update'=>is_file($this->root.'/bin/apply-staged-update.php'),
                'run_release_acceptance'=>is_file($this->root.'/bin/run-release-acceptance.php')]];
    }

    public function stageOnlineUpdate(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        try{$result=(new OnlineUpdate($this->root,null,null,null,$this->updateFetcher))->stage($input);}
        catch(\RuntimeException $error){throw new \D2S\domain\Problem($error->getMessage(),400);}
        return $this->auditStagedUpdate($adminId,$result,$context,$now);
    }

    public function checkGitHubUpdate(int $adminId,mixed $address=null): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        try{return $this->github($address)->check($this->currentRelease());}
        catch(\RuntimeException $error){throw new \D2S\domain\Problem($error->getMessage(),400);}
    }

    public function downloadGitHubUpdate(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        try{$result=$this->github($input['address']??null)->download($this->currentRelease(),$input);}
        catch(\RuntimeException $error){throw new \D2S\domain\Problem($error->getMessage(),400);}
        return $this->auditStagedUpdate($adminId,$result,$context,$now);
    }

    private function github(mixed $address=null): GitHubUpdateSource
    {
        return new GitHubUpdateSource(new OnlineUpdate($this->root),$this->githubRequester,$address);
    }

    private function currentRelease(): ?string
    {
        $manifestPath=getenv('D2S_RELEASE_MANIFEST')?:$this->root.'/release-manifest.json';
        return $this->manifestRelease($manifestPath)?:((getenv('D2S_RELEASE')?:null)?:ReleaseManifest::gitHead($this->root));
    }

    private function manifestRelease(string $path): ?string
    {
        if(!is_file($path)||is_link($path))return null;
        try{$manifest=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){return null;}
        $release=is_array($manifest)?($manifest['release']??null):null;
        return is_string($release)&&preg_match('/^[a-f0-9]{40}$/D',$release)?$release:null;
    }

    private function auditStagedUpdate(int $adminId,array $result,array $context,int $now): array
    {
        $email=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$email->execute([$adminId]);
        $adminEmail=$email->fetchColumn();if($adminEmail===false)throw new Problem('administrator_inactive',403);
        Audit::admin($this->db,$adminId,(string)$adminEmail,'STAGE_ONLINE_UPDATE','release',$context,'SUCCESS',[
            'release'=>$result['release'],'artifact_sha256'=>$result['artifact_sha256'],
            'artifact_size'=>$result['artifact_size'],'manifest_root_hash'=>$result['manifest_root_hash'],
            'migration_count'=>count($result['migrations']),'reused'=>$result['reused'],
        ],$now);
        return $result;
    }

    public function prepareOnlineUpdate(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $release=$input['release']??null;
        if(!is_string($release)||!preg_match('/^[a-f0-9]{40}$/D',$release)){
            throw new \D2S\domain\Problem('update_prepare_input_invalid',400);
        }
        try{$result=(new OnlineUpdate($this->root))->prepareRelease($release,$now);}
        catch(\RuntimeException $error){throw new \D2S\domain\Problem($error->getMessage(),400);}
        $email=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$email->execute([$adminId]);
        $adminEmail=$email->fetchColumn();if($adminEmail===false)throw new Problem('administrator_inactive',403);
        Audit::admin($this->db,$adminId,(string)$adminEmail,'PREPARE_ONLINE_UPDATE','release',$context,'SUCCESS',[
            'release'=>$result['release'],'manifest_root_hash'=>$result['root_hash'],
            'file_count'=>$result['file_count'],'migration_count'=>$result['migration_count'],
            'php_files_linted'=>$result['php_files_linted'],'reused'=>$result['reused'],
        ],$now);
        return $result;
    }

    public function startOnlineUpdateInstall(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $release=$input['release']??null;
        if(!is_string($release)||!preg_match('/^[a-f0-9]{40}$/D',$release)){
            throw new Problem('update_prepare_input_invalid',400);
        }
        try{$task=(new OnlineUpdateTasks($this->root))->start($release);}
        catch(\RuntimeException $error){throw new Problem($error->getMessage(),400);}
        $email=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $email->execute([$adminId]);$adminEmail=$email->fetchColumn();
        if($adminEmail===false)throw new Problem('administrator_inactive',403);
        Audit::admin($this->db,$adminId,(string)$adminEmail,'START_ONLINE_UPDATE','release',$context,'SUCCESS',[
            'release'=>$release,'task_id'=>$task['task_id'],
        ],$now);
        return $task;
    }

    public function onlineUpdateProgress(int $adminId,string $taskId): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        try{return (new OnlineUpdateTasks($this->root))->get($taskId);}
        catch(\RuntimeException $error){throw new Problem($error->getMessage(),404);}
    }

    /** Inspect migration files without evaluating their PHP or executing any SQL. */
    public static function migrationStatus(array $rows,string $directory): array
    {
        $known=array_column($rows,null,'version');$items=[];$pending=0;$blocked=0;
        $files=glob(rtrim($directory,'/\\').'/*.php')?:[];sort($files,SORT_STRING);
        foreach($files as$file){
            $version=basename($file);$row=$known[$version]??null;unset($known[$version]);
            $hash=!is_link($file)&&is_readable($file)?hash_file('sha256',$file):false;
            if($hash===false)$state='unreadable';
            elseif($row===null)$state='pending';
            elseif(!hash_equals((string)$row['checksum'],$hash))$state='checksum_mismatch';
            elseif($row['status']!=='applied')$state='incomplete';
            else$state='applied';
            if($state==='pending')$pending++;
            elseif($state!=='applied')$blocked++;
            $items[]=['version'=>$version,'state'=>$state,'applied_at'=>$row['applied_at']??null];
        }
        foreach($known as$row){
            $blocked++;$items[]=['version'=>$row['version'],'state'=>'missing_code','applied_at'=>$row['applied_at']??null];
        }
        if($files===[])$blocked++;
        return ['ready'=>$pending===0&&$blocked===0,'pending'=>$pending,'blocked'=>$blocked,'items'=>$items];
    }
}
