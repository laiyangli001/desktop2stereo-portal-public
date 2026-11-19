<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use DOMDocument;
use DOMXPath;
use Closure;

final readonly class DeepLTranslationProvider implements TranslationProvider
{
    /** @internal Injected only by deterministic tests; production uses the cURL transport. */
    public function __construct(private string $apiKey, private ?Closure $requestHandler = null) {}

    public function translateArticle(array $source,string $sourceLocale,string $targetLocale,array $targetMap,array $glossary=[]): array
    {
        $target=$targetMap[$targetLocale]??null;
        if(!is_string($target)||!preg_match('/^[A-Z]{2,3}(?:-[A-Z]{2,3})?$/D',$target))throw new Problem('translation_language_mapping_missing',409);
        $languageSubtag=strtolower(explode('-',$sourceLocale,2)[0]);
        $sourceCode=$languageSubtag==='zh'?'ZH':strtoupper($languageSubtag);
        if(!preg_match('/^[A-Z]{2,3}$/D',$sourceCode))throw new Problem('translation_source_language_unsupported',409);
        $html=($source['body_format']??'html')!=='plain';
        $values=[];
        foreach(['title','summary','body']as$field){
            $value=$source[$field]??'';
            if(!is_string($value))throw new Problem('translation_source_invalid');
            if($field==='body'&&$html)$value=ArticleContent::clean($value,true);
            if($value!=='')$values[$field]=$value;
        }
        if(!isset($values['title'],$values['body']))throw new Problem('translation_source_invalid');
        $requestValues=[];$tokenMaps=[];
        foreach($values as$field=>$value){
            if($html&&$field==='body'){
                $value=self::protectCode($value);
                [$value,$tokenMaps[$field]]=GlossaryProtector::protectHtml($value,$glossary);
            }else{
                [$value,$tokenMaps[$field]]=GlossaryProtector::protectText($value,$glossary);
                if($html)$value=htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
            }
            $requestValues[$field]=$value;
        }
        $free=str_ends_with(strtolower($this->apiKey),':fx');
        $url=$free?'https://api-free.deepl.com/v2/translate':'https://api.deepl.com/v2/translate';
        $form=['source_lang'=>$sourceCode,'target_lang'=>$target];
        if($html){$form['tag_handling']='html';$form['tag_handling_version']='v2';}
        $parts=[];
        foreach($form as$key=>$value)$parts[]=rawurlencode($key).'='.rawurlencode($value);
        foreach($requestValues as$value)$parts[]='text='.rawurlencode($value);
        $payload=implode('&',$parts);
        $headers=['Authorization: DeepL-Auth-Key '.$this->apiKey,'Content-Type: application/x-www-form-urlencoded'];
        if($this->requestHandler!==null){
            try{$response=($this->requestHandler)($url,$payload,$headers,8,35);}catch(\Throwable){
                throw new Problem('translation_provider_unavailable',503);
            }
            if(!is_array($response)||!array_key_exists('body',$response)||!is_int($response['status']??null)||!is_int($response['error']??null)||(!is_string($response['body'])&&$response['body']!==false))
                throw new Problem('translation_provider_response_invalid',502);
            $body=$response['body'];$status=$response['status'];$error=$response['error'];
        }else{
            $curl=curl_init($url);
            if($curl===false)throw new Problem('translation_transport_unavailable',503);
            curl_setopt_array($curl,[
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>35,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            ]);
            $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_errno($curl);
            curl_close($curl);
        }
        if($body===false||$error!==0)throw new Problem('translation_provider_unavailable',503);
        if(strlen($body)>524288)throw new Problem('translation_provider_response_invalid',502);
        if($status===429||$status>=500)throw new Problem('translation_provider_temporary',503);
        if($status<200||$status>=300)throw new Problem('translation_provider_rejected',422);
        try{$decoded=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('translation_provider_response_invalid',502);}
        $items=$decoded['translations']??null;
        if(!is_array($items)||count($items)!==count($requestValues))throw new Problem('translation_provider_response_invalid',502);
        $result=['title'=>'','summary'=>'','body'=>''];$index=0;
        foreach($requestValues as$field=>$original){
            $value=$items[$index]['text']??null;$index++;
            if(!is_string($value)||trim($value)==='')throw new Problem('translation_provider_response_invalid',502);
            if($field==='body'){
                if($html&&!self::sameHtmlStructure($original,$value))throw new Problem('translation_result_structure_invalid',422);
                $value=$html?GlossaryProtector::restoreHtml($value,$tokenMaps[$field]??[]):GlossaryProtector::restoreText($value,$tokenMaps[$field]??[]);
                $value=$html?ArticleContent::clean($value,true):$value;
            }else{
                if($html)$value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
                $value=GlossaryProtector::restoreText($value,$tokenMaps[$field]??[]);
            }
            $result[$field]=$value;
        }
        return $result;
    }

    public function translateProduct(array $source,string $sourceLocale,string $targetLocale,array $targetMap,array $glossary=[]): array
    {
        $translated=$this->translateArticle([
            'title'=>(string)($source['title']??''),'summary'=>'',
            'body'=>(string)($source['description']??''),'body_format'=>'plain',
            'glossary'=>$glossary,
        ],$sourceLocale,$targetLocale,$targetMap,$glossary);
        return ['title'=>$translated['title'],'description'=>$translated['body']];
    }

    private static function protectCode(string $html): string
    {
        $previous=libxml_use_internal_errors(true);
        try{
            $document=new DOMDocument('1.0','UTF-8');
            if(!$document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',LIBXML_NONET))
                throw new Problem('translation_source_invalid');
            $xpath=new DOMXPath($document);
            foreach($xpath->query('/html/body//pre | /html/body//code')as$node)$node->setAttribute('translate','no');
            $body=$document->getElementsByTagName('body')->item(0);
            if($body===null)throw new Problem('translation_source_invalid');
            $result='';
            foreach($body->childNodes as$node)$result.=$document->saveHTML($node);
            return $result;
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }

    private static function sameHtmlStructure(string $source,string $translated): bool
    {
        $signature=static function(string $html): ?array{
            $previous=libxml_use_internal_errors(true);
            try{
                $document=new DOMDocument('1.0','UTF-8');
                if(!$document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',LIBXML_NONET))return null;
                $xpath=new DOMXPath($document);$tags=[];$links=[];$protected=[];
                foreach($xpath->query('/html/body//*')as$node){
                    $tags[]=$node->nodeName;
                    if($node->nodeName==='a')$links[]=$node->getAttribute('href');
                    if(in_array($node->nodeName,['code','pre'],true))$protected[]=$node->textContent;
                }
                return [$tags,$links,$protected];
            }catch(\Throwable){return null;}
            finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        };
        $before=$signature($source);$after=$signature($translated);
        return $before!==null&&$after!==null&&$before===$after;
    }
}
