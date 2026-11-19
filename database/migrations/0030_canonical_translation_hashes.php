<?php
declare(strict_types=1);

// Match PHP JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES without relying on SQL backslash escapes.
$quote=static fn(string $column):string=>
    "REPLACE(REPLACE(JSON_QUOTE($column),CONVERT(0xE280A8 USING utf8mb4),CONVERT(0x5C7532303238 USING utf8mb4)),CONVERT(0xE280A9 USING utf8mb4),CONVERT(0x5C7532303239 USING utf8mb4))";
$canonical=static fn(array $columns):string=>
    "SHA2(CONCAT('[',".implode(",',',",array_map($quote,$columns)).",']'),256)";
$article=$canonical(['s.title','s.summary','s.body']);
$product=$canonical(['s.title','s.description']);

return [
    // The migration source is revision 1 in zh-Hans, even if the current source has since changed.
    "UPDATE portal_article_translation_revisions r
     JOIN portal_article_translation_revisions s
       ON s.article_id=r.article_id AND s.locale_code='zh-Hans' AND s.revision=1
       AND s.origin='migration' AND s.source_revision=r.source_revision
     SET r.source_hash=$article
     WHERE r.origin='migration' AND r.source_hash<>$article",
    "UPDATE portal_product_translation_revisions r
     JOIN portal_product_translation_revisions s
       ON s.product_code=r.product_code AND s.locale_code='zh-Hans' AND s.revision=1
       AND s.origin='migration' AND s.source_revision=r.source_revision
     SET r.source_hash=$product
     WHERE r.origin='migration' AND r.source_hash<>$product",
];
