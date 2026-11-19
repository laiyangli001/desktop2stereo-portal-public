<?php
declare(strict_types=1);
$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_payment_attempts (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        provider VARCHAR(16) NOT NULL, environment VARCHAR(16) NOT NULL,
        app_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        seller_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        config_cipher LONGTEXT NOT NULL, created_at BIGINT NOT NULL,
        FOREIGN KEY (id) REFERENCES portal_orders(id)
    )".$tail,
    "CREATE TABLE portal_payments (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        environment VARCHAR(16) NOT NULL, app_id VARCHAR(32) NOT NULL, seller_id VARCHAR(32) NOT NULL,
        trade_no VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        received_at BIGINT NOT NULL,
        UNIQUE KEY uq_platform_trade (environment,trade_no), INDEX idx_payment_order (order_id),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        CHECK (amount>0 AND amount<=10000000000), CHECK (currency='CNY')
    )".$tail,
    "CREATE TABLE portal_payment_events (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        event_type VARCHAR(64) NOT NULL, event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_ip VARCHAR(45) NULL, result VARCHAR(16) NOT NULL, received_at BIGINT NOT NULL,
        FOREIGN KEY (order_id) REFERENCES portal_orders(id)
    )".$tail,
];
