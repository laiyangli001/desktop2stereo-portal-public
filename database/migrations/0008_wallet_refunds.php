<?php
declare(strict_types=1);
return [
    "CREATE TABLE portal_wallet_refunds (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, reason VARCHAR(256) NOT NULL,
        operator_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_wallet_refund_key (order_id,idempotency_key),
        INDEX idx_wallet_refund_order (order_id,created_at),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        FOREIGN KEY (user_id,currency) REFERENCES portal_wallets(user_id,currency),
        FOREIGN KEY (operator_id) REFERENCES portal_admins(id),
        CHECK (amount>0 AND amount<=1000000000000),
        CHECK (currency IN ('CNY','USD'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
