<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';
try {
    $db=D2S\repositories\Database::fromEnvironment();
    $count=(new D2S\services\ReferralProgram($db,D2S\services\SecretBox::fromEnvironment()))->settle(time());
    echo json_encode(['released'=>$count],JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,$error instanceof PDOException?'referral_settlement_failed'.PHP_EOL:$error->getMessage().PHP_EOL);exit(1);}
