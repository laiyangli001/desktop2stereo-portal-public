<?php
declare(strict_types=1);
$widgetModules=isset($headerModules)&&is_array($headerModules)?$headerModules:D2S\services\PluginManager::defaults();
if(!isset($headerModules)&&isset($db)&&$db instanceof PDO){
    try{$widgetModules=(new D2S\services\PluginManager($db))->publicConfiguration();}
    catch(Throwable){/* Keep the support entry available if optional module state is unreadable. */}
}
if(!$widgetModules['support_widget'])return;
$widgetChatUrl = trim((string)($siteData['site']['support_wechat_url'] ?? ''));
$widgetQqImage = d2s_asset('/assets/img/d2s-qq.jpg');
?>
<aside translate="no" id="support-widget" class="support-widget support-widget-stack" data-storage-key="d2s-support-widget-position-v2" aria-label="<?= d2s_escape(d2s_tr('common.support.navigation')) ?>">
    <button id="support-widget-handle" type="button" aria-label="<?= d2s_escape(d2s_tr('common.support.move')) ?>">⠿</button>
    <div class="support-widget-actions">
        <a class="support-widget-feedback" href="<?= d2s_escape(d2s_url('/feedback')) ?>">
            <span class="support-widget-feedback-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4.5 5.5h15v10h-8l-4.5 3v-3H4.5z"></path>
                    <path d="M12 8v3.6"></path>
                    <path d="M12 14h.01"></path>
                </svg>
            </span>
            <span class="support-widget-copy">
                <strong><?= d2s_escape(d2s_tr('common.support.feedback')) ?></strong>
                <small><?= d2s_escape(d2s_tr('common.support.progress')) ?></small>
            </span>
        </a>
        <?php if ($widgetChatUrl !== ''): ?>
            <a class="support-widget-wechat" href="<?= d2s_escape(d2s_url('/support/wechat')) ?>">
                <span class="support-widget-wechat-mark" aria-hidden="true">W</span>
                <span class="support-widget-copy">
                    <strong><?= d2s_escape(d2s_tr('common.support.wechat')) ?></strong>
                    <small><?= d2s_escape(d2s_tr('common.support.live')) ?></small>
                </span>
            </a>
        <?php endif ?>
        <a class="support-widget-qq" href="<?= d2s_escape($widgetQqImage) ?>" aria-haspopup="dialog" aria-controls="support-qq-dialog">
            <span class="support-widget-feedback-mark support-widget-qq-mark" aria-hidden="true">QQ</span>
            <span class="support-widget-copy">
                <strong><?= d2s_escape(d2s_tr('common.support.qq')) ?></strong>
                <small><?= d2s_escape(d2s_tr('common.support.qq_scan')) ?></small>
            </span>
        </a>
    </div>
</aside>
<dialog id="support-qq-dialog" class="support-qq-dialog" aria-labelledby="support-qq-title" translate="no">
    <header>
        <h2 id="support-qq-title"><?= d2s_escape(d2s_tr('common.support.qq')) ?></h2>
        <form method="dialog">
            <button type="submit" autofocus aria-label="<?= d2s_escape(d2s_tr('common.support.qq_close')) ?>">×</button>
        </form>
    </header>
    <img alt="<?= d2s_escape(d2s_tr('home.copy.group_alt')) ?>">
</dialog>
<script src="<?= d2s_escape(d2s_asset('/assets/js/support-widget.js')) ?>" defer></script>
