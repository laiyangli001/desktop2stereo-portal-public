<?php
declare(strict_types=1);

$tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$legacyFrom = "portal_settings s
    JOIN JSON_TABLE(s.value_json, '$[*]' COLUMNS (
        ordinality FOR ORDINALITY,
        title VARCHAR(120) PATH '$.title' NULL ON EMPTY NULL ON ERROR,
        body VARCHAR(3000) PATH '$.body' NULL ON EMPTY NULL ON ERROR,
        enabled TINYINT PATH '$.enabled' NULL ON EMPTY NULL ON ERROR,
        created_at BIGINT PATH '$.created_at' NULL ON EMPTY NULL ON ERROR
    )) legacy_item";
$legacyId = "LOWER(SUBSTRING(SHA2(CONCAT(s.name, ':', legacy_item.ordinality), 256), 1, 16))";
$legacyWhere = "s.name='announcements.v1' AND JSON_VALID(s.value_json)
    AND NULLIF(TRIM(legacy_item.title), '') IS NOT NULL
    AND NULLIF(TRIM(legacy_item.body), '') IS NOT NULL";

return [
    "CREATE TABLE portal_announcements (
        id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        source_locale VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_revision INT NOT NULL DEFAULT 1,
        enabled TINYINT NOT NULL DEFAULT 1,
        needs_locale_review TINYINT NOT NULL DEFAULT 0,
        lock_version INT NOT NULL DEFAULT 1,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        deleted_at BIGINT NULL,
        INDEX ix_announcement_public(enabled, deleted_at, created_at),
        FOREIGN KEY(source_locale) REFERENCES portal_locales(code),
        CHECK(enabled IN (0,1) AND needs_locale_review IN (0,1))
    )".$tail,
    "CREATE TABLE portal_announcement_translation_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        announcement_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision INT NOT NULL,
        title VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        source_revision INT NOT NULL DEFAULT 1,
        source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        origin VARCHAR(16) NOT NULL DEFAULT 'manual',
        author_admin_id BIGINT UNSIGNED NULL,
        created_at BIGINT NOT NULL,
        UNIQUE KEY uq_announcement_locale_revision(announcement_id,locale_code,revision),
        UNIQUE KEY uq_announcement_revision_pointer(id,announcement_id,locale_code),
        FOREIGN KEY(announcement_id) REFERENCES portal_announcements(id),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(author_admin_id) REFERENCES portal_admins(id),
        CHECK(origin IN ('manual','migration','machine'))
    )".$tail,
    "CREATE TABLE portal_announcement_translations (
        announcement_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        locale_code VARCHAR(35) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        draft_revision_id BIGINT UNSIGNED NULL,
        published_revision_id BIGINT UNSIGNED NULL,
        lock_version INT NOT NULL DEFAULT 1,
        updated_at BIGINT NOT NULL,
        PRIMARY KEY(announcement_id,locale_code),
        FOREIGN KEY(announcement_id) REFERENCES portal_announcements(id),
        FOREIGN KEY(locale_code) REFERENCES portal_locales(code),
        FOREIGN KEY(draft_revision_id,announcement_id,locale_code)
            REFERENCES portal_announcement_translation_revisions(id,announcement_id,locale_code),
        FOREIGN KEY(published_revision_id,announcement_id,locale_code)
            REFERENCES portal_announcement_translation_revisions(id,announcement_id,locale_code)
    )".$tail,
    "INSERT INTO portal_announcements
        (id,source_locale,source_revision,enabled,needs_locale_review,created_at,updated_at)
        SELECT $legacyId,'zh-Hans',1,COALESCE(legacy_item.enabled,1),1,
            COALESCE(NULLIF(legacy_item.created_at,0),UNIX_TIMESTAMP()),
            COALESCE(NULLIF(legacy_item.created_at,0),UNIX_TIMESTAMP())
        FROM $legacyFrom WHERE $legacyWhere",
    "INSERT INTO portal_announcement_translation_revisions
        (announcement_id,locale_code,revision,title,body,source_revision,source_hash,origin,created_at)
        SELECT $legacyId,'zh-Hans',1,TRIM(legacy_item.title),TRIM(legacy_item.body),1,
            SHA2(CONCAT('[',JSON_QUOTE(TRIM(legacy_item.title)),',',JSON_QUOTE(TRIM(legacy_item.body)),']'),256),
            'migration',COALESCE(NULLIF(legacy_item.created_at,0),UNIX_TIMESTAMP())
        FROM $legacyFrom WHERE $legacyWhere",
    "INSERT INTO portal_announcement_translations
        (announcement_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
        SELECT r.announcement_id,'zh-Hans',r.id,r.id,1,r.created_at
        FROM portal_announcement_translation_revisions r
        WHERE r.locale_code='zh-Hans' AND r.revision=1 AND r.origin='migration'",
];
