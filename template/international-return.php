<?php
declare(strict_types=1);
$legacyDomTranslation = false;
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= d2s_tr('common.payments.return.checking_title') ?> · Desktop2Stereo</title>
<link rel="stylesheet" href="/assets/css/commerce.css"><link rel="stylesheet" href="/assets/css/portal.css">
<script src="<?= d2s_escape(d2s_asset('/assets/js/international-return.js')) ?>" defer></script>
</head><body><?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="portal-main portal-auth"><section id="international-return" class="portal-card" data-order="<?= d2s_escape($returnOrderId) ?>">
<h1><?= d2s_tr('common.payments.return.checking_title') ?></h1><p id="international-result" role="status" aria-live="polite"><?= d2s_tr('common.payments.return.checking') ?></p>
<nav class="portal-links"><a href="<?= d2s_escape(d2s_url('/shop')) ?>"><?= d2s_tr('common.payments.return.back_store') ?></a><a href="<?= d2s_escape(d2s_url('/license')) ?>"><?= d2s_tr('common.payments.return.view_license') ?></a><a href="<?= d2s_escape(d2s_url('/login')) ?>"><?= d2s_tr('common.payments.return.sign_in') ?></a></nav>
</section></main></body></html>
