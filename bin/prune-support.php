<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\SecretBox;
use D2S\services\SupportTickets;

if (!in_array('--apply', $argv, true)) {
    fwrite(STDERR, "usage: php bin/prune-support.php --apply\n");
    exit(2);
}

try {
    // This connection must use the dedicated retention account, never PHP-FPM's runtime account.
    $db = Database::fromEnvironment('D2S_RETENTION_DB_');
    $service = new SupportTickets($db,null,SecretBox::fromEnvironment());
    $now = time();
    $deleted = 0;
    for ($batch = 0; $batch < 10; $batch++) {
        $result = $service->pruneClosed($db,$now,100);
        if ($result['busy']) {
            fwrite(STDOUT, "busy\n");
            exit(0);
        }
        $deleted += (int)$result['deleted'];
        if ((int)$result['deleted'] === 0) {
            break;
        }
    }
    fwrite(STDOUT, 'deleted='.$deleted."\n");
} catch (Throwable $error) {
    fwrite(STDERR, "support_retention_failed\n");
    exit(1);
}
