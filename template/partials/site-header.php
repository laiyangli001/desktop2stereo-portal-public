<?php
declare(strict_types=1);
$headerName = isset($siteData['site']['site_name']) ? (string)$siteData['site']['site_name'] : 'Desktop2Stereo';
$headerHome = $realm ?? null;
$headerAccount = ($_COOKIE['D2S_USER_SESSION'] ?? '') !== '' ? '/profile' : '/login';
$headerAnnouncements = is_array($siteData['announcements'] ?? null) ? $siteData['announcements'] : [];
$headerAnnouncements = array_values(array_filter($headerAnnouncements, static fn(mixed $item): bool =>
    is_array($item) && trim((string)($item['title'] ?? '')) !== '' && trim((string)($item['body'] ?? '')) !== ''
));
$headerAnnouncementCount = count($headerAnnouncements);
$headerLocaleRegistry = new D2S\services\LocaleRegistry();
$headerAnnouncementDate = new IntlDateFormatter(d2s_locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
$headerPlatform = D2S\services\PlatformSettings::defaults();
$headerModules = D2S\services\PluginManager::defaults();
if (isset($db) && $db instanceof PDO) {
    try { $headerPlatform = (new D2S\services\PlatformSettings($db))->publicConfiguration(); }
    catch (Throwable) { /* Keep public navigation available with safe defaults. */ }
    try { $headerModules = (new D2S\services\PluginManager($db))->publicConfiguration(); }
    catch (Throwable) { /* Optional modules remain at safe defaults. */ }
}
?>
<?php require_once D2S_ROOT.'/template/partials/locale-runtime.php'; ?>
<script src="/assets/js/theme.js" data-default-theme="<?= d2s_escape((string)$headerPlatform['default_theme']) ?>" data-platform-template="<?= d2s_escape((string)$headerPlatform['template']) ?>"></script>
<header class="portal-header site-header">
    <a href="<?= d2s_escape(d2s_url('/')) ?>" class="portal-brand"><?= d2s_escape($headerName) ?></a>
    <nav class="site-header-links" aria-label="<?= d2s_tr('common.nav.primary') ?>">
        <a href="<?= d2s_escape(d2s_url('/')) ?>"<?= ($path??'')==='/'?' aria-current="page"':'' ?>><?= d2s_escape(d2s_tr('common.nav.home')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/products')) ?>"<?= ($path??'')==='/products'?' aria-current="page"':'' ?>><?= d2s_tr('commerce.page.shop') ?></a>
        <a href="<?= d2s_escape(d2s_url('/help')) ?>"<?= str_starts_with((string)($path??''),'/help')?' aria-current="page"':'' ?>><?= d2s_tr('common.nav.help') ?></a>
        <a href="<?= d2s_escape(d2s_url('/tickets')) ?>"<?= ($path ?? '') === '/tickets' ? ' aria-current="page"' : '' ?>><?= d2s_tr('common.nav.my_tickets') ?></a>
        <?php if($headerModules['announcement_menu']): ?><details class="announcement-menu">
            <summary aria-label="<?= d2s_escape(d2s_tr('common.announcements.open', ['count'=>$headerAnnouncementCount])) ?>">
                <span><?= d2s_tr('common.nav.announcements') ?></span>
                <?php if ($headerAnnouncementCount > 0): ?><span class="announcement-count" aria-hidden="true"><?= $headerAnnouncementCount ?></span><?php endif ?>
            </summary>
            <section class="announcement-popover" aria-labelledby="site-announcements-title">
                <h2 id="site-announcements-title"><?= d2s_tr('common.announcements.title') ?></h2>
                <?php if ($headerAnnouncements === []): ?>
                    <p class="announcement-empty"><?= d2s_tr('common.announcements.empty') ?></p>
                <?php else: ?>
                    <div class="announcement-popover-list">
                        <?php foreach ($headerAnnouncements as $announcement): ?>
                            <?php
                            $announcementLocale = (string)($announcement['content_locale'] ?? d2s_locale_code());
                            $announcementDirection = $headerLocaleRegistry->get($announcementLocale)['direction'] ?? 'ltr';
                            ?>
                            <article class="announcement-popover-item" lang="<?= d2s_escape($announcementLocale) ?>" dir="<?= d2s_escape((string)$announcementDirection) ?>" translate="no">
                                <time datetime="<?= gmdate('c', (int)($announcement['created_at'] ?? 0)) ?>" lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape((string)d2s_locale_context()['direction']) ?>"><?= d2s_escape($headerAnnouncementDate->format((int)($announcement['created_at'] ?? 0)) ?: '') ?></time>
                                <h3><?= d2s_escape((string)$announcement['title']) ?></h3>
                                <p><?= nl2br(d2s_escape((string)$announcement['body'])) ?></p>
                            </article>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </section>
        </details><?php endif ?>
        <a href="<?= d2s_escape(d2s_url($headerAccount)) ?>"><?= $headerAccount === '/profile' ? d2s_tr('common.nav.account') : d2s_tr('common.nav.sign_in') ?></a>
    </nav>
    <div class="site-header-tools">
        <?php $languagePickerAdmin = false; require D2S_ROOT.'/template/partials/language-switch.php'; ?>
        <button
            type="button"
            class="theme-switch"
            data-theme-toggle
            data-label-dark="<?= d2s_escape(d2s_tr('common.nav.theme.switch_dark')) ?>"
            data-label-light="<?= d2s_escape(d2s_tr('common.nav.theme.switch_light')) ?>"
            data-text-dark="<?= d2s_escape(d2s_tr('common.nav.theme.dark')) ?>"
            data-text-light="<?= d2s_escape(d2s_tr('common.nav.theme.light')) ?>"
            data-default-theme="<?= d2s_escape((string)$headerPlatform['default_theme']) ?>"
            data-platform-template="<?= d2s_escape((string)$headerPlatform['template']) ?>"
        >
            <span class="theme-switch-icon" aria-hidden="true"></span>
            <span data-theme-label><?= d2s_tr('common.nav.theme.dark') ?></span>
        </button>
    </div>
</header>
<?php if (($realm ?? null) !== 'admin' && ($pageMode ?? null) !== 'admin' && !preg_match('#^/admin(?:/|$)#', (string)($path ?? ''))): ?>
    <?php require_once D2S_ROOT.'/template/partials/support-widget.php'; ?>
    <?php if (!empty($siteData['popup_announcement'])) require_once D2S_ROOT.'/template/partials/announcement-popup.php'; ?>
<?php endif ?>
