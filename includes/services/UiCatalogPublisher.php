<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;
use PDOException;
use RuntimeException;

final class UiCatalogPublisher
{
    public function __construct(private readonly PDO $db, private readonly ?string $root = null)
    {
    }

    public function revision(array $manifest): string
    {
        return self::revisionOf($this->resources($manifest));
    }

    public function publish(array $manifest): string
    {
        $resources = $this->resources($manifest);
        $this->assertComplete($manifest, $resources);
        $revision = self::revisionOf($resources);
        $root = dirname(LocaleRegistry::snapshotPath()).'/catalogs';
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new Problem('locale_snapshot_publish_failed', 503);
        }
        $lock = @fopen($root.'/.publish.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new Problem('locale_snapshot_publish_failed', 503);
        }
        $temporary = null;
        try {
            $path = $root.'/'.$revision.'.json';
            if (is_file($path)) {
                if (self::readFile($path, $revision) !== null) return $revision;
                if (!@unlink($path)) throw new Problem('locale_snapshot_publish_failed', 503);
            }
            $payload = json_encode(
                ['revision'=>$revision,'resources'=>$resources],
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
            )."\n";
            $temporary = $root.'/'.$revision.'.'.bin2hex(random_bytes(8)).'.tmp';
            if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload) || !@rename($temporary, $path)) {
                throw new Problem('locale_snapshot_publish_failed', 503);
            }
            $temporary = null;
            return $revision;
        } catch (RuntimeException|\JsonException $error) {
            if ($error instanceof Problem) throw $error;
            throw new Problem('locale_snapshot_publish_failed', 503);
        } finally {
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function pruneRevisions(array $keep): void
    {
        $directory = dirname(LocaleRegistry::snapshotPath()).'/catalogs';
        $lock = @fopen($directory.'/.publish.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            error_log('Unable to lock locale catalog storage for retention');
            return;
        }
        try {
            foreach (glob($directory.'/*.json') ?: [] as $file) {
                if (!preg_match('/^([a-f0-9]{64})\.json$/D', basename($file), $parts)
                    || in_array($parts[1], $keep, true)) continue;
                if (!@unlink($file)) error_log('Unable to remove an expired locale catalog bundle');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function assertComplete(array $manifest, ?array $resources = null, ?array $onlyLocales = null): void
    {
        $sourceLocale = $manifest['default_locale'] ?? null;
        if (!is_string($sourceLocale)) throw new Problem('locale_settings_invalid', 409);
        $root = $this->root ?? D2S_ROOT;
        $sourceDirectory = $root.'/resources/locales/'.$sourceLocale;
        $sourceFiles = glob($sourceDirectory.'/*.json') ?: [];
        if ($sourceFiles === []) throw new Problem('translation_catalog_invalid', 409);
        $sourceCatalogs = [];
        foreach ($sourceFiles as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $namespace)) {
                $sourceCatalogs[$namespace] = $this->readCatalog($file);
            }
        }
        if ($sourceCatalogs === []) throw new Problem('translation_catalog_invalid', 409);
        $localeDefinitions = $manifest['locales'] ?? [];
        if ($resources === null) {
            if ($onlyLocales !== null) {
                $allowed = array_fill_keys($onlyLocales, true);
                foreach ($localeDefinitions as &$localeDefinition) {
                    if (!isset($allowed[$localeDefinition['code'] ?? ''])) $localeDefinition['enabled'] = false;
                }
                unset($localeDefinition);
            }
            $probeManifest = ['default_locale'=>$sourceLocale,'locales'=>$localeDefinitions];
            $resources = $this->resources($probeManifest);
        }
        $builtinManifestPath = D2S_ROOT.'/resources/locales/manifest.json';
        $builtinManifest = json_decode((string)file_get_contents($builtinManifestPath), true, 32, JSON_THROW_ON_ERROR);
        $builtinCodes = array_fill_keys(array_column($builtinManifest['locales'] ?? [], 'code'), true);
        foreach ($localeDefinitions as $localeDefinition) {
            if (!is_array($localeDefinition) || !($localeDefinition['enabled'] ?? false)) continue;
            $locale = $localeDefinition['code'] ?? null;
            if (!is_string($locale) || !LocaleRegistry::isValidCode($locale)) {
                throw new Problem('locale_settings_invalid', 409);
            }
            if ($onlyLocales !== null && !in_array($locale, $onlyLocales, true)) continue;
            if (isset($builtinCodes[$locale])) continue;
            foreach ($sourceCatalogs as $namespace => $source) {
                $catalog = $resources[$locale][$namespace] ?? null;
                if (!is_array($catalog) || array_diff_key($source, $catalog) !== [] || array_diff_key($catalog, $source) !== []) {
                    throw new Problem('locale_catalog_missing', 409);
                }
            }
        }
    }

    public function publishedRevision(string $revision): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $revision)) return null;
        $path = dirname(LocaleRegistry::snapshotPath()).'/catalogs/'.$revision.'.json';
        return self::readFile($path, $revision);
    }

    public static function published(LocaleRegistry $registry): ?array
    {
        static $cache = [];
        $revision = $registry->catalogRevision();
        $path = $registry->catalogBundlePath();
        if ($revision === null || $path === null) return null;
        if (array_key_exists($path, $cache)) return $cache[$path];
        return $cache[$path] = self::readFile($path, $revision);
    }

    private function resources(array $manifest): array
    {
        $sourceLocale = $manifest['default_locale'] ?? null;
        $locales = $manifest['locales'] ?? null;
        if (!is_string($sourceLocale) || !is_array($locales)) throw new Problem('locale_settings_invalid', 409);
        $root = $this->root ?? D2S_ROOT;
        $sourceDirectory = $root.'/resources/locales/'.$sourceLocale;
        $files = glob($sourceDirectory.'/*.json') ?: [];
        if ($files === []) throw new Problem('translation_catalog_invalid', 409);
        $sourceCatalogs = [];
        foreach ($files as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $namespace)) continue;
            $sourceCatalogs[$namespace] = $this->readCatalog($file);
        }
        ksort($sourceCatalogs, SORT_STRING);
        $resources = [];
        foreach ($locales as $localeDefinition) {
            if (!is_array($localeDefinition) || !($localeDefinition['enabled'] ?? false)) continue;
            $locale = $localeDefinition['code'] ?? null;
            if (!is_string($locale) || !LocaleRegistry::isValidCode($locale)) {
                throw new Problem('locale_settings_invalid', 409);
            }
            $targetPath = $root.'/resources/locales/'.$locale;
            foreach ($sourceCatalogs as $namespace => $source) {
                $base = [];
                $file = $targetPath.'/'.$namespace.'.json';
                if (is_file($file)) {
                    $base = $this->readCatalog($file);
                    foreach ($base as $key => $_message) {
                        if (!array_key_exists($key, $source)) throw new Problem('translation_catalog_invalid', 409);
                    }
                }
                $overrides = $this->readOverrides($locale, $namespace);
                $catalog = [];
                foreach ($source as $key => $sourceMessage) {
                    if (!is_string($key) || !is_string($sourceMessage)) continue;
                    $message = $locale === $sourceLocale ? $sourceMessage : ($base[$key] ?? null);
                    $override = $overrides[$key] ?? null;
                    if (is_array($override) && hash_equals(hash('sha256', $sourceMessage), $override['source_hash'])) {
                        $message = $override['message_value'];
                    }
                    if (!is_string($message) || trim($message) === '') continue;
                    $this->validateMessage($locale, $sourceMessage, $message);
                    $catalog[$key] = $message;
                }
                if ($catalog !== []) {
                    ksort($catalog, SORT_STRING);
                    $resources[$locale][$namespace] = $catalog;
                }
            }
        }
        ksort($resources, SORT_STRING);
        foreach ($resources as &$namespaces) ksort($namespaces, SORT_STRING);
        unset($namespaces);
        return $resources;
    }

    private function readOverrides(string $locale, string $namespace): array
    {
        try {
            $query = $this->db->prepare(
                'SELECT message_key,message_value,source_hash FROM portal_ui_translation_overrides WHERE locale_code=? AND namespace=?'
            );
            $query->execute([$locale, $namespace]);
            $result = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['message_key'] ?? null;
                $value = $row['message_value'] ?? null;
                $sourceHash = $row['source_hash'] ?? null;
                if (is_string($key) && is_string($value) && is_string($sourceHash)) {
                    $result[$key] = ['message_value'=>$value,'source_hash'=>$sourceHash];
                }
            }
            return $result;
        } catch (PDOException) {
            return [];
        }
    }

    private function validateMessage(string $locale, string $source, string $message): void
    {
        $formatter = new \MessageFormatter(str_replace('-', '_', $locale), $message);
        if (!$formatter instanceof \MessageFormatter || $formatter->getErrorCode() !== 0
            || $this->placeholderSignature($source) !== $this->placeholderSignature($message)) {
            throw new Problem('translation_message_invalid', 409);
        }
    }

    private function readCatalog(string $path): array
    {
        try {
            $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem('translation_catalog_invalid', 409);
        }
        if (!is_array($data)) throw new Problem('translation_catalog_invalid', 409);
        foreach ($data as $key => $message) {
            if (!is_string($key) || !is_string($message)) throw new Problem('translation_catalog_invalid', 409);
        }
        return $data;
    }

    private function placeholderSignature(string $message): array
    {
        preg_match_all('/\\{\\s*([A-Za-z][A-Za-z0-9_]*)\\s*(?:,|\\})/u', $message, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        sort($names, SORT_STRING);
        return $names;
    }

    private static function revisionOf(array $resources): string
    {
        return hash('sha256', json_encode(
            $resources,
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        ));
    }

    private static function readFile(string $path, string $expectedRevision): ?array
    {
        try {
            $payload = json_decode((string)@file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload) || ($payload['revision'] ?? null) !== $expectedRevision
            || !is_array($payload['resources'] ?? null)
            || !hash_equals($expectedRevision, self::revisionOf($payload['resources']))) {
            return null;
        }
        return $payload['resources'];
    }
}
