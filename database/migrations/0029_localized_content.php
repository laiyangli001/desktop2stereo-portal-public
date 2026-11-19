<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE portal_locales (
        code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        url_prefix VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        native_name VARCHAR(100) NOT NULL,
        direction VARCHAR(3) NOT NULL DEFAULT 'ltr',
        fallback_locales_json JSON NOT NULL,
        ui_enabled TINYINT NOT NULL DEFAULT 0,
        public_enabled TINYINT NOT NULL DEFAULT 0,
        admin_enabled TINYINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 100,
        revision INT NOT NULL DEFAULT 1,
        updated_at BIGINT NOT NULL,
        CHECK(direction IN ('ltr','rtl')),
        CHECK(ui_enabled IN (0,1) AND public_enabled IN (0,1) AND admin_enabled IN (0,1))
    )".$tail,
    "INSERT INTO portal_locales(code,url_prefix,native_name,direction,fallback_locales_json,ui_enabled,public_enabled,admin_enabled,sort_order,updated_at) VALUES
        ('zh-Hans','zh-hans','简体中文','ltr',JSON_ARRAY(),1,1,1,10,UNIX_TIMESTAMP()),
        ('en','en','English','ltr',JSON_ARRAY('zh-Hans'),1,1,1,20,UNIX_TIMESTAMP()),
        ('zh-Hant','zh-hant','繁體中文','ltr',JSON_ARRAY('zh-Hans'),0,0,0,30,UNIX_TIMESTAMP()),
        ('fr','fr','Français','ltr',JSON_ARRAY('en','zh-Hans'),0,0,0,40,UNIX_TIMESTAMP()),
        ('ru','ru','Русский','ltr',JSON_ARRAY('en','zh-Hans'),0,0,0,50,UNIX_TIMESTAMP()),
        ('ja','ja','日本語','ltr',JSON_ARRAY('en','zh-Hans'),0,0,0,60,UNIX_TIMESTAMP()),
        ('vi','vi','Tiếng Việt','ltr',JSON_ARRAY('en','zh-Hans'),0,0,0,70,UNIX_TIMESTAMP())",
    "CREATE TABLE portal_locale_aliases (
        alias VARCHAR(35) CHARACTER SET ascii COLLATE ascii_general_ci PRIMARY KEY,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code)
    )".$tail,
    "INSERT INTO portal_locale_aliases(alias,locale_code) VALUES
        ('zh','zh-Hans'),('zh-CN','zh-Hans'),('zh_CN','zh-Hans'),('zh-Hans','zh-Hans'),('zh_Hans','zh-Hans'),('zhCN','zh-Hans'),
        ('en-US','en'),('en_GB','en'),('en-GB','en'),
        ('zh-TW','zh-Hant'),('zh_TW','zh-Hant'),('zh-Hant','zh-Hant'),('zh_Hant','zh-Hant'),('zhTW','zh-Hant'),
        ('fr-FR','fr'),('ru-RU','ru'),('ja-JP','ja'),('vi-VN','vi')",
    "CREATE TABLE portal_ui_translation_overrides (
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        namespace VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        message_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        message_value TEXT NOT NULL,
        source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_by BIGINT UNSIGNED NOT NULL,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(locale_code,namespace,message_key),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(updated_by) REFERENCES portal_admins(id)
    )".$tail,
    "ALTER TABLE portal_articles
        ADD source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'zh-Hans',
        ADD source_revision INT NOT NULL DEFAULT 1",
    "CREATE TABLE portal_article_translation_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision INT NOT NULL,
        title VARCHAR(240) NOT NULL,
        summary TEXT NOT NULL,
        body MEDIUMTEXT NOT NULL,
        body_format VARCHAR(16) NOT NULL DEFAULT 'plain',
        seo_title VARCHAR(240) NOT NULL DEFAULT '',
        seo_description VARCHAR(500) NOT NULL DEFAULT '',
        source_revision INT NOT NULL DEFAULT 1,
        source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        origin VARCHAR(16) NOT NULL DEFAULT 'manual',
        provider VARCHAR(80) NULL,
        glossary_revision INT NULL,
        author_admin_id BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        UNIQUE KEY uq_article_locale_revision(article_id,locale_code,revision),
        UNIQUE KEY uq_article_revision_pointer(id,article_id,locale_code),
        FOREIGN KEY(article_id) REFERENCES portal_articles(id),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(author_admin_id) REFERENCES portal_admins(id),
        CHECK(body_format IN ('plain','html')),
        CHECK(origin IN ('manual','migration','machine'))
    )".$tail,
    "CREATE TABLE portal_article_translations (
        article_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        draft_revision_id BIGINT UNSIGNED NULL,
        published_revision_id BIGINT UNSIGNED NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(article_id,locale_code),
        FOREIGN KEY(article_id) REFERENCES portal_articles(id),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(draft_revision_id,article_id,locale_code)
            REFERENCES portal_article_translation_revisions(id,article_id,locale_code),
        FOREIGN KEY(published_revision_id,article_id,locale_code)
            REFERENCES portal_article_translation_revisions(id,article_id,locale_code)
    )".$tail,
    "INSERT INTO portal_article_translation_revisions
        (article_id,locale_code,revision,title,summary,body,body_format,seo_title,seo_description,source_revision,source_hash,origin,author_admin_id,created_at)
        SELECT id,'zh-Hans',1,title_zh,summary_zh,body_zh,body_format,seo_title,seo_description,source_revision,
            SHA2(CONCAT(title_zh,'\\n',summary_zh,'\\n',body_zh),256),'migration',author_id,updated_at
        FROM portal_articles",
    "INSERT INTO portal_article_translations(article_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
        SELECT a.id,'zh-Hans',r.id,IF(a.status='published',r.id,NULL),1,a.updated_at
        FROM portal_articles a JOIN portal_article_translation_revisions r
            ON r.article_id=a.id AND r.locale_code='zh-Hans' AND r.revision=1",
    "INSERT INTO portal_article_translation_revisions
        (article_id,locale_code,revision,title,summary,body,body_format,seo_title,seo_description,source_revision,source_hash,origin,author_admin_id,created_at)
        SELECT id,'en',1,title_en,summary_en,body_en,body_format,'','',source_revision,
            SHA2(CONCAT(title_zh,'\\n',summary_zh,'\\n',body_zh),256),'migration',author_id,updated_at
        FROM portal_articles
        WHERE title_en<>'' OR summary_en<>'' OR body_en<>''",
    "INSERT INTO portal_article_translations(article_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
        SELECT a.id,'en',r.id,IF(a.status='published',r.id,NULL),1,a.updated_at
        FROM portal_articles a JOIN portal_article_translation_revisions r
            ON r.article_id=a.id AND r.locale_code='en' AND r.revision=1",
    "ALTER TABLE portal_products
        ADD source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'zh-Hans',
        ADD source_revision INT NOT NULL DEFAULT 1",
    "CREATE TABLE portal_product_translation_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_code VARCHAR(32) NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision INT NOT NULL,
        title VARCHAR(160) NOT NULL,
        description TEXT NOT NULL,
        source_revision INT NOT NULL DEFAULT 1,
        source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        origin VARCHAR(16) NOT NULL DEFAULT 'manual',
        author_admin_id BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        UNIQUE KEY uq_product_locale_revision(product_code,locale_code,revision),
        UNIQUE KEY uq_product_revision_pointer(id,product_code,locale_code),
        FOREIGN KEY(product_code) REFERENCES portal_products(code),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(author_admin_id) REFERENCES portal_admins(id),
        CHECK(origin IN ('manual','migration','machine'))
    )".$tail,
    "CREATE TABLE portal_product_translations (
        product_code VARCHAR(32) NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        draft_revision_id BIGINT UNSIGNED NULL,
        published_revision_id BIGINT UNSIGNED NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(product_code,locale_code),
        FOREIGN KEY(product_code) REFERENCES portal_products(code),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(draft_revision_id,product_code,locale_code)
            REFERENCES portal_product_translation_revisions(id,product_code,locale_code),
        FOREIGN KEY(published_revision_id,product_code,locale_code)
            REFERENCES portal_product_translation_revisions(id,product_code,locale_code)
    )".$tail,
    "INSERT INTO portal_product_translation_revisions
        (product_code,locale_code,revision,title,description,source_revision,source_hash,origin,created_at)
        SELECT code,'zh-Hans',1,title_zh,description_zh,source_revision,
            SHA2(CONCAT(title_zh,'\\n',description_zh),256),'migration',updated_at FROM portal_products",
    "INSERT INTO portal_product_translations(product_code,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
        SELECT p.code,'zh-Hans',r.id,r.id,1,p.updated_at FROM portal_products p
        JOIN portal_product_translation_revisions r ON r.product_code=p.code AND r.locale_code='zh-Hans' AND r.revision=1",
    "INSERT INTO portal_product_translation_revisions
        (product_code,locale_code,revision,title,description,source_revision,source_hash,origin,created_at)
        SELECT code,'en',1,title_en,description_en,source_revision,
            SHA2(CONCAT(title_zh,'\\n',description_zh),256),'migration',updated_at FROM portal_products
        WHERE title_en<>'' OR description_en<>''",
    "INSERT INTO portal_product_translations(product_code,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
        SELECT p.code,'en',r.id,r.id,1,p.updated_at FROM portal_products p
        JOIN portal_product_translation_revisions r ON r.product_code=p.code AND r.locale_code='en' AND r.revision=1",
    "INSERT INTO portal_admin_permissions(admin_id,permission)
        SELECT admin_id,'locales.manage' FROM portal_admin_permissions GROUP BY admin_id
        HAVING COUNT(DISTINCT permission)>=30"
];
