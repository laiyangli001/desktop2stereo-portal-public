<?php
declare(strict_types=1);
$popup = $siteData['popup_announcement'];
$popupLocale = (string)$popup['content_locale'];
$popupDirection = $headerLocaleRegistry->get($popupLocale)['direction'] ?? 'ltr';
?>
<link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/announcement-popup.css')) ?>">
<dialog id="announcement-popup" aria-labelledby="announcement-popup-title" translate="no"
        data-id="<?= d2s_escape($popup['id']) ?>" data-ends-at="<?= (int)($popup['ends_at'] ?? 0) ?>">
    <form method="dialog">
        <header>
            <h2 id="announcement-popup-title" lang="<?= d2s_escape($popupLocale) ?>" dir="<?= d2s_escape($popupDirection) ?>"><?= d2s_escape($popup['title']) ?></h2>
            <button type="submit" class="announcement-popup-close" aria-label="<?= d2s_escape(d2s_tr('common.announcements.close')) ?>">×</button>
        </header>
        <div class="announcement-popup-body" lang="<?= d2s_escape($popupLocale) ?>" dir="<?= d2s_escape($popupDirection) ?>" tabindex="0"><?= d2s_escape($popup['body']) ?></div>
        <footer><button class="site-primary announcement-popup-confirm" type="submit" autofocus><?= d2s_escape(d2s_tr('common.announcements.dismiss')) ?></button></footer>
    </form>
</dialog>
<script src="<?= d2s_escape(d2s_asset('/assets/js/announcement-popup.js')) ?>" defer></script>
