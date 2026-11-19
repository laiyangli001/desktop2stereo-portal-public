<?php
declare(strict_types=1);

$adminPermissions = is_array($permissions ?? null) ? $permissions : [];
$adminCurrentPath = isset($path) ? (string)$path : '/admin';
if (str_starts_with($adminCurrentPath, '/admin/content/announcements/')) $adminCurrentPath = '/admin/content/announcements';
$adminUsername = (string)($owner['username'] ?? 'Admin');
$adminPlatform = D2S\services\PlatformSettings::defaults();
if (isset($db) && $db instanceof PDO) {
    try { $adminPlatform = (new D2S\services\PlatformSettings($db))->publicConfiguration(); }
    catch (Throwable) { /* Keep the administration shell available with safe defaults. */ }
}
$adminCan = static function (array $needed) use ($adminPermissions): bool {
    return $needed === [] || array_intersect($needed, $adminPermissions) !== [];
};
$policyTranslationEnabled = false;
if (isset($db) && $db instanceof PDO) {
    try {
        $policyTranslationEnabled = (new D2S\services\PolicyTranslations($db))->isAvailable();
    } catch (Throwable) {
        $policyTranslationEnabled = false;
    }
}
$adminMenu = [
    [
        'id' => 'dashboard', 'label' => d2s_tr('navigation.admin.dashboard'), 'icon' => '⌂', 'url' => '/admin', 'needs' => [],
    ],
    [
        'id' => 'content', 'label' => d2s_tr('navigation.admin.site_content'), 'icon' => '▤', 'children' => [
            ['/admin/content/site', d2s_tr('navigation.admin.site_information'), ['settings.manage']],
            ['/admin/articles', d2s_tr('navigation.admin.help_center_articles'), ['articles.manage']],
            ['/admin/content/announcements', d2s_tr('navigation.admin.announcements'), ['announcements.manage']],
            ...($policyTranslationEnabled ? [['/admin/content/policies', d2s_tr('navigation.admin.policies'), ['settings.manage']]] : []),
        ],
    ],
    [
        'id' => 'accounts', 'label' => d2s_tr('navigation.admin.accounts_and_permissions'), 'icon' => '♙', 'children' => [
            ['/admin/users', d2s_tr('navigation.admin.users'), ['users.read']],
            ['/admin/staff', d2s_tr('navigation.admin.staff'), ['admins.manage']],
            ['/admin/settings/oauth', d2s_tr('navigation.admin.oauth_sign_in'), ['settings.manage']],
        ],
    ],
    [
        'id' => 'commerce', 'label' => d2s_tr('navigation.admin.commerce_and_orders'), 'icon' => '▦', 'children' => [
            ['/admin/products', d2s_tr('navigation.admin.products'), ['products.manage']],
            ['/admin/order-search', d2s_tr('navigation.admin.order_search_and_export'), ['orders.read']],
            ['/admin/finance', d2s_tr('navigation.admin.financial_ledger'), ['wallets.read']],
            ['/admin/referrals', d2s_tr('navigation.admin.referral_commissions'), ['referrals.manage']],

            ['/admin/orders', d2s_tr('navigation.admin.orders_and_reconciliation'), ['orders.read']],
            ['/admin/bills', d2s_tr('navigation.admin.transaction_bills'), ['bills.read']],
        ],
    ],
    [
        'id' => 'delivery', 'label' => d2s_tr('navigation.admin.channels_and_notifications'), 'icon' => '◈', 'children' => [
            ['/admin/settings/payments', d2s_tr('navigation.admin.payment_interfaces'), ['payment.configure']],
            ['/admin/settings/smtp', d2s_tr('navigation.admin.smtp_email'), ['settings.manage']],
            ['/admin/settings/ses', d2s_tr('navigation.admin.tencent_cloud_ses'), ['settings.manage']],
            ['/admin/settings/sms', d2s_tr('navigation.admin.sms_settings'), ['settings.manage']],
            ['/admin/notifications', d2s_tr('navigation.admin.notification_delivery'), ['notifications.manage']],
        ],
    ],
    [
        'id' => 'support', 'label' => d2s_tr('navigation.admin.customer_support'), 'icon' => '✉', 'children' => [
            ['/admin/tickets', d2s_tr('navigation.admin.support_tickets'), ['tickets.read']],
        ],
    ],
    [
        'id' => 'system', 'label' => d2s_tr('navigation.admin.system_management'), 'icon' => '⚙', 'children' => [
            ['/admin/settings/platform', d2s_tr('navigation.admin.platform_settings'), ['settings.manage']],
            ['/admin/plugins', d2s_tr('navigation.admin.plugins_and_modules'), ['settings.manage']],
            ['/admin/upgrade', d2s_tr('navigation.admin.system_upgrade'), ['settings.manage']],
            ['/admin/tasks', d2s_tr('navigation.admin.scheduled_tasks'), ['settings.manage']],
            ['/admin/locales', d2s_tr('navigation.admin.language_settings'), ['locales.manage']],
            ['/admin/maintenance', d2s_tr('navigation.admin.system_maintenance'), ['settings.manage']],
        ],
    ],
    [
        'id' => 'security', 'label' => d2s_tr('navigation.admin.security_and_audit'), 'icon' => '⚿', 'children' => [
            ['/admin/accounts', d2s_tr('navigation.admin.account_restrictions'), ['users.read','accounts.restrict']],
            ['/admin/audit', d2s_tr('navigation.admin.audit_and_exports'), ['audit.read']],
        ],
    ],
];
foreach ($adminMenu as &$adminGroup) {
    if (!isset($adminGroup['children'])) continue;
    $adminGroup['children'] = array_values(array_filter(
        $adminGroup['children'],
        static fn(array $item): bool => $adminCan($item[2]),
    ));
    $adminGroup['active'] = false;
    foreach ($adminGroup['children'] as $adminChild) {
        if ($adminCurrentPath === $adminChild[0]) $adminGroup['active'] = true;
    }
}
unset($adminGroup);
$adminMenu = array_values(array_filter($adminMenu, static function (array $item): bool {
    return isset($item['url']) || ($item['children'] ?? []) !== [];
}));
?>
<?php require_once D2S_ROOT.'/template/partials/locale-runtime.php'; ?>
<link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/admin-controls.css')) ?>">
<header class="portal-header admin-topbar">
    <div class="admin-topbar-brand">
        <button id="admin-sidebar-toggle" class="admin-sidebar-toggle" type="button" aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= d2s_escape(d2s_tr('navigation.admin.open_or_close_admin_navigation')) ?>">☰</button>
        <a href="<?= d2s_escape(d2s_url('/admin')) ?>" class="portal-brand">Desktop2Stereo <span><?= d2s_escape(d2s_tr('common.nav.admin')) ?></span></a>
    </div>
    <div class="admin-topbar-tools">
        <?php $languagePickerAdmin = true; require D2S_ROOT.'/template/partials/language-switch.php'; ?>
        <button
            type="button"
            class="theme-switch"
            data-theme-toggle
            data-label-dark="<?= d2s_escape(d2s_tr('common.nav.theme.switch_dark')) ?>"
            data-label-light="<?= d2s_escape(d2s_tr('common.nav.theme.switch_light')) ?>"
            data-text-dark="<?= d2s_escape(d2s_tr('common.nav.theme.dark')) ?>"
            data-text-light="<?= d2s_escape(d2s_tr('common.nav.theme.light')) ?>"
            data-default-theme="<?= d2s_escape((string)$adminPlatform['default_theme']) ?>"
            data-platform-template="<?= d2s_escape((string)$adminPlatform['template']) ?>"
        >
            <span class="theme-switch-icon" aria-hidden="true"></span>
            <span data-theme-label><?= d2s_escape(d2s_tr('navigation.admin.dark')) ?></span>
        </button>
        <span class="admin-user-chip"><span class="admin-user-avatar"><?= d2s_escape(mb_substr($adminUsername,0,1,'UTF-8')) ?></span><?= d2s_escape($adminUsername) ?></span>
        <button data-redirect="<?= d2s_escape(d2s_url('/admin/login')) ?>" id="admin-logout" class="admin-logout" type="button"><?= d2s_escape(d2s_tr('navigation.admin.sign_out')) ?></button>
    </div>
