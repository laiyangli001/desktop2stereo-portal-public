<?php
declare(strict_types=1);

namespace D2S\services;

final class TaskCatalog
{
    public const TASKS = [
        'refresh-release'=>['script'=>'bin/refresh-release.php','interval'=>900,'optional'=>false],
        'deliver'=>['script'=>'bin/deliver.php','interval'=>60,'optional'=>false],
        'notify-transactions'=>['script'=>'bin/notify-transactions.php','interval'=>60,'optional'=>false],
        'alipay-reconcile'=>['script'=>'bin/alipay-reconcile.php','interval'=>300,'optional'=>false],
        'alipay-refunds'=>['script'=>'bin/alipay-refunds.php','interval'=>600,'optional'=>false],
        'wechat-reconcile'=>['script'=>'bin/wechat-reconcile.php','interval'=>300,'optional'=>false],
        'wechat-refunds'=>['script'=>'bin/wechat-refunds.php','interval'=>600,'optional'=>false],
        'international-payments'=>['script'=>'bin/international-payments.php','interval'=>120,'optional'=>true],
        'settle-referrals'=>['script'=>'bin/settle-referrals.php','interval'=>300,'optional'=>true],
        'update-geoip'=>['script'=>'bin/update-geoip.php','interval'=>2592000,'optional'=>true],
        'translate-content'=>['script'=>'bin/translate-content.php','interval'=>60,'optional'=>true],
        'purge-edgeone-cache'=>['script'=>'bin/purge-edgeone-cache.php','interval'=>60,'optional'=>true],
        'prune-support'=>['script'=>'bin/prune-support.php','interval'=>86400,'optional'=>false],
        'prune-login-history'=>['script'=>'bin/prune-login-history.php','interval'=>86400,'optional'=>false],
        'backup'=>['script'=>'bin/backup.php','interval'=>86400,'optional'=>true],
        'verify-backup'=>['script'=>'bin/verify-backup.php','interval'=>86400,'optional'=>true],
    ];

    public static function scripts(): array
    {
        return array_map(static fn(array $task): string => $task['script'],self::TASKS);
    }
}
