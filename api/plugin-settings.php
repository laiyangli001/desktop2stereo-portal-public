<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\PluginManager;
use D2S\services\WebSessions;

$db=Database::fromEnvironment();
$method=$_SERVER['REQUEST_METHOD'];
$now=time();
$owner=HttpRequest::session('admin',new WebSessions($db),$method!=='GET',$now);
$context=Audit::context($_SERVER);
$plugins=new PluginManager($db);

if($method==='GET'&&$path==='/api/v2/admin/settings/plugins'){
    $data=$plugins->view($owner['id'],$context,$now);
}elseif($method==='POST'&&$path==='/api/v2/admin/settings/plugins'){
    $input=HttpRequest::json();
    if(!(($input['values']??null)instanceof stdClass))throw new Problem('module_setting_invalid');
    $data=$plugins->save($owner['id'],(array)$input['values'],$context,$now);
}else{
    throw new Problem('not_found',404);
}
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
