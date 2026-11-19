<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use DOMDocument;
use DOMXPath;
use HTMLPurifier;
use HTMLPurifier_Config;

/** Sanitizes the limited rich-text subset accepted for private support messages. */
final class TicketContent
{
    public const MAX_CHARACTERS=5000;
    public const MAX_HTML_BYTES=32768;
    private static ?HTMLPurifier $purifier=null;

    public static function clean(mixed $source): string
    {
        if(!is_string($source)||!mb_check_encoding($source,'UTF-8')||strlen($source)>self::MAX_HTML_BYTES||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$source)){
            throw new Problem('ticket_body_invalid');
        }
        $html=self::stripInactiveLinks(self::purifier()->purify(self::normalizeAlignment($source)));
        $visible=self::plainText($html);
        if($visible==='')throw new Problem('ticket_body_invalid');
        if(mb_strlen($visible,'UTF-8')>self::MAX_CHARACTERS)throw new Problem('ticket_body_invalid');
        return $html;
    }

    public static function plainText(string $html): string
    {
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return trim(preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u',' ',$text)??'');
    }

    private static function purifier(): HTMLPurifier
    {
        if(self::$purifier!==null)return self::$purifier;
        $config=HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding','UTF-8');
        $config->set('HTML.Doctype','HTML 4.01 Transitional');
        $config->set('HTML.TidyLevel','none');
        $config->set('HTML.Allowed','p,br,strong,b,em,i,u,s,del,blockquote,pre,code,h2,h3,ul,ol[start],li,a[href|title],span[class]');
        $config->set('Attr.AllowedClasses',[
            'ql-align-center','ql-align-right','ql-align-justify','ql-direction-rtl',
            'ql-indent-1','ql-indent-2','ql-indent-3','ql-indent-4',
            'ql-indent-5','ql-indent-6','ql-indent-7','ql-indent-8',
        ]);
        $config->set('URI.AllowedSchemes',['http'=>true,'https'=>true,'mailto'=>true]);
        $config->set('URI.DisableExternalResources',true);
        $config->set('HTML.ForbiddenElements',[
            'script','style','iframe','object','embed','svg','math','form','input','button','textarea',
            'select','video','audio','img','link','meta','base',
        ]);
        $config->set('Cache.DefinitionImpl',null);
        return self::$purifier=new HTMLPurifier($config);
    }

    private static function stripInactiveLinks(string $source): string
    {
        $previous=libxml_use_internal_errors(true);
        try{
            $document=new DOMDocument('1.0','UTF-8');
            $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$source.'</body></html>',LIBXML_NONET);
            $xpath=new DOMXPath($document);
            foreach(iterator_to_array($xpath->query('//a[not(@href)]')?:[])as$anchor){
                $anchor->parentNode?->replaceChild($document->createTextNode($anchor->textContent),$anchor);
            }
            $body=$document->getElementsByTagName('body')->item(0);$html='';
            if($body!==null)foreach($body->childNodes as$node)$html.=$document->saveHTML($node);
            return $html;
        }finally{
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Convert Quill alignment styles into the explicit class subset accepted by the purifier. */
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
                    if(strtolower(trim($property))==='text-align'&&in_array(strtolower(trim($value)),['center','right','justify'],true)){
                        $classes[]='ql-align-'.strtolower(trim($value));
                    }
                }
                $element->removeAttribute('style');
                if($classes!==[])$element->setAttribute('class',implode(' ',array_unique($classes)));
            }
            $body=$document->getElementsByTagName('body')->item(0);$html='';
            if($body!==null)foreach($body->childNodes as$node)$html.=$document->saveHTML($node);
            return $html;
        }finally{
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
