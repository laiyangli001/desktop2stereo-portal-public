<?php
declare(strict_types=1);

return [
    "CREATE TABLE portal_signing_keys (
        key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        public_jwk TEXT NOT NULL,
        status VARCHAR(16) NOT NULL,
        created_at BIGINT NOT NULL, retired_at BIGINT NULL,
        CHECK (status IN ('active','verify_only','retired'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
