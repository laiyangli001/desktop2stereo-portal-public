<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use RuntimeException;

final readonly class WechatSettings
{
    public const DEFAULTS = [
        'enabled'=>'false','app_id'=>'','mchid'=>'','merchant_serial'=>'','private_key'=>'','api_v3_key'=>'',
        'verification_id'=>'','verification_key'=>'','previous_verification_id'=>'','previous_verification_key'=>'',
        'notify_url'=>'https://d2s.site/api/v2/wechat/notify',
        'refund_notify_url'=>'https://d2s.site/api/v2/wechat/refund-notify',
    ];
    public const SECRET_FIELDS = ['private_key','api_v3_key','verification_key','previous_verification_key'];
    public const ENVIRONMENT = [
        'enabled'=>'D2S_WECHAT_ENABLED','app_id'=>'D2S_WECHAT_APP_ID','mchid'=>'D2S_WECHAT_MCHID',
        'merchant_serial'=>'D2S_WECHAT_MERCHANT_SERIAL','private_key'=>'D2S_WECHAT_PRIVATE_KEY',
        'api_v3_key'=>'D2S_WECHAT_API_V3_KEY','verification_id'=>'D2S_WECHAT_VERIFICATION_ID',
        'verification_key'=>'D2S_WECHAT_VERIFICATION_KEY','previous_verification_id'=>'D2S_WECHAT_PREVIOUS_VERIFICATION_ID',
        'previous_verification_key'=>'D2S_WECHAT_PREVIOUS_VERIFICATION_KEY','notify_url'=>'D2S_WECHAT_NOTIFY_URL',
        'refund_notify_url'=>'D2S_WECHAT_REFUND_NOTIFY_URL',
    ];
    public const CHECKOUT_INTEGRATED = false;

    public function __construct(private PDO $db, private SecretBox $secrets) {}

    public function load(): array
    {
        $raw=$this->db->query("SELECT value_json FROM portal_settings WHERE name='wechat.v1'")->fetchColumn();
        $config=$this->decode($raw===false?null:$raw);
        self::validate($config);
        return $config;
    }

    public function view(int $adminId,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        $public=self::redact($this->load(),$now);
        Audit::admin($this->db,$adminId,$this->email($adminId),'VIEW_WECHAT_SETTINGS','configuration:wechat',$context,'SUCCESS',[],$now);
        return $public;
    }

    public function save(int $adminId,array $values,array $clear,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        if (!array_is_list($clear)||array_filter($clear,static fn($v)=>!is_string($v))!==[]||
            count(array_unique($clear))!==count($clear)||array_diff($clear,self::SECRET_FIELDS)!==[]||
            array_diff(array_keys($values),array_keys(self::DEFAULTS))!==[]) throw new Problem('wechat_setting_invalid');
        foreach($values as $field=>$value){
            if(!is_string($value)||strlen($value)>16384||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")||
                (!in_array($field,self::SECRET_FIELDS,true)&&preg_match('/[\x00-\x1f\x7f]/',$value))){
                throw new Problem('wechat_setting_invalid');
            }
        }
        return Database::transaction($this->db,function(PDO $db)use($adminId,$values,$clear,$context,$now):array{
            $db->exec("INSERT INTO portal_settings (name,value_json,updated_at) VALUES ('wechat.v1','',0) ON DUPLICATE KEY UPDATE name=name");
            $raw=$db->query("SELECT value_json FROM portal_settings WHERE name='wechat.v1' FOR UPDATE")->fetchColumn();
            $current=$this->decode($raw===''?null:$raw);
            foreach(['merchant_serial'=>'private_key','verification_id'=>'verification_key','previous_verification_id'=>'previous_verification_key']as$id=>$material){
                if(isset($values[$id])&&trim($values[$id])!==$current[$id]&&$current[$material]!==''&&
                    trim($values[$material]??'')===''&&!in_array($material,$clear,true)){
                    throw new Problem('wechat_rotation_material_required',409);
                }
            }
            foreach($values as$field=>$value){
                if(in_array($field,self::SECRET_FIELDS,true)&&trim($value)==='')continue;
                $current[$field]=trim($value);
            }
            foreach($clear as$field)$current[$field]='';
            self::validate($current);
            if($current['enabled']==='true'&&!self::CHECKOUT_INTEGRATED)throw new Problem('wechat_checkout_not_integrated',409);
            $plain=$current;$encrypted=[];
            foreach(self::SECRET_FIELDS as$field){
                if($current[$field]!=='')$encrypted[$field]=$this->secrets->encrypt($current[$field],'configuration:wechat:'.$field);
                unset($plain[$field]);
            }
            $db->prepare("UPDATE portal_settings SET value_json=?,updated_at=? WHERE name='wechat.v1'")
                ->execute([json_encode(['version'=>1,'values'=>$plain,'secrets'=>$encrypted],JSON_THROW_ON_ERROR),$now]);
            Audit::admin($db,$adminId,$this->email($adminId),'UPDATE_WECHAT_SETTINGS','configuration:wechat',$context,'SUCCESS',
                ['changed_fields'=>array_keys($values),'cleared_fields'=>$clear],$now);
            return self::redact($current,$now);
        });
    }

    public static function validate(array $config,bool $requireReady=false,?int $now=null): void
    {
        if(array_diff(array_keys(self::DEFAULTS),array_keys($config))!==[]||
            array_diff(array_keys($config),array_keys(self::DEFAULTS))!==[])throw new Problem('wechat_setting_invalid');
        foreach($config as$field=>$value){
            if(!is_string($value)||strlen($value)>16384||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")||
                (!in_array($field,self::SECRET_FIELDS,true)&&preg_match('/[\x00-\x1f\x7f]/',$value)))throw new Problem('wechat_setting_invalid');
        }
        if(!in_array($config['enabled'],['true','false'],true))throw new Problem('wechat_setting_invalid');
        foreach(['app_id'=>'/^wx[A-Za-z0-9]{16}$/D','mchid'=>'/^[0-9]{8,15}$/D','merchant_serial'=>'/^[A-Fa-f0-9]{1,40}$/D']as$field=>$pattern){
            if($config[$field]!==''&&!preg_match($pattern,$config[$field]))throw new Problem('wechat_identity_invalid');
        }
        if($config['api_v3_key']!==''&&!preg_match('/^[\x21-\x7e]{32}$/D',$config['api_v3_key']))throw new Problem('wechat_api_v3_key_invalid');
        if($config['private_key']!=='')WechatProtocol::rsa($config['private_key'],true);
        foreach(['verification_id'=>'verification_key','previous_verification_id'=>'previous_verification_key']as$id=>$material){
            if($config[$id]!==''&&!preg_match('/^(?:PUB_KEY_ID_[0-9]{10,64}|[A-Fa-f0-9]{1,40})$/D',$config[$id])){
                throw new Problem('wechat_verification_key_invalid');
            }
            if($config[$material]!==''){
                if($config[$id]==='')throw new Problem('wechat_verification_key_invalid');
                WechatProtocol::verificationKey($config[$id],$config[$material]);
            }
        }
        if($config['verification_id']!==''&&strcasecmp($config['verification_id'],$config['previous_verification_id'])===0){
            throw new Problem('wechat_verification_key_duplicate');
        }
        $allowed=array_map('trim',explode(',',getenv('D2S_ALLOWED_ORIGINS')?:'https://d2s.site,https://d2s.top'));
        foreach(['notify_url'=>'/api/v2/wechat/notify','refund_notify_url'=>'/api/v2/wechat/refund-notify']as$field=>$path){
            $url=parse_url($config[$field]);$origin=is_array($url)?($url['scheme']??'').'://'.($url['host']??''):'';
            if(!is_array($url)||($url['scheme']??'')!=='https'||!in_array($origin,$allowed,true)||
                ($url['path']??'')!==$path||array_diff(array_keys($url),['scheme','host','path'])!==[]){
                throw new Problem('wechat_callback_url_invalid');
            }
        }
        if($requireReady||$config['enabled']==='true'){
            foreach(['app_id','mchid','merchant_serial','private_key','api_v3_key','verification_id','verification_key']as$field){
                if($config[$field]==='')throw new Problem('wechat_configuration_incomplete',409);
            }
            WechatProtocol::verificationKey($config['verification_id'],$config['verification_key'],$now??time());
        }
    }

    public static function protocolConfiguration(array $config,int $now): array
    {
        self::validate($config,true,$now);
        $keys=[$config['verification_id']=>$config['verification_key']];
        if($config['previous_verification_key']!=='')$keys[$config['previous_verification_id']]=$config['previous_verification_key'];
        return ['mchid'=>$config['mchid'],'merchant_serial'=>$config['merchant_serial'],'private_key'=>$config['private_key'],
            'api_v3_key'=>$config['api_v3_key'],'verification_keys'=>$keys];
    }

    private function decode(?string $raw): array
    {
        if($raw===null){
            $config=self::DEFAULTS;
            foreach(self::ENVIRONMENT as$field=>$name)if(getenv($name)!==false)$config[$field]=getenv($name);
            return $config;
        }
        $stored=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($stored)||($stored['version']??null)!==1||!is_array($stored['values']??null)||!is_array($stored['secrets']??null)||
            array_diff(array_keys($stored),['version','values','secrets'])!==[]||
            array_diff(array_keys($stored['values']),array_diff(array_keys(self::DEFAULTS),self::SECRET_FIELDS))!==[]||
            array_diff(array_keys($stored['secrets']),self::SECRET_FIELDS)!==[])throw new RuntimeException('wechat_settings_corrupt');
        $config=self::DEFAULTS;
        foreach($stored['values']as$field=>$value){
            if(!is_string($value))throw new RuntimeException('wechat_settings_corrupt');$config[$field]=$value;
        }
        foreach($stored['secrets']as$field=>$value){
            if(!is_string($value))throw new RuntimeException('wechat_settings_corrupt');
            $config[$field]=$this->secrets->decrypt($value,'configuration:wechat:'.$field);
        }
        return $config;
    }

    private static function redact(array $config,int $now): array
    {
        $values=$config;$present=[];
        foreach(self::SECRET_FIELDS as$field){$present[$field]=$config[$field]!=='';unset($values[$field]);}
        $ready=true;try{self::validate($config,true,$now);}catch(Problem){$ready=false;}
        return ['values'=>$values,'secrets_configured'=>$present,'gateway'=>WechatProtocol::API_ORIGIN,
            'configuration_ready'=>$ready,'checkout_integrated'=>self::CHECKOUT_INTEGRATED];
    }

    private function email(int $id): string
    {
        $query=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$query->execute([$id]);
        $email=$query->fetchColumn();if(!is_string($email))throw new Problem('administrator_unavailable',403);return $email;
    }
}
