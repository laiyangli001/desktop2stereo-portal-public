<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\TaskCatalog;
use D2S\services\TaskHealth;

$options = getopt('', ['task:']);
$only = isset($options['task'])
    ? array_values(array_filter(explode(',',(string)$options['task']),static fn(string $task): bool => $task !== ''))
    : null;
if ($only !== null && array_diff($only,array_keys(TaskCatalog::TASKS)) !== []) {
    fwrite(STDERR,json_encode(['success'=>false,'error'=>'task_unknown'],JSON_THROW_ON_ERROR)."\n");
    exit(2);
}
try {
    $schedule=getenv('D2S_DB_DSN')?(new D2S\services\TaskSettings(D2S\repositories\Database::fromEnvironment()))->load():null;
    $result = TaskHealth::check(getenv('D2S_TASK_LOG_DIR') ?: D2S_ROOT.'/storage/logs/tasks',time(),$only,$schedule);
} catch(Throwable) {
    fwrite(STDERR,json_encode(['success'=>false,'error'=>'task_settings_unavailable'],JSON_THROW_ON_ERROR)."\n");
    exit(2);
}
echo json_encode(['success'=>$result['ok'],'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($result['ok'] ? 0 : 1);
