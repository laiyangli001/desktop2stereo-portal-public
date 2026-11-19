<?php
declare(strict_types=1);

// Run only as an operator with account-provisioning privileges, never under PHP-FPM.
require dirname(__DIR__) . '/includes/bootstrap/app.php';

$created = false;
$admin = null;
$account = null;
try {
    $user = getenv('D2S_PROVISION_USER');
    $password = getenv('D2S_PROVISION_PASSWORD');
    $role = getenv('D2S_PROVISION_ROLE');
    if (!is_string($user) || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $user) ||
        !is_string($password) || strlen($password) < 24 ||
        !in_array($role, ['runtime', 'retention'], true)) {
        throw new RuntimeException('provision_user_role_and_long_random_password_required');
    }
    $admin = D2S\repositories\Database::fromEnvironment('D2S_PROVISION_DB_');
    $database = (string)$admin->query('SELECT DATABASE()')->fetchColumn();
    $account = $admin->quote($user) . "@'127.0.0.1'";
    $admin->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $admin->quote($password));
    $created = true;
    D2S\services\DatabasePermissions::grant($admin, $database, $user, $role);
    echo "account_provisioned\n";
} catch (Throwable $error) {
    // Remove only the exact account created by this invocation if grants failed.
    if ($created && $admin !== null && $account !== null) {
        try {
            $admin->exec('DROP USER ' . $account);
        } catch (Throwable) {
            fwrite(STDERR, "provision_cleanup_requires_operator_review\n");
        }
    }
    fwrite(STDERR, $error instanceof PDOException ? "account_provision_failed\n" : $error->getMessage() . "\n");
    exit(1);
}
