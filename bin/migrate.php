<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

try {
    $pdo = D2S\repositories\Database::fromEnvironment('D2S_MIGRATION_DB_');
    $versions = (new D2S\services\Migrator($pdo, D2S_ROOT . '/database/migrations'))->run();

    $runtimeDsn = getenv('D2S_DB_DSN') ?: '';
    $runtimeUser = getenv('D2S_DB_USER') ?: '';
    $migrationUser = getenv('D2S_MIGRATION_DB_USER') ?: '';
    if ($runtimeDsn === '' || $runtimeUser === '' || $migrationUser === '' || $runtimeUser === $migrationUser ||
        !preg_match('/(?:^|[:;])host=([^;]+)/', $runtimeDsn, $hostMatch) ||
        !preg_match('/(?:^|;)dbname=([A-Za-z0-9_]{1,64})(?:;|$)/', $runtimeDsn, $databaseMatch)) {
        throw new RuntimeException('runtime_database_configuration_invalid');
    }
    if ($hostMatch[1] !== '127.0.0.1') {
        throw new RuntimeException('runtime_database_host_unsupported');
    }
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '' || !hash_equals($database, $databaseMatch[1])) {
        throw new RuntimeException('runtime_database_target_mismatch');
    }
    D2S\services\DatabasePermissions::grantOperations($pdo, $database, $runtimeUser, 'runtime');

    echo json_encode(['applied' => $versions, 'runtime_operations_permissions_refreshed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "database_connection_or_permission_failed\n" : $error->getMessage() . "\n");
    exit(1);
}
