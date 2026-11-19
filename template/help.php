<?php
declare(strict_types=1);
$legacyDomTranslation=false;
$contentLocale=$article?(string)$article['content_locale']:d2s_locale();
$contentDefinition=(new \D2S\services\LocaleRegistry())->get($contentLocale);
$title=$article?(string)$article['title']:d2s_tr('common.help.title');
$description=$article?(($article['seo_description']??'')?:($article['summary']??'')):d2s_tr('common.help.description');
$seoPath=$article?'/help/'.(string)$article['slug']:'/help';
$seoLocale=$article&&!($article['fallback_used']??false)?$contentLocale:d2s_locale_code();
$seoLocales=isset($seoLocales)&&is_array($seoLocales)?$seoLocales:[];
$seoNoCanonical=(bool)($article['fallback_used']??false);
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title translate="no" lang="<?= d2s_escape($contentLocale) ?>"><?= d2s_escape($article?($article['seo_title']?:$title):$title) ?> · Desktop2Stereo</title>
    <meta name="description" content="<?= d2s_escape($description) ?>" lang="<?= d2s_escape($contentLocale) ?>">
    <?php require D2S_ROOT.'/template/partials/seo-head.php'; ?>
    <?php if($article&&!($article['fallback_used']??false)): ?>
    <script type="application/ld+json"><?= json_encode(['@context'=>'https://schema.org','@type'=>'TechArticle','headline'=>$title,'description'=>$description,'inLanguage'=>$contentLocale,'dateModified'=>gmdate(DATE_ATOM,(int)$article['updated_at']),'mainEntityOfPage'=>$seoCanonical],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
    <?php endif ?>
    <link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/operations.css">
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/help.css')) ?>">
</head>
<body class="help-page">
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="site-main help-main" translate="no">
    <?php if($article&&(($article['fallback_used']??false)||($_GET['translation']??'')==='unavailable')): ?><p class="portal-help" role="status"><?= d2s_escape(d2s_tr('common.content.unavailable', ['language'=>$contentDefinition['native_name']??$contentLocale])) ?></p><?php endif ?>
    <?php if($article): ?>
        <a class="help-back" href="<?= d2s_escape(d2s_url('/help')) ?>">← <?= d2s_escape(d2s_tr('common.help.title')) ?></a>
        <article class="portal-card help-article">
            <p class="portal-eyebrow" translate="no" lang="<?= d2s_escape((string)($article['category_locale']??$contentLocale)) ?>"><?= d2s_escape($article['category']) ?></p>
            <h1 translate="no" lang="<?= d2s_escape($contentLocale) ?>" dir="<?= d2s_escape($contentDefinition['direction']??'ltr') ?>"><?= d2s_escape($title) ?></h1>
            <p class="portal-help"><?= d2s_escape(d2s_tr('common.help.updated')) ?> <?= d2s_escape(gmdate('Y-m-d',(int)$article['updated_at'])) ?></p>
            <div translate="no" lang="<?= d2s_escape($contentLocale) ?>" dir="<?= d2s_escape($contentDefinition['direction']??'ltr') ?>" class="operation-article<?= ($article['body_format']??'plain')==='html'?' rich-text':'' ?>"><?= \D2S\services\ArticleContent::render((string)$article['body'],(string)($article['body_format']??'plain')) ?></div>
        </article>
    <?php else: ?>
        <header class="help-heading">
            <h1><?= d2s_escape(d2s_tr('common.help.title')) ?></h1>
            <p><?= d2s_escape(d2s_tr('common.help.intro')) ?></p>
        </header>
        <?php if($articles===[]): ?>
        <section class="portal-card help-empty">
            <p><?= d2s_escape(d2s_tr('common.help.empty')) ?></p>
        </section>
        <?php else: ?>
        <div class="help-articles">
            <?php foreach($articles as$item): ?>
            <article class="portal-card help-card">
                <p class="portal-eyebrow" translate="no" lang="<?= d2s_escape((string)($item['category_locale']??$item['content_locale'])) ?>"><?= d2s_escape($item['category']) ?></p>
                <h2><a translate="no" lang="<?= d2s_escape((string)$item['content_locale']) ?>" href="<?= d2s_escape(d2s_url('/help/'.(string)$item['slug'])) ?>"><?= d2s_escape((string)$item['title']) ?></a></h2>
                <?php if((string)$item['summary']!==''): ?>
                    <p translate="no" lang="<?= d2s_escape((string)$item['content_locale']) ?>"><?= d2s_escape((string)$item['summary']) ?></p>
                <?php endif ?>
            </article>
            <?php endforeach ?>
        </div>
        <?php endif ?>
    <?php endif ?>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
