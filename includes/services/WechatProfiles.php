<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;
use RuntimeException;

final readonly class WechatProfiles
{
    public function __construct(private PDO $db,private SecretBox $secrets,private ?string $configurationId=null) {}

    public function available(): bool
    {
        $config=$this->currentConfiguration();
        if($config['enabled']!=='true')return false;
        WechatSettings::validate($config,true);
        return true;
    }

    /** Internal read-only configuration for channel operations that do not use an order snapshot. */
    public function currentConfiguration(): array
    {
        return (new PaymentConfigurations($this->db,$this->secrets))->currentNative('wechat_native',$this->configurationId);
    }

    /** Caller holds account/order locks and owns the transaction. */
    public function capture(string $id,int $now): string
    {
        if(!$this->db->inTransaction())throw new RuntimeException('payment_profile_transaction_required');
        $old=$this->db->prepare("SELECT config_cipher FROM portal_payment_attempts WHERE id=? AND provider='wechat_native'");
        $old->execute([$id]);$existing=$old->fetchColumn();
        if(is_string($existing))return $this->reference($id,$existing);
        $order=$this->db->prepare("SELECT id FROM portal_orders WHERE id=? AND payment_method='wechat_native' AND currency='CNY'");
        $order->execute([$id]);if($order->fetchColumn()===false)throw new Problem('wechat_order_invalid');
        $choice=(new PaymentConfigurations($this->db,$this->secrets))->resolve('wechat_native','CNY',$this->configurationId);
        $config=$choice['configuration']??(new PaymentConfigurations($this->db,$this->secrets))->currentNative('wechat_native');
        if($choice!==null)$this->db->prepare('UPDATE portal_orders SET payment_configuration_id=? WHERE id=?')->execute([$choice['id'],$id]);
        if($config['enabled']!=='true')throw new Problem('payment_method_unavailable',409);
        WechatSettings::validate($config,true,$now);
        ksort($config,SORT_STRING);
        $json=json_encode($config,JSON_THROW_ON_ERROR);
        $profile=$this->secrets->digest('wechat-profile:'.$json);
        $cipher=$this->secrets->encrypt($json,'wechat-profile:'.$profile);
        $this->db->prepare('INSERT IGNORE INTO portal_wechat_profiles (id,app_id,mchid,config_cipher,created_at) VALUES (?,?,?,?,?)')
            ->execute([$profile,$config['app_id'],$config['mchid'],$cipher,$now]);
        $this->read($profile); // Verify a reused immutable archive before linking another order.
        $pointer=$this->secrets->encrypt($profile,'wechat-order-profile:'.$id);
        $this->db->prepare('INSERT INTO portal_payment_attempts (id,provider,environment,app_id,seller_id,config_cipher,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id,'wechat_native','production',$config['app_id'],$config['mchid'],$pointer,$now]);
        return $profile;
    }

    public function forOrder(string $id): array
    {
        $select=$this->db->prepare("SELECT config_cipher FROM portal_payment_attempts WHERE id=? AND provider='wechat_native'");
        $select->execute([$id]);$cipher=$select->fetchColumn();
        if(!is_string($cipher))throw new Problem('payment_attempt_not_found',404);
        $profile=$this->reference($id,$cipher);
        return ['id'=>$profile,'config'=>$this->read($profile)];
    }

    public function read(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new Problem('wechat_profile_invalid');
        $select=$this->db->prepare('SELECT * FROM portal_wechat_profiles WHERE id=? FOR SHARE');$select->execute([$id]);$row=$select->fetch();
        if(!$row)throw new Problem('wechat_profile_not_found',404);
        $json=$this->secrets->decrypt($row['config_cipher'],'wechat-profile:'.$id);
        if(!hash_equals($id,$this->secrets->digest('wechat-profile:'.$json)))throw new RuntimeException('wechat_profile_corrupt');
        $config=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($config)||($config['app_id']??null)!==$row['app_id']||($config['mchid']??null)!==$row['mchid']){
            throw new RuntimeException('wechat_profile_corrupt');
        }
        return $config;
    }

    /** Try only trusted current/archive material for the same merchant and application. */
    public function notification(string $id,array $headers,string $body,int $now): array
    {
        $archive=$this->read($id);$configs=[$archive];$current=$this->current($archive);
        $ring=$this->keys($archive,$current);
        if($current!==null&&$current['api_v3_key']!==''&&$current['api_v3_key']!==$archive['api_v3_key']){
            $updated=$archive;$updated['api_v3_key']=$current['api_v3_key'];$configs[]=$updated;
        }
        $last=null;
        foreach($configs as$config){
            try{
                $protocol=new WechatProtocol(['mchid'=>$archive['mchid'],'merchant_serial'=>$archive['merchant_serial'],
                    'private_key'=>$archive['private_key'],'api_v3_key'=>$config['api_v3_key'],'verification_keys'=>$ring]);
                return ['config'=>$archive,'event'=>$protocol->notification($headers,$body,$now)];
            }catch(Problem $error){
                if($error->error!=='wechat_decryption_failed')throw $error;$last=$error;
            }
        }
        throw $last??new Problem('wechat_notification_invalid');
    }

    /** Existing-order requests may rotate credentials but never change merchant identity. */
    public function request(string $id): array
    {
        $archive=$this->forOrder($id)['config'];$current=$this->current($archive);
        $ring=$this->keys($archive,$current);$config=$archive;$config['enabled']='false';
        if($current!==null){
            if($current['private_key']!==''&&$current['merchant_serial']!==''){
                $config['private_key']=$current['private_key'];$config['merchant_serial']=$current['merchant_serial'];
            }
            if($current['verification_id']!==''&&$current['verification_key']!==''){
                $config['verification_id']=$current['verification_id'];$config['verification_key']=$current['verification_key'];
                $config['previous_verification_id']='';$config['previous_verification_key']='';
                foreach($ring as$serial=>$pem){
                    if((string)$serial===$config['verification_id'])continue;
                    $config['previous_verification_id']=(string)$serial;$config['previous_verification_key']=$pem;break;
                }
            }
        }
        return ['config'=>$config,'verification_keys'=>$ring];
    }

    private function current(array $archive): ?array
    {
        try{
            $candidate=(new PaymentConfigurations($this->db,$this->secrets))->matchingWechat($archive)??(new WechatSettings($this->db,$this->secrets))->load();
            if($candidate['app_id']===$archive['app_id']&&$candidate['mchid']===$archive['mchid'])return $candidate;
        }catch(\Throwable){ /* Historical operations do not depend on live settings availability. */ }
        return null;
    }

    private function keys(array $archive,?array $current): array
    {
        $ring=[];
        foreach([$archive,$current]as$config){
            if($config===null)continue;
            foreach(['verification_id'=>'verification_key','previous_verification_id'=>'previous_verification_key']as$serial=>$material){
                if($config[$serial]===''||$config[$material]==='')continue;
                $key=$config[$serial];$pem=$config[$material];
                if(isset($ring[$key])){
                    $old=openssl_pkey_get_details(WechatProtocol::verificationKey($key,$ring[$key]))['key'];
                    $new=openssl_pkey_get_details(WechatProtocol::verificationKey($key,$pem))['key'];
                    if(!hash_equals($old,$new))throw new Problem('wechat_verification_key_conflict',502);
                }
                $ring[$key]=$pem;
            }
        }
        return $ring;
    }

    public function payerHash(string $appId,string $mchid,?string $payer): ?string
    {
        return $payer===null?null:$this->secrets->digest('wechat-payer:'.$appId.':'.$mchid.':'.$payer);
    }

    private function reference(string $id,string $cipher): string
    {
        $profile=$this->secrets->decrypt($cipher,'wechat-order-profile:'.$id);
        if(!preg_match('/^[a-f0-9]{64}$/D',$profile))throw new RuntimeException('wechat_profile_corrupt');
        return $profile;
    }
}
