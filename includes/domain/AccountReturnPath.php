<?php
declare(strict_types=1);
namespace D2S\domain;

/** Only known account destinations may survive login and policy confirmation. */
final class AccountReturnPath
{
    public static function normalize(mixed $value): string
    {
        if(!is_string($value))return '';
        if(in_array($value,['/feedback','/feedback/new','/support/wechat','/shop'],true))return $value;
        return preg_match('#^/shop\\?product=([a-z][a-z0-9_-]{2,31})$#D',$value,$match)===1
            &&$match[1]!=='wallet_recharge'?$value:'';
    }
}
