<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class SiteContent
{
    private const SITE_KEY = 'site.v1';
    private const NAV_KEY = 'navigation.v1';
    private const ANNOUNCEMENT_KEY = 'announcements.v1';
    private const HOME_LABELS = [
        'zh-Hans'=>'首页','zh-Hant'=>'首頁','en'=>'Home','de'=>'Startseite',
        'fr'=>'Accueil','es'=>'Inicio','pt'=>'Início','ru'=>'Главная',
        'ja'=>'ホーム','ko'=>'홈',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function publicData(string $locale = 'zh-Hans', int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $site = $this->localizedSite($this->read(self::SITE_KEY,self::defaultSite()),$locale,new LocaleRegistry(),false);
        $navigation = $this->read(self::NAV_KEY,self::defaultNavigation());
        $locales = new LocaleRegistry();
        $announcementTranslations = new AnnouncementTranslations($this->db,$locales);
        $announcements = $announcementTranslations->publicList($locale,$now,'header');
        $homeAnnouncements = $announcementTranslations->publicList($locale,$now,'home');
        $popupAnnouncements = $announcementTranslations->publicList($locale,$now,'popup');
        $navigation = array_values(array_filter($navigation,static function(mixed $item): bool {
            if (!is_array($item)) return false;
            $url=(string)($item['url']??'');
            return ($item['enabled'] ?? false) === true &&
                self::validLink($url) && !self::headerExcludedPath($url);
        }));
        usort($navigation,static fn(array $a,array $b): int => [(int)$a['sort'],(string)$a['id']] <=> [(int)$b['sort'],(string)$b['id']]);
        $navigation = $this->localizedNavigation($navigation,$locale,$locales,false);
        return ['site'=>$site,'navigation'=>$navigation,'announcements'=>array_slice($announcements,0,10),
            'home_announcements'=>array_slice($homeAnnouncements,0,3),'popup_announcement'=>$popupAnnouncements[0]??null];
    }

    public function view(int $adminId, string $contentLocale = 'zh-Hans'): array
    {
        Permissions::requireAny($this->db,$adminId,['settings.manage','announcements.manage']);
        $locales = new LocaleRegistry();
        $selectedLocale = $locales->normalizeForAdmin($contentLocale) ?? $locales->defaultLocale();
        $permissions = $this->permissions($adminId);
        return [
            'site'=>$this->localizedSite($this->read(self::SITE_KEY,self::defaultSite()),$selectedLocale,$locales,true),
            'navigation'=>$this->localizedNavigation($this->read(self::NAV_KEY,self::defaultNavigation()),$selectedLocale,$locales,true),
            'announcements'=>(new AnnouncementTranslations($this->db,$locales))->adminList($selectedLocale),
            'content_locale'=>$selectedLocale,
            'content_locales'=>$locales->enabledForAdmin(),
            'can_settings'=>in_array('settings.manage',$permissions,true),
            'can_announcements'=>in_array('announcements.manage',$permissions,true),
        ];
    }

    private function localizedSite(array $site, string $locale, LocaleRegistry $locales, bool $admin): array
    {
        $codes=array_values(array_unique(array_column($locales->all(),'code')));
        $marks=implode(',',array_fill(0,count($codes),'?'));
        $query=$this->db->prepare("SELECT locale_code,tagline,intro,lock_version FROM portal_site_translations WHERE locale_code IN ($marks)");
        $query->execute($codes);
        $translations=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $translations[(string)$row['locale_code']]=[
                'tagline'=>(string)$row['tagline'],'intro'=>(string)$row['intro'],
                'lock_version'=>(int)$row['lock_version']];
        }
        $order=array_values(array_unique([$locale,...$locales->fallbacks($locale),$locales->defaultLocale()]));
        $legacyLocale=static function(array $site,string $candidate): ?string {
            if (str_starts_with($candidate,'en')
                && trim((string)($site['tagline_en']??''))!==''&&trim((string)($site['intro_en']??''))!=='') return 'en';
            if (str_starts_with($candidate,'zh')
                && trim((string)($site['tagline_zh']??''))!==''&&trim((string)($site['intro_zh']??''))!=='') return 'zh';
            return null;
        };
        if ($admin) {
            $current=$translations[$locale] ?? null;
            $fallback=null;
            foreach ($order as $candidate) {
                if ($candidate!==$locale && isset($translations[$candidate])) {$fallback=$translations[$candidate];break;}
            }
            if ($fallback===null) {
                foreach ($order as $candidate) {
                    if ($candidate===$locale) continue;
                    $suffix=$legacyLocale($site,$candidate);
                    if($suffix===null)continue;
                    $fallback=['tagline'=>(string)($site['tagline_'.$suffix]??''),
                        'intro'=>(string)($site['intro_'.$suffix]??'')];
                    break;
                }
            }
            $site['tagline']=$current['tagline'] ?? '';
            $site['intro']=$current['intro'] ?? '';
            $site['fallback_tagline']=$fallback['tagline'] ?? '';
            $site['fallback_intro']=$fallback['intro'] ?? '';
            $site['translation_lock_version']=$current['lock_version'] ?? 0;
            $site['translation_locale']=$locale;
            return $site;
        }
        $actual=null;
        foreach ($order as $candidate) {
            if (isset($translations[$candidate])) {$actual=$candidate;break;}
        }
        if ($actual!==null) {
            $site['tagline']=$translations[$actual]['tagline'];
            $site['intro']=$translations[$actual]['intro'];
            $site['translation_locale']=$actual;
            return $site;
        }
        $actualLegacyLocale=null;
        foreach($order as$candidate){
            $actualLegacyLocale=$legacyLocale($site,$candidate);
            if($actualLegacyLocale!==null)break;
        }
        $suffix=$actualLegacyLocale??'zh';
        $site['tagline']=(string)($site['tagline_'.$suffix]??'');
        $site['intro']=(string)($site['intro_'.$suffix]??'');
        $site['translation_locale']=$suffix==='en'?'en':'zh-Hans';
        return $site;
    }

    public function saveSite(int $adminId, array $values, array $context, int $now, string $contentLocale = 'zh-Hans'): array
    {
        $locales = new LocaleRegistry();
        $locale = $locales->normalizeForAdmin($contentLocale);
        if ($locale === null) throw new Problem('site_content_invalid');
        $site = Database::transaction($this->db,function(PDO $db)use($adminId,$values,$context,$now,$locale): array {
            Permissions::require($db,$adminId,'settings.manage');
            $current = $this->readLocked($db,self::SITE_KEY,self::defaultSite());
            $text = static function(string $key,int $max)use($values,$current): string {
                $value = trim((string)($values[$key] ?? $current[$key] ?? ''));
                if (mb_strlen($value,'UTF-8')>$max) throw new Problem('site_content_invalid');
                return $value;
            };
            $next = $current;
            foreach (['site_name'=>80,'company_name'=>160,'contact_email'=>254,'tagline_zh'=>160,
                'tagline_en'=>160,'intro_zh'=>500,'intro_en'=>500,'github_repo'=>160,
                'privacy_version'=>40,'privacy_effective'=>40,'terms_version'=>40,'terms_effective'=>40,
                'legal_version'=>40,'legal_effective'=>40,'icp_site_number'=>60,'icp_top_number'=>60,
                'icp_license_number'=>60,'public_security_number'=>60] as $key=>$max) {
                $next[$key] = $text($key,$max);
            }
            foreach(['site_name','company_name','tagline_zh','tagline_en','intro_zh','intro_en'] as $key){
                if($next[$key]==='')throw new Problem('site_required_text_missing');
            }
            if (!filter_var($next['contact_email'],FILTER_VALIDATE_EMAIL)) throw new Problem('site_contact_email_invalid');
            foreach (['privacy_version','terms_version','legal_version'] as $versionField) {
                if ($next[$versionField] === '') throw new Problem('site_policy_version_required');
            }
            foreach(['privacy_effective','terms_effective','legal_effective'] as $dateField){
                $date=$next[$dateField];
                if($date!==''&&(!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$parts)||
                    !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])))throw new Problem('site_policy_date_invalid');
            }
            foreach (['icp_site_number','icp_top_number'] as $icpField) {
                if ($next[$icpField] === '' ||
                    !preg_match('/^[\p{Han}]ICP备[0-9]{6,12}号(?:-[0-9]+)?$/uD',$next[$icpField])) {
                    throw new Problem('site_icp_number_invalid');
                }
            }
            if ($next['public_security_number'] !== '' &&
                !preg_match('/^[\p{Han}]公网安备[0-9]+号$/uD',$next['public_security_number'])) {
                throw new Problem('site_public_security_number_invalid');
            }
            if ($next['github_repo'] !== '' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D',$next['github_repo'])) {
                throw new Problem('site_github_repo_invalid');
            }
            foreach (['support_wechat_url','public_security_url'] as $key) {
                $next[$key] = trim((string)($values[$key] ?? $current[$key] ?? ''));
                if ($next[$key] !== '' && !self::httpsUrl($next[$key])) throw new Problem('site_link_invalid');
            }
            if ($next['support_wechat_url'] !== '' &&
                parse_url($next['support_wechat_url'],PHP_URL_HOST) !== 'work.weixin.qq.com') {
                throw new Problem('site_wechat_host_invalid');
            }
            if ($next['public_security_url'] !== '' &&
                parse_url($next['public_security_url'],PHP_URL_HOST) !== 'beian.mps.gov.cn') {
                throw new Problem('site_public_security_host_invalid');
            }
            $writeTranslation=static function(string $translationLocale,?string $tagline,?string $intro,?int $expectedVersion)use($db,&$next,$adminId,$now): void {
                $query=$db->prepare('SELECT tagline,intro,lock_version FROM portal_site_translations WHERE locale_code=? FOR UPDATE');
                $query->execute([$translationLocale]);
                $existing=$query->fetch(PDO::FETCH_ASSOC);
                if ($expectedVersion!==null &&
                    (($existing===false && $expectedVersion!==0) ||
                    ($existing!==false && (int)$existing['lock_version']!==$expectedVersion))) {
                    throw new Problem('record_changed',409);
                }
                $legacySuffix=$translationLocale==='en'?'en':'zh';
                $nextTagline=trim($tagline ?? (string)($existing['tagline'] ?? $next['tagline_'.$legacySuffix]));
                $nextIntro=trim($intro ?? (string)($existing['intro'] ?? $next['intro_'.$legacySuffix]));
                if ($nextTagline==='' || $nextIntro==='') throw new Problem('site_required_text_missing');
                if (mb_strlen($nextTagline,'UTF-8')>160 || mb_strlen($nextIntro,'UTF-8')>500) {
                    throw new Problem('site_content_invalid');
                }
                if (in_array($translationLocale,['zh-Hans','en'],true)) {
                    $suffix=$translationLocale==='en'?'en':'zh';
                    $next['tagline_'.$suffix]=$nextTagline;
                    $next['intro_'.$suffix]=$nextIntro;
                }
                if ($existing===false) {
                    $db->prepare('INSERT INTO portal_site_translations(locale_code,tagline,intro,lock_version,updated_by,updated_at)
                        VALUES (?,?,?,1,?,?)')->execute([$translationLocale,$nextTagline,$nextIntro,$adminId,$now]);
                } else {
                    $db->prepare('UPDATE portal_site_translations
                        SET tagline=?,intro=?,lock_version=lock_version+1,updated_by=?,updated_at=? WHERE locale_code=?')
                        ->execute([$nextTagline,$nextIntro,$adminId,$now,$translationLocale]);
                }
            };
            if (array_key_exists('tagline',$values) || array_key_exists('intro',$values)) {
                $expected=array_key_exists('translation_lock_version',$values)?(int)$values['translation_lock_version']:null;
                $writeTranslation($locale,
                    array_key_exists('tagline',$values)?(string)$values['tagline']:null,
                    array_key_exists('intro',$values)?(string)$values['intro']:null,$expected);
            } elseif (array_intersect(['tagline_zh','intro_zh','tagline_en','intro_en'],array_keys($values))!==[]) {
                if (array_key_exists('tagline_zh',$values)||array_key_exists('intro_zh',$values)) {
                    $writeTranslation('zh-Hans',$values['tagline_zh']??null,$values['intro_zh']??null,null);
                }
                if (array_key_exists('tagline_en',$values)||array_key_exists('intro_en',$values)) {
                    $writeTranslation('en',$values['tagline_en']??null,$values['intro_en']??null,null);
                }
            }
            $policyChanged=$current['privacy_version']!==$next['privacy_version']||
                $current['terms_version']!==$next['terms_version'];
            $this->write($db,self::SITE_KEY,$next,$now);
            if($policyChanged)$db->exec("UPDATE portal_web_sessions SET consent_required=1 WHERE realm='user'");
            $this->audit($db,$adminId,'UPDATE_SITE_CONTENT','site',$context,
                ['locale'=>$locale,'fields'=>array_keys($values)],$now);
            EdgeOneCachePurge::enqueueAllPublic($db,'site:content:'.bin2hex(random_bytes(16)),$now);
            return $next;
        });
        return $this->localizedSite($site,$locale,$locales,true);
    }

    public function saveNavigation(int $adminId, array $links, array $context, int $now, string $contentLocale = 'zh-Hans'): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $locales = new LocaleRegistry();
        $locale = $locales->normalizeForAdmin($contentLocale);
        if ($locale === null || count($links)>20) throw new Problem('site_navigation_limit');
        $clean = [];
        $translationLabels = [];
        foreach ($links as $index=>$link) {
            if (!is_array($link)) throw new Problem('site_navigation_invalid');
            $legacyLabel=(string)($link[$locale==='en'?'label_en':'label_zh'] ?? $link['label_zh'] ?? $link['label_en'] ?? '');
            $label=trim((string)($link['label'] ?? $legacyLabel));
            $url=trim((string)($link['url'] ?? ''));
            $existingId=(string)($link['id'] ?? '');
            $id=preg_match('/^[a-z0-9_-]{1,64}$/D',$existingId)?$existingId:bin2hex(random_bytes(8));
            $sourceFallback=array_key_exists('label_zh',$link)?'zh-Hans':$locale;
            $sourceLocale=$locales->normalizeForAdmin((string)($link['source_locale'] ?? $sourceFallback)) ?? $locale;
            if (($label!=='' && mb_strlen($label,'UTF-8')>120) || !self::validLink($url)) {
                throw new Problem('site_navigation_invalid');
            }
            if ($existingId==='' && $label==='') throw new Problem('site_navigation_invalid');
            if(self::headerExcludedPath($url))throw new Problem('site_navigation_header_forbidden');
            $clean[]=['id'=>$id,'source_locale'=>$sourceLocale,'url'=>$url,
                'sort'=>$index,'enabled'=>($link['enabled'] ?? true)===true];
            $translationLabels[]=['id'=>$id,'label'=>$label,'lock_version'=>(int)($link['translation_lock_version'] ?? 0)];
        }
        $this->db->beginTransaction();
        try {
            $current = $this->readLocked($this->db,self::NAV_KEY,self::defaultNavigation());
            $legacyLabels=[];
            foreach($current as$oldLink){
                $oldId=(string)($oldLink['id']??'');
                if($oldId==='')continue;
                $legacyLabels[$oldId]=[
                    'zh-Hans'=>trim((string)($oldLink['label_zh']??'')),
                    'en'=>trim((string)($oldLink['label_en']??'')),
                ];
            }
            if($clean!==[]){
                $ids=array_column($clean,'id');
                $marks=implode(',',array_fill(0,count($ids),'?'));
                $labelsQuery=$this->db->prepare("SELECT navigation_id,locale_code,label FROM portal_navigation_translations
                    WHERE navigation_id IN ($marks) AND locale_code IN ('zh-Hans','en')");
                $labelsQuery->execute($ids);
                foreach($labelsQuery->fetchAll(PDO::FETCH_ASSOC)as$row){
                    $legacyLabels[(string)$row['navigation_id']][(string)$row['locale_code']]=(string)$row['label'];
                }
            }
            $keptIds = array_column($clean,'id');
            foreach ($current as $oldLink) {
                $oldId=(string)($oldLink['id'] ?? '');
                if ($oldId!=='' && !in_array($oldId,$keptIds,true)) {
                    $this->db->prepare('DELETE FROM portal_navigation_translations WHERE navigation_id=?')->execute([$oldId]);
                }
            }
            foreach ($translationLabels as $translation) {
                if(in_array($locale,['zh-Hans','en'],true)){
                    $legacyLabels[$translation['id']][$locale]=$translation['label'];
                }
                $select=$this->db->prepare('SELECT lock_version FROM portal_navigation_translations WHERE navigation_id=? AND locale_code=? FOR UPDATE');
                $select->execute([$translation['id'],$locale]);
                $version=$select->fetchColumn();
                if (($version===false && $translation['lock_version']!==0) ||
                    ($version!==false && (int)$version!==$translation['lock_version'])) {
                    throw new Problem('record_changed',409);
                }
                if ($translation['label']==='') {
                    if ($version!==false) {
                        $this->db->prepare('DELETE FROM portal_navigation_translations WHERE navigation_id=? AND locale_code=?')
                            ->execute([$translation['id'],$locale]);
                    }
                    continue;
                }
                if ($version===false) {
                    $this->db->prepare('INSERT INTO portal_navigation_translations
                        (navigation_id,locale_code,label,lock_version,updated_by,updated_at) VALUES (?,?,?,1,?,?)')
                        ->execute([$translation['id'],$locale,$translation['label'],$adminId,$now]);
                } else {
                    $this->db->prepare('UPDATE portal_navigation_translations
                        SET label=?,lock_version=lock_version+1,updated_by=?,updated_at=?
                        WHERE navigation_id=? AND locale_code=?')
                        ->execute([$translation['label'],$adminId,$now,$translation['id'],$locale]);
                }
            }
            foreach($clean as&$link){
                $labels=$legacyLabels[(string)$link['id']]??[];
                $link['label_zh']=(string)($labels['zh-Hans']??'');
                $link['label_en']=(string)($labels['en']??'');
            }
            unset($link);
            $this->write($this->db,self::NAV_KEY,$clean,$now);
            $this->audit($this->db,$adminId,'UPDATE_SITE_NAVIGATION','navigation',$context,
                ['locale'=>$locale,'link_count'=>count($clean),'enabled_count'=>count(array_filter($clean,static fn(array $row): bool=>$row['enabled']))],$now);
            EdgeOneCachePurge::enqueueAllPublic($this->db,'site:navigation:'.bin2hex(random_bytes(16)),$now);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->localizedNavigation($clean,$locale,$locales,true);
    }

    private function localizedNavigation(array $items, string $locale, LocaleRegistry $locales, bool $admin): array
    {
        $items=array_values(array_filter($items,'is_array'));
        if ($items===[]) return [];
        $ids=array_values(array_unique(array_map(static fn(array $item): string=>(string)($item['id'] ?? ''),$items)));
        $ids=array_values(array_filter($ids,static fn(string $id): bool=>$id!==''));
        if ($ids===[]) return [];
        $codes=array_values(array_unique(array_column($locales->all(),'code')));
        $idMarks=implode(',',array_fill(0,count($ids),'?'));
        $localeMarks=implode(',',array_fill(0,count($codes),'?'));
        $query=$this->db->prepare("SELECT navigation_id,locale_code,label,lock_version
            FROM portal_navigation_translations
            WHERE navigation_id IN ($idMarks) AND locale_code IN ($localeMarks)");
        $query->execute([...$ids,...$codes]);
        $translations=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $translations[(string)$row['navigation_id']][(string)$row['locale_code']]=[
                'label'=>(string)$row['label'],'lock_version'=>(int)$row['lock_version']];
        }
        $result=[];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id=(string)($item['id'] ?? '');
            $source=$locales->normalize((string)($item['source_locale'] ?? 'zh-Hans')) ?? 'zh-Hans';
            $labels=$translations[$id] ?? [];
            $order=array_values(array_unique([$locale,...$locales->fallbacks($locale),$source,$locales->defaultLocale()]));
            if ($admin) {
                $current=$labels[$locale] ?? null;
                $fallback='';
                foreach ($order as $candidate) {
                    if ($candidate!==$locale && isset($labels[$candidate])) {$fallback=$labels[$candidate]['label'];break;}
                }
                if ($fallback==='') {
                    $fallback=(string)($item['label_'.(str_starts_with($locale,'en')?'en':'zh')] ?? $item['label_zh'] ?? $item['label_en'] ?? '');
                }
                $item['source_locale']=$source;
                $item['label']=$current['label'] ?? '';
                $item['fallback_label']=$fallback;
                $item['translated']=$current!==null;
                $item['translation_lock_version']=$current['lock_version'] ?? 0;
                $item['translation_locale']=$locale;
            } else {
                $actual=null;
                foreach ($order as $candidate) {
                    if (isset($labels[$candidate])) {$actual=$candidate;break;}
                }
                if (($item['url'] ?? '')==='/' && !isset($labels[$locale]) && isset(self::HOME_LABELS[$locale])) {
                    $item['label']=self::HOME_LABELS[$locale];
                    $item['translation_locale']=$locale;
                } elseif ($actual!==null) {
                    $item['label']=$labels[$actual]['label'];
                    $item['translation_locale']=$actual;
                } else {
                    $legacyLocale=str_starts_with($locale,'en')&&trim((string)($item['label_en'] ?? ''))!==''?'en':
                        (trim((string)($item['label_zh'] ?? ''))!==''?'zh-Hans':'en');
                    $legacyKey=$legacyLocale==='zh-Hans'?'label_zh':'label_en';
                    $item['label']=(string)($item[$legacyKey] ?? '');
                    $item['translation_locale']=$legacyLocale;
                }
            }
            $result[]=$item;
        }
        return $result;
    }

    public function saveAnnouncements(int $adminId, array $items, array $context, int $now, string $contentLocale = 'zh-Hans', array $deletedIds = [], bool $publish = true): array
    {
        return (new AnnouncementTranslations($this->db,new LocaleRegistry()))
            ->save($adminId,$contentLocale,$items,$deletedIds,$context,$now,$publish);
    }

    public function privacyPolicy(bool $lock = false): array
    {
        return $this->registrationPolicies($lock)[0];
    }

    public function registrationPolicies(bool $lock = false): array
    {
        $site = $lock ? $this->readLocked($this->db,self::SITE_KEY,self::defaultSite())
            : $this->read(self::SITE_KEY,self::defaultSite());
        return [
            ['type'=>'PRIVACY','version'=>(string)$site['privacy_version'],'effective_date'=>(string)$site['privacy_effective']],
            ['type'=>'TERMS','version'=>(string)$site['terms_version'],'effective_date'=>(string)$site['terms_effective']],
        ];
    }

    public static function defaultSite(): array
    {
        return [
            'site_name'=>'Desktop2Stereo',
            'company_name'=>'烟台图立视软件开发有限公司',
            'contact_email'=>'zuohuanhao@tulishisoft.cn',
            'tagline_zh'=>'把 2D 影像转换为可沉浸观看的立体画面',
            'tagline_en'=>'Turn ordinary 2D media into immersive stereoscopic experiences',
            'intro_zh'=>'Desktop2Stereo 提供软件下载入口、账户授权、设备管理和私密售后服务。安装包由外部平台托管。',
            'intro_en'=>'Desktop2Stereo provides software downloads, account licensing, device management and private support. Installers are hosted by external platforms.',
            'github_repo'=>'laiyangli001/desktop2stereo-vulkan',
            'support_wechat_url'=>'https://work.weixin.qq.com/ca/cawcde40faa1d08998',
            'privacy_version'=>'1.1',
            'privacy_effective'=>'',
            'terms_version'=>'1.1',
            'terms_effective'=>'',
            'legal_version'=>'1.0',
            'legal_effective'=>'',
            'icp_site_number'=>'鲁ICP备2026064880号-1',
            'icp_top_number'=>'鲁ICP备2026064880号-2',
            'icp_license_number'=>'',
            'public_security_number'=>'',
            'public_security_url'=>'',
        ];
    }

    public static function defaultNavigation(): array
    {
        return [
            ['id'=>'home','label_zh'=>'首页','label_en'=>'Home','url'=>'/','sort'=>0,'enabled'=>true],
        ];
    }

    private function read(string $key, array $default): array
    {
        $query=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute([$key]);
        $raw=$query->fetchColumn();
        return $this->decode($raw,$default);
    }

    private function readLocked(PDO $db,string $key,array $default): array
    {
        $db->prepare('INSERT INTO portal_settings (name,value_json,updated_at) VALUES (?,?,0)
            ON DUPLICATE KEY UPDATE name=name')->execute([$key,json_encode($default,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $query=$db->prepare('SELECT value_json FROM portal_settings WHERE name=? FOR UPDATE');
        $query->execute([$key]);
        return $this->decode($query->fetchColumn(),$default);
    }

    private function decode(mixed $raw,array $default): array
    {
        if (!is_string($raw) || $raw==='') return $default;
        try {
            $value=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
        if (!is_array($value)) return $default;
        if (array_is_list($default)) return $value;
        return array_replace($default,$value);
    }

    private function write(PDO $db,string $key,array $value,int $now): void
    {
        $db->prepare('INSERT INTO portal_settings (name,value_json,updated_at) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)')
            ->execute([$key,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$now]);
    }

    private function permissions(int $adminId): array
    {
        $query=$this->db->prepare('SELECT permission FROM portal_admin_permissions WHERE admin_id=?');
        $query->execute([$adminId]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    private function audit(PDO $db,int $adminId,string $operation,string $target,array $context,array $details,int $now): void
    {
        $query=$db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$adminId]);
        $email=$query->fetchColumn();
        if ($email===false) throw new Problem('administrator_inactive',403);
        Audit::admin($db,$adminId,(string)$email,$operation,$target,$context,'SUCCESS',$details,$now);
    }

    public static function httpsUrl(string $value): bool
    {
        if(strlen($value)>2048||str_contains($value,'\\')||
            preg_match('/[\x00-\x1F\x7F]/',$value)===1)return false;
        if(filter_var($value,FILTER_VALIDATE_URL)===false||str_starts_with($value,'https://')===false)return false;
        $parts=parse_url($value);
        return is_array($parts)&&is_string($parts['host']??null)&&$parts['host']!==''&&
            !isset($parts['user'])&&!isset($parts['pass']);
    }

    private static function validLink(string $url): bool
    {
        if($url===''||strlen($url)>2048||str_contains($url,'\\')||
            preg_match('/[\x00-\x1F\x7F]/',$url)===1)return false;
        if (str_starts_with($url,'/')) return !str_starts_with($url,'//');
        return self::httpsUrl($url);
    }

    private static function headerExcludedPath(string $url): bool
    {
        $path=parse_url($url,PHP_URL_PATH);
        if(!is_string($path)||!str_starts_with($path,'/'))return false;
        $path=$path==='/'?'/':rtrim($path,'/');
        foreach(['/about','/contact','/account-recovery','/business-license','/privacy-policy','/profile','/shop','/wallet','/license','/notifications','/feedback','/support','/tickets','/consent','/admin','/api']as$excluded){
            if($path===$excluded||str_starts_with($path,$excluded.'/'))return true;
        }
        return false;
    }
}
