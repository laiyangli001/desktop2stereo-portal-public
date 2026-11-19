<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap/app.php';

d2s_language();
header('Content-Language: '.d2s_locale_code());

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$localeRegistry = new D2S\services\LocaleRegistry();
$path = is_string($path) && $path !== '' ? $path : '/';
$segments = explode('/', trim($path, '/'));
$firstSegment = $segments[0] ?? '';
$localizedPrefix = $localeRegistry->fromPrefix($firstSegment, true);
$platformRuntime = null;
$loadPlatformRuntime = static function () use (&$platformRuntime): array {
    if (is_array($platformRuntime)) return $platformRuntime;
    try {
        $platformRuntime = (new D2S\services\PlatformSettings(D2S\repositories\Database::fromEnvironment()))->publicConfiguration();
    } catch (Throwable) {
        $platformRuntime = D2S\services\PlatformSettings::defaults();
    }
    return $platformRuntime;
};
$platformRuntime = (in_array($path,['/install','/install/'],true) || str_starts_with($path,'/api/v2/install/'))
    ? D2S\services\PlatformSettings::defaults() : $loadPlatformRuntime();
if (!$platformRuntime['seo_index']) header('X-Robots-Tag: noindex, nofollow');
$canCachePublicPage = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $_GET === []
    && ($localizedPrefix === null || $localizedPrefix !== $localeRegistry->defaultLocale());
$enablePublicPageCache = static function () use ($canCachePublicPage,$platformRuntime): void {
    $ttl = (int)$platformRuntime['page_cache_ttl_seconds'];
    if (!$canCachePublicPage || $ttl <= 0 || http_response_code() !== 200) return;
    foreach (headers_list() as $header) {
        if (strncasecmp($header, 'Set-Cookie:', 11) === 0) return;
    }
    header('Cache-Control: public, max-age=0, s-maxage='.$ttl.', stale-while-revalidate='.min(60,$ttl).'', true);
};
$prefixRemainder = '/' . implode('/', array_slice($segments, 1));
$reservedPrefixPath = preg_match('#^/(?:api|assets|uploads|downloads|alipay|oauth|webhook|native)(?:/|$)#', $prefixRemainder) === 1;
$privatePrefixPath = preg_match('#^/(?:admin|user|account|dashboard|profile|tickets|wallet|license|notifications|feedback|consent)(?:/|$)#', $prefixRemainder) === 1;
$reservedRequestPath = preg_match('#^/(?:api|assets|uploads|downloads|alipay|oauth|webhook|native)(?:/|$)#', $path) === 1;
$privateRequestPath = preg_match('#^/(?:admin|user|account|dashboard|profile|tickets|wallet|license|notifications|feedback|consent)(?:/|$)#', $path) === 1;
if ($localizedPrefix !== null && !$reservedPrefixPath && !$privatePrefixPath) {
    $canonicalPath = $prefixRemainder === '/' ? '/' : $prefixRemainder;
    if (array_key_exists('lang', $_GET)) {
        $query = $_GET;
        unset($query['lang']);
        $target = '/' . $localeRegistry->get($localizedPrefix)['url_prefix'] . $canonicalPath;
        if ($query !== []) $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $target, true, 302);
        exit;
    }
    $path = $canonicalPath;
} elseif (!$reservedRequestPath && !$privateRequestPath && is_string($_GET['lang'] ?? null)) {
    $selectedLocale = $localeRegistry->normalize($_GET['lang'], true);
    if ($selectedLocale !== null) {
        $query = $_GET;
        unset($query['lang']);
        $target = '/' . $localeRegistry->get($selectedLocale)['url_prefix'] . ($path === '/' ? '/' : $path);
        if ($query !== []) $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $target, true, 302);
        exit;
    }
}

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    if (!$platformRuntime['seo_index'] || !$platformRuntime['seo_sitemap']) {
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        exit;
    }
    try {
        $seoRegistry = new D2S\services\LocaleRegistry();
        $seoUrls = new D2S\services\PublicSeoUrls($seoRegistry);
        $publicLocales = array_column($seoRegistry->enabled(true), 'code');
        $entries = [];
        $db = D2S\repositories\Database::fromEnvironment();
        $policyService = new D2S\services\PolicyTranslations($db,$seoRegistry);
        $policyPaths = ['/privacy-policy'=>'PRIVACY','/terms'=>'TERMS','/legal-statement'=>'LEGAL'];
        $staticPaths = ['/','/help','/privacy-policy','/business-license','/terms','/legal-statement','/about','/contact'];
        foreach ($staticPaths as $publicPath) {
            $pageLocales = $publicLocales;
            if (isset($policyPaths[$publicPath])) {
                $pageLocales = array_values(array_intersect(['zh-Hans','en'],$publicLocales));
                if ($policyService->isAvailable()) {
                    $publishedLocales = $policyService->publishedLocales($policyPaths[$publicPath]);
                    if ($publishedLocales !== []) $pageLocales = $publishedLocales;
                }
            }
            $alternates = $seoUrls->alternates($publicPath, $pageLocales);
            foreach ($pageLocales as $locale) {
                $url = $seoUrls->url($publicPath, $locale);
                $entries[$url] = ['alternates'=>$alternates,'lastmod'=>null];
            }
        }
        $articleService = new D2S\services\ArticleTranslations($db, $seoRegistry);
        $articlesBySlug = [];
        foreach ($articleService->publicRoutes() as $route) {
            $articlesBySlug[(string)$route['slug']][(string)$route['locale_code']] = (int)$route['created_at'];
        }
        foreach ($articlesBySlug as $slug => $publishedLocales) {
            $articlePath = '/help/' . $slug;
            $alternates = $seoUrls->alternates($articlePath, array_keys($publishedLocales));
            foreach ($publishedLocales as $locale => $timestamp) {
                $url = $seoUrls->url($articlePath, $locale);
                $entries[$url] = ['alternates'=>$alternates,'lastmod'=>$timestamp > 0 ? gmdate('Y-m-d', $timestamp) : null];
            }
        }
        ksort($entries, SORT_STRING);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . PHP_EOL;
        foreach ($entries as $url => $entry) {
            $xml .= "  <url><loc>" . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>";
            if ($entry['lastmod'] !== null) $xml .= '<lastmod>' . $entry['lastmod'] . '</lastmod>';
            foreach ($entry['alternates'] as $locale => $alternate) {
                $xml .= '<xhtml:link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '" href="' . htmlspecialchars($alternate, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>';
            }
            $xml .= '</url>' . PHP_EOL;
        }
        if ($localizedPrefix === null) $enablePublicPageCache();
        echo $xml . '</urlset>';
    } catch (Throwable) {
        http_response_code(503);
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }
    exit;
}
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    $robotsRegistry = new D2S\services\LocaleRegistry();
    $robotsSitemap = (new D2S\services\PublicSeoUrls($robotsRegistry))->url('/sitemap.xml', $robotsRegistry->defaultLocale());
    if (!$platformRuntime['seo_index']) echo "User-agent: *\nDisallow: /\n";
    else echo "User-agent: *\nAllow: /\n".(($platformRuntime['seo_sitemap'] ? 'Sitemap: '.$robotsSitemap."\n" : ''));
    exit;
}
if(in_array($path,['/install','/install/'],true)){
    header('Content-Type: text/html; charset=utf-8');require D2S_ROOT.'/template/install.php';exit;
}
if(str_starts_with((string)$path,'/api/v2/install/')){
    header('Content-Type: application/json; charset=utf-8');
    try{require D2S_ROOT.'/api/installation.php';}
    catch(D2S\domain\Problem $error){http_response_code($error->httpStatus);echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);}
    catch(Throwable){http_response_code(503);echo '{"success":false,"error":"installation_failed_requires_review"}';}
    exit;
}
if(isset($_GET['ref'])&&is_string($_GET['ref'])&&D2S\services\ReferralProgram::isValidCode($_GET['ref'])){
    setcookie('D2S_REFERRAL',$_GET['ref'],['path'=>'/','expires'=>time()+30*86400,
        'httponly'=>true,'secure'=>str_starts_with(getenv('D2S_APP_URL')?:'https://d2s.site','https://'),'samesite'=>'Lax']);
}

