<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_order_number_clock (
        id TINYINT UNSIGNED PRIMARY KEY,
        last_second BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        CHECK (id=1),
        CHECK (last_second>=0 AND updated_at>=0)
    )".$tail,
];
