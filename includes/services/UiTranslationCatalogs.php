<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class UiTranslationCatalogs
{
    public function __construct(private PDO $db, private LocaleRegistry $registry)
    {
    }

    public function catalog(int $adminId, string $locale, string $namespace, string $query = '', int $page = 1): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $locale = $this->registry->normalize($locale, false);
        if ($locale === null || $locale === $this->registry->defaultLocale()) throw new Problem('translation_locale_invalid');
        $this->assertNamespace($namespace);
        if ($page < 1 || $page > 10000 || mb_strlen($query, 'UTF-8') > 120) throw new Problem('translation_catalog_query_invalid');
        $sourceLocale = $this->registry->defaultLocale();
        $source = $this->readCatalog($sourceLocale, $namespace);
        $target = $this->readCatalog($locale, $namespace);
        $stmt = $this->db->prepare('SELECT message_key,message_value,source_hash,lock_version FROM portal_ui_translation_overrides WHERE locale_code=? AND namespace=?');
        $stmt->execute([$locale, $namespace]);
        $overrides = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $overrides[$row['message_key']] = $row;
        $items = [];
        $mode = in_array($query, ['@missing','@stale'], true) ? $query : '';
        $needle = $mode === '' ? mb_strtolower($query, 'UTF-8') : '';
        foreach ($source as $key => $sourceValue) {
            if (!is_string($key) || !is_string($sourceValue)) continue;
            $isOverride = isset($overrides[$key]) && hash_equals(hash('sha256', $sourceValue), (string)$overrides[$key]['source_hash']);
            $baseValue = $target[$key] ?? '';
            $value = $isOverride ? (string)$overrides[$key]['message_value'] : (is_string($baseValue) ? $baseValue : '');
            $missing = $value === '';
            if ($needle !== '' && !str_contains(mb_strtolower($key.' '.$sourceValue.' '.$value, 'UTF-8'), $needle)) continue;
            if ($mode === '@missing' && !$missing) continue;
            if ($mode === '@stale' && (!isset($overrides[$key]) || $isOverride)) continue;
            $items[] = [
                'key'=>$key,'source'=>$sourceValue,'value'=>$value,'missing'=>$missing,
                'overridden'=>$isOverride,'stale_override'=>isset($overrides[$key]) && !$isOverride,
                'source_hash'=>hash('sha256',$sourceValue),'lock_version'=>isset($overrides[$key])?(int)$overrides[$key]['lock_version']:0,
            ];
        }
        usort($items, static fn(array $a,array $b):int=>[$a['missing']?0:1,$a['key']]<=>[$b['missing']?0:1,$b['key']]);
        $total = count($items);
        $limit = 50;
        return [
            'locale'=>$locale,'namespace'=>$namespace,'source_locale'=>$sourceLocale,
            'namespaces'=>$this->namespaces($sourceLocale),'query'=>$query,'page'=>$page,'limit'=>$limit,
            'total'=>$total,'pages'=>max(1,(int)ceil($total/$limit)),
            'items'=>array_slice($items,($page-1)*$limit,$limit),
        ];
    }

    public function exportPackage(int $adminId, string $locale, ?string $namespace = null): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $locale = $this->registry->normalize($locale, false);
        $sourceLocale = $this->registry->defaultLocale();
        if ($locale === null || $locale === $sourceLocale) throw new Problem('translation_locale_invalid');
        if ($namespace !== null) $this->assertNamespace($namespace);

        $sql = 'SELECT namespace,message_key,message_value,source_hash,lock_version
            FROM portal_ui_translation_overrides WHERE locale_code=?';
        $stmt = $this->db->prepare($namespace === null ? $sql : $sql.' AND namespace=?');
        $stmt->execute($namespace === null ? [$locale] : [$locale, $namespace]);
        $overrides = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $overrides[$row['namespace']][$row['message_key']] = $row;

        $groups = [];
        foreach ($namespace === null ? $this->namespaces($sourceLocale) : [$namespace] as $group) {
            $groups[$group] = $this->exportMessages($sourceLocale, $locale, $group, $overrides[$group] ?? []);
        }
        $header = [
            'format' => 'd2s-ui-catalog',
            'version' => $namespace === null ? 2 : 1,
            'locale' => $locale,
            'source_locale' => $sourceLocale,
        ];
        return $namespace === null
            ? $header + ['namespaces' => $groups]
            : $header + ['namespace' => $namespace, 'messages' => $groups[$namespace]];
    }

    private function exportMessages(string $sourceLocale, string $locale, string $namespace, array $overrides): array
    {
        $source = $this->readCatalog($sourceLocale, $namespace);
        $target = $this->readCatalog($locale, $namespace);
        $messages = [];
        foreach ($source as $key => $sourceValue) {
            if (!is_string($key) || !is_string($sourceValue)) continue;
            $sourceHash = hash('sha256', $sourceValue);
            $override = $overrides[$key] ?? null;
            $validOverride = is_array($override) && hash_equals($sourceHash, (string)$override['source_hash']);
            $base = $target[$key] ?? '';
            $messages[$key] = [
                'source' => $sourceValue,
                'value' => $validOverride ? (string)$override['message_value'] : (is_string($base) ? $base : ''),
                'source_hash' => $sourceHash,
                'lock_version' => is_array($override) ? (int)$override['lock_version'] : 0,
            ];
        }
        return $messages;
    }

    public function importPackage(int $adminId, array $package, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $locale = is_string($package['locale'] ?? null) ? $this->registry->normalize($package['locale'], false) : null;
        $sourceLocale = $this->registry->defaultLocale();
        $version = $package['version'] ?? null;
        if (($package['format'] ?? null) !== 'd2s-ui-catalog' || !in_array($version, [1, 2], true)
            || ($package['source_locale'] ?? null) !== $sourceLocale || $locale === null || $locale === $sourceLocale) {
            throw new Problem('translation_package_invalid');
        }

        $namespace = $package['namespace'] ?? null;
        $groups = $package['namespaces'] ?? null;
        if ($version === 1) {
            if (!is_string($namespace)) throw new Problem('translation_package_invalid');
            $groups = [$namespace => $package['messages'] ?? null];
        }
        $available = $this->namespaces($sourceLocale);
        if (!is_array($groups) || array_is_list($groups)
            || ($version === 2 && array_diff(array_keys($groups), $available) !== [])) {
            throw new Problem('translation_package_invalid');
        }
        $validated = [];
        foreach ($groups as $group => $messages) {
            $this->assertNamespace((string)$group);
            $validated[$group] = $this->validateMessages($locale, $sourceLocale, $group, $messages);
        }
        ksort($validated, SORT_STRING);

        return Database::transaction($this->db, function (PDO $db) use ($adminId, $locale, $version, $namespace, $validated, $context, $now): array {
            $localeLock = $db->prepare('SELECT code FROM portal_locales WHERE code=? FOR UPDATE');
            $localeLock->execute([$locale]);
            if ($localeLock->fetchColumn() === false) throw new Problem('translation_locale_invalid', 409);
            $select = $db->prepare('SELECT message_key,message_value,source_hash,lock_version FROM portal_ui_translation_overrides
                WHERE locale_code=? AND namespace=? FOR UPDATE');
            $insert = $db->prepare('INSERT INTO portal_ui_translation_overrides(locale_code,namespace,message_key,message_value,source_hash,lock_version,updated_by,updated_at) VALUES(?,?,?,?,?,1,?,?)');
            $update = $db->prepare('UPDATE portal_ui_translation_overrides SET message_value=?,source_hash=?,lock_version=lock_version+1,updated_by=?,updated_at=? WHERE locale_code=? AND namespace=? AND message_key=?');
            $delete = $db->prepare('DELETE FROM portal_ui_translation_overrides WHERE locale_code=? AND namespace=? AND message_key=?');
            $counts = ['imported'=>0, 'removed'=>0, 'skipped_empty'=>0];
            foreach ($validated as $group => $messages) {
                $select->execute([$locale, $group]);
                $overrides = array_column($select->fetchAll(PDO::FETCH_ASSOC), null, 'message_key');
                $base = $version === 2 ? $this->readCatalog($locale, $group) : [];
                foreach ($messages as $key => [$value, $sourceHash, $expectedVersion]) {
                    $current = $overrides[$key] ?? null;
                    if ((int)($current['lock_version'] ?? 0) !== $expectedVersion) throw new Problem('record_changed', 409);
                    if ($value === '') {
                        if ($current === null) {
                            $counts['skipped_empty']++;
                        } else {
                            $delete->execute([$locale, $group, $key]);
                            $counts['removed']++;
                        }
                        continue;
                    }
                    $previous = $current !== null && hash_equals($sourceHash, $current['source_hash'])
                        ? $current['message_value'] : ($base[$key] ?? '');
                    if ($version === 2 && $value === $previous) continue;
                    if ($current === null) {
                        $insert->execute([$locale, $group, $key, $value, $sourceHash, $adminId, $now]);
                    } else {
                        $update->execute([$value, $sourceHash, $adminId, $now, $locale, $group, $key]);
                    }
                    $counts['imported']++;
                }
            }
            (new Backoffice($db))->audit($adminId, 'IMPORT_UI_TRANSLATION_CATALOG', 'ui_translation_catalog', [
                'locale' => $locale,
                ...($version === 1 ? ['namespace'=>$namespace] : ['namespaces'=>array_keys($validated)]),
                'imported' => $counts['imported'], 'removed' => $counts['removed'],
                'package_hash' => hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            ], $context, $now);
            return $counts;
        });
    }

    private function validateMessages(string $locale, string $sourceLocale, string $namespace, mixed $messages): array
    {
        if (!is_array($messages) || array_is_list($messages) || count($messages) > 5000) {
            throw new Problem('translation_package_invalid');
        }
        $source = $this->readCatalog($sourceLocale, $namespace);
        $validated = [];
        foreach ($messages as $key => $entry) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/D', $key)
                || !is_array($entry) || !is_string($entry['value'] ?? null)
                || !is_string($entry['source_hash'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['source_hash'])
                || filter_var($entry['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) === false) {
                throw new Problem('translation_package_invalid');
            }
            if (!is_string($source[$key] ?? null) || !hash_equals(hash('sha256', $source[$key]), $entry['source_hash'])) {
                throw new Problem('translation_source_changed', 409);
            }
            $value = $entry['value'];
            if ($value !== '') {
                if (trim($value) === '' || mb_strlen($value, 'UTF-8') > 10000) throw new Problem('translation_package_invalid');
                $formatter = new \MessageFormatter(str_replace('-', '_', $locale), $value);
                if (!$formatter instanceof \MessageFormatter || $formatter->getErrorCode() !== 0
                    || $this->placeholderSignature($source[$key]) !== $this->placeholderSignature($value)) {
                    throw new Problem('translation_message_invalid');
                }
            }
            $validated[$key] = [$value, $entry['source_hash'], (int)$entry['lock_version']];
        }
        ksort($validated, SORT_STRING);
        return $validated;
    }

    public function save(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'locales.manage');
        $locale = $this->registry->normalize((string)($input['locale'] ?? ''), false);
        $namespace = $input['namespace'] ?? null;
        $key = $input['key'] ?? null;
        $value = $input['value'] ?? null;
        $sourceHash = $input['source_hash'] ?? null;
        $lockVersion = filter_var($input['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($locale === null || $locale === $this->registry->defaultLocale() || !is_string($namespace)
            || !is_string($key) || !preg_match('/^[a-z][a-z0-9_-]*(?:\\.[a-z][a-z0-9_-]*)*$/D',$key)
            || !is_string($value) || ($value !== '' && trim($value) === '') || mb_strlen($value,'UTF-8') > 10000
            || !is_string($sourceHash) || !preg_match('/^[a-f0-9]{64}$/D',$sourceHash) || $lockVersion === false) {
            throw new Problem('translation_catalog_input_invalid');
        }
        $this->assertNamespace($namespace);
        $source = $this->readCatalog($this->registry->defaultLocale(),$namespace);
        if (!is_string($source[$key] ?? null) || !hash_equals(hash('sha256',$source[$key]),$sourceHash)) {
            throw new Problem('translation_source_changed',409);
        }
        if ($value !== '') {
            $formatter = new \MessageFormatter(str_replace('-','_',$locale),$value);
            if (!$formatter instanceof \MessageFormatter || $formatter->getErrorCode() !== 0) {
                throw new Problem('translation_message_invalid');
            }
            if ($this->placeholderSignature($source[$key]) !== $this->placeholderSignature($value)) {
                throw new Problem('translation_message_invalid');
            }
        }
        $removed = false;
        Database::transaction($this->db,function(PDO $db) use($adminId,$locale,$namespace,$key,$value,$sourceHash,$lockVersion,$context,$now,&$removed):void {
            $localeLock=$db->prepare('SELECT code FROM portal_locales WHERE code=? FOR UPDATE');
            $localeLock->execute([$locale]);
            if($localeLock->fetchColumn()===false)throw new Problem('translation_locale_invalid',409);
            $select=$db->prepare('SELECT lock_version FROM portal_ui_translation_overrides WHERE locale_code=? AND namespace=? AND message_key=? FOR UPDATE');
            $select->execute([$locale,$namespace,$key]);
            $current=$select->fetchColumn();
            if (($current===false && $lockVersion!==0) || ($current!==false && (int)$current!==$lockVersion)) {
                throw new Problem('record_changed',409);
            }
            if ($value === '') {
                if ($current !== false) {
                    $delete=$db->prepare('DELETE FROM portal_ui_translation_overrides WHERE locale_code=? AND namespace=? AND message_key=?');
                    $delete->execute([$locale,$namespace,$key]);
                    $removed = true;
                }
            } elseif ($current===false) {
                $insert=$db->prepare('INSERT INTO portal_ui_translation_overrides(locale_code,namespace,message_key,message_value,source_hash,lock_version,updated_by,updated_at) VALUES(?,?,?,?,?,1,?,?)');
                $insert->execute([$locale,$namespace,$key,$value,$sourceHash,$adminId,$now]);
            } else {
                $update=$db->prepare('UPDATE portal_ui_translation_overrides SET message_value=?,source_hash=?,lock_version=lock_version+1,updated_by=?,updated_at=? WHERE locale_code=? AND namespace=? AND message_key=?');
                $update->execute([$value,$sourceHash,$adminId,$now,$locale,$namespace,$key]);
            }
            (new Backoffice($db))->audit($adminId,$value===''?'CLEAR_UI_TRANSLATION_OVERRIDE':'UPDATE_UI_TRANSLATION','ui_translation',[
                'locale'=>$locale,'namespace'=>$namespace,'key'=>$key,'value_hash'=>hash('sha256',$value),'removed'=>$removed,
            ],$context,$now);
        });
        return ['saved'=>true,'removed'=>$removed,'lock_version'=>$removed?0:($value===''?0:$lockVersion+1)];
    }

    private function readCatalog(string $locale,string $namespace): array
    {
        $path=D2S_ROOT.'/resources/locales/'.$locale.'/'.$namespace.'.json';
        if (!is_file($path)) return [];
        $data=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new Problem('translation_catalog_invalid',500);
        return $data;
    }

    private function namespaces(string $locale): array
    {
        $files=glob(D2S_ROOT.'/resources/locales/'.$locale.'/*.json')?:[];
        $namespaces=[];
        foreach($files as$file){
            $name=pathinfo($file,PATHINFO_FILENAME);
            if(preg_match('/^[a-z][a-z0-9_-]*$/D',$name))$namespaces[]=$name;
        }
        sort($namespaces,SORT_STRING);
        return $namespaces;
    }

    private function placeholderSignature(string $message): array
    {
        preg_match_all('/\\{\\s*([A-Za-z][A-Za-z0-9_]*)\\s*(?:,|\\})/u',$message,$matches);
        $names=array_values(array_unique($matches[1]??[]));
        sort($names,SORT_STRING);
        return $names;
    }

    private function assertNamespace(string $namespace): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/D',$namespace)) throw new Problem('translation_namespace_invalid');
    }
}
