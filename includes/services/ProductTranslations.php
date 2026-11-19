<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ProductTranslations
{
    public function __construct(private PDO $db, private LocaleRegistry $locales = new LocaleRegistry())
    {
    }

    public function catalog(int $adminId, string $productCode): array
    {
        Permissions::require($this->db, $adminId, 'products.manage');
        $product = $this->product($productCode);
        $query = $this->db->prepare(
            'SELECT t.locale_code,t.draft_revision_id,t.published_revision_id,t.lock_version,t.updated_at,
                d.title draft_title,d.source_revision draft_source_revision,
                p.title published_title,p.revision published_revision,p.source_revision published_source_revision
             FROM portal_product_translations t
             LEFT JOIN portal_product_translation_revisions d ON d.id=t.draft_revision_id
             LEFT JOIN portal_product_translation_revisions p ON p.id=t.published_revision_id
             WHERE t.product_code=? ORDER BY t.locale_code'
        );
        $query->execute([$productCode]);
        $items = [];
        foreach ($query->fetchAll() as $row) {
            $locale = $this->locales->get((string)$row['locale_code']);
            $items[] = [
                'locale' => $row['locale_code'],
                'native_name' => $locale['native_name'] ?? (string)$row['locale_code'],
                'draft_revision_id' => $row['draft_revision_id'] === null ? null : (int)$row['draft_revision_id'],
                'published_revision_id' => $row['published_revision_id'] === null ? null : (int)$row['published_revision_id'],
                'lock_version' => (int)$row['lock_version'],
                'updated_at' => (int)$row['updated_at'],
                'draft_title' => $row['draft_title'],
                'published_title' => $row['published_title'],
                'published_revision' => $row['published_revision'] === null ? null : (int)$row['published_revision'],
                'outdated' => $row['draft_source_revision'] !== null
                    && (int)$row['draft_source_revision'] < (int)$product['source_revision'],
            ];
        }
        return [
            'product_code' => $productCode,
            'source_locale' => $product['source_locale'],
            'source_revision' => (int)$product['source_revision'],
            'items' => $items,
        ];
    }

    public function locales(int $adminId): array
    {
        Permissions::require($this->db, $adminId, 'products.manage');
        return ['items' => array_map(static fn(array $locale): array => [
            'code' => $locale['code'], 'native_name' => $locale['native_name'],
            'enabled' => (bool)$locale['enabled'], 'public_enabled' => (bool)$locale['public_enabled'], 'direction' => $locale['direction'],
        ], $this->locales->all())];
    }

    public function get(int $adminId, string $productCode, string $requestedLocale): array
    {
        Permissions::require($this->db, $adminId, 'products.manage');
        $locale = $this->locale($requestedLocale);
        $product = $this->product($productCode);
        $query = $this->db->prepare(
            'SELECT t.draft_revision_id,t.published_revision_id,t.lock_version,
                d.revision,d.title,d.description,d.source_revision
             FROM portal_product_translations t
             LEFT JOIN portal_product_translation_revisions d ON d.id=t.draft_revision_id
             WHERE t.product_code=? AND t.locale_code=?'
        );
        $query->execute([$productCode, $locale]);
        $row = $query->fetch() ?: [];
        return [
            'product_code' => $productCode, 'locale' => $locale,
            'source_locale' => $product['source_locale'], 'source_revision' => (int)$product['source_revision'],
            'enabled' => (int)$product['enabled'],
            'draft_revision_id' => $row['draft_revision_id'] ?? null,
            'published_revision_id' => $row['published_revision_id'] ?? null,
            'lock_version' => isset($row['lock_version']) ? (int)$row['lock_version'] : 0,
            'revision' => isset($row['revision']) ? (int)$row['revision'] : 0,
            'title' => $row['title'] ?? '', 'description' => $row['description'] ?? '',
            'outdated' => isset($row['source_revision']) && (int)$row['source_revision'] < (int)$product['source_revision'],
        ];
    }

    public function save(int $adminId, string $productCode, string $requestedLocale, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'products.manage');
        $locale = $this->locale($requestedLocale);
        $title = Backoffice::text($input, 'title', 160, true);
        $description = Backoffice::text($input, 'description', 5000);
        $enabled = $input['enabled'] ?? null;
        if ($enabled !== null && !is_bool($enabled)) throw new Problem('field_invalid');
        $expectedVersion = filter_var($input['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($expectedVersion === false) throw new Problem('record_changed', 409);
        return Database::transactionOrJoin($this->db, function (PDO $db) use (
            $adminId, $productCode, $locale, $title, $description, $enabled, $expectedVersion, $context, $now
        ): array {
            $query = $db->prepare('SELECT code,source_locale,source_revision,title_zh,description_zh,title_en,description_en,enabled,updated_at FROM portal_products WHERE code=? FOR UPDATE');
            $query->execute([$productCode]);
            $product = $query->fetch();
            if (!$product) throw new Problem('product_not_found', 404);
            $query = $db->prepare('SELECT draft_revision_id,published_revision_id,lock_version FROM portal_product_translations WHERE product_code=? AND locale_code=? FOR UPDATE');
            $query->execute([$productCode, $locale]);
            $current = $query->fetch() ?: null;
            $version = (int)($current['lock_version'] ?? 0);
            if ($version !== $expectedVersion) throw new Problem('record_changed', 409);
            $revisionQuery = $db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_product_translation_revisions WHERE product_code=? AND locale_code=?');
            $revisionQuery->execute([$productCode, $locale]);
            $revision = (int)$revisionQuery->fetchColumn();
            $isSource = $product['source_locale'] === $locale;
            $sourceRevision = (int)$product['source_revision'];
            if ($isSource) {
                $sourceHash = self::sourceHash($title, $description);
            } else {
                $sourceQuery = $db->prepare(
                    'SELECT r.title,r.description FROM portal_product_translations t
                     JOIN portal_product_translation_revisions r ON r.id=t.draft_revision_id
                     WHERE t.product_code=? AND t.locale_code=?'
                );
                $sourceQuery->execute([$productCode,$product['source_locale']]);
                $source = $sourceQuery->fetch();
                if (!$source) throw new Problem('product_source_missing', 409);
                $sourceHash = self::sourceHash((string)$source['title'],(string)$source['description']);
            }
            if ($isSource) {
                $previous = null;
                if ($current && $current['draft_revision_id'] !== null) {
                    $previousQuery = $db->prepare('SELECT title,description FROM portal_product_translation_revisions WHERE id=?');
                    $previousQuery->execute([$current['draft_revision_id']]);
                    $previous = $previousQuery->fetch() ?: null;
                }
                $oldTitle = $previous['title'] ?? ($locale === 'en' ? $product['title_en'] : ($locale === 'zh-Hans' ? $product['title_zh'] : null));
                $oldDescription = $previous['description'] ?? ($locale === 'en' ? $product['description_en'] : ($locale === 'zh-Hans' ? $product['description_zh'] : null));
                if ($oldTitle !== null && ($oldTitle !== $title || $oldDescription !== $description)) $sourceRevision++;
            }
            $db->prepare(
                "INSERT INTO portal_product_translation_revisions
                    (product_code,locale_code,revision,title,description,source_revision,source_hash,origin,author_admin_id,created_at)
                 VALUES(?,?,?,?,?,?,?,'manual',?,?)"
            )->execute([$productCode,$locale,$revision,$title,$description,$sourceRevision,$sourceHash,$adminId,$now]);
            $revisionId = (int)$db->lastInsertId();
            $nextVersion = $version + 1;
            $db->prepare(
                'INSERT INTO portal_product_translations(product_code,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                 VALUES(?,?,?,NULL,?,?) ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)'
            )->execute([$productCode,$locale,$revisionId,$nextVersion,$now]);
            if (in_array($locale,['zh-Hans','en'],true)) {
                $titleColumn = $locale === 'en' ? 'title_en' : 'title_zh';
                $descriptionColumn = $locale === 'en' ? 'description_en' : 'description_zh';
                $db->prepare("UPDATE portal_products SET $titleColumn=?,$descriptionColumn=?,source_revision=?,enabled=COALESCE(?,enabled),updated_at=? WHERE code=?")
                    ->execute([$title,$description,$sourceRevision,$enabled===null?null:(int)$enabled,max($now,(int)$product['updated_at']+1),$productCode]);
            } else {
                $db->prepare('UPDATE portal_products SET source_revision=?,enabled=COALESCE(?,enabled),updated_at=? WHERE code=?')
                    ->execute([$sourceRevision,$enabled===null?null:(int)$enabled,max($now,(int)$product['updated_at']+1),$productCode]);
            }
            (new Backoffice($db))->audit($adminId, 'SAVE_PRODUCT_TRANSLATION', $productCode, [
                'locale' => $locale, 'revision' => $revision, 'source_revision' => $sourceRevision,
                ...($enabled === null ? [] : ['enabled'=>$enabled]),
            ], $context, $now);
            return ['product_code'=>$productCode,'locale'=>$locale,'revision_id'=>$revisionId,'revision'=>$revision,
                'lock_version'=>$nextVersion,'source_revision'=>$sourceRevision];
        });
    }

    public function publish(int $adminId, string $productCode, string $requestedLocale, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'products.manage');
        $locale = $this->locale($requestedLocale);
        return Database::transactionOrJoin($this->db, function (PDO $db) use ($adminId,$productCode,$locale,$context,$now): array {
            $product = $this->product($productCode);
            $query = $db->prepare('SELECT draft_revision_id FROM portal_product_translations WHERE product_code=? AND locale_code=? FOR UPDATE');
            $query->execute([$productCode,$locale]);
            $translation = $query->fetch();
            if (!$translation || $translation['draft_revision_id'] === null) throw new Problem('product_translation_draft_missing', 409);
            $db->prepare('UPDATE portal_product_translations SET published_revision_id=?,lock_version=lock_version+1,updated_at=? WHERE product_code=? AND locale_code=?')
                ->execute([$translation['draft_revision_id'],$now,$productCode,$locale]);
            (new Backoffice($db))->audit($adminId,'PUBLISH_PRODUCT_TRANSLATION',$productCode,['locale'=>$locale],$context,$now);
            return ['product_code'=>$productCode,'locale'=>$locale,'published_revision_id'=>(int)$translation['draft_revision_id']];
        });
    }

    public function publicContent(string $productCode, string $requestedLocale): ?array
    {
        $product = $this->product($productCode);
        $locale = $this->locales->normalize($requestedLocale, true) ?? $this->locales->defaultLocale();
        $candidates = (new ContentPublicationLanguages($this->db))->candidates('product',$productCode,$locale,(string)$product['source_locale']);
        $query = $this->db->prepare(
            'SELECT r.title,r.description,r.revision,r.source_revision
             FROM portal_product_translations t
             JOIN portal_product_translation_revisions r
                ON r.id=t.published_revision_id AND r.product_code=t.product_code AND r.locale_code=t.locale_code
             WHERE t.product_code=? AND t.locale_code=?'
        );
        foreach (array_unique($candidates) as $candidate) {
            $query->execute([$productCode,$candidate]);
            $row = $query->fetch();
            if ($row) return [
                'title'=>$row['title'],'description'=>$row['description'],'content_locale'=>$candidate,
                'requested_locale'=>$locale,'fallback_used'=>$candidate!==$locale,
                'published_revision'=>(int)$row['revision'],'source_revision'=>(int)$product['source_revision'],
                'outdated'=>(int)$row['source_revision']<(int)$product['source_revision'],
            ];
        }
        return null;
    }

    private function product(string $code): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{2,31}$/D',$code) || $code==='wallet_recharge') throw new Problem('product_not_found', 404);
        $query = $this->db->prepare('SELECT * FROM portal_products WHERE code=?');
        $query->execute([$code]);
        $product = $query->fetch();
        if (!$product) throw new Problem('product_not_found', 404);
        return $product;
    }

    private function locale(string $requested): string
    {
        $locale = $this->locales->normalize($requested);
        if ($locale === null) throw new Problem('locale_unsupported', 400);
        return $locale;
    }

    private function fallbackChain(string $locale): array
    {
        $chain = [];
        $visit = function (string $current) use (&$visit,&$chain): void {
            foreach ($this->locales->fallbacks($current) as $fallback) {
                if (in_array($fallback,$chain,true) || $fallback === $current) continue;
                $chain[] = $fallback;
                $visit($fallback);
            }
        };
        $visit($locale);
        return [$locale,...$chain];
    }

    private static function sourceHash(string $title, string $description): string
    {
        return ContentSourceHash::product($title,$description);
    }
}
