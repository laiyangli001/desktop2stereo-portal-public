<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_policy_consents
        ADD COLUMN locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER policy_version,
        ADD INDEX idx_policy_consent_locale(locale_code),
        ADD CONSTRAINT fk_policy_consent_locale FOREIGN KEY(locale_code) REFERENCES portal_locales(code)",
];
