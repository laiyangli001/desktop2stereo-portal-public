<?php
declare(strict_types=1);
use D2S\domain\Problem;
use D2S\services\Audit;
use D2S\services\Installation;

$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'&&$path==='/api/v2/install/environment'){
    $data=Installation::environment();
}elseif($method==='POST'&&in_array($path,['/api/v2/install/database','/api/v2/install/run'],true)){
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new Problem('json_content_type_required',415);
    $origin=$_SERVER['HTTP_ORIGIN']??null;
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $self=$scheme.'://'.($_SERVER['HTTP_HOST']??'');
    if($origin!==null&&$origin!==$self&&$origin!==getenv('D2S_APP_URL'))throw new Problem('origin_denied',403);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new Problem('request_too_large',413);
    $raw=file_get_contents('php://input',false,null,0,16385);
    if(!is_string($raw)||strlen($raw)>16384)throw new Problem('request_too_large',413);
    try{$input=json_decode($raw,false,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new Problem('json_invalid');}
    if(!$input instanceof stdClass)throw new Problem('json_object_required');
    $input=(array)$input;
    $data=$path==='/api/v2/install/database'?Installation::test($input):Installation::install($input,Audit::context($_SERVER),time());
}else throw new Problem('not_found',404);
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
