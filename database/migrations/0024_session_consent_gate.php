<?php
declare(strict_types=1);

return [
    'ALTER TABLE portal_web_sessions ADD COLUMN consent_required TINYINT NOT NULL DEFAULT 0 AFTER session_version',
];
