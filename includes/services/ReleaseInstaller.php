<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Installs a prepared release inside an explicit deployment root using directory swaps. */
final class ReleaseInstaller
{
    public function __construct(
        private OnlineUpdate $updates,
        private string $deploymentRoot,
        private Closure $migrate,
        private Closure $health,
        private ?Closure $progress=null,
    ) {}

    public function install(string $release,string $backupDirectory,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_install_release_invalid');
        $this->report('validating',5,$release);
        $root=$this->root();
        $current=$root.DIRECTORY_SEPARATOR.'current';
        if(!is_dir($current))throw new RuntimeException('update_install_current_invalid');
        if(is_link($current))return $this->installSymlinkRelease($root,$current,$release,$backupDirectory,$now);
        $currentRelease=$this->currentRelease($current);
        $backup=BackupSetVerifier::verify($backupDirectory,$currentRelease);
        if(($backup['product_images_included']??false)!==true){
            throw new RuntimeException('update_install_backup_images_required');
        }
        $candidate=$this->updates->preparedCandidate($release);
        foreach(['composer.lock','vendor/autoload.php']as$dependencyFile){
            $path=(string)$candidate['root'].DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$dependencyFile);
            if(!is_file($path)||is_link($path))throw new RuntimeException('update_install_dependencies_invalid');
        }
        $transition=gmdate('YmdHis',$now).'-'.bin2hex(random_bytes(5));
        $next=$root.DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.$transition;
        $previous=$root.DIRECTORY_SEPARATOR.'rollback'.DIRECTORY_SEPARATOR.$currentRelease.'-'.$transition;
        $failed=$root.DIRECTORY_SEPARATOR.'failed'.DIRECTORY_SEPARATOR.$release.'-'.$transition;
        $applied=[];
        $codeSwitched=false;
        $lock=$this->lock();
        try{
            $this->report('copying_release',25,$release);
            $this->copyTree((string)$candidate['root'],$next);
            $verified=ReleaseManifest::verify($next,$next.DIRECTORY_SEPARATOR.'release-manifest.json',$release);
            if(!$verified['ok']||!hash_equals((string)$candidate['manifest_root_hash'],$verified['root_hash'])){
                throw new RuntimeException('update_install_candidate_invalid');
            }
            $this->report('applying_migrations',50,$release);
            $migrationResult=($this->migrate)($next,$release,$now);
            if(!is_array($migrationResult))throw new RuntimeException('update_install_migration_invalid');
            foreach($migrationResult as$migration){
                if(!is_string($migration)||!preg_match('/^[0-9]{4}_[A-Za-z0-9_]+\.php$/D',$migration)){
                    throw new RuntimeException('update_install_migration_invalid');
                }
            }
            $applied=$migrationResult;
            $state=[
                'format'=>'d2s-release-install-v1','status'=>'switching','release'=>$release,
                'previous_release'=>$currentRelease,'previous_path'=>$previous,'transition'=>$transition,
                'backup'=>$backup,'applied_migrations'=>$applied,'created_at'=>$now,
                'database_restore_required'=>$applied!==[],
            ];
            $this->writeJson($this->statePath(),$state);
            $this->ensureParent($previous);
            if(!rename($current,$previous))throw new RuntimeException('update_install_previous_failed');
            $codeSwitched=true;
            if(!rename($next,$current)){
                if(!rename($previous,$current))throw new RuntimeException('update_install_rollback_failed');
                throw new RuntimeException('update_install_switch_failed');
            }
            $this->report('restarting_service',85,$release);
            $health=($this->health)($current,$release,$now);
            $healthy=$health===true||(is_array($health)&&($health['ok']??false)===true);
            if(!$healthy){
                $this->restorePrevious($current,$previous,$failed);
                $codeSwitched=false;
                $state['status']='health_failed_code_rolled_back';
                $state['failed_path']=$failed;
                $this->writeJson($this->statePath(),$state);
                $suffix=$applied===[]?'':'database_restore_required';
                throw new RuntimeException('update_install_health_failed'.($suffix===''?'':':'.$suffix));
            }
            $state['status']='installed';
            $state['installed_at']=$now;
            $this->report('installed',100,$release);
            $this->writeJson($this->statePath(),$state);
            return [
                'status'=>'installed','release'=>$release,'previous_release'=>$currentRelease,
                'previous_path'=>$previous,'backup'=>$backup,'applied_migrations'=>$applied,
                'database_restore_required'=>$applied!==[],
            ];
        }catch(Throwable $error){
            $this->report('failed',100,$release,$error->getMessage());
            if(is_dir($next)||is_link($next))$this->removeTree($next);
            if($codeSwitched&&!is_dir($current)&&is_dir($previous)&&!rename($previous,$current)){
                throw new RuntimeException('update_install_rollback_failed',0,$error);
            }
            throw $error;
        }finally{
            flock($lock,LOCK_UN);fclose($lock);
        }
    }

    private function installSymlinkRelease(string $root,string $current,string $release,string $backupDirectory,int $now): array
    {
        $currentTarget=$this->managedCurrentTarget($root,$current);
        $currentRelease=$this->currentRelease($currentTarget);
        $backup=BackupSetVerifier::verify($backupDirectory,$currentRelease);
        if(($backup['product_images_included']??false)!==true)throw new RuntimeException('update_install_backup_images_required');
        $candidate=$this->updates->preparedCandidate($release);
        foreach(['composer.lock','vendor/autoload.php']as$dependencyFile){
            $path=(string)$candidate['root'].DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$dependencyFile);
            if(!is_file($path)||is_link($path))throw new RuntimeException('update_install_dependencies_invalid');
        }

        $releases=$root.DIRECTORY_SEPARATOR.'releases';
        if(!is_dir($releases)&&!mkdir($releases,0750,true)&&!is_dir($releases)){
            throw new RuntimeException('update_install_directory_failed');
        }
        if(is_link($releases))throw new RuntimeException('update_install_root_invalid');
        $releases=realpath($releases);
        if($releases===false)throw new RuntimeException('update_install_root_invalid');
        $transition=gmdate('YmdHis',$now).'-'.bin2hex(random_bytes(5));
        $temporary=$releases.DIRECTORY_SEPARATOR.'.staging-'.$release.'-'.$transition;
        $destination=$releases.DIRECTORY_SEPARATOR.$release;
        $failed=$root.DIRECTORY_SEPARATOR.'failed'.DIRECTORY_SEPARATOR.$release.'-'.$transition;
        if(file_exists($destination)||is_link($destination))throw new RuntimeException('update_candidate_exists');

        $applied=[];$switched=false;$lock=$this->lock();
        try{
            if($this->managedCurrentTarget($root,$current)!==$currentTarget){
                throw new RuntimeException('update_install_current_changed');
            }
            $this->report('copying_release',25,$release);
            $this->copyTree((string)$candidate['root'],$temporary);
            $verified=ReleaseManifest::verify($temporary,$temporary.DIRECTORY_SEPARATOR.'release-manifest.json',$release);
            if(!$verified['ok']||!hash_equals((string)$candidate['manifest_root_hash'],$verified['root_hash'])){
                throw new RuntimeException('update_install_candidate_invalid');
            }
            $this->report('applying_migrations',50,$release);
            $migrationResult=($this->migrate)($temporary,$release,$now);
            if(!is_array($migrationResult))throw new RuntimeException('update_install_migration_invalid');
            foreach($migrationResult as$migration){
                if(!is_string($migration)||!preg_match('/^[0-9]{4}_[A-Za-z0-9_]+\.php$/D',$migration)){
                    throw new RuntimeException('update_install_migration_invalid');
                }
            }
            $applied=$migrationResult;
            $state=[
                'format'=>'d2s-release-install-v1','status'=>'switching','release'=>$release,
                'previous_release'=>$currentRelease,'previous_path'=>$currentTarget,'transition'=>$transition,
                'backup'=>$backup,'applied_migrations'=>$applied,'created_at'=>$now,
                'database_restore_required'=>$applied!==[],
            ];
            $this->writeJson($this->statePath(),$state);
            if(!rename($temporary,$destination))throw new RuntimeException('update_install_switch_failed');
            $this->switchCurrentLink($root,'releases/'.$release,$transition);
            $switched=true;
            $this->report('restarting_service',85,$release);
            $health=($this->health)($destination,$release,$now);
            $healthy=$health===true||(is_array($health)&&($health['ok']??false)===true);
            if(!$healthy){
                $this->switchCurrentLink($root,$this->relativeToRoot($root,$currentTarget),$transition.'-restore');
                $switched=false;
                try{($this->health)($currentTarget,$currentRelease,$now);}catch(Throwable){}
                $this->ensureParent($failed);
                if(is_dir($destination)&&!rename($destination,$failed))throw new RuntimeException('update_install_rollback_failed');
                $state['status']='health_failed_code_rolled_back';
                $state['failed_path']=$failed;
                $this->writeJson($this->statePath(),$state);
                $suffix=$applied===[]?'':'database_restore_required';
                throw new RuntimeException('update_install_health_failed'.($suffix===''?'':':'.$suffix));
            }
            $state['status']='installed';$state['installed_at']=$now;
            $this->writeJson($this->statePath(),$state);
            $this->report('installed',100,$release);
            return ['status'=>'installed','release'=>$release,'previous_release'=>$currentRelease,
                'previous_path'=>$currentTarget,'backup'=>$backup,'applied_migrations'=>$applied,
                'database_restore_required'=>$applied!==[]];
        }catch(Throwable $error){
            if($switched){
                $this->switchCurrentLink($root,$this->relativeToRoot($root,$currentTarget),$transition.'-catch-restore');
                try{($this->health)($currentTarget,$currentRelease,$now);}catch(Throwable){}
            }
            if(is_dir($temporary)||is_link($temporary))$this->removeTree($temporary);
            $this->report('failed',100,$release,$error->getMessage());
            throw $error;
        }finally{
            flock($lock,LOCK_UN);fclose($lock);
        }
    }

    private function managedCurrentTarget(string $root,string $current): string
    {
        if(!is_link($current))throw new RuntimeException('update_install_current_invalid');
        $target=realpath($current);
        $releases=realpath($root.DIRECTORY_SEPARATOR.'releases');
        if($target===false||$releases===false
            ||!str_starts_with($this->pathKey($target),$this->pathKey($releases).DIRECTORY_SEPARATOR)
            ||!is_dir($target)||is_link($target)){
            throw new RuntimeException('update_install_current_invalid');
        }
        return $target;
    }

    private function switchCurrentLink(string $root,string $relativeTarget,string $transition): void
    {
        if($relativeTarget===''||str_contains($relativeTarget,"\0")||str_starts_with($relativeTarget,'/')
            ||preg_match('/^[A-Za-z]:/D',$relativeTarget)
            ||in_array('..',explode('/',$relativeTarget),true)){
            throw new RuntimeException('update_install_current_invalid');
        }
        $current=$root.DIRECTORY_SEPARATOR.'current';
        if(!is_link($current))throw new RuntimeException('update_install_current_invalid');
        $temporary=$root.DIRECTORY_SEPARATOR.'.current-'.$transition;
        if(file_exists($temporary)||is_link($temporary))throw new RuntimeException('update_install_current_invalid');
        if(!symlink(str_replace('/',DIRECTORY_SEPARATOR,$relativeTarget),$temporary)){
            throw new RuntimeException('update_install_switch_failed');
        }
        if(!rename($temporary,$current)){
            @unlink($temporary);
            throw new RuntimeException('update_install_switch_failed');
        }
    }

    private function relativeToRoot(string $root,string $path): string
    {
        $prefix=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if(!str_starts_with($this->pathKey($path),$this->pathKey($prefix))){
            throw new RuntimeException('update_install_current_invalid');
        }
        return str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($prefix)));
    }

    private function report(string $phase,int $percent,string $release,?string $error=null): void
    {
        $safeError=$error!==null&&preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$error)?$error:($error===null?null:'update_install_failed');
        if($this->progress!==null)($this->progress)([
            'phase'=>$phase,'percent'=>max(0,min(100,$percent)),'release'=>$release,
            'updated_at'=>time(),'error'=>$safeError,
        ]);
    }

    public function rollback(int $now): array
    {
        $root=$this->root();
        $lock=$this->lock();
        try{
            $state=$this->readJson($this->statePath());
            if(($state['status']??null)!=='installed'||!is_string($state['previous_path']??null)){
                throw new RuntimeException('update_rollback_unavailable');
            }
            $current=$root.DIRECTORY_SEPARATOR.'current';
            $previous=$this->assertManagedPath((string)$state['previous_path']);
            if(is_link($current)){
                $currentTarget=$this->managedCurrentTarget($root,$current);
                $previousRelease=$this->currentRelease($previous);
                $transition='rollback-'.bin2hex(random_bytes(6));
                $this->switchCurrentLink($root,$this->relativeToRoot($root,$previous),$transition);
                $health=($this->health)($previous,$previousRelease,$now);
                $healthy=$health===true||(is_array($health)&&($health['ok']??false)===true);
                if(!$healthy){
                    $this->switchCurrentLink($root,$this->relativeToRoot($root,$currentTarget),$transition.'-restore');
                    throw new RuntimeException('update_rollback_health_failed');
                }
                $state['status']='rolled_back';$state['rolled_back_at']=$now;
                $state['rolled_back_path']=$currentTarget;
                $state['release']=$previousRelease;
                $this->writeJson($this->statePath(),$state);
                $this->report('rolled_back',100,$previousRelease);
                return ['status'=>'rolled_back','release'=>$previousRelease,'rolled_back_path'=>$currentTarget,
                    'database_restore_required'=>(bool)($state['database_restore_required']??false),
                    'applied_migrations'=>$state['applied_migrations']??[]];
            }
            if(!is_dir($current)||!is_dir($previous)||is_link($previous)){
                throw new RuntimeException('update_rollback_path_invalid');
            }
            $release=(string)($state['release']??'');
            if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_rollback_state_invalid');
            $failed=$root.DIRECTORY_SEPARATOR.'rolled-back'.DIRECTORY_SEPARATOR.$release.'-'.gmdate('YmdHis',$now).'-'.bin2hex(random_bytes(5));
            $this->ensureParent($failed);
            if(!rename($current,$failed)||!rename($previous,$current)){
                if(!is_dir($current)&&is_dir($failed)&&!rename($failed,$current)){
                    throw new RuntimeException('update_rollback_failed');
                }
                throw new RuntimeException('update_rollback_failed');
            }
            $state['status']='rolled_back';
            $state['rolled_back_at']=$now;
            $state['rolled_back_path']=$failed;
            $this->writeJson($this->statePath(),$state);
            return ['status'=>'rolled_back','release'=>$release,'rolled_back_path'=>$failed,
                'database_restore_required'=>(bool)($state['database_restore_required']??false),
                'applied_migrations'=>$state['applied_migrations']??[]];
        }finally{
            flock($lock,LOCK_UN);fclose($lock);
        }
    }

    private function root(): string
    {
        if($this->deploymentRoot===''||str_contains($this->deploymentRoot,"\0")
            ||(!str_starts_with($this->deploymentRoot,DIRECTORY_SEPARATOR)
                &&!preg_match('/^[A-Za-z]:[\\\\\/]/D',$this->deploymentRoot))){
            throw new RuntimeException('update_install_root_invalid');
        }
        $root=realpath($this->deploymentRoot);
        if($root===false||!is_dir($root)||is_link($this->deploymentRoot))throw new RuntimeException('update_install_root_invalid');
        foreach(['staging','rollback','failed','state','releases']as$name){
            $path=$root.DIRECTORY_SEPARATOR.$name;
            if(is_link($path))throw new RuntimeException('update_install_root_invalid');
        }
        $current=$root.DIRECTORY_SEPARATOR.'current';
        if(is_link($current))$this->managedCurrentTarget($root,$current);
        return rtrim($root,DIRECTORY_SEPARATOR);
    }

    private function currentRelease(string $current): string
    {
        $path=$current.DIRECTORY_SEPARATOR.'release-manifest.json';
        $manifest=$this->readJson($path);
        $release=(string)($manifest['release']??'');
        if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_install_current_invalid');
        $verified=ReleaseManifest::verify($current,$path,$release);
        if(!$verified['ok'])throw new RuntimeException('update_install_current_invalid');
        return $release;
    }

    private function lock()
    {
        $path=$this->root().DIRECTORY_SEPARATOR.'install.lock';
        $handle=fopen($path,'c+');
        if($handle===false||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('update_install_lock_unavailable');
        return $handle;
    }

    private function restorePrevious(string $current,string $previous,string $failed): void
    {
        $this->ensureParent($failed);
        if(!rename($current,$failed)||!rename($previous,$current)){
            if(!is_dir($current)&&is_dir($failed)&&!rename($failed,$current)){
                throw new RuntimeException('update_install_rollback_failed');
            }
            throw new RuntimeException('update_install_rollback_failed');
        }
    }

    private function copyTree(string $source,string $destination): void
    {
        if(!is_dir($source)||is_link($source)||file_exists($destination))throw new RuntimeException('update_install_candidate_invalid');
        if(!mkdir($destination,0755,true))throw new RuntimeException('update_install_copy_failed');
        $iterator=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach($iterator as$item){
            $relative=substr($item->getPathname(),strlen($source)+1);
            $target=$destination.DIRECTORY_SEPARATOR.$relative;
            if($item->isLink())throw new RuntimeException('update_install_candidate_invalid');
            if($item->isDir()){
                if(!is_dir($target)&&!mkdir($target,0755))throw new RuntimeException('update_install_copy_failed');
                continue;
            }
            if(!$item->isFile()||!copy($item->getPathname(),$target))throw new RuntimeException('update_install_copy_failed');
            @chmod($target,0644);
        }
    }

    private function removeTree(string $path): void
    {
        $root=$this->root();
        $key=$this->pathKey($path);
        if(!str_starts_with($key,$this->pathKey($root).DIRECTORY_SEPARATOR))throw new RuntimeException('update_install_cleanup_scope_invalid');
        if(is_link($path)||is_file($path)){@unlink($path);return;}
        if(!is_dir($path))return;
        $items=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach($items as$item)$item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname());
        rmdir($path);
    }

    private function assertManagedPath(string $path): string
    {
        $root=$this->root();
        $real=realpath($path);
        if($real===false||!str_starts_with($this->pathKey($real),$this->pathKey($root).DIRECTORY_SEPARATOR)){
            throw new RuntimeException('update_rollback_path_invalid');
        }
        return $real;
    }

    private function ensureParent(string $path): void
    {
        $parent=dirname($path);
        if(!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent))throw new RuntimeException('update_install_directory_failed');
    }

    private function statePath(): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'current.json';
    }

    private function writeJson(string $path,array $value): void
    {
        $this->ensureParent($path);
        $encoded=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(file_put_contents($path,$encoded,LOCK_EX)!==strlen($encoded))throw new RuntimeException('update_install_state_write_failed');
        @chmod($path,0600);
    }

    private function readJson(string $path): array
    {
        if(!is_file($path)||is_link($path))throw new RuntimeException('update_install_state_missing');
        try{$value=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new RuntimeException('update_install_state_invalid');}
        if(!is_array($value))throw new RuntimeException('update_install_state_invalid');
        return $value;
    }

    private function pathKey(string $path): string
    {
        return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
    }
}
