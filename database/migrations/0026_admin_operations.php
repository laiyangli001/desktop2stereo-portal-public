<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "ALTER TABLE portal_users
        ADD last_login_at BIGINT NULL, ADD last_login_ip VARCHAR(45) NULL,
        ADD login_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ADD password_changed_at BIGINT NULL, ADD remark VARCHAR(1000) NOT NULL DEFAULT ''",
    "ALTER TABLE portal_admins
        ADD is_system TINYINT NOT NULL DEFAULT 0, ADD updated_at BIGINT NULL,
        ADD last_login_at BIGINT NULL, ADD last_login_ip VARCHAR(45) NULL,
        ADD login_count BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD password_changed_at BIGINT NULL,
        ADD remark VARCHAR(1000) NOT NULL DEFAULT ''",
    "UPDATE portal_admins SET is_system=1 WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM portal_admins) initial_admin)",
    "CREATE INDEX idx_registration_user ON portal_registration_records(user_id,id)",
    "CREATE INDEX idx_order_user_paid ON portal_orders(user_id,paid_at,id)",
    "UPDATE portal_users u SET
        last_login_at=(SELECT MAX(e.event_time) FROM portal_user_events e WHERE e.user_id=u.id AND e.action_type='LOGIN' AND e.result_status='SUCCESS'),
        last_login_ip=(SELECT e.source_ip FROM portal_user_events e WHERE e.user_id=u.id AND e.action_type='LOGIN' AND e.result_status='SUCCESS' ORDER BY e.event_time DESC,e.id DESC LIMIT 1),
        login_count=(SELECT COUNT(*) FROM portal_user_events e WHERE e.user_id=u.id AND e.action_type='LOGIN' AND e.result_status='SUCCESS'),
        password_changed_at=GREATEST(u.created_at,COALESCE((SELECT MAX(e.event_time) FROM portal_user_events e WHERE e.user_id=u.id AND e.action_type='PASSWORD_RESET' AND e.result_status='SUCCESS'),0))",
    "UPDATE portal_admins a SET updated_at=created_at,password_changed_at=created_at,
        last_login_at=(SELECT MAX(e.operation_time) FROM portal_admin_audit e WHERE e.operator_id=a.id AND e.operation_type='LOGIN_ADMIN' AND e.result='SUCCESS'),
        last_login_ip=(SELECT e.source_ip FROM portal_admin_audit e WHERE e.operator_id=a.id AND e.operation_type='LOGIN_ADMIN' AND e.result='SUCCESS' ORDER BY e.operation_time DESC,e.id DESC LIMIT 1),
        login_count=(SELECT COUNT(*) FROM portal_admin_audit e WHERE e.operator_id=a.id AND e.operation_type='LOGIN_ADMIN' AND e.result='SUCCESS')",
    "CREATE TABLE portal_login_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, realm VARCHAR(8) NOT NULL,
        user_id BIGINT UNSIGNED NULL, admin_id BIGINT UNSIGNED NULL,
        outcome VARCHAR(16) NOT NULL, source_ip VARCHAR(45) NULL,
        user_agent VARCHAR(512) NOT NULL DEFAULT '', created_at BIGINT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES portal_users(id),
        FOREIGN KEY(admin_id) REFERENCES portal_admins(id),
        INDEX idx_login_user(user_id,created_at), INDEX idx_login_admin(admin_id,created_at),
        INDEX idx_login_time(created_at),
        CHECK(realm IN ('user','admin')), CHECK(outcome IN ('SUCCESS','FAILURE','BLOCKED'))
    )".$tail,
    "CREATE TABLE portal_products (
        code VARCHAR(32) PRIMARY KEY, title_zh VARCHAR(120) NOT NULL, title_en VARCHAR(160) NOT NULL,
        description_zh TEXT NOT NULL, description_en TEXT NOT NULL,
        enabled TINYINT NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL,
        CHECK(code='desktop2stereo'), CHECK(enabled IN (0,1))
    )".$tail,
    "INSERT INTO portal_products VALUES ('desktop2stereo','Desktop2Stereo 正式授权','Desktop2Stereo license',
        'Desktop2Stereo 软件正式授权，支持一个在线会话。','Desktop2Stereo software license with one online session.',1,UNIX_TIMESTAMP())",
    "CREATE TABLE portal_articles (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        category VARCHAR(80) NOT NULL DEFAULT '使用帮助', title_zh VARCHAR(200) NOT NULL,
        title_en VARCHAR(240) NOT NULL DEFAULT '', summary_zh VARCHAR(500) NOT NULL DEFAULT '',
        summary_en VARCHAR(500) NOT NULL DEFAULT '', body_zh MEDIUMTEXT NOT NULL, body_en MEDIUMTEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'draft', sort_order INT NOT NULL DEFAULT 0,
        seo_title VARCHAR(200) NOT NULL DEFAULT '', seo_description VARCHAR(300) NOT NULL DEFAULT '',
        author_id BIGINT UNSIGNED NOT NULL, published_at BIGINT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY(author_id) REFERENCES portal_admins(id),
        CHECK(status IN ('draft','published','archived')), INDEX idx_article_status(status,sort_order,published_at)
    )".$tail,
    "CREATE TABLE portal_payment_configurations (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        provider VARCHAR(16) NOT NULL, label VARCHAR(100) NOT NULL,
        enabled TINYINT NOT NULL DEFAULT 0, priority INT NOT NULL DEFAULT 100,
        currencies_json VARCHAR(100) NOT NULL, config_cipher LONGTEXT NOT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        CHECK(provider IN ('alipay_pc','wechat_native','paypal','stripe')), CHECK(enabled IN(0,1)),
        INDEX idx_payment_config(provider,enabled,priority)
    )".$tail,
    "ALTER TABLE portal_orders ADD payment_configuration_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        ADD CONSTRAINT fk_order_payment_configuration FOREIGN KEY(payment_configuration_id) REFERENCES portal_payment_configurations(id)",
    "CREATE TABLE portal_external_checkouts (
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        configuration_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider VARCHAR(16) NOT NULL, config_cipher LONGTEXT NOT NULL,
        provider_order_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
        provider_payment_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
        checkout_url TEXT NULL, status VARCHAR(24) NOT NULL DEFAULT 'created',
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY(order_id) REFERENCES portal_orders(id),
        FOREIGN KEY(configuration_id) REFERENCES portal_payment_configurations(id),
        UNIQUE KEY uq_external_order(configuration_id,provider_order_id)
    )".$tail,
    "CREATE TABLE portal_external_payments (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        configuration_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider VARCHAR(16) NOT NULL, provider_payment_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        currency CHAR(3) NOT NULL, amount BIGINT NOT NULL, refunded_amount BIGINT NOT NULL DEFAULT 0,
        received_at BIGINT NOT NULL, FOREIGN KEY(order_id) REFERENCES portal_orders(id),
        FOREIGN KEY(configuration_id) REFERENCES portal_payment_configurations(id),
        UNIQUE KEY uq_external_payment(configuration_id,provider_payment_id),
        CHECK(amount>0), CHECK(refunded_amount>=0 AND refunded_amount<=amount)
    )".$tail,
    "CREATE TABLE portal_external_events (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        configuration_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        provider VARCHAR(16) NOT NULL, event_type VARCHAR(100) NOT NULL,
        result VARCHAR(24) NOT NULL, event_hash CHAR(64) NOT NULL,
        received_at BIGINT NOT NULL, INDEX idx_external_event_order(order_id,received_at),
        FOREIGN KEY(configuration_id) REFERENCES portal_payment_configurations(id),
        FOREIGN KEY(order_id) REFERENCES portal_orders(id)
    )".$tail,
    "CREATE TABLE portal_external_refunds (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        payment_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount BIGINT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending',
        provider_refund_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
        reason VARCHAR(500) NOT NULL, requested_by BIGINT UNSIGNED NOT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY(payment_id) REFERENCES portal_external_payments(id),
        FOREIGN KEY(requested_by) REFERENCES portal_admins(id),
        CHECK(amount>0), CHECK(status IN ('pending','submitted','succeeded','failed','review'))
    )".$tail,
    "CREATE TABLE portal_referral_codes (
        user_id BIGINT UNSIGNED PRIMARY KEY, code CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        created_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES portal_users(id)
    )".$tail,
    "CREATE TABLE portal_referrals (
        invitee_id BIGINT UNSIGNED PRIMARY KEY, inviter_id BIGINT UNSIGNED NOT NULL,
        created_at BIGINT NOT NULL, FOREIGN KEY(invitee_id) REFERENCES portal_users(id),
        FOREIGN KEY(inviter_id) REFERENCES portal_users(id),
        CHECK(invitee_id<>inviter_id), INDEX idx_referral_inviter(inviter_id,created_at)
    )".$tail,
    "CREATE TABLE portal_referral_commissions (
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        inviter_id BIGINT UNSIGNED NOT NULL, invitee_id BIGINT UNSIGNED NOT NULL,
        currency CHAR(3) NOT NULL, basis_amount BIGINT NOT NULL, rate_bps INT NOT NULL,
        amount BIGINT NOT NULL, reversed_amount BIGINT NOT NULL DEFAULT 0,
        available_at BIGINT NOT NULL, released_at BIGINT NULL,
        created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY(order_id) REFERENCES portal_orders(id), FOREIGN KEY(inviter_id) REFERENCES portal_users(id),
        FOREIGN KEY(invitee_id) REFERENCES portal_users(id),
        CHECK(amount>=0 AND reversed_amount>=0 AND reversed_amount<=amount),
        INDEX idx_commission_user(inviter_id,created_at), INDEX idx_commission_release(released_at,available_at)
    )".$tail,
    "CREATE TABLE portal_commission_wallets (
        user_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
        available BIGINT NOT NULL DEFAULT 0, reserved BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL,
        PRIMARY KEY(user_id,currency), FOREIGN KEY(user_id) REFERENCES portal_users(id),
        CHECK(currency IN ('CNY','USD')), CHECK(reserved>=0)
    )".$tail,
    "CREATE TABLE portal_commission_withdrawals (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, amount BIGINT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending', destination_cipher LONGTEXT NOT NULL,
        decision_reason VARCHAR(500) NOT NULL DEFAULT '', payout_reference VARCHAR(160) NULL UNIQUE,
        reviewed_by BIGINT UNSIGNED NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES portal_users(id), FOREIGN KEY(reviewed_by) REFERENCES portal_admins(id),
        CHECK(amount>0), CHECK(status IN ('pending','approved','paid','rejected')),
        INDEX idx_withdrawal_status(status,created_at)
    )".$tail,
    "CREATE TABLE portal_commission_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
        amount BIGINT NOT NULL, reserved_delta BIGINT NOT NULL, balance_after BIGINT NOT NULL, reserved_after BIGINT NOT NULL,
        event_type VARCHAR(32) NOT NULL, reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        withdrawal_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        created_at BIGINT NOT NULL, FOREIGN KEY(user_id,currency) REFERENCES portal_commission_wallets(user_id,currency),
        FOREIGN KEY(order_id) REFERENCES portal_orders(id), FOREIGN KEY(withdrawal_id) REFERENCES portal_commission_withdrawals(id),
        INDEX idx_commission_ledger(user_id,created_at)
    )".$tail,
    "CREATE TABLE portal_oauth_identities (
        provider VARCHAR(16) NOT NULL, subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL,
        PRIMARY KEY(provider,subject), UNIQUE KEY uq_user_oauth(user_id,provider),
        FOREIGN KEY(user_id) REFERENCES portal_users(id)
    )".$tail,
    "INSERT IGNORE INTO portal_admin_permissions SELECT admin_id,'users.manage' FROM portal_admin_permissions WHERE permission='admins.manage'",
    "INSERT IGNORE INTO portal_admin_permissions SELECT admin_id,'wallets.adjust' FROM portal_admin_permissions WHERE permission='refunds.manage'",
    "INSERT IGNORE INTO portal_admin_permissions SELECT admin_id,'referrals.manage' FROM portal_admin_permissions WHERE permission='refunds.manage'",
    "INSERT IGNORE INTO portal_admin_permissions SELECT admin_id,'articles.manage' FROM portal_admin_permissions WHERE permission='announcements.manage'",
    "INSERT IGNORE INTO portal_admin_permissions SELECT admin_id,'operations.read' FROM portal_admin_permissions WHERE permission='orders.read'",
    "INSERT IGNORE INTO portal_admin_permissions SELECT admin_id,'orders.export' FROM portal_admin_permissions WHERE permission='orders.read'",
];
