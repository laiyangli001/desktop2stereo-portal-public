<?php
declare(strict_types=1);
namespace D2S\domain;

/** A position, never an authorization token; all queries still require the session owner. */
final class HistoryCursor
{
    public static function encode(string $kind, int $userId, string $id, ?int $time = null): string
    {
        $value=['v'=>1,'kind'=>$kind,'user_id'=>$userId,'id'=>$id];
        if ($time!==null) $value['time']=$time;
        return rtrim(strtr(base64_encode(json_encode($value,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    }

    public static function decode(?string $cursor, string $kind, int $userId): ?array
    {
        if ($cursor===null) return null;
        if (strlen($cursor)>512 || !preg_match('/^[A-Za-z0-9_-]+$/D',$cursor)) throw new Problem('history_cursor_invalid');
        $raw=base64_decode(strtr($cursor,'-_','+/'),true);
        if ($raw===false || rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$cursor) throw new Problem('history_cursor_invalid');
        try { $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Problem('history_cursor_invalid'); }
        if (!is_array($value) || ($value['v']??null)!==1 || ($value['kind']??null)!==$kind ||
            ($value['user_id']??null)!==$userId || !is_string($value['id']??null)) throw new Problem('history_cursor_invalid');
        if (in_array($kind,['orders','notifications','payments'],true)) {
            if (count($value)!==5 || !preg_match('/^[a-f0-9]{32}$/D',$value['id']) ||
                !is_int($value['time']??null) || $value['time']<0) throw new Problem('history_cursor_invalid');
        } elseif ($kind==='wallet') {
            if (count($value)!==4 || !preg_match('/^[1-9][0-9]{0,19}$/D',$value['id']) ||
                (strlen($value['id'])===20 && strcmp($value['id'],'18446744073709551615')>0)) throw new Problem('history_cursor_invalid');
        } else { throw new Problem('history_cursor_invalid'); }
        return $value;
    }
}
