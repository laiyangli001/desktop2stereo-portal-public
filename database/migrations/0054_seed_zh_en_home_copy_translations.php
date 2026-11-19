<?php
declare(strict_types=1);

return [
    "INSERT IGNORE INTO portal_site_translations
        (locale_code,tagline,intro,lock_version,updated_by,updated_at)
        SELECT locale.code,
            CASE locale.code
                WHEN 'zh-Hans' THEN COALESCE(
                    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(setting.value_json),setting.value_json,JSON_OBJECT()),'$.tagline_zh'))),''),
                    '把 2D 影像转换为可沉浸观看的立体画面'
                )
                WHEN 'en' THEN COALESCE(
                    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(setting.value_json),setting.value_json,JSON_OBJECT()),'$.tagline_en'))),''),
                    'Turn ordinary 2D media into immersive stereoscopic experiences'
                )
            END,
            CASE locale.code
                WHEN 'zh-Hans' THEN COALESCE(
                    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(setting.value_json),setting.value_json,JSON_OBJECT()),'$.intro_zh'))),''),
                    'Desktop2Stereo 提供软件下载入口、账户授权、设备管理和私密售后服务。安装包由外部平台托管。'
                )
                WHEN 'en' THEN COALESCE(
                    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(setting.value_json),setting.value_json,JSON_OBJECT()),'$.intro_en'))),''),
                    'Desktop2Stereo provides software downloads, account licensing, device management and private support. Installers are hosted by external platforms.'
                )
            END,
            1,NULL,COALESCE(NULLIF(setting.updated_at,0),UNIX_TIMESTAMP())
        FROM portal_locales locale
        LEFT JOIN portal_settings setting ON setting.name='site.v1'
        WHERE locale.code IN ('zh-Hans','en')"
];
