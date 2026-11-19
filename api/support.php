<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\SupportTickets;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$sessions = new WebSessions($db);
$tickets = new SupportTickets($db);
$now = time();
$context = Audit::context($_SERVER);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && preg_match('#^/api/v2/support/attachments/([a-f0-9]{32})$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('user',$sessions,false,$now);
    $file = $tickets->attachment($owner['id'],'user',$match[1],$context,$now);
    stream_attachment($file);
}
if ($method === 'GET' && preg_match('#^/api/v2/admin/tickets/attachments/([a-f0-9]{32})$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('admin',$sessions,false,$now);
    $file = $tickets->attachment($owner['id'],'admin',$match[1],$context,$now);
    stream_attachment($file);
}
if ($method === 'POST' && preg_match('#^/api/v2/support/tickets/([a-f0-9]{32})/attachments$#D',(string)$path,$match)) {
    HttpRequest::assertOrigin();
    $owner = HttpRequest::session('user',$sessions,true,$now);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 6*1024*1024 ||
        !isset($_POST['message_id']) || !is_string($_POST['message_id']) || !isset($_FILES['file']) ||
        !is_array($_FILES['file'])) {
        throw new Problem('attachment_upload_invalid',413);
    }
    $data = $tickets->uploadAttachment($owner['id'],$match[1],$_POST['message_id'],$_FILES['file'],$now);
    http_response_code(201);
} elseif ($method === 'GET' && $path === '/api/v2/support/tickets') {
    $owner = HttpRequest::session('user',$sessions,false,$now);
    $data = $tickets->userTickets($owner['id'],max(1,(int)($_GET['page'] ?? 1)),
        is_string($_GET['view'] ?? null) ? $_GET['view'] : '',
        is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
} elseif ($method === 'POST' && $path === '/api/v2/support/tickets') {
    $owner = HttpRequest::session('user',$sessions,true,$now);
    $data = $tickets->create($owner['id'],HttpRequest::json(),$context,$now);
    http_response_code(201);
} elseif ($method === 'GET' && preg_match('#^/api/v2/support/tickets/([a-f0-9]{32})$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('user',$sessions,false,$now);
    $data = $tickets->userView($owner['id'],$match[1],$context,$now);
} elseif ($method === 'POST' && preg_match('#^/api/v2/support/tickets/([a-f0-9]{32})/messages$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('user',$sessions,true,$now);
    $input = HttpRequest::json();
    HttpRequest::strings($input,['body']);
    $data = $tickets->userReply($owner['id'],$match[1],$input['body'],$context,$now,
        isset($input['body_format']) ? (string)$input['body_format'] : 'PLAIN');
    http_response_code(201);
} elseif ($method === 'POST' && preg_match('#^/api/v2/support/tickets/([a-f0-9]{32})/close$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('user',$sessions,true,$now);
    $data = $tickets->closeByUser($owner['id'],$match[1],$context,$now);
} elseif ($method === 'GET' && $path === '/api/v2/admin/tickets') {
    $owner = HttpRequest::session('admin',$sessions,false,$now);
    $data = $tickets->adminTickets($owner['id'],(string)($_GET['status'] ?? ''),max(1,(int)($_GET['page'] ?? 1)),$context,$now);
} elseif ($method === 'GET' && preg_match('#^/api/v2/admin/tickets/([a-f0-9]{32})$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('admin',$sessions,false,$now);
    $data = $tickets->adminView($owner['id'],$match[1],$context,$now);
} elseif ($method === 'POST' && preg_match('#^/api/v2/admin/tickets/([a-f0-9]{32})/reply$#D',(string)$path,$match)) {
    $owner = HttpRequest::session('admin',$sessions,true,$now);
    $input = HttpRequest::json();
    HttpRequest::strings($input,['body']);
    $data = $tickets->adminReply($owner['id'],$match[1],$input['body'],
        isset($input['status']) ? (string)$input['status'] : null,
        isset($input['visibility']) ? (string)$input['visibility'] : 'PUBLIC',$context,$now,
        isset($input['body_format']) ? (string)$input['body_format'] : 'PLAIN');
    http_response_code(201);
} else {
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);

function stream_attachment(array $file): never
{
    header('Content-Type: '.$file['mime_type']);
    header('Content-Length: '.$file['byte_size']);
    header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($file['name']));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($file['path']);
    exit;
}
