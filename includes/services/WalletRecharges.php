<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Recharge orders credit wallet balance only; they never create or alter software entitlements. */
final readonly class WalletRecharges
{
    public const MINIMUM=100;
    public const MAXIMUM=1000000;

    public function __construct(private PDO $db,private ?PaymentProfiles $alipay=null,private ?WechatProfiles $wechat=null) {}

    public static function enabled(PDO $db): bool
    {
        $q=$db->prepare("SELECT value_json FROM portal_settings WHERE name='commerce.recharge_enabled'");$q->execute();$value=$q->fetchColumn();
        return $value===false?getenv('D2S_WALLET_RECHARGE_ENABLED')==='true':$value==='true';
    }

    public function catalog(): array
    {
        $methods=[];
        try{if($this->wechatProfiles()->available())$methods[]='wechat_native';}catch(\Throwable){}
        try{if($this->alipayProfiles()->available())array_unshift($methods,'alipay_pc');}catch(\Throwable){}
        return ['enabled'=>self::enabled($this->db),'currency'=>'CNY','minimum'=>self::MINIMUM,'maximum'=>self::MAXIMUM,
            'payment_methods'=>$methods];
    }

    public function configuration(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        return ['enabled'=>self::enabled($this->db),'minimum'=>self::MINIMUM,'maximum'=>self::MAXIMUM,'can_toggle'=>true];
    }

    public function setEnabled(int $adminId,bool $enabled,array $context,int $now): void
    {
        Database::transaction($this->db,function(PDO $db)use($adminId,$enabled,$context,$now):void{
            Permissions::require($db,$adminId,'payment.configure');
            $value=json_encode($enabled,JSON_THROW_ON_ERROR);
            $db->prepare("INSERT INTO portal_settings (name,value_json,updated_at) VALUES ('commerce.recharge_enabled',?,?)
                ON DUPLICATE KEY UPDATE value_json=?,updated_at=?")->execute([$value,$now,$value,$now]);
            $q=$db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$adminId]);$email=$q->fetchColumn();
            if(!is_string($email))throw new Problem('admin_auth_invalid',401);
            Audit::admin($db,$adminId,$email,'TOGGLE_WALLET_RECHARGE','wallet',$context,'SUCCESS',
                ['enabled'=>$enabled],$now);
        });
    }

    public function quote(int $userId,mixed $amount,array $context,int $now): array
    {
        if(!self::enabled($this->db))throw new Problem('wallet_recharge_closed',409);
        try{$amount=Money::positive($amount);}catch(Problem){throw new Problem('wallet_recharge_amount_invalid');}
        if($amount<self::MINIMUM||$amount>self::MAXIMUM)throw new Problem('wallet_recharge_amount_invalid');
        return Database::transaction($this->db,function(PDO $db)use($userId,$amount,$now):array{
            $this->account($userId);
            $id=bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO portal_quotes (id,user_id,product,currency,amount,price_revision,created_at,expires_at)
                VALUES (?,?,'wallet_recharge','CNY',?,1,?,?)")->execute([$id,$userId,$amount,$now,$now+900]);
            return ['id'=>$id,'product'=>'wallet_recharge','currency'=>'CNY','amount'=>$amount,'expires_at'=>$now+900];
        });
    }

    public function createOrder(int $userId,string $quoteId,string $method,string $key,array $context,int $now,string $locale='zh-Hans'): array
    {
        $registry=new LocaleRegistry();
        $locale=$registry->normalizeForAdmin($locale)??$registry->defaultLocale();
        if(!preg_match('/^[a-f0-9]{32}$/D',$quoteId)||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key)||
            !in_array($method,['alipay_pc','wechat_native'],true))throw new Problem('wallet_recharge_input_invalid');
        if(!self::enabled($this->db))throw new Problem('wallet_recharge_closed',409);
        return Database::transaction($this->db,function(PDO $db)use($userId,$quoteId,$method,$key,$context,$now,$locale):array{
            $this->account($userId);
            $q=$db->prepare('SELECT * FROM portal_orders WHERE user_id=? AND idempotency_key=? FOR UPDATE');$q->execute([$userId,$key]);$old=$q->fetch();
            if($old!==false){
                if($old['quote_id']!==$quoteId||$old['payment_method']!==$method||$old['product']!=='wallet_recharge')throw new Problem('idempotency_conflict',409);
                return $this->view($old);
            }
            if($method==='alipay_pc'&&!$this->alipayProfiles()->available())throw new Problem('payment_method_unavailable',409);
            if($method==='wechat_native'&&!$this->wechatProfiles()->available())throw new Problem('payment_method_unavailable',409);
            $q=$db->prepare("SELECT * FROM portal_quotes WHERE id=? AND user_id=? AND product='wallet_recharge' AND currency='CNY'");$q->execute([$quoteId,$userId]);$quote=$q->fetch();
            if($quote===false)throw new Problem('quote_not_found',404);
            if((int)$quote['expires_at']<=$now||(int)$quote['created_at']>$now)throw new Problem('quote_expired',409);
            $q=$db->prepare('SELECT id FROM portal_orders WHERE pending_user_id=? AND product=\'wallet_recharge\' FOR UPDATE');$q->execute([$userId]);
            if($q->fetchColumn()!==false)throw new Problem('pending_order_exists',409);
            $q=$db->prepare('SELECT id FROM portal_orders WHERE quote_id=?');$q->execute([$quoteId]);
            if($q->fetchColumn()!==false)throw new Problem('quote_already_used',409);
            $id=bin2hex(random_bytes(16));$number=(new OrderNumbers(Database::independent($db)))->allocate($id,$now);
            $db->prepare("INSERT INTO portal_orders
                (id,user_id,idempotency_key,quote_id,product,currency,amount,payment_method,email,source_ip,status,pending_user_id,
                created_at,expires_at,updated_at,order_no,locale_snapshot) VALUES (?,?,?,?,'wallet_recharge','CNY',?,?,?,?,'pending',?,?,?,?,?,?)")
                ->execute([$id,$userId,$key,$quoteId,$quote['amount'],$method,Audit::email($db,$userId),$context['source_ip']??null,
                    $userId,$now,$now+900,$now,$number,$locale]);
            OrderNumbers::bindMerchant($db,$id,$method,$number,$now);
            if($method==='alipay_pc')$this->alipayProfiles()->capture($id,$now);
            else $this->wechatProfiles()->capture($id,$now);
            return $this->view($this->lock($userId,$id));
        });
    }

    public function pending(int $userId): ?array
    {
        $q=$this->db->prepare("SELECT * FROM portal_orders WHERE user_id=? AND product='wallet_recharge' AND pending_user_id=?");
        $q->execute([$userId,$userId]);$row=$q->fetch();
        return $row===false?null:$this->view($row);
    }

    /** Caller owns the verified-payment transaction and all user/order locks. */
    public static function creditExternal(PDO $db,array $order,string $provider,string $paymentId,int $now): bool
    {
        if(!$db->inTransaction())throw new Problem('wallet_recharge_transaction_required');
        if(($order['product']??null)!=='wallet_recharge'||($order['currency']??null)!=='CNY'||
            !in_array($provider,['alipay_pc','wechat_native'],true)||!preg_match('/^[a-f0-9]{64}$/D',$paymentId)){
            throw new Problem('wallet_recharge_mismatch');
        }
        if(!in_array($order['status']??null,['pending','payment_review'],true))return false;
        $q=$db->prepare('SELECT order_id FROM portal_wallet_recharge_fulfillments WHERE order_id=? FOR SHARE');$q->execute([$order['id']]);
        if($q->fetchColumn()!==false)return false;
        $amount=(int)$order['amount'];
        $db->prepare('INSERT IGNORE INTO portal_wallets (user_id,currency,balance,updated_at) VALUES (?,?,0,?)')->execute([$order['user_id'],'CNY',$now]);
        $q=$db->prepare("SELECT balance FROM portal_wallets WHERE user_id=? AND currency='CNY' FOR UPDATE");$q->execute([$order['user_id']]);
        $balance=(int)$q->fetchColumn();
        if($balance>1000000000000-$amount)return false;
        $next=$balance+$amount;
        $db->prepare("UPDATE portal_wallets SET balance=?,updated_at=? WHERE user_id=? AND currency='CNY'")->execute([$next,$now,$order['user_id']]);
        $db->prepare("INSERT INTO portal_wallet_entries
            (user_id,currency,amount,balance_after,reference,order_id,event_type,created_at) VALUES (?, 'CNY',?,?,?,?,?,?)")
            ->execute([$order['user_id'],$amount,$next,'recharge:'.$order['id'],$order['id'],'RECHARGE',$now]);
        $column=$provider==='alipay_pc'?'alipay_payment_id':'wechat_payment_id';
        $db->prepare("INSERT INTO portal_wallet_recharge_fulfillments (order_id,{$column},amount,credited_at) VALUES (?,?,?,?)")
            ->execute([$order['id'],$paymentId,$amount,$now]);
        return true;
    }

    /** Reserve credited recharge funds before any provider refund request leaves the server. */
    public static function reserveRefund(PDO $db,string $refundId,string $orderId,string $provider,string $paymentId,int $amount,int $now): bool
    {
        if(!$db->inTransaction())throw new Problem('wallet_recharge_transaction_required');
        $q=$db->prepare('SELECT * FROM portal_wallet_recharge_fulfillments WHERE order_id=?');$q->execute([$orderId]);$fulfillment=$q->fetch();
        if($fulfillment===false)return false;
        $column=$provider==='alipay_pc'?'alipay_payment_id':'wechat_payment_id';
        if(($fulfillment[$column]??null)!==$paymentId)return false;
        $q=$db->prepare('SELECT * FROM portal_wallet_recharge_refund_holds WHERE refund_id=? FOR SHARE');$q->execute([$refundId]);
        if($q->fetch()!==false)return true;
        $q=$db->prepare("SELECT balance FROM portal_wallets WHERE user_id=(SELECT user_id FROM portal_orders WHERE id=?) AND currency='CNY' FOR UPDATE");
        $q->execute([$orderId]);$balance=(int)$q->fetchColumn();
        if($balance<$amount)throw new Problem('wallet_recharge_refund_insufficient',409);
        $next=$balance-$amount;
        $db->prepare("UPDATE portal_wallets SET balance=?,updated_at=? WHERE user_id=(SELECT user_id FROM portal_orders WHERE id=?) AND currency='CNY'")
            ->execute([$next,$now,$orderId]);
        $db->prepare("INSERT INTO portal_wallet_entries
            (user_id,currency,amount,balance_after,reference,order_id,event_type,created_at)
            SELECT user_id,'CNY',?, ?,?,?,'RECHARGE_REFUND_HOLD',? FROM portal_orders WHERE id=?")
            ->execute([-$amount,$next,'recharge-refund-hold:'.$refundId,$orderId,$now,$orderId]);
        $db->prepare("INSERT INTO portal_wallet_recharge_refund_holds
            (refund_id,order_id,provider,payment_id,amount,status,created_at,updated_at) VALUES (?,?,?,?,?,'held',?,?)")
            ->execute([$refundId,$orderId,$provider,$paymentId,$amount,$now,$now]);
        $db->prepare("UPDATE portal_orders SET status='refund_pending',updated_at=? WHERE id=? AND status IN ('paid','partially_refunded')")
            ->execute([$now,$orderId]);
        return true;
    }

    public static function completeRefund(PDO $db,string $refundId,int $now): void
    {
        if(!$db->inTransaction())throw new Problem('wallet_recharge_transaction_required');
        $db->prepare("UPDATE portal_wallet_recharge_refund_holds SET status='consumed',updated_at=? WHERE refund_id=? AND status='held'")
            ->execute([$now,$refundId]);
    }

    public static function releaseRefund(PDO $db,string $refundId,int $now): void
    {
        if(!$db->inTransaction())throw new Problem('wallet_recharge_transaction_required');
        $q=$db->prepare("SELECT * FROM portal_wallet_recharge_refund_holds WHERE refund_id=? AND status='held' FOR UPDATE");
        $q->execute([$refundId]);$hold=$q->fetch();if($hold===false)return;
        $q=$db->prepare('SELECT user_id FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$hold['order_id']]);$userId=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT balance FROM portal_wallets WHERE user_id=? AND currency='CNY' FOR UPDATE");$q->execute([$userId]);$balance=(int)$q->fetchColumn();
        $amount=(int)$hold['amount'];
        if($balance>1000000000000-$amount)throw new Problem('wallet_balance_limit',409);
        $next=$balance+$amount;
        $db->prepare("UPDATE portal_wallets SET balance=?,updated_at=? WHERE user_id=? AND currency='CNY'")->execute([$next,$now,$userId]);
        $db->prepare("INSERT INTO portal_wallet_entries
            (user_id,currency,amount,balance_after,reference,order_id,event_type,created_at) VALUES (?,'CNY',?,?,?,?,'RECHARGE_REFUND_RELEASE',?)")
            ->execute([$userId,$amount,$next,'recharge-refund-release:'.$refundId,$hold['order_id'],$now]);
        $db->prepare("UPDATE portal_wallet_recharge_refund_holds SET status='released',updated_at=? WHERE refund_id=?")
            ->execute([$now,$refundId]);
        $q=$db->prepare("SELECT refunded_amount FROM portal_orders WHERE id=? FOR UPDATE");$q->execute([$hold['order_id']]);$refunded=(int)$q->fetchColumn();
        $db->prepare("UPDATE portal_orders SET status=?,updated_at=? WHERE id=? AND status='refund_pending'")
            ->execute([$refunded===0?'paid':'partially_refunded',$now,$hold['order_id']]);
    }

    private function account(int $userId): void
    {
        $q=$this->db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$userId]);$status=$q->fetchColumn();
        if($status!=='active')throw new Problem('account_restricted_or_unverified',403);
        $q=$this->db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');$q->execute([$userId]);
        if((int)$q->fetchColumn()<1)throw new Problem('account_restricted_or_unverified',403);
        $q=$this->db->prepare('SELECT login_blocked,purchase_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');$q->execute([$userId]);$control=$q->fetch();
        if($control&&((int)$control['login_blocked']||(int)$control['purchase_blocked']))throw new Problem('account_purchase_blocked',403);
    }

    private function lock(int $userId,string $id): array
    {
        $q=$this->db->prepare('SELECT * FROM portal_orders WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$id,$userId]);$row=$q->fetch();
        if($row===false)throw new Problem('order_not_found',404);return$row;
    }

    private function view(array $order): array
    {
        $order['order_no']??=null;
        return array_intersect_key($order,array_flip(['id','order_no','quote_id','product','currency','amount','payment_method','status','paid_at','created_at','expires_at']));
    }

    private function alipayProfiles(): PaymentProfiles
    {
        return $this->alipay??new PaymentProfiles($this->db,SecretBox::fromEnvironment());
    }

    private function wechatProfiles(): WechatProfiles
    {
        return $this->wechat??new WechatProfiles($this->db,SecretBox::fromEnvironment());
    }
}
