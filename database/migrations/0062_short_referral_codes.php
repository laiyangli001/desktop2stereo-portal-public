<?php
declare(strict_types=1);

return [
    'ALTER TABLE portal_referral_codes
        ADD COLUMN code_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
        DROP PRIMARY KEY,
        ADD PRIMARY KEY (user_id, code_version),
        ADD CONSTRAINT chk_referral_code_version CHECK (
            (code_version=1 AND CHAR_LENGTH(code)=24) OR (code_version=2 AND CHAR_LENGTH(code)=6)
        )',
];
