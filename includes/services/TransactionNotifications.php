<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\HistoryCursor;
use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use RuntimeException;

final readonly class TransactionNotifications
{
    public function __construct(private PDO $db) {}

    /** Source transaction owns user/order locks; notification and outbox commit with the money/entitlement. */
    public static function record(PDO $db, array $order, string $type, string $source, int $amount, string $effect, int $now): string
    {
        if (!$db->inTransaction()) throw new RuntimeException('notification_transaction_required');
        Money::positive($amount);Money::currency($order['currency']);
        if (!in_array($type,['ORDER_PAID','PAYMENT_REVIEW','REFUND_CONFIRMED'],true) ||
            !in_array($effect,['activated','revoked','unchanged'],true) || !preg_match('/^[a-z_]+:[a-f0-9]{32,64}$/D',$source) ||
            strlen($source)>100 || $amount>(int)$order['amount']) throw new Problem('notification_event_invalid');
        $q=$db->prepare('SELECT * FROM portal_transaction_notifications WHERE source_key=?');$q->execute([$source]);$old=$q->fetch();
        if ($old) {
            foreach(['user_id'=>(int)$order['user_id'],'order_id'=>$order['id'],'event_type'=>$type,'amount'=>$amount,
                'currency'=>$order['currency'],'license_effect'=>$effect]as$key=>$value) {
                $actual=in_array($key,['user_id','amount'],true)?(int)$old[$key]:$old[$key];
                if ($actual!==$value) throw new Problem('notification_source_conflict',409);
            }
            return $old['id'];
        }
        if($type==='REFUND_CONFIRMED')ReferralProgram::reverse($db,$order['id'],$now);
        $registry=new LocaleRegistry();
        $localeSnapshot=$order['locale_snapshot']??null;
        if(!is_string($localeSnapshot)){
            $localeQuery=$db->prepare('SELECT locale_snapshot FROM portal_orders WHERE id=?');
            $localeQuery->execute([$order['id']]);
            $localeSnapshot=$localeQuery->fetchColumn();
        }
        $locale=$registry->normalize(is_string($localeSnapshot)?$localeSnapshot:'')??$registry->defaultLocale();
        $id=bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO portal_transaction_notifications
            (id,source_key,user_id,order_id,event_type,amount,currency,license_effect,locale_snapshot,template_version,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,1,?)')
            ->execute([$id,$source,$order['user_id'],$order['id'],$type,$amount,$order['currency'],$effect,$locale,$now]);
        $db->prepare("INSERT INTO portal_notification_outbox (notification_id,status,available_at,expires_at,updated_at)
            VALUES (?,'pending',?,?,?)")->execute([$id,$now,$now+604800,$now]);
        return $id;
    }

    public function paymentHistory(int $user, ?string $cursor=null): array
    {
        $position=HistoryCursor::decode($cursor,'payments',$user);$parameters=[$user];$filter='';
        if($position!==null){
            $filter=' AND (paid_at<? OR (paid_at=? AND id<?))';
            array_push($parameters,$position['time'],$position['time'],$position['id']);
        }
        $q=$this->db->prepare("SELECT id,order_no,amount,currency,payment_method,paid_at FROM portal_orders
            WHERE user_id=? AND paid_at IS NOT NULL AND status IN ('paid','partially_refunded','refund_pending','refunded')".$filter.' ORDER BY paid_at DESC,id DESC LIMIT 51');
        $q->execute($parameters);$rows=$q->fetchAll();$next=null;
        if(count($rows)>50){
            array_pop($rows);$last=end($rows);
            $next=HistoryCursor::encode('payments',$user,$last['id'],(int)$last['paid_at']);
        }
        foreach($rows as &$row){$row['order_no']??=$row['id'];unset($row['id']);}
        unset($row);
        return ['payments'=>$rows,'next_cursor'=>$next];
    }

    public function inbox(int $user, ?string $cursor=null): array
    {
        $position=HistoryCursor::decode($cursor,'notifications',$user);$parameters=[$user];$filter='';
        if ($position!==null) {
            $filter=' AND (n.created_at<? OR (n.created_at=? AND n.id<?))';
            array_push($parameters,$position['time'],$position['time'],$position['id']);
        }
        $q=$this->db->prepare('SELECT n.id,n.order_id,o.order_no,n.event_type,n.amount,n.currency,n.license_effect,n.created_at,r.read_at
            FROM portal_transaction_notifications n LEFT JOIN portal_orders o ON o.id=n.order_id
            LEFT JOIN portal_notification_reads r ON r.notification_id=n.id
            WHERE n.user_id=?'.$filter.' ORDER BY n.created_at DESC,n.id DESC LIMIT 51');$q->execute($parameters);$rows=$q->fetchAll();
        $more=count($rows)>50;if($more)array_pop($rows);$last=$rows===[]?null:$rows[array_key_last($rows)];
        $q=$this->db->prepare('SELECT COUNT(*) FROM portal_transaction_notifications n LEFT JOIN portal_notification_reads r ON r.notification_id=n.id
            WHERE n.user_id=? AND r.notification_id IS NULL');$q->execute([$user]);
        return ['notifications'=>$rows,'unread'=>(int)$q->fetchColumn(),'per_page'=>50,
            'next_cursor'=>$more?HistoryCursor::encode('notifications',$user,$last['id'],(int)$last['created_at']):null];
    }

    public function read(int $user, string $id, int $now): void
    {
        self::id($id);
        Database::transaction($this->db,function(PDO $db)use($user,$id,$now):void{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$user]);
            $q=$db->prepare('SELECT id FROM portal_transaction_notifications WHERE id=? AND user_id=?');$q->execute([$id,$user]);
            if(!$q->fetchColumn())throw new Problem('notification_not_found',404);
            $q=$db->prepare('SELECT notification_id FROM portal_notification_reads WHERE notification_id=?');$q->execute([$id]);
            if(!$q->fetchColumn())$db->prepare('INSERT INTO portal_notification_reads (notification_id,read_at) VALUES (?,?)')->execute([$id,$now]);
        });
    }

    public function deliveries(int $admin, int $page, ?string $status, array $context, int $now): array
    {
        $email=$this->admin($admin);
        if($page<1||$page>100000)throw new Problem('page_invalid');
        $allowed=['pending','processing','retry','sent','blocked','failed'];
        if($status!==null&&!in_array($status,$allowed,true))throw new Problem('notification_status_invalid');
        return Database::transaction($this->db,function(PDO $db)use($admin,$email,$page,$status,$context,$now):array{
            $filter=$status===null?'':' WHERE q.status='.$db->quote($status);
            $rows=$db->query('SELECT n.id,n.user_id,n.order_id,o.order_no,n.event_type,n.created_at,q.status,q.attempts,q.total_attempts,
                q.available_at,q.expires_at,q.last_error,q.channel FROM portal_transaction_notifications n
                LEFT JOIN portal_orders o ON o.id=n.order_id
                JOIN portal_notification_outbox q ON q.notification_id=n.id'.$filter.' ORDER BY n.created_at DESC,n.id DESC LIMIT 50 OFFSET '.(($page-1)*50))->fetchAll();
            $total=(int)$db->query('SELECT COUNT(*) FROM portal_notification_outbox q'.$filter)->fetchColumn();
            Audit::admin($db,$admin,$email,'VIEW_NOTIFICATION_DELIVERY',null,$context,'SUCCESS',['status'=>$status,'page'=>$page,'count'=>count($rows)],$now);
            return ['deliveries'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>50];
        });
    }

    public function retry(int $admin, string $id, string $reason, string $key, array $context, int $now): array
    {
        $email=$this->admin($admin);self::id($id);$reason=trim($reason);
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key)||!mb_check_encoding($reason,'UTF-8')||
            mb_strlen($reason,'UTF-8')<1||mb_strlen($reason,'UTF-8')>256||preg_match('/[\x00-\x1f\x7f]/',$reason))
            throw new Problem('notification_retry_invalid');
        return Database::transaction($this->db,function(PDO $db)use($admin,$email,$id,$reason,$key,$context,$now):array{
            $q=$db->prepare('SELECT user_id FROM portal_transaction_notifications WHERE id=?');$q->execute([$id]);$user=$q->fetchColumn();
            if($user===false)throw new Problem('notification_not_found',404);
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$user]);
            $q=$db->prepare('SELECT * FROM portal_notification_outbox WHERE notification_id=? FOR UPDATE');$q->execute([$id]);$job=$q->fetch();
            // The earlier owner lookup may have established an older REPEATABLE READ snapshot.
            $q=$db->prepare('SELECT * FROM portal_notification_retries WHERE notification_id=? AND idempotency_key=? FOR SHARE');$q->execute([$id,$key]);$old=$q->fetch();
            if($old){
                if($old['reason']!==$reason)throw new Problem('idempotency_conflict',409);
                return ['id'=>$old['id'],'queued'=>true];
            }
            if(!$job||!in_array($job['status'],['blocked','failed'],true)||(int)$job['lease_until']>$now)
                throw new Problem('notification_retry_not_allowed',409);
            $retry=bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_notification_retries (id,notification_id,idempotency_key,reason,operator_id,created_at)
                VALUES (?,?,?,?,?,?)')->execute([$retry,$id,$key,$reason,$admin,$now]);
            $db->prepare("UPDATE portal_notification_outbox SET status='pending',attempts=0,available_at=?,expires_at=?,lease_token=NULL,
                lease_until=0,last_error=NULL,updated_at=? WHERE notification_id=?")->execute([$now,$now+604800,$now,$id]);
            Audit::admin($db,$admin,$email,'RETRY_NOTIFICATION',$id,$context,'SUCCESS',['retry_id'=>$retry],$now);
            return ['id'=>$retry,'queued'=>true];
        });
    }

    private function admin(int $id): string
    {
        Permissions::require($this->db,$id,'notifications.manage');
        $q=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$id]);$email=$q->fetchColumn();
        if(!is_string($email))throw new Problem('admin_auth_invalid',401);
        return $email;
    }
    private static function id(string $id):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('notification_not_found',404);
    }
}
