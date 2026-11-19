<?php
declare(strict_types=1);
return [
    "CREATE TABLE portal_wechat_payment_resolutions (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        action VARCHAR(32) NOT NULL,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        reason VARCHAR(256) NOT NULL, result_status VARCHAR(24) NOT NULL,
        evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        details_json LONGTEXT NOT NULL,
        operator_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_wechat_resolution_key (order_id,idempotency_key),
        INDEX idx_wechat_resolution_order (order_id,created_at),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        FOREIGN KEY (payment_id) REFERENCES portal_wechat_receipts(id),
        FOREIGN KEY (operator_id) REFERENCES portal_admins(id),
        CHECK (action IN ('retry_fulfillment','close_case')),
        CHECK (result_status IN ('paid','partially_refunded','refunded'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
