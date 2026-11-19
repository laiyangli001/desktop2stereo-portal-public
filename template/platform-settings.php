<?php
declare(strict_types=1);

$uiNamespaces = [...($uiNamespaces ?? []), 'platform'];
$legacyDomTranslation = false;
$platformValues = $platformConfiguration['values'];
$platformCdn = $platformConfiguration['cdn'];

$platformText = static fn(string $key, array $parameters=[]): string => d2s_escape(d2s_tr('platform.'.$key,$parameters));

$platformInput = static function(string $name, string $type='text', array $attributes=[]): string {
    $result = '<input id="platform-'.str_replace('_','-',$name).'" name="'.$name.'" type="'.$type.'"';
    foreach($attributes as $attribute=>$value)$result .= ' '.$attribute.'="'.d2s_escape((string)$value).'"';
    return $result.'>';
};
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $platformText('title') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/operations.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/platform-settings.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main platform-settings" translate="no">
    <header class="admin-page-heading">
        <div>
            <p class="portal-eyebrow"><?= $platformText('eyebrow') ?></p>
            <h1><?= $platformText('title') ?></h1>
            <p><?= $platformText('description') ?></p>
        </div>
    </header>
    <p id="platform-message" role="status" aria-live="polite"></p>

    <form id="platform-settings-form" class="admin-panel platform-settings-form">
        <section class="platform-settings-section" aria-labelledby="platform-appearance-title">
            <div class="platform-section-heading">
                <div><h2 id="platform-appearance-title"><?= $platformText('section.appearance') ?></h2><p><?= $platformText('help.appearance') ?></p></div>
                <span class="operation-badge"><?= $platformText('badge.runtime') ?></span>
            </div>
            <div class="platform-form-grid">
                <label for="platform-template"><?= $platformText('field.template') ?>
                    <select id="platform-template" name="template">
                        <option value="classic"<?= $platformValues['template']==='classic'?' selected':'' ?>><?= $platformText('option.classic') ?></option>
                        <option value="compact"<?= $platformValues['template']==='compact'?' selected':'' ?>><?= $platformText('option.compact') ?></option>
                    </select>
                </label>
                <label for="platform-default-theme"><?= $platformText('field.default_theme') ?>
                    <select id="platform-default-theme" name="default_theme">
                        <option value="system"<?= $platformValues['default_theme']==='system'?' selected':'' ?>><?= $platformText('option.system') ?></option>
                        <option value="light"<?= $platformValues['default_theme']==='light'?' selected':'' ?>><?= $platformText('option.light') ?></option>
                        <option value="dark"<?= $platformValues['default_theme']==='dark'?' selected':'' ?>><?= $platformText('option.dark') ?></option>
                    </select>
                </label>
            </div>
        </section>

        <section class="platform-settings-section" aria-labelledby="platform-captcha-title">
            <div class="platform-section-heading">
                <div><h2 id="platform-captcha-title"><?= $platformText('section.captcha') ?></h2><p><?= $platformText('help.captcha') ?></p></div>
                <label class="platform-toggle"><input name="captcha_enabled" type="checkbox"<?= $platformValues['captcha_enabled']?' checked':'' ?>><?= $platformText('field.captcha_enabled') ?></label>
            </div>
            <div class="platform-form-grid platform-form-grid-four">
                <label for="platform-captcha-required-min"><?= $platformText('field.captcha_min') ?>
                    <?= $platformInput('captcha_required_min','number',['min'=>2,'max'=>4,'step'=>1,'value'=>$platformValues['captcha_required_min']]) ?>
                </label>
                <label for="platform-captcha-required-max"><?= $platformText('field.captcha_max') ?>
                    <?= $platformInput('captcha_required_max','number',['min'=>2,'max'=>4,'step'=>1,'value'=>$platformValues['captcha_required_max']]) ?>
                </label>
                <label for="platform-captcha-ttl-seconds"><?= $platformText('field.captcha_ttl') ?>
                    <?= $platformInput('captcha_ttl_seconds','number',['min'=>60,'max'=>600,'step'=>1,'value'=>$platformValues['captcha_ttl_seconds']]) ?>
                </label>
                <label for="platform-captcha-tolerance-px"><?= $platformText('field.captcha_tolerance') ?>
                    <?= $platformInput('captcha_tolerance_px','number',['min'=>8,'max'=>24,'step'=>1,'value'=>$platformValues['captcha_tolerance_px']]) ?>
                </label>
            </div>
        </section>

        <section class="platform-settings-section" aria-labelledby="platform-seo-title">
            <div class="platform-section-heading">
                <div><h2 id="platform-seo-title"><?= $platformText('section.seo_cache') ?></h2><p><?= $platformText('help.seo_cache') ?></p></div>
            </div>
            <div class="platform-form-grid">
                <label for="platform-page-cache-ttl-seconds"><?= $platformText('field.page_cache_ttl') ?>
                    <?= $platformInput('page_cache_ttl_seconds','number',['min'=>0,'max'=>3600,'step'=>1,'value'=>$platformValues['page_cache_ttl_seconds']]) ?>
                </label>
                <div class="platform-checks">
                    <label><input name="seo_index" type="checkbox"<?= $platformValues['seo_index']?' checked':'' ?>><?= $platformText('field.seo_index') ?></label>
                    <label><input name="seo_sitemap" type="checkbox"<?= $platformValues['seo_sitemap']?' checked':'' ?>><?= $platformText('field.seo_sitemap') ?></label>
                </div>
            </div>
        </section>

        <div class="platform-save-row">
            <button class="portal-primary" type="submit"><?= $platformText('action.save') ?></button>
            <span><?= $platformText('help.save_scope') ?></span>
        </div>
    </form>

    <section class="admin-panel" aria-labelledby="platform-cdn-title">
        <div class="platform-section-heading">
            <div><h2 id="platform-cdn-title"><?= $platformText('section.cdn') ?></h2><p><?= $platformText('help.cdn') ?></p></div>
            <span class="operation-badge<?= $platformCdn['enabled']?'':' muted' ?>" data-cdn-state><?= $platformCdn['enabled']?$platformText('status.enabled'):$platformText('status.disabled') ?></span>
        </div>
        <dl class="platform-cdn-status">
            <div><dt><?= $platformText('cdn.configuration') ?></dt><dd data-cdn-configuration><?= $platformCdn['configuration_ready']?$platformText('status.ready'):$platformText('status.incomplete') ?></dd></div>
            <div><dt><?= $platformText('cdn.pending') ?></dt><dd data-cdn-count="pending"><?= (int)$platformCdn['counts']['pending'] ?></dd></div>
            <div><dt><?= $platformText('cdn.retry') ?></dt><dd data-cdn-count="retry"><?= (int)$platformCdn['counts']['retry'] ?></dd></div>
            <div><dt><?= $platformText('cdn.failed') ?></dt><dd data-cdn-count="failed"><?= (int)$platformCdn['counts']['failed'] ?></dd></div>
            <div><dt><?= $platformText('cdn.last_error') ?></dt><dd data-cdn-last-error><?= d2s_escape((string)($platformCdn['last_error'] ?? $platformText('status.none'))) ?></dd></div>
        </dl>
        <div class="platform-cdn-actions">
            <button id="platform-cdn-purge" type="button"><?= $platformText('action.queue_purge') ?></button>
            <p><?= $platformText('help.cdn_worker') ?></p>
        </div>
    </section>


</main>
</body>
</html>
