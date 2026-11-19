<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

/** Normalize output paths and reject targets inside the application's public directory. */
final class PrivateOutputDirectory
{
    public static function resolve(string $path, string $applicationRoot, string $errorPrefix): string
    {
        if($path===''||str_contains($path,"\0")||
            (!str_starts_with($path,DIRECTORY_SEPARATOR)&&!preg_match('/^[A-Za-z]:[\\\\\/]/D',$path))){
            throw new RuntimeException($errorPrefix.'_invalid');
        }
        $path=self::canonicalPath($path,$errorPrefix);
        $public=self::canonicalPath($applicationRoot.DIRECTORY_SEPARATOR.'public',$errorPrefix);
        $key=DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
        $publicKey=DIRECTORY_SEPARATOR==='\\'?strtolower($public):$public;
        if($key===$publicKey||str_starts_with($key,$publicKey.DIRECTORY_SEPARATOR))throw new RuntimeException($errorPrefix.'_public');
        return $path;
    }

    /** Resolve existing ancestors before appending children that have not been created yet. */
    private static function canonicalPath(string $path, string $errorPrefix): string
    {
        if(DIRECTORY_SEPARATOR==='\\'){
            $path=str_replace('\\','/',$path);
            if(preg_match('/^[A-Za-z]:\//D',$path)!==1)throw new RuntimeException($errorPrefix.'_invalid');
            $resolved=substr($path,0,3);
            $parts=explode('/',substr($path,3));
        }else{
            $resolved='/';
            $parts=explode('/',substr($path,1));
        }
        foreach($parts as$part){
            if($part===''||$part==='.')continue;
            if($part==='..'){
                $resolved=dirname($resolved);
                continue;
            }
            // Windows aliases trailing spaces/dots and supports alternate data streams.
            if(DIRECTORY_SEPARATOR==='\\'&&(rtrim($part," .")!==$part||strpbrk($part,':*?"<>|')!==false)){
                throw new RuntimeException($errorPrefix.'_invalid');
            }
            $candidate=rtrim($resolved,'/\\').DIRECTORY_SEPARATOR.$part;
            if(file_exists($candidate)||is_link($candidate)){
                $actual=realpath($candidate);
                if($actual===false||!is_dir($actual))throw new RuntimeException($errorPrefix.'_invalid');
                $resolved=$actual;
            }else{
                $resolved=$candidate;
            }
        }
        return str_replace('/',DIRECTORY_SEPARATOR,$resolved);
    }

}
