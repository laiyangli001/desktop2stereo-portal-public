<?php
declare(strict_types=1);

return [
    // Keep a user-leading index for the existing account foreign key.
    "ALTER TABLE portal_licenses ADD UNIQUE KEY uq_license_user_product(user_id,product), DROP INDEX user_id",
    "ALTER TABLE portal_client_sessions
        ADD product VARCHAR(32) NOT NULL DEFAULT 'desktop2stereo',
        ADD public_key VARCHAR(43) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD INDEX idx_client_session_user(user_id),
        DROP PRIMARY KEY, ADD PRIMARY KEY(user_id,product)",
    "UPDATE portal_client_sessions s JOIN portal_devices d ON d.user_id=s.user_id AND d.device_hash=s.device_hash
        SET s.public_key=d.public_key",
    "ALTER TABLE portal_client_sessions MODIFY public_key VARCHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL",
];
