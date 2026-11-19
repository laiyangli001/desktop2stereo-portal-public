<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_policy_consents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        policy_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        policy_version VARCHAR(40) NOT NULL,
        effective_date VARCHAR(40) NOT NULL DEFAULT '',
        accepted_at BIGINT NOT NULL,
        source_ip VARCHAR(45) NULL,
        user_agent VARCHAR(512) NULL,
        record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        UNIQUE KEY uq_policy_consent (user_id,policy_type,policy_version),
        FOREIGN KEY (user_id) REFERENCES portal_users(id),
        INDEX idx_policy_consent_time (accepted_at,id)
    )" . $tail,
];
