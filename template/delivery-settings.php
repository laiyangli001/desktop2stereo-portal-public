<?php
declare(strict_types=1);
$uiNamespaces = [...($uiNamespaces ?? []), 'accounts', 'delivery'];
$legacyDomTranslation = false;

use D2S\services\DeliverySettings;
use D2S\services\LocaleRegistry;

$deliveryLocaleRegistry = new LocaleRegistry();

$deliverySection = isset($deliverySection) && in_array($deliverySection, ['smtp','ses','sms'], true)
    ? $deliverySection : 'smtp';
$deliveryIsSmtp = $deliverySection === 'smtp';
$deliveryIsSes = $deliverySection === 'ses';
$deliveryIsSms = $deliverySection === 'sms';
$deliveryIsEmail = !$deliveryIsSms;
$groups = [
    'smtp' => [
        'group.smtp' => [
            'D2S_SMTP_HOST'=>'field.smtp.host','D2S_SMTP_PORT'=>'field.smtp.port',
            'D2S_SMTP_USER'=>'field.smtp.user','D2S_SMTP_PASSWORD'=>'field.smtp.password','D2S_SMTP_FROM'=>'field.smtp.from',
        ],
    ],
    'ses' => [
        'group.ses' => [
            'D2S_SES_SECRET_ID'=>'field.ses.secret_id','D2S_SES_SECRET_KEY'=>'field.ses.secret_key',
            'D2S_SES_REGION'=>'field.ses.region','D2S_SES_FROM'=>'field.ses.from','D2S_SES_REPLY_TO'=>'field.ses.reply_to',
            'D2S_SES_TEMPLATE_REGISTER'=>'field.ses.template_register',
            'D2S_SES_TEMPLATE_PASSWORD_RESET'=>'field.ses.template_password_reset',
            'D2S_SES_TEMPLATE_BIND_CONTACT'=>'field.ses.template_bind_contact',
            'D2S_SES_TEMPLATE_TRANSACTION'=>'field.ses.template_transaction',
            'D2S_SES_TEMPLATE_TRANSACTION_EN'=>'field.ses.template_transaction_en',
            'D2S_NOTIFICATION_TEMPLATE_FALLBACK'=>'field.notification.fallback_locale',
        ],
    ],
    'sms' => [
        'group.sms' => [
            'D2S_SMS_ENABLED'=>'field.sms.enabled','D2S_SMS_SECRET_ID'=>'field.sms.secret_id','D2S_SMS_SECRET_KEY'=>'field.sms.secret_key',
            'D2S_SMS_REGION'=>'field.sms.region','D2S_SMS_APP_ID'=>'field.sms.app_id','D2S_SMS_SIGN_NAME'=>'field.sms.sign_name',
            'D2S_SMS_TEMPLATE_REGISTER'=>'field.sms.template_register',
            'D2S_SMS_TEMPLATE_PASSWORD_RESET'=>'field.sms.template_password_reset',
            'D2S_SMS_TEMPLATE_BIND_CONTACT'=>'field.sms.template_bind_contact',
            'D2S_SMS_TEMPLATE_TRANSACTION'=>'field.sms.template_transaction',
            'D2S_SMS_TEMPLATE_TRANSACTION_EN'=>'field.sms.template_transaction_en',
            'D2S_NOTIFICATION_TEMPLATE_FALLBACK'=>'field.notification.fallback_locale',
        ],
    ],
];
$deliveryText = static fn(string $key, array $params = []): string => d2s_escape(d2s_tr('delivery.' . $key, $params));
$deliveryGroups = $groups[$deliverySection];
$deliveryTitleKey = $deliveryIsSmtp ? 'title.smtp' : ($deliveryIsSes ? 'title.ses' : 'title.sms');
$deliveryTitle = $deliveryText($deliveryTitleKey);
$deliveryDescriptionKey = $deliveryIsSmtp ? 'description.smtp' : ($deliveryIsSes ? 'description.ses' : 'description.sms');
$deliveryDescription = $deliveryText($deliveryDescriptionKey);
$currentDriver = (string)($configuration['values']['D2S_MAIL_DRIVER'] ?? '');
$pageDriver = $deliveryIsSmtp ? 'smtp' : 'ses';
$driverAction = $deliveryIsEmail && $currentDriver === $pageDriver ? 'enable' : 'keep';
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $deliveryTitle ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/accounts.js')) ?>" defer></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/delivery-settings.js')) ?>" type="module"></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main portal-auth portal-settings">
    <section class="portal-card">
        <h1><?= $deliveryTitle ?></h1>
        <p><?= $deliveryDescription ?></p>
        <form id="delivery-settings-form" data-section="<?= d2s_escape($deliverySection) ?>">
            <?php if ($deliveryIsEmail): ?>
            <fieldset>
                <legend><?= $deliveryText('email.state') ?></legend>
                <label for="mail-driver-action"><?= $deliveryText('email.action') ?></label>
                <select id="mail-driver-action">
                    <option value="keep"<?= $driverAction === 'keep' ? ' selected' : '' ?>><?= $deliveryText('email.keep') ?></option>
                    <option value="enable"<?= $driverAction === 'enable' ? ' selected' : '' ?>><?= $deliveryText('email.enable') ?></option>
                    <option value="disable"><?= $deliveryText('email.disable') ?></option>
                </select>
                <p class="portal-help"><?= $deliveryText('email.current') ?><?= $deliveryText('email.current_separator') ?><?= $currentDriver === 'smtp' ? $deliveryText('email.driver.smtp') : ($currentDriver === 'ses' ? $deliveryText('email.driver.ses') : $deliveryText('email.disabled')) ?></p>
            </fieldset>
            <?php endif ?>
            <?php foreach ($deliveryGroups as $title => $fields): ?>
            <fieldset><legend><?= $deliveryText($title) ?></legend><div class="portal-settings-grid">
                <?php foreach ($fields as $name => $label): ?>
                <div>
                    <label for="<?= d2s_escape($name) ?>"><?= $deliveryText($label) ?></label>
                    <?php if ($name === 'D2S_NOTIFICATION_TEMPLATE_FALLBACK'): ?>
                    <select id="<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>">
                        <option value="zh-Hans"<?= $configuration['values'][$name] === 'zh-Hans' ? ' selected' : '' ?>><?= d2s_escape($deliveryLocaleRegistry->get('zh-Hans')['native_name'] ?? 'zh-Hans') ?></option>
                        <option value="en"<?= $configuration['values'][$name] === 'en' ? ' selected' : '' ?>><?= d2s_escape($deliveryLocaleRegistry->get('en')['native_name'] ?? 'en') ?></option>
                    </select>
                    <?php elseif ($name === 'D2S_SMS_ENABLED'): ?>
                    <select id="<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>">
                        <option value="false"<?= $configuration['values'][$name] === 'false' ? ' selected' : '' ?>><?= $deliveryText('option.off') ?></option>
                        <option value="true"<?= $configuration['values'][$name] === 'true' ? ' selected' : '' ?>><?= $deliveryText('option.on') ?></option>
                    </select>
                    <?php elseif (in_array($name, DeliverySettings::SECRET_FIELDS, true)): ?>
                    <input id="<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" type="password" autocomplete="new-password" maxlength="2048" placeholder="<?= $configuration['secrets_configured'][$name] ? $deliveryText('secret.configured') : $deliveryText('secret.empty') ?>">
                    <label class="portal-clear"><input type="checkbox" name="clear__<?= d2s_escape($name) ?>"><?= $deliveryText('secret.clear') ?></label>
                    <?php else: ?>
                    <input id="<?= d2s_escape($name) ?>" name="<?= d2s_escape($name) ?>" value="<?= d2s_escape($configuration['values'][$name]) ?>" maxlength="2048" autocomplete="off">
                    <?php endif ?>
                </div>
                <?php endforeach ?>
            </div></fieldset>
            <?php endforeach ?>
            <p id="settings-message" role="status" aria-live="polite"></p>
            <button type="submit" class="portal-primary"><?= $deliveryText('action.save') . ' ' . $deliveryTitle ?></button>
            <?php if ($deliveryIsEmail): ?>
                <button type="button" id="delivery-test"><?= $deliveryText('action.test') ?></button>
            <?php endif ?>
        </form>
        <p class="portal-help"><?= $deliveryText($deliveryIsSmtp ? 'hint.smtp' : ($deliveryIsSes ? 'hint.ses' : 'hint.sms')) ?></p>
        <section class="delivery-coverage" aria-labelledby="delivery-coverage-title">
            <h2 id="delivery-coverage-title"><?= $deliveryText('coverage.title') ?></h2>
            <p class="portal-help" data-coverage-description data-fallback-name="<?= d2s_escape($deliveryLocaleRegistry->get($configuration['notification_template_coverage']['fallback_locale'])['native_name'] ?? $configuration['notification_template_coverage']['fallback_locale']) ?>"><?= $deliveryText('coverage.description', ['language'=>$deliveryLocaleRegistry->get($configuration['notification_template_coverage']['fallback_locale'])['native_name'] ?? $configuration['notification_template_coverage']['fallback_locale']]) ?></p>
            <div class="delivery-coverage-scroll">
                <table>
                    <thead><tr>
                        <th scope="col"><?= $deliveryText('coverage.locale') ?></th>
                        <th scope="col">SMTP</th><th scope="col"><?= $deliveryText('email.driver.ses') ?></th><th scope="col"><?= $deliveryText('title.sms') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($configuration['notification_template_coverage']['locales'] as $coverageLocale): ?>
                        <tr data-coverage-locale="<?= d2s_escape($coverageLocale['locale']) ?>">
                            <th scope="row"><span><?= d2s_escape($coverageLocale['native_name']) ?></span> <code><?= d2s_escape($coverageLocale['locale']) ?></code></th>
                            <?php foreach (['smtp','ses','sms'] as $channel): $coverageCell = $coverageLocale['channels'][$channel]; ?>
                                <td data-coverage-channel="<?= d2s_escape($channel) ?>" data-status="<?= d2s_escape($coverageCell['status']) ?>" data-effective-locale="<?= d2s_escape((string)($coverageCell['effective_locale'] ?? '')) ?>" data-effective-name="<?= d2s_escape((string)($coverageCell['effective_name'] ?? '')) ?>">
                                    <?php if ($coverageCell['status'] === 'direct'): ?><?= $deliveryText('coverage.direct', ['language'=>$coverageCell['effective_name']]) ?>
                                    <?php elseif ($coverageCell['status'] === 'fallback'): ?><?= $deliveryText('coverage.fallback_to', ['language'=>$coverageCell['effective_name']]) ?>
                                    <?php else: ?><?= $deliveryText('coverage.unavailable') ?><?php endif ?>
                                </td>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php if (!$deliveryIsSmtp): ?><p class="portal-help"><?= $deliveryText('hint.notification_templates') ?></p><?php endif ?>
    </section>
</main>
</body>
</html>
