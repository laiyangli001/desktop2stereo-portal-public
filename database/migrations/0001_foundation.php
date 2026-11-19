<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
        display_name VARCHAR(80) NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        region VARCHAR(8) NOT NULL,
        session_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
    )" . $tail,
    "CREATE TABLE portal_contacts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(8) NOT NULL, value VARCHAR(254) NOT NULL,
        verified_at BIGINT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_contact (kind, value),
        UNIQUE KEY uq_user_contact (user_id, kind),
        FOREIGN KEY (user_id) REFERENCES portal_users(id)
    )" . $tail,
    "CREATE TABLE portal_admins (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
        email VARCHAR(254) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        session_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL
    )" . $tail,
    "CREATE TABLE portal_admin_permissions (
        admin_id BIGINT UNSIGNED NOT NULL,
        permission VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (admin_id, permission),
        FOREIGN KEY (admin_id) REFERENCES portal_admins(id)
    )" . $tail,
    "CREATE TABLE portal_settings (
        name VARCHAR(128) PRIMARY KEY, value_json LONGTEXT NOT NULL,
        updated_at BIGINT NOT NULL
    )" . $tail,
    "CREATE TABLE portal_admin_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        operator_id BIGINT UNSIGNED NULL, operator_email VARCHAR(254) NOT NULL,
        operation_type VARCHAR(64) NOT NULL, operation_time BIGINT NOT NULL,
        target_resource VARCHAR(255) NULL, source_ip VARCHAR(45) NULL,
        result VARCHAR(16) NOT NULL, details_json LONGTEXT NOT NULL,
        INDEX idx_audit_time (operation_time)
    )" . $tail,
    "CREATE TABLE portal_user_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL, email VARCHAR(254) NULL,
        action_type VARCHAR(32) NOT NULL, event_time BIGINT NOT NULL,
        source_ip VARCHAR(45) NULL, source_port SMALLINT UNSIGNED NULL,
        destination_ip VARCHAR(45) NULL, destination_port SMALLINT UNSIGNED NULL,
        user_agent VARCHAR(1024) NULL,
        device_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        request_path VARCHAR(2048) NOT NULL, result_status VARCHAR(16) NOT NULL,
        record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        hash_version SMALLINT UNSIGNED NOT NULL,
        INDEX idx_event_time (event_time), INDEX idx_event_user (user_id, event_time)
    )" . $tail,
    "CREATE TABLE portal_registration_records (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL, email VARCHAR(254) NULL,
        contact_kind VARCHAR(8) NOT NULL, contact_value VARCHAR(254) NOT NULL,
        register_ip VARCHAR(45) NULL, register_time BIGINT NOT NULL,
        register_user_agent VARCHAR(1024) NULL,
        contact_verified TINYINT NOT NULL, contact_verified_at BIGINT NULL,
        INDEX idx_registration_time (register_time)
    )" . $tail,
    "CREATE TABLE portal_retention_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        operator_id BIGINT UNSIGNED NULL, started_at BIGINT NOT NULL,
        range_start BIGINT NULL, range_end BIGINT NOT NULL,
        counts_json LONGTEXT NOT NULL
    )" . $tail,
];
