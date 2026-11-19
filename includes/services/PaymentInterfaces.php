<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class PaymentInterfaces
{
    private const NAMES=['paypal'=>'PayPal','stripe'=>'Stripe','wechat_native'=>'微信支付','alipay_pc'=>'支付宝'];
    public function __construct(private PDO $db,private SecretBox $secrets) {}

    private function selected(string $provider): ?array
    {
        $q=$this->db->prepare('SELECT id,enabled,currencies_json FROM portal_payment_configurations WHERE provider=? ORDER BY enabled DESC,priority,id LIMIT 1');
        $q->execute([$provider]);return $q->fetch()?:null;
    }

    public function view(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        $registry=new PaymentConfigurations($this->db,$this->secrets);$items=[];
        foreach(self::NAMES as$provider=>$name){
            $row=$this->selected($provider);
            $config=$row?$registry->configuration($row['id'])['configuration']:
                match($provider){
                    'alipay_pc'=>(new AlipaySettings($this->db,$this->secrets))->load(),
                    'wechat_native'=>(new WechatSettings($this->db,$this->secrets))->load(),
                    default=>PaymentConfigurations::fields($provider),
                };
            $enabled=$row?(bool)$row['enabled']:(($config['enabled']??'false')==='true');
            $present=[];foreach(PaymentConfigurations::secrets($provider)as$key){$present[$key]=($config[$key]??'')!=='';unset($config[$key]);}
            unset($config['enabled']);
            $id=$row['id']??self::id($provider);
            $items[]=['provider'=>$provider,'enabled'=>$enabled,'configuration'=>$config,'secret_set'=>$present,
                'currencies'=>$row?json_decode($row['currencies_json'],true,8,JSON_THROW_ON_ERROR):($provider==='stripe'?['CNY','USD']:[$provider==='paypal'?'USD':'CNY']),
                'webhook_url'=>in_array($provider,['paypal','stripe'],true)?rtrim(getenv('D2S_APP_URL')?:'','/').'/api/v2/payments/'.$provider.'/'.$id.'/webhook':null];
        }
        return ['items'=>$items,'fields'=>array_combine(PaymentConfigurations::PROVIDERS,array_map(PaymentConfigurations::fields(...),PaymentConfigurations::PROVIDERS)),
            'secret_fields'=>array_combine(PaymentConfigurations::PROVIDERS,array_map(PaymentConfigurations::secrets(...),PaymentConfigurations::PROVIDERS))];
    }

    public function save(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        $provider=$input['provider']??null;
        if(!is_string($provider)||!isset(self::NAMES[$provider]))throw new Problem('payment_provider_invalid');
        if(!is_bool($input['enabled']??null)||!is_array($input['configuration']??null))throw new Problem('payment_configuration_invalid');
        $row=$this->selected($provider);$values=$input['configuration'];
        // Import the existing native configuration on the server without exposing its secrets.
        if(!$row&&in_array($provider,['alipay_pc','wechat_native'],true)){
            $legacy=$provider==='alipay_pc'?(new AlipaySettings($this->db,$this->secrets))->load():(new WechatSettings($this->db,$this->secrets))->load();
            foreach(PaymentConfigurations::secrets($provider)as$key)if(($values[$key]??'')==='')$values[$key]=$legacy[$key];
            $values=array_replace($legacy,$values);
        }
        $currencies=$provider==='stripe'?($input['currencies']??['CNY','USD']):[$provider==='paypal'?'USD':'CNY'];
        return (new PaymentConfigurations($this->db,$this->secrets))->save($adminId,[
            'id'=>$row['id']??self::id($provider),'provider'=>$provider,'label'=>self::NAMES[$provider],
            'priority'=>0,'enabled'=>$input['enabled'],'currencies'=>$currencies,'configuration'=>$values,
        ],$context,$now,true);
    }

    private static function id(string $provider): string
    {
        return substr(hash('sha256','payment-interface:v1:'.$provider),0,32);
    }
}
