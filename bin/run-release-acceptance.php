<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\ReleaseAcceptance;

$options=getopt('',['profile::','only::','output::','fail-fast','dry-run']);
$profile=(string)($options['profile']??'local');
$onlyValue=(string)($options['only']??'');
$only=$onlyValue===''?[]:array_values(array_filter(array_map('trim',explode(',',$onlyValue))));
$output=isset($options['output'])?(string)$options['output']:null;
try{
    foreach(array_slice($argv,1)as$argument){
        if(!preg_match('/^--(?:(?:profile|only|output)(?:=.*)?|fail-fast|dry-run)$/Ds',$argument)){
            throw new RuntimeException('acceptance_option_unknown');
        }
    }
    $runner=new ReleaseAcceptance(D2S_ROOT,PHP_BINARY,getenv('D2S_NODE_BINARY')?:'node',$output);
    if(isset($options['dry-run'])){
        $result=['success'=>true,'data'=>['profile'=>$profile,'tests'=>$runner->plan($profile,$only)]];
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }
    $summary=$runner->run($profile,$only,isset($options['fail-fast']));
    echo json_encode(['success'=>$summary['status']==='passed','data'=>$summary],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($summary['status']==='passed'?0:1);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}
