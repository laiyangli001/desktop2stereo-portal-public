<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_announcements
        ADD placement VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'header' AFTER source_revision,
        ADD starts_at BIGINT NULL AFTER needs_locale_review,
        ADD ends_at BIGINT NULL AFTER starts_at,
        ADD CONSTRAINT chk_announcement_placement CHECK (placement IN ('header','home','both')),
        ADD CONSTRAINT chk_announcement_schedule CHECK (
            (starts_at IS NULL OR starts_at > 0)
            AND (ends_at IS NULL OR ends_at > 0)
            AND (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
        ),
        ADD INDEX ix_announcement_active_placement (enabled,deleted_at,placement,starts_at,ends_at)",
];
