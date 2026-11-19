<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

/** Runs a fixed, allow-listed local regression profile and records private evidence. */
final class ReleaseAcceptance
{
    private const PROFILES = [
        'quick' => [
            ['id'=>'docs-links','runner'=>'php','script'=>'tests/docs-links.php','timeout'=>120],
            ['id'=>'release-manifest','runner'=>'php','script'=>'tests/release-manifest.php','timeout'=>180],
            ['id'=>'admin-dashboard','runner'=>'php','script'=>'tests/admin-dashboard.php','timeout'=>600],
        ],
        'local' => [
            ['id'=>'docs-links','runner'=>'php','script'=>'tests/docs-links.php','timeout'=>120],
            ['id'=>'release-manifest','runner'=>'php','script'=>'tests/release-manifest.php','timeout'=>180],
            ['id'=>'preflight','runner'=>'php','script'=>'tests/preflight.php','timeout'=>300],
            ['id'=>'mysql','runner'=>'php','script'=>'tests/mysql.php','timeout'=>900],
            ['id'=>'accounts','runner'=>'php','script'=>'tests/accounts.php','timeout'=>1200],
            ['id'=>'licensing','runner'=>'php','script'=>'tests/licensing.php','timeout'=>900],
            ['id'=>'multi-license-management','runner'=>'php','script'=>'tests/multi-license-management.php','timeout'=>1800],
            ['id'=>'commerce','runner'=>'php','script'=>'tests/commerce.php','timeout'=>1200],
            ['id'=>'multiple-product-commerce','runner'=>'php','script'=>'tests/multiple-product-commerce.php','timeout'=>1800],
            ['id'=>'support-audit','runner'=>'php','script'=>'tests/support-audit.php','timeout'=>1200],
            ['id'=>'content','runner'=>'php','script'=>'tests/content.php','timeout'=>1200],
            ['id'=>'online-update','runner'=>'php','script'=>'tests/online-update-staging.php','timeout'=>1800],
            ['id'=>'browser-content','runner'=>'php','script'=>'tests/browser-content.php','timeout'=>1800],
            ['id'=>'browser-localized','runner'=>'php','script'=>'tests/localized-operations.php','timeout'=>2400],
            ['id'=>'browser-i18n','runner'=>'php','script'=>'tests/browser-i18n.php','timeout'=>1800],
            ['id'=>'locales','runner'=>'node','script'=>'frontend/check-locales.mjs','timeout'=>120],
            ['id'=>'i18n-build','runner'=>'node','script'=>'frontend/build-i18n.mjs','timeout'=>180],
        ],
    ];

    public function __construct(
        private string $root,
        private string $phpBinary=PHP_BINARY,
        private string $nodeBinary='node',
        private ?string $outputRoot=null,
    ) {}

    public function profiles(): array
    {
        return array_keys(self::PROFILES);
    }

    public function plan(string $profile,array $only=[]): array
    {
        return array_map(static fn(array $item):array=>[
            'id'=>$item['id'],'runner'=>$item['runner'],'script'=>$item['script'],'timeout'=>$item['timeout'],
        ],$this->tests($profile,$only));
    }

    public function run(string $profile,array $only=[],bool $failFast=false): array
    {
        $tests=$this->tests($profile,$only);
        $outputRoot=$this->outputPath();
        if(!is_dir($outputRoot)&&!mkdir($outputRoot,0700,true)&&!is_dir($outputRoot)){
            throw new RuntimeException('acceptance_output_unavailable');
        }
        $lock=fopen($outputRoot.DIRECTORY_SEPARATOR.'acceptance.lock','c+');
        if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('acceptance_lock_unavailable');
        $runId=gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
        $runDirectory=$outputRoot.DIRECTORY_SEPARATOR.$runId;
        if(!mkdir($runDirectory,0700,true))throw new RuntimeException('acceptance_output_unavailable');
        $started=microtime(true);
        $results=[];
        try{
            $release=getenv('D2S_RELEASE')?:ReleaseManifest::gitHead($this->root);
            if(!is_string($release))throw new RuntimeException('acceptance_release_required');
            $before=$this->snapshot($release,$runDirectory,'before');
            if($before['git_head']!==null&&$before['git_head']!==$release){
                throw new RuntimeException('acceptance_release_mismatch');
            }
            foreach($tests as$test){
                $result=$this->execute($test,$runDirectory);
                $results[]=$result;
                if($failFast&&$result['status']!=='passed')break;
            }
            $integrityErrors=[];
            try{
                $after=$this->snapshot($release,$runDirectory,'after');
                if($before['root_hash']!==$after['root_hash'])$integrityErrors[]='acceptance_runtime_changed';
                if($before['git_head']!==$after['git_head'])$integrityErrors[]='acceptance_git_head_changed';
            }catch(\Throwable $error){
                $after=['error'=>'acceptance_snapshot_failed'];
                $integrityErrors[]='acceptance_snapshot_failed';
            }
            $summary=[
                'format'=>'d2s-release-acceptance-v1','profile'=>$profile,'run_id'=>$runId,
                'release'=>$release,
                'code_evidence'=>['before'=>$before,'after'=>$after,'stable'=>$integrityErrors===[],'errors'=>$integrityErrors],
                'started_at'=>(int)$started,'finished_at'=>time(),
                'duration_ms'=>(int)round((microtime(true)-$started)*1000),
                'status'=>$integrityErrors!==[]?'failed':array_reduce($results,static fn(string $status,array $item):string=>$status==='passed'&&$item['status']==='passed'?'passed':'failed','passed'),
                'tests'=>$results,
            ];
            $this->writeJson($runDirectory.DIRECTORY_SEPARATOR.'summary.json',$summary);
            return array_replace($summary,['run_directory'=>$runDirectory]);
        }finally{
            flock($lock,LOCK_UN);fclose($lock);
        }
    }

