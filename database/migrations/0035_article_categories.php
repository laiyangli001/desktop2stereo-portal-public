<?php
declare(strict_types=1);

return [
    "CREATE TABLE portal_article_categories (
        code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        sort_order INT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        INDEX idx_article_category_sort(sort_order,code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE portal_article_category_translations (
        category_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        label VARCHAR(80) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        lock_version INT NOT NULL DEFAULT 1,
        updated_by BIGINT UNSIGNED NULL,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(category_code,locale_code),
        FOREIGN KEY(category_code) REFERENCES portal_article_categories(code),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(updated_by) REFERENCES portal_admins(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE portal_articles ADD COLUMN category_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL",
    "INSERT INTO portal_article_categories(code,sort_order,created_at,updated_at)
        SELECT CONCAT('legacy-',LEFT(SHA2(CONVERT(IFNULL(NULLIF(TRIM(category),''),'使用帮助') USING utf8mb4),256),24)),
            0,MIN(created_at),MAX(updated_at)
        FROM portal_articles
        GROUP BY IFNULL(NULLIF(TRIM(category),''),'使用帮助')",
    "INSERT INTO portal_article_category_translations
        (category_code,locale_code,label,description,lock_version,updated_by,updated_at)
        SELECT CONCAT('legacy-',LEFT(SHA2(CONVERT(IFNULL(NULLIF(TRIM(category),''),'使用帮助') USING utf8mb4),256),24)),
            'zh-Hans',IFNULL(NULLIF(TRIM(category),''),'使用帮助'),'',
            1,NULL,MAX(updated_at)
        FROM portal_articles
        GROUP BY IFNULL(NULLIF(TRIM(category),''),'使用帮助')",
    "UPDATE portal_articles a
        JOIN portal_article_categories c
          ON c.code=CONCAT('legacy-',LEFT(SHA2(CONVERT(IFNULL(NULLIF(TRIM(a.category),''),'使用帮助') USING utf8mb4),256),24))
        SET a.category_id=c.code",
    "ALTER TABLE portal_articles
        MODIFY category_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        ADD CONSTRAINT fk_article_category FOREIGN KEY(category_id) REFERENCES portal_article_categories(code)",
];
