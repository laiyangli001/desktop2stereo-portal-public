<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

final class UpdateHttp
{
    public static function request(string $url,string $host,int $maximum,array $headers=[],?string $caBundle=null,int $timeout=60,?callable $onProgress=null): array
    {
        $address=self::publicAddresses($host)[0];
        $body='';$location=null;$tooLarge=false;$downloaded=0;$total=null;
        $curl=curl_init($url);
        if($curl===false)throw new RuntimeException('update_download_failed');
        $options=[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',
            CURLOPT_USERAGENT=>'Desktop2Stereo-Portal-Update',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$location,&$total):int{
                if(str_starts_with($line,'HTTP/')){$location=null;$total=null;}
                elseif(str_starts_with(strtolower($line),'location:'))$location=trim(substr($line,9));
                elseif(str_starts_with(strtolower($line),'content-length:')){
                    $value=trim(substr($line,15));
                    if(preg_match('/^[0-9]{1,12}$/D',$value))$total=(int)$value;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge,$maximum,&$downloaded,&$total,$onProgress):int{
                if(strlen($body)+strlen($chunk)>$maximum){$tooLarge=true;return 0;}
                $body.=$chunk;$downloaded+=strlen($chunk);
                if($onProgress!==null)$onProgress($downloaded,$total);
                return strlen($chunk);
            },
        ];
        if($caBundle!==null&&$caBundle!=='')$options[CURLOPT_CAINFO]=$caBundle;
        if(!filter_var($host,FILTER_VALIDATE_IP)){
            $options[CURLOPT_RESOLVE]=[$host.':443:'.(str_contains($address,':')?'['.$address.']':$address)];
        }
        curl_setopt_array($curl,$options);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
        if($tooLarge)throw new RuntimeException('update_download_too_large');
        if($ok===false||$errno!==0)throw new RuntimeException('update_download_failed');
        return ['body'=>$body,'status'=>$status,'location'=>$location,'download_bytes'=>$downloaded,'download_total_bytes'=>$total];
    }

    public static function probe(string $url,string $host,int $timeout=10): array
    {
        $parsed=parse_url($url);
        $urlHost=is_array($parsed)?strtolower(rtrim((string)($parsed['host']??''),'.')):'';
        if(!is_array($parsed)||strtolower((string)($parsed['scheme']??''))!=='https'||
            $urlHost!==strtolower(rtrim($host,'.'))||isset($parsed['user'])||isset($parsed['pass'])||
            (isset($parsed['port'])&&(int)$parsed['port']!==443)){
            return ['reachable'=>false,'http_status'=>null,'error_code'=>'invalid_url'];
        }
        try{
            $address=self::publicAddresses($host)[0];
        }catch(RuntimeException $error){
            return ['reachable'=>false,'http_status'=>null,'error_code'=>$error->getMessage()];
        }
        if(!function_exists('curl_init'))return ['reachable'=>false,'http_status'=>null,'error_code'=>'curl_unavailable'];
        $curl=curl_init($url);
        if($curl===false)return ['reachable'=>false,'http_status'=>null,'error_code'=>'connection_failed'];
        $timeout=max(1,min(15,$timeout));
        $options=[
            CURLOPT_NOBODY=>true,CURLOPT_CUSTOMREQUEST=>'HEAD',CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'Desktop2Stereo-Portal-Channel-Check',
        ];
        if(!filter_var($host,FILTER_VALIDATE_IP)){
            $options[CURLOPT_RESOLVE]=[$host.':443:'.(str_contains($address,':')?'['.$address.']':$address)];
        }
        curl_setopt_array($curl,$options);
        $ok=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $errno=curl_errno($curl);
        curl_close($curl);
        if($ok===false||$errno!==0){
            $errorCode=$errno===28?'timeout':(in_array($errno,[35,51,53,54,58,60,66,77],true)?'tls_error':'connection_failed');
            return ['reachable'=>false,'http_status'=>$status?:null,'error_code'=>$errorCode];
        }
        $reachable=$status>=200&&$status<400;
        return ['reachable'=>$reachable,'http_status'=>$status,'error_code'=>$reachable?null:'http_'.$status];
    }

    private static function publicAddresses(string $host): array
    {
        if(filter_var($host,FILTER_VALIDATE_IP))$addresses=[$host];
        else{
            $addresses=[];
            foreach((gethostbynamel($host)?:[])as$ip)$addresses[]=$ip;
            foreach((dns_get_record($host,DNS_AAAA)?:[])as$record)if(is_string($record['ipv6']??null))$addresses[]=$record['ipv6'];
        }
        $addresses=array_values(array_unique($addresses));
        if($addresses===[])throw new RuntimeException('update_host_unresolvable');
        foreach($addresses as$address){
            if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false){
                throw new RuntimeException('update_host_not_public');
            }
        }
        return $addresses;
    }
}
