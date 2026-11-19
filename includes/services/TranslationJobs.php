<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use Throwable;

final readonly class TranslationJobs
{
    public function __construct(
        private PDO $db,
        private LocaleRegistry $locales=new LocaleRegistry(),
        private ?TranslationProvider $provider=null,
    ) {}

    /** Queue all selected languages atomically; callers may join their source-save transaction. */
    public function enqueueMany(int $adminId,string $type,string $resourceId,array $targets,array $input,
        array $context,int $now,?string $policyVersion=null): array
    {
        if(!in_array($type,['article','product','announcement','policy'],true)){
            throw new Problem('translation_resource_invalid');
        }
        if(!array_is_list($targets)||$targets===[]||count($targets)>100){
            throw new Problem('translation_targets_invalid');
        }
        $normalized=[];
        foreach($targets as$target){
            if(!is_string($target)||($locale=$this->locales->normalizeForAdmin($target))===null){
                throw new Problem('locale_unsupported',400);
            }
            if(in_array($locale,$normalized,true))throw new Problem('translation_targets_invalid');
            $normalized[]=$locale;
        }
        if($type==='policy'&&($policyVersion===null||$policyVersion==='')){
            throw new Problem('policy_translation_invalid');
        }
        return Database::transactionOrJoin($this->db,function()use($adminId,$type,$resourceId,$normalized,$input,$context,$now,$policyVersion):array{
            $jobs=[];
            foreach($normalized as$locale){
                $jobs[$locale]=match($type){
                    'article'=>$this->enqueueArticle($adminId,$resourceId,$locale,$input,$context,$now),
                    'product'=>$this->enqueueProduct($adminId,$resourceId,$locale,$input,$context,$now),
                    'announcement'=>$this->enqueueAnnouncement($adminId,$resourceId,$locale,$input,$context,$now),
                    'policy'=>$this->enqueuePolicy($adminId,$resourceId,$policyVersion,$locale,$input,$context,$now),
                };
            }
            return ['jobs'=>$jobs,'target_locales'=>$normalized];
        });
    }

    public function enqueueArticle(int $adminId,string $articleId,string $targetLocale,array $input,array $context,int $now): array
    {
        $publish=$input['publish_when_ready']??false;
        if(!is_bool($publish))throw new Problem('translation_publish_invalid');
        Permissions::require($this->db,$adminId,'articles.manage');
        $settings=new TranslationSettings($this->db,SecretBox::fromEnvironment());
        $credentials=$settings->providerCredentials();
        $retry=$input['retry']??false;
        if(!is_bool($retry))throw new Problem('translation_retry_invalid');
        $target=$this->locales->normalizeForAdmin($targetLocale);
        if($target===null)throw new Problem('locale_unsupported',400);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$articleId,$target,$context,$now,$retry,$credentials,$publish):array{
            $q=$db->prepare('SELECT id,source_locale,source_revision,status FROM portal_articles WHERE id=? FOR UPDATE');
            $q->execute([$articleId]);$article=$q->fetch();
            if(!$article)throw new Problem('article_not_found',404);
            if($article['status']==='archived')throw new Problem('article_archived',409);
            $sourceLocale=(string)$article['source_locale'];
            if($sourceLocale===$target)throw new Problem('translation_target_is_source',409);
            $q=$db->prepare('SELECT draft_revision_id FROM portal_article_translations WHERE article_id=? AND locale_code=? FOR UPDATE');
            $q->execute([$articleId,$sourceLocale]);$sourceRevisionId=$q->fetchColumn();
            if($sourceRevisionId===false)throw new Problem('article_source_missing',409);
            $q=$db->prepare('SELECT title,summary,body,body_format,source_revision,source_hash
                FROM portal_article_translation_revisions WHERE id=? AND article_id=? AND locale_code=?');
            $q->execute([$sourceRevisionId,$articleId,$sourceLocale]);$source=$q->fetch();
            if(!$source)throw new Problem('article_source_missing',409);
            if((int)$source['source_revision']!==(int)$article['source_revision'])throw new Problem('article_source_stale',409);
            $snapshot=['title'=>(string)$source['title'],'summary'=>(string)$source['summary'],'body'=>(string)$source['body'],
                'body_format'=>(string)$source['body_format']];
            $sourceHash=ContentSourceHash::article($snapshot);
            $glossarySnapshot=(new TranslationGlossary($db))->snapshot($sourceLocale,$target);
            $snapshot['glossary']=$glossarySnapshot['terms'];
            $snapshot['publish_when_ready']=$publish;
            if(!hash_equals((string)$source['source_hash'],$sourceHash))throw new Problem('article_source_hash_invalid',409);
            $q=$db->prepare('SELECT lock_version FROM portal_article_translations WHERE article_id=? AND locale_code=? FOR UPDATE');
            $q->execute([$articleId,$target]);$lock=$q->fetchColumn();
            $targetVersion=$lock===false?0:(int)$lock;
            $providerMap=$credentials['target_map'];$optionsHash=hash('sha256',json_encode([
                'provider'=>'deepl','target'=>$providerMap[$target]??null,'body_format'=>$snapshot['body_format'],
                'glossary_revision'=>$glossarySnapshot['revision'],'publish_when_ready'=>$publish,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $dedup=hash('sha256',json_encode([
                'resource_type'=>'article','resource_id'=>$articleId,'target_locale'=>$target,'source_locale'=>$sourceLocale,
                'source_revision'=>(int)$source['source_revision'],'source_hash'=>$sourceHash,
                'provider'=>'deepl','provider_options_hash'=>$optionsHash,'glossary_revision'=>$glossarySnapshot['revision'],'publish_when_ready'=>$publish,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $q=$db->prepare('SELECT id,status FROM portal_translation_jobs WHERE dedup_key=?');
            $q->execute([$dedup]);$existing=$q->fetch();
            if($existing){
                if($retry&&$existing['status']==='failed'){
                    $db->prepare("UPDATE portal_translation_jobs SET status='retry',attempts=0,available_at=?,last_error_code=NULL,finished_at=NULL WHERE id=?")
                        ->execute([$now,$existing['id']]);
                    (new Backoffice($db))->audit($adminId,'RETRY_ARTICLE_TRANSLATION',$articleId,['target_locale'=>$target,'job_id'=>(int)$existing['id']],$context,$now);
                    return ['id'=>(int)$existing['id'],'status'=>'retry','deduplicated'=>true];
                }
                return ['id'=>(int)$existing['id'],'status'=>$existing['status'],'deduplicated'=>true];
            }
            $characters=mb_strlen($snapshot['title'].$snapshot['summary'].$snapshot['body'],'UTF-8');
            if($characters<1)throw new Problem('translation_source_empty',409);
            $db->prepare("INSERT INTO portal_translation_jobs(resource_type,resource_id,target_locale,source_locale,source_revision,
                source_hash,source_snapshot_json,expected_target_lock_version,provider,provider_options_hash,glossary_revision,
                dedup_key,status,attempts,character_count,available_at,created_by,created_at)
                VALUES('article',?,?,?,?,?,?,?,?,?,?,?,'pending',0,?,?,?,?)")
                ->execute([$articleId,$target,$sourceLocale,(int)$source['source_revision'],$sourceHash,
                    json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    $targetVersion,'deepl',$optionsHash,$glossarySnapshot['revision'],$dedup,$characters,$now,$adminId,$now]);
            $id=(int)$db->lastInsertId();
            (new Backoffice($db))->audit($adminId,'ENQUEUE_ARTICLE_TRANSLATION',$articleId,[
                'target_locale'=>$target,'source_revision'=>(int)$source['source_revision'],'job_id'=>$id,'characters'=>$characters,
            ],$context,$now);
            return ['id'=>$id,'status'=>'pending','deduplicated'=>false];
        });
    }



    public function enqueueProduct(int $adminId,string $productCode,string $targetLocale,array $input,array $context,int $now): array
    {
        $publish=$input['publish_when_ready']??false;
        if(!is_bool($publish))throw new Problem('translation_publish_invalid');
        Permissions::require($this->db,$adminId,'products.manage');
        $settings=new TranslationSettings($this->db,SecretBox::fromEnvironment());
        $credentials=$settings->providerCredentials();
        $retry=$input['retry']??false;
        if(!is_bool($retry))throw new Problem('translation_retry_invalid');
        $target=$this->locales->normalizeForAdmin($targetLocale);
        if($target===null)throw new Problem('locale_unsupported',400);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$productCode,$target,$context,$now,$retry,$credentials,$publish):array{
            $q=$db->prepare('SELECT code,source_locale,source_revision FROM portal_products WHERE code=? FOR UPDATE');
            $q->execute([$productCode]);$product=$q->fetch();
            if(!$product)throw new Problem('product_not_found',404);
            $sourceLocale=(string)$product['source_locale'];
            if($sourceLocale===$target)throw new Problem('translation_target_is_source',409);
            $publication=$publish&&(new ContentPublicationLanguages($db))->selected('product',$productCode)!==null;
            $sourceHead=$publication?'published_revision_id':'draft_revision_id';
            $q=$db->prepare("SELECT $sourceHead FROM portal_product_translations WHERE product_code=? AND locale_code=? FOR UPDATE");
            $q->execute([$productCode,$sourceLocale]);$sourceRevisionId=$q->fetchColumn();
            if($sourceRevisionId===false)throw new Problem('translation_source_invalid',409);
            $q=$db->prepare('SELECT title,description,source_revision,source_hash FROM portal_product_translation_revisions
                WHERE id=? AND product_code=? AND locale_code=?');
            $q->execute([$sourceRevisionId,$productCode,$sourceLocale]);$source=$q->fetch();
            if(!$source)throw new Problem('translation_source_invalid',409);
            if(!$publication&&(int)$source['source_revision']!==(int)$product['source_revision']){
                throw new Problem('translation_source_stale',409);
            }
            $snapshot=['title'=>(string)$source['title'],'description'=>(string)$source['description']];
            $sourceHash=ContentSourceHash::product($snapshot['title'],$snapshot['description']);
            $glossarySnapshot=(new TranslationGlossary($db))->snapshot($sourceLocale,$target);
            $snapshot['glossary']=$glossarySnapshot['terms'];
            $snapshot['publish_when_ready']=$publish;
            if(!hash_equals((string)$source['source_hash'],$sourceHash))throw new Problem('translation_source_hash_invalid',409);
            $q=$db->prepare('SELECT lock_version FROM portal_product_translations WHERE product_code=? AND locale_code=? FOR UPDATE');
            $q->execute([$productCode,$target]);$lock=$q->fetchColumn();$targetVersion=$lock===false?0:(int)$lock;
            $providerMap=$credentials['target_map'];$optionsHash=hash('sha256',json_encode([
                'provider'=>'deepl','target'=>$providerMap[$target]??null,'resource_type'=>'product',
                'glossary_revision'=>$glossarySnapshot['revision'],'publish_when_ready'=>$publish,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $dedup=hash('sha256',json_encode([
                'resource_type'=>'product','resource_id'=>$productCode,'target_locale'=>$target,'source_locale'=>$sourceLocale,
                'source_revision'=>(int)$source['source_revision'],'source_hash'=>$sourceHash,
                'provider'=>'deepl','provider_options_hash'=>$optionsHash,'glossary_revision'=>$glossarySnapshot['revision'],'publish_when_ready'=>$publish,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $q=$db->prepare('SELECT id,status FROM portal_translation_jobs WHERE dedup_key=?');$q->execute([$dedup]);$existing=$q->fetch();
            if($existing){
                if($retry&&$existing['status']==='failed'){
                    $db->prepare("UPDATE portal_translation_jobs SET status='retry',attempts=0,available_at=?,last_error_code=NULL,finished_at=NULL WHERE id=?")
                        ->execute([$now,$existing['id']]);
                    (new Backoffice($db))->audit($adminId,'RETRY_PRODUCT_TRANSLATION',$productCode,['target_locale'=>$target,'job_id'=>(int)$existing['id']],$context,$now);
                    return ['id'=>(int)$existing['id'],'status'=>'retry','deduplicated'=>true];
                }
                return ['id'=>(int)$existing['id'],'status'=>$existing['status'],'deduplicated'=>true];
            }
            $characters=mb_strlen($snapshot['title'].$snapshot['description'],'UTF-8');
            if($characters<1)throw new Problem('translation_source_empty',409);
            $db->prepare("INSERT INTO portal_translation_jobs(resource_type,resource_id,target_locale,source_locale,source_revision,
                source_hash,source_snapshot_json,expected_target_lock_version,provider,provider_options_hash,glossary_revision,
                dedup_key,status,attempts,character_count,available_at,created_by,created_at)
                VALUES('product',?,?,?,?,?,?,?,?,?,?,?,'pending',0,?,?,?,?)")
                ->execute([$productCode,$target,$sourceLocale,(int)$source['source_revision'],$sourceHash,
                    json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    $targetVersion,'deepl',$optionsHash,$glossarySnapshot['revision'],$dedup,$characters,$now,$adminId,$now]);
            $id=(int)$db->lastInsertId();
            (new Backoffice($db))->audit($adminId,'ENQUEUE_PRODUCT_TRANSLATION',$productCode,[
                'target_locale'=>$target,'source_revision'=>(int)$source['source_revision'],'job_id'=>$id,'characters'=>$characters,
            ],$context,$now);
            return ['id'=>$id,'status'=>'pending','deduplicated'=>false];
        });
    }

    public function enqueueAnnouncement(int $adminId,string $announcementId,string $targetLocale,array $input,array $context,int $now): array
    {
        $publish=$input['publish_when_ready']??false;
        if(!is_bool($publish))throw new Problem('translation_publish_invalid');
        Permissions::require($this->db,$adminId,'announcements.manage');
        $credentials=(new TranslationSettings($this->db,SecretBox::fromEnvironment()))->providerCredentials();
        $retry=$input['retry']??false;if(!is_bool($retry))throw new Problem('translation_retry_invalid');
        $target=$this->locales->normalizeForAdmin($targetLocale);if($target===null)throw new Problem('locale_unsupported',400);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$announcementId,$target,$retry,$credentials,$context,$now,$publish):array{
            $q=$db->prepare('SELECT source_locale,source_revision,deleted_at FROM portal_announcements WHERE id=? FOR UPDATE');
            $q->execute([$announcementId]);$entity=$q->fetch();
            if(!$entity||$entity['deleted_at']!==null)throw new Problem('announcement_not_found',404);
            $sourceLocale=(string)$entity['source_locale'];if($sourceLocale===$target)throw new Problem('translation_target_is_source',409);
            $publication=$publish&&(new ContentPublicationLanguages($db))->selected('announcement',$announcementId)!==null;
            $sourceHead=$publication?'published_revision_id':'draft_revision_id';
            $q=$db->prepare("SELECT $sourceHead FROM portal_announcement_translations WHERE announcement_id=? AND locale_code=? FOR UPDATE");
            $q->execute([$announcementId,$sourceLocale]);$sourceRevisionId=$q->fetchColumn();
            $source=false;
            if($sourceRevisionId!==false){
                $q=$db->prepare('SELECT title,body,source_revision,source_hash FROM portal_announcement_translation_revisions
                    WHERE id=? AND announcement_id=? AND locale_code=?');
                $q->execute([$sourceRevisionId,$announcementId,$sourceLocale]);$source=$q->fetch();
            }
            if(!$source||(!$publication&&(int)$source['source_revision']!==(int)$entity['source_revision'])){
                throw new Problem('translation_source_stale',409);
            }
            $hash=ContentSourceHash::announcement((string)$source['title'],(string)$source['body']);
            if(!hash_equals((string)$source['source_hash'],$hash))throw new Problem('translation_source_hash_invalid',409);
            $q=$db->prepare('SELECT lock_version FROM portal_announcement_translations WHERE announcement_id=? AND locale_code=? FOR UPDATE');
            $q->execute([$announcementId,$target]);$lock=$q->fetchColumn();$targetVersion=$lock===false?0:(int)$lock;
            $glossary=(new TranslationGlossary($db))->snapshot($sourceLocale,$target);
            $snapshot=['title'=>(string)$source['title'],'summary'=>'','body'=>(string)$source['body'],'body_format'=>'plain','glossary'=>$glossary['terms']];
            return $this->enqueueSnapshot($db,$adminId,'announcement',$announcementId,$target,$sourceLocale,(int)$source['source_revision'],
                $hash,$targetVersion,$snapshot+['publish_when_ready'=>$publish],(int)$glossary['revision'],$credentials,$retry,$context,$now);
        });
    }

    public function enqueuePolicy(int $adminId,string $policyType,string $policyVersion,string $targetLocale,array $input,array $context,int $now): array
    {
        $publish=$input['publish_when_ready']??false;
        if(!is_bool($publish))throw new Problem('translation_publish_invalid');
        Permissions::require($this->db,$adminId,'settings.manage');
        $type=strtoupper($policyType);
        if(!in_array($type,['PRIVACY','TERMS','LEGAL'],true)||!preg_match('/^[A-Za-z0-9._-]{1,40}$/D',$policyVersion))throw new Problem('policy_translation_invalid');
        $credentials=(new TranslationSettings($this->db,SecretBox::fromEnvironment()))->providerCredentials();
        $retry=$input['retry']??false;if(!is_bool($retry))throw new Problem('translation_retry_invalid');
        $target=$this->locales->normalizeForAdmin($targetLocale);if($target===null)throw new Problem('locale_unsupported',400);
        $resourceId=substr(hash('sha256',$type."\n".$policyVersion),0,32);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$type,$policyVersion,$resourceId,$target,$retry,$credentials,$context,$now,$publish):array{
            $q=$db->prepare('SELECT source_locale,source_revision FROM portal_policy_documents WHERE policy_type=? AND policy_version=? FOR UPDATE');
            $q->execute([$type,$policyVersion]);$document=$q->fetch();if(!$document)throw new Problem('policy_document_not_found',404);
            $sourceLocale=(string)$document['source_locale'];if($sourceLocale===$target)throw new Problem('translation_target_is_source',409);
            $q=$db->prepare('SELECT draft_revision_id FROM portal_policy_translation_heads WHERE policy_type=? AND policy_version=? AND locale_code=? FOR UPDATE');
            $q->execute([$type,$policyVersion,$sourceLocale]);$sourceRevisionId=$q->fetchColumn();
            $source=false;
            if($sourceRevisionId!==false){
                $q=$db->prepare('SELECT title,body,source_revision,source_hash FROM portal_policy_translation_revisions
                    WHERE id=? AND policy_type=? AND policy_version=? AND locale_code=?');
                $q->execute([$sourceRevisionId,$type,$policyVersion,$sourceLocale]);$source=$q->fetch();
            }
            if(!$source||(int)$source['source_revision']!==(int)$document['source_revision'])throw new Problem('translation_source_stale',409);
            $hash=hash('sha256',json_encode([(string)$source['title'],(string)$source['body']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if(!hash_equals((string)$source['source_hash'],$hash))throw new Problem('translation_source_hash_invalid',409);
            $q=$db->prepare('SELECT lock_version FROM portal_policy_translation_heads WHERE policy_type=? AND policy_version=? AND locale_code=? FOR UPDATE');
            $q->execute([$type,$policyVersion,$target]);$lock=$q->fetchColumn();$targetVersion=$lock===false?0:(int)$lock;
            $glossary=(new TranslationGlossary($db))->snapshot($sourceLocale,$target);
            $snapshot=['title'=>(string)$source['title'],'summary'=>'','body'=>(string)$source['body'],'body_format'=>'plain',
                'policy_type'=>$type,'policy_version'=>$policyVersion,'glossary'=>$glossary['terms']];
            return $this->enqueueSnapshot($db,$adminId,'policy',$resourceId,$target,$sourceLocale,(int)$source['source_revision'],
                $hash,$targetVersion,$snapshot+['publish_when_ready'=>$publish],(int)$glossary['revision'],$credentials,$retry,$context,$now);
        });
    }

    private function enqueueSnapshot(PDO $db,int $adminId,string $type,string $resourceId,string $target,string $sourceLocale,int $sourceRevision,
        string $sourceHash,int $targetVersion,array $snapshot,int $glossaryRevision,array $credentials,bool $retry,array $context,int $now): array
    {
        $optionsHash=hash('sha256',json_encode(['provider'=>'deepl','target'=>$credentials['target_map'][$target]??null,
            'resource_type'=>$type,'glossary_revision'=>$glossaryRevision,'publish_when_ready'=>$snapshot['publish_when_ready']??false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $dedup=hash('sha256',json_encode(['resource_type'=>$type,'resource_id'=>$resourceId,'target_locale'=>$target,
            'source_locale'=>$sourceLocale,'source_revision'=>$sourceRevision,'source_hash'=>$sourceHash,'provider'=>'deepl',
            'provider_options_hash'=>$optionsHash,'glossary_revision'=>$glossaryRevision],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $q=$db->prepare('SELECT id,status FROM portal_translation_jobs WHERE dedup_key=?');$q->execute([$dedup]);$existing=$q->fetch();
        if($existing){
            if($retry&&$existing['status']==='failed'){
                $db->prepare("UPDATE portal_translation_jobs SET status='retry',attempts=0,available_at=?,last_error_code=NULL,finished_at=NULL WHERE id=?")
                    ->execute([$now,$existing['id']]);
                (new Backoffice($db))->audit($adminId,'RETRY_'.strtoupper($type).'_TRANSLATION',$resourceId,
                    ['target_locale'=>$target,'job_id'=>(int)$existing['id']],$context,$now);
                return ['id'=>(int)$existing['id'],'status'=>'retry','deduplicated'=>true];
            }
            return ['id'=>(int)$existing['id'],'status'=>$existing['status'],'deduplicated'=>true];
        }
        $characters=mb_strlen($snapshot['title'].$snapshot['body'],'UTF-8');
        if($characters<1)throw new Problem('translation_source_empty',409);
        $db->prepare("INSERT INTO portal_translation_jobs(resource_type,resource_id,target_locale,source_locale,source_revision,
            source_hash,source_snapshot_json,expected_target_lock_version,provider,provider_options_hash,glossary_revision,
            dedup_key,status,attempts,character_count,available_at,created_by,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'pending',0,?,?,?,?)")
            ->execute([$type,$resourceId,$target,$sourceLocale,$sourceRevision,$sourceHash,
                json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$targetVersion,'deepl',
                $optionsHash,$glossaryRevision,$dedup,$characters,$now,$adminId,$now]);
        $id=(int)$db->lastInsertId();
        (new Backoffice($db))->audit($adminId,'ENQUEUE_'.strtoupper($type).'_TRANSLATION',$resourceId,
            ['target_locale'=>$target,'source_revision'=>$sourceRevision,'job_id'=>$id,'characters'=>$characters],$context,$now);
        return ['id'=>$id,'status'=>'pending','deduplicated'=>false];
    }

    public function status(int $adminId,int $jobId): array
    {
        $q=$this->db->prepare('SELECT id,resource_type,resource_id,target_locale,source_locale,source_revision,status,
            attempts,character_count,last_error_code,created_at,finished_at FROM portal_translation_jobs WHERE id=?');
        $q->execute([$jobId]);$row=$q->fetch();
        if(!$row)throw new Problem('translation_job_not_found',404);
        $permission=match($row['resource_type']){
            'product'=>'products.manage','announcement'=>'announcements.manage','policy'=>'settings.manage',default=>'articles.manage',
        };
        Permissions::require($this->db,$adminId,$permission);
        $row['id']=(int)$row['id'];$row['source_revision']=(int)$row['source_revision'];
        $row['attempts']=(int)$row['attempts'];$row['character_count']=(int)$row['character_count'];
        $row['created_at']=(int)$row['created_at'];$row['finished_at']=$row['finished_at']===null?null:(int)$row['finished_at'];
        return $row;
    }

    public function runBatch(int $now,int $limit=3): array
    {
        $settings=new TranslationSettings($this->db,SecretBox::fromEnvironment());
        $credentials=$settings->workerCredentials();
        if($credentials===null)return ['processed'=>0,'status'=>'disabled','items'=>[]];
        $provider=$this->provider??new DeepLTranslationProvider($credentials['api_key']);
        $results=[];
        for($i=0;$i<max(1,min(10,$limit));$i++){
            $job=$this->claim($now,(int)$credentials['monthly_character_budget'],(int)$credentials['concurrency']);
            if($job===null)break;
            if(($job['status']??'')==='failed'){$results[]=['id'=>(int)$job['id'],'status'=>'failed','error'=>'translation_monthly_budget_exceeded'];continue;}
            try{
                $source=json_decode($job['source_snapshot_json'],true,16,JSON_THROW_ON_ERROR);
                $translated=$job['resource_type']==='product'
                    ?$provider->translateProduct($source,$job['source_locale'],$job['target_locale'],$credentials['target_map'],$source['glossary']??[])
                    :$provider->translateArticle($source,$job['source_locale'],$job['target_locale'],$credentials['target_map'],$source['glossary']??[]);
                $results[]=$this->complete($job,$translated,$now);
            }catch(Throwable $error){
                $results[]=$this->fail($job,$error,$now,(int)$credentials['retry_limit']);
            }
        }
        return ['processed'=>count($results),'items'=>$results];
    }

    private function claim(int $now,int $monthlyBudget,int $concurrency): ?array
    {
        $lockName='d2s_translation_jobs_claim';
        $lock=$this->db->prepare('SELECT GET_LOCK(?,5)');
        $lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1)return null;
        try{
            return Database::transaction($this->db,function(PDO $db)use($now,$monthlyBudget,$concurrency):?array{
                $active=$db->prepare("SELECT COUNT(*) FROM portal_translation_jobs WHERE status='running' AND lease_until>?");
                $active->execute([$now]);
                if((int)$active->fetchColumn()>=$concurrency)return null;
                $q=$db->prepare("SELECT * FROM portal_translation_jobs WHERE
                    ((status IN ('pending','retry') AND available_at<=?) OR (status='running' AND (lease_until IS NULL OR lease_until<=?)))
                    ORDER BY available_at,id LIMIT 1 FOR UPDATE");
                $q->execute([$now,$now]);$job=$q->fetch();
                if(!$job)return null;
                $month=gmdate('Y-m',$now);
                $db->prepare('INSERT IGNORE INTO portal_translation_usage(billing_month,submitted_characters,updated_at) VALUES(?,0,?)')->execute([$month,$now]);
                $usage=$db->prepare('SELECT submitted_characters FROM portal_translation_usage WHERE billing_month=? FOR UPDATE');
                $usage->execute([$month]);$used=(int)$usage->fetchColumn();
                if($monthlyBudget>0&&$used+(int)$job['character_count']>$monthlyBudget){
                    $db->prepare("UPDATE portal_translation_jobs SET status='failed',last_error_code='translation_monthly_budget_exceeded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                        ->execute([$now,$job['id']]);
                    return ['id'=>$job['id'],'status'=>'failed'];
                }
                $db->prepare('UPDATE portal_translation_usage SET submitted_characters=submitted_characters+?,updated_at=? WHERE billing_month=?')
                    ->execute([(int)$job['character_count'],$now,$month]);
                $lease=bin2hex(random_bytes(16));$attempts=(int)$job['attempts']+1;
                $db->prepare("UPDATE portal_translation_jobs SET status='running',attempts=?,lease_id=?,lease_until=?,last_error_code=NULL
                    WHERE id=?")->execute([$attempts,$lease,$now+90,$job['id']]);
                $job['attempts']=$attempts;$job['lease_id']=$lease;
                return $job;
            });
        }finally{
            $release=$this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function complete(array $job,array $translated,int $now): array
    {
        if($job['resource_type']==='product')return $this->completeProduct($job,$translated,$now);
        if($job['resource_type']==='announcement')return $this->completeAnnouncement($job,$translated,$now);
        if($job['resource_type']==='policy')return $this->completePolicy($job,$translated,$now);
        return Database::transaction($this->db,function(PDO $db)use($job,$translated,$now):array{
            $q=$db->prepare('SELECT * FROM portal_translation_jobs WHERE id=? FOR UPDATE');$q->execute([$job['id']]);$current=$q->fetch();
            if(!$current||$current['status']!=='running'||!hash_equals((string)$current['lease_id'],(string)$job['lease_id']))
                return ['id'=>(int)$job['id'],'status'=>'superseded'];
            $snapshot=json_decode($current['source_snapshot_json'],true,16,JSON_THROW_ON_ERROR);
            $q=$db->prepare('SELECT source_locale,source_revision,status FROM portal_articles WHERE id=? FOR UPDATE');$q->execute([$current['resource_id']]);$article=$q->fetch();
            $q=$db->prepare('SELECT lock_version FROM portal_article_translations WHERE article_id=? AND locale_code=? FOR UPDATE');
            $q->execute([$current['resource_id'],$current['target_locale']]);$version=$q->fetchColumn();
            $targetVersion=$version===false?0:(int)$version;
            $sourceQuery=$db->prepare('SELECT r.title,r.summary,r.body,r.source_revision,r.source_hash FROM portal_article_translations t
                JOIN portal_article_translation_revisions r ON r.id=t.draft_revision_id
                WHERE t.article_id=? AND t.locale_code=?');
            $sourceQuery->execute([$current['resource_id'],$current['source_locale']]);$latest=$sourceQuery->fetch();
            $latestHash=$latest?ContentSourceHash::article($latest):'';
            if(!$article||$article['status']==='archived'||(int)$article['source_revision']!==(int)$current['source_revision']||
                !$latest||!hash_equals((string)$current['source_hash'],$latestHash)||$targetVersion!==(int)$current['expected_target_lock_version']){
                $db->prepare("UPDATE portal_translation_jobs SET status='superseded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                    ->execute([$now,$current['id']]);
                return ['id'=>(int)$current['id'],'status'=>'superseded'];
            }
            $format=($snapshot['body_format']??'html')==='plain'?'plain':'html';
            $body=$format==='html'?ArticleContent::clean($translated['body']??'',true):(string)($translated['body']??'');
            if($format==='plain'&&(trim($body)===''||mb_strlen($body,'UTF-8')>ArticleContent::MAX_CHARACTERS||
                !mb_check_encoding($body,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$body)))
                throw new Problem('translation_result_invalid');
            $title=trim((string)($translated['title']??''));$summary=trim((string)($translated['summary']??''));
            if($title===''||mb_strlen($title,'UTF-8')>240||mb_strlen($summary,'UTF-8')>500)throw new Problem('translation_result_invalid');
            $q=$db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_article_translation_revisions WHERE article_id=? AND locale_code=?');
            $q->execute([$current['resource_id'],$current['target_locale']]);$revision=(int)$q->fetchColumn();
            $db->prepare("INSERT INTO portal_article_translation_revisions(article_id,locale_code,revision,title,summary,body,body_format,
                seo_title,seo_description,source_revision,source_hash,origin,provider,glossary_revision,author_admin_id,created_at)
                VALUES(?,?,?, ?,?,?,?,'','',?,?,'machine','deepl',0,NULL,?)")
                ->execute([$current['resource_id'],$current['target_locale'],$revision,$title,$summary,$body,$format,
                    (int)$current['source_revision'],$current['source_hash'],$now]);
            $revisionId=(int)$db->lastInsertId();$nextVersion=$targetVersion+1;
            $db->prepare('INSERT INTO portal_article_translations(article_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                VALUES(?,?,?,NULL,?,?) ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)')
                ->execute([$current['resource_id'],$current['target_locale'],$revisionId,$nextVersion,$now]);
            $this->publishCompleted($db,$current,$revisionId,$now);
            $db->prepare("UPDATE portal_translation_jobs SET status='succeeded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                ->execute([$now,$current['id']]);
            return ['id'=>(int)$current['id'],'status'=>'succeeded','revision'=>$revision,'lock_version'=>$nextVersion];
        });
    }

    private function completeProduct(array $job,array $translated,int $now): array
    {
        return Database::transaction($this->db,function(PDO $db)use($job,$translated,$now):array{
            $q=$db->prepare('SELECT * FROM portal_translation_jobs WHERE id=? FOR UPDATE');
            $q->execute([$job['id']]);$current=$q->fetch();
            if(!$current||$current['status']!=='running'||!hash_equals((string)$current['lease_id'],(string)$job['lease_id']))
                return ['id'=>(int)$job['id'],'status'=>'superseded'];
            $productQuery=$db->prepare('SELECT source_locale,source_revision FROM portal_products WHERE code=? FOR UPDATE');
            $productQuery->execute([$current['resource_id']]);$product=$productQuery->fetch();
            $targetQuery=$db->prepare('SELECT lock_version FROM portal_product_translations WHERE product_code=? AND locale_code=? FOR UPDATE');
            $targetQuery->execute([$current['resource_id'],$current['target_locale']]);
            $targetLock=$targetQuery->fetchColumn();
            $targetVersion=$targetLock===false?0:(int)$targetLock;
            $publication=$this->isCatalogPublication($db,$current);
            $sourceHead=$publication?'published_revision_id':'draft_revision_id';
            $sourceQuery=$db->prepare("SELECT r.title,r.description,r.source_revision,r.source_hash
                FROM portal_product_translations t JOIN portal_product_translation_revisions r
                    ON r.id=t.$sourceHead AND r.product_code=t.product_code AND r.locale_code=t.locale_code
                WHERE t.product_code=? AND t.locale_code=?");
            $sourceQuery->execute([$current['resource_id'],$current['source_locale']]);$latest=$sourceQuery->fetch();
            $latestHash=$latest?ContentSourceHash::product((string)$latest['title'],(string)$latest['description']):'';
            if(!$product||(!$publication&&(int)$product['source_revision']!==(int)$current['source_revision'])||
                (string)$product['source_locale']!==(string)$current['source_locale']||!$latest||
                (int)$latest['source_revision']!==(int)$current['source_revision']||
                !hash_equals((string)$current['source_hash'],$latestHash)||
                $targetVersion!==(int)$current['expected_target_lock_version']){
                $db->prepare("UPDATE portal_translation_jobs SET status='superseded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                    ->execute([$now,$current['id']]);
                return ['id'=>(int)$current['id'],'status'=>'superseded'];
            }
            $title=trim((string)($translated['title']??''));$description=trim((string)($translated['description']??''));
            if($title===''||mb_strlen($title,'UTF-8')>160||mb_strlen($description,'UTF-8')>5000||
                !mb_check_encoding($title,'UTF-8')||!mb_check_encoding($description,'UTF-8')||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$title.$description)){
                throw new Problem('translation_result_invalid');
            }
            $revisionQuery=$db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_product_translation_revisions WHERE product_code=? AND locale_code=?');
            $revisionQuery->execute([$current['resource_id'],$current['target_locale']]);$revision=(int)$revisionQuery->fetchColumn();
            $db->prepare("INSERT INTO portal_product_translation_revisions
                (product_code,locale_code,revision,title,description,source_revision,source_hash,origin,provider,glossary_revision,author_admin_id,created_at)
                VALUES(?,?, ?,?,?,?,?,'machine','deepl',?,NULL,?)")
                ->execute([$current['resource_id'],$current['target_locale'],$revision,$title,$description,
                    (int)$current['source_revision'],$current['source_hash'],(int)$current['glossary_revision'],$now]);
            $revisionId=(int)$db->lastInsertId();$nextVersion=$targetVersion+1;
            $db->prepare('INSERT INTO portal_product_translations(product_code,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                VALUES(?,?,?,NULL,?,?) ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)')
                ->execute([$current['resource_id'],$current['target_locale'],$revisionId,$nextVersion,$now]);
            $this->publishCompleted($db,$current,$revisionId,$now);
            $db->prepare("UPDATE portal_translation_jobs SET status='succeeded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                ->execute([$now,$current['id']]);
            return ['id'=>(int)$current['id'],'status'=>'succeeded','revision'=>$revision,'lock_version'=>$nextVersion];
        });
    }

    private function completeAnnouncement(array $job,array $translated,int $now): array
    {
        return Database::transaction($this->db,function(PDO $db)use($job,$translated,$now):array{
            $q=$db->prepare('SELECT * FROM portal_translation_jobs WHERE id=? FOR UPDATE');$q->execute([$job['id']]);$current=$q->fetch();
            if(!$current||$current['status']!=='running'||!hash_equals((string)$current['lease_id'],(string)$job['lease_id']))return ['id'=>(int)$job['id'],'status'=>'superseded'];
            $entityQuery=$db->prepare('SELECT source_locale,source_revision,deleted_at FROM portal_announcements WHERE id=? FOR UPDATE');
            $entityQuery->execute([$current['resource_id']]);$entity=$entityQuery->fetch();
            $targetQuery=$db->prepare('SELECT lock_version FROM portal_announcement_translations WHERE announcement_id=? AND locale_code=? FOR UPDATE');
            $targetQuery->execute([$current['resource_id'],$current['target_locale']]);$lock=$targetQuery->fetchColumn();$targetVersion=$lock===false?0:(int)$lock;
            $publication=$this->isCatalogPublication($db,$current);
            $sourceHead=$publication?'published_revision_id':'draft_revision_id';
            $sourceQuery=$db->prepare("SELECT r.title,r.body,r.source_revision,r.source_hash FROM portal_announcement_translations t
                JOIN portal_announcement_translation_revisions r ON r.id=t.$sourceHead
                WHERE t.announcement_id=? AND t.locale_code=?");
            $sourceQuery->execute([$current['resource_id'],$current['source_locale']]);$source=$sourceQuery->fetch();
            $hash=$source?ContentSourceHash::announcement((string)$source['title'],(string)$source['body']):'';
            if(!$entity||$entity['deleted_at']!==null||(string)$entity['source_locale']!==(string)$current['source_locale']||
                (!$publication&&(int)$entity['source_revision']!==(int)$current['source_revision'])||!$source||
                (int)$source['source_revision']!==(int)$current['source_revision']||!hash_equals((string)$current['source_hash'],$hash)||
                $targetVersion!==(int)$current['expected_target_lock_version']){
                $db->prepare("UPDATE portal_translation_jobs SET status='superseded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                    ->execute([$now,$current['id']]);return ['id'=>(int)$current['id'],'status'=>'superseded'];
            }
            $title=trim((string)($translated['title']??''));$body=trim((string)($translated['body']??''));
            if($title===''||mb_strlen($title,'UTF-8')>120||$body===''||mb_strlen($body,'UTF-8')>3000||
                !mb_check_encoding($title.$body,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$title.$body))throw new Problem('translation_result_invalid');
            $q=$db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_announcement_translation_revisions WHERE announcement_id=? AND locale_code=?');
            $q->execute([$current['resource_id'],$current['target_locale']]);$revision=(int)$q->fetchColumn();
            $db->prepare("INSERT INTO portal_announcement_translation_revisions(announcement_id,locale_code,revision,title,body,source_revision,source_hash,origin,author_admin_id,created_at)
                VALUES(?,?,?,?,?,?,?,'machine',NULL,?)")->execute([$current['resource_id'],$current['target_locale'],$revision,$title,$body,
                    (int)$current['source_revision'],$current['source_hash'],$now]);
            $revisionId=(int)$db->lastInsertId();$next=$targetVersion+1;
            $db->prepare('INSERT INTO portal_announcement_translations(announcement_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                VALUES(?,?,?,NULL,?,?) ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)')
                ->execute([$current['resource_id'],$current['target_locale'],$revisionId,$next,$now]);
            $this->publishCompleted($db,$current,$revisionId,$now);
            $db->prepare("UPDATE portal_translation_jobs SET status='succeeded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                ->execute([$now,$current['id']]);return ['id'=>(int)$current['id'],'status'=>'succeeded','revision'=>$revision,'lock_version'=>$next];
        });
    }

    private function completePolicy(array $job,array $translated,int $now): array
    {
        $snapshot=json_decode((string)$job['source_snapshot_json'],true,16,JSON_THROW_ON_ERROR);
        $type=(string)($snapshot['policy_type']??'');$version=(string)($snapshot['policy_version']??'');
        if(!in_array($type,['PRIVACY','TERMS','LEGAL'],true)||!preg_match('/^[A-Za-z0-9._-]{1,40}$/D',$version))throw new Problem('translation_source_invalid');
        return Database::transaction($this->db,function(PDO $db)use($job,$translated,$now,$snapshot,$type,$version):array{
            $q=$db->prepare('SELECT * FROM portal_translation_jobs WHERE id=? FOR UPDATE');$q->execute([$job['id']]);$current=$q->fetch();
            if(!$current||$current['status']!=='running'||!hash_equals((string)$current['lease_id'],(string)$job['lease_id']))return ['id'=>(int)$job['id'],'status'=>'superseded'];
            $docQuery=$db->prepare('SELECT source_locale,source_revision FROM portal_policy_documents WHERE policy_type=? AND policy_version=? FOR UPDATE');
            $docQuery->execute([$type,$version]);$document=$docQuery->fetch();
            $targetQuery=$db->prepare('SELECT lock_version FROM portal_policy_translation_heads WHERE policy_type=? AND policy_version=? AND locale_code=? FOR UPDATE');
            $targetQuery->execute([$type,$version,$current['target_locale']]);$lock=$targetQuery->fetchColumn();$targetVersion=$lock===false?0:(int)$lock;
            $sourceQuery=$db->prepare('SELECT r.title,r.body,r.source_revision,r.source_hash FROM portal_policy_translation_heads h
                JOIN portal_policy_translation_revisions r ON r.id=h.draft_revision_id
                WHERE h.policy_type=? AND h.policy_version=? AND h.locale_code=?');
            $sourceQuery->execute([$type,$version,$current['source_locale']]);$source=$sourceQuery->fetch();
            $hash=$source?hash('sha256',json_encode([(string)$source['title'],(string)$source['body']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)):'';
            if(!$document||(string)$document['source_locale']!==(string)$current['source_locale']||
                (int)$document['source_revision']!==(int)$current['source_revision']||!$source||
                (int)$source['source_revision']!==(int)$current['source_revision']||!hash_equals((string)$current['source_hash'],$hash)||
                $targetVersion!==(int)$current['expected_target_lock_version']){
                $db->prepare("UPDATE portal_translation_jobs SET status='superseded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                    ->execute([$now,$current['id']]);return ['id'=>(int)$current['id'],'status'=>'superseded'];
            }
            $title=trim((string)($translated['title']??''));$body=trim((string)($translated['body']??''));
            if($title===''||mb_strlen($title,'UTF-8')>180||$body===''||mb_strlen($body,'UTF-8')>50000||strlen($body)>150000||
                !mb_check_encoding($title.$body,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$title.$body))throw new Problem('translation_result_invalid');
            $q=$db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_policy_translation_revisions WHERE policy_type=? AND policy_version=? AND locale_code=?');
            $q->execute([$type,$version,$current['target_locale']]);$revision=(int)$q->fetchColumn();
            $db->prepare("INSERT INTO portal_policy_translation_revisions(policy_type,policy_version,locale_code,revision,title,body,source_revision,source_hash,origin,author_admin_id,created_at)
                VALUES(?,?,?,?,?,?,?,?,'machine',NULL,?)")->execute([$type,$version,$current['target_locale'],$revision,$title,$body,
                    (int)$current['source_revision'],$current['source_hash'],$now]);
            $revisionId=(int)$db->lastInsertId();$next=$targetVersion+1;
            $db->prepare('INSERT INTO portal_policy_translation_heads(policy_type,policy_version,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                VALUES(?,?, ?,?,NULL,?,?) ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)')
                ->execute([$type,$version,$current['target_locale'],$revisionId,$next,$now]);
            $this->publishCompleted($db,$current,$revisionId,$now);
            $db->prepare("UPDATE portal_translation_jobs SET status='succeeded',lease_id=NULL,lease_until=NULL,finished_at=? WHERE id=?")
                ->execute([$now,$current['id']]);return ['id'=>(int)$current['id'],'status'=>'succeeded','revision'=>$revision,'lock_version'=>$next];
        });
    }

    /** Publish only the validated revision created under the worker's entity and target locks. */
    private function isCatalogPublication(PDO $db,array $job): bool
    {
        $snapshot=json_decode((string)$job['source_snapshot_json'],true,16,JSON_THROW_ON_ERROR);
        return ($snapshot['publish_when_ready']??false)===true
            && (new ContentPublicationLanguages($db))->selected($job['resource_type'],$job['resource_id'])!==null;
    }

    private function publishCompleted(PDO $db,array $job,int $revisionId,int $now): void
    {
        $snapshot=json_decode((string)$job['source_snapshot_json'],true,16,JSON_THROW_ON_ERROR);
        if(($snapshot['publish_when_ready']??false)!==true)return;
        $selected=(new ContentPublicationLanguages($db))->selected($job['resource_type'],$job['resource_id']);
        if($selected!==null&&!in_array($job['target_locale'],$selected,true))return;
        $locale=$job['target_locale'];$id=$job['resource_id'];
        [$sql,$params,$paths]=match($job['resource_type']){
            'article'=>['UPDATE portal_article_translations SET published_revision_id=? WHERE article_id=? AND locale_code=? AND draft_revision_id=?',
                [$revisionId,$id,$locale,$revisionId],['/help','/sitemap.xml']],
            'product'=>['UPDATE portal_product_translations SET published_revision_id=? WHERE product_code=? AND locale_code=? AND draft_revision_id=?',
                [$revisionId,$id,$locale,$revisionId],['/products','/shop','/','/sitemap.xml']],
            'announcement'=>['UPDATE portal_announcement_translations SET published_revision_id=? WHERE announcement_id=? AND locale_code=? AND draft_revision_id=?',
                [$revisionId,$id,$locale,$revisionId],['/']],
            'policy'=>['UPDATE portal_policy_translation_heads SET published_revision_id=? WHERE policy_type=? AND policy_version=? AND locale_code=? AND draft_revision_id=?',
                [$revisionId,$snapshot['policy_type'],$snapshot['policy_version'],$locale,$revisionId],
                [match($snapshot['policy_type']){'PRIVACY'=>'/privacy-policy','TERMS'=>'/terms','LEGAL'=>'/legal-statement'},'/sitemap.xml']],
            default=>throw new Problem('translation_resource_invalid'),
        };
        $query=$db->prepare($sql);$query->execute($params);
        if($query->rowCount()!==1)throw new Problem('record_changed',409);
        EdgeOneCachePurge::enqueuePaths($db,$paths,'translation-publish:'.$job['id'],$now);
        (new Backoffice($db))->audit((int)$job['created_by'],'PUBLISH_TRANSLATION',$id,
            ['job_id'=>(int)$job['id'],'locale'=>$locale,'revision_id'=>$revisionId],
            ['request_path'=>'/tasks/translate-content'],$now);
    }

    private function fail(array $job,Throwable $error,int $now,int $retryLimit): array
    {
        $temporary=$error instanceof Problem&&in_array($error->httpStatus,[429,502,503,504],true);
        $attempt=(int)$job['attempts'];$retry=$temporary&&$attempt<=$retryLimit;
        $status=$retry?'retry':'failed';$delay=min(21600,60*(2**min(8,max(0,$attempt-1))));
        $code=$error instanceof Problem?$error->getMessage():'translation_worker_failed';
        if(!preg_match('/^[a-z0-9_]{1,64}$/D',$code))$code='translation_worker_failed';
        $this->db->prepare('UPDATE portal_translation_jobs SET status=?,available_at=?,lease_id=NULL,lease_until=NULL,last_error_code=?,finished_at=?
            WHERE id=? AND status=\'running\' AND lease_id=?')
            ->execute([$status,$retry?$now+$delay:$now,$code,$retry?null:$now,$job['id'],$job['lease_id']]);
        return ['id'=>(int)$job['id'],'status'=>$status,'error'=>$code];
    }
}
