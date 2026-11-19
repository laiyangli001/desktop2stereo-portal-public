<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use RuntimeException;

final readonly class AlipaySettings
{
    public const GATEWAYS = [
        'production'=>'https://openapi.alipay.com/gateway.do',
        'sandbox'=>'https://openapi-sandbox.dl.alipaydev.com/gateway.do',
    ];
    public const DEFAULTS = [
        'enabled'=>'false','environment'=>'production','app_id'=>'','seller_id'=>'',
        'private_key'=>'','alipay_public_key'=>'',
        'notify_url'=>'https://d2s.site/api/v1/alipay/d2s/notify',
        'app_gateway_url'=>'https://d2s.site/api/v1/alipay/app-gateway',
        'return_url'=>'https://d2s.site/alipay/return','qr_pay_mode'=>'4','qrcode_width'=>'240',
    ];
    public const SECRET_FIELDS = ['private_key','alipay_public_key'];
    public const ENVIRONMENT = [
        'enabled'=>'D2S_ALIPAY_PC_ENABLED','environment'=>'D2S_ALIPAY_ENVIRONMENT',
        'app_id'=>'D2S_ALIPAY_APP_ID','seller_id'=>'D2S_ALIPAY_SELLER_ID',
        'private_key'=>'D2S_ALIPAY_PRIVATE_KEY','alipay_public_key'=>'D2S_ALIPAY_PUBLIC_KEY',
        'notify_url'=>'D2S_ALIPAY_NOTIFY_URL','app_gateway_url'=>'D2S_ALIPAY_APP_GATEWAY_URL',
        'return_url'=>'D2S_ALIPAY_RETURN_URL','qr_pay_mode'=>'D2S_ALIPAY_QR_PAY_MODE','qrcode_width'=>'D2S_ALIPAY_QRCODE_WIDTH',
    ];

    public function __construct(private PDO $db, private SecretBox $secrets) {}

    public function load(): array
    {
        $query = $this->db->query("SELECT value_json FROM portal_settings WHERE name='alipay.v1'");
        $raw = $query->fetchColumn();
        return $this->decode($raw === false ? null : $raw);
    }

    public function view(int $adminId, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        $public = self::redact($this->load());
        Audit::admin($this->db,$adminId,$this->email($adminId),'VIEW_ALIPAY_SETTINGS','configuration:alipay',
            $context,'SUCCESS',[],$now);
        return $public;
    }

    public function save(int $adminId, array $values, array $clear, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'payment.configure');
        if (!array_is_list($clear) || array_filter($clear,static fn ($v) => !is_string($v)) !== [] ||
            array_diff($clear,self::SECRET_FIELDS) !== [] || array_diff(array_keys($values),array_keys(self::DEFAULTS)) !== []) {
            throw new Problem('alipay_setting_invalid');
        }
        foreach ($values as $key=>$value) {
            if (!is_string($value) || strlen($value)>16384 || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") ||
                (!in_array($key,self::SECRET_FIELDS,true) && preg_match('/[\x00-\x1f\x7f]/',$value))) {
                throw new Problem('alipay_setting_invalid');
            }
        }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$values,$clear,$context,$now): array {
            $db->exec("INSERT INTO portal_settings (name,value_json,updated_at) VALUES ('alipay.v1','',0) ON DUPLICATE KEY UPDATE name=name");
            $raw = $db->query("SELECT value_json FROM portal_settings WHERE name='alipay.v1' FOR UPDATE")->fetchColumn();
            $current = $this->decode($raw === '' ? null : $raw);
            foreach ($values as $key=>$value) {
                if ($value === '' && in_array($key,self::SECRET_FIELDS,true)) { continue; }
                $current[$key] = trim($value);
            }
            foreach ($clear as $key) { $current[$key] = ''; }
            self::validate($current);
            $plain = $current;
            $encrypted = [];
            foreach (self::SECRET_FIELDS as $key) {
                if ($current[$key] !== '') { $encrypted[$key] = $this->secrets->encrypt($current[$key],'configuration:alipay:'.$key); }
                unset($plain[$key]);
            }
            $stored = json_encode(['version'=>1,'values'=>$plain,'secrets'=>$encrypted],JSON_THROW_ON_ERROR);
            $db->prepare("UPDATE portal_settings SET value_json=?,updated_at=? WHERE name='alipay.v1'")->execute([$stored,$now]);
            Audit::admin($db,$adminId,$this->email($adminId),'UPDATE_ALIPAY_SETTINGS','configuration:alipay',$context,'SUCCESS',
                ['changed_fields'=>array_keys($values),'cleared_fields'=>$clear],$now);
            return self::redact($current);
        });
    }

    public static function validate(array $config, bool $requireReady = false): void
    {
        if (array_diff(array_keys(self::DEFAULTS),array_keys($config)) !== []) { throw new Problem('alipay_setting_invalid'); }
        if (!in_array($config['enabled'],['true','false'],true) || !isset(self::GATEWAYS[$config['environment']]) ||
            !in_array($config['qr_pay_mode'],['0','1','3','4'],true) ||
            !preg_match('/^[0-9]{2,3}$/D',$config['qrcode_width']) ||
            (int)$config['qrcode_width']<75 || (int)$config['qrcode_width']>600) {
            throw new Problem('alipay_setting_invalid');
        }
        foreach (['app_id','seller_id'] as $key) {
            if ($config[$key] !== '' && !preg_match($key === 'app_id' ? '/^[0-9]{16,32}$/D':'/^[0-9]{16}$/D',$config[$key])) {
                throw new Problem('alipay_identity_invalid');
            }
        }
        foreach (self::SECRET_FIELDS as $key) {
            if ($config[$key] !== '') { AlipayRSA::key($config[$key],$key === 'private_key'); }
        }
        foreach (['notify_url'=>'/api/v1/alipay/d2s/notify','app_gateway_url'=>'/api/v1/alipay/app-gateway','return_url'=>['/shop','/alipay/return']] as $key=>$path) {
            $url = parse_url($config[$key]);
            $allowed = array_map('trim',explode(',',getenv('D2S_ALLOWED_ORIGINS') ?: 'https://d2s.site,https://d2s.top'));
            $origin = is_array($url) ? ($url['scheme'] ?? '').'://'.($url['host'] ?? '') : '';
            if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !in_array($origin,$allowed,true) ||
                !in_array($url['path'] ?? '',(array)$path,true) || array_diff(array_keys($url),['scheme','host','path']) !== []) {
                throw new Problem('alipay_callback_url_invalid');
            }
        }
        if ($requireReady || $config['enabled'] === 'true') {
            foreach (['app_id','seller_id','private_key','alipay_public_key'] as $key) {
                if ($config[$key] === '') { throw new Problem('alipay_configuration_incomplete',409); }
            }
        }
    }

    private function decode(?string $raw): array
    {
        if ($raw === null) {
            $config = self::DEFAULTS;
            foreach (self::ENVIRONMENT as $key=>$name) {
                if (getenv($name) !== false) { $config[$key] = getenv($name); }
            }
            return $config;
        }
        $stored = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($stored) || ($stored['version'] ?? null) !== 1 || !is_array($stored['values'] ?? null) ||
            !is_array($stored['secrets'] ?? null) ||
            array_diff(array_keys($stored['values']),array_diff(array_keys(self::DEFAULTS),self::SECRET_FIELDS)) !== [] ||
            array_diff(array_keys($stored['secrets']),self::SECRET_FIELDS) !== []) {
            throw new RuntimeException('alipay_settings_corrupt');
        }
        $config = self::DEFAULTS;
        foreach ($stored['values'] as $key=>$value) {
            if (!is_string($value)) { throw new RuntimeException('alipay_settings_corrupt'); }
            $config[$key] = $value;
        }
        foreach ($stored['secrets'] as $key=>$value) {
            if (!is_string($value)) { throw new RuntimeException('alipay_settings_corrupt'); }
            $config[$key] = $this->secrets->decrypt($value,'configuration:alipay:'.$key);
        }
        return $config;
    }

    private static function redact(array $config): array
    {
        $public = $config;
        $present = [];
        foreach (self::SECRET_FIELDS as $key) {
            $present[$key] = $config[$key] !== '';
            unset($public[$key]);
        }
        return ['values'=>$public,'secrets_configured'=>$present,'gateway'=>self::GATEWAYS[$config['environment']] ?? null,
            'checkout_integrated'=>true];
    }

    private function email(int $adminId): string
    {
        $query = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$adminId]);
        $email = $query->fetchColumn();
        if (!is_string($email)) { throw new Problem('administrator_unavailable',403); }
        return $email;
    }
}
