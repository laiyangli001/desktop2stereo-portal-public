<?php
declare(strict_types=1);

return [
    "INSERT IGNORE INTO portal_product_translation_revisions
        (product_code,locale_code,revision,title,description,source_revision,source_hash,origin,author_admin_id,created_at)
        SELECT product.code,locale.code,1,
            CASE locale.code
                WHEN 'zh-Hant' THEN 'Desktop2Stereo 正式授權'
                WHEN 'de' THEN 'Desktop2Stereo-Lizenz'
                WHEN 'fr' THEN 'Licence Desktop2Stereo'
                WHEN 'es' THEN 'Licencia de Desktop2Stereo'
                WHEN 'pt' THEN 'Licença do Desktop2Stereo'
                WHEN 'ru' THEN 'Лицензия Desktop2Stereo'
                WHEN 'ja' THEN 'Desktop2Stereo ライセンス'
                WHEN 'ko' THEN 'Desktop2Stereo 라이선스'
            END,
            CASE locale.code
                WHEN 'zh-Hant' THEN 'Desktop2Stereo 軟體正式授權，支援一個線上工作階段。'
                WHEN 'de' THEN 'Offizielle Desktop2Stereo-Softwarelizenz mit einer gleichzeitigen Online-Sitzung.'
                WHEN 'fr' THEN 'Licence officielle du logiciel Desktop2Stereo, avec une session en ligne.'
                WHEN 'es' THEN 'Licencia oficial del software Desktop2Stereo, con una sesión en línea.'
                WHEN 'pt' THEN 'Licença oficial do software Desktop2Stereo, com uma sessão online.'
                WHEN 'ru' THEN 'Официальная лицензия программного обеспечения Desktop2Stereo с одним одновременным онлайн-сеансом.'
                WHEN 'ja' THEN '同時に1つのオンラインセッションを利用できる、Desktop2Stereoソフトウェアの正式ライセンスです。'
                WHEN 'ko' THEN '동시에 하나의 온라인 세션을 사용할 수 있는 Desktop2Stereo 정식 소프트웨어 라이선스입니다.'
            END,
            product.source_revision,source_revision.source_hash,'migration',NULL,product.updated_at
        FROM portal_products product
        JOIN portal_locales locale ON locale.code IN ('zh-Hant','de','fr','es','pt','ru','ja','ko')
        JOIN portal_product_translations source_head
            ON source_head.product_code=product.code AND source_head.locale_code='zh-Hans'
        JOIN portal_product_translation_revisions source_revision
            ON source_revision.id=source_head.draft_revision_id
            AND source_revision.product_code=product.code AND source_revision.locale_code='zh-Hans'
        WHERE product.code='desktop2stereo' AND product.source_locale='zh-Hans'
            AND product.source_revision=source_revision.source_revision
            AND product.title_zh='Desktop2Stereo 正式授权'
            AND product.description_zh='Desktop2Stereo 软件正式授权，支持一个在线会话。'
            AND product.title_en='Desktop2Stereo license'
            AND product.description_en='Desktop2Stereo software license with one online session.'
            AND source_revision.title='Desktop2Stereo 正式授权'
            AND source_revision.description='Desktop2Stereo 软件正式授权，支持一个在线会话。'
            AND NOT EXISTS (
                SELECT 1 FROM portal_product_translations existing
                WHERE existing.product_code=product.code AND existing.locale_code=locale.code
            )",
    "INSERT IGNORE INTO portal_product_translations
        (product_code,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
        SELECT product.code,locale.code,revision.id,revision.id,1,product.updated_at
        FROM portal_products product
        JOIN portal_locales locale ON locale.code IN ('zh-Hant','de','fr','es','pt','ru','ja','ko')
        JOIN portal_product_translations source_head
            ON source_head.product_code=product.code AND source_head.locale_code='zh-Hans'
        JOIN portal_product_translation_revisions source_revision
            ON source_revision.id=source_head.draft_revision_id
            AND source_revision.product_code=product.code AND source_revision.locale_code='zh-Hans'
        JOIN portal_product_translation_revisions revision
            ON revision.product_code=product.code AND revision.locale_code=locale.code
            AND revision.revision=1 AND revision.origin='migration'
            AND revision.source_revision=product.source_revision
        WHERE product.code='desktop2stereo' AND product.source_locale='zh-Hans'
            AND product.source_revision=source_revision.source_revision
            AND product.title_zh='Desktop2Stereo 正式授权'
            AND product.description_zh='Desktop2Stereo 软件正式授权，支持一个在线会话。'
            AND product.title_en='Desktop2Stereo license'
            AND product.description_en='Desktop2Stereo software license with one online session.'
            AND source_revision.title='Desktop2Stereo 正式授权'
            AND source_revision.description='Desktop2Stereo 软件正式授权，支持一个在线会话。'
            AND NOT EXISTS (
                SELECT 1 FROM portal_product_translations existing
                WHERE existing.product_code=product.code AND existing.locale_code=locale.code
            )",
];
