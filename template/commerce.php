<?php
declare(strict_types=1);
$uiNamespaces=['commerce'];
$legacyDomTranslation=false;
$title = $path === '/wallet' ? d2s_tr('commerce.page.wallet') : d2s_tr('commerce.page.shop');
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape($title) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/shop.css')) ?>">
    <script src="/assets/js/qrcodegen-v1.8.0-es6.js"></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/shop.js')) ?>" type="module"></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main translate="no" data-wechat-module="<?= d2s_escape(d2s_asset('/assets/js/wechat-cashier.js')) ?>" class="portal-main portal-auth portal-settings shop-shell" data-commerce-page="<?= $path === '/wallet' ? 'wallet':'shop' ?>">
    <?php require D2S_ROOT.'/template/partials/user-nav.php'; ?>
    <div class="shop-content user-workspace account-page-stack">
        <h1><?= d2s_escape($title) ?></h1>
        <p id="commerce-message" role="status" aria-live="polite"></p>

        <?php if ($path === '/wallet'): ?>
        <section class="portal-card" id="wallet-summary"><h2><?= d2s_escape(d2s_tr('commerce.wallet.balance')) ?></h2><p id="wallet-balances"><?= d2s_escape(d2s_tr('commerce.loading.default')) ?></p></section>
        <?php endif ?>
        <?php if ($path === '/shop'): ?>
        <section class="portal-card shop-product">
            <span class="portal-eyebrow">DESKTOP2STEREO</span>
            <h2 id="product-title" translate="no" lang="<?= d2s_escape($productPresentation['content_locale'] ?? d2s_locale()) ?>"><?= d2s_escape($productPresentation['title'] ?? d2s_tr('commerce.product.license')) ?></h2>
            <p id="product-description" translate="no" lang="<?= d2s_escape($productPresentation['content_locale'] ?? d2s_locale()) ?>"><?= d2s_escape($productPresentation['description'] ?? '') ?></p>
            <p id="product-price" class="shop-price"><?= d2s_escape(d2s_tr('commerce.loading.prices')) ?></p>
            <label for="currency"><?= d2s_escape(d2s_tr('commerce.purchase.currency')) ?></label><select id="currency" disabled></select>
            <fieldset id="payment-options"><legend><?= d2s_escape(d2s_tr('commerce.purchase.method')) ?></legend></fieldset>
            <ul><li><?= d2s_escape(d2s_tr('commerce.purchase.lifetime')) ?></li><li><?= d2s_escape(d2s_tr('commerce.purchase.sessions')) ?></li><li><?= d2s_escape(d2s_tr('commerce.purchase.offline')) ?></li></ul>
            <p><?= d2s_escape(d2s_tr('commerce.purchase.single')) ?></p>
            <button id="prepare-order" class="portal-primary" type="button" disabled><?= d2s_escape(d2s_tr('commerce.purchase.select')) ?></button>
        </section>
        <?php endif ?>
        <?php if ($path === '/wallet'): ?>
        <section class="portal-card" id="wallet-recharge" hidden>
            <h2><?= d2s_escape(d2s_tr('commerce.recharge.title')) ?></h2>
            <p id="recharge-status"><?= d2s_escape(d2s_tr('commerce.loading.recharge')) ?></p>
            <form id="recharge-form">
                <label for="recharge-amount"><?= d2s_escape(d2s_tr('commerce.recharge.amount')) ?></label>
                <input id="recharge-amount" type="number" min="1" max="10000" step="0.01" value="10.00" required>
                <fieldset id="recharge-options"><legend><?= d2s_escape(d2s_tr('commerce.recharge.method')) ?></legend></fieldset>
                <button id="prepare-recharge" class="portal-primary" type="submit" disabled><?= d2s_escape(d2s_tr('commerce.recharge.confirm')) ?></button>
            </form>
        </section>
        <?php endif ?>
        <?php if (in_array($path, ['/shop','/wallet'], true)): ?>
        <section class="portal-card" id="checkout" hidden>
            <h2 tabindex="-1" id="checkout-title"><?= d2s_escape(d2s_tr('commerce.checkout.purchase')) ?></h2>
            <p id="checkout-summary"></p>
            <p id="payment-description"></p>
            <section id="alipay-cashier" hidden aria-label="<?= d2s_escape(d2s_tr('commerce.cashier.alipay')) ?>">
                <div id="cashier-frame" class="cashier-frame" tabindex="0" aria-label="<?= d2s_escape(d2s_tr('commerce.cashier.alipay_scroll')) ?>"></div>
                <p><?= d2s_escape(d2s_tr('commerce.cashier.alipay_instructions')) ?></p>
                <button id="check-payment" type="button"><?= d2s_escape(d2s_tr('commerce.cashier.check')) ?></button>
            </section>
            <section id="wechat-cashier" hidden aria-label="<?= d2s_escape(d2s_tr('commerce.cashier.wechat')) ?>">
                <h3 tabindex="-1" id="wechat-cashier-title"><?= d2s_escape(d2s_tr('commerce.cashier.wechat_title')) ?></h3>
                <div id="wechat-code" class="wechat-code"></div>
                <p><?= d2s_escape(d2s_tr('commerce.cashier.wechat_instructions')) ?></p>
                <p id="wechat-expiry" role="status"></p>
                <button id="check-wechat-payment" type="button"><?= d2s_escape(d2s_tr('commerce.cashier.wechat_check')) ?></button>
            </section>
            <button id="confirm-order" type="button" hidden><?= d2s_escape(d2s_tr('commerce.checkout.order')) ?></button>
            <button id="pay-order" class="portal-primary" type="button" hidden><?= d2s_escape(d2s_tr('commerce.checkout.pay')) ?></button>
            <button id="cancel-order" type="button" hidden><?= d2s_escape(d2s_tr('commerce.checkout.cancel')) ?></button>
        </section>
        <?php endif ?>
        <?php if ($path === '/wallet'): ?>
        <section class="portal-card"><h2><?= d2s_escape(d2s_tr('commerce.history.title')) ?></h2>
            <p><?= d2s_escape(d2s_tr('commerce.history.description')) ?></p>
            <p id="history-message" role="status" aria-live="polite"></p>
            <ul id="commerce-history" class="shop-history account-records"><li><?= d2s_escape(d2s_tr('commerce.loading.default')) ?></li></ul>
            <nav class="account-record-pager" aria-label="<?= d2s_escape(d2s_tr('commerce.history.navigation')) ?>">
                <button type="button" id="history-previous" disabled><?= d2s_escape(d2s_tr('commerce.history.previous')) ?></button>
                <span id="history-page" role="status" aria-live="polite"></span>
                <button type="button" id="history-next" disabled><?= d2s_escape(d2s_tr('commerce.history.next')) ?></button>
                <button type="button" id="history-refresh"><?= d2s_escape(d2s_tr('commerce.history.refresh')) ?></button>
            </nav>
        </section>
        <?php endif ?>
    </div>
</main>
</body>
</html>
