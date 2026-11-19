<?php
declare(strict_types=1);
$legacyDomTranslation = false;
?>
<!doctype html><html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= d2s_tr('common.payments.return.result_title') ?> · Desktop2Stereo</title><script src="/assets/js/payment-return.js" defer></script></head>
<body><p><?= d2s_tr('common.payments.return.verifying') ?></p><a href="<?= d2s_escape(d2s_url('/shop')) ?>" target="_top"><?= d2s_tr('common.payments.return.store_order') ?></a></body></html>
