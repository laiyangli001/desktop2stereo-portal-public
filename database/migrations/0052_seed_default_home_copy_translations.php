<?php
declare(strict_types=1);

return [
    "INSERT IGNORE INTO portal_site_translations
        (locale_code,tagline,intro,lock_version,updated_by,updated_at)
        SELECT locale.code,
            CASE locale.code
                WHEN 'zh-Hant' THEN '將 2D 影像轉換為可沉浸觀看的立體畫面'
                WHEN 'de' THEN 'Verwandeln Sie 2D-Bilder in stereoskopische Bilder für ein immersives Seherlebnis'
                WHEN 'fr' THEN 'Transformez les images 2D en images stéréoscopiques pour une expérience immersive'
                WHEN 'es' THEN 'Convierte imágenes 2D en imágenes estereoscópicas para disfrutar de una experiencia inmersiva'
                WHEN 'pt' THEN 'Converta imagens 2D em imagens estereoscópicas para uma experiência imersiva'
                WHEN 'ru' THEN 'Преобразуйте 2D-изображения в стереоскопические для полного погружения в просмотр'
                WHEN 'ja' THEN '2D映像を、没入感のある立体映像に変換します'
                WHEN 'ko' THEN '2D 영상을 몰입감 있는 입체 화면으로 변환하세요'
            END,
            CASE locale.code
                WHEN 'zh-Hant' THEN 'Desktop2Stereo 提供軟體下載入口、帳戶授權、裝置管理和私密售後服務。安裝程式由外部平台託管。'
                WHEN 'de' THEN 'Desktop2Stereo bietet Software-Downloads, Kontolizenzen, Geräteverwaltung und privaten Kundendienst. Installationsdateien werden auf externen Plattformen gehostet.'
                WHEN 'fr' THEN 'Desktop2Stereo propose le téléchargement du logiciel, la gestion des licences de compte, des appareils et une assistance après-vente privée. Les installateurs sont hébergés sur des plateformes externes.'
                WHEN 'es' THEN 'Desktop2Stereo ofrece descargas de software, licencias de cuenta, administración de dispositivos y asistencia posventa privada. Los instaladores se alojan en plataformas externas.'
                WHEN 'pt' THEN 'O Desktop2Stereo oferece downloads de software, licenças de conta, gerenciamento de dispositivos e suporte pós-venda privado. Os instaladores ficam hospedados em plataformas externas.'
                WHEN 'ru' THEN 'Desktop2Stereo предоставляет загрузки программного обеспечения, лицензии для учетных записей, управление устройствами и конфиденциальную послепродажную поддержку. Установочные файлы размещены на внешних платформах.'
                WHEN 'ja' THEN 'Desktop2Stereoでは、ソフトウェアのダウンロード、アカウントライセンス、デバイス管理、非公開のアフターサポートを提供しています。インストーラーは外部プラットフォームでホストされています。'
                WHEN 'ko' THEN 'Desktop2Stereo는 소프트웨어 다운로드, 계정 라이선스, 기기 관리 및 비공개 사후 지원을 제공합니다. 설치 파일은 외부 플랫폼에서 호스팅됩니다.'
            END,
            1,NULL,UNIX_TIMESTAMP()
        FROM portal_locales locale
        LEFT JOIN portal_settings setting ON setting.name='site.v1'
        WHERE locale.code IN ('zh-Hant','de','fr','es','pt','ru','ja','ko')
            AND (
                setting.name IS NULL
                OR (
                    JSON_VALID(setting.value_json)
                    AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(setting.value_json),setting.value_json,JSON_OBJECT()),'$.tagline_en'))='Turn ordinary 2D media into immersive stereoscopic experiences'
                    AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(setting.value_json),setting.value_json,JSON_OBJECT()),'$.intro_en'))='Desktop2Stereo provides software downloads, account licensing, device management and private support. Installers are hosted by external platforms.'
                )
            )",
];
