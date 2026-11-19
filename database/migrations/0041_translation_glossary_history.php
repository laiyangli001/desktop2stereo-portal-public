<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE portal_translation_glossary_revisions (
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        target_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision INT UNSIGNED NOT NULL,
        terms_json MEDIUMTEXT NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        PRIMARY KEY(source_locale,target_locale,revision),
        FOREIGN KEY(source_locale) REFERENCES portal_locales(code),
        FOREIGN KEY(target_locale) REFERENCES portal_locales(code),
        FOREIGN KEY(updated_by) REFERENCES portal_admins(id),
        CHECK(source_locale <> target_locale),
        CHECK(JSON_VALID(terms_json))
    )".$tail,
];
