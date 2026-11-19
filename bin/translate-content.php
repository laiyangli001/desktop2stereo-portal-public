<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

try{
    $result=(new D2S\services\TranslationJobs(D2S\repositories\Database::fromEnvironment()))->runBatch(time());
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}catch(Throwable $error){
    $code=$error instanceof D2S\domain\Problem?$error->getMessage():'translation_worker_failed';
    if(!preg_match('/^[a-z0-9_]{1,64}$/D',$code))$code='translation_worker_failed';
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$code],JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
