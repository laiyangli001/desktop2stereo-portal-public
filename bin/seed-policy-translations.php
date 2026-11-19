<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

try {
    $db = D2S\repositories\Database::fromEnvironment('D2S_MIGRATION_DB_');
    $policies = new D2S\services\PolicyTranslations($db);
    $seeded = $policies->seedLegacyCurrentVersions();
    echo json_encode(['seeded_revisions'=>$seeded],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,$error instanceof PDOException ? "database_connection_or_permission_failed\n" : $error->getMessage()."\n");
    exit(1);
}
