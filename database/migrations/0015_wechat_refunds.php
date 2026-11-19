<?php
declare(strict_types=1);
$tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_wechat_refund_requests (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, reason VARCHAR(80) NOT NULL,
        operator_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_wechat_refund_key (payment_id,idempotency_key),
        INDEX idx_wechat_refund_order (order_id,created_at),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        FOREIGN KEY (payment_id) REFERENCES portal_wechat_receipts(id),
        FOREIGN KEY (operator_id) REFERENCES portal_admins(id),
        CHECK (amount>0 AND amount<=10000000000)
    )".$tail,
    "CREATE TABLE portal_wechat_refund_states (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        status VARCHAR(16) NOT NULL, retry_mode VARCHAR(16) NOT NULL DEFAULT 'automatic',
        attempts BIGINT UNSIGNED NOT NULL DEFAULT 0, next_attempt_at BIGINT NOT NULL,
        last_sent_at BIGINT NULL, lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        lease_until BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL,
        INDEX idx_wechat_refund_due (status,next_attempt_at),
        FOREIGN KEY (id) REFERENCES portal_wechat_refund_requests(id),
        CHECK (status IN ('pending','unknown','processing','abnormal','closed','succeeded')),
        CHECK (retry_mode IN ('automatic','query_only'))
    )".$tail,
    "CREATE TABLE portal_wechat_refund_receipts (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        mchid VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider_refund_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, payer_refund BIGINT NULL,
        proof_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, confirmed_at BIGINT NOT NULL,
        UNIQUE KEY uq_wechat_provider_refund (mchid,provider_refund_id),
        FOREIGN KEY (id) REFERENCES portal_wechat_refund_requests(id),
        FOREIGN KEY (payment_id) REFERENCES portal_wechat_receipts(id),
        CHECK (amount>0 AND amount<=10000000000),
        CHECK (payer_refund IS NULL OR (payer_refund>=0 AND payer_refund<=amount))
    )".$tail,
];
