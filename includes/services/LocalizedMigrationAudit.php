<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use PDOException;

final readonly class LocalizedMigrationAudit
{
    public function __construct(private PDO $db, private ?string $root = null)
    {
    }

    public function report(): array
    {
        return [
            'generated_at' => time(),
            'ledger' => $this->migrationLedger(),
            'domains' => [
                ...$this->articleAudit(),
                ...$this->productAudit(),
                ...$this->announcementAudit(),
                ...$this->navigationAudit(),
                ...$this->categoryAudit(),
                ...$this->siteAudit(),
                ...$this->policyAudit(),
            ],
        ];
    }

    private function migrationLedger(): array
    {
        $root = $this->root ?? D2S_ROOT;
        $files = glob($root . '/database/migrations/*.php') ?: [];
        $code = [];
        foreach ($files as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum !== false) $code[$version] = $checksum;
        }
        try {
            $rows = $this->db->query('SELECT version,checksum,status,applied_at FROM portal_migrations ORDER BY version')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [
                'available' => false, 'code_count' => count($code), 'database_count' => 0,
                'applied_count' => 0, 'pending_count' => count($code), 'incomplete_count' => 0,
                'checksum_mismatch_count' => 0, 'orphan_count' => 0, 'items' => [],
            ];
        }

        $database = [];
        foreach ($rows as $row) $database[(string)$row['version']] = $row;
        $versions = array_unique([...array_keys($code), ...array_keys($database)]);
        sort($versions, SORT_STRING);
        $items = [];
        $applied = $pending = $incomplete = $mismatches = $orphans = 0;
        foreach ($versions as $version) {
            $row = $database[$version] ?? null;
            $codeHash = $code[$version] ?? null;
            $dbHash = is_array($row) ? (string)$row['checksum'] : null;
            $status = is_array($row) ? (string)$row['status'] : 'pending';
            $hashStatus = $codeHash === null ? 'missing_code'
                : ($dbHash === null ? 'not_applied'
                    : (hash_equals($codeHash, $dbHash) ? 'match' : 'mismatch'));
            if ($row === null) $pending++;
            elseif ($status === 'applied') $applied++;
            else $incomplete++;
            if ($hashStatus === 'mismatch') $mismatches++;
            if ($hashStatus === 'missing_code') $orphans++;
            $items[] = [
                'version' => $version, 'status' => $status, 'hash_status' => $hashStatus,
                'code_hash' => $codeHash, 'database_hash' => $dbHash,
                'applied_at' => is_array($row) && $row['applied_at'] !== null ? (int)$row['applied_at'] : null,
            ];
        }
        return [
            'available' => true, 'code_count' => count($code), 'database_count' => count($database),
            'applied_count' => $applied, 'pending_count' => $pending, 'incomplete_count' => $incomplete,
            'checksum_mismatch_count' => $mismatches, 'orphan_count' => $orphans, 'items' => $items,
        ];
    }

    private function articleAudit(): array
    {
        $query = $this->db->query("SELECT a.id,a.status,
            a.title_zh,a.summary_zh,a.body_zh,a.title_en,a.summary_en,a.body_en,
            zh.draft_revision_id AS zh_draft,zh.published_revision_id AS zh_published,
            z.title AS zh_title,z.summary AS zh_summary,z.body AS zh_body,
            en.draft_revision_id AS en_draft,en.published_revision_id AS en_published,
            e.title AS en_title,e.summary AS en_summary,e.body AS en_body
            FROM portal_articles a
            LEFT JOIN portal_article_translations zh ON zh.article_id=a.id AND zh.locale_code='zh-Hans'
            LEFT JOIN portal_article_translation_revisions z ON z.id=zh.draft_revision_id
            LEFT JOIN portal_article_translations en ON en.article_id=a.id AND en.locale_code='en'
            LEFT JOIN portal_article_translation_revisions e ON e.id=en.draft_revision_id
            ORDER BY a.id");
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach (['zh-Hans'=>'zh','en'=>'en'] as $locale=>$prefix) {
            $legacy = $translated = $expectedPublished = $actualPublished = [];
            foreach ($rows as $row) {
                $id = (string)$row['id'];
                $old = [(string)$row['title_'.$prefix], (string)$row['summary_'.$prefix], (string)$row['body_'.$prefix]];
                $draftId = $row[$prefix.'_draft'];
                if (self::hasText($old)) {
                    $legacy[$id] = $old;
                    $expectedPublished[$id] = (string)$row['status'] === 'published';
                }
                if ($draftId !== null) {
                    $translated[$id] = [(string)$row[$prefix.'_title'], (string)$row[$prefix.'_summary'], (string)$row[$prefix.'_body']];
                    $actualPublished[$id] = $row[$prefix.'_published'] !== null;
                }
            }
            $result[] = self::compare('articles',$locale,$legacy,$translated,$expectedPublished,$actualPublished);
        }
        return $result;
    }

    private function productAudit(): array
    {
        $rows = $this->db->query("SELECT p.code,p.enabled,p.title_zh,p.description_zh,p.title_en,p.description_en,
            zh.draft_revision_id AS zh_draft,zh.published_revision_id AS zh_published,
            z.title AS zh_title,z.description AS zh_description,
            en.draft_revision_id AS en_draft,en.published_revision_id AS en_published,
            e.title AS en_title,e.description AS en_description
            FROM portal_products p
            LEFT JOIN portal_product_translations zh ON zh.product_code=p.code AND zh.locale_code='zh-Hans'
            LEFT JOIN portal_product_translation_revisions z ON z.id=zh.draft_revision_id
            LEFT JOIN portal_product_translations en ON en.product_code=p.code AND en.locale_code='en'
            LEFT JOIN portal_product_translation_revisions e ON e.id=en.draft_revision_id
            ORDER BY p.code")->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach (['zh-Hans'=>'zh','en'=>'en'] as $locale=>$prefix) {
            $legacy = $translated = $expectedPublished = $actualPublished = [];
            foreach ($rows as $row) {
                $id = (string)$row['code'];
                $old = [(string)$row['title_'.$prefix], (string)$row['description_'.$prefix]];
                if (self::hasText($old)) $legacy[$id] = $old;
                if ($row[$prefix.'_draft'] !== null) {
                    $translated[$id] = [(string)$row[$prefix.'_title'], (string)$row[$prefix.'_description']];
                }
            }
            $result[] = self::compare('products',$locale,$legacy,$translated);
        }
        return $result;
    }

    private function announcementAudit(): array
    {
        $legacy = [];
        $query = $this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute(['announcements.v1']);
        $value = $query->fetchColumn();
        $items = is_string($value) ? json_decode($value,true) : null;
        if (is_array($items)) {
            foreach (array_values($items) as $index=>$item) {
                if (!is_array($item) || !is_string($item['title']??null) || !is_string($item['body']??null)) continue;
                $title=trim($item['title']);$body=trim($item['body']);
                if ($title===''||$body==='') continue;
                $id=strtolower(substr(hash('sha256','announcements.v1:'.($index+1)),0,16));
                $legacy[$id]=[$title,$body];
            }
        }
        $translated=[];
        $published=[];
        $rows=$this->db->query("SELECT r.announcement_id,r.title,r.body,h.published_revision_id
            FROM portal_announcement_translation_revisions r
            LEFT JOIN portal_announcement_translations h
              ON h.announcement_id=r.announcement_id AND h.locale_code='zh-Hans'
            WHERE r.locale_code='zh-Hans' AND r.revision=1 AND r.origin='migration'
            ORDER BY r.announcement_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$row){
            $id=(string)$row['announcement_id'];
            $translated[$id]=[(string)$row['title'],(string)$row['body']];
            $published[$id]=$row['published_revision_id']!==null;
        }
        return [self::compare('announcements','zh-Hans',$legacy,$translated,array_fill_keys(array_keys($legacy),true),$published)];
    }

    private function navigationAudit(): array
    {
        $query=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute(['navigation.v1']);$value=$query->fetchColumn();
        $items=is_string($value)?json_decode($value,true):null;
        $result=[];
        foreach(['zh-Hans'=>'zh','en'=>'en']as$locale=>$suffix){
            $legacy=[];
            if(is_array($items))foreach($items as$item){
                if(!is_array($item)||!is_string($item['id']??null)||!is_string($item['label_'.$suffix]??null))continue;
                $label=trim($item['label_'.$suffix]);if($label!=='')$legacy[$item['id']]=[$label];
            }
            $query=$this->db->prepare('SELECT navigation_id,label FROM portal_navigation_translations WHERE locale_code=? ORDER BY navigation_id');
            $query->execute([$locale]);$translated=[];
            foreach($query->fetchAll(PDO::FETCH_ASSOC)as$row)$translated[(string)$row['navigation_id']]=[(string)$row['label']];
            $result[]=self::compare('navigation',$locale,$legacy,$translated);
        }
        return $result;
    }

    private function categoryAudit(): array
    {
        $legacy=[];
        $rows=$this->db->query("SELECT DISTINCT IFNULL(NULLIF(TRIM(category),''),'使用帮助') AS label
            FROM portal_articles ORDER BY label")->fetchAll(PDO::FETCH_COLUMN);
        foreach($rows as$label){
            $label=(string)$label;
            $id='legacy-'.substr(hash('sha256',$label),0,24);
            $legacy[$id]=[$label];
        }
        $translated=[];
        $rows=$this->db->query("SELECT c.code,t.label FROM portal_article_categories c
            JOIN portal_article_category_translations t ON t.category_code=c.code
            WHERE c.code LIKE 'legacy-%' AND t.locale_code='zh-Hans' ORDER BY c.code")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$row)$translated[(string)$row['code']]=[(string)$row['label']];
        return [self::compare('categories','zh-Hans',$legacy,$translated)];
    }

    private function siteAudit(): array
    {
        $query=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute(['site.v1']);$value=$query->fetchColumn();
        $site=is_string($value)?json_decode($value,true):null;
        if(!is_array($site)||array_is_list($site))$site=[];
        $result=[];
        foreach(['zh-Hans'=>'zh','en'=>'en']as$locale=>$suffix){
            $legacy=[];
            $tagline=trim((string)($site['tagline_'.$suffix]??''));$intro=trim((string)($site['intro_'.$suffix]??''));
            if($tagline!==''&&$intro!=='')$legacy['site-copy']=[$tagline,$intro];
            $query=$this->db->prepare('SELECT tagline,intro FROM portal_site_translations WHERE locale_code=?');
            $query->execute([$locale]);$row=$query->fetch(PDO::FETCH_ASSOC);$translated=[];
            if(is_array($row))$translated['site-copy']=[(string)$row['tagline'],(string)$row['intro']];
            $result[]=self::compare('site',$locale,$legacy,$translated,[],[],array_keys($legacy));
        }
        return $result;
    }

    private function policyAudit(): array
    {
        $query=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute(['site.v1']);$value=$query->fetchColumn();
        $site=is_string($value)?json_decode($value,true):null;
        if(!is_array($site))$site=SiteContent::defaultSite();
        $versions=['PRIVACY'=>'privacy_version','TERMS'=>'terms_version','LEGAL'=>'legal_version'];
        $legacy=[];
        foreach($versions as$type=>$key){
            $version=trim((string)($site[$key]??''));
            if($version==='')continue;
            $document=PolicyText::document($type,'zh-Hans',$site);
            $legacy[$type.':'.$version]=[$version,(string)($site[strtolower($type).'_effective']??''),$document['title'],$document['body']];
        }
        $translated=[];$expected=array_fill_keys(array_keys($legacy),true);$actual=[];
        $rows=$this->db->query("SELECT d.policy_type,d.policy_version,d.effective_date,d.source_locale,
            h.published_revision_id,r.title,r.body
            FROM portal_policy_documents d
            LEFT JOIN portal_policy_translation_heads h
              ON h.policy_type=d.policy_type AND h.policy_version=d.policy_version AND h.locale_code=d.source_locale
            LEFT JOIN portal_policy_translation_revisions r ON r.id=COALESCE(h.draft_revision_id,h.published_revision_id)
            ORDER BY d.policy_type,d.policy_version")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$row){
            $id=(string)$row['policy_type'].':'.(string)$row['policy_version'];
            if(!array_key_exists($id,$legacy)||$row['title']===null||$row['body']===null)continue;
            $translated[$id]=[(string)$row['policy_version'],(string)$row['effective_date'],(string)$row['title'],(string)$row['body']];
            $actual[$id]=$row['published_revision_id']!==null;
        }
        return [self::compare('policies','zh-Hans',$legacy,$translated,$expected,$actual)];
    }

    private static function compare(string $domain,string $locale,array $legacy,array $translated,
        array $expectedPublished=[],array $actualPublished=[],?array $comparisonIds=null):array
    {
        ksort($legacy,SORT_STRING);ksort($translated,SORT_STRING);
        $ids=$comparisonIds===null
            ?array_unique([...array_keys($legacy),...array_keys($translated)])
            :array_unique($comparisonIds);
        sort($ids,SORT_STRING);
        $legacyContext=hash_init('sha256');$translatedContext=hash_init('sha256');
        foreach($legacy as$id=>$fields)hash_update($legacyContext,self::canonical([$id,$fields])."\n");
        foreach($translated as$id=>$fields)hash_update($translatedContext,self::canonical([$id,$fields])."\n");
        $mismatches=[];$fieldMismatch=0;$publicationMismatch=0;$matched=0;
        foreach($ids as$id){
            $hasLegacy=array_key_exists($id,$legacy);$hasNew=array_key_exists($id,$translated);
            $legacyHash=$hasLegacy?hash('sha256',self::canonical($legacy[$id])):null;
            $newHash=$hasNew?hash('sha256',self::canonical($translated[$id])):null;
            if($hasLegacy&&$hasNew&&hash_equals((string)$legacyHash,(string)$newHash))$matched++;
            else{
                $fieldMismatch++;
                if(count($mismatches)<100)$mismatches[]=['id'=>$id,'legacy_hash'=>$legacyHash,'translated_hash'=>$newHash];
            }
            if(array_key_exists($id,$expectedPublished)
                &&(bool)$expectedPublished[$id] !== (bool)($actualPublished[$id] ?? false))$publicationMismatch++;
        }
        return [
            'domain'=>$domain,'locale'=>$locale,'legacy_count'=>count($legacy),'translated_count'=>count($translated),
            'matched_count'=>$matched,'field_mismatch_count'=>$fieldMismatch,
            'publication_check_available'=>$expectedPublished!==[],
            'publication_mismatch_count'=>$publicationMismatch,
            'legacy_hash'=>hash_final($legacyContext),'translated_hash'=>hash_final($translatedContext),
            'mismatch_records'=>$mismatches,'mismatch_records_truncated'=>$fieldMismatch>count($mismatches),
        ];
    }

    private static function hasText(array $fields):bool
    {
        foreach($fields as$field)if($field!=='')return true;
        return false;
    }

    private static function canonical(mixed $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
