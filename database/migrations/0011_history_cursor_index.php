<?php
declare(strict_types=1);
return [
    // The order index already includes its primary ID after user_id/created_at in InnoDB.
    "CREATE INDEX idx_wallet_user_cursor ON portal_wallet_entries (user_id,id)",
];
