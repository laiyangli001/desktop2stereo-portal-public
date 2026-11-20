<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ClientLicensing
{
    public function __construct(private PDO $db, private LicenseCredentials $credentials, private ?SecretBox $secrets = null)
    {
    }

    public function login(string $identifier, string $password, string $deviceHash, string $publicKey, array $context, int $now, string $product = 'desktop2stereo', string $platform = 'unknown', string $deviceType = 'unknown'): array
    {
        ProductCatalog::code($product);
        self::device($deviceHash);
        self::assertClientProfileTarget($platform,$deviceType);
        CoreKeyWrapper::deviceKey($publicKey);
        $context['device_fingerprint'] = $deviceHash;
        $result = Database::transaction($this->db,function (PDO $db) use ($identifier,$password,$deviceHash,$publicKey,$context,$now,$product,$platform,$deviceType): array {
            $ipAllowed = RateLimiter::take($db,'login:ip:user:' . ($context['source_ip'] ?? 'unknown'),30,600,$now);
            $user = $this->findUser($identifier);
            $id = $user === null ? null : (int)$user['id'];
            $email = $id === null ? null : Audit::email($db,$id);
            $identity = $id === null ? 'unknown:' . strtolower(substr($identifier,0,254)) : 'id:' . $id;
            $bucket = 'login:account:user:' . $identity;
            $allowed = RateLimiter::take($db,$bucket,5,900,$now);
            $error = null;
            if (!$ipAllowed || !$allowed) {
                $error = ['error'=>'login_temporarily_locked','status'=>429];
            } elseif ($user === null || strlen($password)>64 || !password_verify($password,$user['password_hash'])) {
                if ($user === null) {
                    password_verify($password,password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT));
                }
                $error = ['error'=>'invalid_credentials','status'=>401];
            } elseif (!$this->userAllowed($user)) {
                $error = ['error'=>'account_restricted_or_unverified','status'=>403];
            }
            if ($error !== null) {
                Audit::user($db,$id,$email,'LOGIN',$error['status']===401 ? 'FAILURE':'BLOCKED',$context,$now);
                return $error;
            }
            $select = $db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');
            $select->execute([$id,$product]);
            $license = $this->resolveProductLicense($db,$id,$product,$select->fetch()?:null,$now);
            if($license===null){
                Audit::user($db,$id,$email,'LOGIN','BLOCKED',$context,$now);
                return ['error'=>'license_unavailable','status'=>403];
            }
            $unavailable = $this->licenseError($license,$now);
            if ($unavailable !== null) {
                Audit::user($db,$id,$email,'LOGIN','BLOCKED',$context,$now);
                return $unavailable;
            }
            $version = (int)$license['session_version']+1;
            // Signing and resource wrapping happen before any commit; missing secrets cannot start a trial.
            $this->credentials->registerSigningKey($db,$now);
            $bundle = $this->credentials->issue($license,$deviceHash,$publicKey,$now,$version,
                (new PlatformSettings($db))->heartbeatConfiguration());
            $db->prepare('INSERT INTO portal_devices (user_id,device_hash,public_key,first_seen_at,last_seen_at)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE public_key=?,last_seen_at=?')
                ->execute([$id,$deviceHash,$publicKey,$now,$now,$publicKey,$now]);
            $token = bin2hex(random_bytes(32));
            $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=?')->execute([$id,$product]);
            $db->prepare('INSERT INTO portal_client_sessions
                (user_id,product,public_key,token_hash,device_hash,user_version,session_version,created_at,verified_at,expires_at)
                VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$product,$publicKey,hash('sha256',$token),$deviceHash,
                    $user['session_version'],$version,$now,$now,$bundle['offline_expires_at']]);
            $db->prepare('UPDATE portal_licenses SET session_version=?,updated_at=? WHERE id=?')
                ->execute([$version,$now,$license['id']]);
            $this->record($id,$license,$deviceHash,$bundle,$now);
            $this->touchClientLogin($db,$id,$product,$platform,$now);
            $clientState=$this->clientState($db,$id,$product,$platform,$deviceType);
            RateLimiter::clear($db,$bucket);
            Audit::user($db,$id,$email,'LOGIN','SUCCESS',$context,$now);
            return $bundle + ['access_token'=>$token,'token_type'=>'Bearer','session_version'=>$version,
                'user_id'=>$id,'license'=>$this->view($license),'client_state'=>$clientState];
        });
        return self::result($result);
    }

    private function resolveProductLicense(PDO $db,int $userId,string $product,?array $license,int $now,bool $lockOperations=true): ?array
    {
        $query=$db->prepare('SELECT beta_starts_at,beta_ends_at,post_beta_mode,trial_enabled,trial_days,revision
            FROM portal_product_operations WHERE product_code=?'.($lockOperations?' FOR UPDATE':''));
        $query->execute([$product]);$rawOperations=$query->fetch();
        $configured=$rawOperations!==false;
        $operations=$rawOperations?:[
            'beta_starts_at'=>null,'beta_ends_at'=>null,'post_beta_mode'=>'paid','trial_enabled'=>0,'trial_days'=>30,'revision'=>1,
        ];
        $betaStart=$operations['beta_starts_at']===null?null:(int)$operations['beta_starts_at'];
        $betaEnd=$operations['beta_ends_at']===null?null:(int)$operations['beta_ends_at'];
        $revision=(int)$operations['revision'];
        $member=null;
        if($configured&&$betaStart!==null&&$betaEnd!==null&&$now>=$betaStart&&$now<$betaEnd){
            $db->prepare('INSERT IGNORE INTO portal_product_beta_members
                (user_id,product_code,beta_revision,enrolled_at,beta_ends_at,trial_enabled_snapshot,trial_days_snapshot,post_beta_mode_snapshot)
                VALUES(?,?,?,?,?,?,?,?)')->execute([$userId,$product,$revision,$now,$betaEnd,(int)$operations['trial_enabled'],
                    (int)$operations['trial_days'],$operations['post_beta_mode']]);
        }
        if($configured){
            $memberQuery=$db->prepare('SELECT beta_revision,enrolled_at,beta_ends_at,trial_enabled_snapshot,
                trial_days_snapshot,post_beta_mode_snapshot FROM portal_product_beta_members
                WHERE user_id=? AND product_code=? AND beta_revision=?');
            $memberQuery->execute([$userId,$product,$revision]);$member=$memberQuery->fetch()?:null;
        }

        if($license!==null&&$license['status']==='active'&&$member!==null&&$betaStart!==null&&$betaEnd!==null&&$now>=$betaEnd&&
            $license['kind']==='trial'&&(int)$license['trial_started_at']>=$betaStart&&
            (int)$license['trial_started_at']<$betaEnd&&(int)$license['expires_at']<=$betaEnd){
            $mode=$member['post_beta_mode_snapshot'];
            $trialEnabled=(bool)$member['trial_enabled_snapshot'];
            $trialEnd=$betaEnd+(int)$member['trial_days_snapshot']*86400;
            if($mode==='free'){
                $db->prepare("UPDATE portal_licenses SET kind='formal',trial_started_at=?,expires_at=NULL,updated_at=? WHERE id=? AND status='active'")
                    ->execute([$betaEnd,$now,$license['id']]);
                $license['kind']='formal';$license['trial_started_at']=$betaEnd;$license['expires_at']=null;
            }elseif($trialEnabled&&$trialEnd>$now){
                $db->prepare("UPDATE portal_licenses SET trial_started_at=?,expires_at=?,updated_at=? WHERE id=? AND status='active'")
                    ->execute([$betaEnd,$trialEnd,$now,$license['id']]);
                $license['trial_started_at']=$betaEnd;$license['expires_at']=$trialEnd;
            }
        }elseif($license!==null&&$license['status']==='active'&&$betaStart!==null&&$betaEnd!==null&&$now>=$betaStart&&$now<$betaEnd&&
            $license['kind']==='trial'&&(int)$license['expires_at']<=$now){
            $db->prepare("UPDATE portal_licenses SET trial_started_at=?,expires_at=?,updated_at=? WHERE id=? AND status='active'")
                ->execute([$now,$betaEnd,$now,$license['id']]);
            $license['trial_started_at']=$now;$license['expires_at']=$betaEnd;
        }

        if($license!==null)return $license;
        $kind=null;$trialStart=$now;$expiresAt=null;
        $betaActive=$betaStart!==null&&$betaEnd!==null&&$now>=$betaStart&&$now<$betaEnd;
        if($betaActive){
            $kind='trial';$expiresAt=$betaEnd;
        }elseif($member!==null&&$betaEnd!==null&&$now>=$betaEnd){
            if($member['post_beta_mode_snapshot']==='free')$kind='formal';
            elseif((bool)$member['trial_enabled_snapshot']){
                $kind='trial';$trialStart=$betaEnd;
                $expiresAt=$betaEnd+(int)$member['trial_days_snapshot']*86400;
            }
        }elseif($configured&&$operations['post_beta_mode']==='free'&&
            ($betaEnd===null||$now>=$betaEnd)){
            $kind='formal';
        }elseif(!$configured&&$product==='desktop2stereo'){
            $kind='trial';$expiresAt=$now+30*86400;
        }
        if($kind===null||($kind==='trial'&&($expiresAt===null||$expiresAt<=$now)))return null;
        $productQuery=$db->prepare('SELECT code FROM portal_products WHERE code=? AND enabled=1 AND deleted_at IS NULL');
        $productQuery->execute([$product]);
        if($productQuery->fetchColumn()===false)throw new Problem('product_not_found',404);
        $license=['id'=>bin2hex(random_bytes(16)),'user_id'=>$userId,'product'=>$product,'kind'=>$kind,'status'=>'active',
            'trial_started_at'=>$trialStart,'expires_at'=>$expiresAt,'session_version'=>0];
        $db->prepare('INSERT INTO portal_licenses
            (id,user_id,product,kind,status,trial_started_at,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$license['id'],$userId,$product,$kind,'active',$trialStart,$expiresAt,$now,$now]);
        return $license;
    }

    private static function assertClientProfileTarget(string $platform,string $deviceType): void
    {
        if(!in_array($platform,['windows','macos','linux','android','unknown'],true)||
            !in_array($deviceType,['desktop','headset','phone','tablet','unknown'],true)){
            throw new Problem('client_platform_invalid');
        }
    }

    private function touchClientLogin(PDO $db,int $userId,string $product,string $platform,int $now): void
    {
        $db->prepare('INSERT INTO portal_product_user_activity
            (user_id,product_code,platform,last_client_login_at,revision,updated_at)
            VALUES(?,?,?,?,1,?) ON DUPLICATE KEY UPDATE
                last_client_login_at=VALUES(last_client_login_at),updated_at=VALUES(updated_at)')
            ->execute([$userId,$product,$platform,$now,$now]);
    }

    private function clientState(PDO $db,int $userId,string $product,string $platform,string $deviceType): array
    {
        $activity=$db->prepare('SELECT last_client_login_at,last_version_reported_at,version_code,version_name,architecture,revision
            FROM portal_product_user_activity WHERE user_id=? AND product_code=? AND platform=?');
        $activity->execute([$userId,$product,$platform]);
        $version=$activity->fetch()?:[
            'last_client_login_at'=>null,'last_version_reported_at'=>null,'version_code'=>null,
            'version_name'=>null,'architecture'=>null,'revision'=>1,
        ];
        foreach(['last_client_login_at','last_version_reported_at','version_code','revision'] as $key){
            $version[$key]=$version[$key]===null?null:(int)$version[$key];
        }
        $environmentQuery=$db->prepare('SELECT os_name,os_version,os_build,gpu_model,android_api_level,device_model,reported_at,revision
            FROM portal_product_user_environment WHERE user_id=? AND product_code=? AND platform=? AND device_type=?');
        $environmentQuery->execute([$userId,$product,$platform,$deviceType]);
        $environment=$environmentQuery->fetch()?:[
            'os_name'=>null,'os_version'=>null,'os_build'=>null,'gpu_model'=>null,
            'android_api_level'=>null,'device_model'=>null,'reported_at'=>null,'revision'=>1,
        ];
        foreach(['android_api_level','reported_at','revision'] as $key){
            $environment[$key]=$environment[$key]===null?null:(int)$environment[$key];
        }
        return ['version'=>$version,'environment'=>$environment];
    }

    public function bootstrap(string $token,string $deviceHash,string $expectedProduct,string $platform,string $architecture,int $now,string $deviceType='unknown'): array
    {
        self::device($deviceHash);
        $expectedProduct=ProductCatalog::code($expectedProduct);
        self::assertUpdateTarget($platform,$architecture);
        self::assertClientProfileTarget($platform,$deviceType);
        return Database::transaction($this->db,function(PDO $db)use($token,$deviceHash,$expectedProduct,$platform,$architecture,$now,$deviceType):array{
            [$user,$session,$license]=$this->session($token,$deviceHash,$now);
            if($session['product']!==$expectedProduct)throw new Problem('client_product_mismatch',403);
            $release=$db->prepare("SELECT r.version_code,r.version_name,r.release_notes,r.published_at
                FROM portal_product_releases r
                WHERE r.product_code=? AND r.platform=? AND r.status='published'
                    AND EXISTS(SELECT 1 FROM portal_product_release_files f WHERE f.release_id=r.id AND f.architecture=?)
                ORDER BY r.version_code DESC LIMIT 1");
            $release->execute([$expectedProduct,$platform,$architecture]);
            $latest=$release->fetch()?:null;
            if($latest!==null){
                $latest['version_code']=(int)$latest['version_code'];
                $latest['published_at']=$latest['published_at']===null?null:(int)$latest['published_at'];
            }
            $policy=$db->prepare('SELECT minimum_supported_version FROM portal_product_update_policies WHERE product_code=? AND platform=?');
            $policy->execute([$expectedProduct,$platform]);
            $minimum=$policy->fetchColumn();
            $clientState=$this->clientState($db,(int)$user['id'],$expectedProduct,$platform,$deviceType);
            return ['product_code'=>$expectedProduct,'platform'=>$platform,'architecture'=>$architecture,
                'license'=>$this->view($license),'client_state'=>$clientState,'update'=>['latest'=>$latest,
                    'minimum_supported_version'=>$minimum===false?0:(int)$minimum]];
        });
    }

    public function syncEnvironment(string $token,string $deviceHash,string $platform,string $deviceType,array $input,int $now): array
    {
        self::device($deviceHash);
        self::assertClientProfileTarget($platform,$deviceType);
        $version=$input['version']??null;
        $environment=$input['environment']??null;
        if($version!==null){
            if(!is_array($version)||!is_int($version['revision']??null)||$version['revision']<1||
                !is_int($version['version_code']??null)||$version['version_code']<1||$version['version_code']>2147483647||
                !is_string($version['version_name']??null)||!is_string($version['architecture']??null)||
                !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$version['architecture'])){
                throw new Problem('client_version_report_invalid');
            }
            $versionName=trim($version['version_name']);
            if($versionName===''||!mb_check_encoding($versionName,'UTF-8')||mb_strlen($versionName,'UTF-8')>64||
                preg_match('/[\x00-\x1F\x7F]/',$versionName))throw new Problem('client_version_report_invalid');
            $version=['revision'=>$version['revision'],'version_code'=>$version['version_code'],
                'version_name'=>$versionName,'architecture'=>$version['architecture']];
        }
        $environmentFields=['os_name'=>64,'os_version'=>64,'os_build'=>64,'gpu_model'=>160,'device_model'=>160];
        if($environment!==null){
            if(!is_array($environment)||!is_int($environment['revision']??null)||$environment['revision']<1){
                throw new Problem('client_environment_report_invalid');
            }
            $normalized=['revision'=>$environment['revision']];
            foreach($environment as $key=>$value){
                if($key==='revision')continue;
                if($key==='android_api_level'){
                    if($value!==null&&(!is_int($value)||$value<1||$value>65535))throw new Problem('client_environment_report_invalid');
                    $normalized[$key]=$value;
                    continue;
                }
                if(!array_key_exists($key,$environmentFields)||($value!==null&&!is_string($value)))throw new Problem('client_environment_report_invalid');
                if($value===null){$normalized[$key]=null;continue;}
                $value=trim($value);
                if($value===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$environmentFields[$key]||
                    preg_match('/[\x00-\x1F\x7F]/',$value))throw new Problem('client_environment_report_invalid');
                $normalized[$key]=$value;
            }
            if(count($normalized)===1)throw new Problem('client_environment_report_invalid');
            $environment=$normalized;
        }
        if($version===null&&$environment===null)throw new Problem('client_environment_report_invalid');

        return Database::transaction($this->db,function(PDO $db)use($token,$deviceHash,$platform,$deviceType,$version,$environment,$now):array{
            [$user,$session]=$this->session($token,$deviceHash,$now);
            $userId=(int)$user['id'];$product=(string)$session['product'];$conflicts=[];
            if($version!==null){
                $query=$db->prepare('SELECT last_client_login_at,version_code,version_name,architecture,revision
                    FROM portal_product_user_activity WHERE user_id=? AND product_code=? AND platform=? FOR UPDATE');
                $query->execute([$userId,$product,$platform]);$current=$query->fetch();
                $actual=$current===false?1:(int)$current['revision'];
                if($version['revision']!==$actual){
                    $conflicts[]='version';
                }else{
                    $same=$current!==false&&(int)$current['version_code']===$version['version_code']&&
                        $current['version_name']===$version['version_name']&&$current['architecture']===$version['architecture'];
                    if(!$same){
                        $next=$actual+1;
                        $db->prepare('INSERT INTO portal_product_user_activity
                            (user_id,product_code,platform,last_version_reported_at,version_code,version_name,architecture,revision,updated_at)
                            VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
                                last_version_reported_at=VALUES(last_version_reported_at),version_code=VALUES(version_code),
                                version_name=VALUES(version_name),architecture=VALUES(architecture),revision=VALUES(revision),updated_at=VALUES(updated_at)')
                            ->execute([$userId,$product,$platform,$now,$version['version_code'],$version['version_name'],$version['architecture'],$next,$now]);
                    }
                }
            }
            if($environment!==null){
                $query=$db->prepare('SELECT os_name,os_version,os_build,gpu_model,android_api_level,device_model,revision
                    FROM portal_product_user_environment WHERE user_id=? AND product_code=? AND platform=? AND device_type=? FOR UPDATE');
                $query->execute([$userId,$product,$platform,$deviceType]);$current=$query->fetch();
                $actual=$current===false?1:(int)$current['revision'];
                if($environment['revision']!==$actual){
                    $conflicts[]='environment';
                }else{
                    $columns=['os_name','os_version','os_build','gpu_model','android_api_level','device_model'];
                    $values=[];
                    foreach($columns as $column)$values[$column]=$environment[$column]??($current[$column]??null);
                    $same=$current!==false;
                    foreach($columns as $column)if(($current[$column]??null)!==$values[$column])$same=false;
                    if(!$same){
                        $next=$actual+1;
                        $db->prepare('INSERT INTO portal_product_user_environment
                            (user_id,product_code,platform,device_type,os_name,os_version,os_build,gpu_model,android_api_level,device_model,reported_at,revision)
                            VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE os_name=VALUES(os_name),
                                os_version=VALUES(os_version),os_build=VALUES(os_build),gpu_model=VALUES(gpu_model),
                                android_api_level=VALUES(android_api_level),device_model=VALUES(device_model),
                                reported_at=VALUES(reported_at),revision=VALUES(revision)')
                            ->execute([$userId,$product,$platform,$deviceType,$values['os_name'],$values['os_version'],$values['os_build'],
                                $values['gpu_model'],$values['android_api_level'],$values['device_model'],$now,$next]);
                    }
                }
            }
            return ['conflicts'=>$conflicts,'client_state'=>$this->clientState($db,$userId,$product,$platform,$deviceType)];
        });
    }

    public function updateDownloads(string $token,string $deviceHash,string $expectedProduct,string $platform,string $architecture,
        int $versionCode,?string $region,int $now): array
    {
        self::device($deviceHash);
        $expectedProduct=ProductCatalog::code($expectedProduct);
        self::assertUpdateTarget($platform,$architecture);
        if($versionCode<1)throw new Problem('product_release_version_invalid');
        if($region!==null&&!preg_match('/^(?:[A-Z]{2}(?:-[A-Z0-9]{1,3})?|\*)$/D',$region))throw new Problem('product_download_region_invalid');
        return Database::transaction($this->db,function(PDO $db)use($token,$deviceHash,$expectedProduct,$platform,$architecture,$versionCode,$region,$now):array{
            [$user,$session,$license]=$this->session($token,$deviceHash,$now);
            if($session['product']!==$expectedProduct)throw new Problem('client_product_mismatch',403);
            $release=$db->prepare("SELECT id,version_code,version_name FROM portal_product_releases
                WHERE product_code=? AND platform=? AND version_code=? AND status='published' FOR UPDATE");
            $release->execute([$expectedProduct,$platform,$versionCode]);
            $row=$release->fetch();
            if($row===false)throw new Problem('release_changed',409);
            $minimumQuery=$db->prepare('SELECT minimum_supported_version FROM portal_product_update_policies WHERE product_code=? AND platform=?');
            $minimumQuery->execute([$expectedProduct,$platform]);
            $minimum=(int)($minimumQuery->fetchColumn()?:0);
            if($minimum>0&&$versionCode<$minimum)throw new Problem('product_update_below_minimum',409);
            $files=$db->prepare('SELECT id,architecture,file_format,file_name,byte_size,sha256,android_package_name,android_signing_sha256
                FROM portal_product_release_files WHERE release_id=? AND architecture=? ORDER BY file_format');
            $files->execute([$row['id'],$architecture]);
            $items=$files->fetchAll();
            if($items===[])throw new Problem('release_changed',409);
            $channels=$db->prepare("SELECT c.id,c.channel_name,c.provider,c.region_code,c.download_url,c.passcode_ciphertext,
                    c.sort_order
                FROM portal_product_download_channels c JOIN portal_product_download_hosts h
                    ON h.hostname=c.hostname AND h.enabled=1
                WHERE c.file_id=? AND c.enabled=1 AND (? IS NULL OR c.region_code IN (?, '*'))
                ORDER BY CASE WHEN c.region_code=? THEN 0 ELSE 1 END,c.sort_order,c.id");
            foreach($items as &$file){
                $file['byte_size']=(int)$file['byte_size'];
                $channels->execute([$file['id'],$region,$region,$region]);
                $file['channels']=[];
                foreach($channels->fetchAll() as $channel){
                    $passcode=null;
                    if($channel['passcode_ciphertext']!==null){
                        if($this->secrets===null)throw new Problem('secret_configuration_unavailable',503);
                        $passcode=$this->secrets->decrypt($channel['passcode_ciphertext'],'product-download-channel:'.$channel['id']);
                    }
                    $file['channels'][]=['channel_name'=>$channel['channel_name'],'provider'=>$channel['provider'],
                        'region_code'=>$channel['region_code'],'download_url'=>$channel['download_url'],
                        'passcode'=>$passcode,'sort_order'=>(int)$channel['sort_order']];
                }
                unset($file['id']);
            }
            unset($file);
            foreach($items as $file)if($file['channels']===[])throw new Problem('product_download_channel_unavailable',503);
            return ['product_code'=>$expectedProduct,'platform'=>$platform,'version_code'=>(int)$row['version_code'],
                'version_name'=>$row['version_name'],'files'=>$items,'license'=>$this->view($license)];
        });
    }

    private static function assertUpdateTarget(string $platform,string $architecture): void
    {
        if(!in_array($platform,['windows','macos','linux','android'],true)||
            !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$architecture))throw new Problem('client_platform_invalid');
    }

    public function heartbeat(string $token, string $deviceHash, int $now): array
    {
        self::device($deviceHash);
        return Database::transaction($this->db,function (PDO $db) use ($token,$deviceHash,$now): array {
            [$user,$session,$license] = $this->session($token,$deviceHash,$now);
            $publicKey = $session['public_key'];
            $this->credentials->registerSigningKey($db,$now);
            $bundle = $this->credentials->issue($license,$deviceHash,$publicKey,$now,(int)$session['session_version'],
                (new PlatformSettings($db))->heartbeatConfiguration());
            $db->prepare('UPDATE portal_client_sessions SET verified_at=?,expires_at=? WHERE user_id=? AND product=?')
                ->execute([$now,$bundle['offline_expires_at'],$user['id'],$license['product']]);
            $db->prepare('UPDATE portal_devices SET last_seen_at=? WHERE user_id=? AND device_hash=?')
                ->execute([$now,$user['id'],$deviceHash]);
            $this->record((int)$user['id'],$license,$deviceHash,$bundle,$now);
            return $bundle + ['session_version'=>(int)$session['session_version'],'license'=>$this->view($license)];
        });
    }

    public function logout(string $token, string $deviceHash, array $context, int $now): void
    {
        self::device($deviceHash);
        Database::transaction($this->db,function (PDO $db) use ($token,$deviceHash,$context,$now): void {
            [$user,$session] = $this->session($token,$deviceHash,$now);
            $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=? AND product=?')->execute([$user['id'],$session['product']]);
            $context['device_fingerprint'] = $deviceHash;
            Audit::user($db,(int)$user['id'],Audit::email($db,(int)$user['id']),'LOGOUT','SUCCESS',$context,$now);
        });
    }

    public function forUser(int $userId, string $product = 'desktop2stereo'): ?array
    {
        $select = $this->db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=?');
        $select->execute([$userId,ProductCatalog::code($product)]);
        $row = $select->fetch();
        return $row === false ? null : $this->view($row);
    }

    public function devices(int $userId, string $product = 'desktop2stereo'): array
    {
        return (new LicenseOverview($this->db))->devices($userId,$product);
    }

    private function session(string $token, string $deviceHash, int $now): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) {
            throw new Problem('client_session_invalid',401);
        }
        $hash = hash('sha256',$token);
        // Resolve first, then always lock user before license/session, matching password reset and login.
        $select = $this->db->prepare('SELECT user_id,product FROM portal_client_sessions WHERE token_hash=?');
        $select->execute([$hash]);
        $resolved = $select->fetch();
        if ($resolved === false) { throw new Problem('client_session_replaced',401); }
        $id = $resolved['user_id'];
        $product = $resolved['product'];
        $select = $this->db->prepare('SELECT * FROM portal_users WHERE id=? FOR UPDATE');
        $select->execute([$id]);
        $user = $select->fetch();
        $select = $this->db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');
        $select->execute([$id,$product]);
        $license = $select->fetch();
        if($license!==false)$license=$this->resolveProductLicense($this->db,(int)$id,(string)$product,$license,$now,false);
        $select = $this->db->prepare('SELECT * FROM portal_client_sessions WHERE user_id=? AND product=? FOR UPDATE');
        $select->execute([$id,$product]);
        $session = $select->fetch();
        if ($session === false || !hash_equals($session['token_hash'],$hash) ||
            $session['device_hash'] !== $deviceHash || $user === false ||
            (int)$session['user_version'] !== (int)$user['session_version'] ||
            $license === false || (int)$session['session_version'] !== (int)$license['session_version']) {
            throw new Problem('client_session_replaced',401);
        }
        if (!$this->userAllowed($user)) { throw new Problem('account_restricted_or_unverified',403); }
        if ((int)$session['expires_at'] <= $now || $now < (int)$session['verified_at']) {
            throw new Problem('client_session_expired',401);
        }
        self::result($this->licenseError($license,$now) ?? []);
        return [$user,$session,$license];
    }

    private function findUser(string $identifier): ?array
    {
        try { [$kind,$value] = AccountInput::identifier($identifier); }
        catch (Problem) { return null; }
        if ($kind === 'username') {
            $select = $this->db->prepare('SELECT * FROM portal_users WHERE username=? FOR UPDATE');
            $select->execute([$value]);
        } else {
            $select = $this->db->prepare('SELECT u.* FROM portal_users u JOIN portal_contacts c ON c.user_id=u.id
                WHERE c.kind=? AND c.value=? AND c.verified_at IS NOT NULL FOR UPDATE');
            $select->execute([$kind,$value]);
        }
        return $select->fetch() ?: null;
    }

    private function userAllowed(array $user): bool
    {
        $verified = $this->db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');
        $verified->execute([$user['id']]);
        $control = $this->db->prepare('SELECT login_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');
        $control->execute([$user['id']]);
        return $user['status'] === 'active' && (int)$verified->fetchColumn()>0 && (int)$control->fetchColumn() !== 1;
    }

    private function licenseError(array $license, int $now): ?array
    {
        if ($license['status'] !== 'active') { return ['error'=>'license_disabled','status'=>403]; }
        if ($license['expires_at'] !== null && (int)$license['expires_at'] <= $now) {
            return ['error'=>'license_expired','status'=>403];
        }
        if ($now < (int)$license['trial_started_at']) { return ['error'=>'server_time_invalid','status'=>503]; }
        return null;
    }

    private function record(int $userId, array $license, string $device, array $bundle, int $now): void
    {
        $this->db->prepare('INSERT INTO portal_license_credentials
            (id,user_id,license_id,device_hash,key_id,token_hash,issued_at,expires_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$bundle['offline_id'],$userId,$license['id'],$device,$bundle['key_id'],
                hash('sha256',$bundle['offline_token']),$now,$bundle['offline_expires_at']]);
    }

    private function view(array $license): array
    {
        return ['id'=>$license['id'],'product'=>$license['product'],'kind'=>$license['kind'],'status'=>$license['status'],
            'trial_started_at'=>$license['trial_started_at'] === null ? null : (int)$license['trial_started_at'],
            'expires_at'=>$license['expires_at'] === null ? null : (int)$license['expires_at']];
    }

    private static function device(string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new Problem('device_hash_invalid'); }
    }

    private static function result(array $result): array
    {
        if (isset($result['error'])) { throw new Problem($result['error'],$result['status']); }
        return $result;
    }
}
