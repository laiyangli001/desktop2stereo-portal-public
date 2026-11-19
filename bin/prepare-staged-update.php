<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\OnlineUpdate;

$options=getopt('',['release:']);
$release=(string)($options['release']??'');
if($release===''||!preg_match('/^[a-f0-9]{40}$/D',$release)){
    fwrite(STDERR,"usage: php bin/prepare-staged-update.php --release=<40-character-release-sha>\n");
    exit(2);
}
try{
    $result=(new OnlineUpdate())->prepareRelease($release,time());
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
