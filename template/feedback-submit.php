<?php
declare(strict_types=1);

$legacyDomTranslation = false;
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'feedback']));
$feedbackChatUrl = trim((string)($siteData['site']['support_wechat_url'] ?? ''));
$feedbackCategories = [
    ['BUG', 'category.bug.title', 'category.bug.description'],
    ['PURCHASE', 'category.purchase.title', 'category.purchase.description'],
    ['LICENSE', 'category.license.title', 'category.license.description'],
    ['DOWNLOAD', 'category.download.title', 'category.download.description'],
    ['ACCOUNT', 'category.account.title', 'category.account.description'],
    ['SUGGESTION', 'category.suggestion.title', 'category.suggestion.description'],
    ['OTHER', 'category.other.title', 'category.other.description'],
];
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_tr('feedback.form.submit') ?> · <?= d2s_escape($siteData['site']['site_name']) ?></title>
    <meta name="description" content="<?= d2s_escape(d2s_tr('feedback.meta.description')) ?>">
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/support.css">
    <link rel="stylesheet" href="/assets/css/feedback.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <script src="/assets/js/feedback.js" defer></script>
</head>
<body class="feedback-page">
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="feedback-main">
    <?php require D2S_ROOT.'/template/partials/user-nav.php'; ?>
    <div class="user-workspace">
    <section class="feedback-submit-intro" aria-labelledby="feedback-title">
        <a href="/feedback">← <?= d2s_tr('feedback.step.back') ?></a>
        <p class="portal-eyebrow"><?= d2s_tr('feedback.hero.eyebrow') ?></p>
        <h1 id="feedback-title"><?= d2s_tr('feedback.form.submit') ?></h1>
        <p><?= d2s_tr('feedback.form.after_submission') ?></p>
    </section>

    <section class="feedback-submit-layout" aria-labelledby="feedback-form-title">
        <form
            id="feedback-form"
            class="feedback-form-card"
            data-endpoint="/api/v2/support/tickets"
            data-msg-submitting="<?= d2s_escape(d2s_tr('feedback.form.submitting')) ?>"
            data-msg-files="<?= d2s_escape(d2s_tr('feedback.form.files.error')) ?>"
            data-msg-too-long="<?= d2s_escape(d2s_tr('feedback.form.too_long')) ?>"
            data-msg-created="<?= d2s_escape(d2s_tr('feedback.form.created')) ?>"
            data-msg-partial="<?= d2s_escape(d2s_tr('feedback.form.partial')) ?>"
            data-msg-submit-failed="<?= d2s_escape(d2s_tr('feedback.form.submit_error')) ?>"
            data-msg-order-not-found="<?= d2s_escape(d2s_tr('feedback.form.order_missing')) ?>"
            data-msg-auth-required="<?= d2s_escape(d2s_tr('feedback.form.auth_required')) ?>"
            data-label-version="<?= d2s_escape(d2s_tr('feedback.field.version')) ?>"
            data-label-environment="<?= d2s_escape(d2s_tr('feedback.field.environment')) ?>"
            data-label-severity="<?= d2s_escape(d2s_tr('feedback.field.severity')) ?>"
            data-label-description="<?= d2s_escape(d2s_tr('feedback.field.description')) ?>"
        >
            <div class="feedback-form-heading">
                <div>
                    <p class="portal-eyebrow"><?= d2s_tr('feedback.form.eyebrow') ?></p>
                    <h2 id="feedback-form-title"><?= d2s_tr('feedback.form.title') ?></h2>
                </div>
                <a href="/tickets"><?= d2s_tr('feedback.form.history') ?></a>
            </div>

            <fieldset class="feedback-type-fieldset">
                <legend><?= d2s_tr('feedback.form.category') ?></legend>
                <div class="feedback-type-grid">
                    <?php foreach ($feedbackCategories as $index => $category): ?>
                        <label class="feedback-type-card">
                            <input type="radio" name="category" value="<?= d2s_escape($category[0]) ?>"<?= $index === 0 ? ' checked' : '' ?> required>
                            <span class="feedback-type-mark" aria-hidden="true"><?= d2s_escape($category[0]) ?></span>
                            <strong><?= d2s_escape(d2s_tr('feedback.' . $category[1])) ?></strong>
                            <small><?= d2s_escape(d2s_tr('feedback.' . $category[2])) ?></small>
                        </label>
                    <?php endforeach ?>
                </div>
            </fieldset>

            <div class="feedback-field-grid">
                <label class="feedback-field feedback-field-wide">
                    <span><?= d2s_tr('feedback.field.subject') ?></span>
                    <input name="subject" type="text" maxlength="200" required placeholder="<?= d2s_tr('feedback.field.subject.placeholder') ?>" autocomplete="off">
                </label>
                <label class="feedback-field">
                    <span><?= d2s_tr('feedback.field.order') ?> <small><?= d2s_tr('feedback.field.optional') ?></small></span>
                    <input name="order_no" type="text" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" placeholder="0000000000000000" autocomplete="off">
                    <small><?= d2s_tr('feedback.field.order.help') ?></small>
                </label>
                <label class="feedback-field">
                    <span><?= d2s_tr('feedback.field.version') ?> <small><?= d2s_tr('feedback.field.optional') ?></small></span>
                    <input name="software_version" type="text" maxlength="80" placeholder="<?= d2s_tr('feedback.field.version.placeholder') ?>" autocomplete="off">
                </label>
                <label class="feedback-field">
                    <span><?= d2s_tr('feedback.field.environment') ?> <small><?= d2s_tr('feedback.field.optional') ?></small></span>
                    <input name="environment" type="text" maxlength="120" placeholder="<?= d2s_tr('feedback.field.environment.placeholder') ?>" autocomplete="off">
                </label>
                <label class="feedback-field">
                    <span><?= d2s_tr('feedback.field.severity') ?></span>
                    <select name="severity">
                        <option value="low"><?= d2s_tr('feedback.severity.low') ?></option>
                        <option value="medium" selected><?= d2s_tr('feedback.severity.medium') ?></option>
                        <option value="high"><?= d2s_tr('feedback.severity.high') ?></option>
                    </select>
                </label>
                <label class="feedback-field feedback-field-wide">
                    <span><?= d2s_tr('feedback.field.description') ?></span>
                    <textarea name="body" minlength="10" maxlength="4400" rows="9" required placeholder="<?= d2s_tr('feedback.field.description.placeholder') ?>"></textarea>
                    <small><?= d2s_tr('feedback.field.sensitive') ?></small>
                </label>
            </div>

            <label class="feedback-upload">
                <span><?= d2s_tr('feedback.attachment.title') ?> <small><?= d2s_tr('feedback.attachment.optional') ?></small></span>
                <input id="feedback-files" name="files" type="file" accept=".pdf,.jpg,.jpeg,.png,.zip,.txt" multiple>
                <strong><?= d2s_tr('feedback.attachment.add') ?></strong>
                <small><?= d2s_tr('feedback.attachment.help') ?></small>
            </label>

            <label class="feedback-consent">
                <input name="agreement" type="checkbox" required>
                <span><?= d2s_tr('feedback.agreement.prefix') ?> <a href="/terms" target="_blank" rel="noopener noreferrer"><?= d2s_tr('feedback.agreement.terms') ?></a> <?= d2s_tr('feedback.agreement.join') ?> <a href="/privacy-policy" target="_blank" rel="noopener noreferrer"><?= d2s_tr('feedback.agreement.privacy') ?></a><?= d2s_tr('feedback.agreement.end') ?></span>
            </label>
            <label class="feedback-honeypot" aria-hidden="true">Website <input name="website" type="text" tabindex="-1" autocomplete="off"></label>

            <p id="feedback-message" role="status" aria-live="polite"></p>
            <button id="feedback-submit" class="portal-primary feedback-submit" type="submit"><?= d2s_tr('feedback.form.submit') ?></button>
        </form>

        <aside class="feedback-side">
            <section class="feedback-side-card">
                <h2><?= d2s_tr('feedback.preparation.title') ?></h2>
                <ul>
                    <li><?= d2s_tr('feedback.sidebar.bug') ?></li>
                    <li><?= d2s_tr('feedback.sidebar.order') ?></li>
                    <li><?= d2s_tr('feedback.sidebar.license') ?></li>
                    <li><?= d2s_tr('feedback.sidebar.sensitive') ?></li>
                </ul>
            </section>
            <section class="feedback-side-card feedback-response-card">
                <h2><?= d2s_tr('feedback.response.title') ?></h2>
                <dl>
                    <div><dt><?= d2s_tr('feedback.response.first') ?></dt><dd><?= d2s_tr('feedback.response.first.value') ?></dd></div>
                    <div><dt><?= d2s_tr('feedback.response.critical') ?></dt><dd><?= d2s_tr('feedback.response.critical.value') ?></dd></div>
                    <div><dt><?= d2s_tr('feedback.response.retention') ?></dt><dd><?= d2s_tr('feedback.response.retention.value') ?></dd></div>
                </dl>
            </section>
            <section class="feedback-side-card feedback-privacy-card">
                <h2><?= d2s_tr('feedback.privacy.title') ?></h2>
                <p><?= d2s_tr('feedback.privacy.description') ?> <a href="/privacy-policy"><?= d2s_tr('feedback.agreement.privacy') ?></a><?= d2s_tr('feedback.privacy.end') ?></p>
                <?php if ($feedbackChatUrl !== ''): ?>
                    <a class="site-secondary feedback-chat-link" href="<?= d2s_escape($feedbackChatUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_tr('feedback.privacy.wechat') ?></a>
                <?php endif ?>
            </section>
        </aside>
    </section>

    <section id="feedback-success" class="feedback-success" aria-live="polite" hidden>
        <p class="portal-eyebrow"><?= d2s_tr('feedback.success.eyebrow') ?></p>
        <h2><?= d2s_tr('feedback.success.title') ?></h2>
        <p><?= d2s_tr('feedback.success.reference') ?>：<code id="feedback-reference"></code></p>
        <p><?= d2s_tr('feedback.success.description') ?></p>
        <div class="feedback-success-actions">
            <a class="site-primary" href="/tickets"><?= d2s_tr('feedback.success.view_tickets') ?></a>
            <button id="feedback-reset" class="site-secondary" type="button"><?= d2s_tr('feedback.success.again') ?></button>
        </div>
    </section>
    </div>

</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
