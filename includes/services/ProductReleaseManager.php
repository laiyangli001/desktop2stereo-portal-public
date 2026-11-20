<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ProductReleaseManager
{
    private const PLATFORMS = ['windows','macos','linux','android'];
    private const FORMATS = [
        'windows'=>['exe','msi','zip'],
        'macos'=>['dmg','pkg','zip'],
        'linux'=>['appimage','deb','rpm','tar_gz','zip'],
        'android'=>['apk'],
    ];

    public function __construct(private PDO $db, private SecretBox $secrets) {}

    public function releases(int $adminId, string $product): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);
        $this->assertProduct($this->db,$product);
        $query=$this->db->prepare('SELECT * FROM portal_product_releases WHERE product_code=? ORDER BY platform,version_code DESC');
        $query->execute([$product]);
        $items=$query->fetchAll();
        $files=$this->db->prepare('SELECT * FROM portal_product_release_files WHERE release_id=? ORDER BY architecture,file_format');
        $channels=$this->db->prepare('SELECT c.id,c.file_id,c.channel_name,c.provider,c.region_code,c.download_url,
            c.sort_order,c.enabled,c.checked_at,c.check_status,c.check_error_code,c.passcode_ciphertext IS NOT NULL passcode_configured,
            h.enabled host_enabled
            FROM portal_product_download_channels c LEFT JOIN portal_product_download_hosts h ON h.hostname=c.hostname
            WHERE c.file_id=? ORDER BY c.sort_order,c.id');
        $policies=$this->db->prepare('SELECT minimum_supported_version,revision FROM portal_product_update_policies WHERE product_code=? AND platform=?');
        foreach($items as &$release){
            $release['version_code']=(int)$release['version_code'];
            $release['created_at']=(int)$release['created_at'];
            $release['updated_at']=(int)$release['updated_at'];
            $release['published_at']=$release['published_at']===null?null:(int)$release['published_at'];
            $release['withdrawn_at']=$release['withdrawn_at']===null?null:(int)$release['withdrawn_at'];
            $policies->execute([$product,$release['platform']]);
            $policy=$policies->fetch()?:['minimum_supported_version'=>0,'revision'=>1];
            $release['minimum_supported_version']=(int)$policy['minimum_supported_version'];
            $release['policy_revision']=(int)$policy['revision'];
            $files->execute([$release['id']]);
            $release['files']=$files->fetchAll();
            foreach($release['files'] as &$file){
                $file['byte_size']=(int)$file['byte_size'];
                $channels->execute([$file['id']]);
                $file['channels']=$channels->fetchAll();
                foreach($file['channels'] as &$channel){
                    $channel['sort_order']=(int)$channel['sort_order'];
                    $channel['enabled']=(bool)$channel['enabled'];
                    $channel['passcode_configured']=(bool)$channel['passcode_configured'];
                    $channel['host_enabled']=(bool)$channel['host_enabled'];
                    $channel['checked_at']=$channel['checked_at']===null?null:(int)$channel['checked_at'];
                }
                unset($channel);
            }
            unset($file);
        }
        unset($release);
        $policyList=[];
        foreach(self::PLATFORMS as $platform){
            $policies->execute([$product,$platform]);
            $policy=$policies->fetch()?:['minimum_supported_version'=>0,'revision'=>1];
            $policyList[$platform]=['minimum_supported_version'=>(int)$policy['minimum_supported_version'],'revision'=>(int)$policy['revision']];
        }
        return ['items'=>$items,'policies'=>$policyList,'download_hosts'=>$this->downloadHosts($adminId)['items']];
    }

    public function updateDownloadChannel(int $adminId,string $product,string $channelId,array $input,array $context,int $now,bool $remove=false): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);
        $channelId=self::id($channelId);
        $channel=$remove?null:$this->validateChannel($input);
        if($channel!==null&&$channel['id']!==null&&$channel['id']!==$channelId)throw new Problem('product_download_channel_invalid');
        $hostname='';
        if($channel!==null){
            $channel['id']=$channelId;
            $hostname=$this->normalizeHost((string)parse_url($channel['download_url'],PHP_URL_HOST));
            $hostQuery=$this->db->prepare('SELECT enabled FROM portal_product_download_hosts WHERE hostname=?');
            $hostQuery->execute([$hostname]);
            if(!(bool)$hostQuery->fetchColumn())throw new Problem('product_download_host_not_allowed',403);
        }

        return Database::transaction($this->db,function(PDO $db)use($adminId,$product,$channelId,$channel,$hostname,$context,$now,$remove):array{
            $query=$db->prepare('SELECT c.*,r.status release_status FROM portal_product_download_channels c
                JOIN portal_product_release_files f ON f.id=c.file_id
                JOIN portal_product_releases r ON r.id=f.release_id
                WHERE c.id=? AND r.product_code=? FOR UPDATE');
            $query->execute([$channelId,$product]);$before=$query->fetch();
            if(!$before)throw new Problem('product_download_channel_not_found',404);
            if($before['release_status']!=='published')throw new Problem('product_release_immutable',409);
            $snapshot=static fn(array $row):array=>[
                'hostname'=>$row['hostname'],'channel_name'=>$row['channel_name'],'provider'=>$row['provider'],
                'region_code'=>$row['region_code'],'sort_order'=>(int)$row['sort_order'],
                'enabled'=>(bool)$row['enabled'],'passcode_configured'=>$row['passcode_ciphertext']!==null,
            ];
            if($remove){
                if((bool)$before['enabled'])throw new Problem('product_download_channel_disable_first',409);
                $available=$db->prepare('SELECT COUNT(*) FROM portal_product_download_channels c
                    JOIN portal_product_download_hosts h ON h.hostname=c.hostname
                    WHERE c.file_id=? AND c.id<>? AND c.enabled=1 AND h.enabled=1');
                $available->execute([$before['file_id'],$channelId]);
                if((int)$available->fetchColumn()<1)throw new Problem('product_release_channel_required',409);
                $db->prepare('DELETE FROM portal_product_download_channels WHERE id=?')->execute([$channelId]);
                (new Backoffice($db))->audit($adminId,'REMOVE_PRODUCT_DOWNLOAD_CHANNEL',$product,
                    ['channel_id'=>$channelId,'before'=>$snapshot($before),'after'=>null],$context,$now);
                return ['id'=>$channelId,'removed'=>true];
            }

            $hostLock=$db->prepare('SELECT enabled FROM portal_product_download_hosts WHERE hostname=? FOR UPDATE');
            $hostLock->execute([$hostname]);
            if(!(bool)$hostLock->fetchColumn())throw new Problem('product_download_host_not_allowed',403);
            $passcode=$before['passcode_ciphertext'];
            if($channel['passcode']!=='')$passcode=$this->secrets->encrypt($channel['passcode'],'product-download-channel:'.$channelId);
            elseif($channel['clear_passcode'])$passcode=null;
            $urlChanged=$before['download_url']!==$channel['download_url'];
            $db->prepare('UPDATE portal_product_download_channels SET hostname=?,channel_name=?,provider=?,region_code=?,
                download_url=?,passcode_ciphertext=?,sort_order=?,enabled=?,checked_at=?,check_status=?,check_error_code=?,updated_at=? WHERE id=?')
                ->execute([$hostname,$channel['channel_name'],$channel['provider'],$channel['region_code'],$channel['download_url'],
                    $passcode,$channel['sort_order'],(int)$channel['enabled'],$urlChanged?null:$before['checked_at'],
                    $urlChanged?null:$before['check_status'],$urlChanged?null:$before['check_error_code'],$now,$channelId]);
            $after=$snapshot(['hostname'=>$hostname,'channel_name'=>$channel['channel_name'],'provider'=>$channel['provider'],
                'region_code'=>$channel['region_code'],'sort_order'=>$channel['sort_order'],'enabled'=>$channel['enabled'],
                'passcode_ciphertext'=>$passcode]);
            (new Backoffice($db))->audit($adminId,'UPDATE_PRODUCT_DOWNLOAD_CHANNEL',$product,
                ['channel_id'=>$channelId,'before'=>$snapshot($before),'after'=>$after],$context,$now);
            return ['id'=>$channelId,'saved'=>true,'enabled'=>$channel['enabled']];
        });
    }

    public function saveRelease(int $adminId,string $product,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);
        $platform=$this->string($input,'platform',16,true);
        if(!in_array($platform,self::PLATFORMS,true))throw new Problem('product_release_platform_invalid');
        $versionCode=$this->integer($input['version_code']??null,1,2147483647,'product_release_version_invalid');
        $versionName=$this->string($input,'version_name',64,true);
        $notes=$this->string($input,'release_notes',16000,false);
        $releaseId=($input['release_id']??'')===''?null:self::id($input['release_id']);
        $rawFiles=$input['files']??null;
        if(!is_array($rawFiles)||!array_is_list($rawFiles))throw new Problem('product_release_files_invalid');
        $files=[];
        foreach($rawFiles as $rawFile)$files[]=$this->validateFile($platform,$rawFile);
        $this->assertAllowedChannels($files);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$product,$platform,$versionCode,$versionName,$notes,$releaseId,$files,$context,$now):array{
            $this->assertProduct($db,$product,true);
            $existing=null;
            $oldFiles=[];
            $oldSecrets=[];
            if($releaseId!==null){
                $q=$db->prepare('SELECT * FROM portal_product_releases WHERE id=? AND product_code=? FOR UPDATE');
                $q->execute([$releaseId,$product]);$existing=$q->fetch();
                if(!$existing)throw new Problem('product_release_not_found',404);
                if($existing['status']!=='draft')throw new Problem('product_release_immutable',409);
                $q=$db->prepare('SELECT id FROM portal_product_release_files WHERE release_id=?');
                $q->execute([$releaseId]);$oldFiles=$q->fetchAll(PDO::FETCH_COLUMN);
                if($oldFiles!==[]){
                    $marks=implode(',',array_fill(0,count($oldFiles),'?'));
                    $q=$db->prepare("SELECT id,passcode_ciphertext FROM portal_product_download_channels WHERE file_id IN ($marks)");
                    $q->execute($oldFiles);
                    foreach($q->fetchAll() as $secret)$oldSecrets[$secret['id']]=$secret['passcode_ciphertext'];
                    $q=$db->prepare("SELECT id FROM portal_product_download_channels WHERE file_id IN ($marks)");
                    $q->execute($oldFiles);$oldChannels=$q->fetchAll(PDO::FETCH_COLUMN);
                }
            }
            if($existing!==null&&$existing['platform']!==$platform)throw new Problem('product_release_platform_immutable',409);
            $before=$releaseId===null?null:$this->auditSnapshot($db,$releaseId);
            $id=$releaseId??bin2hex(random_bytes(16));
            if($existing===null){
                $db->prepare('INSERT INTO portal_product_releases(id,product_code,platform,version_code,version_name,release_notes,status,created_by,created_at,updated_at)
                    VALUES(?,?,?,?,?,\'draft\',?,?,?,?)')->execute([$id,$product,$platform,$versionCode,$versionName,$notes,$adminId,$now,$now]);
            }else{
                $db->prepare('UPDATE portal_product_releases SET version_code=?,version_name=?,release_notes=?,updated_at=? WHERE id=?')
                    ->execute([$versionCode,$versionName,$notes,$now,$id]);
                $db->prepare('DELETE FROM portal_product_release_files WHERE release_id=?')->execute([$id]);
            }
            foreach($files as $file){
                $fileId=$file['id']??bin2hex(random_bytes(16));
                if(isset($file['id'])&&!in_array($fileId,$oldFiles,true))throw new Problem('product_release_file_invalid');
                $db->prepare('INSERT INTO portal_product_release_files
                    (id,release_id,architecture,file_format,file_name,byte_size,sha256,android_package_name,android_signing_sha256,created_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$fileId,$id,$file['architecture'],$file['file_format'],$file['file_name'],
                        $file['byte_size'],$file['sha256'],$file['android_package_name'],$file['android_signing_sha256'],$now]);
                foreach($file['channels'] as $channel){
                    if(isset($channel['id'])&&(!isset($oldChannels)||!in_array($channel['id'],$oldChannels,true)))throw new Problem('product_download_channel_invalid');
                    if($existing===null&&isset($channel['id']))throw new Problem('product_download_channel_invalid');
                    $channelId=$channel['id']??bin2hex(random_bytes(16));
                    $hostname=$this->normalizeHost((string)parse_url($channel['download_url'],PHP_URL_HOST));
                    $passcode=null;
                    if(array_key_exists('passcode',$channel)&&$channel['passcode']!==''){
                        $passcode=$this->secrets->encrypt($channel['passcode'],'product-download-channel:'.$channelId);
                    }elseif(!($channel['clear_passcode']??false)){
                        $passcode=$oldSecrets[$channelId]??null;
                    }
                    $db->prepare('INSERT INTO portal_product_download_channels
                        (id,file_id,hostname,channel_name,provider,region_code,download_url,passcode_ciphertext,sort_order,enabled,created_at,updated_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$channelId,$fileId,$hostname,$channel['channel_name'],$channel['provider'],
                            $channel['region_code'],$channel['download_url'],$passcode,$channel['sort_order'],(int)$channel['enabled'],$now,$now]);
                }
            }
            $after=$this->auditSnapshot($db,$id);
            (new Backoffice($db))->audit($adminId,$existing===null?'CREATE_PRODUCT_RELEASE':'UPDATE_PRODUCT_RELEASE',
                $product,['before'=>$before,'after'=>$after],$context,$now);
            return ['id'=>$id,'status'=>'draft','saved'=>true];
        });
    }

    public function publish(int $adminId,string $product,string $releaseId,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);$releaseId=self::id($releaseId);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$product,$releaseId,$context,$now):array{
            $q=$db->prepare('SELECT * FROM portal_product_releases WHERE id=? AND product_code=? FOR UPDATE');
            $q->execute([$releaseId,$product]);$release=$q->fetch();
            if(!$release)throw new Problem('product_release_not_found',404);
            if($release['status']!=='draft')throw new Problem('product_release_state_invalid',409);
            $q=$db->prepare('SELECT MAX(version_code) FROM portal_product_releases WHERE product_code=? AND platform=? AND status=\'published\'');
            $q->execute([$product,$release['platform']]);$maximum=$q->fetchColumn();
            if($maximum!==false&&(int)$release['version_code']<=(int)$maximum)throw new Problem('product_release_version_not_newer',409);
            $q=$db->prepare('SELECT f.id,COUNT(c.id) channel_count FROM portal_product_release_files f
                LEFT JOIN portal_product_download_channels c ON c.file_id=f.id AND c.enabled=1
                    AND EXISTS(SELECT 1 FROM portal_product_download_hosts h WHERE h.hostname=c.hostname AND h.enabled=1)
                WHERE f.release_id=? GROUP BY f.id');
            $q->execute([$releaseId]);$files=$q->fetchAll();
            if($files===[])throw new Problem('product_release_files_required');
            foreach($files as $file)if((int)$file['channel_count']<1)throw new Problem('product_release_channel_required');
            $db->prepare('UPDATE portal_product_releases SET status=\'published\',published_at=?,updated_at=? WHERE id=?')
                ->execute([$now,$now,$releaseId]);
            (new Backoffice($db))->audit($adminId,'PUBLISH_PRODUCT_RELEASE',$product,
                ['release_id'=>$releaseId,'platform'=>$release['platform'],'version_code'=>(int)$release['version_code'],
                    'file_count'=>count($files),'status_before'=>'draft','status_after'=>'published'], $context,$now);
            return ['id'=>$releaseId,'status'=>'published','published_at'=>$now];
        });
    }

    public function withdraw(int $adminId,string $product,string $releaseId,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);$releaseId=self::id($releaseId);
        return Database::transaction($this->db,function(PDO $db)use($adminId,$product,$releaseId,$context,$now):array{
            $q=$db->prepare('SELECT platform,version_code,status FROM portal_product_releases WHERE id=? AND product_code=? FOR UPDATE');
            $q->execute([$releaseId,$product]);$release=$q->fetch();
            if(!$release)throw new Problem('product_release_not_found',404);
            if($release['status']!=='published')throw new Problem('product_release_state_invalid',409);
            $db->prepare('UPDATE portal_product_releases SET status=\'withdrawn\',withdrawn_at=?,updated_at=? WHERE id=?')
                ->execute([$now,$now,$releaseId]);
            (new Backoffice($db))->audit($adminId,'WITHDRAW_PRODUCT_RELEASE',$product,
                ['release_id'=>$releaseId,'platform'=>$release['platform'],'version_code'=>(int)$release['version_code'],
                    'status_before'=>'published','status_after'=>'withdrawn'],$context,$now);
            return ['id'=>$releaseId,'status'=>'withdrawn'];
        });
    }

    public function savePolicy(int $adminId,string $product,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);
        $platform=$this->string($input,'platform',16,true);
        if(!in_array($platform,self::PLATFORMS,true))throw new Problem('product_release_platform_invalid');
        $minimum=$this->integer($input['minimum_supported_version']??null,0,2147483647,'product_update_policy_invalid');
        return Database::transaction($this->db,function(PDO $db)use($adminId,$product,$platform,$minimum,$input,$context,$now):array{
            $this->assertProduct($db,$product,true);
            $expected=$this->integer($input['revision']??null,1,PHP_INT_MAX,'product_update_policy_conflict');
            $q=$db->prepare('SELECT minimum_supported_version,revision FROM portal_product_update_policies WHERE product_code=? AND platform=? FOR UPDATE');
            $q->execute([$product,$platform]);$before=$q->fetch();
            $actual=$before===false?1:(int)$before['revision'];
            if($actual!==$expected)throw new Problem('record_changed',409);
            if($minimum>0){
                $q=$db->prepare('SELECT id FROM portal_product_releases WHERE product_code=? AND platform=? AND version_code=? AND status=\'published\'');
                $q->execute([$product,$platform,$minimum]);$releaseId=$q->fetchColumn();
                if($releaseId===false||!$this->releaseHasEnabledChannels($db,(string)$releaseId))throw new Problem('product_update_minimum_release_invalid');
            }
            $db->prepare('INSERT INTO portal_product_update_policies(product_code,platform,minimum_supported_version,revision,updated_by,updated_at)
                VALUES(?,?,?,1,?,?) ON DUPLICATE KEY UPDATE minimum_supported_version=VALUES(minimum_supported_version),
                    revision=revision+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')
                ->execute([$product,$platform,$minimum,$adminId,$now]);
            $revision=$actual+($before===false?0:1);
            (new Backoffice($db))->audit($adminId,'UPDATE_PRODUCT_UPDATE_POLICY',$product,
                ['platform'=>$platform,'before'=>$before===false?['minimum_supported_version'=>0,'revision'=>1]:[
                    'minimum_supported_version'=>(int)$before['minimum_supported_version'],'revision'=>$actual],
                    'after'=>['minimum_supported_version'=>$minimum,'revision'=>$revision]],$context,$now);
            return ['platform'=>$platform,'minimum_supported_version'=>$minimum,'revision'=>$revision];
        });
    }

    public function productOperations(int $adminId,string $product): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);
        $this->assertProduct($this->db,$product);
        $query=$this->db->prepare('SELECT beta_starts_at,beta_ends_at,post_beta_mode,trial_enabled,trial_days,revision,updated_at
            FROM portal_product_operations WHERE product_code=?');
        $query->execute([$product]);
        $row=$query->fetch()?:[
            'beta_starts_at'=>null,'beta_ends_at'=>null,'post_beta_mode'=>'paid',
            'trial_enabled'=>0,'trial_days'=>30,'revision'=>1,'updated_at'=>null,
        ];
        foreach(['beta_starts_at','beta_ends_at','updated_at'] as $key)$row[$key]=$row[$key]===null?null:(int)$row[$key];
        $row['trial_enabled']=(bool)$row['trial_enabled'];
        $row['trial_days']=(int)$row['trial_days'];
        $row['revision']=(int)$row['revision'];
        return $row;
    }

    public function saveProductOperations(int $adminId,string $product,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $product=ProductCatalog::code($product);
        $start=$input['beta_starts_at']??null;
        $end=$input['beta_ends_at']??null;
        foreach([$start,$end] as $value){
            if($value!==null&&(!is_int($value)||$value<0||$value>4102444800))throw new Problem('product_operations_invalid');
        }
        if($start!==null&&$end!==null&&$end<=$start)throw new Problem('product_operations_invalid');
        $mode=$this->string($input,'post_beta_mode',16,true);
        if(!in_array($mode,['free','paid'],true))throw new Problem('product_operations_invalid');
        $trial=$input['trial_enabled']??null;
        if(!is_bool($trial))throw new Problem('product_operations_invalid');
        $days=$this->integer($input['trial_days']??null,1,3650,'product_operations_invalid');
        $revision=$this->integer($input['revision']??null,1,PHP_INT_MAX,'record_changed');
        return Database::transaction($this->db,function(PDO $db)use($adminId,$product,$start,$end,$mode,$trial,$days,$revision,$context,$now):array{
            $this->assertProduct($db,$product,true);
            $query=$db->prepare('SELECT beta_starts_at,beta_ends_at,post_beta_mode,trial_enabled,trial_days,revision
                FROM portal_product_operations WHERE product_code=? FOR UPDATE');
            $query->execute([$product]);$before=$query->fetch();
            $actual=$before===false?1:(int)$before['revision'];
            if($actual!==$revision)throw new Problem('record_changed',409);
            $db->prepare('INSERT INTO portal_product_operations
                (product_code,beta_starts_at,beta_ends_at,post_beta_mode,trial_enabled,trial_days,revision,updated_by,updated_at)
                VALUES(?,?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE beta_starts_at=VALUES(beta_starts_at),
                    beta_ends_at=VALUES(beta_ends_at),post_beta_mode=VALUES(post_beta_mode),
                    trial_enabled=VALUES(trial_enabled),trial_days=VALUES(trial_days),revision=revision+1,
                    updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')
                ->execute([$product,$start,$end,$mode,(int)$trial,$days,$adminId,$now]);
            $next=$actual+($before===false?0:1);
            $snapshot=static fn(array $row):array=>[
                'beta_starts_at'=>$row['beta_starts_at']===null?null:(int)$row['beta_starts_at'],
                'beta_ends_at'=>$row['beta_ends_at']===null?null:(int)$row['beta_ends_at'],
                'post_beta_mode'=>$row['post_beta_mode'],'trial_enabled'=>(bool)$row['trial_enabled'],
                'trial_days'=>(int)$row['trial_days'],'revision'=>(int)$row['revision'],
            ];
            (new Backoffice($db))->audit($adminId,'UPDATE_PRODUCT_OPERATIONS',$product,[
                'before'=>$before===false?null:$snapshot($before),
                'after'=>['beta_starts_at'=>$start,'beta_ends_at'=>$end,'post_beta_mode'=>$mode,
                    'trial_enabled'=>$trial,'trial_days'=>$days,'revision'=>$next],
            ],$context,$now);
            return ['beta_starts_at'=>$start,'beta_ends_at'=>$end,'post_beta_mode'=>$mode,
                'trial_enabled'=>$trial,'trial_days'=>$days,'revision'=>$next,'updated_at'=>$now];
        });
    }

    public function downloadHosts(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        return ['items'=>$this->db->query('SELECT hostname,enabled,verified_at,created_at,updated_at FROM portal_product_download_hosts ORDER BY hostname')->fetchAll()];
    }

    public function allowDownloadHost(int $adminId,string $host,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $host=$this->normalizeHost($host);
        $this->assertPublicHost($host);
        Database::transaction($this->db,function(PDO $db)use($adminId,$host,$context,$now):void{
            $db->prepare('INSERT INTO portal_product_download_hosts(hostname,enabled,verified_at,verified_by,created_at,updated_at)
                VALUES(?,1,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=1,verified_at=VALUES(verified_at),
                    verified_by=VALUES(verified_by),updated_at=VALUES(updated_at)')
                ->execute([$host,$now,$adminId,$now,$now]);
            (new Backoffice($db))->audit($adminId,'ALLOW_PRODUCT_DOWNLOAD_HOST',$host,
                ['hostname'=>$host,'enabled'=>true,'verified_at'=>$now],$context,$now);
        });
        return ['hostname'=>$host,'enabled'=>true,'verified_at'=>$now];
    }

    public function disableDownloadHost(int $adminId,string $host,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        $host=$this->normalizeHost($host);
        Database::transaction($this->db,function(PDO $db)use($adminId,$host,$context,$now):void{
            $q=$db->prepare('SELECT enabled FROM portal_product_download_hosts WHERE hostname=? FOR UPDATE');$q->execute([$host]);
            $before=$q->fetchColumn();if($before===false)throw new Problem('product_download_host_not_found',404);
            $db->prepare('UPDATE portal_product_download_hosts SET enabled=0,updated_at=? WHERE hostname=?')->execute([$now,$host]);
            (new Backoffice($db))->audit($adminId,'DISABLE_PRODUCT_DOWNLOAD_HOST',$host,
                ['enabled_before'=>(bool)$before,'enabled_after'=>false],$context,$now);
        });
        return ['hostname'=>$host,'enabled'=>false];
    }

    private function validateFile(string $platform,mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw['channels']??null))throw new Problem('product_release_file_invalid');
        $id=isset($raw['id'])&&$raw['id']!==''?self::id($raw['id']):null;
        $architecture=$this->string($raw,'architecture',32,true);
        if(!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$architecture))throw new Problem('product_release_file_invalid');
        $format=$this->string($raw,'file_format',24,true);
        if(!in_array($format,self::FORMATS[$platform],true))throw new Problem('product_release_file_invalid');
        $fileName=$this->string($raw,'file_name',255,true);
        if(str_contains($fileName,'/')||str_contains($fileName,'\\')||preg_match('/[\x00-\x1F\x7F]/',$fileName))throw new Problem('product_release_file_invalid');
        $byteSize=$this->integer($raw['byte_size']??null,1,1099511627776,'product_release_file_invalid');
        $sha=strtolower($this->string($raw,'sha256',64,true));
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new Problem('product_release_file_hash_invalid');
        $package=$this->string($raw,'android_package_name',255,false);
        $signing=$this->string($raw,'android_signing_sha256',64,false);
        if($platform==='android'){
            if(!preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/D',$package))throw new Problem('product_release_android_metadata_required');
            $signing=strtolower(str_replace(':','',$signing));
            if(!preg_match('/^[a-f0-9]{64}$/D',$signing))throw new Problem('product_release_android_metadata_required');
        }else{$package='';$signing='';}
        $channels=[];
        foreach($raw['channels'] as $channel)$channels[]=$this->validateChannel($channel);
        return ['id'=>$id,'architecture'=>$architecture,'file_format'=>$format,'file_name'=>$fileName,'byte_size'=>$byteSize,
            'sha256'=>$sha,'android_package_name'=>$package?:null,'android_signing_sha256'=>$signing?:null,'channels'=>$channels];
    }

    private function validateChannel(mixed $raw): array
    {
        if(!is_array($raw))throw new Problem('product_download_channel_invalid');
        $id=isset($raw['id'])&&$raw['id']!==''?self::id($raw['id']):null;
        $name=$this->string($raw,'channel_name',100,true);
        $provider=$this->string($raw,'provider',80,false);
        $region=$this->string($raw,'region_code',16,true);
        if($region!=='*'&&!preg_match('/^[A-Z]{2}(?:-[A-Z0-9]{1,3})?$/D',$region))throw new Problem('product_download_region_invalid');
        $url=$this->string($raw,'download_url',2048,true);
        if(!SiteContent::httpsUrl($url))throw new Problem('product_download_url_invalid');
        $passcode=$this->string($raw,'passcode',256,false);
        $clear=$raw['clear_passcode']??false;
        $enabled=$raw['enabled']??true;
        $order=$raw['sort_order']??0;
        if(!is_bool($clear)||!is_bool($enabled)||!is_int($order)||$order<0||$order>100000)throw new Problem('product_download_channel_invalid');
        return ['id'=>$id,'channel_name'=>$name,'provider'=>$provider,'region_code'=>$region,
            'download_url'=>$url,'passcode'=>$passcode,'clear_passcode'=>$clear,'enabled'=>$enabled,'sort_order'=>$order];
    }

    private function assertAllowedChannels(array $files): void
    {
        $hosts=[];
        foreach($files as $file){
            foreach($file['channels'] as $channel){
                $host=$this->normalizeHost((string)parse_url($channel['download_url'],PHP_URL_HOST));
                $hosts[$host]=true;
            }
        }
        if($hosts===[])return;
        $query=$this->db->prepare('SELECT hostname FROM portal_product_download_hosts WHERE enabled=1 AND hostname IN ('.implode(',',array_fill(0,count($hosts),'?')).')');
        $query->execute(array_keys($hosts));
        $allowed=array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN),true);
        foreach($hosts as $host=>$_)if(!isset($allowed[$host]))throw new Problem('product_download_host_not_allowed',403);
    }

    private function releaseHasEnabledChannels(PDO $db,string $releaseId): bool
    {
        $query=$db->prepare('SELECT COUNT(*) FROM portal_product_release_files f WHERE f.release_id=? AND NOT EXISTS(
            SELECT 1 FROM portal_product_download_channels c JOIN portal_product_download_hosts h
              ON h.hostname=c.hostname
            WHERE c.file_id=f.id AND c.enabled=1 AND h.enabled=1)');
        $query->execute([$releaseId]);
        $unavailableFiles=(int)$query->fetchColumn();
        $count=$db->prepare('SELECT COUNT(*) FROM portal_product_release_files WHERE release_id=?');
        $count->execute([$releaseId]);
        return (int)$count->fetchColumn()>0&&$unavailableFiles===0;
    }

    private function auditSnapshot(PDO $db,string $releaseId): array
    {
        $q=$db->prepare('SELECT platform,version_code,version_name,release_notes,status FROM portal_product_releases WHERE id=?');
        $q->execute([$releaseId]);$release=$q->fetch();
        if(!$release)return [];
        $q=$db->prepare('SELECT f.architecture,f.file_format,f.file_name,f.byte_size,f.sha256,f.android_package_name,f.android_signing_sha256,
            c.channel_name,c.provider,c.region_code,c.sort_order,c.enabled,c.passcode_ciphertext IS NOT NULL passcode_configured,
            c.hostname
            FROM portal_product_release_files f LEFT JOIN portal_product_download_channels c ON c.file_id=f.id WHERE f.release_id=?
            ORDER BY f.architecture,f.file_format,c.sort_order,c.id');
        $q->execute([$releaseId]);
        $release['version_code']=(int)$release['version_code'];$release['files']=$q->fetchAll();
        return $release;
    }

    private function assertPublicHost(string $host): void
    {
        if(filter_var($host,FILTER_VALIDATE_IP)){
            $addresses=[$host];
        }else{
            $addresses=[];
            foreach(gethostbynamel($host)?:[] as $address)$addresses[]=$address;
            foreach(dns_get_record($host,DNS_AAAA)?:[] as $record)if(is_string($record['ipv6']??null))$addresses[]=$record['ipv6'];
        }
        $addresses=array_values(array_unique($addresses));
        if($addresses===[])throw new Problem('product_download_host_unresolvable');
        foreach($addresses as $address){
            if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false){
                throw new Problem('product_download_host_not_public',400);
            }
        }
    }

    private function normalizeHost(string $host): string
    {
        $host=strtolower(rtrim(trim($host),'.'));
        if($host===''||strlen($host)>253||str_contains($host,'://')||str_contains($host,'/')||str_contains($host,'@'))throw new Problem('product_download_host_invalid');
        if(function_exists('idn_to_ascii')&&!filter_var($host,FILTER_VALIDATE_IP))$host=idn_to_ascii($host,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46)?:'';
        if($host===''||strlen($host)>253)throw new Problem('product_download_host_invalid');
        if(!filter_var($host,FILTER_VALIDATE_IP)){
            if(!str_contains($host,'.')||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)){
                throw new Problem('product_download_host_invalid');
            }
        }
        return $host;
    }

    private function assertProduct(PDO $db,string $product,bool $lock=false): void
    {
        $query=$db->prepare('SELECT code FROM portal_products WHERE code=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));
        $query->execute([$product]);if($query->fetchColumn()===false)throw new Problem('product_not_found',404);
    }

    private static function id(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))throw new Problem('identifier_invalid');
        return $value;
    }

    private function string(array $input,string $key,int $maximum,bool $required): string
    {
        $value=$input[$key]??'';
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$maximum||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new Problem('field_invalid');
        $value=trim($value);if($required&&$value==='')throw new Problem('field_required');return $value;
    }

    private function integer(mixed $value,int $minimum,int $maximum,string $error): int
    {
        if(!is_int($value)||$value<$minimum||$value>$maximum)throw new Problem($error);
        return $value;
    }
}
