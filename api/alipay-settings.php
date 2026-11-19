<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\AlipaySettings;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\SecretBox;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$method = $_SERVER['REQUEST_METHOD'];
$now = time();
$owner = HttpRequest::session('admin',new WebSessions($db),$method !== 'GET',$now);
$service = new AlipaySettings($db,SecretBox::fromEnvironment());
$context = Audit::context($_SERVER);
if ($path !== '/api/v2/admin/settings/alipay') { throw new Problem('not_found',404); }
if ($method === 'GET') {
    $data = $service->view($owner['id'],$context,$now);
} elseif ($method === 'POST') {
    $input = HttpRequest::json();
    if (!(($input['values'] ?? null) instanceof stdClass) || !is_array($input['clear_fields'] ?? [])) {
        throw new Problem('alipay_setting_invalid');
    }
    $data = $service->save($owner['id'],(array)$input['values'],$input['clear_fields'] ?? [],$context,$now);
} else {
    throw new Problem('method_not_allowed',405);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
