<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';

try {
    $db = D2S\repositories\Database::fromEnvironment();
    $profiles = new D2S\services\PaymentProfiles($db,D2S\services\SecretBox::fromEnvironment());
    $worker = new D2S\services\AlipayReconciler($db,new D2S\services\AlipayPayments($db,$profiles));
    $result = $worker->run(time());
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    if ($result['failed']>0) { exit(1); }
} catch (Throwable) {
    fwrite(STDERR,"alipay_reconciliation_unavailable\n");
    exit(1);
}
