<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap/app.php';

use D2S\repositories\Database;
use D2S\services\SecretBox;

$options=getopt('',['target:','purpose:','kind:']);
if(getenv('D2S_LOCAL_VERIFICATION_CAPTURE')!=='true'){
    fwrite(STDERR,"local_verification_capture_disabled\n");
    exit(2);
}
$appUrl=(string)(getenv('D2S_APP_URL')?:'');
$host=strtolower((string)(parse_url($appUrl,PHP_URL_HOST)?: ''));
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){
    fwrite(STDERR,"local_verification_capture_local_url_required\n");
    exit(2);
}
$purpose=(string)($options['purpose']??'');
$kind=(string)($options['kind']??'');
$target=(string)($options['target']??'');
if($purpose!==''&&!in_array($purpose,['register','password_reset','bind_contact'],true)){
    fwrite(STDERR,"local_verification_purpose_invalid\n");
    exit(2);
}
if($kind!==''&&!in_array($kind,['email','phone'],true)){
    fwrite(STDERR,"local_verification_kind_invalid\n");
    exit(2);
}

try{
    $db=Database::fromEnvironment();
    $secrets=SecretBox::fromEnvironment();
    $sql='SELECT q.id,q.channel,q.payload_cipher,q.expires_at
        FROM portal_delivery_outbox q
        JOIN portal_verifications v ON v.id=q.verification_id
        WHERE q.payload_cipher IS NOT NULL AND q.expires_at>? AND v.consumed_at IS NULL';
    $parameters=[time()];
    if($purpose!==''){
        $sql.=' AND v.purpose=?';
        $parameters[]=$purpose;
    }
    if($kind!==''){
        $sql.=' AND v.kind=?';
        $parameters[]=$kind;
    }
    if($target!==''){
        $sql.=' AND v.target=?';
        $parameters[]=trim($target);
    }
    $sql.=' ORDER BY v.created_at DESC,q.id DESC LIMIT 1';
    $query=$db->prepare($sql);
    $query->execute($parameters);
    $job=$query->fetch();
    if($job===false)throw new RuntimeException('local_verification_code_not_found');
    $payload=json_decode($secrets->decrypt((string)$job['payload_cipher'],'delivery:'.$job['id']),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($payload)||!is_string($payload['code']??null)||
        preg_match('/^[0-9]{6}$/D',$payload['code'])!==1){
        throw new RuntimeException('local_verification_payload_invalid');
    }
    echo json_encode(['success'=>true,'data'=>[
        'kind'=>$payload['kind']??null,
        'target'=>$payload['target']??null,
        'purpose'=>$payload['purpose']??null,
        'code'=>$payload['code'],
        'expires_at'=>(int)$job['expires_at'],
    ]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['success'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
