<?php
declare(strict_types=1);
$uiNamespaces=['referrals'];
$legacyDomTranslation=false;
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= d2s_escape(d2s_tr('referrals.page.title')) ?> · Desktop2Stereo</title>
<link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
<link rel="stylesheet" href="/assets/css/operations.css"><script src="<?= d2s_escape(d2s_asset('/assets/js/referrals.js')) ?>" defer></script>
</head><body>
<?php require D2S_ROOT.($referralAdmin?'/template/partials/admin-header.php':'/template/partials/site-header.php'); ?>
<main translate="no" class="portal-main<?= $referralAdmin?'':' account-main' ?>">
<?php if(!$referralAdmin)require D2S_ROOT.'/template/partials/user-nav.php'; ?>
<div id="referrals" class="user-workspace<?= $referralAdmin?'':' account-page-stack' ?>" data-admin="<?= $referralAdmin?'true':'false' ?>">
<header class="admin-page-heading"><div><p class="portal-eyebrow">Desktop2Stereo</p><h1><?= d2s_escape(d2s_tr('referrals.page.title')) ?></h1></div><button id="referral-refresh" type="button"><?= d2s_escape(d2s_tr('referrals.refresh')) ?></button></header>
<p id="referral-message" role="status" aria-live="polite"></p>
<p class="portal-help"><?= d2s_escape(d2s_tr('referrals.intro')) ?></p>
<div id="referral-summary" class="admin-dashboard-grid"></div>
<?php if($referralAdmin): ?>
<section class="admin-panel"><h2><?= d2s_escape(d2s_tr('referrals.config.title')) ?></h2>
<form id="referral-config" class="operation-form">
<label><?= d2s_escape(d2s_tr('referrals.config.enabled')) ?><input name="enabled" type="checkbox"></label>
<label><?= d2s_escape(d2s_tr('referrals.config.rate')) ?><input name="rate" inputmode="decimal" required></label>
<label><?= d2s_escape(d2s_tr('referrals.config.days')) ?><input name="holding_days" type="number" min="0" max="365" required></label>
<label><?= d2s_escape(d2s_tr('referrals.config.minimum')) ?><input name="minimum" inputmode="decimal" required></label>
<footer><button id="referral-settle" type="button"><?= d2s_escape(d2s_tr('referrals.config.settle')) ?></button><button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('referrals.config.save')) ?></button></footer>
</form></section>
<?php else: ?>
<section class="admin-panel">
<h2><?= d2s_escape(d2s_tr('referrals.invite.title')) ?></h2>
<div class="operation-form referral-invite-controls">
<input id="referral-link" type="url" dir="ltr" readonly aria-label="<?= d2s_escape(d2s_tr('referrals.invite.label')) ?>">
<button id="referral-copy" class="portal-primary" type="button"><?= d2s_escape(d2s_tr('referrals.invite.copy')) ?></button>
</div>
</section>
<section class="admin-panel"><h2><?= d2s_escape(d2s_tr('referrals.withdraw.title')) ?></h2>
<form id="referral-withdraw" class="operation-form">
<label><?= d2s_escape(d2s_tr('referrals.currency')) ?><select name="currency"><option>CNY</option><option>USD</option></select></label>
<label><?= d2s_escape(d2s_tr('referrals.withdraw.full_amount')) ?><input id="referral-amount" readonly></label>
<label><?= d2s_escape(d2s_tr('referrals.withdraw.method')) ?><select name="method"><option value="wechat"><?= d2s_escape(d2s_tr('referrals.withdraw.wechat')) ?></option><option value="alipay"><?= d2s_escape(d2s_tr('referrals.withdraw.alipay')) ?></option><option value="paypal"><?= d2s_escape(d2s_tr('referrals.withdraw.paypal')) ?></option></select></label>
<label><?= d2s_escape(d2s_tr('referrals.withdraw.recipient')) ?><input name="recipient" maxlength="120" required></label>
<label><?= d2s_escape(d2s_tr('referrals.withdraw.account')) ?><input name="account" maxlength="254" required></label>
<footer><button class="portal-primary" type="submit" disabled><?= d2s_escape(d2s_tr('referrals.withdraw.submit')) ?></button></footer>
</form></section>
<?php endif ?>
<section class="admin-panel"><h2><?= d2s_escape(d2s_tr('referrals.withdrawals.title')) ?></h2><div id="referral-withdrawals"<?= $referralAdmin?'':' class="account-records"' ?>></div></section>
</div></main>
<dialog translate="no" id="referral-dialog" class="operation-dialog"><header><h2><?= d2s_escape(d2s_tr('referrals.review.title')) ?></h2><button id="referral-close" type="button" aria-label="<?= d2s_escape(d2s_tr('referrals.review.close')) ?>">×</button></header><div id="referral-detail"></div><p id="referral-dialog-message" role="status"></p></dialog>
</body></html>
