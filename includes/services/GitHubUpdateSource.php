<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use RuntimeException;

final class GitHubUpdateSource
{
    private const DEFAULT_REPOSITORY='laiyangli001/desktop2stereo-portal';
    private const ASSETS=['metadata.json','metadata.sig','artifact.tar.gz'];
    private const DOWNLOAD_HOSTS=['release-assets.githubusercontent.com','objects.githubusercontent.com','github-releases.githubusercontent.com'];

    public function __construct(private readonly OnlineUpdate $update,private readonly ?Closure $requester=null,
        private readonly mixed $address=null) {}

    public function repository(): string
    {
        $address=$this->address??(getenv('D2S_UPDATE_GITHUB_REPOSITORY')?:self::DEFAULT_REPOSITORY);
        $repository='([A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99})';
        if(!is_string($address)||(!preg_match('#^'.$repository.'$#D',trim($address),$match)
            &&!preg_match('#^https://github\.com/'.$repository.'(?:/releases(?:/latest)?)?/?$#iD',trim($address),$match))){
            throw new RuntimeException('update_github_repository_invalid');
        }
        return $match[1];
    }

    public static function isArtifactUrl(string $url): bool
    {
        return preg_match('#^https://github\.com/[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}/releases/download/[A-Za-z0-9._%+-]{1,200}/artifact\.tar\.gz$#D',$url)===1;
    }

    public function check(?string $current): array
    {
        return $this->candidate($current)['summary'];
    }

    public function download(?string $current,array $input): array
    {
        $release=$input['release']??null;
        $hash=$input['artifact_sha256']??null;
        if(!is_string($release)||!preg_match('/^[a-f0-9]{40}$/D',$release)
            ||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){
            throw new RuntimeException('update_github_selection_changed');
        }
        $candidate=$this->candidate($current);
        $summary=$candidate['summary'];
        if($summary['state']!=='available'||$summary['release']!==$release||!hash_equals($summary['artifact_sha256'],$hash)){
            throw new RuntimeException('update_github_selection_changed');
        }
        $body=$this->asset($candidate['artifact_id'],$summary['artifact_size']);
        return $this->update->stagePackage($candidate['metadata'],$candidate['signature'],$body,$release);
    }

