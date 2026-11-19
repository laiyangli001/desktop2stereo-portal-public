<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';
try {
    $db=D2S\repositories\Database::fromEnvironment();
    $config=(new D2S\services\DeliverySettings($db,D2S\services\SecretBox::fromEnvironment()))->load();
    $worker=new D2S\services\TransactionNotificationWorker($db,new D2S\services\TransactionNotificationGateway($config));
    $result=['processed'=>0,'sent'=>0,'retry'=>0,'blocked'=>0,'failed'=>0,'busy'=>0,'lease_lost'=>0];
    for($i=0;$i<10;$i++){
        $job=$worker->once(time());if($job===null)break;
        $result['processed']++;$result[$job['status']]++;
    }
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    if($result['failed']>0||$result['blocked']>0||$result['lease_lost']>0)exit(1);
}catch(Throwable){fwrite(STDERR,"transaction_notification_worker_unavailable\n");exit(1);}
