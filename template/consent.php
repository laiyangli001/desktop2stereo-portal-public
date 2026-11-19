<?php
declare(strict_types=1);
$uiNamespaces = [...($uiNamespaces ?? []), 'accounts'];
$legacyDomTranslation = false;
$site=$siteData['site'];
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape(d2s_tr('accounts.consent.title')) ?> · <?= d2s_escape($site['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/accounts.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="portal-main portal-auth" translate="no">
    <section class="portal-card">
        <p class="portal-eyebrow"><?= d2s_escape(d2s_tr('accounts.consent.eyebrow')) ?></p>
        <h1><?= d2s_escape(d2s_tr('accounts.consent.heading')) ?></h1>
        <p><?= d2s_escape(d2s_tr('accounts.consent.description')) ?></p>
        <form id="consent-form">
            <label class="portal-check">
                <input id="consent-accepted" name="legal_accepted" type="checkbox" required>
                <?= d2s_escape(d2s_tr('accounts.legal.agree')) ?>
                <a href="<?= d2s_escape(d2s_url('/terms')) ?>"><?= d2s_escape(d2s_tr('accounts.legal.terms')) ?></a>
                <?= d2s_escape(d2s_tr('accounts.legal.and')) ?>
                <a href="<?= d2s_escape(d2s_url('/privacy-policy')) ?>"><?= d2s_escape(d2s_tr('accounts.legal.privacy')) ?></a>
            </label>
            <p class="portal-help">
                <?= d2s_escape(d2s_tr('accounts.legal.terms_version')) ?> <?= d2s_escape($site['terms_version']) ?>
                · <?= d2s_escape(d2s_tr('accounts.legal.privacy_version')) ?> <?= d2s_escape($site['privacy_version']) ?>
                <?php if($site['privacy_effective']!==''): ?> · <?= d2s_escape(d2s_tr('accounts.legal.effective')) ?> <?= d2s_escape($site['privacy_effective']) ?><?php endif ?>
            </p>
            <p id="consent-message" role="status" aria-live="polite"></p>
            <button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('accounts.consent.confirm')) ?></button>
        </form>
    </section>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
