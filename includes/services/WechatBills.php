<?php
declare(strict_types=1);
namespace D2S\services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use D2S\domain\Problem;
use PDO;

/** Apply, verify and stream a GZIP trade bill without retaining its bearer URL or file. */
final readonly class WechatBills
{
    public const MAXIMUM_BYTES=33554432;
    public const HOST='api.mch.weixin.qq.com';

    public function __construct(private PDO $db,private WechatProfiles $profiles,private ?Closure $transport=null,
        private ?Closure $resolver=null,private ?Closure $fetcher=null) {}

    public function download(int $adminId,string $date,?string $orderId,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'bills.read');
        $q=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$adminId]);$email=$q->fetchColumn();
        if(!is_string($email))throw new Problem('admin_auth_invalid',401);
        self::date($date,$now);
        if($orderId!==null){
            if(!preg_match('/^[a-f0-9]{32}$/D',$orderId))throw new Problem('order_id_invalid');
            $q=$this->db->prepare("SELECT id FROM portal_orders WHERE id=? AND payment_method='wechat_native'");$q->execute([$orderId]);
            if($q->fetchColumn()===false)throw new Problem('order_not_found',404);
        }
        $details=['bill_date'=>$date,'bill_type'=>'trade','channel'=>'wechat','source_order'=>$orderId];
        Audit::admin($this->db,$adminId,$email,'DOWNLOAD_BILL',$date,$context,'PENDING',$details+['phase'=>'intent'],$now);
        try{
            $archive=$orderId===null?null:$this->profiles->request($orderId);
            $config=$archive===null?$this->profiles->currentConfiguration():$archive['config'];
            $metadata=(new WechatClient($config,$now,$this->transport,$archive['verification_keys']??null))->bill($date,$now);
            $url=self::url($metadata['download_url']);
            $addresses=$this->resolver===null?self::resolve():($this->resolver)(self::HOST);
            if(!is_array($addresses)||$addresses===[]||count($addresses)>32)throw new Problem('bill_host_unavailable',502);
            foreach($addresses as$ip)if(!self::publicAddress($ip))throw new Problem('bill_host_unavailable',502);
            $body=$this->fetcher===null?self::fetch($url,$addresses[0]):($this->fetcher)($url,$addresses[0]);
            if(!is_string($body)||strlen($body)<20||strlen($body)>self::MAXIMUM_BYTES||
                !str_starts_with($body,"\x1f\x8b\x08")||!hash_equals($metadata['hash_value'],sha1($body))){
                throw new Problem('bill_file_invalid',502);
            }
            Audit::admin($this->db,$adminId,$email,'DOWNLOAD_BILL',$date,$context,'SUCCESS',
                $details+['phase'=>'result','bytes'=>strlen($body),'sha1'=>$metadata['hash_value'],'sha256'=>hash('sha256',$body),
                    'app_id'=>$config['app_id'],'mchid'=>$config['mchid']],$now);
            return ['filename'=>'wechat_trade_'.$date.'.csv.gz','content_type'=>'application/gzip','body'=>$body];
        }catch(\Throwable $error){
            Audit::admin($this->db,$adminId,$email,'DOWNLOAD_BILL',$date,$context,'FAILURE',$details+['phase'=>'result'],$now);
            throw $error;
        }
    }

    public static function date(string $date,int $now): void
    {
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date))throw new Problem('bill_date_invalid');
        $zone=new DateTimeZone('Asia/Shanghai');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
        $today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0);
        $minimum=$today->modify('-6 years');
        if($parsed===false||$parsed->format('Y-m-d')!==$date||$parsed<$minimum||$parsed>=$today)throw new Problem('bill_date_invalid');
    }

    public static function url(mixed $url): string
    {
        if(!is_string($url)||strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new Problem('bill_url_invalid',502);
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'||($parts['host']??null)!==self::HOST||
            ($parts['path']??null)!=='/v3/bill/downloadurl'||isset($parts['user'])||isset($parts['pass'])||
            isset($parts['port'])||isset($parts['fragment'])||
            !preg_match('/^token=[A-Za-z0-9_.~%+\/=-]{1,1536}$/D',$parts['query']??'')||
            preg_match('/%(?![a-fA-F0-9]{2})/',$parts['query'])){
            throw new Problem('bill_url_invalid',502);
        }
        return $url;
    }

    public static function publicAddress(mixed $ip): bool
    {
        if(!is_string($ip)||str_contains($ip,'%')||str_starts_with(strtolower($ip),'::ffff:')||
            filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)===false)return false;
        $packed=inet_pton($ip);
        return $packed!==false&&(strlen($packed)===4?ord($packed[0])<224:ord($packed[0])!==255);
    }

    private static function resolve(): array
    {
        $records=dns_get_record(self::HOST,DNS_A|DNS_AAAA);
        if(!is_array($records))throw new Problem('bill_host_unavailable',502);
        $addresses=[];
        foreach($records as$record){$ip=$record['ip']??$record['ipv6']??null;if(is_string($ip))$addresses[]=$ip;}
        return array_values(array_unique($addresses));
    }

    private static function fetch(string $url,string $ip): string
    {
        $body='';$pin=str_contains($ip,':')?'['.$ip.']':$ip;$curl=curl_init($url);
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
            CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[self::HOST.':443:'.$pin],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>self::MAXIMUM_BYTES)return 0;
                $body.=$chunk;return strlen($chunk);
            },
        ]);
        $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false||$status!==200)throw new Problem('bill_download_failed',502);
        return $body;
    }
}
