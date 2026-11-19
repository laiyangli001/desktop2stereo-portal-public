<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$legacy = "portal_settings s
    JOIN JSON_TABLE(s.value_json, '$[*]' COLUMNS (
        navigation_id VARCHAR(64) PATH '$.id' NULL ON EMPTY NULL ON ERROR,
        label_zh VARCHAR(120) PATH '$.label_zh' NULL ON EMPTY NULL ON ERROR,
        label_en VARCHAR(120) PATH '$.label_en' NULL ON EMPTY NULL ON ERROR
    )) legacy_link";

return [
    "CREATE TABLE portal_navigation_translations (
        navigation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        label VARCHAR(120) NOT NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_by BIGINT UNSIGNED NULL,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(navigation_id,locale_code),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(updated_by) REFERENCES portal_admins(id)
    )".$tail,
    "INSERT INTO portal_navigation_translations(navigation_id,locale_code,label,updated_at)
        SELECT legacy_link.navigation_id,'zh-Hans',TRIM(legacy_link.label_zh),s.updated_at
        FROM $legacy
        WHERE s.name='navigation.v1' AND JSON_VALID(s.value_json)
            AND legacy_link.navigation_id IS NOT NULL
            AND NULLIF(TRIM(legacy_link.label_zh),'') IS NOT NULL",
    "INSERT INTO portal_navigation_translations(navigation_id,locale_code,label,updated_at)
        SELECT legacy_link.navigation_id,'en',TRIM(legacy_link.label_en),s.updated_at
        FROM $legacy
        WHERE s.name='navigation.v1' AND JSON_VALID(s.value_json)
            AND legacy_link.navigation_id IS NOT NULL
            AND NULLIF(TRIM(legacy_link.label_en),'') IS NOT NULL",
];
