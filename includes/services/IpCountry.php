<?php
declare(strict_types=1);
namespace D2S\services;

use MaxMind\Db\Reader;

final class IpCountry
{
    private ?Reader $reader = null;
    private array $cache = [];

    public function __construct(?string $path = null)
    {
        $path ??= getenv('D2S_GEOIP_DATABASE') ?: D2S_ROOT.'/storage/geoip/country.mmdb';
        if (is_readable($path) && class_exists(Reader::class)) {
            try { $this->reader = new Reader($path); } catch (\Throwable) {}
        }
    }

    public function lookup(?string $ip): array
    {
        if ($ip === null || filter_var($ip,FILTER_VALIDATE_IP) === false) {
            return ['kind'=>'unknown','country_code'=>null,'name_zh'=>'未知','name_en'=>'Unknown'];
        }
        if (isset($this->cache[$ip])) return $this->cache[$ip];
        if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE) === false) {
            $packed=inet_pton($ip);
            $loopback=$packed===inet_pton('::1')||(strlen($packed)===4&&ord($packed[0])===127);
            return $this->cache[$ip]=['kind'=>'local','country_code'=>null,
                'local_scope'=>$loopback?'loopback':'private',
                'name_zh'=>$loopback?'本机地址':'内网 / 保留地址',
                'name_en'=>$loopback?'Loopback address':'Private / reserved address'];
        }
        if ($this->reader === null) {
            return $this->cache[$ip]=['kind'=>'unavailable','country_code'=>null,'name_zh'=>'归属地数据库未配置','name_en'=>'Geolocation database unavailable'];
        }
        try {
            $record=$this->reader->get($ip);
            $country=$record['country']??null;
            $code=$country['iso_code']??null;
            if(is_string($code)&&preg_match('/^[A-Z]{2}$/D',$code)){
                return $this->cache[$ip]=[
                    'kind'=>'country','country_code'=>$code,
                    'name_zh'=>$country['names']['zh-CN']??$country['names']['en']??$code,
                    'name_en'=>$country['names']['en']??$code,
                ];
            }
        } catch (\Throwable) {}
        return $this->cache[$ip]=['kind'=>'unknown','country_code'=>null,'name_zh'=>'未知','name_en'=>'Unknown'];
    }

    public function metadata(): array
    {
        return ['ready'=>$this->reader!==null,'build_time'=>$this->reader?->metadata()->buildEpoch,
            'source'=>'DB-IP Lite','attribution_url'=>'https://db-ip.com','precision'=>'country'];
    }
}
