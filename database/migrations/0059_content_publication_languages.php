<?php
declare(strict_types=1);
return [
    "CREATE TABLE portal_content_publication_languages (
        resource_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        resource_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locales_json JSON NOT NULL,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(resource_type,resource_id),
        CHECK(resource_type IN ('article','product','announcement','policy'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
