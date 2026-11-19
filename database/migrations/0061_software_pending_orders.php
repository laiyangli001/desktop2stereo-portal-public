<?php
declare(strict_types=1);

return [
    // A recharge or another software must not occupy this software's pending-order slot.
    "ALTER TABLE portal_orders ADD UNIQUE KEY uq_pending_user_product(pending_user_id,product),
        DROP INDEX pending_user_id",
];
