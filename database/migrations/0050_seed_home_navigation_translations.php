<?php
declare(strict_types=1);

return [
    "INSERT IGNORE INTO portal_navigation_translations
        (navigation_id,locale_code,label,lock_version,updated_by,updated_at)
        SELECT 'home',code,
            CASE code
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
        FROM portal_locales
        WHERE code IN ('zh-Hans','zh-Hant','en','de','fr','es','pt','ru','ja','ko')",
];
