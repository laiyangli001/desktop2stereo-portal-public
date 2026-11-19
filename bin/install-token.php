<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap/app.php';
try{
    if(D2S\services\Installation::installed())throw new RuntimeException('installation_locked');
    $path=D2S_ROOT.'/storage/install-token';
    if(!is_file($path)){
        $file=fopen($path,'x');if(!$file)throw new RuntimeException('install_token_write_failed');
        fwrite($file,bin2hex(random_bytes(32)));fclose($file);@chmod($path,0600);
    }
    echo trim((string)file_get_contents($path)),PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
