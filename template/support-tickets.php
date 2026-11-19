<?php
declare(strict_types=1);

$legacyDomTranslation = false;
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('support.page.my_tickets') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/vendor/quill-2.0.3/quill.snow.css">
    <link rel="stylesheet" href="/assets/css/support.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/support-format.js')) ?>" defer></script>
    <script src="/assets/vendor/quill-2.0.3/quill.js" defer></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/support-tickets.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="portal-main support-main" id="ticket-app">
    <?php require D2S_ROOT.'/template/partials/ticket-workspace.php'; ?>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
