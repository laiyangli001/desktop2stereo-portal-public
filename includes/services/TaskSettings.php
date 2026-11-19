<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class TaskSettings
{
    public function __construct(private PDO $db) {}

    public function load(): array
    {
        $value=$this->db->query("SELECT value_json FROM portal_settings WHERE name='tasks.schedule.v1'")->fetchColumn();
        $saved=$value===false?[]:json_decode($value,true,16,JSON_THROW_ON_ERROR);
        $result=[];
        foreach(TaskCatalog::TASKS as$name=>$definition){
            $result[$name]=['enabled'=>true,'interval'=>$definition['interval']];
            if(isset($saved[$name]))$result[$name]=self::validate($name,$saved[$name]);
        }
        return $result;
    }

    private static function validate(string $task,array $input): array
    {
        if(!isset(TaskCatalog::TASKS[$task]))throw new Problem('task_unknown');
        $minimum=in_array($task,['backup','verify-backup','prune-support','prune-login-history'],true)?3600:($task==='update-geoip'?86400:30);
        if(!is_bool($input['enabled']??null)||!is_int($input['interval']??null)||
            $input['interval']<$minimum||$input['interval']>7776000)throw new Problem('task_schedule_invalid');
        return ['enabled'=>$input['enabled'],'interval'=>$input['interval']];
    }

    public function save(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $task=$input['task']??null;if(!is_string($task))throw new Problem('task_unknown');
        $rule=self::validate($task,$input);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$task,$rule,$context,$now):array{
            $db->prepare("INSERT IGNORE INTO portal_settings(name,value_json,updated_at) VALUES('tasks.schedule.v1','{}',?)")->execute([$now]);
            $q=$db->query("SELECT value_json FROM portal_settings WHERE name='tasks.schedule.v1' FOR UPDATE");
            $saved=json_decode($q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);$saved[$task]=$rule;
            $db->prepare("UPDATE portal_settings SET value_json=?,updated_at=? WHERE name='tasks.schedule.v1'")->execute([json_encode($saved,JSON_THROW_ON_ERROR),$now]);
            (new Backoffice($db))->audit($adminId,'UPDATE_TASK_SCHEDULE',$task,$rule,$context,$now);
            return ['task'=>$task]+$rule;
        });
    }

    public function overview(int $adminId,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $result=TaskHealth::check(getenv('D2S_TASK_LOG_DIR')?:D2S_ROOT.'/storage/logs/tasks',$now,null,$this->load());
        return $result;
    }
}
