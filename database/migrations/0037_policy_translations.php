<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE portal_policy_documents (
        policy_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        policy_version VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        effective_date VARCHAR(40) NOT NULL DEFAULT '',
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_revision INT NOT NULL DEFAULT 0,
        lock_version INT NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(policy_type,policy_version),
        FOREIGN KEY(source_locale) REFERENCES portal_locales(code),
        CHECK(policy_type IN ('PRIVACY','TERMS','LEGAL'))
    )".$tail,
    "CREATE TABLE portal_policy_translation_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        policy_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        policy_version VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision INT NOT NULL,
        title VARCHAR(180) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        source_revision INT NOT NULL,
        source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        origin VARCHAR(16) NOT NULL DEFAULT 'manual',
        author_admin_id BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        UNIQUE KEY uq_policy_translation_revision(policy_type,policy_version,locale_code,revision),
        UNIQUE KEY uq_policy_translation_revision_pointer(id,policy_type,policy_version,locale_code),
        FOREIGN KEY(policy_type,policy_version) REFERENCES portal_policy_documents(policy_type,policy_version),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(author_admin_id) REFERENCES portal_admins(id),
        CHECK(origin IN ('manual','migration','machine'))
    )".$tail,
    "CREATE TABLE portal_policy_translation_heads (
        policy_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        policy_version VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        draft_revision_id BIGINT UNSIGNED NULL,
        published_revision_id BIGINT UNSIGNED NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(policy_type,policy_version,locale_code),
        FOREIGN KEY(policy_type,policy_version) REFERENCES portal_policy_documents(policy_type,policy_version),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(draft_revision_id,policy_type,policy_version,locale_code)
            REFERENCES portal_policy_translation_revisions(id,policy_type,policy_version,locale_code),
        FOREIGN KEY(published_revision_id,policy_type,policy_version,locale_code)
            REFERENCES portal_policy_translation_revisions(id,policy_type,policy_version,locale_code)
    )".$tail,
    "INSERT IGNORE INTO portal_policy_documents
        (policy_type,policy_version,effective_date,source_locale,source_revision,lock_version,created_at,updated_at)
        SELECT 'PRIVACY',JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.privacy_version')),
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.privacy_effective')),''),'zh-Hans',0,1,
            COALESCE(updated_at,UNIX_TIMESTAMP()),COALESCE(updated_at,UNIX_TIMESTAMP())
        FROM portal_settings WHERE name='site.v1' AND JSON_VALID(value_json)
            AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.privacy_version')),'') IS NOT NULL",
    "INSERT IGNORE INTO portal_policy_documents
        (policy_type,policy_version,effective_date,source_locale,source_revision,lock_version,created_at,updated_at)
        SELECT 'TERMS',JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.terms_version')),
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.terms_effective')),''),'zh-Hans',0,1,
            COALESCE(updated_at,UNIX_TIMESTAMP()),COALESCE(updated_at,UNIX_TIMESTAMP())
        FROM portal_settings WHERE name='site.v1' AND JSON_VALID(value_json)
            AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.terms_version')),'') IS NOT NULL",
    "INSERT IGNORE INTO portal_policy_documents
        (policy_type,policy_version,effective_date,source_locale,source_revision,lock_version,created_at,updated_at)
        SELECT 'LEGAL',JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.legal_version')),
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.legal_effective')),''),'zh-Hans',0,1,
            COALESCE(updated_at,UNIX_TIMESTAMP()),COALESCE(updated_at,UNIX_TIMESTAMP())
        FROM portal_settings WHERE name='site.v1' AND JSON_VALID(value_json)
            AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.legal_version')),'') IS NOT NULL",
];
