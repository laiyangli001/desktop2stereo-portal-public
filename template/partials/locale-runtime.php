<?php
declare(strict_types=1);
$uiNamespaces = array_values(array_unique(['common', ...($uiNamespaces ?? [])]));
$uiCatalog = (new \D2S\services\UiTranslator(new \D2S\services\LocaleRegistry(), d2s_locale_code()))->browserPayload($uiNamespaces);
?>
<script id="d2s-locale-catalog" type="application/json"><?= json_encode($uiCatalog, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="<?= d2s_escape(d2s_asset('/assets/vendor/i18n-runtime.js')) ?>"></script>
