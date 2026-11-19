<?php
declare(strict_types=1);
$tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_order_number_seconds (
        second_at BIGINT PRIMARY KEY, serial_value INT UNSIGNED NOT NULL DEFAULT 0,
        CHECK (second_at>=0 AND serial_value<=9999)
    )".$tail,
    "CREATE TABLE portal_order_numbers (
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_no CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        allocated_at BIGINT NOT NULL,
        UNIQUE KEY uq_order_number_binding (order_id,order_no),
        CHECK (order_no REGEXP '^[0-9]{16}$'),
        CHECK (RIGHT(order_no,4)<>'0000'),
        CHECK (allocated_at>=0)
    )".$tail,
    "ALTER TABLE portal_orders ADD COLUMN order_no CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD UNIQUE KEY uq_order_no (order_no),
        ADD CONSTRAINT fk_order_number_binding FOREIGN KEY (id,order_no) REFERENCES portal_order_numbers(order_id,order_no)",
    "CREATE TRIGGER portal_order_no_immutable BEFORE UPDATE ON portal_orders FOR EACH ROW
        BEGIN
            IF NOT (NEW.order_no <=> OLD.order_no) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='order_number_immutable';
            END IF;
        END",
];