if ($path === '/shop') {
    header("Content-Security-Policy: default-src 'self'; frame-src 'self' https://d2s.site https://d2s.top https://*.alipay.com https://*.alipaydev.com; frame-ancestors 'none'; base-uri 'none'; form-action 'self' https://*.alipay.com https://*.alipaydev.com");
}
if ($path === '/alipay/return' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self' https://d2s.site https://d2s.top; base-uri 'none'; form-action 'self'");
    header('Content-Type: text/html; charset=utf-8');
    require D2S_ROOT . '/template/payment-return.php';
    exit;
}
if (in_array($path, ['/api/v1/alipay/app-gateway','/api/v1/alipay/d2s/notify'], true)) {
    require D2S_ROOT . '/api/alipay-notify.php';
    exit;
}
if (preg_match('#^/api/v2/wechat/notify/([a-f0-9]{64})$#D',(string)$path,$wechatRoute)) {
    require D2S_ROOT . '/api/wechat-notify.php';
    exit;
}
if (preg_match('#^/api/v2/wechat/refund-notify/([a-f0-9]{64})$#D',(string)$path,$wechatRoute)) {
    $wechatRefundNotification = true;
    require D2S_ROOT . '/api/wechat-notify.php';
    exit;
}
if(preg_match('#^/api/v2/payments/(paypal|stripe)/([a-f0-9]{32})/webhook$#D',(string)$path,$internationalRoute)){
    require D2S_ROOT.'/api/international-webhook.php';exit;
}
if($path==='/payments/return'&&$_SERVER['REQUEST_METHOD']==='GET'){
    $returnOrderId=is_string($_GET['order']??null)?$_GET['order']:'';
    if(!preg_match('/^[a-f0-9]{32}$/D',$returnOrderId)){http_response_code(400);echo d2s_escape(d2s_tr('common.errors.invalid_order'));exit;}
    header('Content-Type: text/html; charset=utf-8');require D2S_ROOT.'/template/international-return.php';exit;
}
if (str_starts_with((string)$path, '/api/v1/')) {
    http_response_code(426);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":false,"error":"client_upgrade_required","clear_credentials":true}';
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/license-keys') || str_starts_with((string)$path, '/api/v2/client/') ||
    str_starts_with((string)$path, '/api/v2/me/license') || $path === '/api/v2/license/keys') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/licensing.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error,
            'clear_credentials'=>str_starts_with((string)$path,'/api/v2/client/') && in_array($error->httpStatus,[401,403],true)], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable","clear_credentials":false}';
    }
    exit;
}
if (str_starts_with((string)$path,'/api/v2/referrals') || str_starts_with((string)$path,'/api/v2/admin/referrals')) {
    header('Content-Type: application/json; charset=utf-8');
    try{require D2S_ROOT.'/api/referrals.php';}
    catch(D2S\domain\Problem $error){http_response_code($error->httpStatus);echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);}
    catch(Throwable){http_response_code(503);echo '{"success":false,"error":"service_unavailable"}';}
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/operations/')) {
    header('Content-Type: application/json; charset=utf-8');
    try { require D2S_ROOT.'/api/operations.php'; }
    catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/notifications') || str_starts_with((string)$path, '/api/v2/admin/notifications')) {
    header('Content-Type: application/json; charset=utf-8');
    try { require D2S_ROOT . '/api/notifications.php'; }
    catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
    } catch (Throwable) { http_response_code(503); echo '{"success":false,"error":"service_unavailable"}'; }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/payments/')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/payment-operations.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/support/') || str_starts_with((string)$path, '/api/v2/admin/tickets')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/support.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/audit')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/audit.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/accounts')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/account-controls.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/content')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/content.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        error_log('Content API failed ['.get_class($error).'] '.$error->getFile().':'.$error->getLine());
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if (str_starts_with((string)$path, '/api/v2/shop/') || str_starts_with((string)$path, '/api/v2/orders') ||
    str_starts_with((string)$path, '/api/v2/wallet') || str_starts_with((string)$path, '/api/v2/admin/commerce/')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require D2S_ROOT . '/api/commerce.php';
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success'=>false,'error'=>$error->error], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if ($path === '/api/status') {
    header('Content-Type: application/json; charset=utf-8');
    $databaseReady = false;
    try {
        $pdo = D2S\repositories\Database::fromEnvironment();
        $databaseReady = D2S\services\Health::ready($pdo, D2S_ROOT . '/database/migrations');
    } catch (Throwable) {
        $databaseReady = false;
    }
    $release = getenv('D2S_RELEASE') ?: 'development';
    $manifestPath = getenv('D2S_RELEASE_MANIFEST') ?: '';
    $manifestReady = null;
    $manifestHash = null;
    if ($manifestPath !== '') {
        $manifest = D2S\services\ReleaseManifest::verify(D2S_ROOT,$manifestPath,$release);
        $manifestReady = $manifest['ok'];
        $manifestHash = $manifest['root_hash'];
    }
    $ready = $databaseReady && $manifestReady !== false;
    http_response_code($ready ? 200 : 503);
    echo json_encode([
        'success' => $ready,
        'data' => [
            'version' => $release,
            'database_ready' => $databaseReady,
            'release_manifest_configured' => $manifestPath !== '',
            'release_manifest_ready' => $manifestReady,
            'release_root_hash' => $manifestHash,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}
if (str_starts_with((string)$path, '/api/v2/admin/settings/') || str_starts_with((string)$path, '/api/v2/auth/') ||
    in_array($path, ['/api/v2/admin/captcha','/api/v2/admin/login','/api/v2/admin/session','/api/v2/admin/logout','/api/v2/me/profile',
        '/api/v2/me/password','/api/v2/me/contact','/api/v2/me/consents','/api/v2/me/locale','/api/v2/admin/locale'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $handler = str_starts_with((string)$path, '/api/v2/admin/settings/platform') ? '/api/platform-settings.php' :
            (str_starts_with((string)$path, '/api/v2/admin/settings/plugins') ? '/api/plugin-settings.php' :
            (str_starts_with((string)$path, '/api/v2/admin/settings/wechat') ? '/api/wechat-settings.php' :
            (str_starts_with((string)$path, '/api/v2/admin/settings/alipay') ? '/api/alipay-settings.php' :
            (str_starts_with((string)$path, '/api/v2/admin/settings/') ? '/api/delivery-settings.php' : '/api/accounts.php'))));
        require D2S_ROOT . $handler;
    } catch (D2S\domain\Problem $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['success' => false, 'error' => $error->error], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"success":false,"error":"service_unavailable"}';
    }
    exit;
}
if ($path === '/feedback/new') {
    $feedbackType = strtolower(trim((string)($_GET['type'] ?? '')));
    $feedbackTypes = ['bug','purchase','license','download','account','software','suggestion','other'];
    $target = '/feedback' . (in_array($feedbackType, $feedbackTypes, true) ? '?type=' . rawurlencode($feedbackType) : '');
    header('Location: ' . $target, true, 303);
    exit;
}
$notFoundPath = $localizedPrefix === null ? $path : $prefixRemainder;
$renderNotFound = static function (string $descriptionKey, ?PDO $db = null, ?array $siteData = null) use ($path, $notFoundPath): void {
    http_response_code(404);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex', false);
    header('Content-Type: text/html; charset=utf-8');
    if ($siteData === null) {
        try {
            $db ??= D2S\repositories\Database::fromEnvironment();
            $siteData = (new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
        } catch (Throwable) {
            $siteData = ['site'=>D2S\services\SiteContent::defaultSite(),'navigation'=>D2S\services\SiteContent::defaultNavigation(),'announcements'=>[]];
        }
    }
    $realm = preg_match('#^/admin(?:/|$)#', $notFoundPath) ? 'admin' : 'public';
    require D2S_ROOT . '/template/not-found.php';
};
if ($path === '/help' || preg_match('#^/help/([a-z0-9]+(?:-[a-z0-9]+)*)$#D',(string)$path,$helpMatch)) {
    $publicBufferLevel = ob_get_level();
    ob_start();
    try {
        $db=D2S\repositories\Database::fromEnvironment();
        $siteData=(new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
        $articleService=new D2S\services\ArticleTranslations($db);
        $article=null;
        $seoLocales=array_column($localeRegistry->enabled(true),'code');
        if(isset($helpMatch[1])){
            $article=$articleService->publicArticle($helpMatch[1],d2s_locale_code());
            if (!$article) {
                $renderNotFound('common.errors.article_not_found', $db, $siteData);
                exit;
            }
            $seoLocales=$articleService->publicLocales($helpMatch[1]);
            if($article['fallback_used']){
                $definition=$localeRegistry->get((string)$article['content_locale']);
                if(($definition['enabled']??false)&&($definition['public_enabled']??false)){
                    $target=d2s_url('/help/'.$helpMatch[1],(string)$article['content_locale']);
                    $target.=(str_contains($target,'?')?'&':'?').'translation=unavailable';
                    header('Location: '.$target,true,302);exit;
                }
                // Keep published source content readable when its UI locale has no public URL.
                header('X-Robots-Tag: noindex, follow');
                $seoLocales=[];
            }
            $articles=[];
        }else{
            $articles=$articleService->publicList(d2s_locale_code());
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Language: '.d2s_locale_code());
        require D2S_ROOT.'/template/help.php';
        $publicBody = ob_get_clean();
        if (!is_array($article) || empty($article['fallback_used'])) $enablePublicPageCache();
        echo $publicBody;
    }catch(Throwable){
        while (ob_get_level() > $publicBufferLevel) ob_end_clean();
        http_response_code(503);
        header('Cache-Control: no-store', true);
        echo d2s_escape(d2s_tr('common.errors.help_unavailable'));
    }
    exit;
}
if (preg_match('#^/product-images/([a-f0-9]{64})\\.png$#D',(string)$path,$productImagePath)) {
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){
        http_response_code(405);header('Allow: GET, HEAD');exit;
    }
    $imageFile=D2S\services\ProductImages::directory().DIRECTORY_SEPARATOR.$productImagePath[1].'.png';
    if(!is_file($imageFile)||is_link($imageFile)||hash_file('sha256',$imageFile)!==$productImagePath[1]){
        http_response_code(404);exit;
    }
    header('Content-Type: image/png');
    header('Content-Length: '.filesize($imageFile));
    header('Cache-Control: public, max-age=31536000, immutable');
    if($_SERVER['REQUEST_METHOD']==='GET')readfile($imageFile);
    exit;
}

if ($path === '/products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $realm='public';
    try {
        $db=D2S\repositories\Database::fromEnvironment();
        $siteData=(new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
        $publicCatalog=(new D2S\services\Commerce($db))->storefront(d2s_locale_code());
        header('Content-Type: text/html; charset=utf-8');
        require D2S_ROOT.'/template/products.php';
    } catch (Throwable) {
        http_response_code(503);
        echo d2s_escape(d2s_tr('common.errors.service_unavailable'));
    }
    exit;
}
$accountPages = ['/login'=>'login','/register'=>'register','/password-reset'=>'reset','/admin/login'=>'admin'];
if (isset($accountPages[$path])) {
    $pageMode = $accountPages[$path];
    $pageNext = D2S\domain\AccountReturnPath::normalize($_GET['next'] ?? null);
    $deliveryConfig = [];
    $captchaConfiguration = ['enabled'=>true];
    $siteData = ['site'=>D2S\services\SiteContent::defaultSite(),'navigation'=>D2S\services\SiteContent::defaultNavigation(),'announcements'=>[]];
    try {
        $db = D2S\repositories\Database::fromEnvironment();
        $deliveryConfig = (new D2S\services\DeliverySettings($db, D2S\services\SecretBox::fromEnvironment()))->load();
        $captchaConfiguration = (new D2S\services\PlatformSettings($db))->captchaConfiguration();
        $siteData = (new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
    } catch (Throwable) {
        // Unavailable configuration disables verification choices.
    }
    $realm = 'public';
    header('Content-Type: text/html; charset=utf-8');
    require D2S_ROOT . '/template/auth.php';
    exit;
}
if(in_array($path,['/referrals','/admin/referrals'],true)){
    $referralAdmin=$path==='/admin/referrals';
    try{
        $db=D2S\repositories\Database::fromEnvironment();
        $owner=D2S\services\HttpRequest::session($referralAdmin?'admin':'user',new D2S\services\WebSessions($db),false,time());
        if($referralAdmin){
            D2S\services\Permissions::require($db,(int)$owner['id'],'referrals.manage');
            $permissions=(new D2S\services\Backoffice($db))->permissions((int)$owner['id']);
        }else{
            $referralProgramEnabled=(bool)D2S\services\ReferralProgram::configuration($db)['enabled'];
            if(!$referralProgramEnabled){
                header('Location: '.d2s_url('/profile'),true,303);
                exit;
            }
            $siteData=(new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
        }
        header('Content-Type: text/html; charset=utf-8');require D2S_ROOT.'/template/referrals.php';
    }catch(D2S\domain\Problem $error){
        if($error->error==='legal_reconsent_required')header('Location: '.d2s_url('/consent'),true,303);
        elseif($error->httpStatus===403){http_response_code(403);echo d2s_escape(d2s_tr('referrals.errors.permission_denied'));}
        else header('Location: '.d2s_url($referralAdmin?'/admin/login':'/login'),true,303);
    }catch(Throwable){http_response_code(503);echo d2s_escape(d2s_tr('referrals.errors.service_unavailable'));}
    exit;
}
$paymentSettingsAliases=['/admin/payment-configurations'=>'','/admin/settings/alipay'=>'#alipay_pc','/admin/settings/wechat'=>'#wechat_native'];
if(isset($paymentSettingsAliases[$path])){header('Location: '.d2s_url('/admin/settings/payments'.$paymentSettingsAliases[$path]),true,303);exit;}
if($path==='/admin/commerce'){header('Location: '.d2s_url('/admin/products'),true,303);exit;}
$operationPages=[
    '/admin/users'=>['users','users.read'],'/admin/order-search'=>['orders','orders.read'],
    '/admin/finance'=>['ledger','wallets.read'],'/admin/articles'=>['articles','articles.manage'],
    '/admin/products'=>['product','products.manage'],'/admin/staff'=>['staff','admins.manage'],
    '/admin/tasks'=>['tasks','settings.manage'],
    '/admin/maintenance'=>['maintenance','settings.manage'],
    '/admin/upgrade'=>['upgrade','settings.manage'],
    '/admin/settings/platform'=>['platform','settings.manage'],
    '/admin/plugins'=>['plugins','settings.manage'],
    '/admin/locales'=>['locales','locales.manage'],
    '/admin/settings/oauth'=>['oauth','settings.manage'],
    '/admin/settings/payments'=>['payment-interfaces','payment.configure'],
];
if(isset($operationPages[$path])){
    try {
        $db=D2S\repositories\Database::fromEnvironment();
        $owner=D2S\services\HttpRequest::session('admin',new D2S\services\WebSessions($db),false,time());
        D2S\services\Permissions::require($db,(int)$owner['id'],$operationPages[$path][1]);
        $permissions=(new D2S\services\Backoffice($db))->permissions((int)$owner['id']);
        $operationSection=$operationPages[$path][0];
        header('Content-Type: text/html; charset=utf-8');
        if($operationSection==='platform'){
            $platformConfiguration=(new D2S\services\PlatformSettings($db))->view((int)$owner['id'],D2S\services\Audit::context($_SERVER),time());
            require D2S_ROOT.'/template/platform-settings.php';
        }elseif($operationSection==='plugins'){
            $pluginConfiguration=(new D2S\services\PluginManager($db))->view((int)$owner['id'],D2S\services\Audit::context($_SERVER),time());
            require D2S_ROOT.'/template/plugin-settings.php';
        }else{
            if($operationSection==='payment-interfaces'){
                $walletRechargeConfiguration=(new D2S\services\WalletRecharges($db))->configuration((int)$owner['id']);
            }
            require D2S_ROOT.'/template/operations.php';
        }
    }catch(D2S\domain\Problem $error){
        if($error->httpStatus===403){http_response_code(403);echo d2s_escape(d2s_tr('common.errors.access_denied'));}
        else header('Location: /admin/login',true,303);
    }catch(Throwable){http_response_code(503);echo d2s_escape(d2s_tr('common.errors.service_unavailable'));}
    exit;
}
if ($path === '/support/wechat') {
    try {
        $db = D2S\repositories\Database::fromEnvironment();
        $siteData = (new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
    } catch (Throwable) {
        $siteData = [];
    }
    $wechatUrl = trim((string)($siteData['site']['support_wechat_url'] ?? ''));
    $wechatParts = parse_url($wechatUrl);
    $wechatSafe = strlen($wechatUrl) <= 2048 && filter_var($wechatUrl,FILTER_VALIDATE_URL) !== false &&
        str_starts_with($wechatUrl,'https://work.weixin.qq.com/') && is_array($wechatParts) &&
        ($wechatParts['host'] ?? null) === 'work.weixin.qq.com' &&
        !isset($wechatParts['user']) && !isset($wechatParts['pass']) &&
        !str_contains($wechatUrl,'\\') && preg_match('/[\x00-\x1F\x7F]/',$wechatUrl) !== 1;
    if (!$wechatSafe) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo d2s_tr('common.errors.wechat_support_unavailable');
        exit;
    }
    header('Location: ' . $wechatUrl, true, 303);
    exit;
}
$announcementEditorRoute = preg_match('#^/admin/content/announcements/(new|[a-f0-9]{16})$#D', $path, $announcementEditorMatch) === 1;
if ($announcementEditorRoute || in_array($path, ['/profile','/profile/password','/profile/contact','/shop','/wallet','/license','/notifications','/feedback','/tickets','/consent','/admin/notifications','/admin','/admin/','/admin/settings/smtp','/admin/settings/ses','/admin/settings/sms','/admin/settings/email','/admin/settings/delivery','/admin/settings/alipay','/admin/settings/wechat','/admin/orders','/admin/bills','/admin/tickets','/admin/audit','/admin/accounts','/admin/content','/admin/content/site','/admin/content/announcements','/admin/content/policies'], true)) {
    $realm = in_array($path, ['/profile','/profile/password','/profile/contact','/shop','/wallet','/license','/notifications','/feedback','/tickets','/consent'], true) ? 'user' : 'admin';
    $cookieName = $realm === 'user' ? 'D2S_USER_SESSION' : 'D2S_ADMIN_SESSION';
    $loginLocation = $realm === 'user' ? '/login' : '/admin/login';
    $shopProduct = 'desktop2stereo';
    $requestedProduct = $_GET['product'] ?? null;
    if ($path==='/shop' && is_string($requestedProduct) && preg_match('/^[a-z][a-z0-9_-]{2,31}$/D',$requestedProduct) && $requestedProduct!=='wallet_recharge') {
        $shopProduct = $requestedProduct;
    }
    $shopTarget = $path==='/shop' && $shopProduct!=='desktop2stereo' ? '/shop?product='.rawurlencode($shopProduct) : $path;
    if ($realm === 'user' && in_array($path, ['/feedback','/feedback/new','/shop'], true)) {
        $loginLocation .= '?next=' . rawurlencode($shopTarget);
    }
    if (empty($_COOKIE[$cookieName])) {
        header('Location: ' . $loginLocation, true, 303);
        exit;
    }
    try {
        $db = D2S\repositories\Database::fromEnvironment();
        $owner = D2S\services\HttpRequest::session($realm, new D2S\services\WebSessions($db), false, time(),
            $realm === 'user' && $path === '/consent');
        if ($realm === 'admin') {
            $query = $db->prepare('SELECT permission FROM portal_admin_permissions WHERE admin_id=?');
            $query->execute([$owner['id']]);
            $permissions = $query->fetchAll(PDO::FETCH_COLUMN);
        }
        if (in_array($path, ['/notifications','/admin/notifications'], true)) {
            if ($realm === 'admin') D2S\services\Permissions::require($db,$owner['id'],'notifications.manage');
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/notifications.php';
            exit;
        }
        if ($realm === 'user') {
            $siteData = (new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
            if ($path === '/consent') {
                header('Content-Type: text/html; charset=utf-8');
                require D2S_ROOT . '/template/consent.php';
                exit;
            }
            if ($path === '/feedback') {
                $uiNamespaces = ['support', 'feedback'];
                $legacyDomTranslation = false;
                header('Content-Type: text/html; charset=utf-8');
                require D2S_ROOT . '/template/feedback.php';
                exit;
            }
            if ($path === '/tickets') {
                $uiNamespaces = ['support'];
                $legacyDomTranslation = false;
                header('Content-Type: text/html; charset=utf-8');
                require D2S_ROOT . '/template/support-tickets.php';
                exit;
            }
            if ($path === '/license') {
                header('Content-Type: text/html; charset=utf-8');
                require D2S_ROOT . '/template/license.php';
                exit;
            }
            $secrets = D2S\services\SecretBox::fromEnvironment();
            $deliveryConfig = (new D2S\services\DeliverySettings($db, $secrets))->load();
            $verification = new D2S\services\VerificationService($db, $secrets, $deliveryConfig);
            $profile = (new D2S\services\AccountService($db, $verification, $deliveryConfig))->profile($owner['id']);
        }
        if (in_array($path, ['/shop','/wallet'], true)) {
            $productPresentation = $path === '/shop'
                ? (new D2S\services\ProductTranslations($db))->publicContent($shopProduct, d2s_locale_code())
                : null;

            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/commerce.php';
            exit;
        }
        if ($path === '/admin/bills') {
            D2S\services\Permissions::require($db,$owner['id'],'bills.read');
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/bills.php';
            exit;
        }
        if ($path === '/admin/tickets') {
            D2S\services\Permissions::require($db,$owner['id'],'tickets.read');
            $uiNamespaces = ['support'];
            $legacyDomTranslation = false;
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/admin-tickets.php';
            exit;
        }
        if ($path === '/admin/audit') {
            D2S\services\Permissions::require($db,$owner['id'],'audit.read');
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/audit.php';
            exit;
        }
        if ($path === '/admin/accounts') {
            if (array_intersect(['users.read','accounts.restrict'],$permissions) === []) {
                throw new D2S\domain\Problem('permission_denied',403);
            }
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/account-controls.php';
            exit;
        }
        if ($path === '/admin/content') {
            header('Location: /admin/content/site', true, 303);
            exit;
        }
        $contentAdminPages = [
            '/admin/content/site' => ['section'=>'site','permissions'=>['settings.manage']],
            '/admin/content/announcements' => ['section'=>'announcements','permissions'=>['announcements.manage']],
            '/admin/content/policies' => ['section'=>'policies','permissions'=>['settings.manage']],
        ];
        if ($announcementEditorRoute) $contentAdminPages[$path] = ['section'=>'announcements','permissions'=>['announcements.manage']];
        if (isset($contentAdminPages[$path])) {
            if ($path === '/admin/content/policies' && !(new D2S\services\PolicyTranslations($db))->isAvailable()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
                echo d2s_tr('common.errors.policy_translation_storage_unavailable');
                exit;
            }
            D2S\services\Permissions::requireAny($db,$owner['id'],$contentAdminPages[$path]['permissions']);
            $contentSection = $contentAdminPages[$path]['section'];
            $announcementView = $announcementEditorRoute ? 'editor' : 'list';
            $announcementEditId = $announcementEditorRoute ? $announcementEditorMatch[1] : '';
            if ($announcementEditorRoute && $announcementEditId !== 'new') {
                $exists = $db->prepare('SELECT id FROM portal_announcements WHERE id=? AND deleted_at IS NULL');
                $exists->execute([$announcementEditId]);
                if ($exists->fetchColumn() === false) {
                    $renderNotFound('common.not_found.description', $db, $siteData ?? null);
                    exit;
                }
            }
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/content.php';
            exit;
        }
        if ($path === '/admin/orders') {
            D2S\services\Permissions::require($db,$owner['id'],'orders.read');
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/payment-orders.php';
            exit;
        }
        if ($path === '/admin/settings/wechat') {
            $configuration = (new D2S\services\WechatSettings($db, D2S\services\SecretBox::fromEnvironment()))
                ->view($owner['id'], D2S\services\Audit::context($_SERVER), time());
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/wechat-settings.php';
            exit;
        }
        if ($path === '/admin/settings/alipay') {
            $configuration = (new D2S\services\AlipaySettings($db, D2S\services\SecretBox::fromEnvironment()))
                ->view($owner['id'], D2S\services\Audit::context($_SERVER), time());
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/alipay-settings.php';
            exit;
        }
        if (in_array($path, ['/admin/settings/delivery','/admin/settings/email'], true)) {
            header('Location: /admin/settings/smtp', true, 303);
            exit;
        }
        if (in_array($path, ['/admin/settings/smtp','/admin/settings/ses','/admin/settings/sms'], true)) {
            $deliverySection = match ($path) {
                '/admin/settings/ses' => 'ses',
                '/admin/settings/sms' => 'sms',
                default => 'smtp',
            };
            $configuration = (new D2S\services\DeliverySettings($db, D2S\services\SecretBox::fromEnvironment()))
                ->view($owner['id'], D2S\services\Audit::context($_SERVER), time());
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/delivery-settings.php';
            exit;
        }
        $profileSections = [
            '/profile' => 'basic',
            '/profile/password' => 'password',
            '/profile/contact' => 'contact',
        ];
        if ($realm === 'user' && isset($profileSections[$path])) {
            if ($path !== '/profile') {
                header('Location: '.d2s_url('/profile?section='.$profileSections[$path]), true, 303);
                exit;
            }
            header('Content-Type: text/html; charset=utf-8');
            require D2S_ROOT . '/template/profile.php';
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        require D2S_ROOT . ($realm === 'user' ? '/template/profile.php' : '/template/admin.php');
    } catch (D2S\domain\Problem $error) {
        if ($error->error === 'legal_reconsent_required') {
            $consentTarget = $path==='/shop' ? '/consent?next='.rawurlencode($shopTarget) : '/consent';
            header('Location: '.d2s_url($consentTarget), true, 303);
            exit;
        }
        if ($error->httpStatus === 403) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo d2s_escape(d2s_tr('common.errors.access_denied'));
            exit;
        }
        header('Location: ' . ($realm === 'user' ? $loginLocation : '/admin/login'), true, 303);
    } catch (Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo d2s_escape(d2s_tr('common.errors.service_unavailable'));
    }
    exit;
}
$legalPages = [
    '/privacy-policy'=>'privacy-policy.php','/business-license'=>'business-license.php',
    '/terms'=>'terms.php','/legal-statement'=>'legal-statement.php',
    '/about'=>'about.php','/contact'=>'contact.php','/account-recovery'=>'account-recovery.php',
];
if (isset($legalPages[$path])) {
    $policyTranslation = null;
    $policyRequiresPublishedContent = false;
    $seoLocales = array_column((new D2S\services\LocaleRegistry())->enabled(true),'code');
    $seoLocale = d2s_locale_code();
    $policyType = ['/privacy-policy'=>'PRIVACY','/terms'=>'TERMS','/legal-statement'=>'LEGAL'][$path] ?? null;
    if ($policyType !== null) {
        $enabledPublicLocales = array_column((new D2S\services\LocaleRegistry())->enabled(true),'code');
        $seoLocales = array_values(array_intersect(['zh-Hans','en'],$enabledPublicLocales));
        $seoLocale = d2s_language()==='en'?'en':'zh-Hans';
    }
    try {
        $db = D2S\repositories\Database::fromEnvironment();
        $siteData = (new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
        if ($policyType !== null) {
            $policyService = new D2S\services\PolicyTranslations($db);
            if ($policyService->isAvailable()) {
                $policyRequiresPublishedContent = true;
                $policyTranslation = $policyService->publicContent($policyType,d2s_locale_code());
                $publishedLocales = $policyService->publishedLocales($policyType);
                if ($publishedLocales !== []) $seoLocales = $publishedLocales;
            }
            $seoLocale = is_array($policyTranslation) ? (string)$policyTranslation['locale'] : (d2s_language()==='en'?'en':'zh-Hans');
        }
    } catch (Throwable) {
        $policyTranslation = null;
        $siteData = ['site'=>D2S\services\SiteContent::defaultSite(),'navigation'=>D2S\services\SiteContent::defaultNavigation(),'announcements'=>[]];
    }
    if ($policyRequiresPublishedContent && $policyTranslation === null) {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        echo d2s_escape(d2s_tr('common.errors.service_unavailable'));
        exit;
    }
    $realm = 'public';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Language: '.d2s_locale_code());
    ob_start();
    require D2S_ROOT . '/template/' . $legalPages[$path];
    $publicBody = ob_get_clean();
    $policyContentMatchesRequest = $policyType === null || (is_array($policyTranslation)
        && ($policyTranslation['locale'] ?? null) === d2s_locale_code());
    if ($policyContentMatchesRequest) $enablePublicPageCache();
    echo $publicBody;
    exit;
}
if ($path !== '/') {
    if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
        && !$reservedRequestPath && !($localizedPrefix !== null && $reservedPrefixPath)
        && !preg_match('#^/product-images(?:/|$)#', $notFoundPath)) {
        $renderNotFound('common.not_found.description');
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo d2s_escape(d2s_tr('common.errors.page_not_found'));
    }
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Content-Language: '.d2s_locale_code());
$realm = 'public';
try {
    $db = D2S\repositories\Database::fromEnvironment();
    $siteData = (new D2S\services\SiteContent($db))->publicData(d2s_locale_code());
} catch (Throwable) {
    $siteData = ['site'=>D2S\services\SiteContent::defaultSite(),'navigation'=>D2S\services\SiteContent::defaultNavigation(),'announcements'=>[]];
}
$release = D2S\services\ReleaseInfo::fromEnvironment()->current($siteData['site']);
ob_start();
require D2S_ROOT . '/template/home.php';
$publicBody = ob_get_clean();
$enablePublicPageCache();
echo $publicBody;
