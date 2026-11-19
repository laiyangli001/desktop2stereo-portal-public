<?php
declare(strict_types=1);

define('D2S_ROOT', dirname(__DIR__, 2));
date_default_timezone_set('UTC');
ini_set('display_errors', '0');

if (is_file(D2S_ROOT . '/vendor/autoload.php')) {
    require_once D2S_ROOT . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'D2S\\')) {
        return;
    }
    $relative = substr($class, 4);
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_\\\\]*$/D', $relative)) {
        return;
    }
    $file = D2S_ROOT . '/includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

D2S\services\LocalConfiguration::load();

function d2s_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function d2s_asset(string $path): string
{
    if (!preg_match('#^/assets/[A-Za-z0-9/_.-]+$#D', $path) || str_contains($path, '..')) {
        throw new RuntimeException('asset_path_invalid');
    }
    $root = realpath(D2S_ROOT.'/public/assets');
    $file = realpath(D2S_ROOT.'/public'.$path);
    if ($root === false || $file === false || !str_starts_with($file, $root.DIRECTORY_SEPARATOR) || !is_file($file)) {
        throw new RuntimeException('asset_unavailable');
    }
    $revision = hash_file('sha256', $file);
    if (!is_string($revision)) throw new RuntimeException('asset_unavailable');
    return $path.'?v='.$revision;
}

function d2s_language(): string
{
    return d2s_locale_code() === 'zh-Hans' ? 'zh' : 'en';
}

function d2s_locale(): string
{
    return d2s_locale_code();
}

function d2s_locale_context(): array
{
    static $context = null;
    if ($context !== null) {
        return $context;
    }
    $registry = new D2S\services\LocaleRegistry();
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $isApi = str_starts_with($path, '/api/v2/');
    $isPrivate = preg_match('#^/(?:admin|user|account|dashboard|profile|tickets|wallet|license|notifications|feedback|consent)(?:/|$)#', $path) === 1;
    $accountPreference = null;
    if ($isPrivate && !$isApi) {
        $admin = preg_match('#^/admin(?:/|$)#', $path) === 1;
        $normalize = fn(string $locale): ?string => $admin
            ? $registry->normalizeForAdmin($locale)
            : $registry->normalize($locale, true);
        $explicit = $normalize((string)($_GET['lang'] ?? ''));
        $cookie = $normalize((string)($_COOKIE['D2S_LOCALE'] ?? $_COOKIE['D2S_LANG'] ?? ''));
        if ($explicit === null && $cookie === null) {
            $accountPreference = d2s_account_locale_preference($admin);
        }
    }
    $context = (new D2S\services\LocaleContext($registry))->resolve($_SERVER, $_GET, $_COOKIE, $accountPreference);
    if (($context['source'] ?? '') === 'query') {
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $configuredUrl = getenv('D2S_APP_URL') ?: '';
        $secure = $https || ($configuredUrl !== '' && str_starts_with($configuredUrl, 'https://'));
        setcookie('D2S_LOCALE', $context['locale'], [
            'path'=>'/', 'secure'=>$secure, 'httponly'=>false, 'samesite'=>'Lax',
            'expires'=>time()+365*86400,
        ]);
    }
    return $context;
}

function d2s_account_locale_preference(bool $admin): ?string
{
    $realm = $admin ? 'admin' : 'user';
    $cookieName = $admin ? 'D2S_ADMIN_SESSION' : 'D2S_USER_SESSION';
    $token = $_COOKIE[$cookieName] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return null;
    }
    try {
        $db = D2S\repositories\Database::fromEnvironment();
        $owner = (new D2S\services\WebSessions($db))
            ->authenticate($realm, $token, null, false, time(), true);
        $table = $admin ? 'portal_admins' : 'portal_users';
        $query = $db->prepare("SELECT preferred_locale FROM $table WHERE id=?");
        $query->execute([$owner['id']]);
        $locale = $query->fetchColumn();
        return is_string($locale) && trim($locale) !== '' ? $locale : null;
    } catch (Throwable) {
        return null;
    }
}

function d2s_locale_code(): string
{
    return (string)d2s_locale_context()['locale'];
}

function d2s_tr(string $key, array $parameters = []): string
{
    static $translators = [];
    $locale = d2s_locale_code();
    $translators[$locale] ??= new D2S\services\UiTranslator(new D2S\services\LocaleRegistry(), $locale);
    return $translators[$locale]->translate($key, $parameters);
}

function d2s_url(string $url, ?string $locale = null): string
{
    $registry = new D2S\services\LocaleRegistry();
    return (new D2S\services\LocaleUrl($registry))->build($url, $locale ?? d2s_locale_code());
}

function d2s_language_url(string $language): string
{
    $registry = new D2S\services\LocaleRegistry();
    $code = $registry->normalize($language, true) ?? $registry->defaultLocale();
    return (new D2S\services\LocaleUrl($registry))->build((string)($_SERVER['REQUEST_URI'] ?? '/'), $code);
}
