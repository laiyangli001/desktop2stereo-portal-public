<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use RuntimeException;

final readonly class LocaleSettings
{
    public function __construct(private PDO $db, private ?string $resourceRoot = null)
    {
    }

    public function view(int $adminId): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $manifest = $this->manifest($this->db);
        $currentCatalogRevision = null;
        $catalogError = null;
        try {
            $currentCatalogRevision = (new UiCatalogPublisher($this->db))->revision($manifest);
        } catch (Problem $error) {
            $catalogError = $error->error;
        }
        $publishedRevision = null;
        $publishedCatalogRevision = null;
        $path = LocaleRegistry::snapshotPath();
        if (is_file($path)) {
            try {
                new LocaleRegistry($path);
                $published = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                $publishedRevision = $this->revision($published);
                $publishedCatalogRevision = $published['catalog_revision'] ?? null;
            } catch (RuntimeException|\JsonException) {
                // An invalid snapshot is ignored by the runtime registry as well.
            }
        }
        return $manifest + [
            'catalog_history' => $this->catalogHistory($manifest),
            'content_coverage' => $this->contentCoverage($manifest),

            'published_revision' => $publishedRevision,
            'catalog_revision' => $currentCatalogRevision,
            'catalog_error' => $catalogError,
            'published_catalog_revision' => $publishedCatalogRevision,
            'publication_pending' => $catalogError !== null
                || $publishedRevision !== $manifest['revision']
                || !is_string($publishedCatalogRevision)
                || !is_string($currentCatalogRevision)
                || !hash_equals($currentCatalogRevision, $publishedCatalogRevision),
        ];
    }

    public function save(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $items = $input['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) throw new Problem('locale_settings_invalid');
        $manifest = $this->manifest($this->db);
        $previouslyEnabled = [];
        foreach ($manifest['locales'] as $locale) if ($locale['enabled']) $previouslyEnabled[$locale['code']] = true;
        $byCode = [];
        foreach ($manifest['locales'] as $locale) $byCode[$locale['code']] = $locale;
        $existingCodes = array_fill_keys(array_keys($byCode), true);
        $newLocales = $input['new_locales'] ?? [];
        if (!is_array($newLocales) || !array_is_list($newLocales) || count($newLocales) > 20) {
            throw new Problem('locale_settings_invalid');
        }
        $newCodes = [];
        foreach ($newLocales as $item) {
            $locale = $this->parseNewLocale($item);
            if (isset($byCode[$locale['code']])) throw new Problem('locale_settings_invalid');
            $byCode[$locale['code']] = $locale;
            $newCodes[] = $locale['code'];
        }
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['code'] ?? null) || !isset($existingCodes[$item['code']]) || isset($seen[$item['code']])) {
                throw new Problem('locale_settings_invalid');
            }
            $code = $item['code'];
            $seen[$code] = true;
            foreach (['enabled','public_enabled','admin_enabled'] as $flag) {
                if (!is_bool($item[$flag] ?? null)) throw new Problem('locale_settings_invalid');
                $byCode[$code][$flag] = $item[$flag];
            }
            if (isset($item['fallback_locales'])) {
                if (!is_array($item['fallback_locales']) || !array_is_list($item['fallback_locales'])) throw new Problem('locale_settings_invalid');
                foreach ($item['fallback_locales'] as $fallback) if (!is_string($fallback)) throw new Problem('locale_settings_invalid');
                $byCode[$code]['fallback_locales'] = $item['fallback_locales'];
            }
            if (isset($item['sort_order'])) {
                $sort = filter_var($item['sort_order'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>10000]]);
                if ($sort === false) throw new Problem('locale_settings_invalid');
                $byCode[$code]['sort_order'] = $sort;
            }
        }
        if (count($seen) !== count($existingCodes)) throw new Problem('locale_settings_invalid');
        foreach ($byCode as $locale) {
            if (($locale['public_enabled'] || $locale['admin_enabled']) && !$locale['enabled']) throw new Problem('locale_enable_dependency', 409);
        }
        $localesToValidate = [];
        foreach ($byCode as $code => $locale) {
            if ($locale['enabled'] && !isset($previouslyEnabled[$code])) $localesToValidate[] = $code;
        }
        $manifest['locales'] = array_values($byCode);
        try {
            new LocaleRegistry(null, $manifest);
        } catch (RuntimeException) {
            throw new Problem('locale_settings_invalid', 409);
        }
        $defaultLocale = (string)$manifest['default_locale'];
        if ($localesToValidate !== []) (new UiCatalogPublisher($this->db, $this->resourceRoot))->assertComplete($manifest, null, $localesToValidate);
        Database::transaction($this->db, function (PDO $db) use ($adminId,$byCode,$newCodes,$input,$context,$now,$defaultLocale,$localesToValidate): void {
            $db->query('SELECT code FROM portal_locales ORDER BY code FOR UPDATE')->fetchAll();
            $this->assertRevision($input, $this->manifest($db));
            if ($localesToValidate !== []) {
                (new UiCatalogPublisher($db, $this->resourceRoot))->assertComplete([
                    'default_locale'=>$defaultLocale,'locales'=>array_values($byCode),
                ], null, $localesToValidate);
            }
            $insert = $db->prepare(
                'INSERT INTO portal_locales(code,url_prefix,native_name,direction,fallback_locales_json,ui_enabled,public_enabled,admin_enabled,sort_order,updated_at) VALUES(?,?,?,?,?,0,0,0,?,?)'
            );
            $insertAlias = $db->prepare('INSERT INTO portal_locale_aliases(alias,locale_code) VALUES(?,?)');
            foreach ($newCodes as $code) {
                $locale = $byCode[$code];
                $insert->execute([$code,$locale['url_prefix'],$locale['native_name'],$locale['direction'],
                    json_encode($locale['fallback_locales'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$locale['sort_order'],$now]);
                foreach ($locale['aliases'] as $alias) $insertAlias->execute([$alias,$code]);
            }
            $update = $db->prepare(
                'UPDATE portal_locales SET ui_enabled=?,public_enabled=?,admin_enabled=?,fallback_locales_json=?,sort_order=?,revision=revision+1,updated_at=? WHERE code=?'
            );
            foreach ($byCode as $code => $locale) {
                if (in_array($code,$newCodes,true)) continue;
                $update->execute([
                    (int)$locale['enabled'],(int)$locale['public_enabled'],(int)$locale['admin_enabled'],
                    json_encode($locale['fallback_locales'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                    (int)$locale['sort_order'],$now,$code,
                ]);
            }
            (new Backoffice($db))->audit($adminId,'UPDATE_LOCALE_SETTINGS','locale_registry',[
                'enabled'=>array_keys(array_filter($byCode,static fn(array $locale):bool=>$locale['enabled'])),
            ],$context,$now);
        });
        return $this->view($adminId);
    }

    public function synchronizeSnapshotAfterMigration(int $now): bool
    {
        $manifest = $this->manifest($this->db);
        new LocaleRegistry(null, $manifest);
        $publisher = new UiCatalogPublisher($this->db, $this->resourceRoot);
        $catalogRevision = $publisher->revision($manifest);
        $snapshotPath = LocaleRegistry::snapshotPath();
        if (is_file($snapshotPath)) {
            try {
                $snapshot = json_decode((string)file_get_contents($snapshotPath), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $snapshot = null;
            }
            if (is_array($snapshot)
                && is_string($snapshot['revision'] ?? null)
                && hash_equals($manifest['revision'], $snapshot['revision'])
                && is_string($snapshot['catalog_revision'] ?? null)
                && hash_equals($catalogRevision, $snapshot['catalog_revision'])
                && hash_equals($manifest['revision'], $this->revision($snapshot))) {
                Database::transaction($this->db, function (PDO $db): void {
                    $db->query('SELECT code FROM portal_locales ORDER BY code FOR UPDATE')->fetchAll();
                    $active = $this->activeSnapshot();
                    if ($active !== null) $this->pruneCatalogHistory($active);
                });
                return false;
            }
        }

        Database::transaction($this->db, function (PDO $db) use ($now): void {
            $db->query('SELECT code FROM portal_locales ORDER BY code FOR UPDATE')->fetchAll();
            $manifest = $this->manifest($db);
            new LocaleRegistry(null, $manifest);
            $catalogRevision = (new UiCatalogPublisher($db, $this->resourceRoot))->publish($manifest);
            $manifest['catalog_revision'] = $catalogRevision;
            $this->publishSnapshot($manifest);
            EdgeOneCachePurge::enqueueAllPublic(
                $db,
                'locale:migration:'.substr(hash('sha256', $manifest['revision'].$catalogRevision), 0, 48),
                $now
            );
        });
        return true;
    }

    public function publish(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        Database::transaction($this->db, function (PDO $db) use ($adminId,$input,$context,$now): void {
            $db->query('SELECT code FROM portal_locales ORDER BY code FOR UPDATE')->fetchAll();
            $manifest = $this->manifest($db);
            $this->assertRevision($input, $manifest);
            new LocaleRegistry(null, $manifest);
            $catalogRevision = (new UiCatalogPublisher($db, $this->resourceRoot))->publish($manifest);
            $manifest['catalog_revision'] = $catalogRevision;
            (new Backoffice($db))->audit($adminId, 'PUBLISH_LOCALE_SETTINGS', 'locale_registry',
                ['revision'=>$manifest['revision'],'catalog_revision'=>$catalogRevision], $context, $now);
            $this->publishSnapshot($manifest);
            EdgeOneCachePurge::enqueueAllPublic($db,'locale:publish:'.bin2hex(random_bytes(16)),$now);
        });
        return $this->view($adminId);
    }

    public function rollbackCatalog(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $catalogRevision = $input['catalog_revision'] ?? null;
        if (!is_string($catalogRevision) || !preg_match('/^[a-f0-9]{64}$/D', $catalogRevision)) {
            throw new Problem('locale_catalog_history_invalid', 400);
        }
        Database::transaction($this->db, function (PDO $db) use ($adminId, $input, $context, $now, $catalogRevision): void {
            $db->query('SELECT code FROM portal_locales ORDER BY code FOR UPDATE')->fetchAll();
            $manifest = $this->manifest($db);
            $this->assertRevision($input, $manifest);
            $active = $this->activeSnapshot();
            if (($active['catalog_revision'] ?? null) === $catalogRevision) {
                throw new Problem('locale_catalog_history_current', 409);
            }
            if (($this->catalogHistory($manifest)[0]['catalog_revision'] ?? null) !== $catalogRevision) {
                throw new Problem('locale_catalog_history_invalid', 404);
            }
            $historyPath = $this->historyPath($manifest['revision'], $catalogRevision);
            $historical = $this->readHistorySnapshot($historyPath, $manifest['revision'], $catalogRevision);
            if ($historical === null) throw new Problem('locale_catalog_history_invalid', 404);
            $publisher = new UiCatalogPublisher($db, $this->resourceRoot);
            $historicalResources = $publisher->publishedRevision($catalogRevision);
            if ($historicalResources === null) {
                throw new Problem('locale_catalog_history_bundle_missing', 409);
            }
            $publisher->assertComplete($historical, $historicalResources);

            new LocaleRegistry(null, $historical);
            (new Backoffice($db))->audit($adminId, 'ROLLBACK_UI_CATALOG', 'locale_registry', [
                'registry_revision' => $manifest['revision'],
                'from_catalog_revision' => is_array($active) ? ($active['catalog_revision'] ?? null) : null,
                'to_catalog_revision' => $catalogRevision,
            ], $context, $now);
            $this->publishSnapshot($historical);
            EdgeOneCachePurge::enqueueAllPublic($db,'locale:rollback:'.bin2hex(random_bytes(16)),$now);
        });
        return $this->view($adminId);
    }

    private function catalogHistory(array $manifest): array
    {
        $active = $this->activeSnapshot();
        if ($active === null) return [];
        $previous = $this->previousCatalog($active);
        if ($previous === null || $previous['registry_revision'] !== $manifest['revision']) return [];
        return [[
            'catalog_revision' => $previous['catalog_revision'],
            'created_at' => $previous['created_at'],
            'active' => false,
        ]];
    }

    private function activeSnapshot(): ?array
    {
        $file = LocaleRegistry::snapshotPath();
        if (!is_file($file)) return null;
        try {
            $snapshot = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($snapshot) || !is_string($snapshot['revision'] ?? null)
            || (!is_string($snapshot['catalog_revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $snapshot['catalog_revision']))) return null;
        return $this->readHistorySnapshot($file, $snapshot['revision'], $snapshot['catalog_revision']);
    }

    private function catalogSnapshots(): array
    {
        $files = glob(dirname(LocaleRegistry::snapshotPath()).'/history/*.json') ?: [];
        $entries = [];
        foreach ($files as $file) {
            if (!preg_match('/^([a-f0-9]{64})_([a-f0-9]{64})\.json$/D', basename($file), $parts)) continue;
            $entries[] = ['path'=>$file,'registry_revision'=>$parts[1],'catalog_revision'=>$parts[2],
                'created_at'=>(int)(filemtime($file) ?: 0)];
        }
        usort($entries, static fn(array $a,array $b): int => $b['created_at'] <=> $a['created_at'] ?: strcmp($b['path'],$a['path']));
        return $entries;
    }

    private function previousCatalog(array $active, ?array $priorActive = null): ?array
    {
        $publisher = new UiCatalogPublisher($this->db, $this->resourceRoot);
        $entries = $this->catalogSnapshots();
        if ($priorActive !== null) {
            $priorPath = $this->historyPath($priorActive['revision'], $priorActive['catalog_revision']);
            usort($entries, static fn(array $a,array $b): int => ($b['path'] === $priorPath) <=> ($a['path'] === $priorPath));
        }
        foreach ($entries as $entry) {
            if ($entry['catalog_revision'] === $active['catalog_revision']) continue;
            if ($this->readHistorySnapshot($entry['path'], $entry['registry_revision'], $entry['catalog_revision']) !== null
                && $publisher->publishedRevision($entry['catalog_revision']) !== null) return $entry;
        }
        return null;
    }

    private function pruneCatalogHistory(array $active, ?array $priorActive = null): void
    {
        $publisher = new UiCatalogPublisher($this->db, $this->resourceRoot);
        if ($publisher->publishedRevision($active['catalog_revision']) === null) return;
        $previous = $this->previousCatalog($active, $priorActive);
        foreach ($this->catalogSnapshots() as $entry) {
            if ($entry['path'] !== ($previous['path'] ?? null) && !@unlink($entry['path'])) {
                error_log('Unable to remove an expired locale history snapshot');
            }
        }
        $publisher->pruneRevisions(array_filter([$active['catalog_revision'], $previous['catalog_revision'] ?? null]));
    }

    private function historyPath(string $registryRevision, string $catalogRevision): string
    {
        return dirname(LocaleRegistry::snapshotPath()).'/history/'.$registryRevision.'_'.$catalogRevision.'.json';
    }

    private function readHistorySnapshot(string $path, string $registryRevision, string $catalogRevision): ?array
    {
        try {
            $snapshot = json_decode((string)@file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($snapshot) || ($snapshot['revision'] ?? null) !== $registryRevision
            || ($snapshot['catalog_revision'] ?? null) !== $catalogRevision
            || !is_array($snapshot['locales'] ?? null) || !is_string($snapshot['default_locale'] ?? null)
            || !hash_equals($registryRevision, $this->revision($snapshot))) return null;
        return $snapshot;
    }

    private function contentCoverage(array $manifest): array
    {
        $coverage = [];
        foreach ($manifest['locales'] as $locale) {
            $coverage[$locale['code']] = [];
        }
        $queries = [
            'articles' => "SELECT l.code AS locale_code,COUNT(DISTINCT a.id) AS total_count,
                    COUNT(DISTINCT CASE WHEN t.published_revision_id IS NOT NULL THEN a.id END) AS translated_count
                FROM portal_locales l
                LEFT JOIN portal_articles a ON a.status='published'
                LEFT JOIN portal_article_translations t ON t.article_id=a.id AND t.locale_code=l.code
                GROUP BY l.code",
            'products' => "SELECT l.code AS locale_code,COUNT(DISTINCT p.code) AS total_count,
                    COUNT(DISTINCT CASE WHEN t.published_revision_id IS NOT NULL THEN p.code END) AS translated_count
                FROM portal_locales l
                LEFT JOIN portal_products p ON p.enabled=1
                LEFT JOIN portal_product_translations t ON t.product_code=p.code AND t.locale_code=l.code
                GROUP BY l.code",
            'announcements' => "SELECT l.code AS locale_code,COUNT(DISTINCT a.id) AS total_count,
                    COUNT(DISTINCT CASE WHEN t.published_revision_id IS NOT NULL THEN a.id END) AS translated_count
                FROM portal_locales l
                LEFT JOIN portal_announcements a ON a.enabled=1 AND a.deleted_at IS NULL
                LEFT JOIN portal_announcement_translations t ON t.announcement_id=a.id AND t.locale_code=l.code
                GROUP BY l.code",
            'categories' => "SELECT l.code AS locale_code,COUNT(DISTINCT c.code) AS total_count,
                    COUNT(DISTINCT CASE WHEN NULLIF(TRIM(t.label),'') IS NOT NULL THEN c.code END) AS translated_count
                FROM portal_locales l
                LEFT JOIN portal_article_categories c ON 1=1
                LEFT JOIN portal_article_category_translations t ON t.category_code=c.code AND t.locale_code=l.code
                GROUP BY l.code",
        ];
        foreach ($queries as $type => $sql) {
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string)$row['locale_code'];
                $coverage[$code][$type] = [
                    'translated'=>(int)$row['translated_count'],
                    'total'=>(int)$row['total_count'],
                ];
            }
        }

        $navigationRow = $this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $navigationRow->execute(['navigation.v1']);
        $navigation = $navigationRow->fetchColumn();
        $navigationItems = is_string($navigation) ? json_decode($navigation, true) : null;
        if (!is_array($navigationItems)) $navigationItems = SiteContent::defaultNavigation();
        $navigationIds = [];
        foreach ($navigationItems as $item) {
            if (!is_array($item) || ($item['enabled'] ?? true) !== true || !is_string($item['id'] ?? null)) continue;
            $navigationIds[] = $item['id'];
        }
        $navigationIds = array_values(array_unique($navigationIds));
        $navigationTotals = count($navigationIds);
        $navigationCounts = [];
        if ($navigationIds !== []) {
            $marks = implode(',', array_fill(0, count($navigationIds), '?'));
            $query = $this->db->prepare("SELECT locale_code,COUNT(DISTINCT navigation_id) AS translated_count
                FROM portal_navigation_translations WHERE navigation_id IN ($marks) AND NULLIF(TRIM(label),'') IS NOT NULL
                GROUP BY locale_code");
            $query->execute($navigationIds);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $navigationCounts[(string)$row['locale_code']] = (int)$row['translated_count'];
            }
        }
        foreach ($coverage as $code => &$items) {
            $items['navigation'] = ['translated'=>$navigationCounts[$code] ?? 0,'total'=>$navigationTotals];
        }
        unset($items);

        $siteRows = [];
        foreach ($this->db->query("SELECT locale_code FROM portal_site_translations
                WHERE NULLIF(TRIM(tagline),'') IS NOT NULL AND NULLIF(TRIM(intro),'') IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $siteRows[(string)$row['locale_code']] = true;
        }
        foreach ($coverage as $code => &$items) {
            $items['site'] = ['translated'=>isset($siteRows[$code]) ? 1 : 0,'total'=>1];
        }
        unset($items);

        $siteSettings = $this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $siteSettings->execute(['site.v1']);
        $siteValue = $siteSettings->fetchColumn();
        $siteValue = is_string($siteValue) ? json_decode($siteValue, true) : null;
        if (!is_array($siteValue)) $siteValue = SiteContent::defaultSite();
        $policyVersions = [
            'PRIVACY'=>(string)($siteValue['privacy_version'] ?? ''),
            'TERMS'=>(string)($siteValue['terms_version'] ?? ''),
            'LEGAL'=>(string)($siteValue['legal_version'] ?? ''),
        ];
        $policyTotal = count(array_filter($policyVersions,static fn(string $version): bool=>$version!==''));
        $policyCounts = [];
        $policyQuery = $this->db->prepare('SELECT locale_code FROM portal_policy_translation_heads
            WHERE policy_type=? AND policy_version=? AND published_revision_id IS NOT NULL');
        foreach ($policyVersions as $type => $version) {
            if ($version === '') continue;
            $policyQuery->execute([$type,$version]);
            foreach ($policyQuery->fetchAll(PDO::FETCH_COLUMN) as $localeCode) {
                $policyCounts[(string)$localeCode] = ($policyCounts[(string)$localeCode] ?? 0) + 1;
            }
        }
        foreach ($coverage as $code => &$items) {
            $items['policies'] = ['translated'=>$policyCounts[$code] ?? 0,'total'=>$policyTotal];
        }
        unset($items);

        return $coverage;
    }

    private function parseNewLocale(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['code'] ?? null) || !is_string($item['url_prefix'] ?? null)
            || !is_string($item['native_name'] ?? null) || !is_string($item['direction'] ?? null)
            || !is_array($item['fallback_locales'] ?? null) || !array_is_list($item['fallback_locales']) || $item['fallback_locales'] === []
            || !is_array($item['aliases'] ?? null) || !array_is_list($item['aliases'])) {
            throw new Problem('locale_settings_invalid');
        }
        $code = $item['code'];
        $prefix = $item['url_prefix'];
        $name = trim($item['native_name']);
        $direction = $item['direction'];
        $sort = filter_var($item['sort_order'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>10000]]);
        $reservedPrefixes = [
            'api','assets','uploads','downloads','alipay','oauth','webhook','native',
            'admin','user','account','dashboard','profile','tickets','wallet','license',
            'notifications','feedback','consent','shop','payments','help','support',
            'sitemap.xml','robots.txt',
        ];
        if (!LocaleRegistry::isValidCode($code)
            || !preg_match('/^[a-z0-9-]{2,32}$/D', $prefix) || in_array($prefix,$reservedPrefixes,true)
            || $name === '' || mb_strlen($name,'UTF-8') > 100
            || !in_array($direction,['ltr','rtl'],true) || $sort === false) {
            throw new Problem('locale_settings_invalid');
        }
        foreach ($item['fallback_locales'] as $fallback) {
            if (!is_string($fallback) || $fallback === $code) throw new Problem('locale_settings_invalid');
        }
        foreach ($item['aliases'] as $alias) {
            if (!is_string($alias) || !preg_match('/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/D', $alias)) {
                throw new Problem('locale_settings_invalid');
            }
        }
        return [
            'code'=>$code,'url_prefix'=>$prefix,'native_name'=>$name,'direction'=>$direction,
            'enabled'=>false,'public_enabled'=>false,'admin_enabled'=>false,
            'fallback_locales'=>$item['fallback_locales'],'aliases'=>$item['aliases'],'sort_order'=>$sort,
        ];
    }

    private function manifest(PDO $db): array
    {
        $base = json_decode((string)file_get_contents(D2S_ROOT.'/resources/locales/manifest.json'),true,32,JSON_THROW_ON_ERROR);
        $rows = $db->query('SELECT code,url_prefix,native_name,direction,fallback_locales_json,ui_enabled,public_enabled,admin_enabled,sort_order FROM portal_locales ORDER BY sort_order,code')->fetchAll();
        $aliases = [];
        foreach ($db->query('SELECT alias,locale_code FROM portal_locale_aliases ORDER BY alias')->fetchAll() as $row) {
            $aliases[$row['locale_code']][] = $row['alias'];
        }
        $locales = [];
        foreach ($rows as $row) {
            $fallbacks = json_decode((string)$row['fallback_locales_json'],true,16,JSON_THROW_ON_ERROR);
            if (!is_array($fallbacks) || !array_is_list($fallbacks)) throw new RuntimeException('locale_fallback_invalid');
            $locales[] = [
                'code'=>$row['code'],'url_prefix'=>$row['url_prefix'],'native_name'=>$row['native_name'],
                'direction'=>$row['direction'],'enabled'=>(bool)$row['ui_enabled'],
                'public_enabled'=>(bool)$row['public_enabled'],'admin_enabled'=>(bool)$row['admin_enabled'],
                'fallback_locales'=>$fallbacks,'aliases'=>$aliases[$row['code']]??[],
                'sort_order'=>(int)$row['sort_order'],
            ];
        }
        $manifest = ['default_locale'=>$base['default_locale'],'locales'=>$locales];
        return $manifest + ['revision'=>$this->revision($manifest)];
    }

    private function revision(array $manifest): string
    {
        return hash('sha256', json_encode(
            ['default_locale'=>$manifest['default_locale'],'locales'=>$manifest['locales']],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        ));
    }

    private function assertRevision(array $input, array $manifest): void
    {
        if (!is_string($input['revision'] ?? null) || !hash_equals($manifest['revision'], $input['revision'])) {
            throw new Problem('record_changed', 409);
        }
    }

    private function publishSnapshot(array $manifest): void
    {
        $path = LocaleRegistry::snapshotPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory,$this->snapshotDirectoryMode(),true) && !is_dir($directory)) {
            throw new Problem('locale_snapshot_publish_failed', 503);
        }
        $priorActive = $this->activeSnapshot();
        if ($priorActive !== null && $priorActive['catalog_revision'] !== $manifest['catalog_revision']) {
            $historyDirectory = $directory.'/history';
            if (!is_dir($historyDirectory) && !@mkdir($historyDirectory,$this->snapshotDirectoryMode(),true) && !is_dir($historyDirectory)) {
                throw new Problem('locale_snapshot_publish_failed', 503);
            }
            $this->writeSnapshot($this->historyPath($priorActive['revision'], $priorActive['catalog_revision']), $priorActive);
        }
        $this->writeSnapshot($path, $manifest);
        $this->pruneCatalogHistory($manifest, $priorActive);
    }

    private function snapshotDirectoryMode(): int
    {
        $mode = UpdateSharedPermissions::directoryMode();
        return ($mode & 02000) !== 0 ? $mode | 0020 : $mode;
    }

    private function writeSnapshot(string $path, array $manifest): void
    {
        $contents = json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (@file_put_contents($temporary,$contents,LOCK_EX)!==strlen($contents) || !@rename($temporary,$path)) {
                throw new Problem('locale_snapshot_publish_failed', 503);
            }
            @chmod($path,UpdateSharedPermissions::fileMode());
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }
}
