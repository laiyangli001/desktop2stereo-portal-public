<?php
declare(strict_types=1);

namespace D2S\services;

use PDO;
use D2S\domain\Problem;
use D2S\repositories\Database;

/** Account license management without access to signing secrets. */
final readonly class LicenseOverview
{
    public function __construct(private PDO $db)
    {
    }

    public function license(int $userId, string $product = 'desktop2stereo'): ?array
    {
        $query=$this->db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=?');
        $query->execute([$userId,ProductCatalog::code($product)]);
        $row=$query->fetch();
        return $row===false?null:$this->view($row);
    }

    public function all(int $userId, string $locale='en'): array
    {
        $query=$this->db->prepare("SELECT l.*,p.enabled product_enabled,p.deleted_at product_deleted_at,p.code catalog_product
            FROM portal_licenses l LEFT JOIN portal_products p ON p.code=l.product
            WHERE l.user_id=? ORDER BY (l.product='desktop2stereo') DESC,p.sort_order,l.product");
        $query->execute([$userId]);$translations=new ProductTranslations($this->db);$items=[];
        foreach($query->fetchAll()as$row){
            $retired=$row['catalog_product']===null||!(bool)$row['product_enabled']||$row['product_deleted_at']!==null;
            $content=$retired?null:$translations->publicContent($row['product'],$locale);
            $items[]=$this->view($row)+[
                'title'=>$content['title']??$row['product'],'content_locale'=>$content['content_locale']??$locale,
                'retired'=>$retired,
                'created_at'=>(int)$row['created_at'],'updated_at'=>(int)$row['updated_at'],
            ];
        }
        return $items;
    }

    /** Staff callers must authorize users.read before exposing the inventory filter. */
    public function productOptions(string $locale): array
    {
        $items=[];$translations=new ProductTranslations($this->db);
        foreach($this->db->query('SELECT code,enabled,deleted_at FROM portal_products ORDER BY sort_order,code')->fetchAll()as$row){
            $code=$row['code'];
            $content=(bool)$row['enabled']&&$row['deleted_at']===null?$translations->publicContent($code,$locale):null;
            $items[]=['product'=>$code,'title'=>$content['title']??$code];
        }
        return $items;
    }

    public function softwareDownloads(int $userId,string $locale,int $now,SecretBox $secrets): array
    {
        $owned=[];
        foreach($this->all($userId,$locale)as$license){
            if($license['kind']!=='formal'||$license['status']!=='active'||
                ($license['expires_at']!==null&&(int)$license['expires_at']<=$now)||
                ($license['trial_started_at']!==null&&(int)$license['trial_started_at']>$now))continue;
            $product=$license['product'];
            $releases=$this->db->prepare("SELECT id,platform,version_code,version_name,release_notes,published_at
                FROM portal_product_releases WHERE product_code=? AND status='published'
                ORDER BY platform,version_code DESC");
            $releases->execute([$product]);$latest=[];
            foreach($releases->fetchAll()as$release){
                if(isset($latest[$release['platform']]))continue;
                $release['version_code']=(int)$release['version_code'];
                $release['published_at']=$release['published_at']===null?null:(int)$release['published_at'];
                $minimum=$this->db->prepare('SELECT minimum_supported_version FROM portal_product_update_policies WHERE product_code=? AND platform=?');
                $minimum->execute([$product,$release['platform']]);
                $release['minimum_supported_version']=(int)($minimum->fetchColumn()?:0);
                $fileQuery=$this->db->prepare('SELECT id,architecture,file_format,file_name,byte_size,sha256,android_package_name,android_signing_sha256
                    FROM portal_product_release_files WHERE release_id=? ORDER BY architecture,file_format');
                $fileQuery->execute([$release['id']]);$release['files']=[];
                foreach($fileQuery->fetchAll()as$file){
                    $file['byte_size']=(int)$file['byte_size'];$file['channels']=[];
                    $channelQuery=$this->db->prepare('SELECT c.id,c.channel_name,c.provider,c.region_code,c.download_url,c.passcode_ciphertext,c.sort_order
                        FROM portal_product_download_channels c JOIN portal_product_download_hosts h
                            ON h.hostname=c.hostname AND h.enabled=1
                        WHERE c.file_id=? AND c.enabled=1 ORDER BY c.sort_order,c.id');
                    $channelQuery->execute([$file['id']]);
                    foreach($channelQuery->fetchAll()as$channel){
                        $passcode=$channel['passcode_ciphertext']===null?null:
                            $secrets->decrypt($channel['passcode_ciphertext'],'product-download-channel:'.$channel['id']);
                        $file['channels'][]=['channel_name'=>$channel['channel_name'],'provider'=>$channel['provider'],
                            'region_code'=>$channel['region_code'],'download_url'=>$channel['download_url'],
                            'passcode'=>$passcode,'sort_order'=>(int)$channel['sort_order']];
                    }
                    unset($file['id']);
                    $release['files'][]=$file;
                }
                unset($release['id']);
                $latest[$release['platform']]=$release;
            }
            if($latest!==[])$owned[]=['product'=>$product,'title'=>$license['title'],'content_locale'=>$license['content_locale'],
                'platforms'=>array_values($latest)];
        }
        return ['items'=>$owned];
    }

    public function devices(int $userId, string $product = 'desktop2stereo', string $locale='en'): array
    {
        ProductCatalog::code($product);
        $licenses=$this->all($userId,$locale);$products=[];
        foreach((new ProductCatalog($this->db))->publicList($locale)as$item){
            $products[$item['code']]=['product'=>$item['code'],'title'=>$item['presentation']['title'],
                'content_locale'=>$item['presentation']['content_locale'],'retired'=>false];
        }
        foreach($licenses as$item)$products[$item['product']]=array_intersect_key($item,array_flip(['product','title','content_locale','retired']));
        $license=null;
        foreach($licenses as$item)if($item['product']===$product){$license=$item;break;}
        if(!isset($products[$product])&&$product!=='desktop2stereo')throw new Problem('product_not_found',404);
        $selected=$products[$product]??['product'=>$product,'title'=>'Desktop2Stereo','content_locale'=>'en','retired'=>true];
        // Signing records and current sessions prove which software actually used a device.
        // Preserve the old global history only for accounts that have never held another software.
        $devices=$this->db->prepare("SELECT device_hash,MIN(first_seen_at) first_seen_at,MAX(last_seen_at) last_seen_at FROM (
            SELECT c.device_hash,MIN(c.issued_at) first_seen_at,MAX(c.issued_at) last_seen_at
                FROM portal_license_credentials c JOIN portal_licenses l ON l.id=c.license_id AND l.user_id=c.user_id
                WHERE c.user_id=? AND l.product=? GROUP BY c.device_hash
            UNION ALL SELECT device_hash,created_at,verified_at FROM portal_client_sessions WHERE user_id=? AND product=?
            UNION ALL SELECT d.device_hash,d.first_seen_at,d.last_seen_at FROM portal_devices d WHERE d.user_id=? AND ?='desktop2stereo'
                AND NOT EXISTS(SELECT 1 FROM portal_licenses l WHERE l.user_id=d.user_id AND l.product<>'desktop2stereo')
            ) activity GROUP BY device_hash ORDER BY last_seen_at DESC,device_hash");
        $devices->execute([$userId,$product,$userId,$product,$userId,$product]);
        $session=$this->db->prepare('SELECT device_hash,session_version,created_at,verified_at,expires_at
            FROM portal_client_sessions WHERE user_id=? AND product=?');
        $session->execute([$userId,$product]);$current=$session->fetch();
        return ['product'=>$product,'selected_product'=>$selected,'license'=>$license,'licenses'=>$licenses,
            'products'=>array_values($products),'devices'=>$devices->fetchAll(),
            'current_session'=>$current===false?null:$current];
    }

    public function logout(int $userId, string $deviceHash, int $sessionVersion, array $context, int $now, string $product = 'desktop2stereo'): void
    {
        Database::transaction($this->db,function(PDO $db)use($userId,$deviceHash,$sessionVersion,$context,$now,$product):void{
            $this->removeSession($userId,$deviceHash,$sessionVersion,$product,true);
            $context['device_fingerprint']=$deviceHash;
            Audit::user($db,$userId,Audit::email($db,$userId),'LOGOUT','SUCCESS',$context,$now);
        });
    }

    public function logoutByAdmin(int $adminId, int $userId, string $deviceHash, int $sessionVersion, array $context, int $now, string $product): void
    {
        Permissions::require($this->db,$adminId,'users.manage');
        Database::transaction($this->db,function(PDO $db)use($adminId,$userId,$deviceHash,$sessionVersion,$context,$now,$product):void{
            $this->removeSession($userId,$deviceHash,$sessionVersion,$product,false);
            (new Backoffice($db))->audit($adminId,'UPDATE_USER',(string)$userId,[
                'action'=>'client_session_logout','scope'=>'software','product'=>$product,
                'device_hash'=>$deviceHash,'session_version'=>$sessionVersion,
            ],$context,$now);
        });
    }

    private function removeSession(int $userId,string $deviceHash,int $sessionVersion,string $product,bool $requireActive): void
    {
        ProductCatalog::code($product);
        if(!preg_match('/^[a-f0-9]{64}$/D',$deviceHash)||$sessionVersion<1)throw new Problem('client_session_invalid');
        $db=$this->db;
        $user=$db->prepare('SELECT id,status FROM portal_users WHERE id=? FOR UPDATE');$user->execute([$userId]);$owner=$user->fetch();
        if($owner===false)throw new Problem($requireActive?'session_invalid':'user_not_found',$requireActive?401:404);
        if($requireActive&&$owner['status']!=='active')throw new Problem('session_invalid',401);
        $license=$db->prepare('SELECT id FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');$license->execute([$userId,$product]);
        $session=$db->prepare('SELECT device_hash,session_version FROM portal_client_sessions WHERE user_id=? AND product=? FOR UPDATE');
        $session->execute([$userId,$product]);$current=$session->fetch();
        if($current===false||$current['device_hash']!==$deviceHash||(int)$current['session_version']!==$sessionVersion){
            throw new Problem('client_session_changed',409);
        }
        $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=?')->execute([$userId,$product]);
    }

    private function view(array $license): array
    {
        return ['id'=>$license['id'],'product'=>$license['product'],'kind'=>$license['kind'],
            'status'=>$license['status'],
            'trial_started_at'=>$license['trial_started_at']===null?null:(int)$license['trial_started_at'],
            'expires_at'=>$license['expires_at']===null?null:(int)$license['expires_at']];
    }
}
