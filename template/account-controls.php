<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'account_controls']));
$legacyDomTranslation = false;
$canRestrict = in_array('accounts.restrict',$permissions,true);
$tr = static fn(string $key): string => d2s_escape(d2s_tr('account_controls.'.$key));
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $tr('page.title') ?> · Desktop2Stereo <?= $tr('page.admin') ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/account-controls.css')) ?>">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/account-controls.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main admin-controls" id="account-controls" data-locale="<?= d2s_escape(d2s_locale_code()) ?>">
    <header class="admin-page-heading">
        <div>
            <h1><?= $tr('page.title') ?></h1>
            <p><?= $tr('page.intro') ?></p>
        </div>
    </header>

    <section class="admin-panel">
        <form id="account-search" class="account-control-search">
            <label class="account-control-field" for="account-query">
                <?= $tr('search.label') ?>
                <input id="account-query" type="search" maxlength="254">
            </label>
            <button class="portal-primary" type="submit"><?= $tr('search.submit') ?></button>
        </form>
        <p id="control-message" class="account-control-message" role="status"></p>
        <ul id="account-results" aria-live="polite"></ul>
    </section>

    <section id="account-detail" class="admin-panel" hidden aria-labelledby="account-detail-title">
        <header class="account-control-detail-heading">
            <h2 id="account-detail-title" tabindex="-1"><?= $tr('detail.title') ?></h2>
            <button id="account-detail-close" type="button"><?= $tr('action.collapse') ?></button>
        </header>
        <div id="account-detail-body" class="account-control-facts"></div>
        <div class="account-control-sections">
            <?php if ($canRestrict): ?>
                <section class="account-control-section" aria-labelledby="restriction-title">
                    <h3 id="restriction-title"><?= $tr('batch.actions') ?></h3>
                    <form id="account-control-form" class="account-control-fields">
                        <div class="account-control-choices">
                            <label><input id="control-login" type="checkbox"> <?= $tr('action.block_login') ?></label>
                            <label><input id="control-purchase" type="checkbox"> <?= $tr('action.block_purchase') ?></label>
                            <label><input id="control-force-logout" type="checkbox"> <?= $tr('action.force_logout') ?></label>
                        </div>
                        <label class="account-control-field"><?= $tr('action.reason') ?><input id="control-reason" maxlength="500" required></label>
                        <label class="account-control-check"><input id="control-confirm" type="checkbox" required> <?= $tr('action.confirm_impact') ?></label>
                        <button class="portal-primary" type="submit"><?= $tr('action.apply_restrictions') ?></button>
                    </form>
                    <p id="restriction-message" class="account-control-message" role="status"></p>
                </section>
            <?php endif ?>
            <section class="account-control-section" aria-labelledby="identity-review-title">
                <h3 id="identity-review-title"><?= $tr('identity.title') ?></h3>
                <div id="identity-summary" class="account-control-facts"></div>
                <?php if ($canRestrict): ?>
                    <p class="portal-help"><?= $tr('identity.help') ?></p>
                    <form id="identity-request-form" class="account-control-fields">
                        <label class="account-control-field"><?= $tr('identity.trigger_reason') ?><input id="identity-request-reason" maxlength="500" required></label>
                        <button class="portal-primary" type="submit"><?= $tr('identity.request') ?></button>
                    </form>
                    <form id="identity-decision-form" class="account-control-fields" hidden>
                        <div class="account-control-pair">
                            <label class="account-control-field"><?= $tr('identity.document_type') ?><select id="identity-document-type">
                                <option value="PASSPORT"><?= $tr('identity.passport') ?></option>
                                <option value="FOREIGN_PERMANENT_RESIDENT_ID"><?= $tr('identity.permanent_resident') ?></option>
                                <option value="OTHER"><?= $tr('identity.other') ?></option>
                            </select></label>
                            <label class="account-control-field"><?= $tr('identity.country_code') ?><input id="identity-country" maxlength="2" pattern="[A-Za-z]{2}" required></label>
                        </div>
                        <label class="account-control-field"><?= $tr('identity.document_number') ?><input id="identity-number" maxlength="128" autocomplete="off" required></label>
                        <label class="account-control-field"><?= $tr('identity.decision') ?><select id="identity-decision">
                            <option value="VERIFIED"><?= $tr('identity.approve') ?></option>
                            <option value="REJECTED"><?= $tr('identity.reject') ?></option>
                        </select></label>
                        <label class="account-control-field"><?= $tr('identity.explanation') ?><input id="identity-decision-reason" maxlength="500" required></label>
                        <button class="portal-primary" type="submit"><?= $tr('identity.submit_decision') ?></button>
                    </form>
                    <p id="identity-message" class="account-control-message" role="status"></p>
                <?php endif ?>
            </section>
        </div>
    </section>

    <?php if ($canRestrict): ?>
        <details class="admin-panel account-control-batch">
            <summary><span><?= $tr('batch.title') ?></span></summary>
            <div class="account-control-batch-body">
                <p class="portal-help"><?= $tr('batch.intro') ?></p>
                <form id="batch-form" class="account-control-fields">
                    <div class="account-control-pair">
                        <label class="account-control-field"><?= $tr('batch.ip_range') ?><input id="batch-ip" placeholder="<?= $tr('batch.ip_placeholder') ?>" maxlength="64"></label>
                        <label class="account-control-field"><?= $tr('batch.email_domain') ?><input id="batch-domain" placeholder="<?= $tr('batch.domain_placeholder') ?>" maxlength="253"></label>
                        <label class="account-control-field"><?= $tr('batch.register_from') ?><input id="batch-from" type="datetime-local"></label>
                        <label class="account-control-field"><?= $tr('batch.register_until') ?><input id="batch-until" type="datetime-local"></label>
                    </div>
                    <fieldset>
                        <legend><?= $tr('batch.actions') ?></legend>
                        <div class="account-control-choices">
                            <label><input id="batch-login" type="checkbox"> <?= $tr('action.block_login') ?></label>
                            <label><input id="batch-purchase" type="checkbox"> <?= $tr('action.block_purchase') ?></label>
                        </div>
                    </fieldset>
                    <label class="account-control-field"><?= $tr('batch.reason') ?><input id="batch-reason" maxlength="500" required></label>
                    <button type="submit"><?= $tr('batch.preview') ?></button>
                </form>
                <div id="batch-preview" hidden>
                    <p id="batch-preview-summary"></p>
                    <button id="batch-apply" class="portal-primary" type="button"><?= $tr('batch.confirm') ?></button>
                </div>
                <p id="batch-message" class="account-control-message" role="status"></p>
            </div>
        </details>
    <?php endif ?>
</main>
</body>
</html>
