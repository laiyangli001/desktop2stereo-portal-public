<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Serialize cashier requests without holding InnoDB row locks across provider calls. */
final readonly class WechatCheckout
{
    public function __construct(private PDO $db,private SecretBox $secrets,private ?Closure $transport=null) {}

    public function open(int $userId,string $id,array $context,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('resource_id_invalid');
        if($this->db->inTransaction())throw new Problem('wechat_checkout_transaction_invalid',409);
        $owned=$this->db->prepare('SELECT id FROM portal_orders WHERE id=? AND user_id=?');
        $owned->execute([$id,$userId]);if($owned->fetchColumn()===false)throw new Problem('order_not_found',404);
        return WechatOrderLock::run($this->db,$id,function()use($userId,$id,$context,$now):array{
            $ready=$this->prepare($userId,$id,$context,$now,false);
            if(isset($ready['result']))return $ready['result'];
            $profiles=new WechatProfiles($this->db,$this->secrets);
            // Query before submission/recovery; unknown results never release the order.
            $query=(new WechatPayments($this->db,$profiles,$this->transport))->query($id,$context,$now);
            if(!in_array($query['provider_status']??'', ['NOT_FOUND','NOTPAY'],true)){
                return ['status'=>$query['status']];
            }
            $ready=$this->prepare($userId,$id,$context,$now,true);
            if(isset($ready['result']))return $ready['result'];
            $config=$ready['request']['config'];$config['enabled']='true';
            $code=(new WechatClient($config,$now,$this->transport,$ready['request']['verification_keys']))
                ->native($ready['order'],$now,$ready['profile']);
            return Database::transaction($this->db,function()use($userId,$id,$context,$now,$code):array{
                $current=$this->guard($userId,$id,$now);
                if($current['status']!=='pending')return ['status'=>$current['status']];
                $cipher=$this->secrets->encrypt($code['code_url'],'wechat-checkout:'.$id);
                $this->db->prepare('INSERT INTO portal_wechat_checkouts (order_id,code_cipher,expires_at,created_at) VALUES (?,?,?,?)')
                    ->execute([$id,$cipher,$code['expires_at'],$now]);
                $this->event($id,'CHECKOUT:READY',$context,$now);
                return ['status'=>'pending','code_url'=>$code['code_url'],'expires_at'=>$code['expires_at']];
            });
        },'wechat_checkout_busy');
    }

    private function prepare(int $userId,string $id,array $context,int $now,bool $sending): array
    {
        return Database::transaction($this->db,function()use($userId,$id,$context,$now,$sending):array{
            $order=$this->guard($userId,$id,$now);
            if($order['status']!=='pending')return ['result'=>['status'=>$order['status']]];
            $saved=$this->db->prepare('SELECT * FROM portal_wechat_checkouts WHERE order_id=? FOR SHARE');
            $saved->execute([$id]);$cache=$saved->fetch();
            if($cache!==false){
                if((int)$cache['expires_at']<=$now)throw new Problem('wechat_code_expired',409);
                return ['result'=>['status'=>'pending','code_url'=>$this->secrets->decrypt($cache['code_cipher'],'wechat-checkout:'.$id),
                    'expires_at'=>(int)$cache['expires_at']]];
            }
            if($order['expires_at']<$now+60)throw new Problem('order_expired',409);
            $profiles=new WechatProfiles($this->db,$this->secrets);$bound=$profiles->forOrder($id);
            $request=$profiles->request($id);
            if($sending)$this->event($id,'CHECKOUT:REQUESTED',$context,$now);
            return ['order'=>$order,'profile'=>$bound['id'],'request'=>$request];
        });
    }

    private function guard(int $userId,string $id,int $now): array
    {
        $user=$this->db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');$user->execute([$userId]);
        if($user->fetchColumn()!=='active')throw new Problem('account_restricted_or_unverified',403);
        $order=$this->db->prepare('SELECT * FROM portal_orders WHERE id=? AND user_id=? FOR UPDATE');$order->execute([$id,$userId]);$row=$order->fetch();
        if(!$row)throw new Problem('order_not_found',404);
        if($row['payment_method']!=='wechat_native'||$row['currency']!=='CNY'||
            ($row['product']!=='wallet_recharge'&&!(new ProductCatalog($this->db))->exists($row['product']))){
            throw new Problem('payment_method_unavailable',409);
        }
        $verified=$this->db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');$verified->execute([$userId]);
        if((int)$verified->fetchColumn()<1)throw new Problem('account_restricted_or_unverified',403);
        $controls=$this->db->prepare('SELECT login_blocked,purchase_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');$controls->execute([$userId]);$control=$controls->fetch();
        if($control&&((int)$control['login_blocked']||(int)$control['purchase_blocked']))throw new Problem('account_purchase_blocked',403);
        // Completed orders display their state without reopening a payment channel.
        if($row['status']!=='pending')return $row;
        if($row['product']!=='wallet_recharge'){
            (new Commerce($this->db))->assertPurchaseAllowed($userId,$row['product']);
        }elseif(!WalletRecharges::enabled($this->db))throw new Problem('wallet_recharge_closed',409);
        $config=(new WechatSettings($this->db,$this->secrets))->load();
        if($config['enabled']!=='true')throw new Problem('payment_method_unavailable',409);
        $archive=(new WechatProfiles($this->db,$this->secrets))->forOrder($id)['config'];
        if($archive['app_id']!==$config['app_id']||$archive['mchid']!==$config['mchid'])throw new Problem('payment_method_unavailable',409);
        if((int)$row['expires_at']<=$now||(int)$row['created_at']>$now)throw new Problem('order_expired',409);
        $row['amount']=(int)$row['amount'];$row['expires_at']=(int)$row['expires_at'];
        $row['merchant_order_no']=OrderNumbers::merchant($this->db,$id,'wechat_native');
        return $row;
    }

    private function event(string $id,string $type,array $context,int $now): void
    {
        $this->db->prepare('INSERT INTO portal_wechat_events (id,order_id,event_type,event_hash,source_ip,result,received_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([hash('sha256',random_bytes(32)),$id,$type,hash('sha256',$id.'|'.$type),$context['source_ip']??null,'PENDING',$now]);
    }
}
