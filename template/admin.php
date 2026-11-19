<?php
declare(strict_types=1);
$uiNamespaces = [...($uiNamespaces ?? []), 'accounts', 'operations', 'locales', 'licensing'];
$legacyDomTranslation=false;
$adminQuickLinks = [];
if (in_array('settings.manage', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/content/site', d2s_tr('operations.dashboard.site_information'), d2s_tr('operations.dashboard.maintain_site_identity_downloads_and_filing_disclosures')];
}
if (in_array('settings.manage', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/settings/platform', d2s_tr('navigation.admin.platform_settings'), d2s_tr('operations.dashboard.platform_settings_summary')];
    $adminQuickLinks[] = ['/admin/plugins', d2s_tr('navigation.admin.plugins_and_modules'), d2s_tr('operations.dashboard.plugins_summary')];
}
if (in_array('products.manage', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/products', d2s_tr('navigation.admin.products'), d2s_tr('operations.page_help.prices_and_purchasing')];
}
if (in_array('orders.read', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/orders', d2s_tr('operations.dashboard.orders_and_reconciliation'), d2s_tr('operations.dashboard.review_orders_payments_and_pending_closures')];
}
if (in_array('tickets.read', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/tickets', d2s_tr('operations.dashboard.support_tickets'), d2s_tr('operations.dashboard.handle_user_complaints_order_issues_and_software_bugs')];
}
if (in_array('audit.read', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/audit', d2s_tr('operations.dashboard.security_logs'), d2s_tr('operations.dashboard.search_logs_verify_hashes_and_review_export_requests')];
}
if (in_array('payment.configure', $permissions, true)) {
    $adminQuickLinks[] = ['/admin/settings/payments', d2s_tr('operations.dashboard.payment_interfaces'), d2s_tr('operations.dashboard.configure_paypal_stripe_wechat_pay_and_alipay')];
}
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape(d2s_tr('operations.dashboard.management_console')) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/operations.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/operations.js')) ?>" defer></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/accounts.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main portal-auth">
    <header class="admin-page-heading">
        <div>
            <p class="portal-eyebrow"><?= d2s_escape(d2s_tr('operations.dashboard.operations_overview')) ?></p>
            <h1><?= d2s_escape(d2s_tr('operations.dashboard.management_console')) ?></h1>
            <p><?= d2s_escape(d2s_tr('operations.dashboard.use_the_left_navigation_to_open_each_function_on_its')) ?></p>
        </div>
        <div class="admin-page-actions"><a class="site-secondary" href="<?= d2s_escape(d2s_url('/')) ?>" target="_blank" rel="noopener"><?= d2s_escape(d2s_tr('operations.dashboard.view_website')) ?></a></div>
    </header>
    <section id="operations-dashboard" class="admin-panel" aria-live="polite"><p><?= d2s_escape(d2s_tr('operations.dashboard.loading_operations_and_tasks')) ?></p></section>
    <section class="admin-dashboard-grid" aria-label="<?= d2s_escape(d2s_tr('operations.dashboard.administrator_overview')) ?>">
        <article class="admin-summary-card accent"><span><?= d2s_escape(d2s_tr('operations.dashboard.current_administrator')) ?></span><strong><?= d2s_escape($owner['username']) ?></strong><small><?= d2s_escape(d2s_tr('operations.dashboard.separate_admin_session')) ?></small></article>
        <article class="admin-summary-card"><span><?= d2s_escape(d2s_tr('operations.dashboard.granted_permissions')) ?></span><strong><?= count($permissions) ?></strong><small><?= d2s_escape(d2s_tr('operations.dashboard.every_action_is_rechecked_by_the_server')) ?></small></article>
        <article class="admin-summary-card"><span><?= d2s_escape(d2s_tr('operations.dashboard.available_shortcuts')) ?></span><strong><?= count($adminQuickLinks) ?></strong><small><?= d2s_escape(d2s_tr('operations.dashboard.filtered_automatically_by_role')) ?></small></article>
        <article class="admin-summary-card"><span><?= d2s_escape(d2s_tr('operations.dashboard.site_status')) ?></span><strong><?= d2s_escape(d2s_tr('operations.dashboard.status_ok')) ?></strong><small><a href="/api/status" target="_blank" rel="noopener"><?= d2s_escape(d2s_tr('operations.dashboard.view_runtime_status')) ?></a></small></article>
    </section>
    <section class="admin-panel" aria-labelledby="admin-quick-title">
        <div class="admin-page-heading">
            <div><p class="portal-eyebrow"><?= d2s_escape(d2s_tr('operations.dashboard.shortcuts')) ?></p><h2 id="admin-quick-title"><?= d2s_escape(d2s_tr('operations.dashboard.start_with_a_module')) ?></h2></div>
        </div>
        <?php if ($adminQuickLinks === []): ?>
            <p><?= d2s_escape(d2s_tr('operations.dashboard.this_account_has_no_available_business_modules_ask_a_system')) ?></p>
        <?php else: ?>
            <div class="admin-quick-grid">
                <?php foreach ($adminQuickLinks as $link): ?>
                    <a class="admin-quick-card" href="<?= d2s_escape(d2s_url($link[0])) ?>"><b><?= d2s_escape($link[1]) ?></b><span><?= d2s_escape($link[2]) ?></span><em><?= d2s_escape(d2s_tr('operations.dashboard.open_module')) ?> →</em></a>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </section>
    <section class="admin-panel">
        <div class="admin-page-heading">
            <div><p class="portal-eyebrow"><?= d2s_escape(d2s_tr('operations.dashboard.security_notice')) ?></p><h2><?= d2s_escape(d2s_tr('operations.dashboard.work_within_your_assigned_permissions')) ?></h2></div>
        </div>
        <p><?= d2s_escape(d2s_tr('operations.dashboard.sensitive_actions_include_account_restrictions_refund_review_secret_configuration_audit')) ?></p>
    </section>
</main>
</body>
</html>
