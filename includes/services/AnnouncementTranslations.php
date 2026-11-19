<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class AnnouncementTranslations
{
    public function __construct(private PDO $db, private LocaleRegistry $locales)
    {
    }

    public function publicList(string $requestedLocale, int $now = 0, string $placement = 'header'): array
    {
        $now = $now > 0 ? $now : time();
        if (!in_array($placement, ['header', 'home', 'both', 'popup'], true)) throw new Problem('site_announcement_invalid');
        $placements = match ($placement) {
            'header' => ['header', 'both'],
            'home' => ['home', 'both'],
            'popup' => ['popup'],
            default => ['both'],
        };
        $locale = $this->locales->normalize($requestedLocale, true) ?? $this->locales->defaultLocale();
        if (!(bool)($this->locales->get($locale)['public_enabled'] ?? false)) {
            $locale = $this->locales->defaultLocale();
        }
        $publicCodes = array_column($this->locales->enabled(true), 'code');
        $chain = array_values(array_filter(
            array_unique([$locale, ...$this->locales->fallbacks($locale), $this->locales->defaultLocale()]),
            static fn(string $candidate): bool => in_array($candidate, $publicCodes, true),
        ));
        $marks = implode(',', array_fill(0, count($placements), '?'));
        $query = $this->db->prepare("SELECT a.id,a.created_at,a.placement,a.starts_at,a.ends_at,t.locale_code,r.title,r.body
            FROM portal_announcements a
            JOIN portal_announcement_translations t ON t.announcement_id=a.id AND t.published_revision_id IS NOT NULL
            JOIN portal_announcement_translation_revisions r
              ON r.id=t.published_revision_id AND r.announcement_id=t.announcement_id AND r.locale_code=t.locale_code
            WHERE a.enabled=1 AND a.deleted_at IS NULL AND a.needs_locale_review=0
              AND a.placement IN ($marks)
              AND (a.starts_at IS NULL OR a.starts_at<=?)
              AND (a.ends_at IS NULL OR a.ends_at>?)
            ORDER BY COALESCE(a.starts_at,a.created_at) DESC");
        $query->execute([...$placements, $now, $now]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $row) $byId[$row['id']][$row['locale_code']] = $row;
        $result = [];
        foreach ($byId as $id=>$translations) {
            $candidates=(new ContentPublicationLanguages($this->db))->candidates('announcement',(string)$id,$locale,$this->locales->defaultLocale());
            foreach ($candidates as $candidate) {
                if (!isset($translations[$candidate])) continue;
                $row = $translations[$candidate];
                $result[] = ['id'=>$row['id'],'created_at'=>(int)$row['created_at'],
                    'placement'=>(string)$row['placement'],
                    'starts_at'=>is_numeric($row['starts_at'] ?? null) ? (int)$row['starts_at'] : null,
                    'ends_at'=>is_numeric($row['ends_at'] ?? null) ? (int)$row['ends_at'] : null,
                    'title'=>(string)$row['title'],'body'=>(string)$row['body'],
                    'content_locale'=>(string)$row['locale_code']];
                break;
            }
        }
        usort($result, static fn(array $a,array $b): int =>
            ($b['starts_at'] ?? $b['created_at']) <=> ($a['starts_at'] ?? $a['created_at']));
        return array_slice($result, 0, 10);
    }

    public function adminList(string $contentLocale): array
    {
        $locale = $this->locales->normalizeForAdmin($contentLocale);
        if ($locale === null) throw new Problem('locale_not_available');
        $query = $this->db->prepare("SELECT a.id,a.source_locale,a.source_revision,a.placement,a.enabled,a.lock_version AS entity_lock_version,
                a.needs_locale_review,a.starts_at,a.ends_at,a.created_at,a.draft_json,t.draft_revision_id,t.published_revision_id,t.lock_version AS translation_lock_version,
                d.title AS draft_title,d.body AS draft_body,p.title AS published_title,p.body AS published_body,
                COALESCE(sd.title,sp.title,'') AS source_title,sp.title AS source_published_title,
                COALESCE(sd.body,sp.body,'') AS source_body,sp.body AS source_published_body,
                (SELECT GROUP_CONCAT(h.locale_code ORDER BY h.locale_code SEPARATOR ',')
                    FROM portal_announcement_translations h WHERE h.announcement_id=a.id AND h.published_revision_id IS NOT NULL) AS published_locales
            FROM portal_announcements a
            LEFT JOIN portal_announcement_translations t ON t.announcement_id=a.id AND t.locale_code=?
            LEFT JOIN portal_announcement_translation_revisions d ON d.id=t.draft_revision_id
            LEFT JOIN portal_announcement_translation_revisions p ON p.id=t.published_revision_id
            LEFT JOIN portal_announcement_translations st ON st.announcement_id=a.id AND st.locale_code=a.source_locale
            LEFT JOIN portal_announcement_translation_revisions sd ON sd.id=st.draft_revision_id
            LEFT JOIN portal_announcement_translation_revisions sp ON sp.id=st.published_revision_id
            WHERE a.deleted_at IS NULL ORDER BY a.created_at DESC");
        $query->execute([$locale]);
        return array_map(function(array $row) use ($locale): array {
            $selected=(new ContentPublicationLanguages($this->db))->selected('announcement',(string)$row['id']);
            $published=$row['published_locales']===null?[]:explode(',',$row['published_locales']);
            if($selected!==null)$published=array_values(array_intersect($published,$selected));
            $draft=$row['draft_json']===null?null:json_decode($row['draft_json'],true,16,JSON_THROW_ON_ERROR);
            $localizedDraft=$draft!==null&&$draft['locale']===$locale?$draft:null;
            return [
            'draft'=>$draft,
            'publication_locales'=>$selected??[$row['source_locale'],'en'],
            'published_locales'=>$published,'is_published'=>$published!==[],
            'source_title'=>(string)$row['source_title'],'source_published_title'=>(string)($row['source_published_title']??''),
            'source_body'=>(string)$row['source_body'],'source_published_body'=>(string)($row['source_published_body']??''),
            'id'=>(string)$row['id'],'source_locale'=>(string)$row['source_locale'],
            'source_revision'=>(int)$row['source_revision'],'placement'=>(string)$row['placement'],
            'enabled'=>(bool)$row['enabled'],'starts_at'=>is_numeric($row['starts_at'] ?? null) ? (int)$row['starts_at'] : null,
            'ends_at'=>is_numeric($row['ends_at'] ?? null) ? (int)$row['ends_at'] : null,
            'entity_lock_version'=>(int)$row['entity_lock_version'],
            'translation_lock_version'=>(int)($row['translation_lock_version'] ?? 0),
            'needs_locale_review'=>(bool)$row['needs_locale_review'],'created_at'=>(int)$row['created_at'],
            'title'=>(string)($localizedDraft['title'] ?? $row['draft_title'] ?? $row['published_title'] ?? ''),
            'body'=>(string)($localizedDraft['body'] ?? $row['draft_body'] ?? $row['published_body'] ?? ''),
            'published_title'=>(string)($row['published_title'] ?? ''),
            'published_body'=>(string)($row['published_body'] ?? ''),
            'has_draft'=>$localizedDraft!==null||$row['draft_revision_id']!==null,
        ]; }, $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function save(int $adminId, string $contentLocale, array $items, array $deletedIds, array $context, int $now, bool $publish = true, bool $translateDraft = false, array $targetLocales = []): array
    {
        Permissions::require($this->db, $adminId, 'announcements.manage');
        $locale = $this->locales->normalizeForAdmin($contentLocale);
        if ($locale === null || count($items) > 20 || count($deletedIds) > 20) throw new Problem('site_announcement_invalid');
        return Database::transactionOrJoin($this->db, function(PDO $db) use ($adminId,$locale,$items,$deletedIds,$context,$now,$publish,$translateDraft,$targetLocales): array {
            foreach ($deletedIds as $id) {
                if (!preg_match('/^[a-f0-9]{16}$/D', (string)$id)) throw new Problem('site_announcement_invalid');
                $db->prepare('UPDATE portal_announcements SET deleted_at=?,draft_json=NULL,updated_at=?,source_revision=source_revision+1,lock_version=lock_version+1 WHERE id=? AND deleted_at IS NULL')
                    ->execute([$now,$now,$id]);
                $db->prepare('UPDATE portal_announcement_translations SET draft_revision_id=NULL,published_revision_id=NULL,
                    lock_version=lock_version+1,updated_at=? WHERE announcement_id=?')->execute([$now,$id]);
                (new ContentPublicationLanguages($db))->cancel('announcement',$id,$now);
            }
            foreach ($items as $item) {
                if (!is_array($item)) throw new Problem('site_announcement_invalid');
                $title = trim((string)($item['title'] ?? ''));
                $body = trim((string)($item['body'] ?? ''));
                $id = preg_match('/^[a-f0-9]{16}$/D',(string)($item['id'] ?? '')) ? (string)$item['id'] : bin2hex(random_bytes(8));
                if ($title === '' || mb_strlen($title,'UTF-8') > 120 || $body === '' || mb_strlen($body,'UTF-8') > 3000) {
                    throw new Problem('site_announcement_invalid');
                }
                $placement = (string)($item['placement'] ?? 'header');
                $startsAt = self::scheduleTimestamp($item['starts_at'] ?? null);
                $endsAt = self::scheduleTimestamp($item['ends_at'] ?? null);
                if (!in_array($placement, ['header', 'home', 'both', 'popup'], true)
                    || ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt)) {
                    throw new Problem('site_announcement_invalid');
                }
                $draftJson=$publish?null:json_encode([
                    'locale'=>$locale,'title'=>$title,'body'=>$body,'placement'=>$placement,
                    'enabled'=>(bool)($item['enabled']??true),'starts_at'=>$startsAt,'ends_at'=>$endsAt,
                    'target_locales'=>$targetLocales,
                ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                $isNew = !isset($item['id']) || $item['id'] === '';
                if (!$isNew) {
                    $lock = $db->prepare('SELECT lock_version,source_locale,source_revision FROM portal_announcements WHERE id=? AND deleted_at IS NULL FOR UPDATE');
                    $lock->execute([$id]);
                    $entity = $lock->fetch(PDO::FETCH_ASSOC);
                    if ($entity === false || (int)$entity['lock_version'] !== (int)($item['entity_lock_version'] ?? 0)) throw new Problem('record_changed',409);
                    $sourceLocale = (string)$entity['source_locale'];
                    $sourceRevision = (int)$entity['source_revision'];
                    if($publish){
                        $db->prepare('UPDATE portal_announcements SET placement=?,enabled=?,starts_at=?,ends_at=?,
                            draft_json=NULL,updated_at=?,lock_version=lock_version+1 WHERE id=?')
                            ->execute([$placement,($item['enabled']??true)?1:0,$startsAt,$endsAt,$now,$id]);
                    }else{
                        $db->prepare('UPDATE portal_announcements SET draft_json=?,lock_version=lock_version+1 WHERE id=?')
                            ->execute([$draftJson,$id]);
                    }
                } else {
                    $sourceLocale = $locale;
                    $sourceRevision = 1;
                    $db->prepare('INSERT INTO portal_announcements
                        (id,source_locale,source_revision,placement,enabled,needs_locale_review,starts_at,ends_at,lock_version,created_at,updated_at,draft_json)
                        VALUES (?,?,1,?,?,0,?,?,1,?,?,?)')
                        ->execute([$id,$sourceLocale,$publish?$placement:'header',$publish&&($item['enabled']??true)?1:0,
                            $publish?$startsAt:null,$publish?$endsAt:null,$now,$now,$draftJson]);
                }
                $head = $db->prepare('SELECT draft_revision_id,published_revision_id,lock_version FROM portal_announcement_translations WHERE announcement_id=? AND locale_code=? FOR UPDATE');
                $head->execute([$id,$locale]);
                $current = $head->fetch(PDO::FETCH_ASSOC);
                $expected = (int)($item['translation_lock_version'] ?? 0);
                if (($current === false && $expected !== 0) || ($current !== false && (int)$current['lock_version'] !== $expected)) throw new Problem('record_changed',409);
                if(!$publish&&!$translateDraft)continue;
                $revision = $db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_announcement_translation_revisions WHERE announcement_id=? AND locale_code=?');
                $revision->execute([$id,$locale]);
                $nextRevision = (int)$revision->fetchColumn();
                $hash = ContentSourceHash::announcement($title,$body);
                if ($locale === $sourceLocale) {
                    if (!$isNew) {
                        $sourceRevision++;
                        $db->prepare('UPDATE portal_announcements
                            SET source_revision=?,needs_locale_review=CASE WHEN ?=1 THEN 0 ELSE needs_locale_review END,updated_at=?
                            WHERE id=?')->execute([$sourceRevision,$publish?1:0,$now,$id]);
                    }
                    $sourceHash = $hash;
                } else {
                    $sourceHead = $db->prepare('SELECT r.source_hash FROM portal_announcement_translations h JOIN portal_announcement_translation_revisions r ON r.id=h.draft_revision_id WHERE h.announcement_id=? AND h.locale_code=?');
                    $sourceHead->execute([$id,$sourceLocale]);
                    $sourceHash = (string)($sourceHead->fetchColumn() ?: '');
                }
                $db->prepare("INSERT INTO portal_announcement_translation_revisions
                    (announcement_id,locale_code,revision,title,body,source_revision,source_hash,origin,author_admin_id,created_at)
                    VALUES (?,?,?,?,?,?,?,'manual',?,?)")
                    ->execute([$id,$locale,$nextRevision,$title,$body,$sourceRevision,$sourceHash,$adminId,$now]);
                $revisionId = (int)$db->lastInsertId();
                if ($current === false) {
                    $db->prepare('INSERT INTO portal_announcement_translations
                        (announcement_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                        VALUES (?,?,?,?,1,?)')
                        ->execute([$id,$locale,$revisionId,$publish ? $revisionId : null,$now]);
                } elseif ($publish) {
                    $db->prepare('UPDATE portal_announcement_translations
                        SET draft_revision_id=?,published_revision_id=?,lock_version=lock_version+1,updated_at=?
                        WHERE announcement_id=? AND locale_code=?')
                        ->execute([$revisionId,$revisionId,$now,$id,$locale]);
                } else {
                    $db->prepare('UPDATE portal_announcement_translations
                        SET draft_revision_id=?,lock_version=lock_version+1,updated_at=?
                        WHERE announcement_id=? AND locale_code=?')
                        ->execute([$revisionId,$now,$id,$locale]);
                }
            }
            $emailQuery=$db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
            $emailQuery->execute([$adminId]);
            $email=$emailQuery->fetchColumn();
            if ($email === false) throw new Problem('administrator_inactive',403);
            Audit::admin($db,$adminId,(string)$email,$publish?'PUBLISH_SITE_ANNOUNCEMENT_TRANSLATIONS':'SAVE_SITE_ANNOUNCEMENT_DRAFTS',$locale,$context,'SUCCESS',
                ['saved_count'=>count($items),'deleted_count'=>count($deletedIds),'published'=>$publish],$now);
            if($publish||$deletedIds!==[]){
                EdgeOneCachePurge::enqueueAllPublic($db,'announcement:'.bin2hex(random_bytes(16)),$now);
            }
            return $this->adminList($locale);
        });
    }

    private static function scheduleTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            throw new Problem('site_announcement_invalid');
        }
        $timestamp = (int)$value;
        if ($timestamp <= 0) throw new Problem('site_announcement_invalid');
        return $timestamp;
    }
}
