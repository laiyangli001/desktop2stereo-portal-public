<?php
declare(strict_types=1);
$tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_transaction_notifications (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        source_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        user_id BIGINT UNSIGNED NOT NULL,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        event_type VARCHAR(24) NOT NULL, amount BIGINT NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        license_effect VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL,
        INDEX idx_notification_user (user_id,created_at,id),
        FOREIGN KEY (user_id) REFERENCES portal_users(id),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        CHECK (event_type IN ('ORDER_PAID','PAYMENT_REVIEW','REFUND_CONFIRMED')),
        CHECK (license_effect IN ('activated','revoked','unchanged')),
        CHECK (currency IN ('CNY','USD')), CHECK (amount>0 AND amount<=1000000000000)
    )".$tail,
    "CREATE TABLE portal_notification_reads (
        notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        read_at BIGINT NOT NULL,
        FOREIGN KEY (notification_id) REFERENCES portal_transaction_notifications(id)
    )".$tail,
    "CREATE TABLE portal_notification_outbox (
        notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        status VARCHAR(16) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
        total_attempts BIGINT UNSIGNED NOT NULL DEFAULT 0,
        available_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        lease_until BIGINT NOT NULL DEFAULT 0,
        last_error VARCHAR(64) NULL, channel VARCHAR(8) NULL, updated_at BIGINT NOT NULL,
        INDEX idx_notification_due (status,available_at),
        FOREIGN KEY (notification_id) REFERENCES portal_transaction_notifications(id),
        CHECK (status IN ('pending','processing','retry','sent','blocked','failed')),
        CHECK (channel IS NULL OR channel IN ('email','phone'))
    )".$tail,
    "CREATE TABLE portal_notification_retries (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        reason VARCHAR(256) NOT NULL, operator_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_notification_retry (notification_id,idempotency_key),
        FOREIGN KEY (notification_id) REFERENCES portal_transaction_notifications(id),
        FOREIGN KEY (operator_id) REFERENCES portal_admins(id)
    )".$tail,
];
