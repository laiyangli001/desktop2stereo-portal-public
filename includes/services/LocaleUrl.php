<?php
declare(strict_types=1);

namespace D2S\services;

final class LocaleUrl
{
    private const UNLOCALIZED_PREFIXES = [
        'api', 'assets', 'uploads', 'downloads', 'product-images', 'alipay', 'oauth', 'webhook', 'native',
    ];
    private const PRIVATE_PREFIXES = ['admin', 'user', 'account', 'dashboard', 'profile', 'tickets', 'wallet', 'license', 'notifications', 'feedback', 'consent'];

    public function __construct(private readonly LocaleRegistry $registry)
    {
    }

    public function build(string $url, string $locale): string
    {
        if ($url === '' || str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !str_starts_with((string)($parts['path'] ?? ''), '/')) {
            return $url;
        }
        $path = (string)$parts['path'];
        $code = $this->registry->normalize($locale, true) ?? $this->registry->defaultLocale();
        $segments = explode('/', trim($path, '/'));
        $currentPrefix = $this->registry->fromPrefix($segments[0] ?? '', true);
        if ($currentPrefix !== null) {
            $path = '/' . implode('/', array_slice($segments, 1));
            if ($path === '') $path = '/';
            $segments = explode('/', trim($path, '/'));
        }
        $first = $segments[0] ?? '';
        if (in_array(strtolower($first), self::UNLOCALIZED_PREFIXES, true)) {
            return $this->render($path, $parts);
        }
        if (in_array(strtolower($first), self::PRIVATE_PREFIXES, true)) {
            $query = $this->query($parts['query'] ?? '');
            if ($code === $this->registry->defaultLocale()) {
                unset($query['lang']);
            } else {
                $query['lang'] = $code;
            }
            return $this->render($path, $parts, $query);
        }
        $prefix = (string)($this->registry->get($code)['url_prefix'] ?? '');
        $localizedPath = '/' . $prefix . ($path === '/' ? '/' : $path);
        $query = $this->query($parts['query'] ?? '');
        unset($query['lang']);
        return $this->render($localizedPath, $parts, $query);
    }

    private function render(string $path, array $parts, ?array $query = null): string
    {
        $query ??= $this->query($parts['query'] ?? '');
        $result = $path;
        if ($query !== []) {
            $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }
        return $result;
    }

    private function query(string $query): array
    {
        parse_str($query, $values);
        return is_array($values) ? $values : [];
    }
}
