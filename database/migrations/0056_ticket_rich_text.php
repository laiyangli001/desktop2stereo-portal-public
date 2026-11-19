<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_support_messages
        ADD body_format VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PLAIN' AFTER body,
        ADD CONSTRAINT chk_support_message_body_format CHECK (body_format IN ('PLAIN','HTML'))",
];
