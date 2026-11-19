<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\AccountControls;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\SecretBox;
use D2S\services\SupportTickets;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$sessions = new WebSessions($db);
$controls = new AccountControls($db);
$tickets = new SupportTickets($db,null,SecretBox::fromEnvironment());
$now = time();
$context = Audit::context($_SERVER);
$method = $_SERVER['REQUEST_METHOD'];
$owner = HttpRequest::session('admin',$sessions,$method !== 'GET',$now);

if ($method === 'GET' && $path === '/api/v2/admin/accounts') {
    $data = $controls->search($owner['id'],(string)($_GET['query'] ?? ''));
} elseif ($method === 'GET' && preg_match('#^/api/v2/admin/accounts/([1-9][0-9]{0,18})/control$#D',(string)$path,$match)) {
    $data = $controls->view($owner['id'],(int)$match[1]);
} elseif ($method === 'POST' && preg_match('#^/api/v2/admin/accounts/([1-9][0-9]{0,18})/control$#D',(string)$path,$match)) {
    $input = HttpRequest::json();
    $login = array_key_exists('login_blocked',$input) ? is_bool($input['login_blocked']) ? $input['login_blocked'] : null : null;
    $purchase = array_key_exists('purchase_blocked',$input) ? is_bool($input['purchase_blocked']) ? $input['purchase_blocked'] : null : null;
    if ((array_key_exists('login_blocked',$input) && $login === null) ||
        (array_key_exists('purchase_blocked',$input) && $purchase === null)) {
        throw new Problem('emergency_control_invalid');
    }
    $data = $controls->apply($owner['id'],(int)$match[1],$login,$purchase,(bool)($input['force_logout'] ?? false),
        (string)($input['reason'] ?? ''),($input['confirm'] ?? false) === true,$context,$now);
} elseif ($method === 'POST' && $path === '/api/v2/admin/accounts/batch/preview') {
    $data = $controls->previewBatch($owner['id'],HttpRequest::json(),$context,$now);
} elseif ($method === 'POST' && $path === '/api/v2/admin/accounts/batch/apply') {
    $input = HttpRequest::json();
    $data = $controls->applyBatch($owner['id'],(string)($input['preview_id'] ?? ''),
        (int)($input['confirmation_count'] ?? 0),($input['confirmed'] ?? false) === true,$context,$now);
} elseif ($method === 'POST' && preg_match('#^/api/v2/admin/accounts/([1-9][0-9]{0,18})/identity/request$#D',(string)$path,$match)) {
    $input = HttpRequest::json();
    $data = $tickets->requestIdentityReview($owner['id'],(int)$match[1],(string)($input['reason'] ?? ''),$context,$now);
} elseif ($method === 'POST' && preg_match('#^/api/v2/admin/accounts/([1-9][0-9]{0,18})/identity/decision$#D',(string)$path,$match)) {
    $input = HttpRequest::json();
    $data = $tickets->decideIdentityReview($owner['id'],(int)$match[1],(string)($input['decision'] ?? ''),
        (string)($input['document_type'] ?? ''),(string)($input['document_number'] ?? ''),
        (string)($input['document_country'] ?? ''),(string)($input['reason'] ?? ''),$context,$now);
} else {
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
