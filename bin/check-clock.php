<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\services\NtpProbe;

$options=getopt('',['servers:','max-offset-ms:','quorum:','timeout-ms:']);
$intOption=static function(string $name,string $environment,int $default)use($options):int{
    if(array_key_exists($name,$options))return (int)$options[$name];
    $value=getenv($environment);
    return is_string($value)&&$value!==''?(int)$value:$default;
};
$raw=(string)($options['servers'] ?? getenv('D2S_NTP_SERVERS') ?: '');
$maxOffset=$intOption('max-offset-ms','D2S_NTP_MAX_OFFSET_MS',1000);
$timeout=$intOption('timeout-ms','D2S_NTP_TIMEOUT_MS',1200);
try{
    $parsed=NtpProbe::parseServers($raw);
    if($parsed['invalid']!==[])throw new RuntimeException('ntp_servers_invalid');
    $endpoints=$parsed['endpoints'];
    $quorum=$intOption('quorum','D2S_NTP_QUORUM',min(2,max(1,count($endpoints))));
    $result=NtpProbe::check($endpoints,$maxOffset,$quorum,$timeout);
    echo json_encode(['success'=>$result['ok'],'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['ok']?0:1);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n");
    exit(2);
}
