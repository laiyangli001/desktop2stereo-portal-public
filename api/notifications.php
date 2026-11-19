<?php
declare(strict_types=1);
use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\TransactionNotifications;
use D2S\services\WebSessions;
$db=Database::fromEnvironment();$now=time();$write=$_SERVER['REQUEST_METHOD']==='POST';
$admin=str_starts_with($path,'/api/v2/admin/');
$owner=HttpRequest::session($admin?'admin':'user',new WebSessions($db),$write,$now);
$service=new TransactionNotifications($db);
if($admin){
    if($_SERVER['REQUEST_METHOD']==='GET'&&$path==='/api/v2/admin/notifications'){
        $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false)throw new Problem('page_invalid');
        if(isset($_GET['status'])&&!is_string($_GET['status']))throw new Problem('notification_status_invalid');
        $data=$service->deliveries($owner['id'],$page,$_GET['status']??null,Audit::context($_SERVER),$now);
    }elseif($write&&preg_match('#^/api/v2/admin/notifications/([a-f0-9]{32})/retry$#D',$path,$parts)){
        $input=HttpRequest::json();HttpRequest::strings($input,['reason','idempotency_key']);
        if(($input['confirm_retry']??null)!==true)throw new Problem('notification_retry_confirmation_required');
        $data=$service->retry($owner['id'],$parts[1],$input['reason'],$input['idempotency_key'],Audit::context($_SERVER),$now);
    }else throw new Problem('not_found',404);
}elseif($_SERVER['REQUEST_METHOD']==='GET'&&$path==='/api/v2/notifications/transactions'){
    if(isset($_GET['cursor'])&&!is_string($_GET['cursor']))throw new Problem('history_cursor_invalid');
    $data=$service->paymentHistory($owner['id'],$_GET['cursor']??null);
}elseif($_SERVER['REQUEST_METHOD']==='GET'&&$path==='/api/v2/notifications'){
    if(isset($_GET['cursor'])&&!is_string($_GET['cursor']))throw new Problem('history_cursor_invalid');
    $data=$service->inbox($owner['id'],$_GET['cursor']??null);
}elseif($write&&preg_match('#^/api/v2/notifications/([a-f0-9]{32})/read$#D',$path,$parts)){
    HttpRequest::json();$service->read($owner['id'],$parts[1],$now);$data=['read'=>true];
}else throw new Problem('not_found',404);
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
