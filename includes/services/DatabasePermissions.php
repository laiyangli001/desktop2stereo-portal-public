<?php
declare(strict_types=1);

namespace D2S\services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Explicit per-table grants; audit tables never inherit business DML privileges. */
final class DatabasePermissions
{
    public const AUDIT_TABLES = [
        'portal_admin_audit', 'portal_user_events',
        'portal_registration_records', 'portal_policy_consents', 'portal_retention_runs', 'portal_license_credentials',
    ];
    // Financial entries and snapshots are append-only and are not generic retention targets.
    public const IMMUTABLE_TABLES = ['portal_quotes','portal_wallet_entries','portal_order_fulfillments',
        'portal_payment_attempts','portal_payments','portal_payment_events','portal_payment_fulfillments',
        'portal_refund_requests','portal_refund_receipts','portal_wallet_refunds','portal_payment_resolutions','portal_refund_review_actions','portal_refund_cancellations',
        'portal_transaction_notifications','portal_notification_reads','portal_notification_retries',
        'portal_wechat_profiles','portal_wechat_events','portal_wechat_receipts','portal_wechat_fulfillments','portal_wechat_checkouts','portal_wechat_refund_requests','portal_wechat_refund_receipts','portal_wechat_refund_review_actions','portal_wechat_refund_cancellations','portal_order_numbers','portal_merchant_order_numbers','portal_wechat_payment_resolutions','portal_wallet_recharge_fulfillments'];
    public const IMMUTABLE_SUPPORT_TABLES = [
        'portal_support_messages','portal_support_attachments','portal_support_ticket_events',
        'portal_audit_export_actions','portal_user_restriction_events',
    ];
    public const CONTROLLED_TABLES=['portal_order_number_seconds','portal_order_number_clock','portal_wallet_recharge_refund_holds',
        'portal_support_tickets','portal_audit_export_requests','portal_account_batch_previews',
        'portal_identity_verifications','portal_user_controls'];
    public const RETENTION_DELETE_TABLES = [
        'portal_support_attachments','portal_support_messages','portal_support_ticket_events','portal_support_tickets',
    ];
    public const BUSINESS_TABLES = [
        'portal_users', 'portal_contacts', 'portal_admins',
        'portal_admin_permissions', 'portal_settings',
        'portal_rate_limits', 'portal_verifications', 'portal_web_sessions',
        'portal_delivery_outbox',
        'portal_licenses', 'portal_devices', 'portal_client_sessions', 'portal_signing_keys',
        'portal_catalog_prices', 'portal_orders', 'portal_wallets', 'portal_refund_states', 'portal_refund_controls', 'portal_notification_outbox', 'portal_wechat_refund_states',
    ];

    public const OPERATIONS_AUDIT_TABLES = ['portal_login_history'];
    public const OPERATIONS_IMMUTABLE_TABLES = ['portal_external_events','portal_external_refund_receipts','portal_referral_codes','portal_referrals','portal_commission_entries',
        'portal_article_translation_revisions','portal_product_translation_revisions','portal_announcement_translation_revisions',
        'portal_policy_translation_revisions','portal_translation_glossary_revisions'];
    public const OPERATIONS_CONTROLLED_TABLES = ['portal_products','portal_product_prices','portal_content_publication_languages','portal_articles','portal_locales','portal_locale_aliases',
        'portal_article_translations','portal_product_translations','portal_payment_configurations',
        'portal_site_translations','portal_article_categories','portal_article_category_translations','portal_translation_jobs','portal_translation_usage',
        'portal_announcements','portal_announcement_translations','portal_policy_documents','portal_policy_translation_heads',
        'portal_translation_glossary_versions','portal_external_checkouts','portal_external_payments','portal_external_refunds','portal_edge_cache_purges',
        'portal_referral_commissions','portal_commission_wallets','portal_commission_withdrawals'];
    public const OPERATIONS_DELETABLE_CONTROLLED_TABLES = ['portal_ui_translation_overrides'];
    public const OPERATIONS_BUSINESS_TABLES = ['portal_oauth_identities','portal_navigation_translations','portal_translation_glossary_terms'];

    public static function grant(PDO $admin, string $database, string $user, string $role): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $database) ||
            !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $user) ||
            !in_array($role, ['runtime', 'retention'], true)) {
            throw new InvalidArgumentException('invalid_permission_target');
        }
        $account = $admin->quote($user) . "@'127.0.0.1'";
        $grants = $admin->query('SHOW GRANTS FOR ' . $account)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($grants as $grant) {
            if (!str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) {
                throw new RuntimeException('provision_requires_new_unprivileged_account');
            }
        }
        foreach (self::AUDIT_TABLES as $table) {
            $permissions = $role === 'runtime' ? 'SELECT, INSERT' : 'SELECT, INSERT, DELETE';
            $admin->exec("GRANT $permissions ON `$database`.`$table` TO $account");
        }
        if ($role === 'runtime') {
            foreach (self::IMMUTABLE_TABLES as $table) {
                $admin->exec("GRANT SELECT, INSERT ON `$database`.`$table` TO $account");
            }
            foreach (self::IMMUTABLE_SUPPORT_TABLES as $table) {
                $admin->exec("GRANT SELECT, INSERT ON `$database`.`$table` TO $account");
            }
            foreach (self::BUSINESS_TABLES as $table) {
                $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.`$table` TO $account");
            }
            foreach(self::CONTROLLED_TABLES as$table)$admin->exec("GRANT SELECT, INSERT, UPDATE ON `$database`.`$table` TO $account");
            $admin->exec("GRANT SELECT ON `$database`.`portal_migrations` TO $account");
        } else {
            foreach (self::RETENTION_DELETE_TABLES as $table) {
                $admin->exec("GRANT SELECT, DELETE ON `$database`.`$table` TO $account");
            }
        }
        self::grantOperations($admin,$database,$user,$role);
    }

    public static function grantOperations(PDO $admin,string $database,string $user,string $role): void
    {
        if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$database)||!preg_match('/^[a-zA-Z0-9_]{1,32}$/D',$user)||
            !in_array($role,['runtime','retention'],true))throw new InvalidArgumentException('invalid_permission_target');
        $account=$admin->quote($user)."@'127.0.0.1'";
        foreach(self::OPERATIONS_AUDIT_TABLES as$table){
            $privileges=$role==='runtime'?'SELECT, INSERT':'SELECT, INSERT, DELETE';
            $admin->exec("GRANT $privileges ON `$database`.`$table` TO $account");
        }
        if($role!=='runtime')return;
        foreach(self::OPERATIONS_IMMUTABLE_TABLES as$table)$admin->exec("GRANT SELECT, INSERT ON `$database`.`$table` TO $account");
        foreach(self::OPERATIONS_CONTROLLED_TABLES as$table)$admin->exec("GRANT SELECT, INSERT, UPDATE ON `$database`.`$table` TO $account");
        foreach(self::OPERATIONS_DELETABLE_CONTROLLED_TABLES as$table)$admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.`$table` TO $account");
        foreach(self::OPERATIONS_BUSINESS_TABLES as$table)$admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.`$table` TO $account");
    }
}
