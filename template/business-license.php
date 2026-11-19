<?php
declare(strict_types=1);

$legacyDomTranslation = false;
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'public_pages']));
$site=$siteData['site'];
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <?php $seoPath=$path; require D2S_ROOT.'/template/partials/seo-head.php'; ?>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('public_pages.business_license.business_license') ?> · <?= d2s_escape($site['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="legal-main">
    <p class="portal-eyebrow"><?= d2s_tr('public_pages.business_license.corporate_disclosure') ?></p>
    <h1><?= d2s_tr('public_pages.business_license.business_license_and_corporate_information') ?></h1>
    <section class="portal-card legal-card">
        <dl class="legal-facts">
            <dt><?= d2s_tr('public_pages.business_license.operator') ?></dt><dd><?= d2s_escape($site['company_name']) ?></dd>
            <dt><?= d2s_tr('public_pages.business_license.privacy_contact') ?></dt><dd><a href="mailto:<?= d2s_escape($site['contact_email']) ?>"><?= d2s_escape($site['contact_email']) ?></a></dd>
            <dt><?= d2s_tr('public_pages.business_license.domains') ?></dt><dd>d2s.site · d2s.top</dd>
            <dt><?= d2s_tr('public_pages.business_license.icp_filing') ?></dt><dd>
                <?php if ((string)($site['icp_site_number'] ?? '') !== ''): ?><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= d2s_escape($site['icp_site_number']) ?></a><?php endif ?>
                <?php if ((string)($site['icp_top_number'] ?? '') !== ''): ?> · <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= d2s_escape($site['icp_top_number']) ?></a><?php endif ?>
            </dd>
        </dl>
        <figure class="license-figure">
            <a href="/assets/img/business-license.png" target="_blank" rel="noopener noreferrer"><img src="/assets/img/business-license.png" alt="<?= d2s_tr('public_pages.business_license.business_license_of_yantai_tulishi_software_development_co_ltd') ?>"></a>
            <figcaption><?= d2s_tr('public_pages.business_license.business_license_image_click_for_full_size') ?></figcaption>
        </figure>
    </section>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
