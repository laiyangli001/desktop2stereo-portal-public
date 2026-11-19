<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'installation']));
$legacyDomTranslation = false;
$installed = D2S\services\Installation::installed();
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= d2s_tr('installation.page.title') ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/operations.css">
    <?php require D2S_ROOT.'/template/partials/locale-runtime.php'; ?>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/install.js')) ?>" defer></script>
</head>
<body>
<main class="operation-help">
    <p class="portal-eyebrow">Desktop2Stereo</p><h1><?= d2s_tr('installation.page.heading') ?></h1>
    <?php if($installed): ?>
    <section class="portal-card"><h2><?= d2s_tr('installation.installed.title') ?></h2>
        <p><?= d2s_tr('installation.installed.description') ?></p>
        <a href="<?= d2s_escape(d2s_url('/admin/login')) ?>"><?= d2s_tr('installation.installed.sign_in') ?> →</a>
    </section>
    <?php else: ?>
    <section class="portal-card">
        <h2><?= d2s_tr('installation.environment.heading') ?></h2>
        <p><?= d2s_tr('installation.environment.requirements') ?></p>
        <div id="install-environment" aria-live="polite"></div><button id="install-recheck" type="button"><?= d2s_tr('installation.environment.retry') ?></button>
    </section>
    <section class="portal-card">
        <h2><?= d2s_tr('installation.database.heading') ?></h2>
        <p class="portal-help"><?= d2s_tr('installation.database.token_help') ?></p>
        <form id="install-form" class="operation-form">
            <label class="wide"><?= d2s_tr('installation.field.install_token') ?><input name="install_token" type="password" autocomplete="off" required></label>
            <label><?= d2s_tr('installation.field.db_host') ?><input name="db_host" value="127.0.0.1" required></label>
            <label><?= d2s_tr('installation.field.db_port') ?><input name="db_port" type="number" min="1" max="65535" value="3306" required></label>
            <label><?= d2s_tr('installation.field.db_name') ?><input name="db_name" pattern="[A-Za-z0-9_]{1,64}" required></label>
            <label><?= d2s_tr('installation.field.db_user') ?><input name="db_user" autocomplete="off" required></label>
            <label><?= d2s_tr('installation.field.db_password') ?><input name="db_password" type="password" autocomplete="off"></label>
            <label><?= d2s_tr('installation.field.app_url') ?><input name="app_url" type="url" required></label>
            <label><?= d2s_tr('installation.field.admin_username') ?><input name="admin_username" pattern="(?![0-9]+$)[A-Za-z0-9_-]{3,32}" required></label>
            <label><?= d2s_tr('installation.field.admin_email') ?><input name="admin_email" type="email" required></label>
            <label><?= d2s_tr('installation.field.admin_password') ?><input name="admin_password" type="password" minlength="14" maxlength="64" autocomplete="new-password" required></label>
            <label><?= d2s_tr('installation.field.admin_password_confirm') ?><input name="admin_password_confirm" type="password" minlength="14" maxlength="64" autocomplete="new-password" required></label>
            <p class="wide portal-help"><?= d2s_tr('installation.password.help') ?></p>
            <footer><button id="install-test" type="button"><?= d2s_tr('installation.action.test_database') ?></button><button id="install-run" class="portal-primary" type="submit" disabled><?= d2s_tr('installation.action.install') ?></button></footer>
        </form>
        <p id="install-message" role="status" aria-live="polite"></p>
    </section>
    <?php endif ?>
</main>
</body>
</html>
