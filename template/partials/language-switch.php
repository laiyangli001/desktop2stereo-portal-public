<?php
declare(strict_types=1);
$languagePickerOptions = ($languagePickerAdmin ?? false)
    ? (new D2S\services\LocaleRegistry())->enabledForAdmin()
    : (new D2S\services\LocaleRegistry())->enabled(true);
$currentLanguageOption = null;
foreach ($languagePickerOptions as $languageOption) {
    if (d2s_locale_code() === $languageOption['code']) {
        $currentLanguageOption = $languageOption;
        break;
    }
}
if ($currentLanguageOption === null) {
    $currentLanguageOption = $languagePickerOptions[0] ?? ['code' => 'zh-Hans', 'native_name' => '简体中文'];
}
?>
<link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/locale-switch.css')) ?>">
<script src="<?= d2s_escape(d2s_asset('/assets/js/locale-switch.js')) ?>" defer></script>
<div class="language-switch" data-locale-picker translate="no">
    <select class="locale-select" data-locale-switch aria-label="<?= d2s_escape(d2s_tr('common.nav.language')) ?>">
        <?php foreach ($languagePickerOptions as $languageOption): ?>
        <option value="<?= d2s_escape(d2s_language_url((string)$languageOption['code'])) ?>" data-locale="<?= d2s_escape((string)$languageOption['code']) ?>" lang="<?= d2s_escape((string)$languageOption['code']) ?>"<?= d2s_locale_code() === $languageOption['code'] ? ' selected' : '' ?>><?= d2s_escape((string)$languageOption['native_name']) ?></option>
        <?php endforeach ?>
    </select>
    <button class="locale-toggle" type="button" data-locale-toggle aria-haspopup="listbox" aria-expanded="false" hidden>
        <span class="locale-globe" data-locale-current-icon aria-hidden="true">🌐</span>
        <span data-locale-current-name><?= d2s_escape((string)$currentLanguageOption['native_name']) ?></span>
        <svg class="locale-chevron" aria-hidden="true" viewBox="0 0 12 8"><path d="m1 1 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
    </button>
    <div class="locale-menu" data-locale-menu role="listbox" aria-label="<?= d2s_escape(d2s_tr('common.nav.language')) ?>" hidden>
        <?php foreach ($languagePickerOptions as $languageOption): ?>
        <button class="locale-menu-option" type="button" role="option" data-locale-option data-locale="<?= d2s_escape((string)$languageOption['code']) ?>" data-value="<?= d2s_escape(d2s_language_url((string)$languageOption['code'])) ?>" aria-selected="<?= d2s_locale_code() === $languageOption['code'] ? 'true' : 'false' ?>" lang="<?= d2s_escape((string)$languageOption['code']) ?>">
            <span class="locale-globe" aria-hidden="true">🌐</span>
            <span><?= d2s_escape((string)$languageOption['native_name']) ?></span>
        </button>
        <?php endforeach ?>
    </div>
</div>
