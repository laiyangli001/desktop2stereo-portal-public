<?php
declare(strict_types=1);
$canRequest = count(array_intersect(['audit.export.request','audit.export.approve'],$permissions)) > 0;
$uiNamespaces = ['audit'];
$legacyDomTranslation = false;
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('audit.page.title') ?> · Desktop2Stereo <?= d2s_tr('audit.page.admin') ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/audit.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/audit.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main audit-main" id="audit-app"
      data-can-request="<?= $canRequest ? 'true' : 'false' ?>">
    <header class="admin-page-heading">
        <div>
            <p class="portal-eyebrow"><?= d2s_tr('audit.page.admin') ?></p>
            <h1><?= d2s_tr('audit.page.title') ?></h1>
            <p><?= d2s_tr('audit.page.intro') ?></p>
        </div>
        <div class="admin-page-actions"><a class="site-secondary" href="/admin"><?= d2s_tr('audit.page.console') ?></a></div>
    </header>
    <p id="audit-message" role="status" aria-live="polite"></p>
    <section class="portal-card admin-panel audit-filter-panel" aria-labelledby="audit-filter-title">
        <div class="audit-section-heading">
            <div><h2 id="audit-filter-title"><?= d2s_tr('audit.table.region') ?></h2><p><?= d2s_tr('audit.page.intro') ?></p></div>
            <label class="audit-resource-control" for="audit-resource"><span><?= d2s_tr('audit.resource.label') ?></span><select id="audit-resource">
                <option value="events"><?= d2s_tr('audit.resource.events') ?></option>
                <option value="registrations"><?= d2s_tr('audit.resource.registrations') ?></option>
                <option value="operations"><?= d2s_tr('audit.resource.operations') ?></option>
            </select></label>
        </div>
        <form id="audit-filter" class="support-filter-grid audit-filter-grid">
            <label><?= d2s_tr('audit.filter.start') ?><input id="audit-start" name="start_time" type="datetime-local" required></label>
            <label><?= d2s_tr('audit.filter.end') ?><input id="audit-end" name="end_time" type="datetime-local" required></label>
            <label data-filter="access_log"><?= d2s_tr('audit.filter.user_id') ?><input id="audit-user-id" inputmode="numeric" maxlength="19"></label>
            <label data-filter="access_log"><?= d2s_tr('audit.filter.email') ?><input id="audit-email" type="email" maxlength="254"></label>
            <label data-filter="access_log"><?= d2s_tr('audit.filter.ip') ?><input id="audit-ip" maxlength="64"></label>
            <label data-filter="access_log"><?= d2s_tr('audit.filter.action') ?>
                <select id="audit-action">
                    <option value=""><?= d2s_tr('audit.filter.all') ?></option>
                    <option value="REGISTER"><?= d2s_tr('audit.action_type.register') ?></option>
                    <option value="LOGIN"><?= d2s_tr('audit.action_type.login') ?></option>
                    <option value="LOGOUT"><?= d2s_tr('audit.action_type.logout') ?></option>
                    <option value="PURCHASE"><?= d2s_tr('audit.action_type.purchase') ?></option>
                    <option value="DOWNLOAD"><?= d2s_tr('audit.action_type.download') ?></option>
                    <option value="PASSWORD_RESET"><?= d2s_tr('audit.action_type.password_reset') ?></option>
                    <option value="PROFILE_UPDATE"><?= d2s_tr('audit.action_type.profile_update') ?></option>
                    <option value="WITHDRAWAL"><?= d2s_tr('audit.action_type.withdrawal') ?></option>
                </select>
            </label>
            <label data-filter="admin_audit"><?= d2s_tr('audit.filter.operator') ?><input id="audit-operator" type="email" maxlength="254"></label>
            <label data-filter="admin_audit"><?= d2s_tr('audit.filter.operation') ?><input id="audit-operation" maxlength="64"></label>
            <div class="audit-filter-actions">
                <button id="audit-search" class="portal-primary" type="submit"><?= d2s_tr('audit.action.search') ?></button>
                <button id="audit-export-request" type="button" <?= $canRequest ? '' : 'hidden' ?>><?= d2s_tr('audit.action.export_request') ?></button>
            </div>
        </form>
    </section>
    <section class="portal-card admin-panel audit-results-panel">
        <div class="audit-section-heading"><div><h2><?= d2s_tr('audit.table.region') ?></h2><p id="audit-page"></p></div></div>
        <div class="audit-table-wrap" tabindex="0" role="region" aria-label="<?= d2s_tr('audit.table.region') ?>"><table id="audit-table"></table></div>
        <nav class="support-pager" aria-label="<?= d2s_tr('audit.pager.label') ?>">
            <button id="audit-previous" type="button"><?= d2s_tr('audit.pager.previous') ?></button><span aria-hidden="true">↔</span><button id="audit-next" type="button"><?= d2s_tr('audit.pager.next') ?></button>
        </nav>
    </section>
    <section class="portal-card admin-panel audit-exports-panel" aria-labelledby="audit-export-title">
        <div class="audit-section-heading"><div><h2 id="audit-export-title"><?= d2s_tr('audit.export.title') ?></h2><p><?= d2s_tr('audit.export.intro') ?></p></div></div>
        <div id="audit-exports" class="audit-export-list" aria-live="polite"></div>
    </section>
</main>
</body>
</html>
