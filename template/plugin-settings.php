<?php
declare(strict_types=1);
$uiNamespaces=[...($uiNamespaces??[]),'plugins','platform'];
$legacyDomTranslation=false;
$pluginText=static fn(string $key,array $parameters=[]): string=>d2s_escape(d2s_tr('plugins.'.$key,$parameters));
$pluginDetails=[
    'support_widget'=>$pluginText('detail.support_widget'),
    'announcement_menu'=>$pluginText('detail.announcement_menu'),
];
$pluginKinds=[
    'toggleable'=>$pluginText('kind.toggleable'),
    'locked'=>$pluginText('kind.locked'),
];
$pluginLabels=[
    'support_widget'=>$pluginText('module.support_widget'),
    'announcement_menu'=>$pluginText('module.announcement_menu'),
    'referral_program'=>$pluginText('module.referral_program'),
    'deepl_translation'=>$pluginText('module.deepl_translation'),
    'edgeone_cache'=>$pluginText('module.edgeone_cache'),
    'oauth'=>$pluginText('module.oauth'),
    'rich_text_editor'=>$pluginText('module.rich_text_editor'),
    'localized_content'=>$pluginText('module.localized_content'),
    'captcha'=>d2s_escape(d2s_tr('platform.extensions.captcha')),
    'scheduled_tasks'=>d2s_escape(d2s_tr('platform.extensions.scheduled_tasks')),
];
?>
<!doctype html>
<html lang="<?= d2s_locale() ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pluginText('title') ?> · Desktop2Stereo</title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/operations.css">
    <script src="<?= d2s_escape(d2s_asset('/assets/js/plugin-settings.js')) ?>" defer></script>
</head>
<body>
<?php require D2S_ROOT.'/template/partials/admin-header.php'; ?>
<main class="portal-main plugin-settings" translate="no">
    <header class="admin-page-heading">
        <div>
            <p class="portal-eyebrow"><?= $pluginText('eyebrow') ?></p>
            <h1><?= $pluginText('title') ?></h1>
            <p><?= $pluginText('description') ?></p>
        </div>
        <div class="admin-page-actions"><a class="site-secondary" href="<?= d2s_escape(d2s_url('/admin/maintenance')) ?>"><?= $pluginText('action.maintenance') ?></a></div>
    </header>
    <p id="plugin-message" role="status" aria-live="polite"></p>

    <form id="plugin-settings-form" class="admin-panel plugin-settings-form">
        <div class="plugin-section-heading">
            <div><h2><?= $pluginText('section.toggleable') ?></h2><p><?= $pluginText('help.toggleable') ?></p></div>
            <button class="portal-primary" type="submit"><?= $pluginText('action.save') ?></button>
        </div>
        <div class="plugin-toggle-grid">
            <?php foreach(['support_widget','announcement_menu']as$id): ?>
                <label class="plugin-toggle-card">
                    <span><strong><?= $pluginLabels[$id] ?></strong><small><?= $pluginDetails[$id] ?></small></span>
                    <input name="<?= d2s_escape($id) ?>" type="checkbox"<?= $pluginConfiguration['values'][$id]?' checked':'' ?>>
                </label>
            <?php endforeach ?>
        </div>
    </form>

    <section class="admin-panel" aria-labelledby="plugin-inventory-title">
        <div class="plugin-section-heading">
            <div><h2 id="plugin-inventory-title"><?= $pluginText('section.inventory') ?></h2><p><?= $pluginText('help.inventory') ?></p></div>
            <span class="operation-badge muted"><?= $pluginText('badge.allowlist') ?></span>
        </div>
        <div class="plugin-inventory-grid">
            <?php foreach($pluginConfiguration['modules']as$module): ?>
                <article class="plugin-inventory-card" data-plugin-id="<?= d2s_escape((string)$module['id']) ?>">
                    <div><h3><?= $pluginLabels[$module['id']]??d2s_escape((string)$module['id']) ?></h3><span class="operation-badge<?= $module['enabled']?'':' muted' ?>"><?= $module['enabled']?$pluginText('status.enabled'):$pluginText('status.disabled') ?></span></div>
                    <p><?= $pluginKinds[$module['kind']]??d2s_escape((string)$module['kind']) ?></p>
                    <?php if($module['id']==='localized_content'): ?><small><?= $pluginText('detail.locale_count',['count'=>(int)$module['detail']]) ?></small><?php elseif(($module['detail']??null)==='configured'): ?><small><?= $pluginText('status.configured') ?></small><?php elseif(($module['detail']??null)==='not_configured'): ?><small><?= $pluginText('status.not_configured') ?></small><?php elseif(($module['detail']??null)==='reserved'): ?><small><?= $pluginText('status.reserved') ?></small><?php endif ?>
                    <a href="<?= d2s_escape(d2s_url((string)$module['url'])) ?>"><?= $pluginText('action.manage') ?></a>
                </article>
            <?php endforeach ?>
        </div>
    </section>

    <section class="admin-panel plugin-security-note">
        <h2><?= $pluginText('section.security') ?></h2>
        <p><?= $pluginText('help.security') ?></p>
    </section>
</main>
</body>
</html>
