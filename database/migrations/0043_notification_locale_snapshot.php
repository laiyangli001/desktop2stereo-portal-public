<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_orders
        ADD COLUMN locale_snapshot VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'zh-Hans'",
    "ALTER TABLE portal_transaction_notifications
        ADD COLUMN locale_snapshot VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'zh-Hans',
        ADD COLUMN template_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        ADD CONSTRAINT ck_notification_template_version CHECK (template_version > 0)",
];
