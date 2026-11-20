<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\ClientLicensing;
use D2S\services\CoreKeyWrapper;
use D2S\services\HttpRequest;
use D2S\services\LicenseCredentials;
use D2S\services\LicenseOverview;
use D2S\services\LicenseSigner;
use D2S\services\SecretBox;
use D2S\services\WebSessions;
use D2S\services\SigningKeys;

$method = $_SERVER['REQUEST_METHOD'];
$clientBootstrapRoute=preg_match('#^/api/v1/products/([a-z][a-z0-9_-]{2,31})/bootstrap$#D',$path,$bootstrapMatch)===1;
$clientDownloadsRoute=preg_match('#^/api/v1/products/([a-z][a-z0-9_-]{2,31})/update-downloads$#D',$path,$downloadsMatch)===1;
$clientEnvironmentRoute=$path==='/api/v2/client/environment';
if($clientBootstrapRoute||$clientDownloadsRoute||$clientEnvironmentRoute)header('Cache-Control: no-store, private, max-age=0');
$methods = ['/api/v2/license/keys'=>'GET','/api/v2/me/license'=>'GET','/api/v2/me/license/devices'=>'GET','/api/v2/me/software-downloads'=>'GET','/api/v2/me/license/trial/claim'=>'POST','/api/v2/me/license/session/logout'=>'POST',
    '/api/v2/client/login'=>'POST','/api/v2/client/heartbeat'=>'POST','/api/v2/client/logout'=>'POST',
    '/api/v2/client/environment'=>'POST','/api/v2/admin/license-keys'=>'GET','/api/v2/admin/license-keys/activate'=>'POST',
    '/api/v2/admin/license-keys/retire'=>'POST'];
