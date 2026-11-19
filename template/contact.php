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
    <title><?= d2s_tr('public_pages.contact.contact_us') ?> · <?= d2s_escape($site['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="legal-main">
    <p class="portal-eyebrow"><?= d2s_tr('public_pages.contact.operator_and_contact') ?></p>
    <h1><?= d2s_tr('public_pages.contact.contact_us') ?></h1>
    <section class="portal-card legal-card">
        <dl class="legal-facts">
            <dt><?= d2s_tr('public_pages.contact.operator') ?></dt><dd><?= d2s_escape($site['company_name']) ?></dd>
            <dt><?= d2s_tr('public_pages.contact.email') ?></dt><dd><a href="mailto:<?= d2s_escape($site['contact_email']) ?>"><?= d2s_escape($site['contact_email']) ?></a></dd>
            <dt><?= d2s_tr('public_pages.contact.registered_address') ?></dt><dd><?= d2s_tr('public_pages.contact.as_shown_in_the_published_business_license') ?> <a href="/business-license"><?= d2s_tr('public_pages.contact.view_business_license_and_operator_information') ?></a></dd>
            <dt><?= d2s_tr('public_pages.contact.software_and_license_support') ?></dt><dd><?= d2s_tr('public_pages.contact.sign_in_to_use') ?> <a href="/tickets"><?= d2s_tr('public_pages.contact.private_support_tickets') ?></a><?= d2s_tr('public_pages.contact.and_optionally_link_your_own_order') ?></dd>
            <dt><?= d2s_tr('public_pages.contact.personal_information_rights') ?></dt><dd><?= d2s_tr('public_pages.contact.requests_to_access_copy_correct_supplement_delete_restrict_processing_or_delete_an_account_may_be_submitted_through_private_support_or_the_email_above') ?></dd>
            <dt><?= d2s_tr('public_pages.contact.complaints_and_infringement_notices') ?></dt><dd><?= d2s_tr('public_pages.contact.please_include_the_relevant_page_facts_rights_basis_and_valid_contact_details_additional_verification_may_be_requested') ?></dd>
            <dt><?= d2s_tr('public_pages.contact.domains') ?></dt><dd>d2s.site · d2s.top</dd>
        </dl>
        <h2><?= d2s_tr('public_pages.contact.handling') ?></h2>
        <p><?= d2s_tr('public_pages.contact.for_account_or_personal_information_requests_we_first_verify_control_of_the_account_or_contact_channel_our_target_is_an_initial_response_within_10_business_days_complex_disputes_payment_channel_checks_or_legal_procedures_may_take_longer') ?></p>
        <p><?= d2s_tr('public_pages.contact.do_not_send_passwords_full_verification_codes_private_keys_complete_identity_document_numbers_or_payment_secrets_by_ordinary_email') ?></p>
    </section>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
