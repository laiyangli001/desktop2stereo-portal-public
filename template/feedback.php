<?php
declare(strict_types=1);

$legacyDomTranslation = false;

$feedbackText = static fn(string $key, array $params = []): string => d2s_escape(d2s_tr('feedback.' . $key, $params));
$feedbackView = in_array($_GET['view'] ?? '', ['pending','all','form'], true) ? $_GET['view'] : 'submit';
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
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $feedbackText('title') ?> · <?= d2s_escape($siteData['site']['site_name']) ?></title>
    <meta name="description" content="<?= $feedbackText('meta.description') ?>">
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <?php if (in_array($feedbackView,['pending','all'],true)): ?><link rel="stylesheet" href="/assets/vendor/quill-2.0.3/quill.snow.css"><?php endif ?>
    <link rel="stylesheet" href="/assets/css/support.css">
    <link rel="stylesheet" href="/assets/css/feedback.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <?php if (in_array($feedbackView,['pending','all'],true)): ?>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/support-format.js')) ?>" defer></script>
    <script src="/assets/vendor/quill-2.0.3/quill.js" defer></script>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/support-tickets.js')) ?>" defer></script>
    <?php endif ?>
    <script src="<?= d2s_escape(d2s_asset('/assets/js/feedback.js')) ?>" defer></script>
</head>
<body class="feedback-page">
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="feedback-home-main feedback-standalone">
    <nav class="feedback-ticket-tabs" aria-label="<?= $feedbackText('nav.aria') ?>">
        <a href="/feedback?view=submit#feedback-category-section"<?= in_array($feedbackView,['submit','form'],true) ? ' aria-current="page"' : '' ?>><?= $feedbackText('nav.submit') ?></a>
        <a href="/feedback?view=pending#ticket-app"<?= $feedbackView === 'pending' ? ' aria-current="page"' : '' ?>><?= $feedbackText('nav.pending') ?></a>
        <a href="/feedback?view=all#ticket-app"<?= $feedbackView === 'all' ? ' aria-current="page"' : '' ?>><?= $feedbackText('nav.all') ?></a>
    </nav>
    <?php if (in_array($feedbackView,['pending','all'],true)): ?>
    <div class="support-main" id="ticket-app">
        <?php require D2S_ROOT.'/template/partials/ticket-workspace.php'; ?>
    </div>
    <?php elseif ($feedbackView === 'submit'): ?>
    <ol id="feedback-progress" class="feedback-progress-steps" aria-label="<?= $feedbackText('step.aria') ?>">
        <li aria-current="step"><span>1</span><?= $feedbackText('step.category') ?></li>
        <li><span>2</span><?= $feedbackText('step.details') ?></li>
        <li><span>3</span><?= $feedbackText('step.submit') ?></li>
    </ol>
    <section class="feedback-landing-hero" aria-labelledby="feedback-title">
        <div class="feedback-landing-copy">
            <p class="portal-eyebrow"><?= $feedbackText('hero.eyebrow') ?></p>
            <h1 id="feedback-title"><?= $feedbackText('hero.title') ?></h1>
            <p><?= $feedbackText('hero.description') ?></p>
            <p class="feedback-landing-note"><?= $feedbackText('hero.note') ?></p>
        </div>
        <aside class="feedback-latest-card" aria-labelledby="feedback-preparation-title">
            <div class="feedback-latest-head"><?= $feedbackText('preparation.title') ?></div>
            <h2 id="feedback-preparation-title"><?= $feedbackText('preparation.heading') ?></h2>
            <ul class="feedback-preparation-list">
                <li><?= $feedbackText('preparation.software') ?></li>
                <li><?= $feedbackText('preparation.order') ?></li>
                <li><?= $feedbackText('preparation.private') ?></li>
            </ul>
        </aside>
    </section>
    <section id="feedback-category-section" class="feedback-types-section" aria-labelledby="feedback-types-title">
        <div class="feedback-section-heading">
            <p class="portal-eyebrow"><?= $feedbackText('category.eyebrow') ?></p>
            <h2 id="feedback-types-title"><?= $feedbackText('category.heading') ?></h2>
            <p><?= $feedbackText('category.description') ?></p>
        </div>
        <div class="feedback-type-preview-grid">
            <?php foreach ($feedbackCategories as [$category,$title,$description]): ?>
                <a class="feedback-type-preview" href="/feedback?view=form&amp;type=<?= d2s_escape(strtolower($category)) ?>#feedback-form-section">
                    <span><?= d2s_escape($category) ?></span>
                    <h3><?= $feedbackText($title) ?></h3>
                    <p><?= $feedbackText($description) ?></p>
                    <strong><?= $feedbackText('category.open') ?> →</strong>
                </a>
            <?php endforeach ?>
        </div>
    </section>
    <?php else: ?>
    <ol id="feedback-progress" class="feedback-progress-steps" aria-label="<?= $feedbackText('step.aria') ?>">
        <li><span>1</span><?= $feedbackText('step.category') ?></li>
        <li aria-current="step"><span>2</span><?= $feedbackText('step.details') ?></li>
        <li><span>3</span><?= $feedbackText('step.submit') ?></li>
    </ol>
    <section id="feedback-form-section" class="feedback-form-section" aria-labelledby="feedback-form-title">
        <div class="feedback-form-heading">
            <a class="feedback-back-link" href="/feedback?view=submit#feedback-category-section">← <?= $feedbackText('step.back') ?></a>
        </div>
        <?php require D2S_ROOT.'/template/partials/feedback-form.php'; ?>
    </section>
    <?php endif ?>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
