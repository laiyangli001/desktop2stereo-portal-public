<?php
declare(strict_types=1);

$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'content_admin','operations']));
$legacyDomTranslation = false;
$contentTr = static fn(string $key): string => d2s_tr('content_admin.'.$key);
$contentSection = isset($contentSection) && in_array($contentSection, ['site','announcements','policies'], true)
    ? $contentSection : 'site';
$contentMeta = [
    'site' => [
        'eyebrow' => $contentTr('site.eyebrow'),
        'title' => $contentTr('site.information'),
        'description' => $contentTr('site.information_description'),
    ],
    'policies' => [
        'eyebrow' => $contentTr('site.eyebrow'),
        'title' => $contentTr('policies.title'),
        'description' => $contentTr('policies.description'),
    ],
    'announcements' => [
        'eyebrow' => $contentTr('site.eyebrow'),
        'title' => $contentTr('announcements.title'),
        'description' => $contentTr('announcements.description'),
    ],
];
$contentPage = $contentMeta[$contentSection];
if ($contentSection === 'announcements' && ($announcementView ?? 'list') === 'editor') {
    $contentPage['title'] = ($announcementEditId ?? '') === 'new' ? $contentTr('announcements.add') : $contentTr('announcements.edit');
    $contentPage['description'] = $contentTr('announcements.editor_description');
}
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape($contentPage['title']) ?> · Desktop2Stereo <?= d2s_tr('common.nav.admin') ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/content.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/content.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main content-admin admin-content-page" id="content-app" data-section="<?= d2s_escape($contentSection) ?>" data-view="<?= d2s_escape($announcementView ?? 'list') ?>" data-announcement-id="<?= d2s_escape($announcementEditId ?? '') ?>">
    <header class="admin-page-heading">
        <div>
            <p class="portal-eyebrow"><?= d2s_escape($contentPage['eyebrow']) ?></p>
            <h1><?= d2s_escape($contentPage['title']) ?></h1>
            <p><?= d2s_escape($contentPage['description']) ?></p>
        </div>
        <?php if ($contentSection === 'announcements' && ($announcementView ?? 'list') === 'editor'): ?>
            <a class="admin-control-link" href="/admin/content/announcements"><?= $contentTr('announcements.list') ?></a>
        <?php endif ?>
    </header>
    <p id="content-message" role="status" aria-live="polite"></p>

    <?php if ($contentSection === 'site'): ?>
        <section class="portal-card admin-panel">
            <h2><?= $contentTr('site.basic_downloads') ?></h2>
            <form id="site-content-form" class="content-form">
                <div class="content-grid">
                    <label><?= $contentTr('content_language') ?><select id="site-locale" aria-label="<?= d2s_escape($contentTr('site.language')) ?>"></select></label>
                    <input type="hidden" name="translation_lock_version" value="0">
                    <label><?= $contentTr('site.name') ?><input name="site_name" maxlength="80" required></label>
                    <label><?= $contentTr('site.operator') ?><input name="company_name" maxlength="160" required></label>
                    <label><?= $contentTr('site.privacy_email') ?><input name="contact_email" type="email" maxlength="254" required></label>
                    <label><?= $contentTr('site.tagline') ?><input name="tagline" maxlength="160" required></label>
                    <label class="wide"><?= $contentTr('site.intro') ?><textarea name="intro" maxlength="500" rows="4" required></textarea></label>
                    <label><?= $contentTr('site.wechat_url') ?><input name="support_wechat_url" type="url"></label>
                    <label><?= $contentTr('site.privacy_version') ?><input name="privacy_version" maxlength="40" required></label>
                    <label><?= $contentTr('site.privacy_effective') ?><input name="privacy_effective" type="date" min="0001-01-01" max="9999-12-31"></label>
                    <label><?= $contentTr('site.terms_version') ?><input name="terms_version" maxlength="40" required></label>
                    <label><?= $contentTr('site.terms_effective') ?><input name="terms_effective" type="date" min="0001-01-01" max="9999-12-31"></label>
                    <label><?= $contentTr('site.legal_version') ?><input name="legal_version" maxlength="40" required></label>
                    <label><?= $contentTr('site.legal_effective') ?><input name="legal_effective" type="date" min="0001-01-01" max="9999-12-31"></label>
                    <label><?= $contentTr('site.icp_primary') ?><input name="icp_site_number" maxlength="60" required></label>
                    <label><?= $contentTr('site.icp_secondary') ?><input name="icp_top_number" maxlength="60" required></label>
                    <label><?= $contentTr('site.icp_license') ?><input name="icp_license_number" maxlength="60"></label>
                    <label><?= $contentTr('site.public_security_number') ?><input name="public_security_number" maxlength="60"></label>
                    <label><?= $contentTr('site.public_security_url') ?><input name="public_security_url" type="url" placeholder="https://beian.mps.gov.cn/"></label>
                </div>
                <div class="admin-form-actions"><button type="submit" class="portal-primary"><?= $contentTr('site.save') ?></button></div>
            </form>
        </section>
    <?php elseif ($contentSection === 'policies'): ?>
        <section class="portal-card admin-panel" id="policy-editor"
            data-status-version="<?= d2s_escape($contentTr('policy.version')) ?>"
            data-status-effective="<?= d2s_escape($contentTr('policy.effective')) ?>"
            data-status-source="<?= d2s_escape($contentTr('policy.source')) ?>"
            data-status-revision="<?= d2s_escape($contentTr('policy.revision')) ?>"
            data-status-published="<?= d2s_escape($contentTr('policy.published')) ?>"
            data-status-missing="<?= d2s_escape($contentTr('policy.missing')) ?>"
            data-status-outdated="<?= d2s_escape($contentTr('policy.outdated')) ?>"
            data-success-draft="<?= d2s_escape($contentTr('policy.saved_draft')) ?>"
            data-success-published="<?= d2s_escape($contentTr('policy.published_success')) ?>"
            data-error="<?= d2s_escape($contentTr('policy.save_error')) ?>"
            data-error-source="<?= d2s_escape($contentTr('policy.source_required')) ?>"
            data-error-conflict="<?= d2s_escape($contentTr('policy.conflict')) ?>"
            data-confirm-discard="<?= d2s_escape($contentTr('policy.confirm_discard')) ?>">
            <div class="account-heading compact">
                <label><?= $contentTr('policy.type') ?>
                    <select id="policy-type">
                        <option value="PRIVACY"><?= $contentTr('policy.privacy') ?></option>
                        <option value="TERMS"><?= $contentTr('policy.terms') ?></option>
                        <option value="LEGAL"><?= $contentTr('policy.legal') ?></option>
                    </select>
                </label>
                <label><?= $contentTr('content_language') ?>
                    <select id="policy-locale" aria-label="<?= d2s_escape($contentTr('policy.language')) ?>"></select>
                </label>
            </div>
            <p id="policy-status" role="status"></p>
            <form id="policy-form" class="content-form">
                <label><?= $contentTr('policy.title') ?><input id="policy-title" maxlength="180" required></label>
                <label><?= $contentTr('policy.body') ?><textarea id="policy-body" rows="24" maxlength="50000" placeholder="<?= d2s_escape($contentTr('policy.body_placeholder')) ?>" required></textarea></label>
            </form>
            <fieldset class="publication-languages"><legend><?= $contentTr('translation.target_language') ?></legend><div id="policy-translation-target"></div><p class="portal-help"><?= $contentTr('publication.targets_help') ?></p></fieldset>
            <div id="policy-publication-progress"></div>
            <div class="admin-form-actions">
                <button id="policy-draft" type="button"><?= $contentTr('policy.save_draft') ?></button>
                <button id="policy-publish" type="button" class="portal-primary"><?= $contentTr('publication.publish') ?></button>
                <button id="policy-delete" type="button" class="danger-button"><?= $contentTr('publication.delete_all') ?></button>
            </div>
        </section>
    <?php elseif (($announcementView ?? 'list') === 'list'): ?>
        <section class="portal-card admin-panel announcement-list-panel">
            <div class="announcement-toolbar">
                <label><?= d2s_tr('operations.edit_user.status') ?><select id="announcement-filter">
                    <option value="all"><?= $contentTr('announcements.all') ?></option>
                    <option value="published"><?= d2s_tr('operations.status.published') ?></option>
                    <option value="draft"><?= d2s_tr('operations.status.draft') ?></option>
                    <option value="review"><?= $contentTr('announcements.review') ?></option>
                </select></label>
                <a id="announcement-add" class="admin-control-link portal-primary" href="/admin/content/announcements/new"><?= $contentTr('announcements.add') ?></a>
            </div>
            <div id="announcement-list"></div>
        </section>
    <?php else: ?>
        <section class="portal-card admin-panel announcement-compose-panel">
            <form id="announcement-form">
                <div class="announcement-toolbar">
                    <label><?= $contentTr('content_language') ?><select id="announcement-locale" aria-label="<?= d2s_escape($contentTr('announcements.language')) ?>"></select></label>
                </div>
                <div id="announcement-editor" class="content-list"></div>
                <fieldset class="publication-languages"><legend><?= $contentTr('translation.target_language') ?></legend>
                    <div id="announcement-publication-targets"></div><p class="portal-help"><?= $contentTr('publication.targets_help') ?></p>
                </fieldset>
                <div class="admin-form-actions">
                    <button id="announcement-draft" type="button"><?= $contentTr('policy.save_draft') ?></button>
                    <button id="announcement-save" type="button" class="portal-primary"><?= $contentTr('publication.publish') ?></button>
                </div>
            </form>
        </section>
    <?php endif ?>
</main>
</body>
</html>
