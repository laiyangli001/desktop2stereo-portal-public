<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\Problem;
use DOMDocument;
use DOMXPath;
use HTMLPurifier;
use HTMLPurifier_Config;

final class ArticleContent
{
    public const MAX_CHARACTERS=14000;
    public const MAX_HTML_BYTES=131072;
    private static ?HTMLPurifier $purifier=null;

    public static function clean(mixed $source,bool $required=false): string
    {
        if(!is_string($source)||!mb_check_encoding($source,'UTF-8')||strlen($source)>self::MAX_HTML_BYTES||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$source))throw new Problem('article_content_invalid');
        if($source===''){
            if($required)throw new Problem('article_body_required');
            return '';
        }
        $html=self::purifier()->purify(self::normalizeAlignment($source));
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(mb_strlen($text,'UTF-8')>self::MAX_CHARACTERS)throw new Problem('article_content_too_long');
        $visible=trim(preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u',' ',$text)??'');
        if($visible===''){
            if($required)throw new Problem('article_body_required');
            return '';
        }
        return $html;
    }

    public static function render(string $source,string $format): string
    {
        return $format==='html'?self::clean($source):htmlspecialchars($source,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function purifier(): HTMLPurifier
    {
        if(self::$purifier!==null)return self::$purifier;
        $config=HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding','UTF-8');
        $config->set('HTML.Doctype','HTML 4.01 Transitional');
        $config->set('HTML.TidyLevel','none');
        $config->set('HTML.Allowed','p[class],br,strong,b,em,i,u,s,del,blockquote,pre,code,h1[class],h2[class],h3[class],h4[class],h5[class],h6[class],ul,ol[start],li[class],a[href|title],span[class],div[class]');
        $config->set('Attr.AllowedClasses',array_merge(['ql-align-center','ql-align-right','ql-align-justify','ql-direction-rtl'],
            array_map(static fn(int $number):string=>'ql-indent-'.$number,range(1,8))));
        $config->set('URI.AllowedSchemes',['http'=>true,'https'=>true,'mailto'=>true]);
        $config->set('URI.DisableExternalResources',true);
        $config->set('HTML.ForbiddenElements',['script','style','iframe','object','embed','svg','math','form','input','button','textarea','select','video','audio','img','link','meta','base']);
        $config->set('Cache.DefinitionImpl',null);
        return self::$purifier=new HTMLPurifier($config);
    }

    /** Convert the editor's alignment styles into local CSS classes before final HTML purification. */
    private static function normalizeAlignment(string $source): string
    {
        $previous=libxml_use_internal_errors(true);
        try{
            $document=new DOMDocument('1.0','UTF-8');
            $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$source.'</body></html>',LIBXML_NONET);
            $xpath=new DOMXPath($document);
            foreach($xpath->query('//*[@style]')as$element){
                $classes=preg_split('/\s+/',trim($element->getAttribute('class')),-1,PREG_SPLIT_NO_EMPTY)?:[];
                foreach(explode(';',$element->getAttribute('style'))as$declaration){
                    [$property,$value]=array_pad(explode(':',$declaration,2),2,'');
                    if(strtolower(trim($property))==='text-align'&&in_array(strtolower(trim($value)),['center','right','justify'],true))$classes[]='ql-align-'.strtolower(trim($value));
                }
                $element->removeAttribute('style');
                if($classes!==[])$element->setAttribute('class',implode(' ',array_unique($classes)));
            }
            $body=$document->getElementsByTagName('body')->item(0);$html='';
            if($body!==null)foreach($body->childNodes as$node)$html.=$document->saveHTML($node);
            return $html;
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
