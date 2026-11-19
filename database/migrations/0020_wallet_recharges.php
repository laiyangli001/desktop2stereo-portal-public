<?php
declare(strict_types=1);
$tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_wallet_recharge_fulfillments (
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        alipay_payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        wechat_payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        amount BIGINT NOT NULL, credited_at BIGINT NOT NULL,
        UNIQUE KEY uq_wallet_recharge_alipay (alipay_payment_id),
        UNIQUE KEY uq_wallet_recharge_wechat (wechat_payment_id),
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        FOREIGN KEY (alipay_payment_id) REFERENCES portal_payments(id),
        FOREIGN KEY (wechat_payment_id) REFERENCES portal_wechat_receipts(id),
        CHECK (amount>0 AND amount<=1000000000000),
        CHECK ((alipay_payment_id IS NULL) <> (wechat_payment_id IS NULL))
    )".$tail,
    "CREATE TABLE portal_wallet_recharge_refund_holds (
        refund_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider VARCHAR(16) NOT NULL, payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, status VARCHAR(16) NOT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY (order_id) REFERENCES portal_orders(id),
        CHECK (provider IN ('alipay_pc','wechat_native')),
        CHECK (amount>0 AND amount<=1000000000000),
        CHECK (status IN ('held','consumed','released'))
    )".$tail,
];
