<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\repositories\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class LoginHistoryRetention
{
    public const MINIMUM_DAYS = 366;
    public const MAXIMUM_DAYS = 3650;
    private const LOCK_NAME = 'd2s_login_history_purge';

    public function __construct(private PDO $db,private int $retentionDays=self::MINIMUM_DAYS)
    {
        if ($retentionDays < self::MINIMUM_DAYS || $retentionDays > self::MAXIMUM_DAYS) {
            throw new InvalidArgumentException('login_history_retention_days_invalid');
        }
    }

    public static function retentionDaysFromEnvironment(): int
    {
        $raw = trim((string)(getenv('D2S_LOGIN_HISTORY_RETENTION_DAYS') ?: ''));
        if ($raw === '') return self::MINIMUM_DAYS;
        if (preg_match('/^[0-9]{3,4}$/D',$raw) !== 1) throw new RuntimeException('login_history_retention_days_invalid');
        $days = (int)$raw;
        if ($days < self::MINIMUM_DAYS || $days > self::MAXIMUM_DAYS) {
            throw new RuntimeException('login_history_retention_days_invalid');
        }
        return $days;
    }

    public function prune(int $now,int $batchSize=1000): array
    {
        if ($batchSize < 1 || $batchSize > 10000) throw new InvalidArgumentException('login_history_batch_invalid');
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([self::LOCK_NAME]);
        if ((int)$lock->fetchColumn() !== 1) return ['status'=>'busy','deleted'=>0];
        try {
            $cutoff = $now - ($this->retentionDays * 86400);
            $base = [
                'task'=>'login_history',
                'status'=>'started',
                'retention_days'=>$this->retentionDays,
                'cutoff_utc'=>$this->utc($cutoff),
                'range_start_utc'=>$this->utc(0),
                'range_end_utc'=>$this->utc($cutoff),
            ];
            $this->recordRun(null,$cutoff,$now,$base,$now);
            try {
                $deleted = Database::transaction($this->db,function (PDO $db) use ($cutoff,$now,$batchSize,$base): int {
                    $deleted = 0;
                    $statement = $db->prepare("DELETE FROM portal_login_history
                        WHERE created_at < ? ORDER BY created_at,id LIMIT {$batchSize}");
                    for ($batch=0; $batch<20; $batch++) {
                        $statement->execute([$cutoff]);
                        $count = $statement->rowCount();
                        $deleted += $count;
                        if ($count < $batchSize) break;
                    }
                    $details = array_replace($base,[
                        'status'=>'completed',
                        'deleted'=>$deleted,
                    ]);
                    $this->recordRun(null,$cutoff,$now,$details,$now,$db);
                    Audit::admin($db,null,'system@d2s.invalid','CLEANUP_LOGIN_HISTORY','login_history',[
                        'source_ip'=>null,
                    ],'SUCCESS',[
                        'deleted'=>$deleted,
                        'retention_days'=>$this->retentionDays,
                        'cutoff_utc'=>$details['cutoff_utc'],
                    ],$now);
                    return $deleted;
                });
                return ['status'=>'completed','deleted'=>$deleted,'cutoff'=>$cutoff,'retention_days'=>$this->retentionDays];
            } catch (\Throwable $error) {
                try {
                    Database::transaction($this->db,function (PDO $db) use ($cutoff,$now,$base): void {
                        $details=array_replace($base,[
                            'status'=>'failed',
                            'failure_code'=>'login_history_retention_failed',
                        ]);
                        $this->recordRun(null,$cutoff,$now,$details,$now,$db);
                        Audit::admin($db,null,'system@d2s.invalid','CLEANUP_LOGIN_HISTORY','login_history',[
                            'source_ip'=>null,
                        ],'FAILURE',[
                            'failure_code'=>'login_history_retention_failed',
                            'retention_days'=>$this->retentionDays,
                            'cutoff_utc'=>$details['cutoff_utc'],
                        ],$now);
                    });
                } catch (\Throwable) {
                    // The original failure remains primary; the started run still provides a durable trace.
                }
                throw $error;
            }
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK_NAME]);
        }
    }

    private function recordRun(?int $operatorId,int $cutoff,int $now,array $details,int $timestamp,?PDO $db=null): void
    {
        ($db ?? $this->db)->prepare('INSERT INTO portal_retention_runs
            (operator_id,started_at,range_start,range_end,counts_json) VALUES (?,?,?,?,?)')
            ->execute([$operatorId,$timestamp,0,$cutoff,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
    }

    private function utc(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z',$timestamp);
    }
}
