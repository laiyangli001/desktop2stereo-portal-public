<?php
declare(strict_types=1);
$path=$argv[1]??'';
$source=file_get_contents($path);
if(!is_string($source))throw new RuntimeException('source_unreadable');
$raw=token_get_all($source,TOKEN_PARSE);
$tokens=[];$offset=0;
foreach($raw as$token){
    $text=is_array($token)?$token[1]:$token;
    if(!is_array($token)||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){
        $tokens[]=['type'=>is_array($token)?$token[0]:null,'text'=>$text,'start'=>$offset,'end'=>$offset+strlen($text)];
    }
    $offset+=strlen($text);
}
$calls=[];$unsupported=[];
$literal=static function(string $text):?string{
    if(!str_starts_with($text,"'")||!str_ends_with($text,"'"))return null;
    return strtr(substr($text,1,-1),["\\\\"=>"\\","\\'"=>"'"]);
};
foreach($tokens as$i=>$token){
    if($token['type']!==T_STRING||$token['text']!=='d2s_t'||($tokens[$i+1]['text']??null)!=='(')continue;
    if(($tokens[$i+2]['type']??null)===T_CONSTANT_ENCAPSED_STRING
        &&($tokens[$i+3]['text']??null)===','
        &&($tokens[$i+4]['type']??null)===T_CONSTANT_ENCAPSED_STRING
        &&($tokens[$i+5]['text']??null)===')'){
        $zh=$literal($tokens[$i+2]['text']);$en=$literal($tokens[$i+4]['text']);
        if($zh!==null&&$en!==null){
            $calls[]=['source'=>substr($source,$token['start'],$tokens[$i+5]['end']-$token['start']),'zh'=>$zh,'en'=>$en];
            continue;
        }
    }
    $unsupported[]=$token['start'];
}
echo json_encode(['file'=>$path,'calls'=>$calls,'unsupported'=>$unsupported],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
