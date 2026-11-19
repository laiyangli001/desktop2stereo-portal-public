<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_licenses (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL UNIQUE,
        product VARCHAR(32) NOT NULL DEFAULT 'desktop2stereo',
        kind VARCHAR(8) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active',
        trial_started_at BIGINT NOT NULL, expires_at BIGINT NULL,
        session_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        CHECK (kind IN ('trial','formal')),
        CHECK (kind <> 'trial' OR expires_at IS NOT NULL),
        FOREIGN KEY (user_id) REFERENCES portal_users(id)
    )" . $tail,
    "CREATE TABLE portal_devices (
        user_id BIGINT UNSIGNED NOT NULL,
        device_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        public_key VARCHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        first_seen_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL,
        PRIMARY KEY (user_id,device_hash),
        FOREIGN KEY (user_id) REFERENCES portal_users(id)
    )" . $tail,
    "CREATE TABLE portal_client_sessions (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        device_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        user_version BIGINT UNSIGNED NOT NULL, session_version BIGINT UNSIGNED NOT NULL,
        created_at BIGINT NOT NULL, verified_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        FOREIGN KEY (user_id,device_hash) REFERENCES portal_devices(user_id,device_hash)
    )" . $tail,
    "CREATE TABLE portal_license_credentials (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        license_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        device_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        issued_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        INDEX idx_credential_time (issued_at),
        FOREIGN KEY (license_id) REFERENCES portal_licenses(id)
    )" . $tail,
];
