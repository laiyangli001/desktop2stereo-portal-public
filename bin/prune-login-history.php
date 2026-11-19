<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\LoginHistoryRetention;

if (!in_array('--apply',$argv,true)) {
    fwrite(STDERR,"usage: php bin/prune-login-history.php --apply\n");
    exit(2);
}

try {
    $db=Database::fromEnvironment('D2S_RETENTION_DB_');
    $service=new LoginHistoryRetention($db,LoginHistoryRetention::retentionDaysFromEnvironment());
    $result=$service->prune(time());
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    if (($result['status']??'')==='busy') exit(75);
} catch (Throwable) {
    fwrite(STDERR,"login_history_retention_failed\n");
    exit(1);
}
