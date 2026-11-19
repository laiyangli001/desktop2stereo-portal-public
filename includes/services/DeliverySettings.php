<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;
use RuntimeException;
use Throwable;

final readonly class DeliverySettings
{
    public const DEFAULTS = [
        'D2S_MAIL_DRIVER'=>'', 'D2S_SMTP_HOST'=>'', 'D2S_SMTP_PORT'=>'587',
        'D2S_SMTP_USER'=>'', 'D2S_SMTP_PASSWORD'=>'', 'D2S_SMTP_FROM'=>'',
        'D2S_SES_SECRET_ID'=>'', 'D2S_SES_SECRET_KEY'=>'', 'D2S_SES_REGION'=>'ap-shanghai',
        'D2S_SES_FROM'=>'', 'D2S_SES_REPLY_TO'=>'',
        'D2S_SES_TEMPLATE_REGISTER'=>'', 'D2S_SES_TEMPLATE_PASSWORD_RESET'=>'', 'D2S_SES_TEMPLATE_BIND_CONTACT'=>'', 'D2S_SES_TEMPLATE_TRANSACTION'=>'',
        'D2S_SES_TEMPLATE_TRANSACTION_EN'=>'', 'D2S_NOTIFICATION_TEMPLATE_FALLBACK'=>'zh-Hans',
        'D2S_SMS_ENABLED'=>'false', 'D2S_SMS_SECRET_ID'=>'', 'D2S_SMS_SECRET_KEY'=>'',
        'D2S_SMS_REGION'=>'ap-guangzhou', 'D2S_SMS_APP_ID'=>'', 'D2S_SMS_SIGN_NAME'=>'',
        'D2S_SMS_TEMPLATE_REGISTER'=>'', 'D2S_SMS_TEMPLATE_PASSWORD_RESET'=>'', 'D2S_SMS_TEMPLATE_BIND_CONTACT'=>'', 'D2S_SMS_TEMPLATE_TRANSACTION'=>'',
        'D2S_SMS_TEMPLATE_TRANSACTION_EN'=>'',
    ];
    public const SECRET_FIELDS = [
        'D2S_SMTP_PASSWORD','D2S_SES_SECRET_ID','D2S_SES_SECRET_KEY','D2S_SMS_SECRET_ID','D2S_SMS_SECRET_KEY',
    ];

    public function __construct(private PDO $db, private SecretBox $secrets)
    {
    }

    /** A saved configuration is authoritative; blank values never fall back to old environment secrets. */
    public function load(): array
    {
        $select = $this->db->prepare('SELECT value_json FROM portal_settings WHERE name=?');
        $select->execute(['delivery.v1']);
        $raw = $select->fetchColumn();
        return $this->decode($raw === false ? null : $raw);
    }

    public function view(int $adminId, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $config = $this->load();
        $public = $this->redact($config);
        Audit::admin($this->db,$adminId,$this->email($adminId),'VIEW_DELIVERY_SETTINGS','configuration:delivery',
            $context,'SUCCESS',[],$now);
        return $public;
    }

    public function save(int $adminId, array $values, array $clearFields, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        if (!array_is_list($clearFields) || array_filter($clearFields, static fn ($field) => !is_string($field)) !== []) {
            throw new Problem('delivery_setting_invalid');
        }
        if (array_diff(array_keys($values),array_keys(self::DEFAULTS)) !== [] ||
            array_diff($clearFields,self::SECRET_FIELDS) !== []) {
            throw new Problem('delivery_setting_unknown');
        }
        foreach ($values as $value) {
            if (!is_string($value) || strlen($value) > 2048 || !mb_check_encoding($value,'UTF-8') ||
                str_contains($value,"\0") || str_contains($value,"\r") || str_contains($value,"\n")) {
                throw new Problem('delivery_setting_invalid');
            }
        }
        return Database::transaction($this->db,function (PDO $db) use ($adminId,$values,$clearFields,$context,$now): array {
            $seed = $db->prepare("INSERT INTO portal_settings (name,value_json,updated_at) VALUES ('delivery.v1','',0)
                ON DUPLICATE KEY UPDATE name=name");
            $seed->execute();
            $select = $db->query("SELECT value_json FROM portal_settings WHERE name='delivery.v1' FOR UPDATE");
            $raw = $select->fetchColumn();
            $current = $this->decode($raw === '' ? null : $raw);
            foreach ($values as $key=>$value) {
                if (in_array($key,self::SECRET_FIELDS,true) && $value === '') {
                    continue;
                }
                $current[$key] = in_array($key,self::SECRET_FIELDS,true) ? $value : trim($value);
            }
            foreach ($clearFields as $key) {
                $current[$key] = '';
            }
            $this->validate($current);
            $plain = $current;
            $encrypted = [];
            foreach (self::SECRET_FIELDS as $key) {
                if ($current[$key] !== '') {
                    $encrypted[$key] = $this->secrets->encrypt($current[$key],'configuration:delivery:' . $key);
                }
                unset($plain[$key]);
            }
            $serialized = json_encode(['version'=>1,'values'=>$plain,'secrets'=>$encrypted],JSON_THROW_ON_ERROR);
            $update = $db->prepare("UPDATE portal_settings SET value_json=?,updated_at=? WHERE name='delivery.v1'");
            $update->execute([$serialized,$now]);
            Audit::admin($db,$adminId,$this->email($adminId),'UPDATE_DELIVERY_SETTINGS','configuration:delivery',
                $context,'SUCCESS',['changed_fields'=>array_keys($values),'cleared_fields'=>array_values($clearFields)],$now);
            return $this->redact($current);
        });
    }

    /** Test only to the authenticated administrator's stored email, never an arbitrary recipient. */
    public function test(int $adminId, array $context, int $now, ?MessageSender $sender = null): void
    {
        Permissions::require($this->db,$adminId,'settings.manage');
        $config = $this->load();
        if (!DeliveryGateway::available('email',$config)) {
            throw new Problem('email_delivery_unavailable',503);
        }
        $email = AccountInput::contact('email',$this->email($adminId));
        $allowed = Database::transaction($this->db,static function (PDO $db) use ($adminId,$email,$context,$now): bool {
            if (!RateLimiter::take($db,'delivery:test:admin:' . $adminId,5,3600,$now,60)) {
                return false;
            }
            Audit::admin($db,$adminId,$email,'TEST_DELIVERY','configuration:delivery',$context,'PENDING',[],$now);
            return true;
        });
        if (!$allowed) {
            throw new Problem('verification_rate_limited',429);
        }
        try {
            ($sender ?? new DeliveryGateway($config))->send([
                'kind'=>'email','target'=>$email,'purpose'=>'register',
                'code'=>str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT),
            ]);
        } catch (Throwable) {
            Audit::admin($this->db,$adminId,$email,'TEST_DELIVERY','configuration:delivery',$context,'FAILURE',[],$now);
            throw new Problem('delivery_test_failed',502);
        }
        Audit::admin($this->db,$adminId,$email,'TEST_DELIVERY','configuration:delivery',$context,'SUCCESS',[],$now);
    }

    private function decode(?string $raw): array
    {
        if ($raw === null) {
            $values = self::DEFAULTS;
            foreach ($values as $key=>$default) {
                $environment = getenv($key);
                $values[$key] = $environment === false ? $default : $environment;
            }
            return $values;
        }
        $stored = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($stored) || ($stored['version'] ?? null) !== 1 ||
            !is_array($stored['values'] ?? null) || !is_array($stored['secrets'] ?? null) ||
            array_diff(array_keys($stored['values']),array_diff(array_keys(self::DEFAULTS),self::SECRET_FIELDS)) !== [] ||
            array_diff(array_keys($stored['secrets']),self::SECRET_FIELDS) !== []) {
            throw new RuntimeException('delivery_settings_corrupt');
        }
        $config = self::DEFAULTS;
        foreach ($stored['values'] as $key=>$value) {
            if (!is_string($value)) {
                throw new RuntimeException('delivery_settings_corrupt');
            }
            $config[$key] = $value;
        }
        foreach ($stored['secrets'] as $key=>$value) {
            if (!is_string($value)) {
                throw new RuntimeException('delivery_settings_corrupt');
            }
            $config[$key] = $this->secrets->decrypt($value,'configuration:delivery:' . $key);
        }
        return $config;
    }

    private function validate(array $config): void
    {
        if (!in_array($config['D2S_MAIL_DRIVER'],['','smtp','ses'],true) ||
            !in_array($config['D2S_SMS_ENABLED'],['true','false'],true)) {
            throw new Problem('delivery_setting_invalid');
        }
        if ($config['D2S_MAIL_DRIVER'] === 'smtp') {
            if (!DeliveryGateway::available('email',$config) ||
                !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D',$config['D2S_SMTP_HOST'])) {
                throw new Problem('smtp_configuration_invalid');
            }
            AccountInput::contact('email',$config['D2S_SMTP_FROM']);
        }
        if ($config['D2S_MAIL_DRIVER'] === 'ses') {
            if (!DeliveryGateway::available('email',$config)) {
                throw new Problem('ses_configuration_invalid');
            }
            AccountInput::contact('email',$config['D2S_SES_FROM']);
            if ($config['D2S_SES_REPLY_TO'] !== '') {
                AccountInput::contact('email',$config['D2S_SES_REPLY_TO']);
            }
            foreach (['REGISTER','PASSWORD_RESET','BIND_CONTACT'] as $scene) {
                $number = filter_var($config['D2S_SES_TEMPLATE_' . $scene],FILTER_VALIDATE_INT);
                if ($number === false || $number <= 0) {
                    throw new Problem('ses_template_invalid');
                }
            }
        }
        foreach (['D2S_SES_TEMPLATE_TRANSACTION','D2S_SES_TEMPLATE_TRANSACTION_EN','D2S_SMS_TEMPLATE_TRANSACTION','D2S_SMS_TEMPLATE_TRANSACTION_EN'] as $field) {
            if ($config[$field] !== '' && !preg_match('/^[1-9][0-9]{0,15}$/D',$config[$field])) throw new Problem('transaction_template_invalid');
        }
        if (!in_array($config['D2S_NOTIFICATION_TEMPLATE_FALLBACK'],['zh-Hans','en'],true)) {
            throw new Problem('delivery_setting_invalid');
        }
        if ($config['D2S_NOTIFICATION_TEMPLATE_FALLBACK']==='en' &&
            (($config['D2S_MAIL_DRIVER']==='ses' && $config['D2S_SES_TEMPLATE_TRANSACTION_EN']==='') ||
             ($config['D2S_SMS_ENABLED']==='true' && $config['D2S_SMS_TEMPLATE_TRANSACTION_EN']===''))) {
            throw new Problem('transaction_template_invalid');
        }
        if ($config['D2S_SMS_ENABLED'] === 'true' && !DeliveryGateway::available('phone',$config)) {
            throw new Problem('sms_configuration_invalid');
        }
    }

    private function redact(array $config): array
    {
        $configured = [];
        $values = $config;
        foreach (self::SECRET_FIELDS as $key) {
            $configured[$key] = $config[$key] !== '';
            unset($values[$key]);
        }
        return ['values'=>$values,'secrets_configured'=>$configured,
            'email_available'=>DeliveryGateway::available('email',$config),
            'phone_available'=>DeliveryGateway::available('phone',$config),
            'notification_template_coverage'=>$this->notificationTemplateCoverage($config)];
    }

    private function notificationTemplateCoverage(array $config): array
    {
        $fallback = in_array($config['D2S_NOTIFICATION_TEMPLATE_FALLBACK'] ?? null, ['zh-Hans','en'], true)
            ? $config['D2S_NOTIFICATION_TEMPLATE_FALLBACK'] : 'zh-Hans';
        $registry = new LocaleRegistry();
        $locales = $registry->all();
        $mailDriver = $config['D2S_MAIL_DRIVER'] ?? '';
        $smtpAvailable = $mailDriver === 'smtp' && DeliveryGateway::available('email', $config);
        $sesAvailable = $mailDriver === 'ses' && DeliveryGateway::available('email', $config);
        $smsAvailable = DeliveryGateway::available('phone', $config);
        $rows = [];
        foreach ($locales as $locale) {
            $code = (string)$locale['code'];
            $rows[] = [
                'locale'=>$code,
                'native_name'=>(string)$locale['native_name'],
                'channels'=>[
                    'smtp'=>$this->templateCoverageCell($registry, $code, $fallback, $smtpAvailable, true, $config, 'SMTP'),
                    'ses'=>$this->templateCoverageCell($registry, $code, $fallback, $sesAvailable, false, $config, 'SES'),
                    'sms'=>$this->templateCoverageCell($registry, $code, $fallback, $smsAvailable, false, $config, 'SMS'),
                ],
            ];
        }
        return ['fallback_locale'=>$fallback,'locales'=>$rows];
    }

    private function templateCoverageCell(LocaleRegistry $registry, string $requested, string $fallback, bool $available, bool $smtp, array $config, string $driver): array
    {
        if (!$available) return ['status'=>'unavailable','effective_locale'=>null,'effective_name'=>null];
        $supported = in_array($requested, ['zh-Hans','en'], true);
        if ($smtp) {
            $effective = $supported ? $requested : $fallback;
        } else {
            $prefix = $driver === 'SES' ? 'D2S_SES_TEMPLATE_TRANSACTION' : 'D2S_SMS_TEMPLATE_TRANSACTION';
            $requestedKey = $prefix . ($requested === 'en' ? '_EN' : '');
            $fallbackKey = $prefix . ($fallback === 'en' ? '_EN' : '');
            $requestedTemplate = trim((string)($config[$requestedKey] ?? ''));
            $fallbackTemplate = trim((string)($config[$fallbackKey] ?? ''));
            if ($supported && preg_match('/^[1-9][0-9]{0,15}$/D', $requestedTemplate) === 1) {
                $effective = $requested;
            } elseif (preg_match('/^[1-9][0-9]{0,15}$/D', $fallbackTemplate) === 1) {
                $effective = $fallback;
            } else {
                return ['status'=>'unavailable','effective_locale'=>null,'effective_name'=>null];
            }
        }
        $definition = $registry->get($effective);
        return ['status'=>$effective === $requested ? 'direct' : 'fallback',
            'effective_locale'=>$effective,'effective_name'=>(string)($definition['native_name'] ?? $effective)];
    }

    private function email(int $adminId): string
    {
        $query = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$adminId]);
        $email = $query->fetchColumn();
        if (!is_string($email)) {
            throw new Problem('administrator_unavailable',403);
        }
        return $email;
    }
}
