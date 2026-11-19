<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

final readonly class ReleaseInfo
{
    public function __construct(private string $cacheFile, private ?string $caBundle = null)
    {
    }

    public static function fromEnvironment(): self
    {
        $caBundle=getenv('D2S_CA_BUNDLE');
        return new self(getenv('D2S_RELEASE_CACHE') ?: D2S_ROOT.'/storage/cache/github-release.json',
            is_string($caBundle)&&$caBundle!==''?$caBundle:null);
    }

    public function current(array $site): ?array
    {
        if (!is_file($this->cacheFile)) return null;
        $raw=file_get_contents($this->cacheFile,false,null,0,65536);
        if (!is_string($raw)) return null;
        try {
            $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || ($data['repo'] ?? null)!==($site['github_repo'] ?? null) ||
            !is_string($data['version'] ?? null) || !preg_match('/^[A-Za-z0-9._+-]{1,80}$/D',$data['version'])) {
            return null;
        }
        $url=$data['url'] ?? null;
        if ($url!==null && (!is_string($url) || !str_starts_with($url,'https://github.com/'))) {
            $url=null;
        }
        return [
            'version'=>$data['version'],
            'published_at'=>$data['published_at'] ?? null,
            'url'=>$url,
            'fetched_at'=>(int)($data['fetched_at'] ?? 0),
            'stale'=>time()-(int)($data['fetched_at'] ?? 0)>3600,
        ];
    }

    public function refresh(array $site): array
    {
        $repo=trim((string)($site['github_repo'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D',$repo)) {
            throw new RuntimeException('github_repo_invalid');
        }
        if (!extension_loaded('curl')) throw new RuntimeException('curl_required');
        if ($this->caBundle!==null &&
            ((!str_starts_with($this->caBundle,DIRECTORY_SEPARATOR) && !preg_match('#^[A-Za-z]:[\\\\/]#D',$this->caBundle)) ||
            !is_file($this->caBundle) || !is_readable($this->caBundle))) {
            throw new RuntimeException('github_ca_bundle_unavailable');
        }
        $url='https://api.github.com/repos/'.$repo.'/releases/latest';
        $curl=curl_init($url);
        if ($curl===false) throw new RuntimeException('github_request_failed');
        $headers=['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28','User-Agent: Desktop2Stereo-Portal'];
        $token=getenv('D2S_GITHUB_TOKEN');
        if (is_string($token) && $token!=='') $headers[]='Authorization: Bearer '.$token;
        $options=[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_USERAGENT=>'Desktop2Stereo-Portal',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        ];
        if ($this->caBundle!==null) {
            $options[CURLOPT_CAINFO]=$this->caBundle;
        }
        curl_setopt_array($curl,$options);
        $body=curl_exec($curl);
        $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || $status!==200 || strlen($body)>1048576) {
            throw new RuntimeException('github_release_unavailable');
        }
        try {
            $release=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('github_release_invalid');
        }
        $tag=is_string($release['tag_name'] ?? null)?trim($release['tag_name']):'';
        $version=preg_replace('/^[vV]/','',$tag);
        $html=is_string($release['html_url'] ?? null)?$release['html_url']:'';
        if (!preg_match('/^[A-Za-z0-9._+-]{1,80}$/D',(string)$version) ||
            !str_starts_with($html,'https://github.com/'.strtolower((string)$repo).'/releases/')) {
            throw new RuntimeException('github_release_invalid');
        }
        $payload=['repo'=>$repo,'version'=>$version,'tag'=>$tag,'published_at'=>$release['published_at'] ?? null,
            'url'=>$html,'fetched_at'=>time()];
        if (!is_dir(dirname($this->cacheFile)) && !mkdir(dirname($this->cacheFile),0700,true) && !is_dir(dirname($this->cacheFile))) {
            throw new RuntimeException('release_cache_unavailable');
        }
        $temporary=$this->cacheFile.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false) {
            @unlink($temporary);
            throw new RuntimeException('release_cache_unavailable');
        }
        if (!rename($temporary,$this->cacheFile)) {
            if (!copy($temporary,$this->cacheFile)) {
                @unlink($temporary);
                throw new RuntimeException('release_cache_unavailable');
            }
            @unlink($temporary);
        }
        chmod($this->cacheFile,0600);
        return $payload;
    }
}
