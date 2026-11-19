<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class ArticleTranslations
{
    public function __construct(private PDO $db, private LocaleRegistry $locales = new LocaleRegistry())
    {
    }

    public function catalog(int $adminId, string $articleId): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $article = $this->article($articleId);
        $query = $this->db->prepare(
            'SELECT t.locale_code,t.draft_revision_id,t.published_revision_id,t.lock_version,t.updated_at,
                d.title draft_title,d.source_revision draft_source_revision,
                p.title published_title,p.revision published_revision,p.source_revision published_source_revision
             FROM portal_article_translations t
             LEFT JOIN portal_article_translation_revisions d ON d.id=t.draft_revision_id
             LEFT JOIN portal_article_translation_revisions p ON p.id=t.published_revision_id
             WHERE t.article_id=? ORDER BY t.locale_code'
        );
        $query->execute([$articleId]);
        $items = [];
        foreach ($query->fetchAll() as $row) {
            $locale = $this->locales->get((string)$row['locale_code']);
            $items[] = [
                'locale' => $row['locale_code'],
                'native_name' => $locale['native_name'] ?? (string)$row['locale_code'],
                'draft_revision_id' => $row['draft_revision_id'] === null ? null : (int)$row['draft_revision_id'],
                'published_revision_id' => $row['published_revision_id'] === null ? null : (int)$row['published_revision_id'],
                'lock_version' => (int)$row['lock_version'],
                'updated_at' => (int)$row['updated_at'],
                'draft_title' => $row['draft_title'],
                'published_title' => $row['published_title'],
                'published_revision' => $row['published_revision'] === null ? null : (int)$row['published_revision'],
                'outdated' => $row['draft_source_revision'] !== null
                    && (int)$row['draft_source_revision'] < (int)$article['source_revision'],
            ];
        }
        return [
            'article_id' => $articleId,
            'source_locale' => $article['source_locale'],
            'source_revision' => (int)$article['source_revision'],
            'status' => $article['status'],
            'items' => $items,
        ];
    }

    public function locales(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'articles.manage');
        return ['items'=>array_map(static fn(array $locale):array=>[
            'code'=>$locale['code'],'native_name'=>$locale['native_name'],'enabled'=>(bool)$locale['enabled'],
            'public_enabled'=>(bool)$locale['public_enabled'],'direction'=>$locale['direction'],
        ],$this->locales->all())];
    }

    public function get(int $adminId, string $articleId, string $requestedLocale): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $locale = $this->locale($requestedLocale);
        $article = $this->article($articleId);
        $query = $this->db->prepare(
            'SELECT t.draft_revision_id,t.published_revision_id,t.lock_version,
                d.revision,d.title,d.summary,d.body,d.body_format,d.seo_title,d.seo_description,d.source_revision,d.source_hash,d.created_at
             FROM portal_article_translations t
             LEFT JOIN portal_article_translation_revisions d ON d.id=t.draft_revision_id
             WHERE t.article_id=? AND t.locale_code=?'
        );
        $query->execute([$articleId, $locale]);
        $row = $query->fetch() ?: [];
        return [
            'article_id' => $articleId,
            'locale' => $locale,
            'slug' => $article['slug'],
            'category_id' => $article['category_id'],
            'category' => (new ArticleCategories($this->db,$this->locales))->publicLabel((string)$article['category_id'],$locale)['label'],
            'source_locale' => $article['source_locale'],
            'source_revision' => (int)$article['source_revision'],
            'status' => $article['status'],
            'draft_revision_id' => $row['draft_revision_id'] ?? null,
            'published_revision_id' => $row['published_revision_id'] ?? null,
            'lock_version' => isset($row['lock_version']) ? (int)$row['lock_version'] : 0,
            'revision' => isset($row['revision']) ? (int)$row['revision'] : 0,
            'title' => $row['title'] ?? '',
            'summary' => $row['summary'] ?? '',
            'body' => $row['body'] ?? '',
            'body_format' => $row['body_format'] ?? 'html',
            'seo_title' => $row['seo_title'] ?? '',
            'seo_description' => $row['seo_description'] ?? '',
            'outdated' => isset($row['source_revision']) && (int)$row['source_revision'] < (int)$article['source_revision'],
        ];
    }

    public function create(int $adminId, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $locale = $this->locale((string)($input['locale'] ?? ''));
        $slug = Backoffice::text($input, 'slug', 120, true);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            throw new Problem('article_slug_invalid');
        }
        $categoryValue = $input['category_id'] ?? $input['category'] ?? '';
        if (!is_string($categoryValue)) throw new Problem('article_category_invalid');
        $category = ArticleCategories::enabledCode($this->db,$categoryValue);
        $categoryLabel = (new ArticleCategories($this->db,$this->locales))->publicLabel($category,$locale)['label'];
        if ($categoryLabel === '') throw new Problem('article_category_invalid');
        $title = Backoffice::text($input, 'title', 240, true);
        $summary = Backoffice::text($input, 'summary', 500);
        $format = $input['body_format'] ?? 'html';
        if (!in_array($format, ['plain','html'], true) || !is_string($input['body'] ?? null)) {
            throw new Problem('article_format_invalid');
        }
        $body = $format === 'html'
            ? ArticleContent::clean($input['body'], true)
            : Backoffice::text($input, 'body', 14000, true);
        if ($body === '') throw new Problem('article_body_required');
        $seoTitle = Backoffice::text($input, 'seo_title', 240);
        $seoDescription = Backoffice::text($input, 'seo_description', 500);
        $id = bin2hex(random_bytes(16));
        $sourceHash = self::sourceHash(['title'=>$title,'summary'=>$summary,'body'=>$body]);
        return Database::transactionOrJoin($this->db, function (PDO $db) use (
            $adminId,$locale,$slug,$category,$categoryLabel,$title,$summary,$format,$body,$seoTitle,$seoDescription,$id,$sourceHash,$context,$now
        ): array {
            $titleZh = $locale === 'zh-Hans' ? $title : '';
            $titleEn = $locale === 'en' ? $title : '';
            $summaryZh = $locale === 'zh-Hans' ? $summary : '';
            $summaryEn = $locale === 'en' ? $summary : '';
            $bodyZh = $locale === 'zh-Hans' ? $body : '';
            $bodyEn = $locale === 'en' ? $body : '';
            $legacySeoTitle = $locale === 'zh-Hans' ? $seoTitle : '';
            $legacySeoDescription = $locale === 'zh-Hans' ? $seoDescription : '';
            $db->prepare(
                'INSERT INTO portal_articles
                    (id,slug,category,category_id,title_zh,title_en,summary_zh,summary_en,body_zh,body_en,body_format,status,seo_title,seo_description,
                     author_id,source_locale,source_revision,published_at,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NULL,?,?)'
            )->execute([
                $id,$slug,$categoryLabel,$category,$titleZh,$titleEn,$summaryZh,$summaryEn,$bodyZh,$bodyEn,$format,'draft',
                $legacySeoTitle,$legacySeoDescription,$adminId,$locale,$now,$now,
            ]);
            $db->prepare(
                "INSERT INTO portal_article_translation_revisions
                    (article_id,locale_code,revision,title,summary,body,body_format,seo_title,seo_description,source_revision,source_hash,origin,author_admin_id,created_at)
                 VALUES(?,?,1,?,?,?,?,?,?,1,?,'manual',?,?)"
            )->execute([$id,$locale,$title,$summary,$body,$format,$seoTitle,$seoDescription,$sourceHash,$adminId,$now]);
            $revisionId=(int)$db->lastInsertId();
            $db->prepare(
                'INSERT INTO portal_article_translations(article_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                 VALUES(?,?,?,NULL,1,?)'
            )->execute([$id,$locale,$revisionId,$now]);
            (new Backoffice($db))->audit($adminId,'CREATE_ARTICLE_TRANSLATION',$id,[
                'locale'=>$locale,'revision'=>1,'source_revision'=>1,
            ],$context,$now);
            return ['id'=>$id,'locale'=>$locale,'revision_id'=>$revisionId,'revision'=>1,'lock_version'=>1];
        });
    }

    public function save(int $adminId, string $articleId, string $requestedLocale, array $input, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $locale = $this->locale($requestedLocale);
        $slug = Backoffice::text($input, 'slug', 120, true);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            throw new Problem('article_slug_invalid');
        }
        $categoryValue = $input['category_id'] ?? $input['category'] ?? '';
        if (!is_string($categoryValue)) throw new Problem('article_category_invalid');
        $category = ArticleCategories::enabledCode($this->db,$categoryValue);
        $title = Backoffice::text($input, 'title', 240, true);
        $summary = Backoffice::text($input, 'summary', 500);
        $format = $input['body_format'] ?? 'html';
        if (!in_array($format, ['plain','html'], true)) {
            throw new Problem('article_format_invalid');
        }
        if (!is_string($input['body'] ?? null)) {
            throw new Problem('field_invalid');
        }
        $body = $format === 'html'
            ? ArticleContent::clean($input['body'] ?? '', true)
            : Backoffice::text($input, 'body', 14000, true);
        if ($body === '') {
            throw new Problem('article_body_required');
        }
        $seoTitle = Backoffice::text($input, 'seo_title', 240);
        $seoDescription = Backoffice::text($input, 'seo_description', 500);
        $expectedVersion = filter_var($input['lock_version'] ?? null, FILTER_VALIDATE_INT);
        if ($expectedVersion === false || $expectedVersion < 0) {
            throw new Problem('record_changed', 409);
        }

        return Database::transactionOrJoin($this->db, function (PDO $db) use (
            $adminId, $articleId, $locale, $slug, $category, $title, $summary, $format, $body,
            $seoTitle, $seoDescription, $expectedVersion, $context, $now
        ): array {
            $q = $db->prepare('SELECT id,slug,source_locale,source_revision,status,published_at,updated_at FROM portal_articles WHERE id=? FOR UPDATE');
            $q->execute([$articleId]);
            $article = $q->fetch();
            if (!$article) {
                throw new Problem('article_not_found', 404);
            }
            $q = $db->prepare('SELECT lock_version,draft_revision_id FROM portal_article_translations WHERE article_id=? AND locale_code=? FOR UPDATE');
            $q->execute([$articleId, $locale]);
            $current = $q->fetch() ?: null;
            $currentVersion = $current === null ? 0 : (int)$current['lock_version'];
            if ($expectedVersion !== $currentVersion) {
                throw new Problem('record_changed', 409);
            }

            $sourceLocale = (string)$article['source_locale'];
            $sourceRevision = (int)$article['source_revision'];
            $isSource = $locale === $sourceLocale;
            $nextSourceRevision = $sourceRevision + ($isSource ? 1 : 0);
            $sourceData = $isSource
                ? ['title'=>$title,'summary'=>$summary,'body'=>$body]
                : $this->sourceSnapshot($db, $articleId, $sourceLocale);
            $sourceHash = self::sourceHash($sourceData);
            $q = $db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM portal_article_translation_revisions WHERE article_id=? AND locale_code=?');
            $q->execute([$articleId, $locale]);
            $revision = (int)$q->fetchColumn();
            $db->prepare(
                'INSERT INTO portal_article_translation_revisions
                    (article_id,locale_code,revision,title,summary,body,body_format,seo_title,seo_description,source_revision,source_hash,origin,author_admin_id,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,\'manual\',?,?)'
            )->execute([
                $articleId,$locale,$revision,$title,$summary,$body,$format,$seoTitle,$seoDescription,
                $isSource ? $nextSourceRevision : $sourceRevision,$sourceHash,$adminId,$now,
            ]);
            $revisionId = (int)$db->lastInsertId();
            $lockVersion = $currentVersion + 1;
            $db->prepare(
                'INSERT INTO portal_article_translations(article_id,locale_code,draft_revision_id,published_revision_id,lock_version,updated_at)
                 VALUES(?,?,?,NULL,?,?)
                 ON DUPLICATE KEY UPDATE draft_revision_id=VALUES(draft_revision_id),lock_version=VALUES(lock_version),updated_at=VALUES(updated_at)'
            )->execute([$articleId,$locale,$revisionId,$lockVersion,$now]);

            $parentUpdatedAt = max($now, (int)$article['updated_at'] + 1);
            $categoryLabel=(new ArticleCategories($db,$this->locales))->publicLabel($category,$sourceLocale)['label'];
            if ($categoryLabel==='') throw new Problem('article_category_invalid');
            if ($isSource) {
                $db->prepare('UPDATE portal_articles SET slug=?,category=?,category_id=?,source_revision=?,updated_at=? WHERE id=?')
                    ->execute([$slug,$categoryLabel,$category,$nextSourceRevision,$parentUpdatedAt,$articleId]);
            } else {
                $db->prepare('UPDATE portal_articles SET slug=?,category=?,category_id=?,updated_at=? WHERE id=?')
                    ->execute([$slug,$categoryLabel,$category,$parentUpdatedAt,$articleId]);
            }
            if ($locale === 'zh-Hans') {
                $db->prepare('UPDATE portal_articles SET title_zh=?,summary_zh=?,body_zh=?,body_format=?,seo_title=?,seo_description=? WHERE id=?')
                    ->execute([$title,$summary,$body,$format,$seoTitle,$seoDescription,$articleId]);
            } elseif ($locale === 'en') {
                $db->prepare('UPDATE portal_articles SET title_en=?,summary_en=?,body_en=? WHERE id=?')
                    ->execute([$title,$summary,$body,$articleId]);
            }
            (new Backoffice($db))->audit($adminId,'SAVE_ARTICLE_TRANSLATION',$articleId,[
                'locale'=>$locale,'revision'=>$revision,'source_revision'=>$isSource?$nextSourceRevision:$sourceRevision,
            ],$context,$now);
            if ($article['status'] === 'published') {
                EdgeOneCachePurge::enqueuePaths($db,['/help','/help/'.(string)$article['slug'],'/help/'.$slug,'/sitemap.xml'],
                    'article:save:'.hash('sha256',$articleId.':'.$locale.':'.$revisionId),$now);
            }
            return ['article_id'=>$articleId,'locale'=>$locale,'revision_id'=>$revisionId,'revision'=>$revision,
                'lock_version'=>$lockVersion,'source_revision'=>$isSource?$nextSourceRevision:$sourceRevision,'outdated'=>false];
        });
    }

    public function publish(int $adminId, string $articleId, string $requestedLocale, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'articles.manage');
        $locale = $this->locale($requestedLocale);
        return Database::transactionOrJoin($this->db, function (PDO $db) use ($adminId,$articleId,$locale,$context,$now): array {
            $q = $db->prepare('SELECT id,slug,source_locale,status,published_at FROM portal_articles WHERE id=? FOR UPDATE');
            $q->execute([$articleId]);
            $article = $q->fetch();
            if (!$article) throw new Problem('article_not_found',404);
            if ($article['status'] === 'archived') throw new Problem('article_archived',409);
            $q=$db->prepare('SELECT draft_revision_id,lock_version FROM portal_article_translations WHERE article_id=? AND locale_code=? FOR UPDATE');
            $q->execute([$articleId,$locale]);
            $translation=$q->fetch();
            if(!$translation || $translation['draft_revision_id']===null) throw new Problem('article_translation_draft_missing',409);
            $db->prepare('UPDATE portal_article_translations SET published_revision_id=?,lock_version=lock_version+1,updated_at=? WHERE article_id=? AND locale_code=?')
                ->execute([$translation['draft_revision_id'],$now,$articleId,$locale]);
            if($article['source_locale']===$locale && $article['status']!=='published'){
                $db->prepare("UPDATE portal_articles SET status='published',published_at=COALESCE(published_at,?),updated_at=? WHERE id=?")
                    ->execute([$now,$now,$articleId]);
            }
            (new Backoffice($db))->audit($adminId,'PUBLISH_ARTICLE_TRANSLATION',$articleId,['locale'=>$locale],$context,$now);
            EdgeOneCachePurge::enqueuePaths($db,['/help','/help/'.(string)$article['slug'],'/sitemap.xml'],
                'article:publish:'.hash('sha256',$articleId.':'.$locale.':'.$translation['draft_revision_id']),$now);
            return ['article_id'=>$articleId,'locale'=>$locale,'published_revision_id'=>(int)$translation['draft_revision_id']];
        });
    }

    public function publishLanguages(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'articles.manage');
        if(!is_array($input['target_locales']??null)||!is_string($input['locale']??null))throw new Problem('translation_targets_invalid');
        $languages=new ContentPublicationLanguages($this->db,$this->locales);
        $selected=$languages->targets($input['locale'],$input['target_locales']);
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$input,$selected,$languages,$context,$now):array{
            $id=$input['id']??'';
            if(!is_string($id))throw new Problem('article_not_found',404);
            if($id===''){
                $saved=$this->create($adminId,$input,$context,$now);$id=$saved['id'];
            }else{
                $article=$this->article($id);
                $lock=$db->prepare('SELECT source_locale,status FROM portal_articles WHERE id=? FOR UPDATE');$lock->execute([$id]);$row=$lock->fetch();
                if($row['status']==='archived')throw new Problem('article_archived',409);
                if($row['source_locale']!==$selected[0])$db->prepare('UPDATE portal_articles SET source_locale=?,source_revision=source_revision+1 WHERE id=?')
                    ->execute([$selected[0],$id]);
                $saved=$this->save($adminId,$id,$selected[0],$input,$context,$now);
            }
            $this->publish($adminId,$id,$selected[0],$context,$now);
            $languages->save('article',$id,$selected[0],$selected,$now);
            $targets=array_values(array_diff($selected,[$selected[0]]));
            $jobs=$targets===[]?[]:(new TranslationJobs($db,$this->locales))->enqueueMany($adminId,'article',$id,$targets,
                ['publish_when_ready'=>true],$context,$now)['jobs'];
            return ['id'=>$id,'saved'=>$saved,'publication_locales'=>$selected,'translation_jobs'=>$jobs];
        });
    }

    public function deleteAllLanguages(int $adminId,string $id,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'articles.manage');
        return Database::transactionOrJoin($this->db,function(PDO $db)use($adminId,$id,$context,$now):array{
            $q=$db->prepare('SELECT slug FROM portal_articles WHERE id=? FOR UPDATE');$q->execute([$id]);$slug=$q->fetchColumn();
            if($slug===false)throw new Problem('article_not_found',404);
            $db->prepare("UPDATE portal_articles SET status='archived',source_revision=source_revision+1,updated_at=? WHERE id=?")->execute([$now,$id]);
            $db->prepare('UPDATE portal_article_translations SET draft_revision_id=NULL,published_revision_id=NULL,
                lock_version=lock_version+1,updated_at=? WHERE article_id=?')->execute([$now,$id]);
            (new ContentPublicationLanguages($db))->cancel('article',$id,$now);
            (new Backoffice($db))->audit($adminId,'DELETE_ARTICLE_ALL_LANGUAGES',$id,[],$context,$now);
            EdgeOneCachePurge::enqueuePaths($db,['/help','/help/'.$slug,'/sitemap.xml'],'article:delete:'.$id.':'.$now,$now);
            return ['id'=>$id,'deleted'=>true];
        });
    }

    public function publicLocales(string $slug): array
    {
        $query = $this->db->prepare("SELECT DISTINCT a.id,t.locale_code
            FROM portal_articles a JOIN portal_article_translations t ON t.article_id=a.id
            WHERE a.slug=? AND a.status='published' AND t.published_revision_id IS NOT NULL
            ORDER BY t.locale_code");
        $query->execute([$slug]);
        $public = array_column($this->locales->enabled(true), 'code');
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_map(static fn(array $row):string=>$row['locale_code'],array_filter($rows,
            fn(array $row):bool=>in_array($row['locale_code'],$public,true)
                && (($selected=(new ContentPublicationLanguages($this->db))->selected('article',$row['id']))===null
                    || in_array($row['locale_code'],$selected,true)))));
    }

    public function publicRoutes(): array
    {
        $query = $this->db->query("SELECT a.id,a.slug,t.locale_code,r.created_at
            FROM portal_articles a
            JOIN portal_article_translations t ON t.article_id=a.id AND t.published_revision_id IS NOT NULL
            JOIN portal_article_translation_revisions r ON r.id=t.published_revision_id
            WHERE a.status='published' ORDER BY a.sort_order,a.published_at DESC,t.locale_code");
        $public = array_column($this->locales->enabled(true), 'code');
        return array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC),
            fn(array $row): bool => in_array((string)$row['locale_code'], $public, true)
                && (($selected=(new ContentPublicationLanguages($this->db))->selected('article',$row['id']))===null
                    || in_array($row['locale_code'],$selected,true))));
    }

    public function publicArticle(string $slug, string $requestedLocale): ?array
    {
        $locale = $this->locales->normalize($requestedLocale, true) ?? $this->locales->defaultLocale();
        $q = $this->db->prepare("SELECT * FROM portal_articles WHERE slug=? AND status='published'");
        $q->execute([$slug]);
        $article=$q->fetch();
        if(!$article)return null;
        $candidates=(new ContentPublicationLanguages($this->db))->candidates('article',(string)$article['id'],$locale,(string)$article['source_locale']);
        foreach(array_values(array_unique($candidates)) as$candidate){
            $q=$this->db->prepare(
                'SELECT r.* FROM portal_article_translations t
                 JOIN portal_article_translation_revisions r
                    ON r.id=t.published_revision_id AND r.article_id=t.article_id AND r.locale_code=t.locale_code
                 WHERE t.article_id=? AND t.locale_code=?'
            );
            $q->execute([$article['id'],$candidate]);
            $revision=$q->fetch();
            if(!$revision)continue;
            $categoryPresentation=(new ArticleCategories($this->db,$this->locales))
                ->publicLabel((string)$article['category_id'],$locale);
            return [
                'id'=>$article['id'],'slug'=>$article['slug'],
                'category'=>$categoryPresentation['label'],
                'category_locale'=>$categoryPresentation['locale'],
                'title'=>$revision['title'],'summary'=>$revision['summary'],'body'=>$revision['body'],
                'body_format'=>$revision['body_format'],'seo_title'=>$revision['seo_title'],
                'seo_description'=>$revision['seo_description'],'content_locale'=>$candidate,
                'requested_locale'=>$locale,'fallback_used'=>$candidate!==$locale,
                'published_revision'=>(int)$revision['revision'],'source_revision'=>(int)$article['source_revision'],
                'outdated'=>(int)$revision['source_revision']<(int)$article['source_revision'],
                'updated_at'=>(int)$revision['created_at'],
            ];
        }
        return null;
    }

    public function publicList(string $requestedLocale): array
    {
        $locale=$this->locales->normalize($requestedLocale,true)??$this->locales->defaultLocale();
        $items=$this->db->query("SELECT slug FROM portal_articles WHERE status='published' ORDER BY sort_order,published_at DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
        $result=[];
        foreach($items as$slug){
            $item=$this->publicArticle((string)$slug,$locale);
            if($item!==null)$result[]=$item;
        }
        return $result;
    }

    private function article(string $articleId): array
    {
        $id=Backoffice::hexId($articleId);
        $q=$this->db->prepare('SELECT id,slug,category,category_id,source_locale,source_revision,status,updated_at FROM portal_articles WHERE id=?');
        $q->execute([$id]);
        $article=$q->fetch();
        if(!$article)throw new Problem('article_not_found',404);
        return $article;
    }

    private function locale(string $requested): string
    {
        $locale=$this->locales->normalize($requested);
        if($locale===null)throw new Problem('locale_unsupported',400);
        return $locale;
    }

    private function sourceSnapshot(PDO $db,string $articleId,string $locale): array
    {
        $q=$db->prepare(
            'SELECT r.title,r.summary,r.body FROM portal_article_translations t
             JOIN portal_article_translation_revisions r
                ON r.id=t.draft_revision_id AND r.article_id=t.article_id AND r.locale_code=t.locale_code
             WHERE t.article_id=? AND t.locale_code=?'
        );
        $q->execute([$articleId,$locale]);
        $source=$q->fetch();
        if(!$source)throw new Problem('article_source_missing',409);
        return $source;
    }

    private function fallbackChain(string $locale): array
    {
        $chain=[];
        $visit=function(string $current)use(&$visit,&$chain):void{
            foreach($this->locales->fallbacks($current)as$fallback){
                if(in_array($fallback,$chain,true)||$fallback===$current)continue;
                $chain[]=$fallback;
                $visit($fallback);
            }
        };
        $visit($locale);
        return [$locale,...$chain];
    }

    private static function sourceHash(array $data): string
    {
        return ContentSourceHash::article($data);
    }
}
