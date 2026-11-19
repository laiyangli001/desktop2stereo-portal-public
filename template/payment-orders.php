<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'payments_admin', 'operations']));
$legacyDomTranslation = false;
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('payments_admin.page.title') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/payment-orders.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/payment-orders.js')) ?>" type="module"></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main portal-auth portal-settings" id="payment-orders"
    data-can-query="<?= in_array('orders.reconcile',$permissions,true) ? 'true':'false' ?>"
    data-can-resolve="<?= in_array('orders.resolve',$permissions,true) ? 'true':'false' ?>"
    data-can-close="<?= in_array('orders.close',$permissions,true) ? 'true':'false' ?>"
    data-can-refund="<?= in_array('refunds.manage',$permissions,true) ? 'true':'false' ?>">
    <nav aria-label="<?= d2s_tr('payments_admin.page.admin_navigation') ?>"><a href="<?= d2s_escape(d2s_url('/admin')) ?>"><?= d2s_tr('payments_admin.page.admin_home') ?></a></nav>
    <h1><?= d2s_tr('payments_admin.page.heading') ?></h1>
    <p><?= d2s_tr('payments_admin.page.introduction') ?></p>
    <p id="payment-message" role="status" aria-live="polite"></p>
    <label for="order-filter"><?= d2s_tr('payments_admin.orders.filter_label') ?></label>
    <select id="order-filter"><option value=""><?= d2s_tr('payments_admin.orders.filter_all') ?></option><option value="payment_review"><?= d2s_tr('payments_admin.status.payment_review') ?></option><option value="refund_pending"><?= d2s_tr('payments_admin.status.refund_pending') ?></option></select>
    <section id="payment-order-list" aria-label="<?= d2s_tr('payments_admin.orders.list_label') ?>"></section>
    <nav aria-label="<?= d2s_tr('payments_admin.orders.pagination') ?>">
        <button type="button" id="previous-page" disabled><?= d2s_tr('payments_admin.common.previous_page') ?></button>
        <span id="page-summary"></span>
        <button type="button" id="next-page" disabled><?= d2s_tr('payments_admin.common.next_page') ?></button>
    </nav>
    <dialog id="close-confirmation" aria-labelledby="close-title">
        <form method="dialog">
            <h2 id="close-title"><?= d2s_tr('payments_admin.close.title') ?></h2>
            <p id="close-description"></p>
            <p><?= d2s_tr('payments_admin.close.warning') ?></p>
            <button value="cancel" autofocus><?= d2s_tr('payments_admin.common.back') ?></button>
            <button value="confirm"><?= d2s_tr('payments_admin.close.confirm') ?></button>
        </form>
    </dialog>
    <?php if (in_array('orders.resolve',$permissions,true)): ?>
    <dialog id="review-dialog" aria-labelledby="review-title">
        <h2 id="review-title"><?= d2s_tr('payments_admin.review.title') ?></h2>
        <p id="review-order"></p>
        <p><?= d2s_tr('payments_admin.review.introduction') ?></p>
        <p id="review-message" role="status" aria-live="polite"></p>
        <ul id="review-receipts"></ul>
        <form id="review-form">
            <fieldset id="review-fields">
                <legend><?= d2s_tr('payments_admin.review.action_legend') ?></legend>
                <label for="review-action"><?= d2s_tr('payments_admin.review.action_label') ?></label>
                <select id="review-action"><option value="close_case"><?= d2s_tr('payments_admin.review.close_case') ?></option><option value="retry_fulfillment"><?= d2s_tr('payments_admin.review.retry_fulfillment') ?></option></select>
                <label for="review-payment"><?= d2s_tr('payments_admin.review.payment_label') ?></label><select id="review-payment" disabled></select>
                <label for="review-reason"><?= d2s_tr('payments_admin.review.reason_label') ?></label><textarea id="review-reason" maxlength="256" rows="3" required></textarea>
            </fieldset>
            <button type="submit" id="review-check"><?= d2s_tr('payments_admin.review.check') ?></button>
            <section id="review-confirm" hidden>
                <h3><?= d2s_tr('payments_admin.review.confirm_title') ?></h3><p id="review-summary"></p>
                <button type="button" id="review-back"><?= d2s_tr('payments_admin.common.back_to_edit') ?></button>
                <button type="button" id="review-submit"><?= d2s_tr('payments_admin.review.submit') ?></button>
            </section>
        </form>
        <h3><?= d2s_tr('payments_admin.review.history_title') ?></h3><ul id="review-history"></ul>
        <nav aria-label="<?= d2s_tr('payments_admin.review.history_pagination') ?>"><button type="button" id="review-previous"><?= d2s_tr('payments_admin.common.previous_page') ?></button><span id="review-page"></span><button type="button" id="review-next"><?= d2s_tr('payments_admin.common.next_page') ?></button></nav>
        <button type="button" id="review-refresh"><?= d2s_tr('payments_admin.review.refresh') ?></button>
        <button type="button" id="review-close"><?= d2s_tr('payments_admin.review.close_dialog') ?></button>
    </dialog>
    <?php endif ?>
    <?php if (in_array('refunds.manage',$permissions,true)): ?>
    <dialog id="refund-dialog" aria-labelledby="refund-title">
        <h2 id="refund-title"><?= d2s_tr('payments_admin.refund.default_title') ?></h2>
        <p id="refund-order"></p>
        <p id="refund-channel-help"></p>
        <p id="refund-message" role="status" aria-live="polite"></p>
        <form id="refund-form" hidden>
            <fieldset id="refund-fields">
                <legend><?= d2s_tr('payments_admin.refund.form_legend') ?></legend>
                <label for="refund-payment"><?= d2s_tr('payments_admin.refund.payment_label') ?></label><select id="refund-payment" required></select>
                <label for="refund-amount"><?= d2s_tr('payments_admin.refund.amount_cny') ?></label><input id="refund-amount" type="number" min="0.01" step="0.01" required>
                <label for="refund-reason"><?= d2s_tr('payments_admin.refund.reason_label') ?></label><textarea id="refund-reason" maxlength="256" required rows="3"></textarea>
            </fieldset>
            <p><?= d2s_tr('payments_admin.refund.impact_note') ?></p>
            <button type="submit" id="refund-review"><?= d2s_tr('payments_admin.refund.check') ?></button>
            <section id="refund-confirm" hidden>
                <h3><?= d2s_tr('payments_admin.refund.confirm_title') ?></h3><p id="refund-summary"></p>
                <button type="button" id="refund-back"><?= d2s_tr('payments_admin.common.back_to_edit') ?></button>
                <button type="button" id="refund-submit"><?= d2s_tr('payments_admin.refund.submit') ?></button>
            </section>
        </form>
        <?php if (in_array('refunds.resolve',$permissions,true)): ?>
        <section id="refund-control-panel" hidden aria-labelledby="refund-control-title">
            <h3 id="refund-control-title"><?= d2s_tr('payments_admin.refund_review.title') ?></h3>
            <p id="refund-control-info"></p>
            <p><?= d2s_tr('payments_admin.refund_review.introduction') ?></p>
            <p id="refund-control-message" role="status" aria-live="polite"></p>
            <form id="refund-control-form">
                <fieldset id="refund-control-fields">
                    <legend><?= d2s_tr('payments_admin.refund_review.action_legend') ?></legend>
                    <label for="refund-control-action"><?= d2s_tr('payments_admin.refund_review.action_label') ?></label>
                    <select id="refund-control-action"><option value="pause_retry"><?= d2s_tr('payments_admin.refund_review.pause_retry') ?></option><option value="resume_retry"><?= d2s_tr('payments_admin.refund_review.resume_retry') ?></option><option value="cancel_unsent"><?= d2s_tr('payments_admin.refund_review.cancel_unsent') ?></option></select>
                    <label for="refund-control-reason"><?= d2s_tr('payments_admin.refund_review.reason_label') ?></label><textarea id="refund-control-reason" maxlength="256" required rows="3"></textarea>
                </fieldset>
                <button type="submit" id="refund-control-check"><?= d2s_tr('payments_admin.refund_review.check') ?></button>
                <section id="refund-control-confirm" hidden>
                    <h4><?= d2s_tr('payments_admin.refund_review.confirm_title') ?></h4><p id="refund-control-summary"></p>
                    <button type="button" id="refund-control-back"><?= d2s_tr('payments_admin.common.back_to_edit') ?></button><button type="button" id="refund-control-submit"><?= d2s_tr('payments_admin.refund_review.submit') ?></button>
                </section>
            </form>
            <ul id="refund-control-history"></ul>
            <nav aria-label="<?= d2s_tr('payments_admin.refund_review.pagination') ?>"><button type="button" id="refund-control-previous"><?= d2s_tr('payments_admin.common.previous_page') ?></button><span id="refund-control-page"></span><button type="button" id="refund-control-next"><?= d2s_tr('payments_admin.common.next_page') ?></button></nav>
            <button type="button" id="refund-control-hide"><?= d2s_tr('payments_admin.refund_review.hide') ?></button>
        </section>
        <?php endif ?>
        <section aria-label="<?= d2s_tr('payments_admin.refund.history_label') ?>"><h3><?= d2s_tr('payments_admin.refund.history_title') ?></h3><ul id="refund-history"></ul></section>
        <nav aria-label="<?= d2s_tr('payments_admin.refund.history_pagination') ?>">
            <button type="button" id="refund-previous"><?= d2s_tr('payments_admin.common.previous_page') ?></button><span id="refund-page"></span>
            <button type="button" id="refund-next"><?= d2s_tr('payments_admin.common.next_page') ?></button>
        </nav>
        <button type="button" id="refund-refresh"><?= d2s_tr('payments_admin.refund.refresh') ?></button>
        <button type="button" id="refund-close"><?= d2s_tr('payments_admin.refund.close_dialog') ?></button>
    </dialog>
    <?php endif ?>
</main>
</body>
</html>
