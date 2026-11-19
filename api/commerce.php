<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\Commerce;
use D2S\services\HttpRequest;
use D2S\services\WebSessions;
use D2S\services\WalletRecharges;

$db = Database::fromEnvironment();
$service = new Commerce($db);
$now = time();
$context = Audit::context($_SERVER);
$method = $_SERVER['REQUEST_METHOD'];
$write = $method === 'POST';
$admin = str_starts_with($path,'/api/v2/admin/');
$owner = HttpRequest::session($admin ? 'admin':'user',new WebSessions($db),$write,$now);
$input = $write ? HttpRequest::json():[];
$product = $write ? ($input['product'] ?? null) : ($_GET['product'] ?? null);
if ($product !== null) {
    if (!is_string($product)) { throw new Problem('product_code_invalid'); }
    D2S\services\ProductCatalog::code($product);
}
$cursor = null;
if ($method === 'GET' && in_array($path,['/api/v2/orders','/api/v2/wallet'],true)) {
    if (isset($_GET['cursor']) && !is_string($_GET['cursor'])) { throw new Problem('history_cursor_invalid'); }
    $cursor = $_GET['cursor'] ?? null;
}
try {
    if ($admin) {
        if (!$write) { throw new Problem('not_found',404); }
        if (!isset($input['enabled']) || !is_bool($input['enabled'])) { throw new Problem('boolean_required'); }
        if ($path === '/api/v2/admin/commerce/price') {
            HttpRequest::strings($input,['currency']);
            $service->savePrice($owner['id'],$input['currency'],$input['amount'] ?? null,$input['enabled'],$context,$now);
            $data = ['saved'=>true];

        } else {
            throw new Problem('not_found',404);
        }
    } elseif ($method === 'GET' && $path === '/api/v2/shop/catalog') {
        $data = $service->catalog($owner['id'],$product ?? 'desktop2stereo');
    } elseif ($write && $path === '/api/v2/shop/quote') {
        HttpRequest::strings($input,['currency']);
        $data = $service->quote($owner['id'],$input['currency'],$context,$now,$product ?? 'desktop2stereo');
    } elseif ($write && $path === '/api/v2/orders') {
        $configurationId=$input['payment_configuration_id']??null;
        if($configurationId!==null)D2S\services\Backoffice::hexId($configurationId);
        $service=new Commerce($db,null,null,$configurationId);
        HttpRequest::strings($input,['quote_id','payment_method','idempotency_key']);
        $data = $service->createOrder($owner['id'],$input['quote_id'],$input['payment_method'],$input['idempotency_key'],$context,$now,d2s_locale_code());
    } elseif ($method === 'GET' && $path === '/api/v2/wallet/recharge') {
        $recharges = new WalletRecharges($db);
        $data = $recharges->catalog() + ['pending_order'=>$recharges->pending($owner['id'])];
    } elseif ($write && $path === '/api/v2/wallet/recharge/quote') {
        $data = (new WalletRecharges($db))->quote($owner['id'],$input['amount'] ?? null,$context,$now);
    } elseif ($write && $path === '/api/v2/wallet/recharges') {
        HttpRequest::strings($input,['quote_id','payment_method','idempotency_key']);
        $data = (new WalletRecharges($db))->createOrder($owner['id'],$input['quote_id'],$input['payment_method'],$input['idempotency_key'],$context,$now,d2s_locale_code());
    } elseif ($method === 'GET' && $path === '/api/v2/orders') {
        $data = $service->orderHistory($owner['id'],$cursor,PHP_INT_MAX,$product);
    } elseif ($method === 'GET' && $path === '/api/v2/wallet') {
        $data = $service->wallet($owner['id'],$cursor);
    } elseif (preg_match('#^/api/v2/orders/([a-f0-9]{32})(/pay|/cancel|/checkout|/confirm)?$#D',$path,$parts)) {
        $id = $parts[1];
        if ($method === 'GET' && !isset($parts[2])) {
            $data = $service->order($owner['id'],$id);
        } elseif ($write && ($parts[2] ?? '') === '/checkout') {
            $ownedOrder = $service->order($owner['id'],$id);
            if(in_array($ownedOrder['payment_method'],['paypal','stripe'],true)){
                $data=(new D2S\services\ExternalPayments($db,D2S\services\SecretBox::fromEnvironment()))->open($owner['id'],$id,$now);
            }else $data = $ownedOrder['payment_method'] === 'wechat_native'
                ? (new D2S\services\WechatCheckout($db,D2S\services\SecretBox::fromEnvironment()))->open($owner['id'],$id,$context,$now)
                : $service->checkout($owner['id'],$id,$now);
        } elseif ($write && ($parts[2] ?? '') === '/confirm') {
            $data=(new D2S\services\ExternalPayments($db,D2S\services\SecretBox::fromEnvironment()))->confirm($owner['id'],$id,$now);
        } elseif ($write && ($parts[2] ?? '') === '/pay') {
            $data = $service->payWallet($owner['id'],$id,$context,$now);
        } elseif ($write && ($parts[2] ?? '') === '/cancel') {
            $data = $service->cancel($owner['id'],$id,$context,$now);
        } else { throw new Problem('not_found',404); }
    } else {
        throw new Problem('not_found',404);
    }
} catch (Problem $error) {
    // Record rejected purchase requests after their transaction has rolled back.
    if ($write && !$admin && $error->httpStatus !== 404) {
        Audit::user($db,$owner['id'],Audit::email($db,$owner['id']),'PURCHASE',
            $error->httpStatus === 403 ? 'BLOCKED':'FAILURE',$context,$now);
    }
    throw $error;
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
