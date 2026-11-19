<?php
declare(strict_types=1);
namespace D2S\services;

use DateTimeImmutable;
use DateTimeZone;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Uses an independent committed connection: gaps are allowed, reuse is forbidden. */
final readonly class OrderNumbers
{
    public const TIME_ZONE='Asia/Shanghai';
    public const MAX_PER_SECOND=9999;
    private int $maxRollbackSeconds;

    public function __construct(private PDO $sequence,?int $maxRollbackSeconds=null)
    {
        $value=$maxRollbackSeconds ?? (int)(getenv('D2S_ORDER_CLOCK_MAX_ROLLBACK_SECONDS') ?: 5);
        if($value<0||$value>86400)throw new Problem('order_number_clock_policy_invalid');
        $this->maxRollbackSeconds=$value;
    }

    public function allocate(string $orderId,int $now): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId)||$now<0)throw new Problem('order_number_input_invalid');
        $date=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone(self::TIME_ZONE));
        if((int)$date->format('Y')<2000||(int)$date->format('Y')>2099)throw new Problem('order_number_date_out_of_range');
        return Database::transaction($this->sequence,function(PDO $db)use($orderId,$now,$date):string{
            $db->prepare('INSERT INTO portal_order_number_clock (id,last_second,updated_at) VALUES (1,0,0) ON DUPLICATE KEY UPDATE id=id')->execute();
            $clock=$db->query('SELECT last_second FROM portal_order_number_clock WHERE id=1 FOR UPDATE');
            $lastSecond=(int)$clock->fetchColumn();
            // Idempotent retries keep their committed number even if the wall clock moved backwards later.
            $old=self::forOrder($db,$orderId);
            if($old!==null)return $old;
            if($now+$this->maxRollbackSeconds<$lastSecond)throw new Problem('order_number_clock_rollback',503);
            // UTC epoch seconds are the shared counter key; display uses the fixed business timezone.
            $db->prepare('INSERT INTO portal_order_number_seconds (second_at,serial_value) VALUES (?,0) ON DUPLICATE KEY UPDATE serial_value=serial_value')->execute([$now]);
            $q=$db->prepare('SELECT serial_value FROM portal_order_number_seconds WHERE second_at=? FOR UPDATE');$q->execute([$now]);$last=(int)$q->fetchColumn();
            if($last>=self::MAX_PER_SECOND)throw new Problem('order_number_capacity_exceeded',503);
            $next=$last+1;$number=$date->format('ymdHis').str_pad((string)$next,4,'0',STR_PAD_LEFT);
            $db->prepare('UPDATE portal_order_number_seconds SET serial_value=? WHERE second_at=?')->execute([$next,$now]);
            $db->prepare('INSERT INTO portal_order_numbers (order_id,order_no,allocated_at) VALUES (?,?,?)')->execute([$orderId,$number,$now]);
            $db->prepare('UPDATE portal_order_number_clock SET last_second=?,updated_at=? WHERE id=1')
                ->execute([max($lastSecond,$now),$now]);
            return $number;
        });
    }

    /** Persist at creation, never infer a provider cutover from a display number on an older order. */
    public static function bindMerchant(PDO $db,string $orderId,string $method,string $number,int $now): void
    {
        if(!$db->inTransaction()||!in_array($method,['alipay_pc','wechat_native'],true))throw new Problem('payment_order_reference_invalid');
        $db->prepare('INSERT INTO portal_merchant_order_numbers (order_id,payment_method,merchant_order_no,created_at) VALUES (?,?,?,?)')
            ->execute([$orderId,$method,$number,$now]);
    }

    public static function merchant(PDO $db,string $orderId,string $method): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId)||!in_array($method,['alipay_pc','wechat_native'],true))throw new Problem('payment_order_reference_invalid');
        $q=$db->prepare('SELECT payment_method,merchant_order_no FROM portal_merchant_order_numbers WHERE order_id=?');$q->execute([$orderId]);$row=$q->fetch();
        if($row){
            if($row['payment_method']!==$method)throw new Problem('payment_order_reference_invalid');
            return $row['merchant_order_no'];
        }
        return $method==='alipay_pc'?'D2S'.$orderId:$orderId;
    }

    public static function resolveMerchant(PDO $db,string $number,string $method): ?string
    {
        if(preg_match('/^[0-9]{16}$/D',$number)){
            $q=$db->prepare('SELECT order_id FROM portal_merchant_order_numbers WHERE merchant_order_no=? AND payment_method=?');$q->execute([$number,$method]);
            $id=$q->fetchColumn();return $id===false?null:$id;
        }
        $pattern=$method==='alipay_pc'?'/^D2S([a-f0-9]{32})$/D':'/^([a-f0-9]{32})$/D';
        if(!preg_match($pattern,$number,$match))return null;
        try{return self::merchant($db,$match[1],$method)===$number?$match[1]:null;}
        catch(Problem){return null;}
    }

    public static function forOrder(PDO $db,string $orderId): ?string
    {
        $q=$db->prepare('SELECT order_no FROM portal_order_numbers WHERE order_id=?'.($db->inTransaction()?' FOR SHARE':''));$q->execute([$orderId]);$number=$q->fetchColumn();
        return $number===false?null:$number;
    }
}
