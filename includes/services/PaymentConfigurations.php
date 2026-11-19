<?php
declare(strict_types=1);
namespace D2S\services;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class PaymentConfigurations
{
    public const PROVIDERS=['alipay_pc','wechat_native','paypal','stripe'];
    public const DEFAULTS=[
        'paypal'=>['environment'=>'sandbox','client_id'=>'','client_secret'=>'','merchant_id'=>'','webhook_id'=>''],
        'stripe'=>['environment'=>'test','secret_key'=>'','webhook_secret'=>'','account_id'=>''],
    ];
    public function __construct(private PDO $db,private SecretBox $secrets) {}

    public static function fields(string $provider): array
    {
        return match($provider){
            'alipay_pc'=>AlipaySettings::DEFAULTS,'wechat_native'=>WechatSettings::DEFAULTS,
            'paypal','stripe'=>self::DEFAULTS[$provider],default=>throw new Problem('payment_provider_invalid'),
        };
    }

    public static function secrets(string $provider): array
    {
        return match($provider){
            'alipay_pc'=>AlipaySettings::SECRET_FIELDS,'wechat_native'=>WechatSettings::SECRET_FIELDS,
            'paypal'=>['client_secret'],'stripe'=>['secret_key','webhook_secret'],default=>[],
        };
    }

    public function view(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        $rows=$this->db->query('SELECT * FROM portal_payment_configurations ORDER BY provider,priority,id')->fetchAll();
        foreach($rows as &$row){
            $config=$this->decode($row);
            $row['secret_set']=[];
            foreach(self::secrets($row['provider'])as$key){$row['secret_set'][$key]=($config[$key]??'')!=='';unset($config[$key]);}
            unset($row['config_cipher']);$row['configuration']=$config;$row['currencies']=json_decode($row['currencies_json'],true,8,JSON_THROW_ON_ERROR);unset($row['currencies_json']);
            $row['webhook_url']=rtrim(getenv('D2S_APP_URL')?:'', '/').'/api/v2/payments/'.$row['provider'].'/'.$row['id'].'/webhook';
        }
        return ['items'=>$rows,'fields'=>array_combine(self::PROVIDERS,array_map(self::fields(...),self::PROVIDERS)),
            'secret_fields'=>array_combine(self::PROVIDERS,array_map(self::secrets(...),self::PROVIDERS))];
    }

