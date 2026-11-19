<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE portal_translation_glossary_versions (
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        target_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision INT UNSIGNED NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NULL,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(source_locale,target_locale),
        FOREIGN KEY(source_locale) REFERENCES portal_locales(code),
        FOREIGN KEY(target_locale) REFERENCES portal_locales(code),
        FOREIGN KEY(updated_by) REFERENCES portal_admins(id),
        CHECK(source_locale <> target_locale)
    )".$tail,
    "CREATE TABLE portal_translation_glossary_terms (
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        target_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_term VARCHAR(300) NOT NULL,
        target_term VARCHAR(300) NOT NULL,
        mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'translate_as',
        PRIMARY KEY(source_locale,target_locale,source_term),
        FOREIGN KEY(source_locale,target_locale)
            REFERENCES portal_translation_glossary_versions(source_locale,target_locale),
        CHECK(mode IN ('translate_as','preserve'))
    )".$tail,
];