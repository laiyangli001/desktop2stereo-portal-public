<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class PluginManager
{
    private const KEY='modules.v1';

    public function __construct(private PDO $db) {}

    public static function defaults(): array
    {
        return [
            'support_widget'=>true,
            'announcement_menu'=>true,
        ];
    }

    public function publicConfiguration(): array
    {
        $query=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute([self::KEY]);
        $raw=$query->fetchColumn();
        if(!is_string($raw)||$raw==='')return self::defaults();
        try{$saved=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){return self::defaults();}
        return self::normalize(is_array($saved)?array_replace(self::defaults(),$saved):self::defaults());
    }

    public function view(int $adminId,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $configuration=$this->publicConfiguration();
        $referral=ReferralProgram::configuration($this->db);
        $translation=$this->translationStatus();
        $cdn=EdgeOneCachePurge::status($this->db);
        $locales=array_column((new LocaleRegistry())->enabled(true),'code');
        $modules=[
            self::module('support_widget','toggleable',$configuration['support_widget'],null,'/admin/plugins'),
            self::module('announcement_menu','toggleable',$configuration['announcement_menu'],null,'/admin/content/announcements'),
            self::module('referral_program','locked',(bool)$referral['enabled'],null,'/admin/referrals'),
            self::module('deepl_translation','locked',$translation['enabled'],$translation['api_key_set']?'configured':'not_configured','/admin/tasks'),
            self::module('edgeone_cache','locked',$cdn['enabled'],$cdn['configuration_ready']?'configured':'not_configured','/admin/settings/platform'),
            self::module('oauth','locked',false,'reserved','/admin/settings/oauth'),
            self::module('rich_text_editor','locked',is_file(D2S_ROOT.'/public/assets/vendor/quill-2.0.3/quill.js'),null,'/admin/articles'),
            self::module('localized_content','locked',count($locales)>1,count($locales),'/admin/locales'),
            self::module('captcha','locked',(new PlatformSettings($this->db))->captchaConfiguration()['enabled'],null,'/admin/settings/platform#platform-captcha-title'),
            self::module('scheduled_tasks','locked',true,null,'/admin/tasks'),
        ];
        return ['values'=>$configuration,'modules'=>$modules,'updated_at'=>$this->updatedAt()];
    }

    public function save(int $adminId,array $values,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $normalized=self::overrides($values);
        Database::transaction($this->db,function(PDO $db)use($adminId,$normalized,$context,$now):void{
            $db->prepare('INSERT INTO portal_settings(name,value_json,updated_at)VALUES(?,?,0) ON DUPLICATE KEY UPDATE name=name')
                ->execute([self::KEY,json_encode(self::defaults(),JSON_THROW_ON_ERROR)]);
            $lock=$db->prepare('SELECT value_json FROM portal_settings WHERE name=? FOR UPDATE');$lock->execute([self::KEY]);
            $current=$lock->fetchColumn();
            $current=is_string($current)&&$current!==''?json_decode($current,true,16,JSON_THROW_ON_ERROR):[];
            $merged=self::normalize(array_replace(self::defaults(),is_array($current)?$current:[],$normalized));
            $db->prepare('UPDATE portal_settings SET value_json=?,updated_at=? WHERE name=?')
                ->execute([json_encode($merged,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$now,self::KEY]);
            $email=$db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$email->execute([$adminId]);
            $adminEmail=$email->fetchColumn();if($adminEmail===false)throw new Problem('administrator_inactive',403);
            Audit::admin($db,$adminId,(string)$adminEmail,'UPDATE_INTERNAL_MODULES','modules',$context,'SUCCESS',[
                'support_widget'=>$merged['support_widget'],
                'announcement_menu'=>$merged['announcement_menu'],
            ],$now);
            EdgeOneCachePurge::enqueueAllPublic($db,'modules:'.$now.'-'.bin2hex(random_bytes(4)),$now);
        });
        return $this->view($adminId,$context,$now);
    }

    private static function normalize(array $values): array
    {
        return array_replace(self::defaults(),self::overrides($values));
    }

    private static function overrides(array $values): array
    {
        $result=[];
        foreach(self::defaults()as$name=>$default){
            if(!array_key_exists($name,$values))continue;
            if(!is_bool($values[$name]))throw new Problem('module_boolean_invalid');
            $result[$name]=$values[$name];
        }
        return $result;
    }

    private static function module(string $id,string $kind,bool $enabled,string|int|null $detail,string $url): array
    {
        return ['id'=>$id,'kind'=>$kind,'enabled'=>$enabled,'detail'=>$detail,'url'=>$url];
    }

    private function translationStatus(): array
    {
        $query=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $query->execute(['translation.settings.v1']);$raw=$query->fetchColumn();
        if(!is_string($raw)||$raw==='')return ['enabled'=>false,'api_key_set'=>false];
        try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){return ['enabled'=>false,'api_key_set'=>false];}
        return ['enabled'=>is_array($value)&&($value['enabled']??false)===true,
            'api_key_set'=>is_array($value)&&is_string($value['api_key_cipher']??null)&&$value['api_key_cipher']!==''];
    }

    private function updatedAt(): ?int
    {
        $query=$this->db->prepare('SELECT updated_at FROM portal_settings WHERE name=?');$query->execute([self::KEY]);
        $value=$query->fetchColumn();return $value===false?null:(int)$value;
    }
}
