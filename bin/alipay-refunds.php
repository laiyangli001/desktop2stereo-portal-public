<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';

try {
    $db = D2S\repositories\Database::fromEnvironment();
    $refunds = new D2S\services\AlipayRefunds($db,new D2S\services\PaymentProfiles($db,D2S\services\SecretBox::fromEnvironment()));
    $result = (new D2S\services\AlipayRefundReconciler($db,$refunds))->run(time());
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
    if ($result['failed']>0) { exit(1); }
} catch (Throwable) {
    fwrite(STDERR,"alipay_refunds_unavailable\n");
    exit(1);
}
