<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'public_pages']));
$site=$siteData['site'];
$legacyDomTranslation=false;
$introLocale=(string)($site['translation_locale'] ?? (str_starts_with(d2s_locale_code(),'en')?'en':'zh-Hans'));
$intro=(string)($site['intro'] ?? $site['intro_'.($introLocale==='en'?'en':'zh')] ?? '');
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <?php $seoPath=$path; require D2S_ROOT.'/template/partials/seo-head.php'; ?>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('public_pages.about.about_us') ?> · <?= d2s_escape($site['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="legal-main">
    <p class="portal-eyebrow">DESKTOP2STEREO</p>
    <h1><?= d2s_tr('public_pages.about.about_us') ?></h1>
    <article class="portal-card legal-card">
        <section><h2><?= d2s_tr('public_pages.about.operator') ?></h2><p><?= d2s_escape($site['company_name']) ?></p></section>
        <section><h2><?= d2s_tr('public_pages.about.products_and_services') ?></h2><p lang="<?= d2s_escape($introLocale) ?>" translate="no"><?= d2s_escape($intro) ?></p></section>
        <section><h2><?= d2s_tr('public_pages.about.account_and_licensing') ?></h2><p><?= d2s_tr('public_pages.about.this_site_provides_registration_and_login_software_trials_and_formal_licenses_device_and_session_management_orders_and_wallet_services_private_support_and_technical_services_each_account_has_one_formal_desktop2stereo_license_online_sessions_and_heartbeats_control_active_devices_while_a_signed_credential_permits_limited_24_hour_offline_operation') ?></p></section>
        <section><h2><?= d2s_tr('public_pages.about.downloads_and_source') ?></h2><p><?= d2s_tr('public_pages.about.installers_are_hosted_by_external_platforms_such_as_github_releases_quark_drive_or_baidu_drive_obtain_installers_from_links_published_here_or_official_software_project_sources_and_verify_version_and_file_information_a_download_site_statement_is_not_treated_as_a_license_from_the_original_author') ?></p></section>
        <section><h2><?= d2s_tr('public_pages.about.community_and_content_scope') ?></h2><p><?= d2s_tr('public_pages.about.this_site_does_not_provide_public_forums_comments_groups_ratings_or_public_user_publishing_support_is_handled_through_private_tickets_visible_only_to_the_user_and_authorized_staff_enterprise_wechat_conversations_are_not_automatically_copied_into_tickets') ?></p></section>
        <section><h2><?= d2s_tr('public_pages.about.policies_and_contact') ?></h2><p><a href="/business-license"><?= d2s_tr('public_pages.about.business_license_and_operator_information') ?></a> · <a href="/contact"><?= d2s_tr('public_pages.about.contact_us') ?></a> · <a href="/terms"><?= d2s_tr('public_pages.about.terms') ?></a> · <a href="/privacy-policy"><?= d2s_tr('public_pages.about.privacy') ?></a> · <a href="/legal-statement"><?= d2s_tr('public_pages.about.legal_statement') ?></a></p></section>
    </article>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
