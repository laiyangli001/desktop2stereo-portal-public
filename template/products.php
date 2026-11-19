<?php
declare(strict_types=1);
$uiNamespaces=array_values(array_unique([...($uiNamespaces??[]),'commerce']));
$site=$siteData['site'];
?>
<!doctype html>
<html lang="<?= d2s_escape(d2s_locale_code()) ?>" dir="<?= d2s_escape(d2s_locale_context()['direction']) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <?php $seoPath='/products'; require D2S_ROOT.'/template/partials/seo-head.php'; ?>
    <title><?= d2s_escape(d2s_tr('commerce.page.shop')) ?> · <?= d2s_escape($site['site_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/commerce.css">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="<?= d2s_escape(d2s_asset('/assets/css/shop.css')) ?>">
</head>
<body>
<?php require D2S_ROOT.'/template/partials/site-header.php'; ?>
<main class="legal-main">
    <h1><?= d2s_escape(d2s_tr('commerce.page.shop')) ?></h1>
    <div class="product-catalog">
    <?php foreach($publicCatalog['items'] as$item):
        $presentation=$item['presentation'];$prices=$item['prices'];
        $downloads=array_filter([
            [$item['download_url'],d2s_tr('home.ui.download')],
            [$item['download_quark'],d2s_tr('home.copy.quark')],
            [$item['download_baidu'],d2s_tr('home.copy.baidu')],
        ],static fn(array $download):bool=>$download[0]!=='');
        $legacyMirrors=$item['download_quark']==='https://pan.quark.cn/s/9d2bcf039b96'
            &&$item['download_baidu']==='https://pan.baidu.com/s/1JfMpcrdplPTQSNB6rExh6A?pwd=mr64';
    ?>
    <article class="portal-card shop-product" data-product="<?= d2s_escape($item['code']) ?>">
        <?php if($item['image_path']!==''): ?>
        <img class="product-catalog-image" src="<?= d2s_escape($item['image_path']) ?>" alt="<?= d2s_escape($presentation['title']) ?>" loading="lazy" decoding="async" width="640" height="400">
        <?php endif ?>
        <h2 lang="<?= d2s_escape($presentation['content_locale']) ?>" translate="no"><?= d2s_escape($presentation['title']) ?></h2>
        <p class="product-catalog-description" lang="<?= d2s_escape($presentation['content_locale']) ?>" translate="no"><?= d2s_escape($presentation['description']) ?></p>
        <div class="product-catalog-prices">
        <?php foreach($prices as$price): $amount=(int)$price['amount']; ?>
        <p class="shop-price"><?= d2s_escape($price['currency']) ?> <?= intdiv($amount,100) ?>.<?= str_pad((string)($amount%100),2,'0',STR_PAD_LEFT) ?></p>
        <?php endforeach ?>
        </div>
        <?php if($item['purchases_enabled']&&$prices!==[]): ?>
        <a class="portal-primary" href="<?= d2s_escape(d2s_url('/shop?product='.rawurlencode($item['code']))) ?>"><?= d2s_escape(d2s_tr('commerce.purchase.select')) ?></a>
        <?php else: ?>
        <p class="portal-help"><?= d2s_escape(d2s_tr('commerce.errors.purchases_closed')) ?></p>
        <?php endif ?>
        <?php if($downloads!==[]): ?>
        <div class="product-downloads">
            <div class="product-download-links">
                <?php foreach($downloads as [$url,$label]): ?>
                <a class="site-secondary" href="<?= d2s_escape($url) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_escape($label) ?></a>
                <?php endforeach ?>
            </div>
            <?php if($legacyMirrors): ?><p class="portal-help"><?= d2s_escape(d2s_tr('home.copy.codes')) ?></p><?php endif ?>
        </div>
        <?php endif ?>
    </article>
    <?php endforeach ?>
    </div>
    <?php if($publicCatalog['items']===[]): ?><p><?= d2s_escape(d2s_tr('commerce.catalog.empty')) ?></p><?php endif ?>
</main>
<?php require D2S_ROOT.'/template/partials/site-footer.php'; ?>
</body>
</html>
