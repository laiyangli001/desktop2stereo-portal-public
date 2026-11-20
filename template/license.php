<?php
declare(strict_types=1);
$uiNamespaces=['licensing'];
$legacyDomTranslation=false;
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape(d2s_tr('licensing.page.title')) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/license.css')) ?>">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/licensing.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main translate="no" class="portal-main account-main" id="license-app">
    <?php require D2S_ROOT.'/template/partials/user-nav.php'; ?>
    <div class="user-workspace account-page-stack">
    <div class="account-heading">
        <div><p class="portal-eyebrow"><?= d2s_escape(d2s_tr('licensing.page.eyebrow')) ?></p><h1><?= d2s_escape(d2s_tr('licensing.page.heading')) ?></h1></div>
        <button id="license-refresh" type="button"><?= d2s_escape(d2s_tr('licensing.refresh')) ?></button>
    </div>
    <div class="license-product-picker" id="license-product-picker" hidden>
        <label for="license-product"><?= d2s_escape(d2s_tr('licensing.products.label')) ?></label>
        <select id="license-product"></select>
    </div>
    <p id="license-message" role="status" aria-live="polite"></p>
    <section id="license-overview" class="portal-card" hidden>
        <h2><?= d2s_escape(d2s_tr('licensing.products.title')) ?></h2>
        <div id="license-overview-list" class="license-overview-list account-records"></div>
    </section>
    <section id="license-downloads" class="portal-card" hidden>
        <h2><?= d2s_escape(d2s_tr('licensing.downloads.title')) ?></h2>
        <div id="license-download-list" class="device-list account-records" aria-live="polite"></div>
    </section>
    <section class="license-hero portal-card" aria-labelledby="entitlement-title">
        <div>
            <p class="license-label" id="license-product-title">DESKTOP2STEREO</p>
            <h2 id="entitlement-title"><?= d2s_escape(d2s_tr('licensing.loading.title')) ?></h2>
            <p id="entitlement-detail"></p>
        </div>
        <a id="license-purchase" href="<?= d2s_escape(d2s_url('/shop')) ?>" class="site-secondary"><?= d2s_escape(d2s_tr('licensing.purchase')) ?></a>
    </section>
    <div class="license-grid">
        <section class="portal-card">
            <h2><?= d2s_escape(d2s_tr('licensing.policy.title')) ?></h2>
            <dl class="license-facts">
                <div><dt><?= d2s_escape(d2s_tr('licensing.policy.heartbeat')) ?></dt><dd><?= d2s_escape(d2s_tr('licensing.policy.heartbeat_value')) ?></dd></div>
                <div><dt><?= d2s_escape(d2s_tr('licensing.policy.offline')) ?></dt><dd><?= d2s_escape(d2s_tr('licensing.policy.offline_value')) ?></dd></div>
                <div><dt><?= d2s_escape(d2s_tr('licensing.policy.concurrent')) ?></dt><dd><?= d2s_escape(d2s_tr('licensing.policy.concurrent_value')) ?></dd></div>
                <div><dt><?= d2s_escape(d2s_tr('licensing.policy.trial')) ?></dt><dd><?= d2s_escape(d2s_tr('licensing.policy.trial_value')) ?></dd></div>
            </dl>
            <p class="portal-help"><?= d2s_escape(d2s_tr('licensing.policy.devices')) ?></p>
        </section>
        <section class="portal-card">
            <h2><?= d2s_escape(d2s_tr('licensing.session.title')) ?></h2>
            <div id="current-session"><p><?= d2s_escape(d2s_tr('licensing.loading.default')) ?></p></div>
            <button id="client-logout" type="button" hidden><?= d2s_escape(d2s_tr('licensing.session.logout')) ?></button>
            <p class="portal-help"><?= d2s_escape(d2s_tr('licensing.session.help')) ?></p>
        </section>
    </div>
    <section class="portal-card">
        <div class="account-heading compact"><h2><?= d2s_escape(d2s_tr('licensing.devices.title')) ?></h2><span id="device-count"></span></div>
        <p class="portal-help"><?= d2s_escape(d2s_tr('licensing.devices.help')) ?></p>
        <div class="device-list account-records" id="device-list" aria-live="polite"></div>
    </section>
    </div>
</main>
</body>
</html>
