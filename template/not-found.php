<?php
declare(strict_types=1);
$legacyDomTranslation = false;
$title = d2s_tr('common.errors.page_not_found');
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 · <?= d2s_escape($title) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/not-found.css')) ?>">
</head>
<body class="not-found-page">
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="site-main not-found-main" id="not-found">
    <div class="portal-card not-found-panel">
        <div class="not-found-copy">
            <h1><?= d2s_escape($title) ?></h1>
            <p><?= d2s_escape(d2s_tr($descriptionKey)) ?></p>
            <div class="not-found-actions">
                <a class="site-primary" href="<?= d2s_escape(d2s_url('/')) ?>"><?= d2s_escape(d2s_tr('common.not_found.home')) ?></a>
                <a class="site-secondary" href="<?= d2s_escape(d2s_url('/help')) ?>"><?= d2s_escape(d2s_tr('common.help.title')) ?></a>
            </div>
        </div>
        <span class="not-found-code" aria-hidden="true">404</span>
    </div>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
