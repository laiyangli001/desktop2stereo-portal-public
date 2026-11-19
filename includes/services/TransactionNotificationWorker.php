<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use Throwable;

final readonly class TransactionNotificationWorker
{
    public function __construct(private PDO $db, private MessageSender $sender) {}

    public function once(int $now): ?array
    {
        $q=$this->db->prepare("SELECT notification_id FROM portal_notification_outbox WHERE available_at<=? AND
            (status IN ('pending','retry') OR (status='processing' AND lease_until<=?)) ORDER BY available_at,notification_id LIMIT 1");
        $q->execute([$now,$now]);$id=$q->fetchColumn();
        return is_string($id)?$this->process($id,$now):null;
    }

    public function process(string $id, int $now): array
    {
        $q=$this->db->prepare('SELECT n.*,o.order_no FROM portal_transaction_notifications n
            LEFT JOIN portal_orders o ON o.id=n.order_id WHERE n.id=?');$q->execute([$id]);$event=$q->fetch();
        if(!$event)throw new Problem('notification_not_found',404);
        $token=bin2hex(random_bytes(16));
        $job=Database::transaction($this->db,function(PDO $db)use($event,$id,$token,$now):?array{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$event['user_id']]);
            $q=$db->prepare('SELECT * FROM portal_notification_outbox WHERE notification_id=? FOR UPDATE');$q->execute([$id]);$job=$q->fetch();
            if(!$job||!in_array($job['status'],['pending','retry','processing'],true)||
                (int)$job['available_at']>$now||(int)$job['lease_until']>$now)return null;
            if((int)$job['expires_at']<=$now||(int)$job['attempts']>=8){
                $reason=(int)$job['expires_at']<=$now?'notification_expired':'notification_attempt_limit';
                $db->prepare("UPDATE portal_notification_outbox SET status='failed',last_error=?,lease_token=NULL,lease_until=0,updated_at=? WHERE notification_id=?")->execute([$reason,$now,$id]);
                Audit::admin($db,null,'','NOTIFY_TRANSACTION',$id,[],'FAILURE',['automatic'=>true,'reason'=>$reason],$now);
                return ['expired'=>true];
            }
            $db->prepare("UPDATE portal_notification_outbox SET status='processing',attempts=attempts+1,total_attempts=total_attempts+1,
                lease_token=?,lease_until=?,updated_at=? WHERE notification_id=?")->execute([$token,$now+120,$now,$id]);
            $q=$db->prepare("SELECT kind,value FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL ORDER BY (kind='email') DESC,id LIMIT 1");
            $q->execute([$event['user_id']]);$contact=$q->fetch();
            Audit::admin($db,null,'','NOTIFY_TRANSACTION',$id,[],'PENDING',['automatic'=>true,'attempt_id'=>$token,'channel'=>$contact['kind']??null],$now);
            return ['attempts'=>(int)$job['attempts']+1,'contact'=>$contact];
        });
        if($job===null)return ['status'=>'busy'];
        if(isset($job['expired']))return ['status'=>'failed'];
        $status='sent';$error=null;
        try {
            if(!$job['contact'])throw new Problem('notification_recipient_missing',503);
            $message=array_intersect_key($event,array_flip(['id','order_id','order_no','event_type','amount','currency','license_effect','locale_snapshot','template_version','created_at']));
            $message['amount']=(int)$message['amount'];
            $this->sender->send($message+['kind'=>$job['contact']['kind'],'target'=>$job['contact']['value']]);
        }catch(Throwable $failure){
            $blocked=$failure instanceof Problem&&in_array($failure->error,['notification_channel_unconfigured','notification_recipient_missing'],true);
            $status=$blocked?'blocked':($job['attempts']>=8?'failed':'retry');
            // Provider failures can include credentials, phone numbers or bodies. Persist only fixed codes.
            $error=$blocked?$failure->error:'notification_delivery_failed';
        }
        return Database::transaction($this->db,function(PDO $db)use($event,$id,$token,$job,$now,$status,$error):array{
            $db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE')->execute([$event['user_id']]);
            $q=$db->prepare('SELECT lease_token FROM portal_notification_outbox WHERE notification_id=? FOR UPDATE');$q->execute([$id]);
            if($q->fetchColumn()!==$token){
                Audit::admin($db,null,'','NOTIFY_TRANSACTION',$id,[],'PENDING',['automatic'=>true,'attempt_id'=>$token,'lease_lost'=>true],$now);
                return ['status'=>'lease_lost'];
            }
            $db->prepare('UPDATE portal_notification_outbox SET status=?,available_at=?,lease_token=NULL,lease_until=0,
                last_error=?,channel=?,updated_at=? WHERE notification_id=?')->execute([$status,$now+min(21600,60*(2**min(8,$job['attempts']))),
                    $error,$job['contact']['kind']??null,$now,$id]);
            Audit::admin($db,null,'','NOTIFY_TRANSACTION',$id,[],$status==='sent'?'SUCCESS':($status==='blocked'?'BLOCKED':'FAILURE'),
                ['automatic'=>true,'attempt_id'=>$token,'status'=>$status],$now);
            return ['status'=>$status];
        });
    }
}
