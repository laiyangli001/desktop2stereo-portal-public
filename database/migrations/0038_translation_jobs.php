<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE portal_translation_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        resource_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        resource_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        target_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_revision INT NOT NULL,
        source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_snapshot_json MEDIUMTEXT NOT NULL,
        expected_target_lock_version INT NOT NULL,
        provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider_options_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        glossary_revision INT NOT NULL DEFAULT 0,
        dedup_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        character_count INT UNSIGNED NOT NULL,
        available_at BIGINT NOT NULL,
        lease_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        lease_until BIGINT NULL,
        last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        finished_at BIGINT NULL,
        UNIQUE KEY uq_translation_job_dedup(dedup_key),
        KEY ix_translation_job_queue(status,available_at,id),
        KEY ix_translation_job_lease(status,lease_until),
        KEY ix_translation_job_resource(resource_type,resource_id,target_locale,created_at),
        FOREIGN KEY(target_locale) REFERENCES portal_locales(code),
        FOREIGN KEY(source_locale) REFERENCES portal_locales(code),
        FOREIGN KEY(created_by) REFERENCES portal_admins(id),
        CHECK(resource_type IN ('article')),
        CHECK(status IN ('pending','running','retry','succeeded','superseded','failed','cancelled')),
        CHECK(attempts >= 0),
        CHECK(character_count > 0),
        CHECK(expected_target_lock_version >= 0)
    )".$tail,
    "CREATE TABLE portal_translation_usage (
        billing_month CHAR(7) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        submitted_characters BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL,
        CHECK(billing_month REGEXP '^[0-9]{4}-[0-9]{2}$')
    )".$tail,
];
