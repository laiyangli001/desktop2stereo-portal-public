<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_announcements DROP CHECK chk_announcement_placement,
        ADD CONSTRAINT chk_announcement_display_location CHECK (placement IN ('header','home','both','popup'))",
];
