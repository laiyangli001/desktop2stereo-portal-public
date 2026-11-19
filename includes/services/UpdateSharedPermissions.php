<?php
declare(strict_types=1);

namespace D2S\services;

final class UpdateSharedPermissions
{
    public static function directoryMode(): int
    {
        return getenv('D2S_UPDATE_SHARED_ACCESS') === '1' ? 02750 : 0700;
    }

    public static function fileMode(): int
    {
        return getenv('D2S_UPDATE_SHARED_ACCESS') === '1' ? 0640 : 0600;
    }
}
