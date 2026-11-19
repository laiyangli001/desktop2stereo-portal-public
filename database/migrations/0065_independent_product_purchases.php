<?php
declare(strict_types=1);

// Preserve effective purchase availability when removing the former global switch.
$legacyDefault = getenv('D2S_PURCHASES_ENABLED') === 'true' ? 'true' : 'false';

return [
    "UPDATE portal_products p
        LEFT JOIN portal_settings legacy ON legacy.name='commerce.purchases_enabled'
        SET p.purchases_enabled=0, p.lock_version=p.lock_version+1
        WHERE p.purchases_enabled=1
            AND BINARY COALESCE(legacy.value_json,'$legacyDefault') <> 'true'",
    "DELETE FROM portal_admin_permissions WHERE permission='purchases.toggle'",
];
