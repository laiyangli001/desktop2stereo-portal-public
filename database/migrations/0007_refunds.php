<?php
declare(strict_types=1);
$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_payment_fulfillments (
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        FOREIGN KEY (payment_id) REFERENCES portal_payments(id),
        FOREIGN KEY (order_id) REFERENCES portal_order_fulfillments(id)
    )".$tail,
    // Historical single-receipt fulfillment is unambiguous. Never guess among multiple receipts.
    "INSERT INTO portal_payment_fulfillments (payment_id,order_id)
        SELECT MIN(p.id),f.id FROM portal_order_fulfillments f
        JOIN portal_payments p ON p.order_id=f.id GROUP BY f.id HAVING COUNT(*)=1",
    "CREATE TABLE portal_refund_requests (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, reason VARCHAR(256) NOT NULL,
        operator_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_refund_key (payment_id,idempotency_key),
        INDEX idx_refund_order (order_id,created_at),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        FOREIGN KEY (payment_id) REFERENCES portal_payments(id),
        FOREIGN KEY (operator_id) REFERENCES portal_admins(id),
        CHECK (amount>0 AND amount<=10000000000)
    )".$tail,
    "CREATE TABLE portal_refund_states (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        status VARCHAR(16) NOT NULL, attempts BIGINT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at BIGINT NOT NULL, last_sent_at BIGINT NULL,
        lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, lease_until BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL,
        INDEX idx_refund_due (status,next_attempt_at),
        FOREIGN KEY (id) REFERENCES portal_refund_requests(id),
        CHECK (status IN ('pending','unknown','succeeded'))
    )".$tail,
    "CREATE TABLE portal_refund_receipts (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, proof_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        confirmed_at BIGINT NOT NULL,
        FOREIGN KEY (id) REFERENCES portal_refund_requests(id),
        FOREIGN KEY (payment_id) REFERENCES portal_payments(id),
        CHECK (amount>0 AND amount<=10000000000)
    )".$tail,
];
