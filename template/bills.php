<?php
declare(strict_types=1);
$zone = new DateTimeZone('Asia/Shanghai');
$today = new DateTimeImmutable('today',$zone);
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$uiNamespaces = ['bills'];
$legacyDomTranslation = false;
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('bills.page.title') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/bills.js')) ?>" type="module"></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main portal-auth">
    <nav aria-label="<?= d2s_tr('bills.nav.label') ?>"><a href="/admin"><?= d2s_tr('bills.nav.console') ?></a></nav>
    <section class="portal-card">
        <h1><?= d2s_tr('bills.page.title') ?></h1>
        <p><?= d2s_tr('bills.page.description') ?></p>
        <form id="bill-form">
            <label for="bill-channel"><?= d2s_tr('bills.form.channel') ?></label>
            <select id="bill-channel"><option value="alipay"><?= d2s_tr('bills.channel.alipay') ?></option><option value="wechat"><?= d2s_tr('bills.channel.wechat') ?></option></select>
            <label for="bill-period"><?= d2s_tr('bills.form.period') ?></label>
            <select id="bill-period"><option value="day"><?= d2s_tr('bills.period.day') ?></option><option value="month"><?= d2s_tr('bills.period.month') ?></option></select>
            <label for="bill-date"><?= d2s_tr('bills.form.date') ?></label>
            <input id="bill-date" type="date" value="<?= $yesterday ?>" required
                min="<?= $today->modify('-6 years')->format('Y-m-d') ?>" max="<?= $yesterday ?>"
                data-day-min="<?= $today->modify('-6 years')->format('Y-m-d') ?>" data-day-max="<?= $yesterday ?>"
                data-month-min="<?= $today->modify('-6 years')->format('Y-m') ?>"
                data-month-max="<?= $today->modify('first day of last month')->format('Y-m') ?>">
            <label for="bill-order"><?= d2s_tr('bills.form.order') ?></label>
            <input id="bill-order" type="text" pattern="[a-f0-9]{32}" maxlength="32" autocomplete="off">
            <p class="portal-help"><?= d2s_tr('bills.form.order_help') ?></p>
            <button type="submit"><?= d2s_tr('bills.action.download') ?></button>
        </form>
        <p id="bill-message" role="status" aria-live="polite"></p>
    </section>
</main>
</body>
</html>
