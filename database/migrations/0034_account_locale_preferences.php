<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_users
        ADD COLUMN preferred_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD CONSTRAINT fk_portal_users_preferred_locale
            FOREIGN KEY(preferred_locale) REFERENCES portal_locales(code) ON DELETE SET NULL",
    "ALTER TABLE portal_admins
        ADD COLUMN preferred_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD CONSTRAINT fk_portal_admins_preferred_locale
            FOREIGN KEY(preferred_locale) REFERENCES portal_locales(code) ON DELETE SET NULL",
];
