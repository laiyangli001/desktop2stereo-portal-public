<?php
declare(strict_types=1);
$uiNamespaces = [...($uiNamespaces ?? []), 'accounts'];
$legacyDomTranslation = false;
$profileSection = $_GET['section'] ?? '';
$phoneAvailable = D2S\services\DeliveryGateway::available('phone', $deliveryConfig);
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape(d2s_tr('accounts.nav.profile')) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/accounts.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="portal-main portal-auth profile-page" translate="no">
    <?php require D2S_ROOT.'/template/partials/user-nav.php'; ?>
    <div class="user-workspace">
    <section class="portal-card profile-settings">
        <header class="admin-page-heading">
            <div><p class="portal-eyebrow"><?= d2s_escape(d2s_tr('accounts.profile.settings')) ?></p><h1><?= d2s_escape(d2s_tr('accounts.nav.profile')) ?></h1></div>
        </header>

            <p><strong><?= d2s_escape(d2s_tr('accounts.labels.username')) ?></strong>：<?= d2s_escape($profile['username']) ?></p>

        <section class="profile-settings-section" aria-labelledby="contact-heading">
            <h2 id="contact-heading"><?= d2s_escape(d2s_tr('accounts.profile.contact_title')) ?></h2>
            <div class="profile-summary">
                <p><strong><?= d2s_escape(d2s_tr('accounts.labels.verified_contacts')) ?></strong>：</p>
                <ul><?php foreach ($profile['contacts'] as $contact): ?>
                    <li><?= d2s_escape($contact['kind'] === 'email' ? d2s_tr('accounts.labels.email') : d2s_tr('accounts.labels.phone')) ?>：<?= d2s_escape($contact['value']) ?></li>
                <?php endforeach ?></ul>
            </div>
            <details id="profile-contact-edit" name="profile-edit"<?= $profileSection === 'contact' ? ' open' : '' ?>>
                <summary><?= d2s_escape(d2s_tr('accounts.actions.change_contact')) ?></summary>
                <form id="account-form" data-mode="contact" data-email="<?= D2S\services\DeliveryGateway::available('email', $deliveryConfig) ? 'true' : 'false' ?>" data-phone="<?= $phoneAvailable ? 'true' : 'false' ?>">
                    <label for="kind"><?= d2s_escape(d2s_tr('accounts.labels.verification_method')) ?></label>
                    <select id="kind" name="kind"><option value="email"><?= d2s_escape(d2s_tr('accounts.labels.email_verification')) ?></option>
                        <option value="phone"<?= $phoneAvailable ? '' : ' disabled' ?>><?= d2s_escape(d2s_tr('accounts.labels.phone_verification')) ?></option>
                    </select>
                    <?php if (!$phoneAvailable): ?>
                        <p class="portal-help"><?= d2s_escape(d2s_tr('accounts.errors.phone_registration_unavailable')) ?></p>
                    <?php endif ?>
                    <label for="contact"><?= d2s_escape(d2s_tr('accounts.labels.new_contact')) ?></label><input id="contact" name="contact" type="email" maxlength="254" required>
                    <div class="portal-code">
                        <div><label for="code"><?= d2s_escape(d2s_tr('accounts.labels.code')) ?></label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
                        <button id="send-code" type="button"><?= d2s_escape(d2s_tr('accounts.actions.send_code')) ?></button>
                    </div>
                    <label for="password"><?= d2s_escape(d2s_tr('accounts.labels.current_password')) ?></label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="64" required>
                    <p class="portal-help"><?= d2s_escape(d2s_tr('accounts.profile.contact_help')) ?></p>
                    <p id="form-message" role="status" aria-live="polite"></p>
                    <button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('accounts.actions.verify_save')) ?></button>
                </form>
            </details>
        </section>
        <section class="profile-settings-section" aria-labelledby="security-heading">
            <h2 id="security-heading"><?= d2s_escape(d2s_tr('accounts.profile.security')) ?></h2>
            <details id="profile-password-edit" name="profile-edit"<?= $profileSection === 'password' ? ' open' : '' ?>>
                <summary><?= d2s_escape(d2s_tr('accounts.actions.change_password')) ?></summary>
                <form id="password-form" data-mode="password">
                    <label for="current-password"><?= d2s_escape(d2s_tr('accounts.labels.current_password')) ?></label>
                    <input id="current-password" name="current_password" type="password" autocomplete="current-password" maxlength="64" required>
                    <label for="new-password"><?= d2s_escape(d2s_tr('accounts.labels.new_password')) ?></label>
                    <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="10" maxlength="64" required>
                    <label for="confirm-password"><?= d2s_escape(d2s_tr('accounts.labels.confirm_password')) ?></label>
                    <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="10" maxlength="64" required>
                    <p class="portal-help"><?= d2s_escape(d2s_tr('accounts.profile.password_help')) ?></p>
                    <p id="password-message" role="status" aria-live="polite"></p>
                    <button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('accounts.actions.change_password')) ?></button>
                </form>
            </details>
        </section>

        <div class="profile-security-actions">
            <p class="portal-help"><?= d2s_escape(d2s_tr('accounts.profile.privacy_help')) ?> <a href="<?= d2s_escape(d2s_url('/feedback?view=form&type=account#feedback-form-section')) ?>"><?= d2s_escape(d2s_tr('accounts.profile.private_ticket')) ?></a> · <a href="<?= d2s_escape(d2s_url('/privacy-policy')) ?>"><?= d2s_escape(d2s_tr('accounts.legal.privacy')) ?></a></p>
        </div>
    </section>
    </div>
</main>
</body>
</html>
