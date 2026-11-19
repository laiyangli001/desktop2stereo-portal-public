<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\EdgeOneCachePurge;

try {
    $result = (new EdgeOneCachePurge())->runBatch(Database::fromEnvironment(),time());
    echo json_encode(['success'=>in_array($result['status'],['submitted','idle','disabled'],true),'data'=>$result],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(in_array($result['status'],['submitted','idle','disabled'],true)?0:1);
} catch (Throwable $error) {
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n");
    exit(2);
}
