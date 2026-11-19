<?php
declare(strict_types=1);

return [
    "CREATE TABLE portal_edge_cache_purges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        dedup_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        target_url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        available_at BIGINT UNSIGNED NOT NULL,
        lease_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        lease_until BIGINT UNSIGNED NULL,
        provider_job_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
        last_error_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
        created_at BIGINT UNSIGNED NOT NULL,
        updated_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_edge_cache_purge_dedup (dedup_key),
        KEY ix_edge_cache_purge_due (status,available_at,id),
        KEY ix_edge_cache_purge_lease (status,lease_until,id),
        CONSTRAINT ck_edge_cache_purge_status CHECK (status IN ('pending','running','retry','submitted','failed')),
        CONSTRAINT ck_edge_cache_purge_attempts CHECK (attempts <= 10),
        CONSTRAINT ck_edge_cache_purge_url CHECK (CHAR_LENGTH(target_url) BETWEEN 1 AND 2048)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
