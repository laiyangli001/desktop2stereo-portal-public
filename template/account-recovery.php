<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'public_pages']));
$legacyDomTranslation = false;
$recoverySite = $siteData['site'];
$recoveryEmail = (string)$recoverySite['contact_email'];
$recoverySubject = d2s_tr('public_pages.recovery.subject');
$recoveryMailto = 'mailto:' . $recoveryEmail . '?subject=' . rawurlencode($recoverySubject);
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= d2s_tr('public_pages.recovery.title') ?> · <?= d2s_escape($recoverySite['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="legal-main recovery-main">
    <p class="portal-eyebrow"><?= d2s_tr('public_pages.recovery.eyebrow') ?></p>
    <h1><?= d2s_tr('public_pages.recovery.title') ?></h1>
    <section class="portal-card legal-card">
        <p class="portal-intro"><?= d2s_tr('public_pages.recovery.intro') ?></p>

        <h2><?= d2s_tr('public_pages.recovery.choose_path') ?></h2>
        <dl class="legal-facts recovery-facts">
            <dt><?= d2s_tr('public_pages.recovery.password_works') ?></dt>
            <dd><?= d2s_tr('public_pages.recovery.password_works_detail') ?></dd>
            <dt><?= d2s_tr('public_pages.recovery.old_phone_forgot_password') ?></dt>
            <dd><?= d2s_tr('public_pages.recovery.old_phone_forgot_password_detail') ?></dd>
            <dt><?= d2s_tr('public_pages.recovery.reachable_contact') ?></dt>
            <dd><?= d2s_tr('public_pages.recovery.reachable_contact_detail') ?></dd>
        </dl>

        <h2><?= d2s_tr('public_pages.recovery.contact_channel') ?></h2>
        <p><?= d2s_tr('public_pages.recovery.official_email') ?>：<a class="recovery-email" href="<?= d2s_escape($recoveryMailto) ?>"><?= d2s_escape($recoveryEmail) ?></a></p>
        <p><?= d2s_tr('public_pages.recovery.email_instructions') ?></p>

        <h2><?= d2s_tr('public_pages.recovery.suggested_information') ?></h2>
        <ul>
            <li><?= d2s_tr('public_pages.recovery.username_and_phone_tail') ?></li>
            <li><?= d2s_tr('public_pages.recovery.last_login_details') ?></li>
            <li><?= d2s_tr('public_pages.recovery.purchase_evidence') ?></li>
            <li><?= d2s_tr('public_pages.recovery.new_phone_details') ?></li>
        </ul>

        <h2><?= d2s_tr('public_pages.recovery.handling_principles') ?></h2>
        <ul>
            <li><?= d2s_tr('public_pages.recovery.verify_multiple_facts') ?></li>
            <li><?= d2s_tr('public_pages.recovery.reset_sign_in') ?></li>
            <li><?= d2s_tr('public_pages.recovery.insufficient_evidence') ?></li>
            <li><?= d2s_tr('public_pages.recovery.never_request_secrets') ?></li>
        </ul>

        <p class="portal-help"><?= d2s_tr('public_pages.recovery.before_submit') ?> <a href="<?= d2s_escape(d2s_url('/privacy-policy')) ?>"><?= d2s_tr('public_pages.recovery.privacy_policy') ?></a> · <a href="<?= d2s_escape(d2s_url('/contact')) ?>"><?= d2s_tr('public_pages.recovery.contact_us') ?></a>。</p>
        <p class="portal-help"><?= d2s_tr('public_pages.recovery.page_disclaimer') ?></p>
    </section>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
