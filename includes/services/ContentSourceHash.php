<?php
declare(strict_types=1);

namespace D2S\services;

/** Canonical UTF-8 JSON arrays; field order is part of the persisted hash contract. */
final class ContentSourceHash
{
    public static function article(array $data): string
    {
        return self::fields([
            (string)($data['title']??''), (string)($data['summary']??''), (string)($data['body']??''),
        ]);
    }

    public static function product(string $title, string $description): string
    {
        return self::fields([$title,$description]);
    }

    public static function announcement(string $title, string $body): string
    {
        return self::fields([$title,$body]);
    }

    private static function fields(array $fields): string
    {
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
