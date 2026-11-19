<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class PolicyTranslations
{
    private const POLICY_FIELDS = [
        'PRIVACY' => ['version'=>'privacy_version','date'=>'privacy_effective'],
        'TERMS' => ['version'=>'terms_version','date'=>'terms_effective'],
        'LEGAL' => ['version'=>'legal_version','date'=>'legal_effective'],
    ];

    public function __construct(private PDO $db, private LocaleRegistry $locales = new LocaleRegistry())
    {
    }

    public function isAvailable(): bool
    {
        $query=$this->db->prepare('SELECT status FROM portal_migrations WHERE version=?');
        $query->execute(['0037_policy_translations.php']);
        return $query->fetchColumn()==='applied';
    }

    public function adminCatalog(int $adminId, string $policyType): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $type=$this->policyType($policyType);
        $current=$this->currentDocument($this->db,$type);
        $query=$this->db->prepare('SELECT h.locale_code,h.draft_revision_id,h.published_revision_id,h.lock_version,
                d.title AS draft_title,p.title AS published_title,d.source_revision AS draft_source_revision
            FROM portal_policy_translation_heads h
            LEFT JOIN portal_policy_translation_revisions d ON d.id=h.draft_revision_id
            LEFT JOIN portal_policy_translation_revisions p ON p.id=h.published_revision_id
            WHERE h.policy_type=? AND h.policy_version=? ORDER BY h.locale_code');
        $query->execute([$type,$current['version']]);
        $items=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
            $locale=$this->locales->get((string)$row['locale_code']);
            $items[]=[
                'locale'=>(string)$row['locale_code'],
                'native_name'=>(string)($locale['native_name']??$row['locale_code']),
                'draft_revision_id'=>$row['draft_revision_id']===null?null:(int)$row['draft_revision_id'],
                'published_revision_id'=>$row['published_revision_id']===null?null:(int)$row['published_revision_id'],
                'lock_version'=>(int)$row['lock_version'],
                'draft_title'=>(string)($row['draft_title']??''),
                'published_title'=>(string)($row['published_title']??''),
                'outdated'=>$row['draft_source_revision']!==null
                    && (int)$row['draft_source_revision']<(int)$current['source_revision'],
            ];
        }
        return ['policy_type'=>$type,'version'=>$current['version'],'effective_date'=>$current['effective_date'],
            'resource_id'=>substr(hash('sha256',$type."\n".$current['version']),0,32),
            'source_locale'=>$current['source_locale'],'source_revision'=>(int)$current['source_revision'],
            'publication_locales'=>(new ContentPublicationLanguages($this->db))->selected('policy',substr(hash('sha256',$type."\n".$current['version']),0,32))??[$current['source_locale'],'en'],
            'items'=>$items,'locales'=>$this->locales->enabledForAdmin()];
    }

    public function get(int $adminId, string $policyType, string $localeCode): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $type=$this->policyType($policyType);
        $locale=$this->adminLocale($localeCode);
        $document=$this->currentDocument($this->db,$type);
        $query=$this->db->prepare('SELECT h.draft_revision_id,h.published_revision_id,h.lock_version,
                d.revision,d.title,d.body,d.source_revision,d.source_hash,
                p.revision AS published_revision,p.title AS published_title,p.body AS published_body
            FROM portal_policy_translation_heads h
            LEFT JOIN portal_policy_translation_revisions d ON d.id=h.draft_revision_id
            LEFT JOIN portal_policy_translation_revisions p ON p.id=h.published_revision_id
            WHERE h.policy_type=? AND h.policy_version=? AND h.locale_code=?');
        $query->execute([$type,$document['version'],$locale]);
        $row=$query->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'policy_type'=>$type,'policy_version'=>$document['version'],'effective_date'=>$document['effective_date'],
            'locale'=>$locale,'source_locale'=>$document['source_locale'],
            'source_revision'=>(int)$document['source_revision'],
            'draft_revision_id'=>isset($row['draft_revision_id'])?(int)$row['draft_revision_id']:null,
            'published_revision_id'=>isset($row['published_revision_id'])?(int)$row['published_revision_id']:null,
            'lock_version'=>(int)($row['lock_version']??0),'revision'=>(int)($row['revision']??0),
            'title'=>(string)($row['title']??''),'body'=>(string)($row['body']??''),
            'published_revision'=>isset($row['published_revision'])?(int)$row['published_revision']:null,
            'published_title'=>(string)($row['published_title']??''),
            'published_body'=>(string)($row['published_body']??''),
            'outdated'=>isset($row['source_revision'])&&(int)$row['source_revision']<(int)$document['source_revision'],
        ];
    }

    public function save(int $adminId, string $policyType, string $policyVersion, string $localeCode,
        string $title, string $body, int $expectedLockVersion, array $context, int $now, bool $publish): array
    {
        $type=$this->policyType($policyType);
        $locale=$this->adminLocale($localeCode);
        $title=trim($title);
        $body=trim($body);
        if($title===''||mb_strlen($title,'UTF-8')>180||$body===''||mb_strlen($body,'UTF-8')>50000
            ||strlen($body)>150000||$expectedLockVersion<0){
            throw new Problem('policy_translation_invalid');
        }
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$type,$policyVersion,$locale,$title,$body,
            $expectedLockVersion,$context,$now,$publish):array{
            Permissions::require($db,$adminId,'settings.manage');
            $document=$this->currentDocument($db,$type,true);
            if(!hash_equals((string)$document['version'],$policyVersion))throw new Problem('record_changed',409);
            $db->prepare('INSERT IGNORE INTO portal_policy_documents
                (policy_type,policy_version,effective_date,source_locale,source_revision,lock_version,created_at,updated_at)
                VALUES (?,?,?,?,0,1,?,?)')->execute([$type,$document['version'],$document['effective_date'],
                    $document['source_locale'],$now,$now]);
            $lock=$db->prepare('SELECT draft_revision_id,published_revision_id,lock_version FROM portal_policy_translation_heads
                WHERE policy_type=? AND policy_version=? AND locale_code=? FOR UPDATE');
            $lock->execute([$type,$document['version'],$locale]);
            $head=$lock->fetch(PDO::FETCH_ASSOC)?:null;
            $actual=(int)($head['lock_version']??0);
            if($actual!==$expectedLockVersion)throw new Problem('record_changed',409);
            $sourceHash=hash('sha256',json_encode([$title,$body],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $sourceRevision=(int)$document['source_revision'];
            if($locale===$document['source_locale']){
                $sourceRevision++;
                $db->prepare('UPDATE portal_policy_documents SET source_revision=?,lock_version=lock_version+1,updated_at=?
                    WHERE policy_type=? AND policy_version=?')->execute([$sourceRevision,$now,$type,$document['version']]);
            }else{
                $source=$db->prepare('SELECT r.source_hash,h.published_revision_id FROM portal_policy_translation_heads h
                    JOIN portal_policy_translation_revisions r ON r.id=COALESCE(h.draft_revision_id,h.published_revision_id)
                    WHERE h.policy_type=? AND h.policy_version=? AND h.locale_code=?');
                $source->execute([$type,$document['version'],$document['source_locale']]);
                $sourceRow=$source->fetch(PDO::FETCH_ASSOC);
                if(!is_array($sourceRow)||!is_string($sourceRow['source_hash'])||$sourceRow['source_hash']===''
                    ||($publish&&$sourceRow['published_revision_id']===null))throw new Problem('policy_source_missing',409);
                $sourceHash=(string)$sourceRow['source_hash'];
            }
            $revisionQuery=$db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_policy_translation_revisions
                WHERE policy_type=? AND policy_version=? AND locale_code=?');
            $revisionQuery->execute([$type,$document['version'],$locale]);
            $revision=(int)$revisionQuery->fetchColumn();
            $db->prepare('INSERT INTO portal_policy_translation_revisions
                (policy_type,policy_version,locale_code,revision,title,body,source_revision,source_hash,origin,author_admin_id,created_at)
                VALUES (?,?,?,?,?,?,?,?,\'manual\',?,?)')->execute([$type,$document['version'],$locale,$revision,$title,$body,
                    $sourceRevision,$sourceHash,$adminId,$now]);
            $revisionId=(int)$db->lastInsertId();
            $published=$publish?$revisionId:($head['published_revision_id']??null);
            $db->prepare('INSERT INTO portal_policy_translation_heads
                (policy_type,policy_version,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),
                    published_revision_id=VALUES(published_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)')
                ->execute([$type,$document['version'],$locale,$revisionId,$published,$actual+1,$now]);
            if($publish){
                $publicPath=match($type){
                    'PRIVACY'=>'/privacy-policy','TERMS'=>'/terms','LEGAL'=>'/legal-statement',
                };
                EdgeOneCachePurge::enqueuePaths($db,[$publicPath,'/sitemap.xml'],
                    'policy:'.$type.':'.bin2hex(random_bytes(16)),$now);
            }
            return ['policy_type'=>$type,'policy_version'=>$document['version'],'locale'=>$locale,
                'revision'=>$revision,'lock_version'=>$actual+1,'published'=>$publish];
        });
    }

    public function deleteAllLanguages(int $adminId,string $policyType,string $version,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $type=$this->policyType($policyType);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$type,$version,$context,$now):array{
            $document=$this->currentDocument($db,$type,true);
            if($document['version']!==$version)throw new Problem('record_changed',409);
            $db->prepare('UPDATE portal_policy_documents SET source_revision=source_revision+1,lock_version=lock_version+1,updated_at=?
                WHERE policy_type=? AND policy_version=?')->execute([$now,$type,$version]);
            $db->prepare('UPDATE portal_policy_translation_heads SET draft_revision_id=NULL,published_revision_id=NULL,
                lock_version=lock_version+1,updated_at=? WHERE policy_type=? AND policy_version=?')->execute([$now,$type,$version]);
            $id=substr(hash('sha256',$type."\n".$version),0,32);
            (new ContentPublicationLanguages($db))->cancel('policy',$id,$now);
            (new Backoffice($db))->audit($adminId,'DELETE_POLICY_ALL_LANGUAGES',$type.':'.$version,[],$context,$now);
            $path=match($type){'PRIVACY'=>'/privacy-policy','TERMS'=>'/terms','LEGAL'=>'/legal-statement'};
            EdgeOneCachePurge::enqueuePaths($db,[$path,'/sitemap.xml'],'policy:delete:'.$id.':'.$now,$now);
            return ['deleted'=>true,'policy_type'=>$type,'policy_version'=>$version];
        });
    }

    public function seedLegacyCurrentVersions(): int
    {
        if(!$this->isAvailable())throw new Problem('policy_translation_unavailable',503);
        return Database::transaction($this->db,function(PDO $db):int{
            $settings=$db->prepare('SELECT value_json FROM portal_settings WHERE name=\'site.v1\' FOR UPDATE');
            $settings->execute();
            $raw=$settings->fetchColumn();
            $site=SiteContent::defaultSite();
            if(is_string($raw)){
                try{$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(is_array($decoded))$site=array_replace($site,$decoded);}
                catch(\JsonException){throw new Problem('site_content_invalid',503);}
            }
            $now=time();
            $seeded=0;
            foreach(self::POLICY_FIELDS as $type=>$fields){
                $version=(string)($site[$fields['version']]??'');
                if(!preg_match('/^[A-Za-z0-9._+-]{1,40}$/D',$version))throw new Problem('legal_policy_unavailable',503);
                $sourceLocale='zh-Hans';
                $effectiveDate=(string)($site[$fields['date']]??'');
                $db->prepare('INSERT IGNORE INTO portal_policy_documents
                    (policy_type,policy_version,effective_date,source_locale,source_revision,lock_version,created_at,updated_at)
                    VALUES (?,?,?, ?,0,1,?,?)')->execute([$type,$version,$effectiveDate,$sourceLocale,$now,$now]);
                $documentQuery=$db->prepare('SELECT source_locale,source_revision FROM portal_policy_documents
                    WHERE policy_type=? AND policy_version=? FOR UPDATE');
                $documentQuery->execute([$type,$version]);
                $document=$documentQuery->fetch(PDO::FETCH_ASSOC);
                if(!is_array($document))throw new Problem('policy_translation_unavailable',503);
                $sourceLocale=(string)$document['source_locale'];
                $sourceHead=$db->prepare('SELECT h.draft_revision_id,h.published_revision_id,r.source_hash
                    FROM portal_policy_translation_heads h
                    LEFT JOIN portal_policy_translation_revisions r
                      ON r.id=COALESCE(h.draft_revision_id,h.published_revision_id)
                    WHERE h.policy_type=? AND h.policy_version=? AND h.locale_code=?');
                $sourceHead->execute([$type,$version,$sourceLocale]);
                $sourceRow=$sourceHead->fetch(PDO::FETCH_ASSOC)?:null;
                $sourceRevision=(int)$document['source_revision'];
                if($sourceRow===null){
                    $sourceContent=PolicyText::document($type,$sourceLocale,$site);
                    $sourceHash=hash('sha256',json_encode([$sourceContent['title'],$sourceContent['body']],
                        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    $sourceRevision=max(1,$sourceRevision+1);
                    $revisionId=$this->insertLegacyRevision($db,$type,$version,$sourceLocale,$sourceRevision,
                        $sourceContent,$sourceHash,$now);
                    $db->prepare('INSERT INTO portal_policy_translation_heads
                        (policy_type,policy_version,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                        VALUES (?,?,?,?,?,1,?)')->execute([$type,$version,$sourceLocale,$revisionId,$revisionId,$now]);
                    $db->prepare('UPDATE portal_policy_documents SET source_revision=?,lock_version=lock_version+1,updated_at=?
                        WHERE policy_type=? AND policy_version=?')->execute([$sourceRevision,$now,$type,$version]);
                    $seeded++;
                }else{
                    $sourceContent=PolicyText::document($type,$sourceLocale,$site);
                    $sourceHash=is_string($sourceRow['source_hash']??null)&&$sourceRow['source_hash']!==''
                        ? (string)$sourceRow['source_hash']
                        : hash('sha256',json_encode([$sourceContent['title'],$sourceContent['body']],
                            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    $sourceRevision=max(1,$sourceRevision);
                    if($sourceRevision>(int)$document['source_revision']){
                        $db->prepare('UPDATE portal_policy_documents SET source_revision=?,lock_version=lock_version+1,updated_at=?
                            WHERE policy_type=? AND policy_version=?')->execute([$sourceRevision,$now,$type,$version]);
                    }
                }
                foreach(['zh-Hans','en'] as $locale){
                    if($locale===$sourceLocale)continue;
                    $exists=$db->prepare('SELECT 1 FROM portal_policy_translation_heads
                        WHERE policy_type=? AND policy_version=? AND locale_code=? FOR UPDATE');
                    $exists->execute([$type,$version,$locale]);
                    if($exists->fetchColumn())continue;
                    $legacy=PolicyText::document($type,$locale,$site);
                    $revisionId=$this->insertLegacyRevision($db,$type,$version,$locale,$sourceRevision,
                        $legacy,$sourceHash,$now);
                    $db->prepare('INSERT INTO portal_policy_translation_heads
                        (policy_type,policy_version,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                        VALUES (?,?,?,?,?,1,?)')->execute([$type,$version,$locale,$revisionId,$revisionId,$now]);
                    $seeded++;
                }
            }
            return $seeded;
        });
    }

    private function insertLegacyRevision(PDO $db,string $type,string $version,string $locale,int $sourceRevision,
        array $content,string $sourceHash,int $now): int
    {
        $db->prepare('INSERT INTO portal_policy_translation_revisions
            (policy_type,policy_version,locale_code,revision,title,body,source_revision,source_hash,origin,author_admin_id,created_at)
            VALUES (?,?,?,1,?,?,?, ?,\'migration\',NULL,?)')
            ->execute([$type,$version,$locale,$content['title'],$content['body'],$sourceRevision,$sourceHash,$now]);
        return (int)$db->lastInsertId();
    }

    public function publishedLocales(string $policyType): array
    {
        $type=$this->policyType($policyType);
        $document=$this->currentDocument($this->db,$type);
        $query=$this->db->prepare('SELECT DISTINCT h.locale_code FROM portal_policy_translation_heads h
            JOIN portal_policy_translation_revisions r
              ON r.id=h.published_revision_id AND r.policy_type=h.policy_type
             AND r.policy_version=h.policy_version AND r.locale_code=h.locale_code
            WHERE h.policy_type=? AND h.policy_version=? AND h.published_revision_id IS NOT NULL');
        $query->execute([$type,$document['version']]);
        $codes=array_map('strval',$query->fetchAll(PDO::FETCH_COLUMN));
        $selected=(new ContentPublicationLanguages($this->db))->selected('policy',substr(hash('sha256',$type."\n".$document['version']),0,32));
        $published=array_fill_keys($selected===null?$codes:array_intersect($codes,$selected),true);
        return array_values(array_map(static fn(array $locale):string=>(string)$locale['code'],array_filter(
            $this->locales->enabled(true),static fn(array $locale):bool=>isset($published[(string)$locale['code']])
        )));
    }

    public function publicContent(string $policyType, string $requestedLocale): ?array
    {
        $type=$this->policyType($policyType);
        $document=$this->currentDocument($this->db,$type);
        $locale=$this->locales->normalize($requestedLocale,true)??$this->locales->defaultLocale();
        $publicCodes=array_column($this->locales->enabled(true),'code');
        $resourceId=substr(hash('sha256',$type."\n".$document['version']),0,32);
        $chain=array_values(array_filter((new ContentPublicationLanguages($this->db))->candidates('policy',$resourceId,$locale,$document['source_locale']),static fn(string $code):bool=>in_array($code,$publicCodes,true)));
        if($chain===[])return null;
        $marks=implode(',',array_fill(0,count($chain),'?'));
        $query=$this->db->prepare("SELECT h.locale_code,r.title,r.body,r.revision
            FROM portal_policy_translation_heads h
            JOIN portal_policy_translation_revisions r
              ON r.id=h.published_revision_id AND r.policy_type=h.policy_type
             AND r.policy_version=h.policy_version AND r.locale_code=h.locale_code
            WHERE h.policy_type=? AND h.policy_version=? AND h.locale_code IN ($marks)");
        $query->execute([$type,$document['version'],...$chain]);
        $rows=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[(string)$row['locale_code']]=$row;
        foreach($chain as $candidate){
            if(!isset($rows[$candidate]))continue;
            $row=$rows[$candidate];
            $definition=$this->locales->get($candidate);
            return ['policy_type'=>$type,'version'=>$document['version'],'effective_date'=>$document['effective_date'],
                'locale'=>$candidate,'locale_name'=>(string)($definition['native_name']??$candidate),
                'requested_locale'=>$locale,'fallback'=>$candidate!==$locale,
                'title'=>(string)$row['title'],'body'=>(string)$row['body'],'revision'=>(int)$row['revision']];
        }
        return null;
    }

    private function currentDocument(PDO $db, string $type, bool $lock = false): array
    {
        $fields=self::POLICY_FIELDS[$type];
        $settings=$db->prepare('SELECT value_json FROM portal_settings WHERE name=\'site.v1\''.($lock?' FOR UPDATE':''));
        $settings->execute();
        $raw=$settings->fetchColumn();
        $site=SiteContent::defaultSite();
        if(is_string($raw)){
            try{$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(is_array($decoded))$site=array_replace($site,$decoded);}
            catch(\JsonException){throw new Problem('site_content_invalid',503);}
        }
        $version=(string)($site[$fields['version']]??'');
        if(!preg_match('/^[A-Za-z0-9._+-]{1,40}$/D',$version))throw new Problem('legal_policy_unavailable',503);
        $query=$db->prepare('SELECT policy_version,effective_date,source_locale,source_revision FROM portal_policy_documents
            WHERE policy_type=? AND policy_version=?');
        $query->execute([$type,$version]);
        $document=$query->fetch(PDO::FETCH_ASSOC);
        if($document!==false){
            $document['version']=(string)$document['policy_version'];
            return $document;
        }
        return ['version'=>$version,'effective_date'=>(string)($site[$fields['date']]??''),
            'source_locale'=>'zh-Hans','source_revision'=>0];
    }

    private function policyType(string $type): string
    {
        $type=strtoupper(trim($type));
        if(!isset(self::POLICY_FIELDS[$type]))throw new Problem('policy_type_invalid');
        return $type;
    }

    private function adminLocale(string $code): string
    {
        $locale=$this->locales->normalizeForAdmin($code);
        if($locale===null)throw new Problem('locale_not_available');
        return $locale;
    }
}
