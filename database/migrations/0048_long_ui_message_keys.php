<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_ui_translation_overrides
        MODIFY message_key VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL",
];
