<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';
try{
    $result=(new D2S\services\ExternalPayments(D2S\repositories\Database::fromEnvironment(),D2S\services\SecretBox::fromEnvironment()))->run(time());
    echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;exit($result['failed']>0?1:0);
}catch(Throwable $error){fwrite(STDERR,$error instanceof PDOException?'international_payment_worker_failed'.PHP_EOL:$error->getMessage().PHP_EOL);exit(1);}
