<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\AuditRecords;
use D2S\services\HttpRequest;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$sessions = new WebSessions($db);
$audit = new AuditRecords($db);
$now = time();
$context = Audit::context($_SERVER);
$method = $_SERVER['REQUEST_METHOD'];
$owner = HttpRequest::session('admin',$sessions,$method === 'POST',$now);

if ($method === 'POST' && preg_match('#^/api/v2/admin/audit/events/([1-9][0-9]{0,18})/verify$#D',(string)$path,$match)) {
    $data = $audit->verifyUserLogHash($owner['id'],$match[1],$context,$now);
} elseif ($method === 'GET' && in_array($path,['/api/v2/admin/audit/events','/api/v2/admin/audit/registrations','/api/v2/admin/audit/operations'],true)) {
    $resource = $path === '/api/v2/admin/audit/events' ? 'access_log' :
        ($path === '/api/v2/admin/audit/registrations' ? 'registration' : 'admin_audit');
    $data = $audit->page($owner['id'],$resource,$_GET,max(1,(int)($_GET['page'] ?? 1)),
        max(1,(int)($_GET['page_size'] ?? 50)),$context,$now);
} elseif ($method === 'GET' && $path === '/api/v2/admin/audit/exports') {
    $data = $audit->exports($owner['id'],(bool)($_GET['all'] ?? false));
} elseif ($method === 'POST' && $path === '/api/v2/admin/audit/exports') {
    $input = HttpRequest::json();
    $data = $audit->requestExport($owner['id'],(string)($input['resource_type'] ?? ''),$input,$context,$now);
    http_response_code(201);
} elseif ($method === 'POST' && preg_match('#^/api/v2/admin/audit/exports/([a-f0-9]{32})/decision$#D',(string)$path,$match)) {
    $input = HttpRequest::json();
    $data = $audit->decideExport($owner['id'],$match[1],(string)($input['decision'] ?? ''),
        (string)($input['reason'] ?? ''),$context,$now);
} elseif ($method === 'POST' && preg_match('#^/api/v2/admin/audit/exports/([a-f0-9]{32})/download$#D',(string)$path,$match)) {
    $download = $audit->beginDownload($owner['id'],$match[1],$context,$now);
    $filename = AuditRecords::filename($download['resource_type'],$now);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output','wb');
    if ($output === false) {
        throw new Problem('export_output_unavailable',503);
    }
    fwrite($output,"\xEF\xBB\xBF");
    $headers = match ($download['resource_type']) {
        'access_log'=>['id','user_id','email','action_type','event_time','source_ip','source_port','destination_ip','destination_port','user_agent','device_fingerprint','request_path','result_status','record_hash','hash_version'],
        'registration'=>['user_id','email','register_ip','register_time','register_user_agent','email_verified','email_verified_at','contact_kind','contact_value'],
        default=>['id','operator_id','operator_email','operation_type','operation_time','target_resource','source_ip','result','details_json'],
    };
    fputcsv($output,$headers,',','"','');
    $after = 0;
    $count = 0;
    while (!connection_aborted()) {
        $rows = $audit->streamRows($download['resource_type'],$download['filters'],$after,500);
        if ($rows === []) {
            break;
        }
        foreach ($rows as $row) {
            $after = (int)$row['id'];
            $values = match ($download['resource_type']) {
                'access_log'=>[$row['id'],$row['user_id'],$row['email'],$row['action_type'],AuditRecords::csvTime($row['event_time']),
                    $row['source_ip'],$row['source_port'],$row['destination_ip'],$row['destination_port'],$row['user_agent'],
                    $row['device_fingerprint'],$row['request_path'],$row['result_status'],$row['record_hash'],$row['hash_version']],
                'registration'=>[$row['user_id'],$row['email'],$row['register_ip'],AuditRecords::csvTime($row['register_time']),
                    $row['register_user_agent'],$row['email_verified'],AuditRecords::csvTime($row['email_verified_at']),
                    $row['contact_kind'],$row['contact_value']],
                default=>[$row['id'],$row['operator_id'],$row['operator_email'],$row['operation_type'],
                    AuditRecords::csvTime($row['operation_time']),$row['target_resource'],$row['source_ip'],$row['result'],$row['details_json']],
            };
            fputcsv($output,array_map(AuditRecords::csvCell(...),$values),',','"','');
            $count++;
        }
    }
    fflush($output);
    $audit->finishDownload($owner['id'],$download,$count,$context,$now);
    exit;
} else {
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
