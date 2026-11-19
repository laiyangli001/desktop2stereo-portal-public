<?php
declare(strict_types=1);

namespace D2S\services;

/** Read-only evaluation of TaskRunner JSONL logs. */
final class TaskHealth
{
    public static function check(string $logDirectory,int $now,?array $only=null,?array $schedule=null): array
    {
        $items = [];
        $healthy = true;
        foreach (TaskCatalog::TASKS as $task => $definition) {
            if ($only !== null && !in_array($task,$only,true)) continue;
            $path = rtrim($logDirectory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$task.'.log';
            $entry = self::lastEntry($path);
            $rule=$schedule[$task]??['enabled'=>true,'interval'=>$definition['interval']];
            $maxAge = (int)$rule['interval'] * 2 + 60;
            if(!$rule['enabled']){
                $state='paused';$ok=true;
            } elseif ($entry === null) {
                $state = (bool)$definition['optional'] ? 'optional_missing' : 'missing';
                $ok = (bool)$definition['optional'];
            } elseif (($entry['error'] ?? null) !== null) {
                $state = 'invalid_log';
                $ok = false;
            } elseif (($entry['status'] ?? null) === 'failed') {
                $state = 'failed';
                $ok = false;
            } else {
                $finished = (int)($entry['finished_at'] ?? 0);
                $age = max(0,$now-$finished);
                if ($age > $maxAge) {
                    $state = 'stale';
                    $ok = false;
                } elseif (($entry['status'] ?? null) === 'busy') {
                    $previous = self::lastSuccess($path);
                    $state = $previous !== null && $now-(int)($previous['finished_at'] ?? 0) <= $maxAge ? 'running' : 'failed';
                    $ok = $state === 'running';
                } else {
                    $state = 'healthy';
                    $ok = true;
                }
            }
            $healthy = $healthy && $ok;
            $items[] = [
                'task'=>$task,
                'enabled'=>$rule['enabled'],
                'interval'=>$rule['interval'],
                'state'=>$state,
                'ok'=>$ok,
                'last_status'=>$entry['status'] ?? null,
                'finished_at'=>$entry['finished_at'] ?? null,
                'age_seconds'=>isset($entry['finished_at']) ? max(0,$now-(int)$entry['finished_at']) : null,
                'max_age_seconds'=>$maxAge,
                'message'=>$entry['message'] ?? null,
            ];
        }
        return ['ok'=>$healthy,'checked_at'=>$now,'items'=>$items];
    }

    private static function lastEntry(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $lines = file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) return null;
        try {
            $entry = json_decode((string)end($lines),true,16,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['error'=>'invalid_json'];
        }
        return is_array($entry) ? $entry : ['error'=>'invalid_entry'];
    }

    private static function lastSuccess(string $path): ?array
    {
        $lines = array_reverse(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: []);
        foreach ($lines as $line) {
            try { $entry = json_decode((string)$line,true,16,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { continue; }
            if (is_array($entry) && ($entry['status'] ?? null) === 'success') return $entry;
        }
        return null;
    }
}
