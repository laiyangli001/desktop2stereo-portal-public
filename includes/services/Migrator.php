<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use RuntimeException;
use Throwable;

/** MySQL DDL auto-commits: journal attempts and stop on partial failures. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory, private ?string $resourceRoot = null)
    {
    }

    public function run(): array
    {
        $name = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name)) {
            throw new RuntimeException('invalid_database_name');
        }
        $lock = 'd2s-migrate-' . substr(hash('sha256', $name), 0, 40);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$lock]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('migration_lock_unavailable');
        }
        try {
            $applied = $this->apply();
            $localeMigration = $this->pdo->prepare('SELECT status FROM portal_migrations WHERE version=?');
            $localeMigration->execute(['0045_enable_all_locales.php']);
            if ($localeMigration->fetchColumn() === 'applied') {
                (new LocaleSettings($this->pdo, $this->resourceRoot))->synchronizeSnapshotAfterMigration(time());
            }
            return $applied;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    private function apply(): array
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS portal_migrations (
            version VARCHAR(128) PRIMARY KEY, checksum CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL, started_at BIGINT NOT NULL, applied_at BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $files = glob($this->directory . '/*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('migration_files_missing');
        }
        sort($files, SORT_STRING);
        $versions = array_map('basename', $files);
        $known = $this->pdo->query('SELECT version FROM portal_migrations')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($known, $versions) !== []) {
            throw new RuntimeException('database_version_newer_than_code');
        }
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException('migration_unreadable');
            }
            $query = $this->pdo->prepare('SELECT checksum, status FROM portal_migrations WHERE version = ?');
            $query->execute([$version]);
            $existing = $query->fetch();
            if ($existing !== false) {
                if (!hash_equals($existing['checksum'], $checksum)) {
                    throw new RuntimeException('migration_checksum_mismatch');
                }
                if ($existing['status'] !== 'applied') {
                    throw new RuntimeException('migration_incomplete_requires_review');
                }
                continue;
            }
            $statements = require $file;
            if (!is_array($statements) || $statements === []) {
                throw new RuntimeException('migration_statements_required');
            }
            $start = $this->pdo->prepare("INSERT INTO portal_migrations VALUES (?, ?, 'started', ?, NULL)");
            $start->execute([$version, $checksum, time()]);
            try {
                foreach ($statements as $sql) {
                    if (!is_string($sql) || $sql === '') {
                        throw new RuntimeException('invalid_migration_statement');
                    }
                    $this->pdo->exec($sql);
                }
                $done = $this->pdo->prepare("UPDATE portal_migrations SET status = 'applied', applied_at = ? WHERE version = ?");
                $done->execute([time(), $version]);
            } catch (Throwable $error) {
                throw new RuntimeException('migration_failed_requires_review', 0, $error);
            }
            $applied[] = $version;
        }
        $policyTranslations = new PolicyTranslations($this->pdo);
        if ($policyTranslations->isAvailable()) {
            $policyTranslations->seedLegacyCurrentVersions();
        }
        return $applied;
    }
}
