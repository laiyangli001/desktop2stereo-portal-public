<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

final class LocaleRegistry
{
    private array $manifest;
    private array $byCode = [];
    private array $byPrefix = [];
    private array $aliases = [];

    public static function isValidCode(string $code): bool
    {
        if ($code === '' || strlen($code) > 35 || preg_match('/[^A-Za-z0-9-]/D', $code)) return false;
        $grandfathered = [
            'art-lojban','cel-gaulish','en-gb-oed','i-ami','i-bnn','i-default','i-enochian','i-hak',
            'i-klingon','i-lux','i-mingo','i-navajo','i-pwn','i-tao','i-tay','i-tsu','no-bok',
            'no-nyn','sgn-be-fr','sgn-be-nl','sgn-ch-de','zh-guoyu','zh-hakka','zh-min',
            'zh-min-nan','zh-xiang',
        ];
        if (in_array(strtolower($code), $grandfathered, true)) return true;
        $parts = explode('-', $code);
        if (in_array('', $parts, true)) return false;
        $count = count($parts);
        $index = 0;
        $isAlpha = static fn(string $value): bool => preg_match('/^[A-Za-z]+$/D', $value) === 1;
        $isAlnum = static fn(string $value): bool => preg_match('/^[A-Za-z0-9]+$/D', $value) === 1;
        if (strtolower($parts[0]) === 'x') {
            $index = 1;
            if ($index >= $count) return false;
            for (; $index < $count; $index++) {
                if (strlen($parts[$index]) > 8 || !$isAlnum($parts[$index])) return false;
            }
            return true;
        }
        $languageLength = strlen($parts[0]);
        if ($languageLength >= 2 && $languageLength <= 3 && $isAlpha($parts[0])) {
            $index = 1;
            for ($extlang = 0; $extlang < 3 && $index < $count
                && strlen($parts[$index]) === 3 && $isAlpha($parts[$index]); $extlang++) {
                $index++;
            }
        } elseif (($languageLength === 4 || ($languageLength >= 5 && $languageLength <= 8)) && $isAlpha($parts[0])) {
            $index = 1;
        } else {
            return false;
        }
        if ($index < $count && strlen($parts[$index]) === 4 && $isAlpha($parts[$index])) $index++;
        if ($index < $count && (preg_match('/^[A-Za-z]{2}$/D', $parts[$index]) === 1
            || preg_match('/^[0-9]{3}$/D', $parts[$index]) === 1)) $index++;
        $variants = [];
        while ($index < $count && (preg_match('/^[A-Za-z0-9]{5,8}$/D', $parts[$index]) === 1
            || preg_match('/^[0-9][A-Za-z0-9]{3}$/D', $parts[$index]) === 1)) {
            $variant = strtolower($parts[$index]);
            if (isset($variants[$variant])) return false;
            $variants[$variant] = true;
            $index++;
        }
        $extensions = [];
        while ($index < $count && preg_match('/^[0-9A-WY-Z]$/iD', $parts[$index]) === 1) {
            $singleton = strtolower($parts[$index]);
            if (isset($extensions[$singleton])) return false;
            $extensions[$singleton] = true;
            $index++;
            $start = $index;
            while ($index < $count && strlen($parts[$index]) >= 2 && strlen($parts[$index]) <= 8
                && $isAlnum($parts[$index])) $index++;
            if ($index === $start) return false;
        }
        if ($index < $count && strtolower($parts[$index]) === 'x') {
            $index++;
            $start = $index;
            while ($index < $count && strlen($parts[$index]) <= 8 && $isAlnum($parts[$index])) $index++;
            if ($index === $start) return false;
        }
        return $index === $count;
    }

    public function __construct(?string $manifestPath = null, ?array $manifestData = null)
    {
        if ($manifestPath === null && $manifestData === null) {
            $publishedPath = self::snapshotPath();
            if (is_file($publishedPath)) {
                try {
                    $published = new self($publishedPath);
                    $this->manifest = $published->manifest;
                    $this->byCode = $published->byCode;
                    $this->byPrefix = $published->byPrefix;
                    $this->aliases = $published->aliases;
                    return;
                } catch (RuntimeException) {
                    // A damaged snapshot must not prevent access to the login page.
                }
            }
            $manifestPath = D2S_ROOT . '/resources/locales/manifest.json';
        }
        if ($manifestData !== null) {
            $manifest = $manifestData;
        } else {
            $raw = @file_get_contents((string)$manifestPath);
            if (!is_string($raw)) throw new RuntimeException('locale_registry_unavailable');
            try {
                $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new RuntimeException('locale_registry_invalid', 0, $error);
            }
        }
        if (!is_array($manifest) || !is_array($manifest['locales'] ?? null)) {
            throw new RuntimeException('locale_registry_invalid');
        }
        $this->manifest = $manifest;
        foreach ($manifest['locales'] as $locale) {
            if (!is_array($locale)) throw new RuntimeException('locale_registry_invalid');
            $code = $locale['code'] ?? '';
            $prefix = $locale['url_prefix'] ?? '';
            if (!is_string($code) || !self::isValidCode($code)
                || !is_string($prefix) || !preg_match('/^[a-z0-9-]{2,32}$/D', $prefix)
                || isset($this->byCode[$code]) || isset($this->byPrefix[$prefix])) {
                throw new RuntimeException('locale_registry_invalid');
            }
            if (!is_string($locale['native_name'] ?? null) || trim($locale['native_name']) === ''
                || !in_array($locale['direction'] ?? null, ['ltr','rtl'], true)
                || !is_bool($locale['enabled'] ?? null) || !is_bool($locale['public_enabled'] ?? null)
                || (isset($locale['admin_enabled']) && !is_bool($locale['admin_enabled']))
                || !is_array($locale['fallback_locales'] ?? null) || !array_is_list($locale['fallback_locales'])
                || !is_array($locale['aliases'] ?? null) || !array_is_list($locale['aliases'])) {
                throw new RuntimeException('locale_registry_invalid');
            }
            if (isset($this->aliases[strtolower($code)]) && $this->aliases[strtolower($code)] !== $code) {
                throw new RuntimeException('locale_alias_conflict');
            }
            $this->byCode[$code] = $locale;
            $this->byPrefix[$prefix] = $code;
            $this->aliases[strtolower($code)] = $code;
            foreach ($locale['aliases'] as $alias) {
                if (!is_string($alias) || !preg_match('/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/D', $alias)) {
                    throw new RuntimeException('locale_alias_invalid');
                }
                $normalized = strtolower(str_replace('_', '-', $alias));
                if (isset($this->aliases[$normalized]) && $this->aliases[$normalized] !== $code) {
                    throw new RuntimeException('locale_alias_conflict');
                }
                $this->aliases[$normalized] = $code;
            }
        }
        if (!$this->isEnabled((string)($manifest['default_locale'] ?? ''))) {
            throw new RuntimeException('locale_default_invalid');
        }
        $default = $this->byCode[(string)$manifest['default_locale']];
        if (!(bool)($default['public_enabled'] ?? false)
            || !(bool)($default['admin_enabled'] ?? $default['enabled'] ?? false)) {
            throw new RuntimeException('locale_default_invalid');
        }
        $complete = [];
        foreach (array_keys($this->byCode) as $code) {
            if ($this->hasFallbackCycle($code, [], $complete)) {
                throw new RuntimeException('locale_fallback_invalid');
            }
        }
    }

