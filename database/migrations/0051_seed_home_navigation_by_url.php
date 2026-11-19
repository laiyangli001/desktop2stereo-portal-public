<?php
declare(strict_types=1);

return [
    "INSERT IGNORE INTO portal_navigation_translations
        (navigation_id,locale_code,label,lock_version,updated_by,updated_at)
        SELECT navigation_link.navigation_id,locale.code,
            CASE locale.code
                WHEN 'zh-Hans' THEN '首页'
                WHEN 'zh-Hant' THEN '首頁'
                WHEN 'en' THEN 'Home'
                WHEN 'de' THEN 'Startseite'
                WHEN 'fr' THEN 'Accueil'
                WHEN 'es' THEN 'Inicio'
                WHEN 'pt' THEN 'Início'
                WHEN 'ru' THEN 'Главная'
                WHEN 'ja' THEN 'ホーム'
                WHEN 'ko' THEN '홈'
            END,
            1,NULL,UNIX_TIMESTAMP()
        FROM portal_settings setting
        JOIN JSON_TABLE(setting.value_json, '$[*]' COLUMNS (
            navigation_id VARCHAR(64) PATH '$.id' NULL ON EMPTY NULL ON ERROR,
            navigation_url VARCHAR(2048) PATH '$.url' NULL ON EMPTY NULL ON ERROR
        )) navigation_link
        JOIN portal_locales locale ON locale.code IN
            ('zh-Hans','zh-Hant','en','de','fr','es','pt','ru','ja','ko')
        WHERE setting.name='navigation.v1' AND JSON_VALID(setting.value_json)
            AND navigation_link.navigation_id IS NOT NULL
            AND navigation_link.navigation_url='/'",
];
