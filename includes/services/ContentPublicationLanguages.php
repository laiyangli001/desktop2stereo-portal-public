<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class ContentPublicationLanguages
{
    public function __construct(private PDO $db,private LocaleRegistry $registry=new LocaleRegistry()) {}

    public function targets(string $source,array $selected): array
    {
        $source=$this->registry->normalizeForAdmin($source);
        if($source===null||!array_is_list($selected)||count($selected)>100)throw new Problem('translation_targets_invalid');
        $result=[$source,'en'];
        foreach($selected as$code){
            if(!is_string($code)||($locale=$this->registry->normalizeForAdmin($code))===null)throw new Problem('locale_unsupported',400);
            $result[]=$locale;
        }
        if($this->registry->normalizeForAdmin('en')===null)throw new Problem('locale_unsupported',400);
        return array_values(array_unique($result));
    }

    public function save(string $type,string $id,string $source,array $selected,int $now): array
    {
        if(!$this->db->inTransaction())throw new \RuntimeException('publication_transaction_required');
        $locales=$this->targets($source,$selected);
        $this->db->prepare('INSERT INTO portal_content_publication_languages(resource_type,resource_id,source_locale,locales_json,updated_at)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE source_locale=VALUES(source_locale),locales_json=VALUES(locales_json),updated_at=VALUES(updated_at)')
            ->execute([$type,$id,$locales[0],json_encode($locales,JSON_THROW_ON_ERROR),$now]);
        return $locales;
    }

    public function selected(string $type,string $id): ?array
    {
        $migration=$this->db->prepare('SELECT status FROM portal_migrations WHERE version=?');
        $migration->execute(['0059_content_publication_languages.php']);
        if($migration->fetchColumn()!=='applied')return null;
        $q=$this->db->prepare('SELECT locales_json FROM portal_content_publication_languages WHERE resource_type=? AND resource_id=?');
        $q->execute([$type,$id]);$json=$q->fetchColumn();
        return $json===false?null:json_decode($json,true,16,JSON_THROW_ON_ERROR);
    }


    /** Cancel leases too, so an in-flight provider response cannot restore deleted content. */
    public function cancel(string $type,string $id,int $now): void
    {
        if(!$this->db->inTransaction())throw new \RuntimeException('publication_transaction_required');
        $this->db->prepare("UPDATE portal_translation_jobs SET status='cancelled',lease_id=NULL,lease_until=NULL,
            finished_at=? WHERE resource_type=? AND resource_id=? AND status IN ('pending','running','retry')")
            ->execute([$now,$type,$id]);
    }

    public function progress(int $adminId,string $type,string $id): array
    {
        $permission=match($type){
            'article'=>'articles.manage','product'=>'products.manage',
            'announcement'=>'announcements.manage','policy'=>'settings.manage',
            default=>throw new Problem('translation_resource_invalid'),
        };
        Permissions::require($this->db,$adminId,$permission);
        [$entitySql,$headSql]=match($type){
            'article'=>['SELECT source_locale,source_revision FROM portal_articles WHERE id=?',
                'SELECT h.locale_code,r.source_revision,r.origin FROM portal_article_translations h
                 JOIN portal_article_translation_revisions r ON r.id=h.published_revision_id WHERE h.article_id=?'],
            'product'=>['SELECT source_locale,source_revision FROM portal_products WHERE code=?',
                'SELECT h.locale_code,r.source_revision,r.origin FROM portal_product_translations h
                 JOIN portal_product_translation_revisions r ON r.id=h.published_revision_id WHERE h.product_code=?'],
            'announcement'=>['SELECT source_locale,source_revision FROM portal_announcements WHERE id=? AND deleted_at IS NULL',
                'SELECT h.locale_code,r.source_revision,r.origin FROM portal_announcement_translations h
                 JOIN portal_announcement_translation_revisions r ON r.id=h.published_revision_id WHERE h.announcement_id=?'],
            'policy'=>["SELECT source_locale,source_revision FROM portal_policy_documents
                WHERE SUBSTRING(SHA2(CONCAT(policy_type,CHAR(10),policy_version),256),1,32)=?",
                "SELECT h.locale_code,r.source_revision,r.origin FROM portal_policy_translation_heads h
                 JOIN portal_policy_translation_revisions r ON r.id=h.published_revision_id
                 WHERE SUBSTRING(SHA2(CONCAT(h.policy_type,CHAR(10),h.policy_version),256),1,32)=?"],
        };
        $q=$this->db->prepare($entitySql);$q->execute([$id]);$entity=$q->fetch();
        if(!$entity)throw new Problem('translation_resource_not_found',404);
        $selected=$this->selected($type,$id);
        $q=$this->db->prepare($headSql);$q->execute([$id]);
        $heads=$q->fetchAll();
        $sourceRevision=(int)$entity['source_revision'];
        if($selected!==null&&in_array($type,['product','announcement'],true)){
            foreach($heads as$head){
                if($head['locale_code']===$entity['source_locale']){
                    $sourceRevision=(int)$head['source_revision'];
                    break;
                }
            }
        }
        $published=[];$completed=[];
        foreach($heads as$row){
            if($selected!==null&&!in_array($row['locale_code'],$selected,true))continue;
            $published[]=$row['locale_code'];
            if((int)$row['source_revision']===$sourceRevision)$completed[]=$row['locale_code'];
        }
        $q=$this->db->prepare('SELECT j.id,j.target_locale,j.status,j.last_error_code FROM portal_translation_jobs j
            WHERE j.resource_type=? AND j.resource_id=? AND j.source_revision=?
              AND JSON_EXTRACT(j.source_snapshot_json,\'$.publish_when_ready\')=TRUE
              AND j.id=(SELECT MAX(k.id) FROM portal_translation_jobs k WHERE k.resource_type=j.resource_type
                AND k.resource_id=j.resource_id AND k.target_locale=j.target_locale AND k.source_revision=j.source_revision
                AND JSON_EXTRACT(k.source_snapshot_json,\'$.publish_when_ready\')=TRUE)
            ORDER BY j.target_locale');
        $q->execute([$type,$id,$sourceRevision]);
        $jobs=[];$active=0;$failed=0;
        foreach($q->fetchAll()as$row){
            if($selected!==null&&!in_array($row['target_locale'],$selected,true))continue;
            $row['id']=(int)$row['id'];$jobs[]=$row;
            if(in_array($row['status'],['pending','running','retry'],true))$active++;
            if(in_array($row['status'],['failed','superseded','cancelled'],true))$failed++;
        }
        $targets=$selected??array_values(array_unique([$entity['source_locale'],'en',...$published]));
        return ['resource_type'=>$type,'resource_id'=>$id,'source_locale'=>$entity['source_locale'],
            'publication_locales'=>$targets,'published_locales'=>$published,'completed_locales'=>$completed,
            'translated_locales'=>array_values(array_diff($completed,[$entity['source_locale']])),
            'completed'=>count(array_intersect($targets,$completed)),'total'=>count($targets),
            'active'=>$active,'failed'=>$failed,'jobs'=>$jobs];
    }

    public function candidates(string $type,string $id,string $requested,string $source): array
    {
        $selected=$this->selected($type,$id);
        if($selected===null)return array_values(array_unique([$requested,'en',$source]));
        return in_array($requested,$selected,true)?array_values(array_unique([$requested,'en'])):['en'];
    }
}
