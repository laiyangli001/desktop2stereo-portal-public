<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    // Buying before first client use must not invent a trial start date.
    "ALTER TABLE portal_licenses MODIFY trial_started_at BIGINT NULL,
        ADD CONSTRAINT chk_trial_start CHECK (kind <> 'trial' OR trial_started_at IS NOT NULL)",
    "CREATE TABLE portal_catalog_prices (
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        amount BIGINT NOT NULL, revision BIGINT UNSIGNED NOT NULL,
        enabled TINYINT NOT NULL, updated_at BIGINT NOT NULL,
        CHECK (currency IN ('CNY','USD')), CHECK (amount > 0 AND amount <= 1000000000000),
        CHECK (enabled IN (0,1))
    )" . $tail,
    "CREATE TABLE portal_quotes (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL, product VARCHAR(32) NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, price_revision BIGINT UNSIGNED NOT NULL,
        created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES portal_users(id),
        CHECK (amount > 0 AND amount <= 1000000000000)
    )" . $tail,
    "CREATE TABLE portal_orders (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        quote_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        product VARCHAR(32) NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, payment_method VARCHAR(16) NOT NULL,
        email VARCHAR(254) NULL, source_ip VARCHAR(45) NULL,
        payment_account_identifier CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        pending_user_id BIGINT UNSIGNED NULL UNIQUE,
        paid_at BIGINT NULL, refunded_amount BIGINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        UNIQUE KEY uq_order_idempotency (user_id,idempotency_key),
        INDEX idx_order_user (user_id,created_at),
        FOREIGN KEY (user_id) REFERENCES portal_users(id),
        FOREIGN KEY (quote_id) REFERENCES portal_quotes(id),
        CHECK (amount > 0 AND amount <= 1000000000000),
        CHECK (currency IN ('CNY','USD')),
        CHECK (refunded_amount >= 0 AND refunded_amount <= amount),
        CHECK ((status='pending' AND pending_user_id=user_id AND pending_user_id IS NOT NULL)
            OR (status<>'pending' AND pending_user_id IS NULL)),
        CHECK (status IN ('pending','paid','cancelled','refund_pending','partially_refunded','refunded','payment_review'))
    )" . $tail,
    "CREATE TABLE portal_wallets (
        user_id BIGINT UNSIGNED NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        balance BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL,
        PRIMARY KEY (user_id,currency), FOREIGN KEY (user_id) REFERENCES portal_users(id),
        CHECK (currency IN ('CNY','USD')), CHECK (balance >= 0 AND balance <= 1000000000000)
    )" . $tail,
    "CREATE TABLE portal_wallet_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, balance_after BIGINT NOT NULL,
        reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        event_type VARCHAR(32) NOT NULL, created_at BIGINT NOT NULL,
        FOREIGN KEY (user_id,currency) REFERENCES portal_wallets(user_id,currency),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        INDEX idx_wallet_history (user_id,created_at),
        CHECK (amount <> 0), CHECK (balance_after >= 0 AND balance_after <= 1000000000000)
    )" . $tail,
    "CREATE TABLE portal_order_fulfillments (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        license_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        fulfilled_at BIGINT NOT NULL,
        FOREIGN KEY (id) REFERENCES portal_orders(id),
        FOREIGN KEY (license_id) REFERENCES portal_licenses(id)
    )" . $tail,
];
