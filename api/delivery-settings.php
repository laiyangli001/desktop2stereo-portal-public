<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\DeliverySettings;
use D2S\services\HttpRequest;
use D2S\services\SecretBox;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$method = $_SERVER['REQUEST_METHOD'];
$now = time();
$input = $method === 'POST' ? HttpRequest::json() : [];
$owner = HttpRequest::session('admin',new WebSessions($db),$method !== 'GET',$now);
$context = Audit::context($_SERVER);
$settings = new DeliverySettings($db,SecretBox::fromEnvironment());

if ($method === 'GET' && $path === '/api/v2/admin/settings/delivery') {
    $data = $settings->view($owner['id'],$context,$now);
} elseif ($method === 'POST' && $path === '/api/v2/admin/settings/delivery') {
    if (!(($input['values'] ?? null) instanceof stdClass) || !is_array($input['clear_fields'] ?? [])) {
        throw new Problem('delivery_setting_invalid');
    }
    $data = $settings->save($owner['id'],(array)$input['values'],$input['clear_fields'] ?? [],$context,$now);
} elseif ($method === 'POST' && $path === '/api/v2/admin/settings/delivery/test') {
    $settings->test($owner['id'],$context,$now);
    $data = ['sent'=>true];
} else {
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
