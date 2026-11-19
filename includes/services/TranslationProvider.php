<?php
declare(strict_types=1);

namespace D2S\services;

interface TranslationProvider
{
    /** @return array{title:string,summary:string,body:string} */
    public function translateArticle(array $source,string $sourceLocale,string $targetLocale,array $targetMap,array $glossary=[]): array;

    /** @return array{title:string,description:string} */
    public function translateProduct(array $source,string $sourceLocale,string $targetLocale,array $targetMap,array $glossary=[]): array;
}
