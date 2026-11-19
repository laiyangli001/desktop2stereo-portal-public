<?php
declare(strict_types=1);

namespace D2S\services;

final class LocaleContext
{
    public function __construct(private readonly LocaleRegistry $registry)
    {
    }

    public function resolve(array $server, array $query, array $cookies, ?string $accountPreference = null): array
    {
        $path = parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/api/v2/')) {
            $requested = is_string($server['HTTP_X_D2S_LOCALE'] ?? null) ? $server['HTTP_X_D2S_LOCALE'] : '';
            $locale = str_starts_with($path, '/api/v2/admin/')
                ? $this->registry->normalizeForAdmin($requested)
                : $this->registry->normalize($requested, true);
            return $this->result($locale ?? $this->registry->defaultLocale(), $locale === null ? 'default' : 'header', false);
        }
        $segments = explode('/', trim($path, '/'));
        $prefixedPath = '/' . implode('/', array_slice($segments, 1));
        $isPrivatePrefixedPath = preg_match('#^/(?:admin|user|account|dashboard|profile|tickets|wallet|license|notifications|feedback|consent)(?:/|$)#', $prefixedPath) === 1;
        $prefixLocale = isset($segments[0]) && !$isPrivatePrefixedPath
            ? $this->registry->fromPrefix($segments[0], true) : null;
        if ($prefixLocale !== null) {
            return $this->result($prefixLocale, 'url', true);
        }

        $private = preg_match('#^/(?:admin|user|account|dashboard|profile|tickets|wallet|license|notifications|feedback|consent)(?:/|$)#', $path) === 1;
        $admin = preg_match('#^/admin(?:/|$)#', $path) === 1;
        $invitedRegistration = $path === '/register' && is_string($query['ref'] ?? null)
            && ReferralProgram::isValidCode($query['ref']);
        if (!$private && !$invitedRegistration) {
            $legacy = $this->legacyQueryLocale($query['lang'] ?? null);
            return $this->result($legacy ?? $this->registry->defaultLocale(), $legacy === null ? 'default' : 'query', false);
        }

        $normalize = fn(string $value): ?string => $admin
            ? $this->registry->normalizeForAdmin($value)
            : $this->registry->normalize($value, true);
        $explicit = is_string($query['lang'] ?? null) ? $normalize($query['lang']) : null;
        if ($explicit !== null) {
            return $this->result($explicit, 'query', false);
        }
        $cookie = $normalize((string)($cookies['D2S_LOCALE'] ?? $cookies['D2S_LANG'] ?? ''));
        if ($cookie !== null) {
            return $this->result($cookie, 'cookie', false);
        }
        $preferred = $accountPreference === null ? null : $normalize($accountPreference);
        if ($preferred !== null) {
            return $this->result($preferred, 'account', false);
        }
        $accept = $this->acceptLanguage((string)($server['HTTP_ACCEPT_LANGUAGE'] ?? ''), $admin);
        return $this->result($accept ?? $this->registry->defaultLocale(), $accept === null ? 'default' : 'accept-language', false);
    }

    private function result(string $locale, string $source, bool $urlAuthoritative): array
    {
        $definition = $this->registry->get($locale);
        return [
            'locale' => $locale,
            'html_lang' => str_replace('_', '-', $locale),
            'direction' => $definition['direction'] ?? 'ltr',
            'source' => $source,
            'url_authoritative' => $urlAuthoritative,
        ];
    }

    private function legacyQueryLocale(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return $this->registry->normalize($value, true);
    }

    private function acceptLanguage(string $header, bool $adminOnly): ?string
    {
        $ranked = [];
        $normalize = fn(string $value): ?string => $adminOnly
            ? $this->registry->normalizeForAdmin($value)
            : $this->registry->normalize($value, true);
        foreach (explode(',', $header) as $position => $item) {
            $parts = explode(';', trim($item));
            $tag = trim((string)array_shift($parts));
            if ($tag === '' || $tag === '*') {
                continue;
            }

            $q = 1.0;
            $qualitySeen = false;
            $invalid = false;
            foreach ($parts as $parameter) {
                $parameter = trim($parameter);
                if (!preg_match('/\\Aq\\s*=\\s*(0(?:\\.\\d{0,3})?|1(?:\\.0{0,3})?)\\z/i', $parameter, $matches)
                    || $qualitySeen) {
                    $invalid = true;
                    break;
                }
                $qualitySeen = true;
                $q = (float)$matches[1];
            }
            if ($invalid || $q <= 0) {
                continue;
            }

            $normalized = $normalize($tag);
            if ($normalized === null) {
                $primary = explode('-', str_replace('_', '-', $tag))[0] ?? '';
                $normalized = $normalize($primary);
            }
            if ($normalized !== null) {
                $ranked[] = ['locale' => $normalized, 'q' => $q, 'position' => $position];
            }
        }
        usort($ranked, static fn(array $a, array $b): int =>
            ($b['q'] <=> $a['q']) ?: ($a['position'] <=> $b['position']));
        return $ranked[0]['locale'] ?? null;
    }
}