</header>
<aside id="admin-sidebar" class="admin-sidebar" aria-label="<?= d2s_escape(d2s_tr('navigation.admin.admin_navigation')) ?>">
    <div class="admin-sidebar-scroll">
        <nav>
            <?php foreach ($adminMenu as $adminItem): ?>
                <?php if (isset($adminItem['url'])): ?>
                    <?php $adminSingleActive = in_array($adminCurrentPath, [$adminItem['url'],'/admin/'], true); ?>
                    <a class="admin-nav-single<?= $adminSingleActive ? ' is-active' : '' ?>" href="<?= d2s_escape(d2s_url($adminItem['url'])) ?>"<?= $adminSingleActive ? ' aria-current="page"' : '' ?>>
                        <span class="admin-nav-icon" aria-hidden="true"><?= d2s_escape($adminItem['icon']) ?></span>
                        <span class="admin-nav-label"><?= d2s_escape($adminItem['label']) ?></span>
                    </a>
                <?php else: ?>
                    <?php $adminGroupId = (string)$adminItem['id']; $adminGroupOpen = ($adminItem['active'] ?? false) === true; ?>
                    <section class="admin-nav-group<?= $adminGroupOpen ? ' open is-active' : '' ?>" data-admin-nav-group="<?= d2s_escape($adminGroupId) ?>">
                        <button class="admin-nav-group-toggle" type="button" data-admin-group-toggle aria-expanded="<?= $adminGroupOpen ? 'true' : 'false' ?>">
                            <span class="admin-nav-icon" aria-hidden="true"><?= d2s_escape($adminItem['icon']) ?></span>
                            <span class="admin-nav-label"><?= d2s_escape($adminItem['label']) ?></span>
                            <span class="admin-nav-chevron" aria-hidden="true">›</span>
                        </button>
                        <div class="admin-nav-sub">
                            <?php foreach ($adminItem['children'] as $adminChild): ?>
                                <?php $adminChildActive = $adminCurrentPath === $adminChild[0]; ?>
                                <a href="<?= d2s_escape(d2s_url($adminChild[0])) ?>"<?= $adminChildActive ? ' class="is-active" aria-current="page"' : '' ?>><?= d2s_escape($adminChild[1]) ?></a>
                            <?php endforeach ?>
                        </div>
                    </section>
                <?php endif ?>
            <?php endforeach ?>
        </nav>
    </div>
    <div class="admin-sidebar-foot"><a href="<?= d2s_escape(d2s_url('/')) ?>" target="_blank" rel="noopener"><?= d2s_escape(d2s_tr('navigation.admin.view_website')) ?> ↗</a></div>
</aside>
<div id="admin-sidebar-backdrop" class="admin-sidebar-backdrop"></div>
<script src="/assets/js/theme.js" data-default-theme="<?= d2s_escape((string)$adminPlatform['default_theme']) ?>" data-platform-template="<?= d2s_escape((string)$adminPlatform['template']) ?>"></script>
<script src="<?= d2s_escape(d2s_asset('/assets/js/admin-shell.js')) ?>" defer></script>
