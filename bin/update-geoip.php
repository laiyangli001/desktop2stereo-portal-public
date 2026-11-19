<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';

use MaxMind\Db\Reader;

$temporary = null;
$compressed = null;
try {
    $options=getopt('',['month:']);
    $month=$options['month']??gmdate('Y-m');
    if(!is_string($month)||!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('geoip_month_invalid');
    $directory=D2S_ROOT.'/storage/geoip';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('geoip_directory_unavailable');
    $compressed=tempnam($directory,'download-');
    $temporary=tempnam($directory,'database-');
    if($compressed===false||$temporary===false)throw new RuntimeException('geoip_temporary_file_unavailable');
    $file=fopen($compressed,'wb');
    if($file===false)throw new RuntimeException('geoip_download_unavailable');
    $curl=curl_init('https://download.db-ip.com/free/dbip-country-lite-'.$month.'.mmdb.gz');
    curl_setopt_array($curl,[
        CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Desktop2Stereo-GeoIP/1.0',
        CURLOPT_WRITEFUNCTION=>static function($handle,string $bytes)use($file):int{
            if(ftell($file)+strlen($bytes)>20000000)return 0;
            $written=fwrite($file,$bytes);
            return $written===false?0:$written;
        },
    ]);
    if(PHP_OS_FAMILY==='Windows'&&defined('CURLSSLOPT_NATIVE_CA'))curl_setopt($curl,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);
    $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);fclose($file);
    if($ok===false||$status!==200)throw new RuntimeException('geoip_download_failed_http_'.$status);
    $input=gzopen($compressed,'rb');$output=fopen($temporary,'wb');
    if($input===false||$output===false)throw new RuntimeException('geoip_archive_invalid');
    $size=0;
    while(!gzeof($input)){
        $chunk=gzread($input,65536);
        if($chunk===false)throw new RuntimeException('geoip_archive_invalid');
        $size+=strlen($chunk);if($size>60000000)throw new RuntimeException('geoip_database_too_large');
        if(fwrite($output,$chunk)!==strlen($chunk))throw new RuntimeException('geoip_write_failed');
    }
    gzclose($input);fclose($output);
    $reader=new Reader($temporary);$metadata=$reader->metadata();
    if(!str_contains(strtolower($metadata->databaseType),'country'))throw new RuntimeException('geoip_database_type_invalid');
    $buildTime=$metadata->buildEpoch;$reader->close();
    $sha256=hash_file('sha256',$temporary);
    if(!rename($temporary,$directory.'/country.mmdb'))throw new RuntimeException('geoip_publish_failed');
    $temporary=null;
    file_put_contents($directory.'/source.json',json_encode([
        'provider'=>'DB-IP','license'=>'CC BY 4.0','attribution_url'=>'https://db-ip.com',
        'month'=>$month,'build_time'=>$buildTime,'sha256'=>$sha256,'downloaded_at'=>time(),
    ],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
    echo json_encode(['updated'=>true,'month'=>$month,'sha256'=>$sha256,'bytes'=>$size],JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);
} finally {
    foreach([$compressed,$temporary]as$file)if(is_string($file)&&is_file($file))unlink($file);
}
