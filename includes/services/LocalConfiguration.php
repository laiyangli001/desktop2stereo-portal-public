<?php
declare(strict_types=1);
namespace D2S\services;

final class LocalConfiguration
{
    public static function load(): void
    {
        $path=D2S_ROOT.'/storage/config.local.json';
        if(!is_file($path))return;
        $values=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($values))throw new \RuntimeException('local_configuration_invalid');
        foreach(['D2S_DB_DSN','D2S_DB_USER','D2S_DB_PASSWORD','D2S_MASTER_KEY','D2S_APP_URL']as$key){
            if(getenv($key)===false&&isset($values[$key])&&is_string($values[$key]))putenv($key.'='.$values[$key]);
        }
    }
}