    public static function snapshotPath(): string
    {
        $directory = getenv('D2S_LOCALE_SNAPSHOT_DIR');
        return rtrim(is_string($directory) && $directory !== '' ? $directory : D2S_ROOT . '/storage/locales', '/\\') . '/published.json';
    }

    public function catalogRevision(): ?string
    {
        $revision = $this->manifest['catalog_revision'] ?? null;
        return is_string($revision) && preg_match('/^[a-f0-9]{64}$/D', $revision) ? $revision : null;
    }

    public function catalogBundlePath(): ?string
    {
        $revision = $this->catalogRevision();
        return $revision === null ? null : dirname(self::snapshotPath()).'/catalogs/'.$revision.'.json';
    }

    public function normalize(string $locale, bool $enabledOnly = false): ?string
    {
        $code = $this->aliases[strtolower(str_replace('_', '-', trim($locale)))] ?? null;
        return $code !== null && (!$enabledOnly || $this->isEnabled($code)) ? $code : null;
    }

    public function get(string $code): ?array
    {
        return $this->byCode[$code] ?? null;
    }

    public function fromPrefix(string $prefix, bool $publicOnly = false): ?string
    {
        $code = $this->byPrefix[strtolower($prefix)] ?? null;
        if ($code === null || !$this->isEnabled($code) || ($publicOnly && !($this->byCode[$code]['public_enabled'] ?? false))) {
            return null;
        }
        return $code;
    }

    public function defaultLocale(): string
    {
        return (string)$this->manifest['default_locale'];
    }

    public function fallbacks(string $code): array
    {
        $locale = $this->byCode[$code] ?? $this->byCode[$this->defaultLocale()];
        $values = $locale['fallback_locales'] ?? $this->manifest['fallback_locales'] ?? [];
        $result = [];
        foreach ($values as $value) {
            $normalized = $this->normalize((string)$value, true);
            if ($normalized !== null && $normalized !== $code && !in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    public function enabled(bool $publicOnly = false): array
    {
        return array_values(array_filter($this->byCode, fn(array $locale): bool =>
            $this->isEnabled((string)$locale['code']) && (!$publicOnly || (bool)($locale['public_enabled'] ?? false))
        ));
    }

    public function enabledForAdmin(): array
    {
        return array_values(array_filter($this->byCode, fn(array $locale): bool =>
            $this->isEnabled((string)$locale['code']) && (bool)($locale['admin_enabled'] ?? $locale['enabled'] ?? false)
        ));
    }

    public function normalizeForAdmin(string $locale): ?string
    {
        $code = $this->normalize($locale, true);
        return $code !== null && (bool)($this->byCode[$code]['admin_enabled'] ?? $this->byCode[$code]['enabled'] ?? false) ? $code : null;
    }

    public function all(): array
    {
        return array_values($this->byCode);
    }

    private function isEnabled(string $code): bool
    {
        return isset($this->byCode[$code]) && (bool)($this->byCode[$code]['enabled'] ?? false);
    }

    private function hasFallbackCycle(string $code, array $path, array &$complete): bool
    {
        if (isset($path[$code])) {
            return true;
        }
        if (isset($complete[$code])) {
            return false;
        }
        $path[$code] = true;
        $fallbacks = $this->byCode[$code]['fallback_locales'] ?? [];
        if (!is_array($fallbacks)) {
            throw new RuntimeException('locale_fallback_invalid');
        }
        foreach ($fallbacks as $fallback) {
            $target = is_string($fallback) ? $this->normalize($fallback) : null;
            if ($target === null || $this->hasFallbackCycle($target, $path, $complete)) {
                return true;
            }
        }
        $complete[$code] = true;
        return false;
    }
}
