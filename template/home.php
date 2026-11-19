<?php
declare(strict_types=1);

$legacyDomTranslation = false;

$site = $siteData['site'];
$tagline = (string)($site['tagline'] ?? '');
$intro = (string)($site['intro'] ?? '');
$taglineLocale = (string)($site['translation_locale'] ?? d2s_locale_code());
$repo = trim((string)($site['github_repo'] ?? ''));
if ($repo === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo)) {
    $repo = 'laiyangli001/desktop2stereo-vulkan';
}
$repoUrl = 'https://github.com/' . $repo;
$downloadUrl = d2s_url('/products');
$guideUrl = $repoUrl . '#readme';
$requirementsUrl = $repoUrl . '/blob/main/requirements.txt';
$bilibiliGuideUrl = 'https://space.bilibili.com/156928642/lists';
$homeAnnouncements = is_array($siteData['home_announcements'] ?? null) ? $siteData['home_announcements'] : [];
$homeAnnouncementRegistry = new D2S\services\LocaleRegistry();
$homeAnnouncementDate = new IntlDateFormatter(d2s_locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');

$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'home']));
$copy = [
        'eyebrow' => d2s_tr('home.copy.eyebrow'),
        'hero' => d2s_tr('home.copy.hero'),
        'signals' => [
        d2s_tr('home.copy.signals.item_01'),
        d2s_tr('home.copy.signals.item_02'),
        d2s_tr('home.copy.signals.item_03'),
        d2s_tr('home.copy.signals.item_04'),
    ],
        'modes' => [
        d2s_tr('home.copy.modes.item_01'),
        d2s_tr('home.copy.modes.item_02'),
        d2s_tr('home.copy.modes.item_03'),
        d2s_tr('home.copy.modes.item_04'),
        d2s_tr('home.copy.modes.item_05'),
        d2s_tr('home.copy.modes.item_06'),
    ],
        'intro' => d2s_tr('home.copy.intro'),
        'compatibility' => d2s_tr('home.copy.compatibility'),
        'compatibilityTitle' => d2s_tr('home.copy.compatibility_title'),
        'compatibilityCopy' => d2s_tr('home.copy.compatibility_copy'),
        'hardware' => d2s_tr('home.copy.hardware'),
        'systems' => d2s_tr('home.copy.systems'),
        'hardwareItems' => [
        d2s_tr('home.copy.hardware_items.item_01'),
        d2s_tr('home.copy.hardware_items.item_02'),
        d2s_tr('home.copy.hardware_items.item_03'),
        d2s_tr('home.copy.hardware_items.item_04'),
        d2s_tr('home.copy.hardware_items.item_05'),
    ],
        'systemItems' => [
        d2s_tr('home.copy.system_items.item_01'),
        d2s_tr('home.copy.system_items.item_02'),
        d2s_tr('home.copy.system_items.item_03'),
    ],
        'features' => d2s_tr('home.copy.features'),
        'featuresTitle' => d2s_tr('home.copy.features_title'),
        'featuresCopy' => d2s_tr('home.copy.features_copy'),
        'featureItems' => [
        [
        d2s_tr('home.copy.feature_items.item_01.item_01'),
        d2s_tr('home.copy.feature_items.item_01.item_02'),
    ],
        [
        d2s_tr('home.copy.feature_items.item_02.item_01'),
        d2s_tr('home.copy.feature_items.item_02.item_02'),
    ],
        [
        d2s_tr('home.copy.feature_items.item_03.item_01'),
        d2s_tr('home.copy.feature_items.item_03.item_02'),
    ],
        [
        d2s_tr('home.copy.feature_items.item_04.item_01'),
        d2s_tr('home.copy.feature_items.item_04.item_02'),
    ],
        [
        d2s_tr('home.copy.feature_items.item_05.item_01'),
        d2s_tr('home.copy.feature_items.item_05.item_02'),
    ],
        [
        d2s_tr('home.copy.feature_items.item_06.item_01'),
        d2s_tr('home.copy.feature_items.item_06.item_02'),
    ],
        [
        d2s_tr('home.copy.feature_items.item_07.item_01'),
        d2s_tr('home.copy.feature_items.item_07.item_02'),
    ],
    ],
        'parameters' => d2s_tr('home.copy.parameters'),
        'parameterItems' => [
        [
        d2s_tr('home.copy.parameter_items.item_01.item_01'),
        d2s_tr('home.copy.parameter_items.item_01.item_02'),
    ],
        [
        d2s_tr('home.copy.parameter_items.item_02.item_01'),
        d2s_tr('home.copy.parameter_items.item_02.item_02'),
    ],
        [
        d2s_tr('home.copy.parameter_items.item_03.item_01'),
        d2s_tr('home.copy.parameter_items.item_03.item_02'),
    ],
        [
        d2s_tr('home.copy.parameter_items.item_04.item_01'),
        d2s_tr('home.copy.parameter_items.item_04.item_02'),
    ],
        [
        d2s_tr('home.copy.parameter_items.item_05.item_01'),
        d2s_tr('home.copy.parameter_items.item_05.item_02'),
    ],
        [
        d2s_tr('home.copy.parameter_items.item_06.item_01'),
        d2s_tr('home.copy.parameter_items.item_06.item_02'),
    ],
    ],
        'requirements' => d2s_tr('home.copy.requirements'),
        'guideTitle' => d2s_tr('home.copy.guide_title'),
        'guideCopy' => d2s_tr('home.copy.guide_copy'),
        'webGuide' => d2s_tr('home.copy.web_guide'),
        'video' => d2s_tr('home.copy.video'),
        'workflow' => d2s_tr('home.copy.workflow'),
        'workflowTitle' => d2s_tr('home.copy.workflow_title'),
        'steps' => [
        [
        d2s_tr('home.copy.steps.item_01.item_01'),
        d2s_tr('home.copy.steps.item_01.item_02'),
    ],
        [
        d2s_tr('home.copy.steps.item_02.item_01'),
        d2s_tr('home.copy.steps.item_02.item_02'),
    ],
        [
        d2s_tr('home.copy.steps.item_03.item_01'),
        d2s_tr('home.copy.steps.item_03.item_02'),
    ],
        [
        d2s_tr('home.copy.steps.item_04.item_01'),
        d2s_tr('home.copy.steps.item_04.item_02'),
    ],
    ],
        'platforms' => d2s_tr('home.copy.platforms'),
        'platformsTitle' => d2s_tr('home.copy.platforms_title'),
        'platformsCopy' => d2s_tr('home.copy.platforms_copy'),
        'platformItems' => [
        [
        d2s_tr('home.copy.platform_items.item_01.item_01'),
        d2s_tr('home.copy.platform_items.item_01.item_02'),
    ],
        [
        d2s_tr('home.copy.platform_items.item_02.item_01'),
        d2s_tr('home.copy.platform_items.item_02.item_02'),
    ],
        [
        d2s_tr('home.copy.platform_items.item_03.item_01'),
        d2s_tr('home.copy.platform_items.item_03.item_02'),
    ],
        [
        d2s_tr('home.copy.platform_items.item_04.item_01'),
        d2s_tr('home.copy.platform_items.item_04.item_02'),
    ],
    ],
        'openGithub' => d2s_tr('home.copy.open_github'),
        'support' => d2s_tr('home.copy.support'),
        'supportTitle' => d2s_tr('home.copy.support_title'),
        'supportCopy' => d2s_tr('home.copy.support_copy'),
        'supportNote' => d2s_tr('home.copy.support_note'),
        'enlarge' => d2s_tr('home.copy.enlarge'),
        'visualAlt' => d2s_tr('home.copy.visual_alt'),
        'supportAlt' => d2s_tr('home.copy.support_alt'),
    ];
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape($site['site_name']) ?> · <?= d2s_escape($tagline) ?></title>
    <meta name="description" content="<?= d2s_escape($intro) ?>">
    <?php $seoPath='/'; $seoLocale=d2s_locale_code(); $seoLocales=array_column((new \D2S\services\LocaleRegistry())->enabled(true),'code'); require D2S_ROOT.'/template/partials/seo-head.php'; ?>
    <script type="application/ld+json"><?= json_encode(['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$site['site_name'],'description'=>$intro,'url'=>$seoCanonical],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body class="home-page">
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="home-main">
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero-copy">
            <div class="home-eyebrow-row">
                <p class="portal-eyebrow"><?= d2s_escape($copy['eyebrow']) ?></p>
                <?php if (is_array($release)): ?>
                    <span class="release-version" aria-label="<?= d2s_tr('home.ui.current_version') ?>">v<?= d2s_escape($release['version']) ?></span>
                <?php endif ?>
            </div>
            <h1 id="home-title">
                <span class="home-product-name"><?= d2s_escape($site['site_name']) ?></span>
                <span class="home-tagline" lang="<?= d2s_escape($taglineLocale) ?>"><?= d2s_escape($tagline) ?></span>
            </h1>
            <p class="home-hero-lead"><?= d2s_escape($copy['hero']) ?></p>
            <p class="home-intro" lang="<?= d2s_escape($taglineLocale) ?>"><?= d2s_escape($intro) ?></p>
            <div class="hero-actions home-actions">
                <a class="site-primary" href="<?= d2s_escape($downloadUrl) ?>"><?= d2s_tr('home.ui.download') ?></a>
                <a class="site-secondary" href="<?= d2s_escape($guideUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_tr('home.ui.read_install_guide') ?></a>
                <a class="site-tertiary" href="<?= ($_COOKIE['D2S_USER_SESSION'] ?? '') ? '/profile' : '/register' ?>"><?= ($_COOKIE['D2S_USER_SESSION'] ?? '') ? d2s_tr('home.ui.account_center') : d2s_tr('home.ui.create_account') ?></a>
            </div>
            <ul class="home-signals" aria-label="<?= d2s_tr('home.ui.product_highlights') ?>">
                <?php foreach ($copy['signals'] as $signal): ?><li><?= d2s_escape($signal) ?></li><?php endforeach ?>
            </ul>
        </div>
        <figure class="home-hero-visual">
            <img src="/assets/img/home-stereo.jpg" alt="<?= d2s_escape($copy['visualAlt']) ?>" width="1280" height="720">
            <figcaption><?= d2s_tr('home.ui.workflow_caption') ?></figcaption>
        </figure>
    </section>

    <?php if ($homeAnnouncements !== []): ?>
    <section id="site-notices" class="home-notices" aria-labelledby="site-notices-title">
        <div class="home-section-heading">
            <p class="portal-eyebrow"><?= d2s_tr('common.nav.announcements') ?></p>
            <h2 id="site-notices-title"><?= d2s_tr('common.announcements.title') ?></h2>
        </div>
        <div class="home-notice-grid">
            <?php foreach ($homeAnnouncements as $announcement): ?>
                <?php
                $announcementLocale = (string)($announcement['content_locale'] ?? d2s_locale_code());
                $announcementDirection = $homeAnnouncementRegistry->get($announcementLocale)['direction'] ?? 'ltr';
                $announcementTime = (int)($announcement['starts_at'] ?? $announcement['created_at'] ?? 0);
                ?>
                <article class="home-notice-card" lang="<?= d2s_escape($announcementLocale) ?>" dir="<?= d2s_escape((string)$announcementDirection) ?>" translate="no">
                    <time datetime="<?= gmdate('c', $announcementTime) ?>" lang="<?= d2s_escape(d2s_locale_code()) ?>"><?= d2s_escape($homeAnnouncementDate->format($announcementTime) ?: '') ?></time>
                    <h3><?= d2s_escape((string)$announcement['title']) ?></h3>
                    <p><?= nl2br(d2s_escape((string)$announcement['body'])) ?></p>
                </article>
            <?php endforeach ?>
        </div>
    </section>
    <?php endif ?>

    <section class="home-mode-strip" aria-label="<?= d2s_tr('home.ui.hardware_and_output_capabilities') ?>">
        <p><?= d2s_escape($copy['intro']) ?></p>
        <div><?php foreach ($copy['modes'] as $mode): ?><span><?= d2s_escape($mode) ?></span><?php endforeach ?></div>
    </section>

    <section id="compatibility" class="home-section home-section-tinted">
        <div class="home-section-heading">
            <p class="portal-eyebrow"><?= d2s_escape($copy['compatibility']) ?></p>
            <h2><?= d2s_escape($copy['compatibilityTitle']) ?></h2>
            <p><?= d2s_escape($copy['compatibilityCopy']) ?></p>
        </div>
        <div class="home-two-column">
            <article class="home-info-card">
                <span class="home-card-mark" aria-hidden="true">HW</span>
                <h3><?= d2s_escape($copy['hardware']) ?></h3>
                <ul class="home-check-list">
                    <?php foreach ($copy['hardwareItems'] as $item): ?><li><?= d2s_escape($item) ?></li><?php endforeach ?>
                </ul>
            </article>
            <article class="home-info-card">
                <span class="home-card-mark" aria-hidden="true">OS</span>
                <h3><?= d2s_escape($copy['systems']) ?></h3>
                <ul class="home-check-list">
                    <?php foreach ($copy['systemItems'] as $item): ?><li><?= d2s_escape($item) ?></li><?php endforeach ?>
                </ul>
            </article>
        </div>
    </section>

    <section id="capabilities" class="home-section">
        <div class="home-section-heading">
            <p class="portal-eyebrow"><?= d2s_escape($copy['features']) ?></p>
            <h2><?= d2s_escape($copy['featuresTitle']) ?></h2>
            <p><?= d2s_escape($copy['featuresCopy']) ?></p>
        </div>
        <div class="home-feature-grid">
            <?php foreach ($copy['featureItems'] as $index => $feature): ?>
                <article class="home-feature-card">
                    <span aria-hidden="true"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <h3><?= d2s_escape($feature[0]) ?></h3>
                    <p><?= d2s_escape($feature[1]) ?></p>
                </article>
            <?php endforeach ?>
        </div>
        <div class="home-parameter-block">
            <h3><?= d2s_escape($copy['parameters']) ?></h3>
            <div class="home-parameter-grid">
                <?php foreach ($copy['parameterItems'] as $parameter): ?>
                    <article><strong><?= d2s_escape($parameter[0]) ?></strong><code><?= d2s_escape($parameter[1]) ?></code></article>
                <?php endforeach ?>
            </div>
        </div>
    </section>

    <section id="download" class="home-section home-section-tinted">
        <div class="home-section-heading">
            <h2><?= d2s_escape(d2s_tr('home.ui.download')) ?></h2>
        </div>
        <div class="home-card-actions">
            <a class="site-primary" href="<?= d2s_escape($downloadUrl) ?>"><?= d2s_escape(d2s_tr('commerce.page.shop')) ?></a>
            <a class="site-secondary" href="<?= d2s_escape($requirementsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_escape($copy['requirements']) ?></a>
        </div>
    </section>

    <section id="guide" class="home-guide">
        <div class="home-guide-copy">
            <p class="portal-eyebrow"><?= d2s_tr('home.ui.guide_section') ?></p>
            <h2><?= d2s_escape($copy['guideTitle']) ?></h2>
            <p><?= d2s_escape($copy['guideCopy']) ?></p>
            <div class="home-card-actions">
                <a class="site-primary" href="<?= d2s_escape($guideUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_escape($copy['webGuide']) ?></a>
                <a class="site-secondary" href="<?= d2s_escape($bilibiliGuideUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_escape($copy['video']) ?></a>
            </div>
        </div>
        <a class="home-guide-visual" href="<?= d2s_escape($bilibiliGuideUrl) ?>" target="_blank" rel="noopener noreferrer">
            <img src="/assets/img/lc700x_videoguide.png" alt="<?= d2s_escape($copy['video']) ?>" width="800" height="400" loading="lazy">
            <span class="home-play-mark" aria-hidden="true">▶</span>
            <strong><?= d2s_escape($copy['video']) ?></strong>
        </a>
    </section>

    <section id="workflow" class="home-section">
        <div class="home-section-heading">
            <p class="portal-eyebrow"><?= d2s_escape($copy['workflow']) ?></p>
            <h2><?= d2s_escape($copy['workflowTitle']) ?></h2>
        </div>
        <div class="home-step-grid">
            <?php foreach ($copy['steps'] as $index => $step): ?>
                <article><span aria-hidden="true">0<?= $index + 1 ?></span><h3><?= d2s_escape($step[0]) ?></h3><p><?= d2s_escape($step[1]) ?></p></article>
            <?php endforeach ?>
        </div>
    </section>

    <section id="platforms" class="home-section home-section-tinted">
        <div class="home-section-heading">
            <p class="portal-eyebrow"><?= d2s_escape($copy['platforms']) ?></p>
            <h2><?= d2s_escape($copy['platformsTitle']) ?></h2>
            <p><?= d2s_escape($copy['platformsCopy']) ?></p>
        </div>
        <div class="home-platform-grid">
            <?php foreach ($copy['platformItems'] as $item): ?>
                <article><h3><?= d2s_escape($item[0]) ?></h3><p><?= d2s_escape($item[1]) ?></p></article>
            <?php endforeach ?>
        </div>
        <a class="site-secondary home-inline-link" href="<?= d2s_escape($repoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_escape($copy['openGithub']) ?></a>
    </section>

    <section class="home-community-grid">
        <article id="support" class="home-community-card home-support-card">
            <div>
                <p class="portal-eyebrow"><?= d2s_escape($copy['support']) ?></p>
                <h2><?= d2s_escape($copy['supportTitle']) ?></h2>
                <p><?= d2s_escape($copy['supportCopy']) ?></p>
                <strong><?= d2s_escape($copy['supportNote']) ?></strong>
                <a class="site-secondary" href="/assets/img/d2s-support.png" target="_blank" rel="noopener noreferrer"><?= d2s_escape($copy['enlarge']) ?></a>
            </div>
            <a class="home-qr" href="/assets/img/d2s-support.png" target="_blank" rel="noopener noreferrer"><img src="/assets/img/d2s-support.png" alt="<?= d2s_escape($copy['supportAlt']) ?>" width="800" height="800" loading="lazy"></a>
        </article>
    </section>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
