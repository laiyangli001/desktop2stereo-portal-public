<?php
declare(strict_types=1);

return [
    "CREATE TABLE portal_product_download_hosts (
        hostname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        enabled TINYINT NOT NULL DEFAULT 1,
        verified_at BIGINT NOT NULL,
        verified_by BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        CHECK(enabled IN (0,1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
