<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_rate_limits (
        bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        attempts INT UNSIGNED NOT NULL, window_started BIGINT NOT NULL,
        last_attempt BIGINT NOT NULL
    )" . $tail,
    "CREATE TABLE portal_verifications (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        kind VARCHAR(8) NOT NULL, target VARCHAR(254) NOT NULL, purpose VARCHAR(32) NOT NULL,
        code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, consumed_at BIGINT NULL,
        INDEX idx_verification_target (kind, target, purpose, created_at),
        FOREIGN KEY (user_id) REFERENCES portal_users(id)
    )" . $tail,
    "CREATE TABLE portal_web_sessions (
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        realm VARCHAR(8) NOT NULL, user_id BIGINT UNSIGNED NULL, admin_id BIGINT UNSIGNED NULL,
        csrf_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        session_version BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        CHECK ((realm = 'user' AND user_id IS NOT NULL AND admin_id IS NULL)
            OR (realm = 'admin' AND admin_id IS NOT NULL AND user_id IS NULL)),
        FOREIGN KEY (user_id) REFERENCES portal_users(id),
        FOREIGN KEY (admin_id) REFERENCES portal_admins(id),
        INDEX idx_session_user (user_id), INDEX idx_session_admin (admin_id)
    )" . $tail,
    "CREATE TABLE portal_delivery_outbox (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        verification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        channel VARCHAR(8) NOT NULL, payload_cipher LONGTEXT NULL,
        status VARCHAR(16) NOT NULL, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        available_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        lease_id CHAR(32) NULL, lease_until BIGINT NULL,
        last_error VARCHAR(64) NULL,
        FOREIGN KEY (verification_id) REFERENCES portal_verifications(id),
        INDEX idx_delivery_claim (status, available_at)
    )" . $tail,
    "CREATE TABLE portal_user_controls (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        login_blocked TINYINT NOT NULL DEFAULT 0,
        purchase_blocked TINYINT NOT NULL DEFAULT 0,
        reason VARCHAR(500) NOT NULL DEFAULT '', updated_at BIGINT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES portal_users(id)
    )" . $tail,
    "ALTER TABLE portal_registration_records
        ADD COLUMN email_verified TINYINT NOT NULL DEFAULT 0,
        ADD COLUMN email_verified_at BIGINT NULL",
    "UPDATE portal_registration_records SET email_verified=contact_verified,
        email_verified_at=contact_verified_at WHERE contact_kind='email' AND email IS NOT NULL",
];
