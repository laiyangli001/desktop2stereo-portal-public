<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\OnlineUpdate;

$options=getopt('',['stage:']);
$stage=(string)($options['stage']??'');
if($stage===''||str_contains($stage,"\0")){
    fwrite(STDERR,"usage: php bin/verify-staged-update.php --stage=<absolute-staging-directory>\n");
    exit(2);
}
try{
    $result=(new OnlineUpdate())->inspectStaged($stage,true);
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
