<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique([...($uiNamespaces ?? []), 'public_pages']));
$legacyDomTranslation = false;
$site=$siteData['site'];
$policyTranslation = is_array($policyTranslation ?? null) ? $policyTranslation : null;
$policyTitle = (string)($policyTranslation['title'] ?? '') ?: d2s_tr('public_pages.terms.title');
$policyVersion = (string)($policyTranslation['version'] ?? $site['terms_version']);
$policyEffectiveDate = (string)($policyTranslation['effective_date'] ?? $site['terms_effective']);
$policyContentLocale = (string)($policyTranslation['locale'] ?? (str_starts_with(d2s_locale_code(),'en') ? 'en' : 'zh-Hans'));
$policyParagraphs = $policyTranslation === null ? [] : (preg_split('/\\R{2,}/u', trim((string)$policyTranslation['body'])) ?: []);
$sections = \D2S\services\PolicyText::sections('TERMS',$policyContentLocale,$site);
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <?php $seoPath=$path; require D2S_ROOT.'/template/partials/seo-head.php'; ?>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d2s_escape($policyTitle) ?> · <?= d2s_escape($site['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="legal-main">
    <p class="portal-eyebrow"><?= d2s_tr('public_pages.legal.eyebrow') ?></p>
    <h1><?= d2s_escape($policyTitle) ?></h1>
    <p class="legal-meta"><?= d2s_tr('public_pages.legal.version') ?> <?= d2s_escape($policyVersion) ?><?php if($site['terms_effective']!==''): ?> · <?= d2s_tr('public_pages.legal.effective_date') ?> <?= d2s_escape($policyEffectiveDate) ?><?php endif ?></p>
    <?php if($policyTranslation!==null&&($policyTranslation['fallback']??false)): ?><p class="portal-help" role="status"><?= d2s_tr('common.content.unavailable',['language'=>$policyTranslation['locale_name']??$policyContentLocale]) ?></p><?php endif ?>
    <article class="portal-card legal-card" lang="<?= d2s_escape($policyContentLocale) ?>" translate="no">
        <?php if($policyTranslation!==null): ?>
            <?php foreach($policyParagraphs as $paragraph):
                $parts=preg_split('/\\R/u',trim($paragraph),2)?:[];
                if(isset($parts[1])&&preg_match('/^[0-9]+\\.\\s*/u',trim($parts[0]))): ?>
                <section>
                    <h2><?= d2s_escape((string)preg_replace('/^[0-9]+\\.\\s*/u','',trim($parts[0]))) ?></h2>
                    <p><?= nl2br(d2s_escape(trim($parts[1]))) ?></p>
                </section>
            <?php else: ?>
                <p><?= nl2br(d2s_escape(trim($paragraph))) ?></p>
            <?php endif; endforeach ?>
        <?php else: ?>
            <?php foreach($sections as $index=>[$heading,$body]): ?>
                <section><h2><?= ($index+1) ?>. <?= d2s_escape($heading) ?></h2><p><?= nl2br(d2s_escape($body)) ?></p></section>
            <?php endforeach ?>
        <?php endif ?>
    </article>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
