<?php
declare(strict_types=1);

namespace D2S\services;

final readonly class PublicSeoUrls
{
    public function __construct(private LocaleRegistry $locales)
    {
    }

    public function url(string $path, string $locale): string
    {
        $origin = $this->origin();
        $code = $this->locales->normalize($locale, true) ?? $this->locales->defaultLocale();
        $path = $this->unprefixedPath($path);
        if ($code !== $this->locales->defaultLocale()) {
            $prefix = (string)($this->locales->get($code)['url_prefix'] ?? '');
            $path = '/' . $prefix . ($path === '/' ? '/' : $path);
        }
        return $origin . $path;
    }

    public function alternates(string $path, array $locales): array
    {
        $result = [];
        $publicCodes = array_column($this->locales->enabled(true), 'code');
        foreach (array_values(array_unique($locales)) as $locale) {
            if (!is_string($locale) || !in_array($locale, $publicCodes, true)) continue;
            $result[$locale] = $this->url($path, $locale);
        }
        $default = $this->locales->defaultLocale();
        if ($result !== []) $result['x-default'] = $result[$default] ?? reset($result);
        return $result;
    }

    private function unprefixedPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/') $path = '/' . $path;
        $segments = explode('/', trim($path, '/'));
        $prefixLocale = $this->locales->fromPrefix($segments[0] ?? '', true);
        if ($prefixLocale !== null) {
            $path = '/' . implode('/', array_slice($segments, 1));
            if ($path === '') $path = '/';
        }
        return $path;
    }

    private function origin(): string
    {
        $configured = trim((string)(getenv('D2S_APP_URL') ?: ''));
        $parts = parse_url($configured);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http','https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            return 'https://d2s.site';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return strtolower((string)$parts['scheme']) . '://' . strtolower($parts['host']) . $port;
    }
}