    /** Keep content hashes and Git state alongside the logs, without copying file contents. */
    private function snapshot(string $release,string $runDirectory,string $phase): array
    {
        clearstatcache();
        $manifest=ReleaseManifest::build($this->root,$release,time());
        $path=$runDirectory.DIRECTORY_SEPARATOR.'runtime-'.$phase.'.json';
        $this->writeJson($path,$manifest);
        $clean=ReleaseManifest::cleanGit($this->root);
        return [
            'manifest'=>basename($path),'manifest_sha256'=>$this->fileHash($path),
            'root_hash'=>$manifest['root_hash'],'file_count'=>$manifest['file_count'],
            'git_head'=>ReleaseManifest::gitHead($this->root),
            'working_tree_dirty'=>$clean===null?null:!$clean,
        ];
    }

    private function tests(string $profile,array $only): array
    {
        if(!isset(self::PROFILES[$profile]))throw new RuntimeException('acceptance_profile_unknown');
        $tests=self::PROFILES[$profile];
        if($only!==[]){
            $wanted=array_values(array_unique(array_map(static fn($value):string=>is_string($value)?$value:'',$only)));
            $tests=array_values(array_filter($tests,static fn(array $item):bool=>in_array($item['id'],$wanted,true)));
            if(count($tests)!==count($wanted))throw new RuntimeException('acceptance_test_unknown');
        }
        foreach($tests as$test){
            $path=$this->root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$test['script']);
            if(!is_file($path)||is_link($path))throw new RuntimeException('acceptance_test_missing');
            $resolved=realpath($path);
            if($resolved===false||!str_starts_with($resolved,$this->root.DIRECTORY_SEPARATOR)){
                throw new RuntimeException('acceptance_test_path_invalid');
            }
        }
        return $tests;
    }

    private function execute(array $test,string $runDirectory): array
    {
        $binary=$test['runner']==='php'?$this->phpBinary:$this->nodeBinary;
        $script=$this->root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$test['script']);
        $stdoutPath=$runDirectory.DIRECTORY_SEPARATOR.$test['id'].'.stdout.log';
        $stderrPath=$runDirectory.DIRECTORY_SEPARATOR.$test['id'].'.stderr.log';
        $started=microtime(true);
        $process=proc_open([$binary,$script],[
            0=>['pipe','r'],1=>['file',$stdoutPath,'a'],2=>['file',$stderrPath,'a'],
        ],$pipes,$this->root);
        if(!is_resource($process))throw new RuntimeException('acceptance_process_start_failed');
        fclose($pipes[0]);
        $timedOut=false;
        while(true){
            $status=proc_get_status($process);
            if(!($status['running']??false))break;
            if(microtime(true)-$started>$test['timeout']){
                $timedOut=true;
                proc_terminate($process,9);
                break;
            }
            usleep(100000);
        }
        $exit=proc_close($process);
        if($exit===-1&&isset($status['exitcode'])&&is_int($status['exitcode']))$exit=$status['exitcode'];
        $result=[
            'id'=>$test['id'],'runner'=>$test['runner'],'script'=>$test['script'],
            'status'=>$timedOut?'timeout':($exit===0?'passed':'failed'),'exit_code'=>$exit,
            'timed_out'=>$timedOut,'duration_ms'=>(int)round((microtime(true)-$started)*1000),
            'stdout'=>['path'=>basename($stdoutPath),'sha256'=>$this->fileHash($stdoutPath),'bytes'=>$this->fileSize($stdoutPath)],
            'stderr'=>['path'=>basename($stderrPath),'sha256'=>$this->fileHash($stderrPath),'bytes'=>$this->fileSize($stderrPath)],
        ];
        $this->writeJson($runDirectory.DIRECTORY_SEPARATOR.$test['id'].'.result.json',$result);
        return $result;
    }

    private function outputPath(): string
    {
        $path=$this->outputRoot??$this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'acceptance';
        return PrivateOutputDirectory::resolve($path,$this->root,'acceptance_output');
    }

    private function writeJson(string $path,array $value): void
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(file_put_contents($path,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('acceptance_write_failed');
        @chmod($path,0600);
    }

    private function fileHash(string $path): ?string
    {
        $hash=is_file($path)?hash_file('sha256',$path):false;
        return is_string($hash)?$hash:null;
    }

    private function fileSize(string $path): int
    {
        $size=is_file($path)?filesize($path):false;
        return is_int($size)?$size:0;
    }
}
