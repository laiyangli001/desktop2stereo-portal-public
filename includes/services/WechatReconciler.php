<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

/** One database-scoped worker; bounded batches and persisted retry cooldowns. */
final readonly class WechatReconciler
{
    public function __construct(private PDO $db,private WechatPayments $payments) {}

    public function run(int $now,int $limit=10): array
    {
        if($now<0||$limit<1||$limit>50||$this->db->inTransaction())throw new Problem('reconciliation_arguments_invalid');
        $name='d2s:wechat:'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);
        if((int)$lock->fetchColumn()!==1)return ['busy'=>true,'processed'=>0,'failed'=>0];
        try{
            $select=$this->db->prepare("SELECT o.id,COALESCE(MAX(e.received_at),o.created_at) AS checked_at
                FROM portal_orders o LEFT JOIN portal_wechat_events e ON e.order_id=o.id
                    AND (e.event_type LIKE 'QUERY:%' OR e.event_type LIKE 'RECONCILE:%')
                WHERE o.status='pending' AND o.payment_method='wechat_native' AND EXISTS(SELECT 1 FROM portal_products item WHERE item.code=o.product)
                GROUP BY o.id,o.created_at HAVING checked_at<=? ORDER BY checked_at,o.id LIMIT ".$limit);
            $select->execute([$now-60]);$ids=$select->fetchAll(PDO::FETCH_COLUMN);
            $processed=0;$failed=0;$started=hrtime(true);
            foreach($ids as$id){
                // A long batch must not reuse a stale timestamp for later signed requests.
                $attemptNow=$now+(int)((hrtime(true)-$started)/1000000000);
                try{
                    $this->db->prepare('INSERT INTO portal_wechat_events (id,order_id,event_type,event_hash,source_ip,result,received_at) VALUES (?,?,?,?,NULL,?,?)')
                        ->execute([hash('sha256',random_bytes(32)),$id,'RECONCILE:ATTEMPT',hash('sha256','ATTEMPT'),'PENDING',$attemptNow]);
                    $result=$this->payments->closeExpired($id,['request_path'=>'/cli/wechat-reconcile'],$attemptNow);
                    if(in_array($result['close_status']??'', ['unknown','pending'],true)||($result['provider_status']??'')==='NOT_FOUND')$failed++;
                    else $processed++;
                }catch(\Throwable){$failed++;}
            }
            return ['busy'=>false,'processed'=>$processed,'failed'=>$failed];
        }finally{$release=$this->db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);}
    }
}
