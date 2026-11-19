<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use PDO;

/** Delivery advances approved refunds only; abnormal/closed entries remain query-only. */
final readonly class WechatRefundReconciler
{
    public function __construct(private PDO $db,private WechatRefunds $refunds) {}

    public function run(int $now,int $limit=10): array
    {
        if($now<0||$limit<1||$limit>50||$this->db->inTransaction())throw new Problem('reconciliation_arguments_invalid');
        $name='d2s:wx-refunds:'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);
        $result=['busy'=>false,'processed'=>0,'confirmed'=>0,'pending'=>0,'failed'=>0];
        if((int)$lock->fetchColumn()!==1)return array_replace($result,['busy'=>true]);
        try{
            $select=$this->db->prepare("SELECT id FROM portal_wechat_refund_states WHERE status<>'succeeded' AND next_attempt_at<=? AND lease_until<=?
                AND NOT EXISTS(SELECT 1 FROM portal_wechat_refund_cancellations c WHERE c.refund_id=portal_wechat_refund_states.id)
                ORDER BY next_attempt_at,id LIMIT ".$limit);
            $select->execute([$now,$now]);$started=hrtime(true);
            foreach($select->fetchAll(PDO::FETCH_COLUMN)as$id){
                $attemptNow=$now+(int)((hrtime(true)-$started)/1000000000);
                try{
                    $state=$this->refunds->process($id,['request_path'=>'/cli/wechat-refunds'],$attemptNow);
                    $result['processed']++;$result[$state['status']==='succeeded'?'confirmed':'pending']++;
                }catch(\Throwable){$result['failed']++;}
            }
            return $result;
        }finally{$release=$this->db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);}
    }
}
