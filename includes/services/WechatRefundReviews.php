<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Manual controls never assert provider success or release attempted/ambiguous money. */
final readonly class WechatRefundReviews
{
    public function __construct(private PDO $db) {}

    public function records(int $adminId,string $id,array $context,int $now,int $page=1): array
    {
        $email=$this->admin($adminId);
        if($page<1||$page>100000)throw new Problem('page_invalid');
        return Database::transaction($this->db,function(PDO $db)use($adminId,$email,$id,$context,$now,$page):array{
            $refund=$this->load($id);
            $q=$db->prepare('SELECT id,action,reason,result_mode,operator_id,created_at,evidence_hash FROM portal_wechat_refund_review_actions
                WHERE refund_id=? ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET '.(($page-1)*50));$q->execute([$id]);$rows=$q->fetchAll();
            $q=$db->prepare('SELECT COUNT(*) FROM portal_wechat_refund_review_actions WHERE refund_id=?');$q->execute([$id]);$total=(int)$q->fetchColumn();
            Audit::admin($db,$adminId,$email,'VIEW_WECHAT_REFUND_REVIEW',$id,$context,'SUCCESS',['page'=>$page,'count'=>count($rows)],$now);
            return ['refund'=>$refund,'actions'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>50];
        });
    }

    public function act(int $adminId,string $id,string $action,string $reason,string $key,array $context,int $now): array
    {
        $email=$this->admin($adminId);$reason=trim($reason);
        if(!in_array($action,['cancel_unsent','pause_retry','resume_retry'],true)||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key)||
            !mb_check_encoding($reason,'UTF-8')||mb_strlen($reason)<1||mb_strlen($reason)>256||preg_match('/[\x00-\x1f\x7f]/',$reason))throw new Problem('refund_review_input_invalid');
        $refund=$this->load($id);
        try{
            return Database::transaction($this->db,function(PDO $db)use($adminId,$email,$refund,$id,$action,$reason,$key,$context,$now):array{
                $q=$db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$refund['user_id']]);
                $q=$db->prepare('SELECT * FROM portal_orders WHERE id=? FOR UPDATE');$q->execute([$refund['order_id']]);$order=$q->fetch();
                $q=$db->prepare('SELECT * FROM portal_wechat_refund_states WHERE id=? FOR UPDATE');$q->execute([$id]);$state=$q->fetch();
                $q=$db->prepare('SELECT * FROM portal_wechat_refund_review_actions WHERE refund_id=? AND idempotency_key=? FOR SHARE');$q->execute([$id,$key]);$old=$q->fetch();
                if($old){
                    if($old['action']!==$action||$old['reason']!==$reason)throw new Problem('idempotency_conflict',409);
                    return ['id'=>$old['id'],'refund_id'=>$id,'result_mode'=>$old['result_mode']];
                }
                // Current reads follow the shared account/order locks, including a concurrent callback.
                $current=$this->load($id,true);
                if($current['cancelled']||$current['confirmed']||$state['status']==='succeeded')throw new Problem('refund_review_terminal',409);
                if((int)$state['lease_until']>$now)throw new Problem('refund_review_busy',409);
                if($action==='cancel_unsent'&&($state['status']!=='pending'||(int)$state['attempts']!==0||$state['last_sent_at']!==null))throw new Problem('refund_already_attempted',409);
                $mode=match($action){'cancel_unsent'=>'cancelled','pause_retry'=>'query_only',default=>'automatic'};
                if($action!=='cancel_unsent'&&$state['retry_mode']===$mode)throw new Problem('refund_review_mode_unchanged',409);
                $evidence=json_encode(['status'=>$state['status'],'attempts'=>(int)$state['attempts'],'last_sent_at'=>$state['last_sent_at'],
                    'lease_until'=>(int)$state['lease_until'],'retry_mode'=>$state['retry_mode'],'payment_id'=>$current['payment_id'],
                    'amount'=>$current['amount'],'confirmed'=>false],JSON_THROW_ON_ERROR);
                $actionId=bin2hex(random_bytes(16));
                $db->prepare('INSERT INTO portal_wechat_refund_review_actions (id,refund_id,action,reason,idempotency_key,result_mode,operator_id,evidence_hash,details_json,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$actionId,$id,$action,$reason,$key,$mode,$adminId,hash('sha256',$evidence),$evidence,$now]);
                if($action==='cancel_unsent'){
                    $db->prepare('INSERT INTO portal_wechat_refund_cancellations (refund_id,action_id,created_at) VALUES (?,?,?)')->execute([$id,$actionId,$now]);
                    $q=$db->prepare('SELECT payment_id FROM portal_wechat_fulfillments WHERE order_id=?');$q->execute([$order['id']]);
                    if($q->fetchColumn()===$current['payment_id']&&$order['status']==='refund_pending'){
                        $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM portal_wechat_refund_receipts WHERE payment_id=?');$q->execute([$current['payment_id']]);$amount=(int)$q->fetchColumn();
                        if($amount!==(int)$order['refunded_amount']||$amount>=(int)$order['amount'])throw new Problem('refund_review_evidence_invalid',409);
                        $db->prepare('UPDATE portal_orders SET status=?,updated_at=? WHERE id=?')->execute([$amount===0?'paid':'partially_refunded',$now,$order['id']]);
                    }
                }else{
                    $db->prepare('UPDATE portal_wechat_refund_states SET retry_mode=? WHERE id=?')->execute([$mode,$id]);
                }
                $db->prepare('UPDATE portal_wechat_refund_states SET lease_token=NULL,lease_until=0,updated_at=? WHERE id=?')->execute([$now,$id]);
                Audit::admin($db,$adminId,$email,'REVIEW_WECHAT_REFUND',$id,$context,'SUCCESS',
                    ['action_id'=>$actionId,'action'=>$action,'mode'=>$mode,'order_id'=>$current['order_id'],'amount'=>$current['amount']],$now);
                return ['id'=>$actionId,'refund_id'=>$id,'result_mode'=>$mode];
            });
        }catch(\Throwable $error){
            Audit::admin($this->db,$adminId,$email,'REVIEW_WECHAT_REFUND',$id,$context,'FAILURE',['action'=>$action],$now);throw $error;
        }
    }

    private function load(string $id,bool $locking=false): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('refund_not_found',404);
        $q=$this->db->prepare('SELECT r.id,r.order_id,r.payment_id,r.amount,o.user_id,s.status,s.retry_mode,s.attempts,s.last_sent_at,s.lease_until,
            (c.refund_id IS NOT NULL) AS cancelled,(f.id IS NOT NULL) AS confirmed
            FROM portal_wechat_refund_requests r JOIN portal_wechat_refund_states s ON s.id=r.id JOIN portal_orders o ON o.id=r.order_id
            LEFT JOIN portal_wechat_refund_cancellations c ON c.refund_id=r.id LEFT JOIN portal_wechat_refund_receipts f ON f.id=r.id
            WHERE r.id=?'.($locking?' FOR SHARE':''));$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new Problem('refund_not_found',404);
        foreach(['amount','user_id','attempts','lease_until']as$key)$row[$key]=(int)$row[$key];
        $row['cancelled']=(bool)$row['cancelled'];$row['confirmed']=(bool)$row['confirmed'];return $row;
    }

    private function admin(int $id): string
    {
        Permissions::require($this->db,$id,'refunds.resolve');
        $q=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$id]);$email=$q->fetchColumn();
        if(!is_string($email))throw new Problem('admin_auth_invalid',401);return $email;
    }
}
