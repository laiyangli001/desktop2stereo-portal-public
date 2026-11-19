<?php
declare(strict_types=1);

namespace D2S\services;

use LogicException;
use PDO;

final class RateLimiter
{
    /** Return false without rolling back prior failed attempts. Caller owns the transaction. */
    public static function take(PDO $db, string $key, int $limit, int $window, int $now, int $spacing = 0): bool
    {
        if (!$db->inTransaction()) {
            throw new LogicException('rate_limit_requires_transaction');
        }
        $bucket = hash('sha256', $key);
        $insert = $db->prepare('INSERT INTO portal_rate_limits (bucket,attempts,window_started,last_attempt)
            VALUES (?,0,?,0) ON DUPLICATE KEY UPDATE bucket = bucket');
        $insert->execute([$bucket, $now]);
        $select = $db->prepare('SELECT * FROM portal_rate_limits WHERE bucket = ? FOR UPDATE');
        $select->execute([$bucket]);
        $row = $select->fetch();
        $count = $now >= (int)$row['window_started'] + $window ? 0 : (int)$row['attempts'];
        $start = $count === 0 ? $now : (int)$row['window_started'];
        if ($count >= $limit || ((int)$row['last_attempt'] > 0 && $now - (int)$row['last_attempt'] < $spacing)) {
            return false;
        }
        $update = $db->prepare('UPDATE portal_rate_limits SET attempts=?,window_started=?,last_attempt=? WHERE bucket=?');
        $update->execute([$count + 1, $start, $now, $bucket]);
        return true;
    }

    public static function clear(PDO $db, string $key): void
    {
        $query = $db->prepare('DELETE FROM portal_rate_limits WHERE bucket=?');
        $query->execute([hash('sha256', $key)]);
    }
}
