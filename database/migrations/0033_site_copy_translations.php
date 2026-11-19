<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$legacy = "portal_settings s
    JOIN JSON_TABLE(s.value_json, '$' COLUMNS (
        tagline_zh VARCHAR(160) PATH '$.tagline_zh' NULL ON EMPTY NULL ON ERROR,
        tagline_en VARCHAR(160) PATH '$.tagline_en' NULL ON EMPTY NULL ON ERROR,
        intro_zh VARCHAR(500) PATH '$.intro_zh' NULL ON EMPTY NULL ON ERROR,
        intro_en VARCHAR(500) PATH '$.intro_en' NULL ON EMPTY NULL ON ERROR
    )) legacy_site";

return [
    "CREATE TABLE portal_site_translations (
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        tagline VARCHAR(160) NOT NULL,
        intro VARCHAR(500) NOT NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_by BIGINT UNSIGNED NULL,
        updated_at BIGINT NOT NULL,
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(updated_by) REFERENCES portal_admins(id)
    )".$tail,
    "INSERT INTO portal_site_translations(locale_code,tagline,intro,updated_at)
        SELECT 'zh-Hans',TRIM(legacy_site.tagline_zh),TRIM(legacy_site.intro_zh),s.updated_at
        FROM $legacy
        WHERE s.name='site.v1' AND JSON_VALID(s.value_json)
            AND NULLIF(TRIM(legacy_site.tagline_zh),'') IS NOT NULL
            AND NULLIF(TRIM(legacy_site.intro_zh),'') IS NOT NULL",
    "INSERT INTO portal_site_translations(locale_code,tagline,intro,updated_at)
        SELECT 'en',TRIM(legacy_site.tagline_en),TRIM(legacy_site.intro_en),s.updated_at
        FROM $legacy
        WHERE s.name='site.v1' AND JSON_VALID(s.value_json)
            AND NULLIF(TRIM(legacy_site.tagline_en),'') IS NOT NULL
            AND NULLIF(TRIM(legacy_site.intro_en),'') IS NOT NULL",
];
