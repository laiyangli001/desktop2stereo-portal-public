<?php
declare(strict_types=1);

namespace D2S\repositories;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Adapted from the PDO/transaction approach of 东方商城系统 includes/lib/DB.php.
 * Site scoping, dynamic SQL builders and permissive SQL modes are omitted.
 */
final class Database
{
    private static ?\WeakMap $connections=null;
    public static function fromEnvironment(string $prefix = 'D2S_DB_'): PDO
    {
        $dsn = getenv($prefix . 'DSN');
        $user = getenv($prefix . 'USER');
        $password = getenv($prefix . 'PASSWORD');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') ||
            !preg_match('/(?:^|;)charset=utf8mb4(?:;|$)/D', substr($dsn, 6)) ||
            !is_string($user) || $user === '' || $password === false) {
            throw new RuntimeException('database_configuration_required');
        }
        return self::connect($dsn,$user,$password);
    }

    public static function connect(string $dsn,string $user,string $password): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$connections ??= new \WeakMap();
        self::$connections[$pdo]=static fn():PDO=>self::connect($dsn,$user,$password);
        return $pdo;
    }

    /** A separate PDO keeps sequence commits independent of the caller's business transaction. */
    public static function independent(PDO $connection): PDO
    {
        $factory=self::$connections[$connection]??null;
        if($factory===null)throw new RuntimeException('independent_database_connection_required');
        $database=$connection->query('SELECT DATABASE()')->fetchColumn();
        if(!is_string($database)||$database==='')throw new RuntimeException('selected_database_required');
        $copy=$factory();
        $copy->exec('USE `'.str_replace('`','``',$database).'`');
        return $copy;
    }

    public static function transaction(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('nested_transaction_not_supported');
        }
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Join an explicitly managed transaction or own one when called directly. */
    public static function transactionOrJoin(PDO $pdo, callable $work): mixed
    {
        return $pdo->inTransaction() ? $work($pdo) : self::transaction($pdo, $work);
    }
}
