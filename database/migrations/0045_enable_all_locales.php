<?php
declare(strict_types=1);

return [
    "UPDATE portal_locales
        SET ui_enabled=1,
            public_enabled=1,
            admin_enabled=1,
            revision=revision+1,
            updated_at=UNIX_TIMESTAMP()
        WHERE code IN ('zh-Hant','fr','ru','ja','vi')",
];
