<?php
declare(strict_types=1);
return [
    "CREATE TABLE portal_wechat_checkouts (
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        code_cipher TEXT NOT NULL, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL,
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        CHECK (expires_at>created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
