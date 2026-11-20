<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Money;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Product identity and prices are shared; text is stored in the existing locale revisions. */
final readonly class ProductCatalog
{
    private const DOWNLOAD_FIELDS = ['download_url','download_quark','download_baidu'];

    public function __construct(private PDO $db) {}

    public static function code(string $code): string
    {
        if(!preg_match('/^[a-z][a-z0-9_-]{2,31}$/D',$code)||$code==='wallet_recharge'){
            throw new Problem('product_code_invalid');
        }
        return $code;
    }

    /** Include retired products when resolving immutable paid orders. */
    public function exists(string $code): bool
    {
        $query=$this->db->prepare('SELECT code FROM portal_products WHERE code=?');
        $query->execute([$code]);
        return $query->fetchColumn()!==false;
    }

    public function adminList(int $adminId,string $locale): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        return $this->items($locale,true,$adminId);
    }

    public function publicList(string $locale): array
    {
        return $this->items($locale,false);
    }

    private function items(string $locale,bool $admin,int $adminId=0): array
    {
        $rows=$this->db->query('SELECT code,source_locale,enabled,purchases_enabled,image_path,sort_order,lock_version,
            download_url,download_quark,download_baidu'.($admin?',draft_json':'').'
            FROM portal_products WHERE deleted_at IS NULL'.($admin?'':' AND enabled=1').' ORDER BY sort_order,code')->fetchAll();
        $translations=new ProductTranslations($this->db);
        $priceQuery=$this->db->prepare('SELECT currency,amount,revision,enabled FROM portal_product_prices
            WHERE product_code=?'.($admin?'':' AND enabled=1').' ORDER BY currency');
        $releaseQuery=$admin?$this->db->prepare("SELECT platform,MAX(CASE WHEN status='published' THEN version_code ELSE NULL END) latest_version_code
            FROM portal_product_releases WHERE product_code=? GROUP BY platform ORDER BY platform"):null;
        $operationsQuery=$admin?$this->db->prepare('SELECT beta_starts_at,beta_ends_at,post_beta_mode FROM portal_product_operations WHERE product_code=?'):null;
        $now=time();
        $items=[];
        foreach($rows as$row){
            $content=$translations->publicContent($row['code'],$locale);
            if(!$admin&&$content===null)continue;
            $priceQuery->execute([$row['code']]);
            $prices=array_map(static fn(array $price):array=>[
                'currency'=>$price['currency'],'amount'=>(int)$price['amount'],
                'revision'=>(int)$price['revision'],'enabled'=>(bool)$price['enabled'],
            ],$priceQuery->fetchAll());
            $platformVersions=[];$operatingMode='not_configured';$betaStartsAt=null;$betaEndsAt=null;
            if($admin){
                $releaseQuery->execute([$row['code']]);$platformVersions=$releaseQuery->fetchAll();
                foreach($platformVersions as &$platformVersion){
                    $platformVersion['latest_version_code']=$platformVersion['latest_version_code']===null?null:(int)$platformVersion['latest_version_code'];
                }
                unset($platformVersion);
                $operationsQuery->execute([$row['code']]);$operations=$operationsQuery->fetch();
                if($operations){
                    $betaStartsAt=$operations['beta_starts_at']===null?null:(int)$operations['beta_starts_at'];
                    $betaEndsAt=$operations['beta_ends_at']===null?null:(int)$operations['beta_ends_at'];
                    $postBetaMode=$operations['post_beta_mode'];
                    $operatingMode=$betaEndsAt!==null&&$betaEndsAt<=$now?$postBetaMode:
                        ($betaStartsAt!==null&&$betaStartsAt>$now?'scheduled':(($betaStartsAt!==null||$betaEndsAt!==null)?'beta':'not_configured'));
                }
            }
            $draft=$admin&&$row['draft_json']!==null
                ? json_decode($row['draft_json'],true,16,JSON_THROW_ON_ERROR) : null;
            $items[]=[
                'code'=>$row['code'],'source_locale'=>$row['source_locale'],
                'enabled'=>(bool)$row['enabled'],'purchases_enabled'=>(bool)$row['purchases_enabled'],
                'image_path'=>$row['image_path'],'sort_order'=>(int)$row['sort_order'],
                'download_url'=>SiteContent::httpsUrl($row['download_url'])?$row['download_url']:'',
                'download_quark'=>SiteContent::httpsUrl($row['download_quark'])?$row['download_quark']:'',
                'download_baidu'=>SiteContent::httpsUrl($row['download_baidu'])?$row['download_baidu']:'',
                'lock_version'=>(int)$row['lock_version'],'presentation'=>$content,'prices'=>$prices,
                ...($admin?[
                    'draft'=>$draft??$translations->get($adminId,$row['code'],$row['source_locale']),
                    'published'=>self::hasPublishedContent($this->db,$row['code']),
                    'publication_locales'=>(new ContentPublicationLanguages($this->db))->selected('product',$row['code'])??[$row['source_locale'],'en'],
                    'platform_versions'=>$platformVersions,'operating_mode'=>$operatingMode,
                    'beta_starts_at'=>$betaStartsAt,'beta_ends_at'=>$betaEndsAt,
                ]:[]),
            ];
        }
        return $items;
    }

    public static function hasPublishedContent(PDO $db,string $code,bool $lock=false): bool
    {
        $query=$db->prepare('SELECT 1 FROM portal_product_translations
            WHERE product_code=? AND published_revision_id IS NOT NULL LIMIT 1'.($lock?' FOR SHARE':''));
        $query->execute([$code]);
        return $query->fetchColumn()!==false;
    }

    public function deleteAllLanguages(int $adminId,string $code,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');self::code($code);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$code,$context,$now):array{
            $q=$db->prepare('SELECT code FROM portal_products WHERE code=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$code]);
            if($q->fetchColumn()===false)throw new Problem('product_not_found',404);
            $db->prepare('UPDATE portal_products SET enabled=0,purchases_enabled=0,draft_json=NULL,deleted_at=?,source_revision=source_revision+1,
                lock_version=lock_version+1,updated_at=? WHERE code=?')->execute([$now,$now,$code]);
            $db->prepare('UPDATE portal_product_translations SET draft_revision_id=NULL,published_revision_id=NULL,
                lock_version=lock_version+1,updated_at=? WHERE product_code=?')->execute([$now,$code]);
            (new ContentPublicationLanguages($db))->cancel('product',$code,$now);
            (new Backoffice($db))->audit($adminId,'DELETE_PRODUCT_ALL_LANGUAGES',$code,[],$context,$now);
            EdgeOneCachePurge::enqueuePaths($db,['/products','/shop','/','/sitemap.xml'],'product:delete:'.$code.':'.$now,$now);
            return ['code'=>$code,'deleted'=>true];
        });
    }

    public function save(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $code=self::code(is_string($input['code']??null)?$input['code']:'');
        $locale=is_string($input['source_locale']??null)?$input['source_locale']:'';
        $locale=(new LocaleRegistry())->normalizeForAdmin($locale);
        if($locale===null)throw new Problem('locale_unsupported',400);
        $title=Backoffice::text($input,'title',160,true);
        $description=Backoffice::text($input,'description',5000);
        $enabled=$input['enabled']??null;$purchase=$input['purchases_enabled']??null;
        $version=$input['lock_version']??null;$sort=$input['sort_order']??0;
        $publish=$input['publish']??false;
        $downloads=[];
        foreach(self::DOWNLOAD_FIELDS as $field){
            if(!array_key_exists($field,$input))continue;
            if(!is_string($input[$field]))throw new Problem('product_download_invalid');
            $value=trim($input[$field]);
            if($value!==''&&!SiteContent::httpsUrl($value))throw new Problem('product_download_invalid');
            $downloads[$field]=$value;
        }
        if(!is_bool($publish))throw new Problem('product_input_invalid');
        if(!is_array($input['target_locales']??[]))throw new Problem('translation_targets_invalid');
        $selected=(new ContentPublicationLanguages($this->db))->targets($locale,$input['target_locales']??[]);
        if(!is_bool($enabled)||!is_bool($purchase)||!is_int($version)||$version<0||
            !is_int($sort)||$sort<0||$sort>100000)throw new Problem('product_input_invalid');
        if(!is_array($input['prices']??null)||array_diff(array_keys($input['prices']),['CNY','USD'])!==[])
            throw new Problem('product_prices_invalid');
        foreach($input['prices']as$currency=>$price){
            Money::currency($currency);
            if(!is_array($price)||!is_bool($price['enabled']??null))throw new Problem('product_prices_invalid');
            Money::positive($price['amount']??null);
        }
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$input,$code,$locale,$title,$description,
            $enabled,$purchase,$version,$sort,$publish,$selected,$downloads,$context,$now):array{
            $q=$db->prepare('SELECT source_locale,lock_version,deleted_at,draft_json FROM portal_products WHERE code=? FOR UPDATE');
            $q->execute([$code]);$existing=$q->fetch();
            if($existing&&$existing['deleted_at']!==null)throw new Problem('product_not_found',404);
            if(($existing?(int)$existing['lock_version']:0)!==$version)throw new Problem('record_changed',409);
            $previousDraft=$existing&&$existing['draft_json']!==null
                ? json_decode($existing['draft_json'],true,16,JSON_THROW_ON_ERROR) : [];
            $image=$previousDraft['image_path']??null;
            if(!$publish){
                $draft=[
                    'source_locale'=>$locale,'title'=>$title,'description'=>$description,
                    'enabled'=>$enabled,'purchases_enabled'=>$purchase,'sort_order'=>$sort,
                    'prices'=>$input['prices'],'target_locales'=>$selected,...$downloads,
                    'image_path'=>$image,
                ];
                $json=json_encode($draft,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if($existing){
                    $db->prepare('UPDATE portal_products SET draft_json=?,lock_version=lock_version+1 WHERE code=?')
                        ->execute([$json,$code]);
                }else{
                    $db->prepare("INSERT INTO portal_products
                        (code,title_zh,title_en,description_zh,description_en,enabled,updated_at,source_locale,source_revision,purchases_enabled,sort_order,lock_version,draft_json)
                        VALUES (?,'','','','',0,?,?,1,0,0,1,?)")->execute([$code,$now,$locale,$json]);
                }
                (new Backoffice($db))->audit($adminId,'SAVE_PRODUCT_DRAFT',$code,[],$context,$now);
                return ['code'=>$code,'lock_version'=>$version+1,'translation'=>$draft,'translation_jobs'=>[]];
            }
            if($existing&&$existing['source_locale']!==$locale){
                $db->prepare('UPDATE portal_products SET source_locale=?,source_revision=source_revision+1 WHERE code=?')
                    ->execute([$locale,$code]);
            }
            if(!$existing){
                $db->prepare('INSERT INTO portal_products(code,title_zh,title_en,description_zh,description_en,enabled,
                    updated_at,source_locale,source_revision,purchases_enabled,sort_order,lock_version)
                    VALUES (?,?,?,?,?,?,?, ?,1,?,?,1)')
                    ->execute([$code,$locale==='zh-Hans'?$title:'',$locale==='en'?$title:'',
                        $locale==='zh-Hans'?$description:'',$locale==='en'?$description:'',(int)$enabled,
                        $now,$locale,(int)$purchase,$sort]);
            }else{
                $db->prepare('UPDATE portal_products SET enabled=?,purchases_enabled=?,sort_order=?,
                    image_path=COALESCE(?,image_path),draft_json=NULL,lock_version=lock_version+1,updated_at=? WHERE code=?')
                    ->execute([(int)$enabled,(int)$purchase,$sort,$image,$now,$code]);
            }
            if($downloads!==[]){
                $statement=$db->prepare('UPDATE portal_products SET download_url=COALESCE(?,download_url),
                    download_quark=COALESCE(?,download_quark),download_baidu=COALESCE(?,download_baidu) WHERE code=?');
                $statement->execute([$downloads['download_url']??null,$downloads['download_quark']??null,$downloads['download_baidu']??null,$code]);
            }
            $translations=new ProductTranslations($db);
            $current=$translations->get($adminId,$code,$locale);
            $saved=$translations->save($adminId,$code,$locale,[
                'title'=>$title,'description'=>$description,'lock_version'=>$current['lock_version'],
            ],$context,$now);
            $translations->publish($adminId,$code,$locale,$context,$now);
            (new ContentPublicationLanguages($db))->save('product',$code,$locale,$selected,$now);
            $targets=array_values(array_diff($selected,[$locale]));
            $jobs=$targets===[]?[]:(new TranslationJobs($db))->enqueueMany($adminId,'product',$code,$targets,
                ['publish_when_ready'=>true],$context,$now)['jobs'];
            foreach($input['prices']as$currency=>$price){
                $db->prepare('INSERT INTO portal_product_prices(product_code,currency,amount,revision,enabled,updated_at)
                    VALUES (?,?,?,1,?,?) ON DUPLICATE KEY UPDATE
                    updated_at=IF(amount<>VALUES(amount) OR enabled<>VALUES(enabled),VALUES(updated_at),updated_at),
                    revision=revision+IF(amount<>VALUES(amount) OR enabled<>VALUES(enabled),1,0),
                    amount=VALUES(amount),enabled=VALUES(enabled)')
                    ->execute([$code,$currency,$price['amount'],(int)$price['enabled'],$now]);
            }
            if($code==='desktop2stereo'){
                $db->exec("INSERT INTO portal_catalog_prices(currency,amount,revision,enabled,updated_at)
                    SELECT currency,amount,revision,enabled,updated_at FROM portal_product_prices WHERE product_code='desktop2stereo'
                    ON DUPLICATE KEY UPDATE amount=VALUES(amount),revision=VALUES(revision),enabled=VALUES(enabled),updated_at=VALUES(updated_at)");
            }
            (new Backoffice($db))->audit($adminId,$existing?'UPDATE_PRODUCT':'CREATE_PRODUCT',$code,
                ['purchases_enabled'=>$purchase,'enabled'=>$enabled],$context,$now);
            EdgeOneCachePurge::enqueuePaths($db,['/products','/shop'],'product:save:'.$code.':'.($version+1),$now);
            return ['code'=>$code,'lock_version'=>$version+1,'translation'=>$saved,'translation_jobs'=>$jobs];
        });
    }
}
