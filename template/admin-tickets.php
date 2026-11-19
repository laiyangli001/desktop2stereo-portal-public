<?php
declare(strict_types=1);

$legacyDomTranslation = false;
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape(d2s_tr('support.admin.title')) ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/support.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/support-format.js')) ?>" defer></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/admin-tickets.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main support-main" id="admin-ticket-app" data-can-reply="<?= in_array('tickets.reply',$permissions,true) ? 'true' : 'false' ?>">
    <nav class="portal-links" aria-label="<?= d2s_escape(d2s_tr('support.admin.nav')) ?>"><a href="<?= d2s_escape(d2s_url('/admin')) ?>"><?= d2s_escape(d2s_tr('support.admin.console')) ?></a><a href="<?= d2s_escape(d2s_url('/admin/tickets')) ?>" aria-current="page"><?= d2s_escape(d2s_tr('support.admin.title')) ?></a></nav>
    <h1><?= d2s_escape(d2s_tr('support.admin.title')) ?></h1>
    <p class="portal-intro"><?= d2s_escape(d2s_tr('support.admin.intro')) ?></p>
    <p id="admin-ticket-message" role="status" aria-live="polite"></p>
    <section class="portal-card">
        <div class="support-title-row">
            <label for="admin-ticket-status"><?= d2s_escape(d2s_tr('support.admin.status_filter')) ?></label>
            <select id="admin-ticket-status">
                <option value=""><?= d2s_escape(d2s_tr('support.admin.all')) ?></option><option value="OPEN"><?= d2s_escape(d2s_tr('support.status.open')) ?></option><option value="IN_PROGRESS"><?= d2s_escape(d2s_tr('support.status.in_progress')) ?></option>
                <option value="RESOLVED"><?= d2s_escape(d2s_tr('support.status.resolved')) ?></option><option value="CLOSED"><?= d2s_escape(d2s_tr('support.status.closed')) ?></option>
            </select>
            <button id="admin-ticket-refresh" type="button"><?= d2s_escape(d2s_tr('support.action.refresh')) ?></button>
        </div>
        <div id="admin-ticket-list" aria-live="polite"></div>
        <nav class="support-pager" aria-label="<?= d2s_escape(d2s_tr('support.pager.label')) ?>">
            <button id="admin-ticket-previous" type="button"><?= d2s_escape(d2s_tr('support.pager.previous')) ?></button>
            <span id="admin-ticket-page"></span>
            <button id="admin-ticket-next" type="button"><?= d2s_escape(d2s_tr('support.pager.next')) ?></button>
        </nav>
    </section>
    <section id="admin-ticket-detail" class="portal-card support-detail" hidden aria-labelledby="admin-ticket-detail-title">
        <div class="support-title-row">
            <h2 id="admin-ticket-detail-title"><?= d2s_escape(d2s_tr('support.admin.detail_title')) ?></h2>
            <button id="admin-ticket-detail-close" type="button"><?= d2s_escape(d2s_tr('support.action.collapse')) ?></button>
        </div>
        <p id="admin-ticket-summary"></p>
        <div id="admin-ticket-messages" class="support-messages"></div>
        <?php if (in_array('tickets.reply',$permissions,true)): ?>
            <form id="admin-ticket-reply-form">
                <label for="admin-ticket-reply"><?= d2s_escape(d2s_tr('support.admin.reply_label')) ?></label>
                <textarea id="admin-ticket-reply" minlength="1" maxlength="5000" rows="5" required></textarea>
                <label for="admin-ticket-visibility"><?= d2s_escape(d2s_tr('support.admin.visibility')) ?></label>
                <select id="admin-ticket-visibility"><option value="PUBLIC"><?= d2s_escape(d2s_tr('support.admin.public')) ?></option><option value="STAFF"><?= d2s_escape(d2s_tr('support.admin.staff')) ?></option></select>
                <label for="admin-ticket-next-status"><?= d2s_escape(d2s_tr('support.admin.next_status')) ?></label>
                <select id="admin-ticket-next-status">
                    <option value=""><?= d2s_escape(d2s_tr('support.admin.keep_status')) ?></option><option value="IN_PROGRESS"><?= d2s_escape(d2s_tr('support.status.in_progress')) ?></option>
                    <option value="RESOLVED"><?= d2s_escape(d2s_tr('support.status.resolved')) ?></option><option value="CLOSED"><?= d2s_escape(d2s_tr('support.admin.close_status')) ?></option>
                </select>
                <button class="portal-primary" type="submit"><?= d2s_escape(d2s_tr('support.admin.submit')) ?></button>
            </form>
        <?php endif ?>
    </section>
</main>
</body>
</html>
