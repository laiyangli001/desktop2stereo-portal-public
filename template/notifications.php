<?php
declare(strict_types=1);
$notificationAdmin = $realm === 'admin';
$uiNamespaces = [...($uiNamespaces ?? []), 'notifications'];
$legacyDomTranslation = false;
$notificationText = static fn(string $key, array $params = []): string => d2s_escape(d2s_tr('notifications.' . $key, $params));
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $notificationText($notificationAdmin ? 'title.admin' : 'title.user') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/notifications.css">
    <?php if(!$notificationAdmin): ?><link rel="stylesheet" href="/assets/css/operations.css"><?php endif ?>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/notifications.js')) ?>" type="module"></script>
</head>
<body>
<?php if($notificationAdmin): ?><?php require D2S_ROOT.'/template/partials/admin-header.php'; ?><?php else: ?><?php require D2S_ROOT.'/template/partials/site-header.php'; ?><?php endif ?>
<main class="portal-main <?= $notificationAdmin?'portal-auth portal-settings':'account-main' ?>" id="notifications" data-admin="<?= $notificationAdmin?'true':'false' ?>">
    <?php if(!$notificationAdmin): ?><?php require D2S_ROOT.'/template/partials/user-nav.php'; ?><div class="user-workspace account-page-stack"><?php endif ?>
    <h1><?= $notificationText($notificationAdmin ? 'heading.admin' : 'heading.user') ?></h1>
    <?php if($notificationAdmin): ?>
    <p><?= $notificationText('intro') ?></p>
    <p><?= $notificationText('admin.explanation') ?></p>
    <label for="notification-status"><?= $notificationText('admin.filter') ?></label>
    <select id="notification-status"><option value=""><?= $notificationText('filter.all') ?></option><option value="blocked"><?= $notificationText('status.blocked') ?></option><option value="failed"><?= $notificationText('status.failed') ?></option><option value="retry"><?= $notificationText('status.retry') ?></option><option value="pending"><?= $notificationText('status.pending') ?></option><option value="processing"><?= $notificationText('status.processing') ?></option><option value="sent"><?= $notificationText('status.sent') ?></option></select>
    <?php endif ?>
    <p id="notification-message" role="status" aria-live="polite"></p>
    <?php if($notificationAdmin): ?>
    <section id="notification-list" aria-label="<?= $notificationText('list.aria') ?>"></section>
    <?php else: ?>
    <div class="operation-table-wrap portal-card account-records">
      <table class="operation-table" aria-label="<?= $notificationText('title.user') ?>">
        <thead><tr>
          <th scope="col"><?= $notificationText('transactions.order') ?></th>
          <th scope="col"><?= $notificationText('transactions.amount') ?></th>
          <th scope="col"><?= $notificationText('transactions.method') ?></th>
          <th scope="col"><?= $notificationText('transactions.time') ?></th>
        </tr></thead>
        <tbody id="notification-list"></tbody>
      </table>
    </div>
    <?php endif ?>
    <nav<?= $notificationAdmin?'':' class="account-record-pager"' ?> aria-label="<?= $notificationText($notificationAdmin?'pagination.aria':'title.user') ?>"><button id="notification-previous" type="button" disabled><?= $notificationAdmin?$notificationText('action.previous'):$notificationText('transactions.previous') ?></button>
        <span id="notification-page" role="status"></span><button id="notification-next" type="button" disabled><?= $notificationAdmin?$notificationText('action.next'):$notificationText('transactions.next') ?></button>
        <button id="notification-refresh" type="button"><?= $notificationAdmin?$notificationText('action.refresh'):$notificationText('transactions.refresh') ?></button></nav>
    <?php if($notificationAdmin): ?>
    <dialog id="notification-retry" aria-labelledby="notification-retry-title">
        <h2 id="notification-retry-title"><?= $notificationText('retry.title') ?></h2><p id="notification-retry-summary"></p>
        <form id="notification-retry-form">
            <label for="notification-reason"><?= $notificationText('retry.reason') ?></label><input id="notification-reason" maxlength="256" required>
            <button type="submit"><?= $notificationText('retry.submit') ?></button><button id="notification-cancel" type="button"><?= $notificationText('retry.cancel') ?></button>
        </form>
    </dialog>
    <?php endif ?>
    <?php if(!$notificationAdmin): ?></div><?php endif ?>
</main>
</body>
</html>
