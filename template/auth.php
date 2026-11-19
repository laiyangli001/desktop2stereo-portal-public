<?php
declare(strict_types=1);

use D2S\services\DeliveryGateway;

$hasVerification = in_array($pageMode, ['register','reset'], true);
$isAdmin = $pageMode === 'admin';
$uiNamespaces = ['accounts'];
$legacyDomTranslation = false;
$title = d2s_tr('accounts.title.'.$pageMode);
$pageNext = D2S\domain\AccountReturnPath::normalize($pageNext ?? null);
$nextQuery = $pageNext === '' ? '' : '?next=' . rawurlencode($pageNext);
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape($title) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/accounts.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="portal-main portal-auth" translate="no">
    <section class="portal-card">
        <h1><?= d2s_escape($title) ?></h1>
        <form id="account-form" data-mode="<?= d2s_escape($pageMode) ?>" data-next="<?= d2s_escape($pageNext) ?>" data-email="<?= DeliveryGateway::available('email', $deliveryConfig) ? 'true' : 'false' ?>" data-phone="<?= DeliveryGateway::available('phone', $deliveryConfig) ? 'true' : 'false' ?>" data-captcha-enabled="<?= ($captchaConfiguration['enabled'] ?? true) ? 'true' : 'false' ?>">
            <?php if ($pageMode === 'register'): ?>
                <label for="username"><?= d2s_escape(d2s_tr('accounts.labels.username')) ?></label>
                <input id="username" name="username" autocomplete="username" minlength="3" maxlength="32" pattern="(?![0-9]+$)[A-Za-z0-9_-]{3,32}" required>
                <p class="portal-help"><?= d2s_escape(d2s_tr('accounts.help.username')) ?></p>

            <?php endif ?>
            <?php if ($hasVerification): ?>
                <label for="kind"><?= d2s_escape(d2s_tr('accounts.labels.verification_method')) ?></label>
                <select id="kind" name="kind">
                    <option value="email"><?= d2s_escape(d2s_tr('accounts.labels.email_verification')) ?></option>
                    <?php if (DeliveryGateway::available('phone', $deliveryConfig)): ?>
                        <option value="phone"><?= d2s_escape(d2s_tr('accounts.labels.phone_verification')) ?></option>
                    <?php endif ?>
                </select>
                <label for="contact"><?= d2s_escape(d2s_tr('accounts.labels.contact')) ?></label>
                <input id="contact" name="contact" type="email" autocomplete="email" maxlength="254" required>
                <div class="portal-code">
                    <div><label for="code"><?= d2s_escape(d2s_tr('accounts.labels.code')) ?></label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
                    <button id="send-code" type="button"><?= d2s_escape(d2s_tr('accounts.actions.send_code')) ?></button>
                </div>
            <?php else: ?>
                <label for="identifier"><?= d2s_escape(d2s_tr($isAdmin ? 'accounts.labels.admin_identifier' : 'accounts.labels.identifier')) ?></label>
                <input id="identifier" name="identifier" autocomplete="username" maxlength="254" required>
            <?php endif ?>
            <label for="password"><?= d2s_escape(d2s_tr($pageMode === 'reset' ? 'accounts.labels.new_password' : 'accounts.labels.password')) ?></label>
            <input id="password" name="password" type="password" autocomplete="<?= $hasVerification ? 'new-password' : 'current-password' ?>" minlength="<?= $hasVerification ? 6 : 1 ?>" maxlength="64" required>
            <?php if ($pageMode === 'register'): ?>
                <label for="confirm-password"><?= d2s_escape(d2s_tr('accounts.labels.confirm_password')) ?></label>
                <div class="portal-password-field">
                    <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="6" maxlength="64" required>
                </div>
            <?php endif ?>
            <?php if ($hasVerification): ?><p class="portal-help"><?= d2s_escape(d2s_tr('accounts.help.password')) ?></p><?php else: ?>
                <input id="captcha-id" name="captcha_id" type="hidden">
                <input id="captcha-clicks" name="captcha_clicks" type="hidden">
                <div class="behavior-captcha-control"<?= empty($captchaConfiguration['enabled']) ? ' hidden' : '' ?>>
                    <button id="captcha-open" type="button" aria-haspopup="dialog"><?= d2s_escape(d2s_tr('accounts.captcha.open')) ?></button>
                    <span id="captcha-status" role="status" aria-live="polite"><?= d2s_escape(d2s_tr('accounts.captcha.required')) ?></span>
                </div>
                <div id="captcha-dialog" class="behavior-captcha-backdrop" hidden>
                    <section class="behavior-captcha-dialog" role="dialog" aria-modal="true" aria-labelledby="captcha-title">
                        <h2 id="captcha-title"><?= d2s_escape(d2s_tr('accounts.captcha.title')) ?></h2>
                        <div class="behavior-captcha-prompt">
                            <span><?= d2s_escape(d2s_tr('accounts.captcha.instruction')) ?></span>
                            <img id="captcha-thumb" alt="<?= d2s_escape(d2s_tr('accounts.captcha.targets_alt')) ?>">
                            <span id="captcha-progress"><?= d2s_escape(d2s_tr('accounts.captcha.counter', ['done'=>0,'total'=>0])) ?></span>
                        </div>
                        <button id="captcha-image-button" class="behavior-captcha-image-button" type="button" aria-label="<?= d2s_escape(d2s_tr('accounts.captcha.image_label')) ?>">
                            <img id="captcha-master" alt="<?= d2s_escape(d2s_tr('accounts.captcha.image_alt')) ?>" draggable="false">
                            <canvas id="captcha-marks" width="320" height="160" aria-hidden="true"></canvas>
                        </button>
                        <div class="behavior-captcha-actions">
                            <div>
                                <button id="captcha-cancel" type="button" aria-label="<?= d2s_escape(d2s_tr('accounts.captcha.cancel')) ?>">×</button>
                                <button id="captcha-refresh" type="button" aria-label="<?= d2s_escape(d2s_tr('accounts.captcha.refresh')) ?>">⟳</button>
                            </div>
                            <button id="captcha-confirm" class="behavior-captcha-confirm" type="button" disabled><?= d2s_escape(d2s_tr('accounts.captcha.confirm')) ?></button>
                        </div>
                        <p id="captcha-error" role="status" aria-live="polite"></p>
                    </section>
                </div>
            <?php endif ?>
            <?php if ($pageMode === 'register'): ?>
                <label class="portal-check">
                    <input id="legal-accepted" name="legal_accepted" type="checkbox" required>
                    <?= d2s_escape(d2s_tr('accounts.legal.agree')) ?> <a href="<?= d2s_escape(d2s_url('/terms')) ?>" target="_blank" rel="noopener"><?= d2s_escape(d2s_tr('accounts.legal.terms')) ?></a> <?= d2s_escape(d2s_tr('accounts.legal.and')) ?> <a href="<?= d2s_escape(d2s_url('/privacy-policy')) ?>" target="_blank" rel="noopener"><?= d2s_escape(d2s_tr('accounts.legal.privacy')) ?></a>
                    (<?= d2s_escape(d2s_tr('accounts.legal.terms_version')) ?> <?= d2s_escape((string)($siteData['site']['terms_version'] ?? '1.0')) ?> · <?= d2s_escape(d2s_tr('accounts.legal.privacy_version')) ?> <?= d2s_escape((string)($siteData['site']['privacy_version'] ?? '1.0')) ?><?php if (($siteData['site']['privacy_effective'] ?? '') !== ''): ?> · <?= d2s_escape(d2s_tr('accounts.legal.effective')) ?> <?= d2s_escape((string)$siteData['site']['privacy_effective']) ?><?php endif ?>)
                </label>
            <?php endif ?>
            <p id="form-message" role="status" aria-live="polite"></p>
            <button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr($pageMode === 'register' ? 'accounts.actions.register' : ($pageMode === 'reset' ? 'accounts.actions.reset' : 'accounts.actions.login'))) ?></button>
        </form>
        <?php if ($pageMode === 'login'): ?>
            <p class="portal-help"><?= d2s_escape(d2s_tr('accounts.recovery.help')) ?> <a href="<?= d2s_escape(d2s_url('/account-recovery')) ?>"><?= d2s_escape(d2s_tr('accounts.recovery.link')) ?></a></p>
        <?php endif ?>
        <?php if (!$isAdmin): ?>
            <?php if ($pageMode !== 'login'): ?>
            <nav class="portal-links" aria-label="<?= d2s_escape(d2s_tr('accounts.legal.information')) ?>"><a href="<?= d2s_escape(d2s_url('/terms')) ?>"><?= d2s_escape(d2s_tr('accounts.legal.terms')) ?></a><a href="<?= d2s_escape(d2s_url('/privacy-policy')) ?>"><?= d2s_escape(d2s_tr('accounts.legal.privacy')) ?></a><a href="<?= d2s_escape(d2s_url('/legal-statement')) ?>"><?= d2s_escape(d2s_tr('accounts.legal.statement')) ?></a></nav>
            <?php endif ?>
            <nav class="portal-links" aria-label="<?= d2s_escape(d2s_tr('accounts.nav.account')) ?>"><a href="<?= d2s_escape(d2s_url('/login'.$nextQuery)) ?>"><?= d2s_escape(d2s_tr('accounts.actions.login')) ?></a><a href="<?= d2s_escape(d2s_url('/register'.$nextQuery)) ?>"><?= d2s_escape(d2s_tr('accounts.nav.register')) ?></a><a href="<?= d2s_escape(d2s_url('/password-reset'.$nextQuery)) ?>"><?= d2s_escape(d2s_tr('accounts.title.reset')) ?></a><a href="<?= d2s_escape(d2s_url('/account-recovery')) ?>"><?= d2s_escape(d2s_tr('accounts.nav.recovery')) ?></a></nav>
        <?php endif ?>
    </section>
</main>
</body>
</html>
