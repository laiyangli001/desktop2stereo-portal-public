<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ReferralProgram
{
    public function __construct(private PDO $db, private SecretBox $secrets) {}

    public static function configuration(PDO $db): array
    {
        $q=$db->query("SELECT value_json FROM portal_settings WHERE name='referrals.v1'");
        $value=$q->fetchColumn();
        return array_replace(['enabled'=>false,'rate_bps'=>0,'holding_days'=>7,'minimum_withdrawal'=>10000],
            is_string($value)?json_decode($value,true,16,JSON_THROW_ON_ERROR):[]);
    }

    public function saveConfiguration(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'referrals.manage');
        $config=[];
        if(!is_bool($input['enabled']??null))throw new Problem('boolean_required');
        $config['enabled']=$input['enabled'];
        foreach(['rate_bps'=>[0,10000],'holding_days'=>[0,365],'minimum_withdrawal'=>[100,1000000000]]as$key=>[$min,$max]){
            if(!is_int($input[$key]??null)||$input[$key]<$min||$input[$key]>$max)throw new Problem('referral_configuration_invalid');
            $config[$key]=$input[$key];
        }
        if($config['enabled']&&$config['rate_bps']===0)throw new Problem('referral_rate_required');
        Database::transaction($this->db,function(PDO $db)use($config,$adminId,$context,$now):void{
            $db->prepare("INSERT INTO portal_settings(name,value_json,updated_at) VALUES('referrals.v1',?,?)
                ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)")->execute([json_encode($config,JSON_THROW_ON_ERROR),$now]);
            (new Backoffice($db))->audit($adminId,'UPDATE_REFERRAL_CONFIGURATION','referrals',$config,$context,$now);
        });
        return $config;
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^(?:[a-z0-9]{6}|[a-f0-9]{24})$/D',$code)===1;
    }

    public static function bind(PDO $db,int $invitee,string $code,int $now): void
    {
        if(!self::isValidCode($code))return;
        if(!self::configuration($db)['enabled'])return;
        $q=$db->prepare('SELECT user_id FROM portal_referral_codes WHERE code=?');$q->execute([$code]);$inviter=$q->fetchColumn();
        if($inviter===false||(int)$inviter===$invitee)return;
        // Registration calls this once in its existing transaction; an established account cannot be reassigned.
        $db->prepare('INSERT IGNORE INTO portal_referrals(invitee_id,inviter_id,created_at) VALUES(?,?,?)')->execute([$invitee,(int)$inviter,$now]);
    }

    public static function accrue(PDO $db,array $order,int $now): void
    {
        if(!$db->inTransaction())throw new \RuntimeException('settlement_transaction_required');
        if(($order['product']??'wallet_recharge')==='wallet_recharge')return;
        $config=self::configuration($db);
        if(!$config['enabled']||(int)$config['rate_bps']===0)return;
        $q=$db->prepare('SELECT inviter_id FROM portal_referrals WHERE invitee_id=?');$q->execute([$order['user_id']]);$inviter=$q->fetchColumn();
        if($inviter===false)return;
        $amount=intdiv((int)$order['amount']*(int)$config['rate_bps'],10000);
        $db->prepare('INSERT IGNORE INTO portal_referral_commissions
            (order_id,inviter_id,invitee_id,currency,basis_amount,rate_bps,amount,available_at,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$order['id'],(int)$inviter,$order['user_id'],$order['currency'],$order['amount'],
                $config['rate_bps'],$amount,$now+(int)$config['holding_days']*86400,$now,$now]);
    }

    public static function reverse(PDO $db,string $orderId,int $now): void
    {
        if(!$db->inTransaction())throw new \RuntimeException('settlement_transaction_required');
        $q=$db->prepare('SELECT c.*,o.refunded_amount FROM portal_referral_commissions c JOIN portal_orders o ON o.id=c.order_id WHERE c.order_id=? FOR UPDATE');
        $q->execute([$orderId]);$commission=$q->fetch();if(!$commission)return;
        $earned=intdiv(max(0,(int)$commission['basis_amount']-(int)$commission['refunded_amount'])*(int)$commission['rate_bps'],10000);
        $reversed=(int)$commission['amount']-$earned;
        $delta=$reversed-(int)$commission['reversed_amount'];
        if($delta<=0)return;
        if($commission['released_at']!==null){
            self::entry($db,(int)$commission['inviter_id'],$commission['currency'],-$delta,0,'REVERSAL',
                'commission_reverse:'.$orderId.':'.$reversed,$orderId,null,$now);
        }
        $db->prepare('UPDATE portal_referral_commissions SET reversed_amount=?,updated_at=? WHERE order_id=?')->execute([$reversed,$now,$orderId]);
    }

    public function settle(int $now,int $limit=100): int
    {
        $ids=$this->db->prepare('SELECT order_id,inviter_id FROM portal_referral_commissions WHERE released_at IS NULL AND available_at<=? ORDER BY available_at,order_id LIMIT '.max(1,min($limit,1000)));
        $ids->execute([$now]);$count=0;
        foreach($ids->fetchAll()as$target){
            $changed=Database::transaction($this->db,function(PDO $db)use($target,$now):bool{
                $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$target['inviter_id']]);
                $q=$db->prepare('SELECT * FROM portal_referral_commissions WHERE order_id=? FOR UPDATE');$q->execute([$target['order_id']]);$row=$q->fetch();
                if(!$row||$row['released_at']!==null||(int)$row['available_at']>$now)return false;
                $amount=(int)$row['amount']-(int)$row['reversed_amount'];
                self::entry($db,(int)$row['inviter_id'],$row['currency'],$amount,0,'COMMISSION','commission:'.$row['order_id'],$row['order_id'],null,$now);
                $db->prepare('UPDATE portal_referral_commissions SET released_at=?,updated_at=? WHERE order_id=?')->execute([$now,$now,$row['order_id']]);
                return true;
            });if($changed)$count++;
        }
        return $count;
    }

    public function overview(int $userId,int $now): array
    {
        $configuration=self::configuration($this->db);
        if(!$configuration['enabled'])throw new Problem('referrals_disabled',403);
        $lookup=$this->db->prepare('SELECT code FROM portal_referral_codes WHERE user_id=? AND code_version=2');
        $lookup->execute([$userId]);$code=$lookup->fetchColumn();
        if($code===false){
            $q=$this->db->prepare("SELECT id FROM portal_users WHERE id=? AND status='active'");$q->execute([$userId]);if(!$q->fetchColumn())throw new Problem('account_unavailable',403);
            $insert=$this->db->prepare('INSERT IGNORE INTO portal_referral_codes(user_id,code,created_at,code_version) VALUES(?,?,?,2)');
            $alphabet='23456789abcdefghjkmnpqrstuvwxyz';
            for($attempt=0;$code===false&&$attempt<10;$attempt++){
                $candidate='';
                for($position=0;$position<6;$position++)$candidate.=$alphabet[random_int(0,strlen($alphabet)-1)];
                $insert->execute([$userId,$candidate,$now]);
                $lookup->execute([$userId]);$code=$lookup->fetchColumn();
            }
            if($code===false)throw new Problem('service_unavailable',503);
        }
        $q=$this->db->prepare('SELECT currency,available,reserved FROM portal_commission_wallets WHERE user_id=?');$q->execute([$userId]);$balances=$q->fetchAll();
        $q=$this->db->prepare('SELECT currency,SUM(amount) earned,SUM(reversed_amount) reversed,
            SUM(CASE WHEN released_at IS NULL THEN amount-reversed_amount ELSE 0 END) pending
            FROM portal_referral_commissions WHERE inviter_id=? GROUP BY currency');$q->execute([$userId]);$totals=$q->fetchAll();
        $buyers=self::effectiveBuyerCount($this->db,$userId);
        $q=$this->db->prepare('SELECT id,currency,amount,status,decision_reason,payout_reference,created_at,updated_at FROM portal_commission_withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
        $q->execute([$userId]);
        $invitePath='/register?ref='.$code;
        return ['configuration'=>$configuration,'code'=>$code,'invite_url'=>rtrim(getenv('D2S_APP_URL')?:'','/').$invitePath,
            'effective_referrals'=>$buyers,'totals'=>$totals,'balances'=>$balances,'withdrawals'=>$q->fetchAll()];
    }

    public static function effectiveBuyerCount(PDO $db,int $userId): int
    {
        $q=$db->prepare("SELECT COUNT(DISTINCT r.invitee_id) FROM portal_referrals r
            JOIN portal_orders o ON o.user_id=r.invitee_id JOIN portal_order_fulfillments f ON f.id=o.id
            WHERE r.inviter_id=? AND o.product<>'wallet_recharge' AND o.paid_at IS NOT NULL
                AND o.status IN ('paid','refund_pending','partially_refunded') AND o.amount>o.refunded_amount");
        $q->execute([$userId]);return (int)$q->fetchColumn();
    }

    public function requestWithdrawal(int $userId,array $input,array $context,int $now): array
    {
        $id=Backoffice::hexId($input['request_id']??null);$currency=Backoffice::currency($input['currency']??null);
        $method=$input['method']??null;
        if(!in_array($method,['wechat','alipay','paypal'],true))throw new Problem('field_invalid');
        $recipient=Backoffice::text($input,'recipient',120,true);
        $account=Backoffice::text($input,'account',254,true);
        if(preg_match('/[\r\n\t]/',$recipient.$account))throw new Problem('field_invalid');
        $destination=json_encode(['method'=>$method,'recipient'=>$recipient,'account'=>$account],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        return Database::transaction($this->db,function(PDO $db)use($userId,$id,$currency,$destination,$context,$now):array{
            $q=$db->prepare("SELECT id FROM portal_users WHERE id=? AND status='active' FOR UPDATE");$q->execute([$userId]);if(!$q->fetchColumn())throw new Problem('account_unavailable',403);
            $q=$db->prepare('SELECT user_id,currency,amount,status,destination_cipher FROM portal_commission_withdrawals WHERE id=?');$q->execute([$id]);$old=$q->fetch();
            if($old){
                if((int)$old['user_id']!==$userId||$old['currency']!==$currency||
                    $this->secrets->decrypt($old['destination_cipher'],'withdrawal:'.$id)!==$destination)throw new Problem('idempotency_conflict',409);
                return ['id'=>$id,'status'=>$old['status']];
            }
            $q=$db->prepare('SELECT available FROM portal_commission_wallets WHERE user_id=? AND currency=? FOR UPDATE');$q->execute([$userId,$currency]);$amount=(int)$q->fetchColumn();
            if($amount<=0)throw new Problem('commission_insufficient',409);
            if($amount<(int)self::configuration($db)['minimum_withdrawal'])throw new Problem('withdrawal_below_minimum');
            $db->prepare("INSERT INTO portal_commission_withdrawals(id,user_id,currency,amount,status,destination_cipher,created_at,updated_at) VALUES(?,?,?,?,'pending',?,?,?)")
                ->execute([$id,$userId,$currency,$amount,$this->secrets->encrypt($destination,'withdrawal:'.$id),$now,$now]);
            self::entry($db,$userId,$currency,-$amount,$amount,'WITHDRAWAL_HOLD','withdrawal_hold:'.$id,null,$id,$now);
            Audit::user($db,$userId,Audit::email($db,$userId),'WITHDRAWAL','SUCCESS',$context,$now);
            return ['id'=>$id,'status'=>'pending'];
        });
    }

    public function adminOverview(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'referrals.manage');
        $q=$this->db->query('SELECT w.id,w.user_id,u.username,w.currency,w.amount,w.status,w.decision_reason,w.payout_reference,w.created_at,w.updated_at
            FROM portal_commission_withdrawals w JOIN portal_users u ON u.id=w.user_id ORDER BY w.created_at DESC LIMIT 200');
        return ['configuration'=>self::configuration($this->db),'withdrawals'=>$q->fetchAll(),
            'totals'=>$this->db->query('SELECT currency,SUM(amount) earned,SUM(reversed_amount) reversed,COUNT(*) orders FROM portal_referral_commissions GROUP BY currency')->fetchAll()];
    }

    public function destination(int $adminId,string $id,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'referrals.manage');Backoffice::hexId($id);
        $q=$this->db->prepare('SELECT destination_cipher FROM portal_commission_withdrawals WHERE id=?');$q->execute([$id]);$cipher=$q->fetchColumn();
        if($cipher===false)throw new Problem('withdrawal_not_found',404);
        (new Backoffice($this->db))->audit($adminId,'VIEW_WITHDRAWAL_DESTINATION',$id,[],$context,$now);
        $destination=$this->secrets->decrypt($cipher,'withdrawal:'.$id);
        $details=json_decode($destination,true);
        if(is_array($details)&&in_array($details['method']??null,['wechat','alipay','paypal'],true)
            &&is_string($details['recipient']??null)&&is_string($details['account']??null))return ['payout'=>$details];
        return ['destination'=>$destination];
    }

    public function decide(int $adminId,string $id,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'referrals.manage');Backoffice::hexId($id);
        $decision=$input['decision']??'';$reason=Backoffice::text($input,'reason',500,true);
        if(!in_array($decision,['approved','paid','rejected'],true))throw new Problem('withdrawal_decision_invalid');
        if($decision==='paid'&&($input['payout_confirmed']??false)!==true)throw new Problem('payout_confirmation_required');
        $reference=$decision==='paid'?Backoffice::text($input,'payout_reference',160,true):null;
        $q=$this->db->prepare('SELECT user_id FROM portal_commission_withdrawals WHERE id=?');$q->execute([$id]);$user=$q->fetchColumn();if($user===false)throw new Problem('withdrawal_not_found',404);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$id,$decision,$reason,$reference,$context,$now,$user):array{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$user]);
            $q=$db->prepare('SELECT * FROM portal_commission_withdrawals WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
            if($row['status']===$decision){
                if($decision==='paid'&&$row['payout_reference']!==$reference)throw new Problem('idempotency_conflict',409);
                return ['id'=>$id,'status'=>$decision];
            }
            if(!in_array($row['status'],['pending','approved'],true)||($decision==='paid'&&$row['status']!=='approved'))throw new Problem('withdrawal_state_conflict',409);
            $q=$db->prepare('SELECT available FROM portal_commission_wallets WHERE user_id=? AND currency=? FOR UPDATE');$q->execute([$user,$row['currency']]);$available=(int)$q->fetchColumn();
            if($decision!=='rejected'&&$available<0)throw new Problem('commission_refund_debt',409);
            if($decision==='rejected')self::entry($db,(int)$user,$row['currency'],(int)$row['amount'],-(int)$row['amount'],'WITHDRAWAL_REJECTED','withdrawal_reject:'.$id,null,$id,$now);
            if($decision==='paid')self::entry($db,(int)$user,$row['currency'],0,-(int)$row['amount'],'WITHDRAWAL_PAID','withdrawal_paid:'.$id,null,$id,$now);
            $db->prepare('UPDATE portal_commission_withdrawals SET status=?,decision_reason=?,payout_reference=?,reviewed_by=?,updated_at=? WHERE id=?')
                ->execute([$decision,$reason,$reference,$adminId,$now,$id]);
            (new Backoffice($db))->audit($adminId,'REVIEW_WITHDRAWAL',$id,['status'=>$decision,'reason'=>$reason,'payout_reference'=>$reference],$context,$now);
            return ['id'=>$id,'status'=>$decision];
        });
    }

    private static function entry(PDO $db,int $user,string $currency,int $amount,int $reserved,string $event,string $reference,?string $order,?string $withdrawal,int $now): void
    {
        $q=$db->prepare('SELECT 1 FROM portal_commission_entries WHERE reference=?');$q->execute([$reference]);if($q->fetchColumn())return;
        $db->prepare('INSERT IGNORE INTO portal_commission_wallets(user_id,currency,available,reserved,updated_at) VALUES(?,?,0,0,?)')->execute([$user,$currency,$now]);
        $q=$db->prepare('SELECT available,reserved FROM portal_commission_wallets WHERE user_id=? AND currency=? FOR UPDATE');$q->execute([$user,$currency]);$wallet=$q->fetch();
        $balance=(int)$wallet['available']+$amount;$afterReserved=(int)$wallet['reserved']+$reserved;
        if($afterReserved<0||abs($balance)>1000000000000||$afterReserved>1000000000000)throw new Problem('commission_balance_invalid',409);
        $db->prepare('UPDATE portal_commission_wallets SET available=?,reserved=?,updated_at=? WHERE user_id=? AND currency=?')->execute([$balance,$afterReserved,$now,$user,$currency]);
        $db->prepare('INSERT INTO portal_commission_entries(user_id,currency,amount,reserved_delta,balance_after,reserved_after,event_type,reference,order_id,withdrawal_id,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$user,$currency,$amount,$reserved,$balance,$afterReserved,$event,$reference,$order,$withdrawal,$now]);
    }
}
