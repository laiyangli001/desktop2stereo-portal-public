<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_products ADD COLUMN draft_json JSON NULL",
    "ALTER TABLE portal_announcements ADD COLUMN draft_json JSON NULL",
];
