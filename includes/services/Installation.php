<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final class Installation
{
    public static function installed(): bool
    {
        if(is_file(D2S_ROOT.'/storage/install.lock')||is_file(D2S_ROOT.'/storage/config.local.json'))return true;
        if(getenv('D2S_DB_DSN')){
            try{
                $db=Database::fromEnvironment();
                return (int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()>0;
            }catch(\Throwable){return true;}
        }
        return false;
    }

    public static function environment(): array
    {
        $items=[['name'=>'PHP 8.4','ok'=>PHP_VERSION_ID>=80400&&PHP_VERSION_ID<80500,'current'=>PHP_VERSION]];
        foreach(['pdo_mysql','openssl','sodium','mbstring','curl','gd','fileinfo','json','dom','intl']as$extension){
            $items[]=['name'=>$extension,'ok'=>extension_loaded($extension),'current'=>extension_loaded($extension)?'enabled':'missing'];
        }
        $storage=D2S_ROOT.'/storage';
        $items[]=['name'=>'storage','ok'=>is_dir($storage)&&is_writable($storage),'current'=>is_writable($storage)?'writable':'not writable'];
        $manifestPath=D2S_ROOT.'/resources/locales/manifest.json';
        $manifestOk=false;
        if(is_file($manifestPath)&&is_readable($manifestPath)){
            try{
                $manifest=json_decode((string)file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
                $manifestOk=is_array($manifest)&&is_array($manifest['locales']??null)&&$manifest['locales']!==[];
            }catch(\Throwable){$manifestOk=false;}
        }
        $items[]=['name'=>'Locale registry','ok'=>$manifestOk,'current'=>$manifestOk?'present':'missing or invalid'];
        $items[]=['name'=>'Composer dependencies','ok'=>class_exists(\PHPMailer\PHPMailer\PHPMailer::class)&&class_exists(\MaxMind\Db\Reader::class)&&class_exists(\HTMLPurifier::class)&&class_exists(\Symfony\Component\Translation\Translator::class),'current'=>is_file(D2S_ROOT.'/vendor/autoload.php')?'present':'missing'];
        return ['items'=>$items,'pass'=>!in_array(false,array_column($items,'ok'),true),'installed'=>self::installed()];
    }

    public static function authorize(mixed $provided): void
    {
        $expected=getenv('D2S_INSTALL_TOKEN')?:'';
        if($expected===''&&is_file(D2S_ROOT.'/storage/install-token'))$expected=trim((string)file_get_contents(D2S_ROOT.'/storage/install-token'));
        if(!is_string($provided)||strlen($provided)>256||strlen($expected)<32||!hash_equals($expected,$provided))throw new Problem('installation_token_invalid',403);
        if(self::installed())throw new Problem('installation_locked',409);
    }

    private static function connection(array $input): array
    {
        $host=Backoffice::text($input,'db_host',253,true);
        // The provisioned runtime account is deliberately restricted to local TCP.
        if(!in_array($host,['127.0.0.1','localhost'],true))throw new Problem('installation_local_database_required');
        $port=filter_var($input['db_port']??3306,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $name=Backoffice::text($input,'db_name',64,true);
        if($port===false||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name))throw new Problem('database_configuration_invalid');
        $user=Backoffice::text($input,'db_user',80,true);$password=$input['db_password']??'';
        if(!is_string($password)||strlen($password)>1024||str_contains($password,"\0"))throw new Problem('database_configuration_invalid');
        return [$host,$port,$name,$user,$password];
    }

    public static function test(array $input): array
    {
        self::authorize($input['install_token']??null);
        [$host,$port,$name,$user,$password]=self::connection($input);
        try{
            $db=Database::connect("mysql:host=$host;port=$port;charset=utf8mb4",$user,$password);
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?');$q->execute([$name]);
            if((int)$q->fetchColumn()>0)throw new Problem('installation_database_not_empty',409);
            return ['connected'=>true,'database_empty'=>true,'version'=>$db->query('SELECT VERSION()')->fetchColumn()];
        }catch(\PDOException){throw new Problem('database_connection_failed',400);}
    }

    public static function install(array $input,array $context,int $now): array
    {
        self::authorize($input['install_token']??null);
        if(!self::environment()['pass'])throw new Problem('installation_environment_failed',409);
        [$host,$port,$name,$operator,$operatorPassword]=self::connection($input);
        $username=AccountInput::username(Backoffice::text($input,'admin_username',32,true));
        $email=AccountInput::contact('email',Backoffice::text($input,'admin_email',254,true));
        $password=$input['admin_password']??null;
        if(!is_string($password)||$password!==($input['admin_password_confirm']??null))throw new Problem('password_confirmation_invalid');
        AccountInput::password($password,true);
        $url=rtrim(Backoffice::text($input,'app_url',2048,true),'/');
        $parts=parse_url($url);
        if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||
            !empty($parts['path'])||!isset($parts['host'])||
            (($parts['scheme']??'')!=='https'&&!(($parts['scheme']??'')==='http'&&in_array($parts['host'],['127.0.0.1','localhost'],true)))){
            throw new Problem('installation_url_invalid');
        }
        $handle=fopen(D2S_ROOT.'/storage/install.guard','c+');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new Problem('installation_busy',409);
        $db=null;$lock=null;
        try{
            self::authorize($input['install_token']??null);
            try{$db=Database::connect("mysql:host=$host;port=$port;charset=utf8mb4",$operator,$operatorPassword);}
            catch(\PDOException){throw new Problem('database_connection_failed');}
            $lock='d2s-install-'.substr(hash('sha256',$name),0,40);
            $q=$db->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new Problem('installation_busy',409);
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?');$q->execute([$name]);
            if((int)$q->fetchColumn()>0)throw new Problem('installation_database_not_empty',409);
            $db->exec("CREATE DATABASE IF NOT EXISTS \x60$name\x60 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->exec("USE \x60$name\x60");
            (new Migrator($db,D2S_ROOT.'/database/migrations'))->run();
            $adminId=Database::transaction($db,static function(PDO $db)use($username,$email,$password,$context,$now):int{
                $db->prepare('INSERT INTO portal_admins(username,email,password_hash,is_system,created_at,updated_at,password_changed_at) VALUES(?,?,?,1,?,?,?)')
                    ->execute([$username,$email,password_hash($password,PASSWORD_DEFAULT),$now,$now,$now]);
                $id=(int)$db->lastInsertId();
                $q=$db->prepare('INSERT INTO portal_admin_permissions(admin_id,permission) VALUES(?,?)');
                foreach(array_unique(array_merge(...array_values(Permissions::ROLES)))as$permission)$q->execute([$id,$permission]);
                Audit::admin($db,$id,$email,'INSTALL_SITE','installation',$context,'SUCCESS',[], $now);
                return $id;
            });
            $runtimeUser='d2s_app_'.bin2hex(random_bytes(6));$runtimePassword=bin2hex(random_bytes(24));
            $db->exec('CREATE USER '.$db->quote($runtimeUser)."@'127.0.0.1' IDENTIFIED BY ".$db->quote($runtimePassword));
            DatabasePermissions::grant($db,$name,$runtimeUser,'runtime');
            $values=['D2S_DB_DSN'=>"mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",
                'D2S_DB_USER'=>$runtimeUser,'D2S_DB_PASSWORD'=>$runtimePassword,
                'D2S_MASTER_KEY'=>base64_encode(random_bytes(32)),'D2S_APP_URL'=>$url];
            $runtime=Database::connect($values['D2S_DB_DSN'],$runtimeUser,$runtimePassword);
            if((int)$runtime->query('SELECT COUNT(*) FROM portal_admins')->fetchColumn()!==1)throw new Problem('installation_runtime_verification_failed');
            $path=D2S_ROOT.'/storage/config.local.json';
            $file=fopen($path,'x');if(!$file)throw new Problem('installation_configuration_exists',409);
            if(fwrite($file,json_encode($values,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false)throw new Problem('installation_configuration_write_failed');
            fclose($file);@chmod($path,0600);
            $file=fopen(D2S_ROOT.'/storage/install.lock','x');if(!$file)throw new Problem('installation_lock_write_failed');
            fwrite($file,json_encode(['installed_at'=>$now,'admin_id'=>$adminId],JSON_THROW_ON_ERROR));fclose($file);
            foreach($values as$key=>$value)if(getenv($key)===false)putenv($key.'='.$value);
            return ['installed'=>true,'login_url'=>'/admin/login','admin_username'=>$username];
        }finally{
            if($db&&$lock){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
            flock($handle,LOCK_UN);fclose($handle);
        }
    }
}
