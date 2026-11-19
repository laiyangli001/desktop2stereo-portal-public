<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class PlatformSettings
{
    private const KEY = 'platform.settings.v1';

    public function __construct(private PDO $db) {}

    public static function defaults(): array
    {
        return [
            'template' => 'classic',
            'default_theme' => 'system',
            'captcha_enabled' => true,
            'captcha_required_min' => 2,
            'captcha_required_max' => 3,
            'captcha_ttl_seconds' => 300,
            'captcha_tolerance_px' => 14,
            'page_cache_ttl_seconds' => 300,
            'seo_index' => true,
            'seo_sitemap' => true,
        ];
    }

    public function publicConfiguration(): array
    {
        $stored = $this->read();
        return self::normalize(array_replace(self::defaults(), $stored));
    }

    public function captchaConfiguration(): array
    {
        $configuration = $this->publicConfiguration();
        return [
            'enabled' => $configuration['captcha_enabled'],
            'required_clicks_min' => $configuration['captcha_required_min'],
            'required_clicks_max' => $configuration['captcha_required_max'],
            'ttl_seconds' => $configuration['captcha_ttl_seconds'],
            'tolerance_px' => $configuration['captcha_tolerance_px'],
        ];
    }

    public function view(int $adminId, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'settings.manage');
        $configuration = $this->publicConfiguration();
        return [
            'values' => $configuration,
            'defaults' => self::defaults(),
            'cdn' => EdgeOneCachePurge::status($this->db),
            'extensions' => $this->extensions($configuration),
            'updated_at' => $this->updatedAt(),
        ];
    }

    public function save(int $adminId, array $values, array $context, int $now): array
    {
        Permissions::require($this->db, $adminId, 'settings.manage');
        self::normalize(array_replace(self::defaults(),$values));
        Database::transaction($this->db, function (PDO $db) use ($adminId, $context, $values, $now): void {
            $db->prepare('INSERT INTO portal_settings (name,value_json,updated_at) VALUES (?,?,0) ON DUPLICATE KEY UPDATE name=name')
                ->execute([self::KEY,json_encode(self::defaults(),JSON_THROW_ON_ERROR)]);
            $lock = $db->prepare('SELECT value_json FROM portal_settings WHERE name=? FOR UPDATE');
            $lock->execute([self::KEY]);
            $current = self::decode($lock->fetchColumn());
            $merged = self::normalize(array_replace(self::defaults(), $current, $values));
            $db->prepare('UPDATE portal_settings SET value_json=?,updated_at=? WHERE name=?')->execute([
                json_encode($merged,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                $now,
                self::KEY,
            ]);
            $email = $db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
            $email->execute([$adminId]);
            $adminEmail = $email->fetchColumn();
            if ($adminEmail === false) throw new Problem('administrator_inactive',403);
            Audit::admin($db,$adminId,(string)$adminEmail,'UPDATE_PLATFORM_SETTINGS','platform_settings',$context,'SUCCESS',[
                'template'=>$merged['template'],
                'default_theme'=>$merged['default_theme'],
                'captcha_enabled'=>$merged['captcha_enabled'],
                'captcha_required_min'=>$merged['captcha_required_min'],
                'captcha_required_max'=>$merged['captcha_required_max'],
                'captcha_ttl_seconds'=>$merged['captcha_ttl_seconds'],
                'captcha_tolerance_px'=>$merged['captcha_tolerance_px'],
                'page_cache_ttl_seconds'=>$merged['page_cache_ttl_seconds'],
                'seo_index'=>$merged['seo_index'],
                'seo_sitemap'=>$merged['seo_sitemap'],
            ],$now);
            EdgeOneCachePurge::enqueueAllPublic($db,'platform:settings:'.$now.'-'.bin2hex(random_bytes(4)),$now);
        });
        return $this->view($adminId,$context,$now);
    }

    public function queueCdnPurge(int $adminId, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $queued = 0;
        Database::transaction($this->db,function (PDO $db) use ($adminId,$context,$now,&$queued): void {
            $queued = EdgeOneCachePurge::enqueueAllPublic($db,'platform:manual:'.$now.'-'.bin2hex(random_bytes(6)),$now);
            $email = $db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
            $email->execute([$adminId]);
            $adminEmail = $email->fetchColumn();
            if ($adminEmail === false) throw new Problem('administrator_inactive',403);
            Audit::admin($db,$adminId,(string)$adminEmail,'QUEUE_EDGE_CACHE_PURGE','edge_cache',$context,'SUCCESS',[
                'queued'=>$queued,
            ],$now);
        });
        return ['queued'=>$queued,'cdn'=>EdgeOneCachePurge::status($this->db)];
    }

    public static function normalize(array $values): array
    {
        $defaults = self::defaults();
        $template = is_string($values['template'] ?? null) ? $values['template'] : $defaults['template'];
        if (!in_array($template,['classic','compact'],true)) throw new Problem('platform_template_invalid');
        $theme = is_string($values['default_theme'] ?? null) ? $values['default_theme'] : $defaults['default_theme'];
        if (!in_array($theme,['system','light','dark'],true)) throw new Problem('platform_theme_invalid');

        $minimum = self::boundedInteger($values['captcha_required_min'] ?? $defaults['captcha_required_min'],2,4,'platform_captcha_targets_invalid');
        $maximum = self::boundedInteger($values['captcha_required_max'] ?? $defaults['captcha_required_max'],2,4,'platform_captcha_targets_invalid');
        if ($minimum > $maximum) throw new Problem('platform_captcha_targets_invalid');

        return [
            'template' => $template,
            'default_theme' => $theme,
            'captcha_enabled' => self::boolean($values['captcha_enabled'] ?? $defaults['captcha_enabled'],'platform_captcha_enabled_invalid'),
            'captcha_required_min' => $minimum,
            'captcha_required_max' => $maximum,
            'captcha_ttl_seconds' => self::boundedInteger($values['captcha_ttl_seconds'] ?? $defaults['captcha_ttl_seconds'],60,600,'platform_captcha_ttl_invalid'),
            'captcha_tolerance_px' => self::boundedInteger($values['captcha_tolerance_px'] ?? $defaults['captcha_tolerance_px'],8,24,'platform_captcha_tolerance_invalid'),
            'page_cache_ttl_seconds' => self::boundedInteger($values['page_cache_ttl_seconds'] ?? $defaults['page_cache_ttl_seconds'],0,3600,'platform_cache_ttl_invalid'),
            'seo_index' => self::boolean($values['seo_index'] ?? $defaults['seo_index'],'platform_seo_index_invalid'),
            'seo_sitemap' => self::boolean($values['seo_sitemap'] ?? $defaults['seo_sitemap'],'platform_seo_sitemap_invalid'),
        ];
    }

    private function read(): array
    {
        $query = $this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute([self::KEY]);
        return self::decode($query->fetchColumn());
    }

    private function updatedAt(): ?int
    {
        $query = $this->db->prepare('SELECT updated_at FROM portal_settings WHERE name=?');
        $query->execute([self::KEY]);
        $value = $query->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    private function extensions(array $configuration): array
    {
        $locales = array_column((new LocaleRegistry())->enabled(true),'code');
        $quill = is_file(D2S_ROOT.'/public/assets/vendor/quill-2.0.3/quill.js');
        return [
            ['id'=>'localized_content','enabled'=>count($locales)>1,'detail'=>count($locales),'url'=>'/admin/locales'],
            ['id'=>'rich_text_editor','enabled'=>$quill,'detail'=>null,'url'=>'/admin/articles'],
            ['id'=>'edgeone_cache','enabled'=>EdgeOneCachePurge::enabled(),'detail'=>EdgeOneCachePurge::enabled()?'queue_only':null,'url'=>'/admin/settings/platform'],
            ['id'=>'captcha','enabled'=>$configuration['captcha_enabled'],'detail'=>null,'url'=>'/admin/settings/platform'],
            ['id'=>'oauth','enabled'=>false,'detail'=>'reserved','url'=>'/admin/settings/oauth'],
            ['id'=>'scheduled_tasks','enabled'=>true,'detail'=>null,'url'=>'/admin/tasks'],
        ];
    }

    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') return [];
        try {
            $value = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($value) ? $value : [];
    }

    private static function boolean(mixed $value, string $error): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') return true;
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off' || $value === '') return false;
        throw new Problem($error);
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $error): int
    {
        if (is_int($value)) $integer = $value;
        elseif (is_string($value) && preg_match('/^[0-9]{1,4}$/D',$value) === 1) $integer = (int)$value;
        else throw new Problem($error);
        if ($integer < $minimum || $integer > $maximum) throw new Problem($error);
        return $integer;
    }
}
