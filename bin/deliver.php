<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

try {
    $db = D2S\repositories\Database::fromEnvironment();
    $secrets = D2S\services\SecretBox::fromEnvironment();
    $configuration = (new D2S\services\DeliverySettings($db,$secrets))->load();
    $worker = new D2S\services\DeliveryWorker($db, $secrets, new D2S\services\DeliveryGateway($configuration));
    $processed = 0;
    while ($processed < 50 && $worker->once(time())) {
        $processed++;
    }
    echo json_encode(['processed' => $processed], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "delivery_worker_unavailable\n");
    exit(1);
}
