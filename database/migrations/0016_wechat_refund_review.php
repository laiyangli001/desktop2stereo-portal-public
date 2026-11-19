<?php
declare(strict_types=1);
$tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_wechat_refund_review_actions (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        refund_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        action VARCHAR(24) NOT NULL, reason VARCHAR(256) NOT NULL,
        idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        result_mode VARCHAR(16) NOT NULL, operator_id BIGINT UNSIGNED NOT NULL,
        evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        details_json LONGTEXT NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE KEY uq_wechat_refund_review_key (refund_id,idempotency_key),
        INDEX idx_wechat_refund_review (refund_id,created_at),
        FOREIGN KEY (refund_id) REFERENCES portal_wechat_refund_requests(id),
        FOREIGN KEY (operator_id) REFERENCES portal_admins(id),
        CHECK (action IN ('cancel_unsent','pause_retry','resume_retry')),
        CHECK (result_mode IN ('cancelled','query_only','automatic'))
    )".$tail,
    "CREATE TABLE portal_wechat_refund_cancellations (
        refund_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        action_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        created_at BIGINT NOT NULL,
        FOREIGN KEY (refund_id) REFERENCES portal_wechat_refund_requests(id),
        FOREIGN KEY (action_id) REFERENCES portal_wechat_refund_review_actions(id)
    )".$tail,
];
