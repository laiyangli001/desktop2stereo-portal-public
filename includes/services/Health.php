<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;

final class Health
{
    public static function ready(PDO $db, string $directory): bool
    {
        $files = glob($directory . '/*.php') ?: [];
        $rows = $db->query('SELECT version,checksum,status FROM portal_migrations')->fetchAll();
        if ($files === [] || count($files) !== count($rows)) {
            return false;
        }
        $known = [];
        foreach ($rows as $row) {
            $known[$row['version']] = $row;
        }
        foreach ($files as $file) {
            $row = $known[basename($file)] ?? null;
            if ($row === null || $row['status'] !== 'applied' || !hash_equals($row['checksum'], hash_file('sha256', $file))) {
                return false;
            }
        }
        return true;
    }
}
