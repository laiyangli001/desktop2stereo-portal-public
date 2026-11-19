<?php
declare(strict_types=1);

$seoLocaleRegistry = new \D2S\services\LocaleRegistry();
$seoBuilder = new \D2S\services\PublicSeoUrls($seoLocaleRegistry);
$seoPath = isset($seoPath) && is_string($seoPath) ? $seoPath : '/';
$seoLocale = isset($seoLocale) && is_string($seoLocale) ? $seoLocale : d2s_locale_code();
$seoLocales = isset($seoLocales) && is_array($seoLocales)
    ? $seoLocales : array_column($seoLocaleRegistry->enabled(true), 'code');
$seoCanonical = $seoBuilder->url($seoPath, $seoLocale);
$seoAlternates = $seoBuilder->alternates($seoPath, $seoLocales);
$seoIndexEnabled = !isset($platformRuntime) || !is_array($platformRuntime) || ($platformRuntime['seo_index'] ?? true);
?>
<?php if (!$seoIndexEnabled): ?><meta name="robots" content="noindex, nofollow"><?php endif ?>
<?php if ($seoIndexEnabled && !($seoNoCanonical ?? false)): ?>
<link rel="canonical" href="<?= d2s_escape($seoCanonical) ?>">
<?php foreach ($seoAlternates as $seoAlternateLocale => $seoAlternateUrl): ?>
<link rel="alternate" hreflang="<?= d2s_escape($seoAlternateLocale) ?>" href="<?= d2s_escape($seoAlternateUrl) ?>">
<?php endforeach ?>
<?php endif ?>
