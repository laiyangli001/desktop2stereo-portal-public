<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Problem;
use PDO;

/** Shared by Native creation/recovery and closing; callbacks only need normal account/order locks. */
final class WechatOrderLock
{
    public static function run(PDO $db,string $id,Closure $work,string $busy='wechat_order_busy'): mixed
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new Problem('wechat_order_invalid');
        if($db->inTransaction())throw new Problem('wechat_order_lock_transaction_invalid',409);
        $scope=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        $name='d2s.wx.'.substr(hash('sha256',$scope),0,12).'.'.$id;
        $lock=$db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);
        if((int)$lock->fetchColumn()!==1)throw new Problem($busy,409);
        try{return $work();}
        finally{$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);}
    }
}