    public function save(int $adminId,array $input,array $context,int $now,bool $singleInterface=false): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        $provider=$input['provider']??'';if(!in_array($provider,self::PROVIDERS,true))throw new Problem('payment_provider_invalid');
        $id=empty($input['id'])?bin2hex(random_bytes(16)):Backoffice::hexId($input['id']);
        $label=Backoffice::text($input,'label',100,true);$currencies=$input['currencies']??[];
        if(!is_array($currencies)||!array_is_list($currencies)||$currencies===[]||array_diff($currencies,['CNY','USD'])!==[])throw new Problem('currency_invalid');
        if(in_array($provider,['alipay_pc','wechat_native'],true)&&$currencies!==['CNY'])throw new Problem('currency_invalid');
        if($provider==='paypal'&&$currencies!==['USD'])throw new Problem('currency_invalid');
        $enabled=$input['enabled']??false;$priority=$input['priority']??100;
        if(!is_bool($enabled)||!is_int($priority)||$priority<0||$priority>10000)throw new Problem('payment_configuration_invalid');
        $values=$input['configuration']??[];
        if(!is_array($values)||array_diff(array_keys($values),array_keys(self::fields($provider))))throw new Problem('payment_configuration_invalid');
        foreach($values as$value)if(!is_string($value)||strlen($value)>16384||str_contains($value,"\0"))throw new Problem('payment_configuration_invalid');
        return Database::transaction($this->db,function(PDO $db)use($adminId,$input,$context,$now,$provider,$id,$label,$currencies,$enabled,$priority,$values,$singleInterface):array{
            $q=$db->prepare('SELECT * FROM portal_payment_configurations WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();
            if($old&&$old['provider']!==$provider)throw new Problem('payment_provider_immutable',409);
            $config=$old?$this->decode($old):self::fields($provider);
            foreach($values as$key=>$value){if($value===''&&in_array($key,self::secrets($provider),true))continue;$config[$key]=trim($value);}
            if(isset($config['enabled']))$config['enabled']=$enabled?'true':'false';
            self::validate($provider,$config,$enabled);
            if($old&&$old['enabled']&&$enabled){
                $before=$this->identity($provider,$this->decode($old));$after=$this->identity($provider,$config);
                if($before!==$after)throw new Problem('payment_identity_immutable_while_enabled',409);
            }
            $cipher=$this->secrets->encrypt(json_encode($config,JSON_THROW_ON_ERROR),'payment-configuration:'.$id);
            $db->prepare('INSERT INTO portal_payment_configurations(id,provider,label,enabled,priority,currencies_json,config_cipher,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),enabled=VALUES(enabled),priority=VALUES(priority),
                    currencies_json=VALUES(currencies_json),config_cipher=VALUES(config_cipher),updated_at=VALUES(updated_at)')
                ->execute([$id,$provider,$label,$enabled?1:0,$priority,json_encode(array_values(array_unique($currencies)),JSON_THROW_ON_ERROR),$cipher,$now,$now]);
            if($singleInterface)$db->prepare('UPDATE portal_payment_configurations SET enabled=0,updated_at=? WHERE provider=? AND id<>?')->execute([$now,$provider,$id]);
            (new Backoffice($db))->audit($adminId,'SAVE_PAYMENT_CONFIGURATION',$id,['provider'=>$provider,'enabled'=>$enabled,'label'=>$label],$context,$now);
            return ['id'=>$id];
        });
    }

    public function available(): array
    {
        $q=$this->db->query('SELECT id,provider,label,currencies_json FROM portal_payment_configurations WHERE enabled=1 ORDER BY priority,id');
        $rows=$q->fetchAll();
        foreach($rows as &$row){$row['currencies']=json_decode($row['currencies_json'],true,8,JSON_THROW_ON_ERROR);unset($row['currencies_json']);}
        return $rows;
    }

    public function resolve(string $provider,string $currency,?string $id=null): ?array
    {
        if(!in_array($provider,self::PROVIDERS,true))throw new Problem('payment_provider_invalid');
        $query='SELECT * FROM portal_payment_configurations WHERE provider=? AND enabled=1';$args=[$provider];
        if($id!==null){Backoffice::hexId($id);$query.=' AND id=?';$args[]=$id;}
        $query.=' ORDER BY priority,id';
        $q=$this->db->prepare($query);$q->execute($args);
        foreach($q->fetchAll()as$row){
            if(!in_array($currency,json_decode($row['currencies_json'],true,8,JSON_THROW_ON_ERROR),true))continue;
            $config=$this->decode($row);self::validate($provider,$config,true);
            return ['id'=>$row['id'],'provider'=>$provider,'label'=>$row['label'],'configuration'=>$config];
        }
        if($id!==null)throw new Problem('payment_configuration_unavailable',409);
        return null;
    }

    public function configuration(string $id): array
    {
        Backoffice::hexId($id);
        $q=$this->db->prepare('SELECT * FROM portal_payment_configurations WHERE id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new Problem('payment_configuration_not_found',404);
        return ['id'=>$id,'provider'=>$row['provider'],'configuration'=>$this->decode($row)];
    }

    public function currentNative(string $provider,?string $id=null): array
    {
        if(!in_array($provider,['alipay_pc','wechat_native'],true))throw new Problem('payment_provider_invalid');
        $choice=$this->resolve($provider,'CNY',$id);
        if($choice!==null)return$choice['configuration'];
        $q=$this->db->prepare('SELECT * FROM portal_payment_configurations WHERE provider=? ORDER BY priority,id LIMIT 1');
        $q->execute([$provider]);$row=$q->fetch();
        if($row){$config=$this->decode($row);$config['enabled']='false';return$config;}
        return $provider==='alipay_pc'?(new AlipaySettings($this->db,$this->secrets))->load():(new WechatSettings($this->db,$this->secrets))->load();
    }

    public function matchingWechat(array $archive): ?array
    {
        $q=$this->db->query("SELECT * FROM portal_payment_configurations WHERE provider='wechat_native' ORDER BY updated_at DESC,id");
        foreach($q->fetchAll()as$row){$config=$this->decode($row);if($config['app_id']===$archive['app_id']&&$config['mchid']===$archive['mchid'])return$config;}
        return null;
    }

    private function decode(array $row): array
    {
        return json_decode($this->secrets->decrypt($row['config_cipher'],'payment-configuration:'.$row['id']),true,32,JSON_THROW_ON_ERROR);
    }

    private function identity(string $provider,array $config): array
    {
        return match($provider){'alipay_pc'=>[$config['environment'],$config['app_id'],$config['seller_id']],
            'wechat_native'=>[$config['app_id'],$config['mchid']],'paypal'=>[$config['environment'],$config['client_id'],$config['merchant_id']],
            'stripe'=>[$config['environment'],$config['account_id']]};
    }

    public static function validate(string $provider,array $config,bool $enabled): void
    {
        if($provider==='alipay_pc'){AlipaySettings::validate($config,$enabled);return;}
        if($provider==='wechat_native'){WechatSettings::validate($config,$enabled);return;}
        if(!isset(self::DEFAULTS[$provider])||array_diff(array_keys($config),array_keys(self::DEFAULTS[$provider])))throw new Problem('payment_configuration_invalid');
        if(!in_array($config['environment'],$provider==='paypal'?['sandbox','production']:['test','production'],true))throw new Problem('payment_environment_invalid');
        foreach($config as$value)if(!is_string($value)||strlen($value)>4096||preg_match('/[\x00-\x20\x7F]/',$value))throw new Problem('payment_configuration_invalid');
        if(!$enabled)return;
        foreach(array_keys(self::DEFAULTS[$provider])as$key)if($config[$key]==='')throw new Problem('payment_configuration_incomplete',409);
        if($provider==='stripe'){
            $prefix=$config['environment']==='test'?'sk_test_':'sk_live_';
            if(!str_starts_with($config['secret_key'],$prefix)||!str_starts_with($config['webhook_secret'],'whsec_')||
                !preg_match('/^acct_[A-Za-z0-9]+$/D',$config['account_id']))throw new Problem('stripe_configuration_invalid');
        }
        if($provider==='paypal'&&(!preg_match('/^[A-Za-z0-9_-]{10,255}$/D',$config['client_id'])||
            !preg_match('/^[A-Z0-9]{8,32}$/D',$config['merchant_id'])||!preg_match('/^[A-Z0-9-]{5,100}$/D',$config['webhook_id'])))throw new Problem('paypal_configuration_invalid');
    }
}
