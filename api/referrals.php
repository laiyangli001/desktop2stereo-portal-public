<?php
declare(strict_types=1);
use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Backoffice;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\Permissions;
use D2S\services\ReferralProgram;
use D2S\services\SecretBox;
use D2S\services\WebSessions;
$db=Database::fromEnvironment();$now=time();$write=$_SERVER['REQUEST_METHOD']==='POST';
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true))throw new Problem('method_not_allowed',405);
$admin=str_starts_with($path,'/api/v2/admin/');
$owner=HttpRequest::session($admin?'admin':'user',new WebSessions($db),$write,$now);
$input=$write?HttpRequest::json(true):[];$context=Audit::context($_SERVER);
$service=new ReferralProgram($db,SecretBox::fromEnvironment());
if($admin)Permissions::require($db,(int)$owner['id'],'referrals.manage');
if(!$write&&$path==='/api/v2/referrals')$data=$service->overview((int)$owner['id'],$now);
elseif($write&&$path==='/api/v2/referrals/withdrawals')$data=$service->requestWithdrawal((int)$owner['id'],$input,$context,$now);
elseif(!$write&&$path==='/api/v2/admin/referrals')$data=$service->adminOverview((int)$owner['id']);
elseif($write&&$path==='/api/v2/admin/referrals/configuration')$data=$service->saveConfiguration((int)$owner['id'],$input,$context,$now);
elseif($write&&$path==='/api/v2/admin/referrals/settle'){
    $count=$service->settle($now);(new Backoffice($db))->audit((int)$owner['id'],'SETTLE_REFERRALS','referrals',['released'=>$count],$context,$now);$data=['released'=>$count];
}elseif($admin&&preg_match('#^/api/v2/admin/referrals/withdrawals/([a-f0-9]{32})(/destination)?$#D',$path,$parts)){
    if(!$write&&isset($parts[2]))$data=$service->destination((int)$owner['id'],$parts[1],$context,$now);
    elseif($write&&!isset($parts[2])){
        if(($input['decision']??'')==='paid'&&($input['payout_confirmed']??false)!==true)throw new Problem('payout_confirmation_required');
        $data=$service->decide((int)$owner['id'],$parts[1],$input,$context,$now);
    }else throw new Problem('not_found',404);
}else throw new Problem('not_found',404);
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
