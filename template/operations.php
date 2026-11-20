<?php
declare(strict_types=1);
$operationTitles=[
    'users'=>'operations.pages.users','orders'=>'operations.pages.orders',
    'ledger'=>'operations.pages.ledger','articles'=>'operations.pages.articles',
    'product'=>'operations.pages.product','staff'=>'operations.pages.staff',
    'tasks'=>'operations.pages.tasks',
    'maintenance'=>'operations.pages.maintenance',
    'upgrade'=>'operations.pages.upgrade',
    'locales'=>'operations.pages.locales',
    'oauth'=>'operations.pages.oauth',
    'payment-interfaces'=>'operations.pages.payment_interfaces',
];
$uiNamespaces=['operations','locales','permissions','content_admin','commerce','licensing'];
if ($operationSection==='articles') $uiNamespaces[]='editor';
if ($operationSection==='tasks') $uiNamespaces[]='translation_glossary';
if ($operationSection==='maintenance') $uiNamespaces[]='maintenance';
if ($operationSection==='upgrade') $uiNamespaces[]='updates';
if ($operationSection==='product') $uiNamespaces[]='product_releases';
$legacyDomTranslation=false;
$heading=d2s_tr($operationTitles[$operationSection]);
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape($heading) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <?php if($operationSection==='articles'): ?>
    <link rel="stylesheet" href="/assets/vendor/quill-2.0.3/quill.snow.css">
    <script src="/assets/vendor/quill-2.0.3/quill.js" defer></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/article-editor.js')) ?>" defer></script>
    <?php endif ?>
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/operations.css')) ?>">
    <?php if($operationSection!=='oauth'): ?><script src="<?= d2s_escape(d2s_asset('/assets/js/operations.js')) ?>" defer></script><?php endif ?>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main" id="operations" translate="no" data-section="<?= d2s_escape($operationSection) ?>"
      data-permissions="<?= d2s_escape(json_encode($permissions,JSON_THROW_ON_ERROR)) ?>">
    <header class="admin-page-heading">
        <div><p class="portal-eyebrow">Desktop2Stereo</p><h1><?= d2s_escape($heading) ?></h1></div>
        <div class="admin-page-actions">
            <?php if($operationSection==='articles'): ?><button id="article-category-manage" type="button" hidden><?= d2s_escape(d2s_tr('editor.category.manage')) ?></button><?php endif ?>
            <?php if($operationSection==='articles'): ?><a class="site-secondary" href="<?= d2s_escape(d2s_url('/help')) ?>" target="_blank" rel="noopener"><?= d2s_escape(d2s_tr('operations.page_help.view_help_center')) ?></a><?php endif ?>

            <button id="operation-create" type="button" hidden><?= d2s_escape(d2s_tr('operations.page_help.create')) ?></button>
            <?php if($operationSection!=='oauth'): ?><button id="operation-refresh" type="button"><?= d2s_escape(d2s_tr('operations.page_help.refresh')) ?></button><?php endif ?>
        </div>
    </header>
    <p id="operation-message" role="status" aria-live="polite"></p>
    <form id="operation-filters" class="operation-filters" hidden></form>
    <?php if($operationSection==='users'): ?>
    <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.currencies_reflect_paid_orders_ip_geolocation_describes_the_network_location')) ?>
        <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>
    </p>
    <?php elseif($operationSection==='payment-interfaces'): ?>
    <section class="admin-panel">
        <h2><?= d2s_escape(d2s_tr('commerce.settings.recharge')) ?></h2>
        <p class="portal-help"><?= d2s_escape(d2s_tr('commerce.settings.recharge_help')) ?></p>
        <form id="recharge-settings-form" class="operation-form">
            <label class="operation-checkbox">
                <input type="checkbox" name="enabled"<?= $walletRechargeConfiguration['enabled'] ? ' checked' : '' ?>>
                <?= d2s_escape(d2s_tr('commerce.settings.recharge_label')) ?>
            </label>
            <footer><button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('commerce.settings.recharge_save')) ?></button></footer>
        </form>
        <p id="recharge-settings-message" role="status" aria-live="polite"></p>
    </section>
    <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.configure_paypal_stripe_wechat_pay_and_alipay_here_select_a')) ?></p>
    <?php elseif($operationSection==='upgrade'): ?>
    <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.inspect_the_website_code_baseline_release_manifest_and_database_migrations')) ?></p>
    <?php elseif($operationSection==='maintenance'): ?>
    <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.review_the_runtime_storage_and_backup_verification_tools_task_schedules')) ?></p>
    <?php elseif($operationSection==='tasks'): ?>
    <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.pause_tasks_or_change_intervals_a_server_scheduler_must_keep')) ?></p>
    <?php elseif($operationSection==='ledger'): ?>
    <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.wallet_funds_and_referral_commissions_have_separate_ledgers_amounts_retain')) ?></p>
    <?php endif ?>
    <div id="operation-results" class="admin-panel" aria-live="polite">
        <?php if($operationSection==='oauth'): ?>
        <span class="operation-badge muted"><?= d2s_escape(d2s_tr('operations.page_help.not_enabled')) ?></span>
        <h2><?= d2s_escape(d2s_tr('operations.page_help.third_party_sign_in_configuration')) ?></h2>
        <p><?= d2s_escape(d2s_tr('operations.page_help.oauth_sign_in_is_deferred_this_administration_entry_is_reserved')) ?></p>
        <p class="portal-help"><?= d2s_escape(d2s_tr('operations.page_help.the_website_continues_using_its_existing_account_sign_in_this')) ?></p>
        <?php endif ?>
    </div>
    <nav id="operation-pagination" class="operation-pagination" hidden aria-label="<?= d2s_escape(d2s_tr('operations.page_help.pagination')) ?>">
        <button id="operation-previous" type="button"><?= d2s_escape(d2s_tr('operations.page_help.previous')) ?></button>
        <span id="operation-page"></span>
        <button id="operation-next" type="button"><?= d2s_escape(d2s_tr('operations.page_help.next')) ?></button>
    </nav>
</main>
<dialog translate="no" id="operation-dialog" class="operation-dialog">
    <header><h2 id="operation-dialog-title"></h2><button id="operation-close" type="button" aria-label="<?= d2s_escape(d2s_tr('operations.page_help.close')) ?>">×</button></header>
    <p id="operation-dialog-message" role="status" aria-live="polite"></p>
    <div id="operation-detail"></div>
</dialog>
</body>
</html>
