<?php
declare(strict_types=1);

return [
    "UPDATE portal_locales
        SET ui_enabled=0,public_enabled=0,admin_enabled=0,
            revision=revision+1,updated_at=UNIX_TIMESTAMP()
        WHERE code IN ('de','es','pt','ko')",
];
