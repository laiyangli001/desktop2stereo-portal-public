<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_products DROP CHECK portal_products_chk_1,
        MODIFY title_zh VARCHAR(160) NOT NULL,
        ADD purchases_enabled TINYINT NOT NULL DEFAULT 0,
        ADD image_path VARCHAR(200) NOT NULL DEFAULT '',
        ADD sort_order INT NOT NULL DEFAULT 0,
        ADD deleted_at BIGINT NULL,
        ADD lock_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        ADD CONSTRAINT chk_product_purchase_switch CHECK (purchases_enabled IN (0,1))",
    "UPDATE portal_products SET purchases_enabled=1 WHERE code='desktop2stereo'",
    "CREATE TABLE portal_product_prices (
        product_code VARCHAR(32) NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        enabled TINYINT NOT NULL DEFAULT 1,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(product_code,currency),
        FOREIGN KEY(product_code) REFERENCES portal_products(code),
        CHECK(currency IN ('CNY','USD')),
        CHECK(amount>0 AND amount<=1000000000000),
        CHECK(enabled IN (0,1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT INTO portal_product_prices(product_code,currency,amount,revision,enabled,updated_at)
        SELECT 'desktop2stereo',currency,amount,revision,enabled,updated_at FROM portal_catalog_prices",
];
