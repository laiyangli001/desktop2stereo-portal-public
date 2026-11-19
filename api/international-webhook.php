<?php
declare(strict_types=1);
use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\ExternalPayments;
use D2S\services\SecretBox;
header('Content-Type: application/json; charset=utf-8');
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new Problem('method_not_allowed',405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576)throw new Problem('payment_event_too_large',413);
    $body=file_get_contents('php://input',false,null,0,1048577);
    if(!is_string($body)||strlen($body)>1048576)throw new Problem('payment_event_too_large',413);
    $headers=function_exists('getallheaders')?getallheaders():[];
    $service=new ExternalPayments(Database::fromEnvironment(),SecretBox::fromEnvironment());
    $data=$service->webhook($internationalRoute[1],$internationalRoute[2],$headers,$body,time());
    echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
}catch(Problem $error){
    http_response_code($error->httpStatus);echo json_encode(['success'=>false,'error'=>$error->error],JSON_THROW_ON_ERROR);
}catch(Throwable){
    http_response_code(503);echo '{"success":false,"error":"payment_notification_unavailable"}';
}