    private function candidate(?string $current): array
    {
        $repo=$this->repository();
        $response=$this->api('/releases/latest');
        if($response['status']===404&&$this->api('')['status']===200){
            return ['summary'=>['state'=>'no_release','repository'=>$repo]];
        }
        $release=$this->json($response);
        $tag=$release['tag_name']??null;
        if(!is_string($tag)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,99}$/D',$tag)
            ||($release['draft']??true)!==false||($release['prerelease']??true)!==false
            ||!is_array($release['assets']??null)){
            throw new RuntimeException('update_github_unavailable');
        }
        $url='https://github.com/'.$repo.'/releases/tag/'.rawurlencode($tag);
        $summary=['repository'=>$repo,'tag'=>$tag,'url'=>$url,'published_at'=>$release['published_at']??null];
        $assets=[];
        foreach($release['assets'] as $asset){
            if(!is_array($asset)||!in_array($asset['name']??null,self::ASSETS,true)||($asset['state']??null)!=='uploaded')continue;
            $name=$asset['name'];
            if(isset($assets[$name])||!is_int($asset['id']??null)||$asset['id']<=0)throw new RuntimeException('update_github_unavailable');
            $assets[$name]=$asset;
        }
        if(count($assets)!==count(self::ASSETS))return ['summary'=>$summary+['state'=>'missing_assets']];
        $metadata=$this->asset($assets['metadata.json']['id'],1048576);
        $signature=$this->asset($assets['metadata.sig']['id'],4096);
        $verified=$this->update->verifyMetadata($metadata,$signature);
        $artifact=$verified['artifact'];
        $target=$verified['release'];
        $releasePrefix='https://github.com/'.$repo.'/releases/download/';
        if(strncasecmp($artifact['url'],$releasePrefix,strlen($releasePrefix))!==0
            ||substr($artifact['url'],strlen($releasePrefix))!==rawurlencode($tag).'/artifact.tar.gz'
            ||($assets['artifact.tar.gz']['size']??null)!==$artifact['size']){
            throw new RuntimeException('update_github_release_mismatch');
        }
        $tagCommit=$this->json($this->api('/commits/'.rawurlencode($tag)));
        if(($tagCommit['sha']??null)!==$target)throw new RuntimeException('update_github_release_mismatch');
        return [
            'summary'=>$summary+['state'=>$this->comparison($current,$target),'release'=>$target,
                'artifact_size'=>$artifact['size'],'artifact_sha256'=>$artifact['sha256']],
            'metadata'=>$metadata,'signature'=>$signature,'artifact_id'=>$assets['artifact.tar.gz']['id'],
        ];
    }

    private function comparison(?string $current,string $target): string
    {
        if($current===$target)return 'current';
        if($current===null||!preg_match('/^[a-f0-9]{40}$/D',$current))return 'unknown_version';
        $response=$this->api('/compare/'.$current.'...'.$target.'?per_page=1');
        if($response['status']===404)return 'unknown_version';
        return match($this->json($response)['status']??null){
            'ahead'=>'available','behind','diverged'=>'not_newer','identical'=>'current',
            default=>throw new RuntimeException('update_github_unavailable'),
        };
    }

    private function api(string $path): array
    {
        return $this->send('https://api.github.com/repos/'.$this->repository().$path,'application/vnd.github+json',2097152);
    }

    private function asset(int $id,int $maximum): string
    {
        $url='https://api.github.com/repos/'.$this->repository().'/releases/assets/'.$id;
        for($attempt=0;$attempt<3;$attempt++){
            $response=$this->send($url,'application/octet-stream',$maximum);
            if($response['status']===200)return $response['body'];
            if(!in_array($response['status'],[301,302,303,307,308],true)||!is_string($response['location']??null))break;
            $url=$response['location'];
            if(!in_array(parse_url($url,PHP_URL_HOST),self::DOWNLOAD_HOSTS,true))break;
        }
        throw new RuntimeException('update_download_failed');
    }

    private function send(string $url,string $accept,int $maximum): array
    {
        $parts=parse_url($url);
        $host=$parts['host']??null;
        if(strlen($url)>8192||preg_match('/[\x00-\x20\x7f]/',$url)
            ||($parts['scheme']??null)!=='https'||!is_string($host)
            ||(isset($parts['port'])&&$parts['port']!==443)||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
            ||($host!=='api.github.com'&&!in_array($host,self::DOWNLOAD_HOSTS,true))){
            throw new RuntimeException('update_github_unavailable');
        }
        $headers=['Accept: '.$accept];
        if($host==='api.github.com'){
            $headers[]='X-GitHub-Api-Version: 2022-11-28';
            $token=trim((string)getenv('D2S_GITHUB_TOKEN'));
            if($token!==''){
                if(!preg_match('/^[A-Za-z0-9_]+$/D',$token))throw new RuntimeException('update_github_unavailable');
                $headers[]='Authorization: Bearer '.$token;
            }
        }
        $response=$this->requester===null
            ?UpdateHttp::request($url,$host,$maximum,$headers,getenv('D2S_CA_BUNDLE')?:null,$maximum>2097152?60:10)
            :($this->requester)($url,$maximum,$headers);
        if(!is_array($response)||!is_int($response['status']??null)||!is_string($response['body']??null)
            ||strlen($response['body'])>$maximum)throw new RuntimeException('update_github_unavailable');
        return $response;
    }

    private function json(array $response): array
    {
        if($response['status']!==200)throw new RuntimeException('update_github_unavailable');
        try{$body=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new RuntimeException('update_github_unavailable');}
        if(!is_array($body))throw new RuntimeException('update_github_unavailable');
        return $body;
    }
}
