<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

/** Runs one allow-listed CLI task at a time and records a structured result. */
final readonly class TaskRunner
{
    public function __construct(
        private string $root,
        private array $tasks,
        private string $lockDirectory,
        private string $logDirectory,
        private int $logMaxBytes = 5242880,
        private int $logKeep = 5,
        private ?array $schedule = null,
    ) {
        if ($logMaxBytes < 128 || $logMaxBytes > 104857600 || $logKeep < 1 || $logKeep > 50) {
            throw new RuntimeException('task_log_policy_invalid');
        }
    }

    public function run(string $task, array $arguments, int $now): array
    {
        if (!preg_match('/^[a-z0-9-]{1,64}$/D',$task) || !isset($this->tasks[$task])) {
            throw new RuntimeException('task_unknown');
        }
        $script = rtrim($this->root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim((string)$this->tasks[$task],'/\\');
        if (!is_file($script) || is_link($script)) {
            throw new RuntimeException('task_script_missing');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument,"\0")) {
                throw new RuntimeException('task_argument_invalid');
            }
        }
        $this->directory($this->lockDirectory);
        $this->directory($this->logDirectory);
        $lockPath = $this->lockDirectory.DIRECTORY_SEPARATOR.$task.'.lock';
        $lock = fopen($lockPath,'c');
        if ($lock === false) {
            throw new RuntimeException('task_lock_unavailable');
        }
        @chmod($lockPath,0600);
        if (!flock($lock,LOCK_EX|LOCK_NB)) {
            fclose($lock);
            $result = [
                'status'=>'busy','task'=>$task,'started_at'=>$now,'finished_at'=>$now,
                'duration_seconds'=>0,'exit_code'=>null,'message'=>'task_already_running',
            ];
            $this->log($task,$result);
            return $result;
        }

        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            if (is_resource($stdout)) fclose($stdout);
            if (is_resource($stderr)) fclose($stderr);
            flock($lock,LOCK_UN);
            fclose($lock);
            throw new RuntimeException('task_output_unavailable');
        }
        $started = microtime(true);
        $process = null;
        try {
            if(isset($this->schedule[$task])){
                $rule=$this->schedule[$task];
                $statePath=$this->lockDirectory.DIRECTORY_SEPARATOR.$task.'.state.json';
                $state=is_file($statePath)?json_decode((string)file_get_contents($statePath),true,8,JSON_THROW_ON_ERROR):[];
                $last=(int)($state['started_at']??0);
                if(!$rule['enabled']||($last>0&&$now-$last<(int)$rule['interval'])){
                    return ['status'=>'skipped','task'=>$task,'started_at'=>$now,'finished_at'=>$now,
                        'duration_seconds'=>0,'exit_code'=>null,'message'=>$rule['enabled']?'task_not_due':'task_paused'];
                }
            }
            $command = array_merge([PHP_BINARY,$script],array_values($arguments));
            $process = proc_open($command,[0=>['pipe','r'],1=>$stdout,2=>$stderr],$pipes,$this->root);
            if (!is_resource($process)) {
                throw new RuntimeException('task_start_failed');
            }
            fclose($pipes[0]);
            $exit = proc_close($process);
            $process = null;
            $finished = $now + max(0,(int)ceil(microtime(true)-$started));
            $output = $this->read($stdout);
            $errors = $this->read($stderr);
            $result = [
                'status'=>$exit === 0 ? 'success' : 'failed',
                'task'=>$task,
                'started_at'=>$now,
                'finished_at'=>$finished,
                'duration_seconds'=>max(0,$finished-$now),
                'exit_code'=>$exit,
                'output_sha256'=>hash('sha256',$output),
                'error_sha256'=>hash('sha256',$errors),
                'message'=>$this->message($exit === 0 ? $output : $errors),
            ];
            $this->log($task,$result);
            if(isset($this->schedule[$task])){
                $temporary=$statePath.'.'.bin2hex(random_bytes(6)).'.tmp';
                if(file_put_contents($temporary,json_encode(['started_at'=>$now],JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('task_state_write_failed');
                @chmod($temporary,0600);
                if(!rename($temporary,$statePath)){unlink($temporary);throw new RuntimeException('task_state_write_failed');}
            }
            return $result;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            fclose($stdout);
            fclose($stderr);
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) {
            throw new RuntimeException('task_directory_unavailable');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('task_directory_unavailable');
        }
    }

    private function read(mixed $stream): string
    {
        rewind($stream);
        $value = stream_get_contents($stream,8192);
        return is_string($value) ? $value : '';
    }

    private function message(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$value) ?? '');
        return strlen($value) > 1000 ? substr($value,0,1000) : $value;
    }

    private function log(string $task, array $result): void
    {
        $path = $this->logDirectory.DIRECTORY_SEPARATOR.$task.'.log';
        $lockPath = $path.'.lock';
        $lock = fopen($lockPath,'c');
        if ($lock === false || !flock($lock,LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('task_log_lock_failed');
        }
        @chmod($lockPath,0600);
        try {
            clearstatcache(true,$path);
            if (is_file($path) && (int)filesize($path) >= $this->logMaxBytes) {
                $this->rotate($path);
            }
            $line = json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            if (file_put_contents($path,$line,FILE_APPEND|LOCK_EX) === false) {
                throw new RuntimeException('task_log_write_failed');
            }
            @chmod($path,0600);
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private function rotate(string $path): void
    {
        for ($index=$this->logKeep; $index>=1; $index--) {
            $source = $index === 1 ? $path : $path.'.'.($index-1);
            $target = $path.'.'.$index;
            if (!is_file($source)) continue;
            if (is_file($target) && !unlink($target)) {
                throw new RuntimeException('task_log_rotation_failed');
            }
            if (!rename($source,$target)) {
                throw new RuntimeException('task_log_rotation_failed');
            }
            @chmod($target,0600);
        }
    }
}
