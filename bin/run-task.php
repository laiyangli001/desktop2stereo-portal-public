<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\TaskCatalog;
use D2S\services\TaskRunner;

$options = getopt('', ['task:']);
$task = (string)($options['task'] ?? '');
$separator = array_search('--',$argv,true);
$arguments = $separator === false ? [] : array_values(array_slice($argv,$separator+1));
try {
    $tasks = TaskCatalog::scripts();
    $runner = new TaskRunner(
        D2S_ROOT,
        $tasks,
        getenv('D2S_TASK_LOCK_DIR') ?: D2S_ROOT.'/storage/locks',
        getenv('D2S_TASK_LOG_DIR') ?: D2S_ROOT.'/storage/logs/tasks',
        (int)(getenv('D2S_TASK_LOG_MAX_BYTES') ?: 5242880),
        (int)(getenv('D2S_TASK_LOG_KEEP') ?: 5),
        getenv('D2S_DB_DSN')?(new D2S\services\TaskSettings(D2S\repositories\Database::fromEnvironment()))->load():null,
    );
    $result = $runner->run($task,$arguments,time());
    echo json_encode(['success'=>in_array($result['status'],['success','skipped'],true),'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(match($result['status']) { 'success','skipped'=>0, 'busy'=>75, default=>1 });
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error instanceof PDOException?'task_database_unavailable':$error->getMessage()],JSON_THROW_ON_ERROR)."\n");
    exit(2);
}
