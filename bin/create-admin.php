<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

use D2S\domain\AccountInput;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\Permissions;

try {
    $options = getopt('', ['username:', 'email:', 'roles:']);
    $username = AccountInput::username($options['username'] ?? '');
    $email = AccountInput::contact('email', $options['email'] ?? '');
    $password = AccountInput::password(getenv('D2S_BOOTSTRAP_PASSWORD') ?: '', true);
    $roles = array_filter(explode(',', $options['roles'] ?? ''));
    if ($roles === [] || array_diff($roles, array_keys(Permissions::ROLES)) !== []) {
        throw new RuntimeException('explicit_known_roles_required');
    }
    $db = Database::fromEnvironment('D2S_ADMIN_DB_');
    $id = Database::transaction($db, static function (PDO $db) use ($username, $email, $password, $roles): int {
        $insert = $db->prepare('INSERT INTO portal_admins (username,email,password_hash,created_at) VALUES (?,?,?,?)');
        $insert->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), time()]);
        $id = (int)$db->lastInsertId();
        $permissions = [];
        foreach ($roles as $role) {
            $permissions = array_merge($permissions, Permissions::ROLES[$role]);
        }
        $grant = $db->prepare('INSERT INTO portal_admin_permissions VALUES (?,?)');
        foreach (array_unique($permissions) as $permission) {
            $grant->execute([$id, $permission]);
        }
        Audit::admin($db, $id, $email, 'CREATE_ADMIN', (string)$id, [], 'SUCCESS', ['roles' => array_values($roles), 'via' => 'operator_cli'], time());
        return $id;
    });
    echo json_encode(['admin_id' => $id], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "admin_creation_failed\n" : $error->getMessage() . "\n");
    exit(1);
} finally {
    putenv('D2S_BOOTSTRAP_PASSWORD');
}