if (!isset($methods[$path])&&!$clientBootstrapRoute&&!$clientDownloadsRoute) { throw new Problem('not_found',404); }
$expectedMethod=$clientBootstrapRoute||$clientDownloadsRoute?'POST':$methods[$path];
if ($expectedMethod !== $method) { throw new Problem('method_not_allowed',405); }
if (str_starts_with($path,'/api/v2/admin/license-keys')) {
    $db = Database::fromEnvironment();
    $owner = HttpRequest::session('admin',new WebSessions($db),$method !== 'GET',time());
    $keys = new SigningKeys($db,LicenseSigner::fromEnvironment());
    $context = Audit::context($_SERVER);
    if ($method === 'GET') {
        $data = $keys->view($owner['id'],$context,time());
    } elseif ($path === '/api/v2/admin/license-keys/activate') {
        $input = HttpRequest::strings(HttpRequest::json(),['confirm_key_id','confirm_fingerprint']);
        $keys->activate($owner['id'],$input['confirm_key_id'],$input['confirm_fingerprint'],$context,time());
        $data = ['activated'=>true];
    } else {
        $input = HttpRequest::strings(HttpRequest::json(),['key_id']);
        $keys->retire($owner['id'],$input['key_id'],$context,time());
        $data = ['retired'=>true];
    }
} elseif ($method === 'GET' && $path === '/api/v2/license/keys') {
    $data = ['keys'=>(new SigningKeys(Database::fromEnvironment(),LicenseSigner::fromEnvironment()))->publicKeys()];
} else {
    $db = Database::fromEnvironment();
    $now = time();
    $productInput = static function (mixed $value): string {
        if (!is_string($value)) { throw new Problem('product_code_invalid'); }
        return D2S\services\ProductCatalog::code($value);
    };
    $clientService = static fn(string $product): ClientLicensing => new ClientLicensing($db,
        new LicenseCredentials(LicenseSigner::fromEnvironment(),CoreKeyWrapper::fromEnvironment($product),$product));
    if ($clientBootstrapRoute || $clientDownloadsRoute) {
        $raw=HttpRequest::json();
        $input=HttpRequest::strings($raw,['device_hash','platform','architecture']);
        $deviceType=$raw['device_type']??'unknown';
        if(!is_string($deviceType))throw new Problem('client_platform_invalid');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$_SERVER['HTTP_AUTHORIZATION'] ?? '',$matches)) {
            throw new Problem('client_session_invalid',401);
        }
        header('Cache-Control: no-store, private, max-age=0');
        $product=$productInput($clientBootstrapRoute?$bootstrapMatch[1]:$downloadsMatch[1]);
        $service=$clientService($product);
        if($clientBootstrapRoute){
            $data=$service->bootstrap($matches[1],$input['device_hash'],$product,$input['platform'],$input['architecture'],$now,$deviceType);
        }else{
            $versionCode=$raw['version_code']??null;
            if(!is_int($versionCode))throw new Problem('product_release_version_invalid');
            $region=$raw['region_code']??null;
            if($region!==null&&!is_string($region))throw new Problem('product_download_region_invalid');
            $downloadService=new ClientLicensing($db,
                new LicenseCredentials(LicenseSigner::fromEnvironment(),CoreKeyWrapper::fromEnvironment($product),$product),
                SecretBox::fromEnvironment());
            $data=$downloadService->updateDownloads($matches[1],$input['device_hash'],$product,$input['platform'],$input['architecture'],
                $versionCode,$region===null?null:strtoupper($region),$now);
        }
    } elseif ($method === 'GET' && $path === '/api/v2/me/license') {
        $owner = HttpRequest::session('user',new WebSessions($db),false,$now);
        $overview = new LicenseOverview($db);
        $data = ['license'=>$overview->license($owner['id'],$productInput($_GET['product'] ?? 'desktop2stereo')),
            'licenses'=>$overview->all($owner['id'],d2s_locale_code())];
    } elseif ($method === 'GET' && $path === '/api/v2/me/software-downloads') {
        $owner = HttpRequest::session('user',new WebSessions($db),false,$now);
        header('Cache-Control: no-store, private, max-age=0');
        $data = (new LicenseOverview($db))->softwareDownloads($owner['id'],d2s_locale_code(),$now,SecretBox::fromEnvironment());
    } elseif ($method === 'GET' && $path === '/api/v2/me/license/devices') {
        $owner = HttpRequest::session('user',new WebSessions($db),false,$now);
        header('Cache-Control: no-store, private, max-age=0');
        $data = (new LicenseOverview($db))->devices($owner['id'],$productInput($_GET['product'] ?? 'desktop2stereo'),d2s_locale_code(),$now);
    } elseif ($method === 'POST' && $path === '/api/v2/me/license/trial/claim') {
        $owner = HttpRequest::session('user',new WebSessions($db),true,$now);
        $input = HttpRequest::json();
        $product = $productInput($input['product'] ?? 'desktop2stereo');
        header('Cache-Control: no-store, private, max-age=0');
        $data = (new LicenseOverview($db))->claimTrial($owner['id'],$product,Audit::context($_SERVER),$now);
    } elseif ($method === 'POST' && $path === '/api/v2/me/license/session/logout') {
        $owner = HttpRequest::session('user',new WebSessions($db),true,$now);
        $input = HttpRequest::json();
        if (!is_string($input['device_hash'] ?? null) || !is_int($input['session_version'] ?? null)) {
            throw new Problem('client_session_invalid');
        }
        (new LicenseOverview($db))->logout($owner['id'],$input['device_hash'],$input['session_version'],Audit::context($_SERVER),$now,$productInput($input['product'] ?? 'desktop2stereo'));
        $data = ['logged_out'=>true];
    } elseif ($method === 'POST' && $path === '/api/v2/client/login') {
        $raw=HttpRequest::json();
        $input = HttpRequest::strings($raw,['identifier','password','device_hash','device_public_key']);
        $product = $productInput($input['product'] ?? 'desktop2stereo');
        $platform=$raw['platform']??'unknown';$deviceType=$raw['device_type']??'unknown';
        if(!is_string($platform)||!is_string($deviceType))throw new Problem('client_platform_invalid');
        $service = $clientService($product);
        $data = $service->login($input['identifier'],$input['password'],$input['device_hash'],$input['device_public_key'],
            Audit::context($_SERVER),$now,$product,$platform,$deviceType);
    } elseif ($method === 'POST' && $path === '/api/v2/client/environment') {
        $raw=HttpRequest::json(true);
        $input=HttpRequest::strings($raw,['device_hash','platform','device_type']);
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$_SERVER['HTTP_AUTHORIZATION'] ?? '',$matches)) {
            throw new Problem('client_session_invalid',401);
        }
        $query=$db->prepare('SELECT product FROM portal_client_sessions WHERE token_hash=?');
        $query->execute([hash('sha256',$matches[1])]);
        $product=$query->fetchColumn();
        if($product===false)throw new Problem('client_session_replaced',401);
        $data=$clientService($product)->syncEnvironment($matches[1],$input['device_hash'],$input['platform'],
            $input['device_type'],$raw,$now);
    } elseif ($method === 'POST' && in_array($path,['/api/v2/client/heartbeat','/api/v2/client/logout'],true)) {
        $input = HttpRequest::strings(HttpRequest::json(),['device_hash']);
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$_SERVER['HTTP_AUTHORIZATION'] ?? '',$matches)) {
            throw new Problem('client_session_invalid',401);
        }
        $query = $db->prepare('SELECT product FROM portal_client_sessions WHERE token_hash=?');
        $query->execute([hash('sha256',$matches[1])]);
        $product = $query->fetchColumn();
        if ($product === false) { throw new Problem('client_session_replaced',401); }
        $service = $clientService($product);
        if ($path === '/api/v2/client/heartbeat') {
            $data = $service->heartbeat($matches[1],$input['device_hash'],$now);
        } else {
            $service->logout($matches[1],$input['device_hash'],Audit::context($_SERVER),$now);
            $data = ['logged_out'=>true];
        }
    } else {
        throw new Problem('not_found',404);
    }
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
