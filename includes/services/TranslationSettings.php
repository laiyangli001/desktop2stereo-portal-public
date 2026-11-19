<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class TranslationSettings
{
    private const KEY = 'translation.settings.v1';
    private const DEFAULTS = [
        'enabled'=>false,'provider'=>'deepl','api_key_cipher'=>'','target_map'=>[
            'zh-Hans'=>'ZH','zh-Hant'=>'ZH','en'=>'EN','de'=>'DE','fr'=>'FR','es'=>'ES','pt'=>'PT-PT','ru'=>'RU','ja'=>'JA','ko'=>'KO','vi'=>'VI',
        ],'monthly_character_budget'=>100000,'concurrency'=>3,'retry_limit'=>3,
    ];

    public function __construct(private PDO $db,private SecretBox $secrets) {}

    public function view(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $settings=$this->load();
        return [
            'enabled'=>$settings['enabled'],'provider'=>$settings['provider'],
            'api_key_set'=>$settings['api_key_cipher']!=='',
            'monthly_characters_used'=>$this->monthlyCharactersUsed(),
            'target_map'=>$settings['target_map'],
            'monthly_character_budget'=>$settings['monthly_character_budget'],
            'concurrency'=>$settings['concurrency'],
            'retry_limit'=>$settings['retry_limit'],
            'locales'=>(new LocaleRegistry())->all(),
        ];
    }

    public function save(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $enabled=$input['enabled']??null;
        $key=$input['api_key']??'';
        $targetMap=$input['target_map']??null;
        $budget=$input['monthly_character_budget']??null;
        $retry=$input['retry_limit']??null;
        $current=$this->load();
        $concurrency=$input['concurrency']??($current['concurrency']??3);
        if(!is_bool($enabled)||!is_string($key)||strlen($key)>512||str_contains($key,"\0")||
            !is_array($targetMap)||!is_int($budget)||$budget<0||$budget>100000000||
            !is_int($retry)||$retry<0||$retry>8||!is_int($concurrency)||$concurrency<1||$concurrency>10)
            throw new Problem('translation_settings_invalid');
        $locales=new LocaleRegistry();
        foreach($targetMap as$locale=>$code){
            if(!is_string($locale)||$locales->get($locale)===null||!is_string($code)||
                !preg_match('/^[A-Z]{2,3}(?:-[A-Z]{2,3})?$/D',$code))throw new Problem('translation_language_mapping_invalid');
        }
        if($enabled){
            foreach($locales->enabledForAdmin()as$locale){
                if(!isset($targetMap[$locale['code']]))throw new Problem('translation_language_mapping_invalid');
            }
        }
        if($enabled&&trim($key)==='')throw new Problem('translation_api_key_required',409);
        $cipher=trim($key)===''?$current['api_key_cipher']:$this->secrets->encrypt(trim($key),'translation-provider:deepl');
        $value=[
            'enabled'=>$enabled,'provider'=>'deepl','api_key_cipher'=>$cipher,
            'target_map'=>$targetMap,'monthly_character_budget'=>$budget,'concurrency'=>$concurrency,'retry_limit'=>$retry,
        ];
        return Database::transaction($this->db,function(PDO $db)use($adminId,$value,$context,$now):array{
            $db->prepare('INSERT INTO portal_settings(name,value_json,updated_at) VALUES(?,?,?)
                ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)')
                ->execute([self::KEY,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$now]);
            (new Backoffice($db))->audit($adminId,'UPDATE_TRANSLATION_SETTINGS','deepl',[
                'enabled'=>$value['enabled'],'api_key_set'=>$value['api_key_cipher']!=='',
                'target_count'=>count($value['target_map']),'monthly_character_budget'=>$value['monthly_character_budget'],
                'concurrency'=>$value['concurrency'],'retry_limit'=>$value['retry_limit'],
            ],$context,$now);
            return ['enabled'=>$value['enabled'],'api_key_set'=>$value['api_key_cipher']!==''];
        });
    }

    public function workerCredentials(): ?array
    {
        $settings=$this->load();
        if(!$settings['enabled']||$settings['api_key_cipher']==='')return null;
        return [
            'api_key'=>$this->secrets->decrypt($settings['api_key_cipher'],'translation-provider:deepl'),
            'target_map'=>$settings['target_map'],
            'monthly_character_budget'=>$settings['monthly_character_budget'],
            'concurrency'=>$settings['concurrency'],
            'retry_limit'=>$settings['retry_limit'],
        ];
    }

    public function providerCredentials(): array
    {
        $credentials=$this->workerCredentials();
        if($credentials===null)throw new Problem('translation_provider_disabled',409);
        return $credentials;
    }

    private function monthlyCharactersUsed(): int
    {
        $query=$this->db->prepare('SELECT submitted_characters FROM portal_translation_usage WHERE billing_month=?');
        $query->execute([gmdate('Y-m')]);
        $value=$query->fetchColumn();
        return $value===false?0:(int)$value;
    }

    private function load(): array
    {
        $q=$this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $q->execute([self::KEY]);$raw=$q->fetchColumn();
        if(!is_string($raw)||$raw==='')return self::DEFAULTS;
        $saved=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($saved))return self::DEFAULTS;
        return array_replace(self::DEFAULTS,$saved);
    }
}
