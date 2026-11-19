<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use FilesystemIterator;
use PharData;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class OnlineUpdate
{
    public const FORMAT='d2s-update-metadata-v1';
    private const MAX_METADATA_BYTES=1048576;
    private const MAX_SIGNATURE_BYTES=4096;
    private const MAX_ARTIFACT_BYTES=134217728;
    private const MAX_UNCOMPRESSED_BYTES=536870912;
    private const MAX_ARCHIVE_FILES=5000;
    private const MAX_ARCHIVE_ENTRIES=10000;
    private const MAX_PHP_SOURCE_BYTES=16777216;
    private const MAX_PREPARED_BYTES=65536;

    private ?string $decodedPublicKey=null;

    public function __construct(
        private string $root=D2S_ROOT,
        private ?string $stagingRoot=null,
        private ?string $publicKeyEncoded=null,
        private ?string $allowedHosts=null,
        private ?Closure $fetcher=null,
        private ?string $caBundle=null,
        private ?string $candidateRoot=null,
    ) {}

    public static function fromEnvironment(string $root=D2S_ROOT): self
    {
        $ca=getenv('D2S_CA_BUNDLE');
        return new self(
            $root,
            getenv('D2S_UPDATE_STAGING_DIR')?:null,
            getenv('D2S_UPDATE_PUBLIC_KEY')?:null,
            getenv('D2S_UPDATE_ALLOWED_HOSTS')?:null,
            null,
            is_string($ca)&&$ca!==''?$ca:null,
            getenv('D2S_UPDATE_CANDIDATE_DIR')?:null,
        );
    }

    public function status(): array
    {
        $root=$this->stagingPath();
        $items=[];
        if(is_dir($root)){
            foreach(glob($root.'/*',GLOB_ONLYDIR)?:[]as$directory){
                $release=basename($directory);
                if(!preg_match('/^[a-f0-9]{40}$/D',$release))continue;
                try{$items[]=$this->inspectStaged($directory,false);}catch(\Throwable $error){
                    $items[]=['release'=>$release,'valid'=>false,'error'=>'staged_update_invalid'];
                }
            }
        }
        usort($items,static fn(array $a,array $b):int=>strcmp($b['release'],$a['release']));
        $keyState=$this->publicKeyState();
        try{$candidateRoot=$this->candidatePath();$candidateWritable=$this->destinationWritable($candidateRoot);}catch(\Throwable){$candidateWritable=false;}
        return [
            'configured'=>$keyState['ready']&&$this->allowedHosts()!==[],
            'public_key_ready'=>$keyState['ready'],
            'public_key_fingerprint'=>$keyState['fingerprint'],
            'allowed_hosts'=>$this->allowedHosts(),
            'staging_writable'=>is_dir($root)&&is_writable($root),
            'candidate_writable'=>$candidateWritable,
            'pending_install'=>count(array_filter($items,static fn(array $item):bool=>$item['valid'])),
            'prepared_packages'=>count(array_filter($items,static fn(array $item):bool=>($item['prepared']['ready']??false)===true)),
            'items'=>$items,
        ];
    }

    public function stage(array $input): array
    {
        $release=$input['release']??null;
        $metadataUrl=$input['metadata_url']??null;
        $signatureUrl=$input['signature_url']??null;
        if(!is_string($release)||!preg_match('/^[a-f0-9]{40}$/D',$release)
            ||!is_string($metadataUrl)||!is_string($signatureUrl)){
            throw new RuntimeException('update_stage_input_invalid');
        }
        $metadataResponse=$this->fetch($metadataUrl,self::MAX_METADATA_BYTES);
        $signatureResponse=$this->fetch($signatureUrl,self::MAX_SIGNATURE_BYTES);
        $metadata=$this->verifyMetadata($metadataResponse['body'],$signatureResponse['body'],$release);
        $artifactResponse=$this->fetch($metadata['artifact']['url'],self::MAX_ARTIFACT_BYTES);
        return $this->stagePackage($metadataResponse['body'],$signatureResponse['body'],$artifactResponse['body'],$release);
    }

    public function verifyMetadata(string $metadataBody,string $signatureBody,?string $release=null): array
    {
        if(strlen($metadataBody)>self::MAX_METADATA_BYTES||strlen($signatureBody)>self::MAX_SIGNATURE_BYTES){
            throw new RuntimeException('update_download_too_large');
        }
        $signature=base64_decode(trim($signatureBody),true);
        if(!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES
            ||!sodium_crypto_sign_verify_detached($signature,$metadataBody,$this->publicKey())){
            throw new RuntimeException('update_signature_invalid');
        }
        return $this->decodeMetadata($metadataBody,$release);
    }

    public function stagePackage(string $metadataBody,string $signatureBody,string $artifactBody,string $release): array
    {
        $metadata=$this->verifyMetadata($metadataBody,$signatureBody,$release);
        $artifact=$metadata['artifact'];
        if(!str_starts_with($artifactBody,"\x1f\x8b"))throw new RuntimeException('update_artifact_invalid');
        if(strlen($artifactBody)!==$artifact['size']||!hash_equals($artifact['sha256'],hash('sha256',$artifactBody))){
            throw new RuntimeException('update_artifact_hash_invalid');
        }

        $root=$this->stagingPath();
        if(!is_dir($root)&&!mkdir($root,UpdateSharedPermissions::directoryMode(),true)&&!is_dir($root))throw new RuntimeException('update_staging_unavailable');
        if(!is_writable($root))throw new RuntimeException('update_staging_unavailable');
        $target=$root.DIRECTORY_SEPARATOR.$release;
        if(is_dir($target)){
            $existing=$this->inspectStaged($target,true);
            if(!hash_equals((string)$existing['artifact_sha256'],$artifact['sha256']))throw new RuntimeException('update_stage_conflict');
            return array_replace($existing,['reused'=>true]);
        }
        $temporary=$root.DIRECTORY_SEPARATOR.'.stage-'.$release.'-'.bin2hex(random_bytes(6));
        if(!mkdir($temporary,UpdateSharedPermissions::directoryMode(),true))throw new RuntimeException('update_staging_unavailable');
        try{
            $this->write($temporary.DIRECTORY_SEPARATOR.'metadata.json',$metadataBody);
            $this->write($temporary.DIRECTORY_SEPARATOR.'metadata.sig',$signatureBody);
            $this->write($temporary.DIRECTORY_SEPARATOR.'artifact.tar.gz',$artifactBody);
            if(!rename($temporary,$target))throw new RuntimeException('update_staging_unavailable');
        }catch(\Throwable $error){
            foreach(glob($temporary.DIRECTORY_SEPARATOR.'*')?:[]as$file)@unlink($file);
            @rmdir($temporary);
            throw $error;
        }
        return array_replace($this->inspectStaged($target,true),['reused'=>false]);
    }

    public function stageSourceArchive(string $archive,string $release,string $repository,string $tag): array
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$release)||strlen($archive)<2||strlen($archive)>self::MAX_ARTIFACT_BYTES
            ||!str_starts_with($archive,"\x1f\x8b")
            ||!preg_match('#^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$#D',$repository)
            ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,99}$/D',$tag)){
            throw new RuntimeException('update_artifact_invalid');
        }
        $metadata=json_encode([
            'format'=>'d2s-github-source-v1','release'=>$release,'repository'=>$repository,'tag'=>$tag,
            'size'=>strlen($archive),'sha256'=>hash('sha256',$archive),'created_at'=>time(),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $root=$this->stagingPath();
        if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('update_staging_unavailable');
        if(!is_writable($root))throw new RuntimeException('update_staging_unavailable');
        $target=$root.DIRECTORY_SEPARATOR.$release;
        if(is_dir($target)){
            $existing=$this->inspectStaged($target,true);
            if(!hash_equals((string)$existing['artifact_sha256'],hash('sha256',$archive)))throw new RuntimeException('update_stage_conflict');
            return array_replace($existing,['reused'=>true]);
        }
        $temporary=$root.DIRECTORY_SEPARATOR.'.stage-'.$release.'-'.bin2hex(random_bytes(6));
        if(!mkdir($temporary,0700,true))throw new RuntimeException('update_staging_unavailable');
        try{
            $this->write($temporary.DIRECTORY_SEPARATOR.'source.json',$metadata);
            $this->write($temporary.DIRECTORY_SEPARATOR.'source.tar.gz',$archive);
            if(!rename($temporary,$target))throw new RuntimeException('update_staging_unavailable');
        }catch(\Throwable $error){
            foreach(glob($temporary.DIRECTORY_SEPARATOR.'*')?:[]as$file)@unlink($file);
            @rmdir($temporary);
            throw $error;
        }
        return array_replace($this->inspectStaged($target,true),['reused'=>false]);
    }

    public function prepareRelease(string $release,int $now): array
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_prepare_input_invalid');
        return $this->prepare(
            $this->stagingPath().DIRECTORY_SEPARATOR.$release,
            $this->candidatePath().DIRECTORY_SEPARATOR.$release,
            $now,
        );
    }

    public function prepare(string $stageDirectory,string $candidateDirectory,int $now): array
    {
        $staged=$this->inspectStaged($stageDirectory,true);
        $release=$staged['release'];
        $stageDirectory=realpath($stageDirectory);
        if($stageDirectory===false)throw new RuntimeException('update_stage_path_invalid');
        $candidateDirectory=$this->normalizeAbsolutePath($candidateDirectory);
        $this->assertCandidatePath($candidateDirectory,$release);
        $preparedPath=$stageDirectory.DIRECTORY_SEPARATOR.'prepared.json';
        $isSource=(bool)($staged['source_archive']??false);
        $existing=$isSource?($staged['prepared']??null):
            $this->preparedStatus($preparedPath,$release,$staged['manifest_root_hash']);
        $expectedManifestRoot=$staged['manifest_root_hash']??($existing['root_hash']??null);
        if($existing!==null&&$existing['ready']===true){
            $candidateManifest=ReleaseManifest::verify($candidateDirectory,$candidateDirectory.DIRECTORY_SEPARATOR.'release-manifest.json',$release);
            if(!$candidateManifest['ok']||!is_string($candidateManifest['root_hash'])
                ||!is_string($expectedManifestRoot)||!hash_equals($expectedManifestRoot,$candidateManifest['root_hash'])){
                throw new RuntimeException('update_candidate_invalid');
            }
            return array_replace($existing,['reused'=>true]);
        }
        if(file_exists($candidateDirectory)||is_link($candidateDirectory))throw new RuntimeException('update_candidate_exists');

        $parent=dirname($candidateDirectory);
        if(!is_dir($parent)&&!mkdir($parent,UpdateSharedPermissions::directoryMode(),true)&&!is_dir($parent))throw new RuntimeException('update_candidate_unavailable');
        if(!is_writable($parent))throw new RuntimeException('update_candidate_unavailable');
        $temporary=$candidateDirectory.'.preparing-'.bin2hex(random_bytes(6));
        if(!mkdir($temporary,UpdateSharedPermissions::directoryMode()))throw new RuntimeException('update_candidate_unavailable');
        $moved=false;
        try{
            if($isSource){
                $this->extractSourceArchive($stageDirectory.DIRECTORY_SEPARATOR.'source.tar.gz',$temporary);
                $this->copyCurrentVendor($temporary);
                $manifest=ReleaseManifest::build($temporary,$release,$now);
                $manifestPath=$temporary.DIRECTORY_SEPARATOR.'release-manifest.json';
                ReleaseManifest::write($manifestPath,$manifest);
                $migrations=$this->migrationInventory($temporary);
            }else{
                $metadataRaw=@file_get_contents($stageDirectory.DIRECTORY_SEPARATOR.'metadata.json',false,null,0,self::MAX_METADATA_BYTES+1);
                if(!is_string($metadataRaw))throw new RuntimeException('update_metadata_invalid');
                $metadata=$this->decodeMetadata($metadataRaw,$release);
                $this->extract($stageDirectory.DIRECTORY_SEPARATOR.'artifact.tar.gz',$temporary);
                $manifestPath=$temporary.DIRECTORY_SEPARATOR.'release-manifest.json';
                $manifest=ReleaseManifest::verify($temporary,$manifestPath,$release);
                if(!$manifest['ok'])throw new RuntimeException('update_release_manifest_invalid');
                if(!is_string($manifest['root_hash'])||!hash_equals($metadata['release_manifest_root_hash'],$manifest['root_hash'])){
                    throw new RuntimeException('update_release_manifest_mismatch');
                }
                $this->verifyMigrations($temporary,$metadata['migrations']);
                $migrations=$metadata['migrations'];
            }
            $verified=ReleaseManifest::verify($temporary,$manifestPath,$release);
            if(!$verified['ok'])throw new RuntimeException('update_release_manifest_invalid');
            $manifest=$verified;
            $phpFiles=$this->lintPhp($temporary);
            $details=[
                'format'=>'d2s-prepared-update-v1','release'=>$release,'prepared_at'=>$now,
                'file_count'=>$manifest['file_count'],'root_hash'=>$manifest['root_hash'],
                'migration_count'=>count($migrations),'php_files_linted'=>$phpFiles,
            ];
            $encoded=json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(!rename($temporary,$candidateDirectory))throw new RuntimeException('update_candidate_unavailable');
            $moved=true;
            $this->write($preparedPath,$encoded);
            return array_replace($details,['candidate_available'=>true,'ready'=>true,'reused'=>false]);
        }catch(\Throwable $error){
            if(is_dir($temporary)||is_link($temporary))$this->removeTree($temporary);
            if($moved&&(is_dir($candidateDirectory)||is_link($candidateDirectory)))$this->removeTree($candidateDirectory);
            throw $error;
        }
    }

    public function preparedCandidate(string $release): array
    {
        if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_prepare_input_invalid');
        $stage=$this->stagingPath().DIRECTORY_SEPARATOR.$release;
        $staged=$this->inspectStaged($stage,true);
        if(($staged['prepared']['ready']??false)!==true)throw new RuntimeException('update_candidate_invalid');
        $candidate=$this->candidatePath().DIRECTORY_SEPARATOR.$release;
        $this->assertCandidatePath($candidate,$release);
        if(!is_dir($candidate)||is_link($candidate))throw new RuntimeException('update_candidate_invalid');
        $verified=ReleaseManifest::verify($candidate,$candidate.DIRECTORY_SEPARATOR.'release-manifest.json',$release);
        $expectedRoot=$staged['manifest_root_hash']??($staged['prepared']['root_hash']??null);
        if(!$verified['ok']||!is_string($expectedRoot)||!hash_equals($expectedRoot,$verified['root_hash'])){
            throw new RuntimeException('update_candidate_invalid');
        }
        $migrations=($staged['source_archive']??false)?$this->migrationInventory($candidate):$staged['migrations'];
        return ['release'=>$release,'root'=>$candidate,'manifest_root_hash'=>$verified['root_hash'],
            'file_count'=>$verified['file_count'],'migrations'=>$migrations,
            'artifact_sha256'=>$staged['artifact_sha256'],'prepared_at'=>(int)($staged['prepared']['prepared_at']??0)];
    }

    public function inspectStaged(string $directory,bool $verify=true): array
    {
        $real=realpath($directory);
        $base=realpath($this->stagingPath());
        if($real===false||$base===false||!str_starts_with($real,$base.DIRECTORY_SEPARATOR))throw new RuntimeException('update_stage_path_invalid');
        $release=basename($real);
        if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('update_stage_path_invalid');
        $sourcePath=$real.DIRECTORY_SEPARATOR.'source.json';
        if(is_file($sourcePath)&&!is_link($sourcePath)){
            $sourceRaw=@file_get_contents($sourcePath,false,null,0,self::MAX_METADATA_BYTES+1);
            if(!is_string($sourceRaw)||$sourceRaw===''||strlen($sourceRaw)>self::MAX_METADATA_BYTES){
                throw new RuntimeException('update_metadata_invalid');
            }
            try{$source=json_decode($sourceRaw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){
                throw new RuntimeException('update_metadata_invalid');
            }
            if(!is_array($source)||($source['format']??null)!=='d2s-github-source-v1'
                ||($source['release']??null)!==$release||!is_string($source['repository']??null)
                ||!preg_match('#^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$#D',$source['repository'])
                ||!is_string($source['tag']??null)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,99}$/D',$source['tag'])
                ||!is_int($source['created_at']??null)||!is_int($source['size']??null)
                ||$source['size']<1||$source['size']>self::MAX_ARTIFACT_BYTES
                ||!is_string($source['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$source['sha256'])){
                throw new RuntimeException('update_metadata_invalid');
            }
            $archivePath=$real.DIRECTORY_SEPARATOR.'source.tar.gz';
            if(!is_file($archivePath)||is_link($archivePath))throw new RuntimeException('update_artifact_invalid');
            if($verify){
                $size=filesize($archivePath);$hash=hash_file('sha256',$archivePath);
                $magic=@file_get_contents($archivePath,false,null,0,2);
                if(!is_int($size)||!is_string($hash)||$size!==$source['size']||!hash_equals($source['sha256'],$hash)
                    ||$magic!=="\x1f\x8b"){
                    throw new RuntimeException('update_artifact_hash_invalid');
                }
            }
            $preparedRaw=@file_get_contents($real.DIRECTORY_SEPARATOR.'prepared.json',false,null,0,self::MAX_PREPARED_BYTES+1);
            $preparedRoot=null;
            if(is_string($preparedRaw)){
                try{$preparedData=json_decode($preparedRaw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){$preparedData=null;}
                if(is_array($preparedData)&&is_string($preparedData['root_hash']??null)
                    &&preg_match('/^[a-f0-9]{64}$/D',$preparedData['root_hash']))$preparedRoot=$preparedData['root_hash'];
            }
            $prepared=$preparedRoot===null?null:$this->preparedStatus($real.DIRECTORY_SEPARATOR.'prepared.json',$release,$preparedRoot);
            return [
                'release'=>$release,'valid'=>true,'source_archive'=>true,'repository'=>$source['repository'],'tag'=>$source['tag'],
                'artifact_sha256'=>$source['sha256'],'artifact_size'=>$source['size'],'artifact_format'=>'tar.gz',
                'manifest_root_hash'=>$preparedRoot,'migrations'=>[],'created_at'=>$source['created_at'],
                'staged_at'=>(int)@filemtime($archivePath),'verified'=>$verify,'prepared'=>$prepared,
            ];
        }
        $metadataRaw=@file_get_contents($real.DIRECTORY_SEPARATOR.'metadata.json',false,null,0,self::MAX_METADATA_BYTES+1);
        $signature=trim((string)@file_get_contents($real.DIRECTORY_SEPARATOR.'metadata.sig',false,null,0,self::MAX_SIGNATURE_BYTES+1));
        if(!is_string($metadataRaw)||$metadataRaw===''||strlen($metadataRaw)>self::MAX_METADATA_BYTES)throw new RuntimeException('update_metadata_invalid');
        $signatureRaw=base64_decode($signature,true);
        if(!is_string($signatureRaw)||strlen($signatureRaw)!==SODIUM_CRYPTO_SIGN_BYTES
            ||!sodium_crypto_sign_verify_detached($signatureRaw,$metadataRaw,$this->publicKey()))throw new RuntimeException('update_signature_invalid');
        $metadata=$this->decodeMetadata($metadataRaw,$release);
        $artifactPath=$real.DIRECTORY_SEPARATOR.'artifact.tar.gz';
        if(!is_file($artifactPath)||is_link($artifactPath))throw new RuntimeException('update_artifact_invalid');
        if($verify){
            $size=filesize($artifactPath);$hash=hash_file('sha256',$artifactPath);
            $magic=@file_get_contents($artifactPath,false,null,0,2);
            if($magic!=="\x1f\x8b")throw new RuntimeException('update_artifact_invalid');
            if(!is_int($size)||!is_string($hash)||$size!==$metadata['artifact']['size']||!hash_equals($metadata['artifact']['sha256'],$hash)){
                throw new RuntimeException('update_artifact_hash_invalid');
            }
        }
        return [
            'release'=>$release,'valid'=>true,'artifact_sha256'=>$metadata['artifact']['sha256'],
            'artifact_size'=>$metadata['artifact']['size'],'artifact_format'=>$metadata['artifact']['format'],
            'manifest_root_hash'=>$metadata['release_manifest_root_hash'],
            'migrations'=>$metadata['migrations'],'created_at'=>$metadata['created_at'],
            'staged_at'=>(int)@filemtime($artifactPath),'verified'=>$verify,
            'prepared'=>$this->preparedStatus($real.DIRECTORY_SEPARATOR.'prepared.json',$release,$metadata['release_manifest_root_hash']),
        ];
    }

    private function decodeMetadata(string $raw,?string $release): array
    {
        try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('update_metadata_invalid');}
        if(!is_array($value)||($value['format']??null)!==self::FORMAT
            ||!is_string($value['release']??null)||!preg_match('/^[a-f0-9]{40}$/D',$value['release'])
            ||($release!==null&&$value['release']!==$release)
            ||!is_int($value['created_at']??null)||!is_string($value['release_manifest_root_hash']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$value['release_manifest_root_hash'])
            ||!is_array($value['artifact']??null)||!is_array($value['migrations']??null)
            ||count($value['migrations'])>500)throw new RuntimeException('update_metadata_invalid');
        $artifact=$value['artifact'];
        if(!is_string($artifact['url']??null)||!is_string($artifact['sha256']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$artifact['sha256'])||!is_int($artifact['size']??null)
            ||$artifact['size']<1||$artifact['size']>self::MAX_ARTIFACT_BYTES||($artifact['format']??null)!=='tar.gz'){
            throw new RuntimeException('update_metadata_invalid');
        }
        if(!GitHubUpdateSource::isArtifactUrl($artifact['url']))$this->url($artifact['url']);
        $migrations=[];
        foreach($value['migrations']as$migration){
            if(!is_array($migration)||!is_string($migration['version']??null)
                ||!preg_match('/^[0-9]{4}_[A-Za-z0-9_]+\.php$/D',$migration['version'])
                ||!is_string($migration['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$migration['sha256'])){
                throw new RuntimeException('update_metadata_invalid');
            }
            $migrations[]=['version'=>$migration['version'],'sha256'=>$migration['sha256']];
        }
        $value['migrations']=$migrations;
        return $value;
    }

    private function fetch(string $url,int $maximum): array
    {
        [$host]=$this->url($url);
        if($this->fetcher!==null){
            $result=($this->fetcher)($url,$maximum);
            if(!is_array($result)||!is_string($result['body']??null)||strlen($result['body'])>$maximum
                ||!is_int($result['status']??null)||$result['status']<200||$result['status']>=300){
                throw new RuntimeException('update_download_failed');
            }
            return ['body'=>$result['body'],'status'=>$result['status']];
        }
        $response=UpdateHttp::request($url,$host,$maximum,[],$this->caBundle?:getenv('D2S_CA_BUNDLE')?:null);
        if($response['status']<200||$response['status']>=300)throw new RuntimeException('update_download_failed');
        return $response;
    }

    private function url(string $url): array
    {
        if(strlen($url)>2048||str_contains($url,"\0"))throw new RuntimeException('update_url_invalid');
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'||!is_string($parts['host']??null)
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
            ||(isset($parts['port'])&&(int)$parts['port']!==443))throw new RuntimeException('update_url_invalid');
        $host=strtolower(rtrim($parts['host'],'.'));
        if(!in_array($host,$this->allowedHosts(),true))throw new RuntimeException('update_host_denied');
        return [$host,(int)($parts['port']??443)];
    }



    private function publicKey(): string
    {
        if($this->decodedPublicKey!==null)return $this->decodedPublicKey;
        $encoded=trim($this->publicKeyEncoded??(string)getenv('D2S_UPDATE_PUBLIC_KEY'));
        $key=base64_decode($encoded,true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('update_public_key_invalid');
        return $this->decodedPublicKey=$key;
    }

    private function publicKeyState(): array
    {
        try{$key=$this->publicKey();}catch(\Throwable){return ['ready'=>false,'fingerprint'=>null];}
        return ['ready'=>true,'fingerprint'=>substr(hash('sha256',$key),0,16)];
    }

    private function allowedHosts(): array
    {
        $hosts=[];
        foreach(explode(',',$this->allowedHosts??(string)getenv('D2S_UPDATE_ALLOWED_HOSTS'))as$host){
            $host=strtolower(trim($host));
            if($host==='')continue;
            if(!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)
                &&filter_var($host,FILTER_VALIDATE_IP)===false)continue;
            $hosts[]=$host;
        }
        return array_values(array_unique($hosts));
    }

    private function preparedStatus(string $path,string $release,string $expectedRootHash): ?array
    {
        if(!is_file($path)||is_link($path))return null;
        try{$candidate=$this->candidatePath().DIRECTORY_SEPARATOR.$release;$available=is_dir($candidate)&&!is_link($candidate);}
        catch(\Throwable){$available=false;}
        $raw=@file_get_contents($path,false,null,0,self::MAX_PREPARED_BYTES+1);
        if(!is_string($raw)||$raw===''||strlen($raw)>self::MAX_PREPARED_BYTES){
            return ['format'=>'d2s-prepared-update-v1','release'=>$release,'candidate_available'=>$available,
                'ready'=>false,'error'=>'prepared_record_invalid'];
        }
        try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){
            return ['format'=>'d2s-prepared-update-v1','release'=>$release,'candidate_available'=>$available,
                'ready'=>false,'error'=>'prepared_record_invalid'];
        }
        $valid=is_array($value)&&($value['format']??null)==='d2s-prepared-update-v1'
            &&($value['release']??null)===$release&&is_int($value['prepared_at']??null)
            &&is_int($value['file_count']??null)&&is_string($value['root_hash']??null)
            &&preg_match('/^[a-f0-9]{64}$/D',$value['root_hash'])===1
            &&hash_equals($expectedRootHash,$value['root_hash'])
            &&is_int($value['migration_count']??null)&&is_int($value['php_files_linted']??null);
        if(!$valid){
            return ['format'=>'d2s-prepared-update-v1','release'=>$release,'candidate_available'=>$available,
                'ready'=>false,'error'=>'prepared_record_invalid'];
        }
        return [
            'format'=>'d2s-prepared-update-v1','release'=>$release,'prepared_at'=>$value['prepared_at'],
            'file_count'=>$value['file_count'],'root_hash'=>$value['root_hash'],
            'migration_count'=>$value['migration_count'],'php_files_linted'=>$value['php_files_linted'],
            'candidate_available'=>$available,'ready'=>$available,
        ];
    }

    private function extract(string $archivePath,string $destination,bool $sourceArchive=false): void
    {
        if(!is_file($archivePath)||is_link($archivePath))throw new RuntimeException('update_artifact_invalid');
        $inventory=$this->inspectTar($archivePath,$sourceArchive);
        try{$archive=new PharData($archivePath);}catch(\Throwable){throw new RuntimeException('update_artifact_invalid');}
        foreach($inventory as$item){
            $path=$item['path'];$size=$item['size'];
            $archivePathInTar=$item['archive_path']??$path;
            if(!isset($archive[$archivePathInTar]))throw new RuntimeException('update_artifact_invalid');
            $entry=$archive[$archivePathInTar];
            if($entry->isLink()||!$entry->isFile()||$entry->getSize()!==$size){
                throw new RuntimeException('update_artifact_invalid');
            }
            $absolute=$destination.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$path);
            $this->ensureDirectory(dirname($absolute),$destination);
            $input=@fopen($entry->getPathname(),'rb');
            $output=@fopen($absolute,'xb');
            if(!is_resource($input)||!is_resource($output)){
                if(is_resource($input))fclose($input);
                if(is_resource($output))fclose($output);
                throw new RuntimeException('update_archive_extract_failed');
            }
            $written=stream_copy_to_stream($input,$output);
            fclose($input);fclose($output);
            if(!is_int($written)||$written!==$size)throw new RuntimeException('update_archive_extract_failed');
            @chmod($absolute,UpdateSharedPermissions::fileMode());
        }
        if(!$sourceArchive&&!is_file($destination.DIRECTORY_SEPARATOR.'release-manifest.json')){
            throw new RuntimeException('update_release_manifest_missing');
        }
    }

    private function extractSourceArchive(string $archivePath,string $destination): void
    {
        $this->extract($archivePath,$destination,true);
    }

    private function inspectTar(string $path,bool $sourceArchive=false): array
    {
        $handle=@gzopen($path,'rb');
        if(!is_resource($handle))throw new RuntimeException('update_artifact_invalid');
        $files=[];$seen=[];$totalBytes=0;$entries=0;$terminated=false;$archiveRoot=null;
        try{
            while(true){
                $header=$this->readTarBytes($handle,512);
                if(trim($header,"\0")===''){
                    $second=$this->readTarBytes($handle,512);
                    if(trim($second,"\0")!=='')throw new RuntimeException('update_artifact_invalid');
                    $terminated=true;break;
                }
                $entries++;
                if($entries>self::MAX_ARCHIVE_ENTRIES)throw new RuntimeException('update_archive_file_limit');
                $checksumField=substr($header,148,8);
                $storedChecksum=trim(str_replace("\0",' ',$checksumField));
                if($storedChecksum===''||!preg_match('/^[0-7]+$/D',$storedChecksum))throw new RuntimeException('update_artifact_invalid');
                $checksumInput=substr_replace($header,str_repeat(' ',8),148,8);
                if(octdec($storedChecksum)!==array_sum(unpack('C*',$checksumInput)))throw new RuntimeException('update_artifact_invalid');
                $name=rtrim(substr($header,0,100),"\0");
                $prefix=rtrim(substr($header,345,155),"\0");
                $rawPath=$prefix!==''?$prefix.'/'.$name:$name;
                if(str_contains($rawPath,'\\'))throw new RuntimeException('update_archive_path_invalid');
                $archivePath=$rawPath;
                if($sourceArchive){
                    $segments=explode('/',rtrim($rawPath,'/'));
                    if(!preg_match('/^[A-Za-z0-9_.-]{1,180}$/D',$segments[0])
                        ||$segments[0]==='.'||$segments[0]==='..'){
                        throw new RuntimeException('update_archive_path_invalid');
                    }
                    if($archiveRoot===null)$archiveRoot=$segments[0];
                    if($segments[0]!==$archiveRoot)throw new RuntimeException('update_archive_path_invalid');
                    $rawPath=implode('/',array_slice($segments,1));
                }
                $sizeField=trim(str_replace("\0",' ',substr($header,124,12)));
                if($sizeField!==''&&!preg_match('/^[0-7]+$/D',$sizeField))throw new RuntimeException('update_artifact_invalid');
                $size=$sizeField===''?0:(int)octdec($sizeField);
                $type=substr($header,156,1);
                $link=rtrim(substr($header,157,100),"\0");
                if($type==='5'){
                    if($size!==0||($sourceArchive?($rawPath!==''&&!$this->safeArchiveRelativePath($rawPath)):!$this->safeArchiveDirectory($rawPath))){
                        throw new RuntimeException('update_archive_path_invalid');
                    }
                    continue;
                }
                if(($type!=='0'&&$type!=="\0")||$link!==''||($sourceArchive
                    ?($rawPath===''||!$this->safeArchiveRelativePath($rawPath))
                    :!$this->safeArchivePath($rawPath))){
                    throw new RuntimeException('update_archive_path_invalid');
                }
                if($sourceArchive&&$rawPath!=='composer.json'&&$rawPath!=='composer.lock'
                    &&!ReleaseManifest::isRuntimePath($rawPath)){
                    if($size<0||$size>self::MAX_UNCOMPRESSED_BYTES-$totalBytes)throw new RuntimeException('update_archive_size_limit');
                    $totalBytes+=$size;$this->skipTarBytes($handle,$size);continue;
                }
                if($sourceArchive){
                    $archiveKey=strtolower($archivePath);
                    if(isset($seen[$archiveKey]))throw new RuntimeException('update_archive_duplicate');
                    $seen[$archiveKey]=true;
                }
                $filesCount=count($files)+1;
                if($filesCount>self::MAX_ARCHIVE_FILES)throw new RuntimeException('update_archive_file_limit');
                if($size<0||$size>self::MAX_UNCOMPRESSED_BYTES-$totalBytes)throw new RuntimeException('update_archive_size_limit');
                $key=strtolower($rawPath);
                if(isset($seen[$key]))throw new RuntimeException('update_archive_duplicate');
                foreach(array_keys($seen)as$existing){
                    if(str_starts_with($key,$existing.'/')||str_starts_with($existing,$key.'/')){
                        throw new RuntimeException('update_archive_path_conflict');
                    }
                }
                $seen[$key]=true;$totalBytes+=$size;
                $files[]=['path'=>$rawPath,'archive_path'=>$archivePath,'size'=>$size];
                $this->skipTarBytes($handle,$size);
            }
        }finally{gzclose($handle);}
        if(!$terminated||$files===[])throw new RuntimeException('update_artifact_invalid');
        return $files;
    }

    private function readTarBytes($handle,int $length): string
    {
        $value='';
        while(strlen($value)<$length){
            $chunk=gzread($handle,min(8192,$length-strlen($value)));
            if(!is_string($chunk)||$chunk==='')throw new RuntimeException('update_artifact_invalid');
            $value.=$chunk;
        }
        return $value;
    }

    private function skipTarBytes($handle,int $length): void
    {
        $padded=$length+(512-($length%512))%512;
        while($padded>0){
            $chunk=gzread($handle,min(8192,$padded));
            if(!is_string($chunk)||$chunk==='')throw new RuntimeException('update_artifact_invalid');
            $padded-=strlen($chunk);
        }
    }

    private function safeArchiveRelativePath(string $path): bool
    {
        if($path===''||strlen($path)>1024||str_contains($path,"\0")||str_contains($path,'\\')
            ||str_starts_with($path,'/')||preg_match('/^[A-Za-z]:/D',$path))return false;
        foreach(explode('/',$path)as$segment){
            if($segment===''||$segment==='.'||$segment==='..')return false;
        }
        return true;
    }

    private function safeArchiveDirectory(string $path): bool
    {
        $path=rtrim($path,'/');
        return $this->safeArchiveRelativePath($path)
            &&ReleaseManifest::isRuntimePath($path.'/__d2s_directory__');
    }

    private function safeArchivePath(string $path): bool
    {
        return $this->safeArchiveRelativePath($path)
            &&(in_array($path,['release-manifest.json','composer.json','composer.lock'],true)
                ||ReleaseManifest::isRuntimePath($path));
    }

    private function ensureDirectory(string $directory,string $root): void
    {
        $root=$this->normalizeAbsolutePath($root);
        $directory=$this->normalizeAbsolutePath($directory);
        $rootKey=rtrim($this->pathKey($root),DIRECTORY_SEPARATOR);
        $directoryKey=$this->pathKey($directory);
        if($directoryKey!==$rootKey&&!str_starts_with($directoryKey,$rootKey.DIRECTORY_SEPARATOR)){
            throw new RuntimeException('update_archive_path_invalid');
        }
        if(is_dir($directory)&&!is_link($directory))return;
        if(is_link($directory)||file_exists($directory))throw new RuntimeException('update_archive_path_conflict');
        $relative=substr($directory,strlen($root));
        $current=$root;
        foreach(explode(DIRECTORY_SEPARATOR,ltrim($relative,DIRECTORY_SEPARATOR))as$segment){
            if($segment==='')continue;
            $current.=$current===DIRECTORY_SEPARATOR?$segment:DIRECTORY_SEPARATOR.$segment;
            if(is_link($current)||(file_exists($current)&&!is_dir($current))){
                throw new RuntimeException('update_archive_path_conflict');
            }
            if(!is_dir($current)&&!mkdir($current,UpdateSharedPermissions::directoryMode())&&!is_dir($current)){
                throw new RuntimeException('update_archive_extract_failed');
            }
        }
    }

    private function copyCurrentVendor(string $destination): void
    {
        $currentLock=$this->root.DIRECTORY_SEPARATOR.'composer.lock';
        $candidateLock=$destination.DIRECTORY_SEPARATOR.'composer.lock';
        $source=$this->root.DIRECTORY_SEPARATOR.'vendor';
        $target=$destination.DIRECTORY_SEPARATOR.'vendor';
        if(!is_file($currentLock)||is_link($currentLock)||!is_file($candidateLock)||is_link($candidateLock)
            ||!is_dir($source)||is_link($source)||file_exists($target)){
            throw new RuntimeException('update_dependencies_changed');
        }
        $currentHash=hash_file('sha256',$currentLock);$candidateHash=hash_file('sha256',$candidateLock);
        if(!is_string($currentHash)||!is_string($candidateHash)||!hash_equals($currentHash,$candidateHash)){
            throw new RuntimeException('update_dependencies_changed');
        }
        if(!mkdir($target,UpdateSharedPermissions::directoryMode()))throw new RuntimeException('update_candidate_unavailable');
        $iterator=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach($iterator as$item){
            if($item->isLink())throw new RuntimeException('update_dependencies_invalid');
            $relative=substr($item->getPathname(),strlen($source)+1);
            $copyTo=$target.DIRECTORY_SEPARATOR.$relative;
            if($item->isDir()){
                if(!is_dir($copyTo)&&!mkdir($copyTo,UpdateSharedPermissions::directoryMode()))throw new RuntimeException('update_dependencies_copy_failed');
                continue;
            }
            if(!$item->isFile()||!copy($item->getPathname(),$copyTo)){
                throw new RuntimeException('update_dependencies_copy_failed');
            }
            @chmod($copyTo,UpdateSharedPermissions::fileMode());
        }
    }

    private function migrationInventory(string $root): array
    {
        $directory=$root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if(!is_dir($directory)||is_link($directory))throw new RuntimeException('update_migration_inventory_invalid');
        $result=[];
        foreach(glob($directory.DIRECTORY_SEPARATOR.'*.php')?:[]as$file){
            $version=basename($file);
            if(!is_file($file)||is_link($file)||!preg_match('/^[0-9]{4}_[A-Za-z0-9_]+\.php$/D',$version)){
                throw new RuntimeException('update_migration_inventory_invalid');
            }
            $hash=hash_file('sha256',$file);
            if(!is_string($hash))throw new RuntimeException('update_migration_inventory_invalid');
            $result[]=['version'=>$version,'sha256'=>$hash];
        }
        usort($result,static fn(array $a,array $b):int=>strcmp($a['version'],$b['version']));
        return $result;
    }

    private function verifyMigrations(string $root,array $migrations): void
    {
        $directory=$root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if(!is_dir($directory)||is_link($directory))throw new RuntimeException('update_migration_inventory_invalid');
        $expected=[];
        foreach($migrations as$migration){
            $version=$migration['version'];
            if(isset($expected[$version]))throw new RuntimeException('update_migration_inventory_invalid');
            $expected[$version]=$migration['sha256'];
        }
        $files=glob($directory.DIRECTORY_SEPARATOR.'*.php')?:[];
        $found=[];
        foreach($files as$file){
            if(!is_file($file)||is_link($file))throw new RuntimeException('update_migration_inventory_invalid');
            $found[basename($file)]=true;
        }
        $expectedNames=array_keys($expected);$foundNames=array_keys($found);
        sort($expectedNames,SORT_STRING);sort($foundNames,SORT_STRING);
        if($expectedNames!==$foundNames)throw new RuntimeException('update_migration_inventory_mismatch');
        foreach($expected as$version=>$hash){
            $file=$directory.DIRECTORY_SEPARATOR.$version;
            $actual=is_readable($file)?hash_file('sha256',$file):false;
            if(!is_string($actual)||!hash_equals($hash,$actual))throw new RuntimeException('update_migration_hash_mismatch');
        }
    }

    private function lintPhp(string $root): int
    {
        $count=0;
        $iterator=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach($iterator as$file){
            if($file->isLink())throw new RuntimeException('update_php_symlink_invalid');
            if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
            $size=$file->getSize();
            if(!is_int($size)||$size>self::MAX_PHP_SOURCE_BYTES)throw new RuntimeException('update_php_file_too_large');
            $source=@file_get_contents($file->getPathname());
            if(!is_string($source))throw new RuntimeException('update_php_unreadable');
            try{PhpToken::tokenize($source,TOKEN_PARSE);}catch(\ParseError){throw new RuntimeException('update_php_syntax_invalid');}
            $count++;
        }
        return $count;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        if($path===''||str_contains($path,"\0"))throw new RuntimeException('update_path_invalid');
        $path=str_replace(['/','\\'],DIRECTORY_SEPARATOR,$path);
        if(!str_starts_with($path,DIRECTORY_SEPARATOR)&&!preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR,'/').'/D',$path)){
            $path=$this->root.DIRECTORY_SEPARATOR.$path;
        }
        if(str_starts_with($path,DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR))throw new RuntimeException('update_path_invalid');
        $prefix='';$rest=$path;
        if(preg_match('/^([A-Za-z]:)'.preg_quote(DIRECTORY_SEPARATOR,'/').'/D',$path,$matches)){
            $prefix=strtoupper($matches[1]);$rest=substr($path,3);
        }elseif(str_starts_with($path,DIRECTORY_SEPARATOR)){
            $prefix=DIRECTORY_SEPARATOR;$rest=ltrim($path,DIRECTORY_SEPARATOR);
        }
        $parts=[];
        foreach(explode(DIRECTORY_SEPARATOR,$rest)as$part){
            if($part===''||$part==='.')continue;
            if($part==='..'){
                if($parts===[])throw new RuntimeException('update_path_invalid');
                array_pop($parts);continue;
            }
            if(str_contains($part,':'))throw new RuntimeException('update_path_invalid');
            $parts[]=$part;
        }
        if($parts===[])throw new RuntimeException('update_path_invalid');
        return $prefix.($prefix===DIRECTORY_SEPARATOR?'':DIRECTORY_SEPARATOR).implode(DIRECTORY_SEPARATOR,$parts);
    }

    private function pathKey(string $path): string
    {
        return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
    }

    private function assertPrivatePath(string $path,string $error): void
    {
        $public=$this->normalizeAbsolutePath($this->root.'/public');
        $publicReal=realpath($public);
        $publicKeys=[$this->pathKey($public)];
        if($publicReal!==false)$publicKeys[]=$this->pathKey($publicReal);
        $key=$this->pathKey($path);
        foreach($publicKeys as$publicKey){
            if($key===$publicKey||str_starts_with($key,$publicKey.DIRECTORY_SEPARATOR))throw new RuntimeException($error);
        }
        $probe=$path;
        while(!file_exists($probe)){
            $parent=dirname($probe);
            if($parent===$probe)break;
            $probe=$parent;
        }
        $real=realpath($probe);
        if($real!==false){
            $realKey=$this->pathKey($real);
            foreach($publicKeys as$publicKey){
                if($realKey===$publicKey||str_starts_with($realKey,$publicKey.DIRECTORY_SEPARATOR))throw new RuntimeException($error);
            }
        }
    }

    private function candidatePath(): string
    {
        $path=$this->candidateRoot??(getenv('D2S_UPDATE_CANDIDATE_DIR')?:$this->root.'/storage/updates/candidates');
        $path=$this->normalizeAbsolutePath($path);
        $this->assertPrivatePath($path,'update_candidate_public_path');
        return $path;
    }

    private function assertCandidatePath(string $candidate,string $release): void
    {
        $root=$this->candidatePath();
        $expected=$this->normalizeAbsolutePath($root.DIRECTORY_SEPARATOR.$release);
        if($this->pathKey($candidate)!==$this->pathKey($expected))throw new RuntimeException('update_candidate_path_invalid');
        $this->assertPrivatePath($candidate,'update_candidate_public_path');
    }

    private function destinationWritable(string $path): bool
    {
        $candidate=$path;
        while(!file_exists($candidate)){
            $parent=dirname($candidate);
            if($parent===$candidate)return false;
            $candidate=$parent;
        }
        return is_dir($candidate)&&is_writable($candidate);
    }

    private function removeTree(string $path): void
    {
        if(is_link($path)||is_file($path)){@unlink($path);return;}
        if(!is_dir($path))return;
        $items=scandir($path);
        if(!is_array($items))throw new RuntimeException('update_candidate_cleanup_failed');
        foreach($items as$item){
            if($item==='.'||$item==='..')continue;
            $child=$path.DIRECTORY_SEPARATOR.$item;
            if(is_link($child)||is_file($child)){if(!@unlink($child))throw new RuntimeException('update_candidate_cleanup_failed');}
            elseif(is_dir($child))$this->removeTree($child);
        }
        if(!@rmdir($path))throw new RuntimeException('update_candidate_cleanup_failed');
    }

    private function stagingPath(): string
    {
        $path=$this->stagingRoot??(getenv('D2S_UPDATE_STAGING_DIR')?:$this->root.'/storage/updates');
        $path=$this->normalizeAbsolutePath($path);
        $this->assertPrivatePath($path,'update_staging_public_path');
        return $path;
    }

    private function write(string $path,string $contents): void
    {
        if(file_put_contents($path,$contents,LOCK_EX)!==strlen($contents))throw new RuntimeException('update_staging_write_failed');
        @chmod($path,UpdateSharedPermissions::fileMode());
    }
}
