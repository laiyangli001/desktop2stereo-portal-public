<?php
declare(strict_types=1);
$uiNamespaces=['wechat'];
$legacyDomTranslation=false;
$fields=[
    'app_id'=>'wechat.field.app_id','mchid'=>'wechat.field.mchid','merchant_serial'=>'wechat.field.merchant_serial',
    'private_key'=>'wechat.field.private_key','api_v3_key'=>'wechat.field.api_v3_key',
    'verification_id'=>'wechat.field.verification_id','verification_key'=>'wechat.field.verification_key',
    'previous_verification_id'=>'wechat.field.previous_verification_id','previous_verification_key'=>'wechat.field.previous_verification_key',
    'notify_url'=>'wechat.field.notify_url','refund_notify_url'=>'wechat.field.refund_notify_url',
];
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= d2s_escape(d2s_tr('wechat.page.title')) ?> · Desktop2Stereo</title>
<link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
<link rel="stylesheet" href="/assets/css/wechat-settings.css">
<script src="<?= d2s_escape(d2s_asset('/assets/js/wechat-settings.js')) ?>" type="module"></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main portal-auth portal-settings"><section class="portal-card">
<h1><?= d2s_escape(d2s_tr('wechat.page.title')) ?></h1>
<p><?= d2s_escape(d2s_tr('wechat.page.intro')) ?></p>
<p><?= d2s_escape(d2s_tr('wechat.page.gate')) ?></p>
<p><?= d2s_escape(d2s_tr('wechat.page.purchase_settings')) ?><a href="/admin/commerce"><?= d2s_escape(d2s_tr('wechat.page.purchase_link')) ?></a><?= d2s_escape(d2s_tr('wechat.page.alipay_settings')) ?><a href="/admin/settings/alipay"><?= d2s_escape(d2s_tr('wechat.page.alipay_link')) ?></a>.</p>
<form id="wechat-settings-form">
<label for="wechat-enabled"><?= d2s_escape(d2s_tr('wechat.field.enabled')) ?></label>
<select id="wechat-enabled" disabled aria-describedby="wechat-gate"><option value="false"><?= d2s_escape(d2s_tr('wechat.choice.disabled')) ?></option></select>
<p id="wechat-gate"><?= d2s_escape(d2s_tr('wechat.status.server_gate')) ?></p>
<p id="wechat-readiness" role="status"><?= d2s_escape(d2s_tr($configuration['configuration_ready']?'wechat.status.ready':'wechat.status.incomplete')) ?></p>
<div class="portal-settings-grid">
<?php foreach($fields as$name=>$label): ?>
<div>
<label for="wechat-<?= d2s_escape($name) ?>"><?= d2s_escape(d2s_tr($label)) ?></label>
<?php if(in_array($name,D2S\services\WechatSettings::SECRET_FIELDS,true)): ?>
    <?php if($name==='api_v3_key'): ?>
    <input id="wechat-<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" type="password" autocomplete="new-password" maxlength="32" aria-describedby="wechat-secrets-help" placeholder="<?= d2s_escape(d2s_tr($configuration['secrets_configured'][$name]?'wechat.secret.configured':'wechat.secret.missing')) ?>">
    <?php else: ?>
    <textarea id="wechat-<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" rows="4" maxlength="16384" autocomplete="off" spellcheck="false" aria-describedby="wechat-secrets-help" placeholder="<?= d2s_escape(d2s_tr($configuration['secrets_configured'][$name]?'wechat.secret.configured':'wechat.secret.missing')) ?>"></textarea>
    <?php endif ?>
    <label class="portal-clear"><input type="checkbox" name="clear__<?= d2s_escape($name) ?>"><?= d2s_escape(d2s_tr('wechat.secret.clear')) ?>: <?= d2s_escape(d2s_tr($label)) ?></label>
<?php else: ?>
<input id="wechat-<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" value="<?= d2s_escape($configuration['values'][$name]) ?>" maxlength="256" autocomplete="off">
<?php endif ?>
</div>
<?php endforeach ?>
</div>
<p id="wechat-secrets-help"><?= d2s_escape(d2s_tr('wechat.help.secrets')) ?></p>
<p><?= d2s_escape(d2s_tr('wechat.help.verification')) ?></p>
<p><?= d2s_escape(d2s_tr('wechat.help.callbacks')) ?></p>
<p id="wechat-message" role="status" aria-live="polite"></p>
<button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('wechat.action.save')) ?></button>
</form>
</section></main>
</body></html>
