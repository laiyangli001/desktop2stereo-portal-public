<?php
declare(strict_types=1);
$uiNamespaces = ['alipay'];
$legacyDomTranslation = false;
$fields = [
    'enabled'=>'alipay.field.enabled','environment'=>'alipay.field.environment','app_id'=>'alipay.field.app_id',
    'seller_id'=>'alipay.field.seller_id','private_key'=>'alipay.field.private_key',
    'alipay_public_key'=>'alipay.field.alipay_public_key','notify_url'=>'alipay.field.notify_url',
    'app_gateway_url'=>'alipay.field.app_gateway_url','return_url'=>'alipay.field.return_url',
    'qr_pay_mode'=>'alipay.field.qr_pay_mode','qrcode_width'=>'alipay.field.qrcode_width',
];
$choices = [
    'enabled'=>['false'=>'alipay.choice.enabled.off','true'=>'alipay.choice.enabled.on'],
    'environment'=>['production'=>'alipay.choice.environment.production','sandbox'=>'alipay.choice.environment.sandbox'],
    'qr_pay_mode'=>['0'=>'alipay.choice.qr_mode_0','1'=>'alipay.choice.qr_mode_1',
        '3'=>'alipay.choice.qr_mode_3','4'=>'alipay.choice.qr_mode_4'],
];
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('alipay.page.title') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/alipay-settings.js')) ?>" type="module"></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main portal-auth portal-settings">
<section class="portal-card">
    <h1><?= d2s_tr('alipay.page.title') ?></h1>
    <p><?= d2s_tr('alipay.page.intro') ?></p>
    <p><?= d2s_tr('alipay.page.enable_warning') ?> <a href="/admin/commerce"><?= d2s_tr('alipay.link.commerce') ?></a></p>
    <form id="alipay-settings-form">
    <div class="portal-settings-grid">
        <?php foreach ($fields as $name=>$label): ?>
        <div>
            <label for="alipay-<?= d2s_escape($name) ?>"><?= d2s_tr($label) ?></label>
            <?php if (isset($choices[$name])): ?>
            <select id="alipay-<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>">
                <?php foreach ($choices[$name] as $value=>$text): ?>
                <option value="<?= d2s_escape((string)$value) ?>"<?= $configuration['values'][$name] === (string)$value ? ' selected':'' ?>><?= d2s_tr($text) ?></option>
                <?php endforeach ?>
            </select>
            <?php elseif (in_array($name,D2S\services\AlipaySettings::SECRET_FIELDS,true)): ?>
            <input id="alipay-<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" type="password" autocomplete="new-password" maxlength="16384" placeholder="<?= d2s_tr($configuration['secrets_configured'][$name] ? 'alipay.secret.configured' : 'alipay.secret.missing') ?>">
            <label class="portal-clear"><input type="checkbox" name="clear__<?= d2s_escape($name) ?>"><?= d2s_tr('alipay.secret.clear') ?>: <?= d2s_tr($fields[$name]) ?></label>
            <?php else: ?>
            <input id="alipay-<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" value="<?= d2s_escape($configuration['values'][$name]) ?>" maxlength="256"<?= $name === 'qrcode_width' ? ' type="number" min="75" max="600" step="1"':'' ?>>
            <?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <p class="portal-help"><?= d2s_tr('alipay.help.secrets') ?></p>
    <p class="portal-help"><?= d2s_tr('alipay.help.urls') ?></p>
    <p id="alipay-message" role="status" aria-live="polite"></p>
    <button class="portal-primary" type="submit"><?= d2s_tr('alipay.action.save') ?></button>
    </form>
</section>
</main>
</body>
</html>
