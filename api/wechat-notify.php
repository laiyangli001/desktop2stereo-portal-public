<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\Audit;
use D2S\services\SecretBox;
use D2S\services\WechatPayments;
use D2S\services\WechatRefunds;
use D2S\services\WechatProfiles;

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo '{"code":"FAIL","message":"method_not_allowed"}';return;}
try{
    if(!empty($_SERVER['QUERY_STRING'])||strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json'){
        throw new Problem('wechat_notification_invalid');
    }
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new Problem('request_too_large',413);
    $raw=file_get_contents('php://input',false,null,0,65537);
    if(!is_string($raw)||strlen($raw)>65536)throw new Problem('request_too_large',413);
    $headers=[];
    foreach(['Timestamp','Nonce','Serial','Signature']as$name)$headers['Wechatpay-'.$name]=$_SERVER['HTTP_WECHATPAY_'.strtoupper($name)]??'';
    $db=Database::fromEnvironment();
    $db->exec('SET SESSION innodb_lock_wait_timeout=2');
    $profiles=new WechatProfiles($db,SecretBox::fromEnvironment());
    $handler=($wechatRefundNotification??false)?new WechatRefunds($db,$profiles):new WechatPayments($db,$profiles);
    $handler->notify($wechatRoute[1],$headers,$raw,Audit::context($_SERVER),time());
    http_response_code(204);
}catch(Problem $error){
    $invalid=in_array($error->error,['wechat_signature_invalid','wechat_response_freshness_invalid','wechat_headers_invalid','wechat_json_invalid'],true);
    http_response_code($invalid||$error->httpStatus<500?400:503);
    echo '{"code":"FAIL","message":"notification_rejected"}';
}catch(Throwable){
    http_response_code(503);
    echo '{"code":"FAIL","message":"temporarily_unavailable"}';
}
