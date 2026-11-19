<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';
try{
    $db=D2S\repositories\Database::fromEnvironment();
    $profiles=new D2S\services\WechatProfiles($db,D2S\services\SecretBox::fromEnvironment());
    $result=(new D2S\services\WechatRefundReconciler($db,new D2S\services\WechatRefunds($db,$profiles)))->run(time());
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    if($result['failed']>0)exit(1);
}catch(Throwable){fwrite(STDERR,"wechat_refund_reconciliation_unavailable\n");exit(1);}
