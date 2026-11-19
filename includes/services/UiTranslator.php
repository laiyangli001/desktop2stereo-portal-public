<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;
use PDO;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\Translator;

final class ExactLocaleUiTranslator extends Translator
{
    protected function computeFallbackLocales(string $locale): array
    {
        return [];
    }
}

final class UiTranslator
{
    private Translator $translator;
    private string $locale;

    public function __construct(private readonly LocaleRegistry $registry, string $locale, ?PDO $db = null)
    {
        if (!class_exists(Translator::class) || !class_exists(\MessageFormatter::class)) {
            throw new RuntimeException('intl_extension_required');
        }
        $locale = $registry->normalize($locale, true) ?? $registry->defaultLocale();
        $this->locale = $locale;
        $this->translator = new ExactLocaleUiTranslator($locale);
        $this->translator->addLoader('json', new JsonFileLoader());
        $this->translator->addLoader('array', new ArrayLoader());
        $this->translator->setFallbackLocales([]);
        $catalogRevision = $registry->catalogRevision();
        $published = UiCatalogPublisher::published($registry);
        if ($catalogRevision !== null) {
            $catalogsByLocale = $published ?? $this->staticResources();
        } else {
            $catalogsByLocale = $this->staticResources();
            foreach ($this->loadOverrides($db) as $catalogLocale => $overrides) {
                foreach ($overrides as $namespace => $messages) {
                    if (!isset($catalogsByLocale[$catalogLocale][$namespace])) $catalogsByLocale[$catalogLocale][$namespace] = [];
                    foreach ($messages as $key => $value) $catalogsByLocale[$catalogLocale][$namespace][$key] = $value;
                }
            }
        }
        foreach ($catalogsByLocale as $catalogLocale => $catalogs) {
            foreach ($catalogs as $namespace => $catalog) {
                $this->translator->addResource('array', $catalog, $catalogLocale, $namespace . '+intl-icu');
            }
        }
    }

    public function browserPayload(array $namespaces = ['common']): array
    {
        $namespaces = array_values(array_unique($namespaces));
        foreach ($namespaces as $namespace) {
            if (!is_string($namespace) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $namespace)) {
                throw new RuntimeException('translation_namespace_invalid');
            }
        }
        $resources = [];
        $catalogRevision = $this->registry->catalogRevision();
        $published = UiCatalogPublisher::published($this->registry);
        foreach ($this->registry->enabled() as $definition) {
            $code = (string)$definition['code'];
            foreach ($namespaces as $namespace) {
                if ($published !== null) {
                    $catalog = $published[$code][$namespace] ?? [];
                } else {
                    $file = D2S_ROOT.'/resources/locales/'.$code.'/'.$namespace.'.json';
                    $catalog = [];
                    if (is_file($file)) {
                        $catalog = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                        if (!is_array($catalog)) throw new RuntimeException('translation_catalog_invalid');
                    }
                    if ($catalogRevision === null) {
                        foreach ($this->loadOverridesFor($code, $namespace) as $key => $value) $catalog[$key] = $value;
                    }
                }
                foreach ($catalog as $key => $message) {
                    if (!is_string($key) || !is_string($message)) throw new RuntimeException('translation_catalog_invalid');
                }
                if ($catalog !== []) $resources[$code][$namespace] = $catalog;
            }
        }
        $urls = new LocaleUrl($this->registry);
        $destinations = ['/login','/admin/login','/profile','/consent','/admin','/feedback','/feedback/new','/support/wechat','/shop'];
        $accountUrls = [];
        foreach ($destinations as $destination) $accountUrls[$destination] = $urls->build($destination, $this->locale);
        return [
            'account_urls'=>$accountUrls,
            'locale'=>$this->locale,
            'namespaces'=>$namespaces,
            'resources'=>$resources,
            'revision'=>$published !== null && $catalogRevision !== null
                ? $catalogRevision
                : hash('sha256', json_encode($resources, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
        ];
    }

    public function translate(string $key, array $parameters = [], ?string $locale = null): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*(?:\\.[a-z][a-z0-9_-]*)+$/D', $key)) {
            throw new RuntimeException('translation_key_invalid');
        }
        [$domain, $message] = explode('.', $key, 2);
        return $this->translator->trans($message, $parameters, $domain . '+intl-icu', $locale);
    }

    private function staticResources(): array
    {
        $resources = [];
        foreach ($this->registry->enabled() as $definition) {
            $locale = (string)$definition['code'];
            $directory = D2S_ROOT.'/resources/locales/'.$locale;
            foreach (glob($directory.'/*.json') ?: [] as $file) {
                $namespace = pathinfo($file, PATHINFO_FILENAME);
                $catalog = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($catalog)) throw new RuntimeException('translation_catalog_invalid');
                $resources[$locale][$namespace] = $catalog;
            }
        }
        return $resources;
    }

    private function loadOverrides(?PDO $db): array
    {
        $db ??= $this->overrideDatabase();
        if ($db === null) return [];
        $result = [];
        $sourceDirectory = D2S_ROOT.'/resources/locales/'.$this->registry->defaultLocale();
        foreach (glob($sourceDirectory.'/*.json') ?: [] as $file) {
            $namespace = pathinfo($file, PATHINFO_FILENAME);
            foreach ($this->registry->enabled() as $definition) {
                $locale = (string)$definition['code'];
                $result[$locale][$namespace] = $this->loadOverridesFor($locale, $namespace, $db);
            }
        }
        return $result;
    }

    private function loadOverridesFor(string $locale, string $namespace, ?PDO $db = null): array
    {
        static $cache = [];
        $db ??= $this->overrideDatabase();
        if ($db === null) return [];
        $cacheKey = spl_object_id($db).':'.$locale.':'.$namespace;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        $sourceLocale = $this->registry->defaultLocale();
        $sourcePath = D2S_ROOT.'/resources/locales/'.$sourceLocale.'/'.$namespace.'.json';
        if (!is_file($sourcePath)) return $cache[$cacheKey] = [];
        $source = json_decode((string)file_get_contents($sourcePath), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($source)) return $cache[$cacheKey] = [];
        $valid = [];
        try {
            $query = $db->prepare('SELECT message_key,message_value,source_hash FROM portal_ui_translation_overrides WHERE locale_code=? AND namespace=?');
            $query->execute([$locale,$namespace]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['message_key'] ?? null;
                if (!is_string($key) || !isset($source[$key]) || !is_string($source[$key])) continue;
                if (hash_equals(hash('sha256',$source[$key]), (string)$row['source_hash'])) $valid[$key] = (string)$row['message_value'];
            }
        } catch (\PDOException) {
            return $cache[$cacheKey] = [];
        }
        return $cache[$cacheKey] = $valid;
    }

    private function overrideDatabase(): ?PDO
    {
        static $resolved = false;
        static $connection = null;
        if ($resolved) return $connection;
        $resolved = true;
        try {
            $connection = \D2S\repositories\Database::fromEnvironment();
        } catch (\Throwable) {
            $connection = null;
        }
        return $connection;
    }
}
