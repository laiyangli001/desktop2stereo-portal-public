<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\AlipayPayments;
use D2S\services\Audit;
use D2S\services\PaymentProfiles;
use D2S\services\SecretBox;

header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/v1/alipay/app-gateway') {
    echo 'success';
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo 'failure'; return;
}
try {
    if (!empty($_SERVER['QUERY_STRING']) ||
        strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/x-www-form-urlencoded') {
        throw new Problem('alipay_notification_invalid');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>65536) { throw new Problem('request_too_large'); }
    $raw = file_get_contents('php://input',false,null,0,65537);
    if (!is_string($raw) || strlen($raw)>65536) { throw new Problem('request_too_large'); }
    $db = Database::fromEnvironment();
    (new AlipayPayments($db,new PaymentProfiles($db,SecretBox::fromEnvironment())))->notify($raw,Audit::context($_SERVER),time());
    echo 'success';
} catch (Problem $error) {
    http_response_code($error->httpStatus>=500 ? 503:400);
    echo 'failure';
} catch (Throwable) {
    http_response_code(503);
    echo 'failure';
}
