<?php
declare(strict_types=1);
return [
    "ALTER TABLE portal_external_refunds ADD sent_at BIGINT NULL, ADD last_error VARCHAR(80) NULL",
    "CREATE TABLE portal_external_refund_receipts (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        configuration_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider_refund_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, currency CHAR(3) NOT NULL, received_at BIGINT NOT NULL,
        FOREIGN KEY(payment_id) REFERENCES portal_external_payments(id),
        FOREIGN KEY(configuration_id) REFERENCES portal_payment_configurations(id),
        UNIQUE KEY uq_external_refund(configuration_id,provider_refund_id), CHECK(amount>0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
