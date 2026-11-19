<?php
declare(strict_types=1);
$tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_wechat_profiles (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        app_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        mchid VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        config_cipher LONGTEXT NOT NULL, created_at BIGINT NOT NULL
    )".$tail,
    "CREATE TABLE portal_wechat_events (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        event_type VARCHAR(48) NOT NULL, event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_ip VARCHAR(45) NULL, result VARCHAR(16) NOT NULL, received_at BIGINT NOT NULL,
        FOREIGN KEY (order_id) REFERENCES portal_orders(id)
    )".$tail,
    "CREATE TABLE portal_wechat_receipts (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        app_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        mchid VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        transaction_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider_state VARCHAR(16) NOT NULL,
        payer_total BIGINT NULL, payer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, received_at BIGINT NOT NULL,
        UNIQUE KEY uq_wechat_transaction (mchid,transaction_id), INDEX idx_wechat_receipt_order (order_id),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        CHECK (amount>0 AND amount<=10000000000), CHECK (currency='CNY'), CHECK (provider_state IN ('SUCCESS','REFUND')),
        CHECK (payer_total IS NULL OR (payer_total>=0 AND payer_total<=amount))
    )".$tail,
    "CREATE TABLE portal_wechat_fulfillments (
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        FOREIGN KEY (payment_id) REFERENCES portal_wechat_receipts(id),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id)
    )".$tail,
];
