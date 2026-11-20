<?php
declare(strict_types=1);

return [
    "ALTER TABLE portal_product_releases
        ADD COLUMN tested_at BIGINT NULL,
        ADD COLUMN tested_by BIGINT UNSIGNED NULL,
        ADD KEY ix_product_release_tester(tested_by),
        ADD CONSTRAINT fk_product_release_tested_by FOREIGN KEY(tested_by) REFERENCES portal_admins(id) ON DELETE SET NULL",
    "ALTER TABLE portal_product_download_channels
        ADD COLUMN tested_at BIGINT NULL,
        ADD COLUMN tested_by BIGINT UNSIGNED NULL,
        ADD KEY ix_product_channel_tester(tested_by),
        ADD CONSTRAINT fk_product_channel_tested_by FOREIGN KEY(tested_by) REFERENCES portal_admins(id) ON DELETE SET NULL",
];
