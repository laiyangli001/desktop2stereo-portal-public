<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

final class NtpProbe
{
    private const NTP_EPOCH_OFFSET = 2208988800;
    private const ERA_SECONDS = 4294967296;

    public static function parseServers(string $raw): array
    {
        $entries=0;$endpoints=[];$invalid=[];
        foreach(explode(',',$raw)as$entry){
            $entry=trim($entry);
            if($entry==='')continue;
            $entries++;
            $server=$entry;$port=123;$bracketed=false;
            if(preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/D',$entry,$match)){
                $server=$match[1];$bracketed=true;
                if(isset($match[2])&&$match[2]!=='')$port=(int)$match[2];
            }elseif(substr_count($entry,':')===1){
                [$server,$portText]=explode(':',$entry,2);$port=(int)$portText;
            }
            $validIp=filter_var($server,FILTER_VALIDATE_IP)!==false;
            $validBracketedIp=!$bracketed||filter_var($server,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false;
            if((!$validIp&&!self::validHostname($server))||!$validBracketedIp||
                $port<1||$port>65535){
                $invalid[]=$entry;
                continue;
            }
            $endpoints[]=['server'=>$server,'port'=>$port];
        }
        return ['entries'=>$entries,'endpoints'=>$endpoints,'invalid'=>$invalid];
    }

    public static function validatePolicy(array $endpoints,int $maxOffsetMs,int $quorum,int $timeoutMs,
        bool $allowEmpty=false): void
    {
        $validRanges=$maxOffsetMs>=1&&$maxOffsetMs<=600000&&$timeoutMs>=100&&$timeoutMs<=10000;
        if(!$validRanges)throw new RuntimeException('ntp_policy_invalid');
        if($endpoints===[]){
            if($allowEmpty&&$quorum>=0)return;
            throw new RuntimeException('ntp_policy_invalid');
        }
        if($quorum<1||$quorum>count($endpoints))throw new RuntimeException('ntp_policy_invalid');
        foreach($endpoints as$endpoint){
            if(!is_array($endpoint)||!isset($endpoint['server'],$endpoint['port'])||
                !is_string($endpoint['server'])||!is_int($endpoint['port'])||
                (!filter_var($endpoint['server'],FILTER_VALIDATE_IP)&&!self::validHostname($endpoint['server']))||
                $endpoint['port']<1||$endpoint['port']>65535){
                throw new RuntimeException('ntp_policy_invalid');
            }
        }
    }

    public static function check(array $endpoints,int $maxOffsetMs,int $quorum,int $timeoutMs): array
    {
        self::validatePolicy($endpoints,$maxOffsetMs,$quorum,$timeoutMs);
        $results=[];$healthy=0;
        foreach($endpoints as$endpoint){
            try{
                $probe=self::probe((string)$endpoint['server'],(int)$endpoint['port'],$timeoutMs);
                $ok=abs($probe['offset_ms'])<=$maxOffsetMs;
                if($ok)$healthy++;
                $results[]=$probe+['ok'=>$ok,'error'=>null];
            }catch(\Throwable $error){
                $results[]=['server'=>$endpoint['server'],'port'=>$endpoint['port'],'ok'=>false,
                    'offset_ms'=>null,'delay_ms'=>null,'stratum'=>null,'error'=>$error->getMessage()];
            }
        }
        return ['ok'=>$healthy>=$quorum,'healthy'=>$healthy,'quorum'=>$quorum,
            'max_offset_ms'=>$maxOffsetMs,'results'=>$results];
    }

    public static function probe(string $server,int $port,int $timeoutMs): array
    {
        if($server==='' || str_contains($server,"\0") || $port<1 || $port>65535 || $timeoutMs<100 || $timeoutMs>10000){
            throw new RuntimeException('ntp_endpoint_invalid');
        }
        $host=str_contains($server,':')?'['.$server.']':$server;
        $socket=@stream_socket_client('udp://'.$host.':'.$port,$errorCode,$errorText,$timeoutMs/1000,STREAM_CLIENT_CONNECT);
        if($socket===false)throw new RuntimeException('ntp_socket_failed');
        stream_set_blocking($socket,true);
        stream_set_timeout($socket,intdiv($timeoutMs,1000),($timeoutMs%1000)*1000);
        try{
            $t1=microtime(true);
            $request=str_repeat("\0",48);
            $request[0]=chr(0x1B);
            $origin=self::encodeTimestamp($t1);
            $request=substr_replace($request,$origin,40,8);
            if(stream_socket_sendto($socket,$request,0,$host.':'.$port)!==48)throw new RuntimeException('ntp_send_failed');
            $peer=null;
            $response=@stream_socket_recvfrom($socket,512,0,$peer);
            $t4=microtime(true);
            if(!is_string($response)||strlen($response)!==48)throw new RuntimeException('ntp_timeout');
            if((ord($response[0])&0x7)!==4 || ord($response[1])<1 || ord($response[1])>15){
                throw new RuntimeException('ntp_response_invalid');
            }
            if(!hash_equals($origin,substr($response,24,8)))throw new RuntimeException('ntp_origin_mismatch');
            $t2=self::decodeTimestamp(substr($response,32,8),$t4);
            $t3=self::decodeTimestamp(substr($response,40,8),$t4);
            $offset=($t2-$t1+$t3-$t4)/2;
            $delay=($t4-$t1)-($t3-$t2);
            if(!is_finite($offset)||!is_finite($delay)||$delay<0||$delay>10)throw new RuntimeException('ntp_result_invalid');
            return ['server'=>$server,'port'=>$port,'offset_ms'=>round($offset*1000,3),
                'delay_ms'=>round($delay*1000,3),'stratum'=>ord($response[1])];
        }finally{
            fclose($socket);
        }
    }

    private static function validHostname(string $server): bool
    {
        if($server===''||strlen($server)>253||str_contains($server,"\0")||
            preg_match('/^[0-9.]+$/D',$server))return false;
        $labels=explode('.',$server);
        foreach($labels as$label){
            if(!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D',$label))return false;
        }
        return true;
    }

    private static function encodeTimestamp(float $time): string
    {
        $seconds=$time+self::NTP_EPOCH_OFFSET;
        $whole=(int)floor($seconds);
        $fraction=(int)round(($seconds-$whole)*self::ERA_SECONDS);
        if($fraction>=self::ERA_SECONDS){$whole++;$fraction=0;}
        return pack('N2',$whole & 0xFFFFFFFF,$fraction & 0xFFFFFFFF);
    }

    private static function decodeTimestamp(string $value,float $reference): float
    {
        if(strlen($value)!==8)throw new RuntimeException('ntp_timestamp_invalid');
        $parts=unpack('Nseconds/Nfraction',$value);
        $base=$reference+self::NTP_EPOCH_OFFSET;
        $seconds=(int)$parts['seconds'];
        $era=(int)floor($base/self::ERA_SECONDS)*self::ERA_SECONDS;
        $candidate=$era+$seconds;
        if($candidate-$base>self::ERA_SECONDS/2)$candidate-=self::ERA_SECONDS;
        if($base-$candidate>self::ERA_SECONDS/2)$candidate+=self::ERA_SECONDS;
        return $candidate-self::NTP_EPOCH_OFFSET+(int)$parts['fraction']/self::ERA_SECONDS;
    }
}
