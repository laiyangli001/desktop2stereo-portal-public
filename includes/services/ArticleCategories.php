<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ArticleCategories
{
    public function __construct(private PDO $db, private LocaleRegistry $locales = new LocaleRegistry())
    {
    }

    public function adminList(int $adminId, string $requestedLocale): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $locale = $this->locales->normalize($requestedLocale);
        if ($locale === null) throw new Problem('locale_unsupported', 400);
        $query = $this->db->query(
            'SELECT c.code,c.sort_order,t.locale_code,t.label,t.description,t.lock_version
             FROM portal_article_categories c
             LEFT JOIN portal_article_category_translations t ON t.category_code=c.code
             ORDER BY c.sort_order,c.code,t.locale_code'
        );
        $grouped = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string)$row['code'];
            $grouped[$code] ??= ['code'=>$code,'sort_order'=>(int)$row['sort_order'],'translations'=>[]];
            if (is_string($row['locale_code'] ?? null)) {
                $grouped[$code]['translations'][(string)$row['locale_code']] = [
                    'label'=>(string)$row['label'],
                    'description'=>(string)$row['description'],
                    'lock_version'=>(int)$row['lock_version'],
                ];
            }
        }
        $items = [];
        foreach ($grouped as $category) {
            $translations = $category['translations'];
            $current = $translations[$locale] ?? null;
            $fallback = null;
            foreach (array_values(array_unique([$locale,...$this->fallbackChain($locale),$this->locales->defaultLocale()])) as $candidate) {
                if (isset($translations[$candidate])) {
                    $fallback = $translations[$candidate];
                    break;
                }
            }
            $items[] = [
                'code'=>$category['code'],
                'sort_order'=>$category['sort_order'],
                'label'=>$current['label'] ?? $fallback['label'] ?? '',
                'fallback_label'=>$fallback['label'] ?? '',
                'current_label'=>$current['label'] ?? '',
                'description'=>$current['description'] ?? '',
                'lock_version'=>$current['lock_version'] ?? 0,
                'translated'=>$current !== null,
            ];
        }
        return ['locale'=>$locale,'items'=>$items];
    }

    public function saveTranslation(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $locale = $this->locales->normalize((string)($input['locale'] ?? ''));
        if ($locale === null) throw new Problem('locale_unsupported', 400);
        $label = Backoffice::text($input, 'label', 80, true);
        $description = Backoffice::text($input, 'description', 500);
        $code = trim((string)($input['code'] ?? ''));
        if ($code !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $code)) {
            throw new Problem('article_category_invalid');
        }
        $expected = filter_var($input['lock_version'] ?? 0, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0) throw new Problem('record_changed', 409);
        $creating = $code === '';
        if ($creating) $code = 'cat-'.bin2hex(random_bytes(10));

        return Database::transaction($this->db, function (PDO $db) use (
            $adminId,$code,$locale,$label,$description,$expected,$creating,$context,$now
        ): array {
            if ($creating) {
                $order = (int)$db->query('SELECT COALESCE(MAX(sort_order),-1)+1 FROM portal_article_categories')->fetchColumn();
                $db->prepare('INSERT INTO portal_article_categories(code,sort_order,created_at,updated_at) VALUES(?,?,?,?)')
                    ->execute([$code,$order,$now,$now]);
            } else {
                $lock = $db->prepare('SELECT code FROM portal_article_categories WHERE code=? FOR UPDATE');
                $lock->execute([$code]);
                if ($lock->fetchColumn() === false) throw new Problem('article_category_not_found',404);
                $db->prepare('UPDATE portal_article_categories SET updated_at=? WHERE code=?')->execute([$now,$code]);
            }
            $query = $db->prepare('SELECT lock_version FROM portal_article_category_translations WHERE category_code=? AND locale_code=? FOR UPDATE');
            $query->execute([$code,$locale]);
            $current = $query->fetchColumn();
            $version = $current === false ? 0 : (int)$current;
            if ($expected !== $version || ($creating && $version !== 0)) throw new Problem('record_changed',409);
            $next = $version + 1;
            if ($current === false) {
                $db->prepare('INSERT INTO portal_article_category_translations
                    (category_code,locale_code,label,description,lock_version,updated_by,updated_at)
                    VALUES(?,?,?,?,?,?,?)')->execute([$code,$locale,$label,$description,$next,$adminId,$now]);
            } else {
                $db->prepare('UPDATE portal_article_category_translations
                    SET label=?,description=?,lock_version=?,updated_by=?,updated_at=?
                    WHERE category_code=? AND locale_code=?')->execute([$label,$description,$next,$adminId,$now,$code,$locale]);
            }
            (new Backoffice($db))->audit($adminId,'SAVE_ARTICLE_CATEGORY_TRANSLATION',$code,
                ['locale'=>$locale,'lock_version'=>$next],$context,$now);
            return ['code'=>$code,'locale'=>$locale,'label'=>$label,'description'=>$description,'lock_version'=>$next];
        });
    }

    public function publicLabel(string $code, string $requestedLocale): array
    {
        $locale = $this->locales->normalize($requestedLocale, true) ?? $this->locales->defaultLocale();
        $query = $this->db->prepare(
            'SELECT locale_code,label FROM portal_article_category_translations WHERE category_code=?'
        );
        $query->execute([$code]);
        $translations = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $translations[(string)$row['locale_code']] = (string)$row['label'];
        }
        foreach (array_values(array_unique([$locale,...$this->fallbackChain($locale),$this->locales->defaultLocale()])) as $candidate) {
            if (isset($translations[$candidate])) {
                return ['label'=>$translations[$candidate],'locale'=>$candidate];
            }
        }
        return ['label'=>'','locale'=>$locale];
    }

    public static function enabledCode(PDO $db, string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value)) {
            $query = $db->prepare('SELECT code FROM portal_article_categories WHERE code=?');
            $query->execute([$value]);
            $code = $query->fetchColumn();
            if (is_string($code)) return $code;
        }
        $legacyName = $value === '' ? '使用帮助' : $value;
        $legacyCode = 'legacy-'.substr(hash('sha256',$legacyName),0,24);
        $query = $db->prepare('SELECT code FROM portal_article_categories WHERE code=?');
        $query->execute([$legacyCode]);
        $code = $query->fetchColumn();
        if (is_string($code)) return $code;
        throw new Problem('article_category_invalid');
    }

    private function fallbackChain(string $locale): array
    {
        $chain = [];
        $visit = function (string $current) use (&$visit,&$chain): void {
            foreach ($this->locales->fallbacks($current) as $fallback) {
                if (in_array($fallback,$chain,true) || $fallback===$current) continue;
                $chain[] = $fallback;
                $visit($fallback);
            }
        };
        $visit($locale);
        return $chain;
    }
}
