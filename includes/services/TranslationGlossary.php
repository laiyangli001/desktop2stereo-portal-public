<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class TranslationGlossary
{
    public function __construct(private PDO $db, private LocaleRegistry $locales = new LocaleRegistry()) {}

    public function view(int $adminId, string $sourceLocale, string $targetLocale): array
    {
        Permissions::require($this->db, $adminId, 'settings.manage');
        [$source, $target] = $this->localePair($sourceLocale, $targetLocale);
        $q = $this->db->prepare('SELECT revision,updated_at FROM portal_translation_glossary_versions WHERE source_locale=? AND target_locale=?');
        $q->execute([$source, $target]);
        $version = $q->fetch();
        $q = $this->db->prepare('SELECT source_term,target_term,mode FROM portal_translation_glossary_terms
            WHERE source_locale=? AND target_locale=? ORDER BY CHAR_LENGTH(source_term) DESC,source_term');
        $q->execute([$source, $target]);
        return [
            'source_locale' => $source,
            'target_locale' => $target,
            'revision' => $version ? (int)$version['revision'] : 0,
            'updated_at' => $version ? (int)$version['updated_at'] : null,
            'terms' => $q->fetchAll(),
        ];
    }

    public function save(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'settings.manage');
        $sourceLocale = $input['source_locale'] ?? null;
        $targetLocale = $input['target_locale'] ?? null;
        $expectedRevision = $input['expected_revision'] ?? null;
        $terms = $input['terms'] ?? null;
        if (!is_string($sourceLocale) || !is_string($targetLocale) || !is_int($expectedRevision) ||
            $expectedRevision < 0 || !is_array($terms) || count($terms) > 500) {
            throw new Problem('translation_glossary_invalid');
        }
        [$source, $target] = $this->localePair($sourceLocale, $targetLocale);
        $normalized = [];
        foreach ($terms as $term) {
            if (!is_array($term)) throw new Problem('translation_glossary_invalid');
            $from = $term['source_term'] ?? null;
            $to = $term['target_term'] ?? null;
            $mode = $term['mode'] ?? 'translate_as';
            if (!is_string($from) || !is_string($to) || !is_string($mode) ||
                !in_array($mode, ['translate_as', 'preserve'], true) ||
                !mb_check_encoding($from, 'UTF-8') || !mb_check_encoding($to, 'UTF-8') ||
                trim($from) === '' || trim($to) === '' || mb_strlen($from, 'UTF-8') > 100 ||
                mb_strlen($to, 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/u', $from.$to)) {
                throw new Problem('translation_glossary_invalid');
            }
            $from = trim($from);
            $to = trim($to);
            $key = mb_strtolower($from, 'UTF-8');
            if (isset($normalized[$key])) throw new Problem('translation_glossary_duplicate_term');
            $normalized[$key] = ['source_term' => $from, 'target_term' => $to, 'mode' => $mode];
        }
        return Database::transaction($this->db, function (PDO $db) use ($adminId, $source, $target, $expectedRevision, $normalized, $context, $now): array {
            $db->prepare('INSERT IGNORE INTO portal_translation_glossary_versions(source_locale,target_locale,revision,updated_by,updated_at)
                VALUES(?,?,0,NULL,?)')->execute([$source, $target, $now]);
            $q = $db->prepare('SELECT revision FROM portal_translation_glossary_versions WHERE source_locale=? AND target_locale=? FOR UPDATE');
            $q->execute([$source, $target]);
            $revision = (int)$q->fetchColumn();
            if ($revision !== $expectedRevision) throw new Problem('record_changed', 409);
            $next = $revision + 1;
            $db->prepare('DELETE FROM portal_translation_glossary_terms WHERE source_locale=? AND target_locale=?')
                ->execute([$source, $target]);
            $insert = $db->prepare('INSERT INTO portal_translation_glossary_terms(source_locale,target_locale,source_term,target_term,mode)
                VALUES(?,?,?,?,?)');
            foreach ($normalized as $term) $insert->execute([$source, $target, $term['source_term'], $term['target_term'], $term['mode']]);
            $db->prepare('UPDATE portal_translation_glossary_versions SET revision=?,updated_by=?,updated_at=? WHERE source_locale=? AND target_locale=?')
                ->execute([$next, $adminId, $now, $source, $target]);
            $db->prepare('INSERT INTO portal_translation_glossary_revisions(source_locale,target_locale,revision,terms_json,updated_by,created_at)
                VALUES(?,?,?,?,?,?)')->execute([$source, $target, $next,
                    json_encode(array_values($normalized), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId, $now]);
            (new Backoffice($db))->audit($adminId, 'UPDATE_TRANSLATION_GLOSSARY', $source.':'.$target, [
                'source_locale' => $source, 'target_locale' => $target, 'revision' => $next, 'term_count' => count($normalized),
            ], $context, $now);
            return ['source_locale' => $source, 'target_locale' => $target, 'revision' => $next, 'term_count' => count($normalized)];
        });
    }

    public function history(int $adminId, string $sourceLocale, string $targetLocale): array
    {
        Permissions::require($this->db, $adminId, 'settings.manage');
        [$source, $target] = $this->localePair($sourceLocale, $targetLocale);
        $q = $this->db->prepare('SELECT revision,updated_by,created_at,JSON_LENGTH(terms_json) AS term_count
            FROM portal_translation_glossary_revisions WHERE source_locale=? AND target_locale=? ORDER BY revision DESC LIMIT 100');
        $q->execute([$source, $target]);
        return ['source_locale' => $source, 'target_locale' => $target, 'revisions' => $q->fetchAll()];
    }

    public function restore(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'settings.manage');
        $sourceLocale = $input['source_locale'] ?? null;
        $targetLocale = $input['target_locale'] ?? null;
        $expectedRevision = $input['expected_revision'] ?? null;
        $restoreRevision = $input['restore_revision'] ?? null;
        if (!is_string($sourceLocale) || !is_string($targetLocale) || !is_int($expectedRevision) ||
            $expectedRevision < 0 || !is_int($restoreRevision) || $restoreRevision < 1) {
            throw new Problem('translation_glossary_invalid');
        }
        [$source, $target] = $this->localePair($sourceLocale, $targetLocale);
        return Database::transaction($this->db, function (PDO $db) use ($adminId, $source, $target, $expectedRevision, $restoreRevision, $context, $now): array {
            $q = $db->prepare('SELECT revision FROM portal_translation_glossary_versions WHERE source_locale=? AND target_locale=? FOR UPDATE');
            $q->execute([$source, $target]);
            $current = $q->fetchColumn();
            $currentRevision = $current === false ? 0 : (int)$current;
            if ($currentRevision !== $expectedRevision) throw new Problem('record_changed', 409);
            $q = $db->prepare('SELECT terms_json FROM portal_translation_glossary_revisions WHERE source_locale=? AND target_locale=? AND revision=?');
            $q->execute([$source, $target, $restoreRevision]);
            $json = $q->fetchColumn();
            if ($json === false) throw new Problem('translation_glossary_revision_not_found', 404);
            $terms = json_decode((string)$json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($terms) || count($terms) > 500) throw new Problem('translation_glossary_invalid', 409);
            $db->prepare('DELETE FROM portal_translation_glossary_terms WHERE source_locale=? AND target_locale=?')->execute([$source, $target]);
            $insert = $db->prepare('INSERT INTO portal_translation_glossary_terms(source_locale,target_locale,source_term,target_term,mode) VALUES(?,?,?,?,?)');
            foreach ($terms as $term) {
                if (!is_array($term) || !isset($term['source_term'], $term['target_term'], $term['mode']) ||
                    !is_string($term['source_term']) || !is_string($term['target_term']) ||
                    !in_array($term['mode'], ['translate_as', 'preserve'], true)) throw new Problem('translation_glossary_invalid', 409);
                $insert->execute([$source, $target, $term['source_term'], $term['target_term'], $term['mode']]);
            }
            $next = $currentRevision + 1;
            $db->prepare('INSERT IGNORE INTO portal_translation_glossary_versions(source_locale,target_locale,revision,updated_by,updated_at) VALUES(?,?,0,NULL,?)')
                ->execute([$source, $target, $now]);
            $db->prepare('UPDATE portal_translation_glossary_versions SET revision=?,updated_by=?,updated_at=? WHERE source_locale=? AND target_locale=?')
                ->execute([$next, $adminId, $now, $source, $target]);
            $db->prepare('INSERT INTO portal_translation_glossary_revisions(source_locale,target_locale,revision,terms_json,updated_by,created_at) VALUES(?,?,?,?,?,?)')
                ->execute([$source, $target, $next, json_encode(array_values($terms), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId, $now]);
            (new Backoffice($db))->audit($adminId, 'RESTORE_TRANSLATION_GLOSSARY', $source.':'.$target, [
                'source_locale' => $source, 'target_locale' => $target, 'restored_revision' => $restoreRevision, 'revision' => $next,
            ], $context, $now);
            return ['source_locale' => $source, 'target_locale' => $target, 'revision' => $next, 'term_count' => count($terms)];
        });
    }

    public function snapshot(string $sourceLocale, string $targetLocale): array
    {
        [$source, $target] = $this->localePair($sourceLocale, $targetLocale);
        $q = $this->db->prepare('SELECT revision FROM portal_translation_glossary_versions WHERE source_locale=? AND target_locale=?');
        $q->execute([$source, $target]);
        $revision = $q->fetchColumn();
        $terms = $this->db->prepare('SELECT source_term,target_term,mode FROM portal_translation_glossary_terms
            WHERE source_locale=? AND target_locale=? ORDER BY CHAR_LENGTH(source_term) DESC,source_term');
        $terms->execute([$source, $target]);
        return ['revision' => $revision === false ? 0 : (int)$revision, 'terms' => $terms->fetchAll()];
    }

    private function localePair(string $sourceLocale, string $targetLocale): array
    {
        $source = $this->locales->normalizeForAdmin($sourceLocale);
        $target = $this->locales->normalizeForAdmin($targetLocale);
        if ($source === null || $target === null) throw new Problem('locale_unsupported', 400);
        if ($source === $target) throw new Problem('translation_target_is_source', 409);
        return [$source, $target];
    }
}
