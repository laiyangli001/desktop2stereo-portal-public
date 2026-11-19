<?php
declare(strict_types=1);
return [
    "CREATE TABLE portal_merchant_order_numbers (
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        payment_method VARCHAR(24) NOT NULL,
        merchant_order_no CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        created_at BIGINT NOT NULL,
        FOREIGN KEY (order_id,merchant_order_no) REFERENCES portal_order_numbers(order_id,order_no),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        CHECK (payment_method IN ('alipay_pc','wechat_native'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
