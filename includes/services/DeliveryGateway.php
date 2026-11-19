<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\AccountInput;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ses\V20201002\SesClient;
use TencentCloud\Ses\V20201002\Models\SendEmailRequest;
use TencentCloud\Sms\V20210111\SmsClient;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;

final class DeliveryGateway implements MessageSender
{
    public function __construct(private readonly ?array $configuration = null)
    {
    }
    public static function available(string $kind, ?array $configuration = null): bool
    {
        if ($kind === 'phone') {
            if (self::configured($configuration,'D2S_SMS_ENABLED') !== 'true') {
                return false;
            }
            $required = ['D2S_SMS_SECRET_ID','D2S_SMS_SECRET_KEY','D2S_SMS_APP_ID','D2S_SMS_SIGN_NAME'];
            $prefix = 'D2S_SMS_TEMPLATE_';
        } elseif ($kind === 'email' && self::configured($configuration,'D2S_MAIL_DRIVER') === 'ses') {
            $required = ['D2S_SES_SECRET_ID','D2S_SES_SECRET_KEY','D2S_SES_FROM'];
            $prefix = 'D2S_SES_TEMPLATE_';
        } elseif ($kind === 'email' && self::configured($configuration,'D2S_MAIL_DRIVER') === 'smtp') {
            $required = ['D2S_SMTP_HOST','D2S_SMTP_USER','D2S_SMTP_PASSWORD','D2S_SMTP_FROM'];
            if (!in_array(self::configured($configuration,'D2S_SMTP_PORT'), ['465','587'], true)) {
                return false;
            }
            $prefix = null;
        } else {
            return false;
        }
        if ($prefix !== null) {
            foreach (['REGISTER','PASSWORD_RESET','BIND_CONTACT'] as $scene) {
                $required[] = $prefix . $scene;
            }
        }
        foreach ($required as $name) {
            if (!is_string(self::configured($configuration,$name)) || trim(self::configured($configuration,$name)) === '') {
                return false;
            }
        }
        return true;
    }

    public function send(array $message): void
    {
        if (!in_array($message['purpose'] ?? '', ['register','password_reset','bind_contact'], true) ||
            !preg_match('/^[0-9]{6}$/D', $message['code'] ?? '')) {
            throw new RuntimeException('delivery_message_invalid');
        }
        $target = AccountInput::contact($message['kind'] ?? '', $message['target'] ?? '');
        if ($message['kind'] === 'phone') {
            if (self::configured($this->configuration,'D2S_SMS_ENABLED') !== 'true') {
                throw new RuntimeException('sms_disabled');
            }
            $client = new SmsClient(
                new Credential($this->required('D2S_SMS_SECRET_ID'), $this->required('D2S_SMS_SECRET_KEY')),
                self::configured($this->configuration,'D2S_SMS_REGION') ?: 'ap-guangzhou',
                self::profile('sms.tencentcloudapi.com'),
            );
            $request = new SendSmsRequest();
            $request->fromJsonString(json_encode([
                'PhoneNumberSet' => [$target],
                'SmsSdkAppId' => $this->required('D2S_SMS_APP_ID'),
                'TemplateId' => $this->required('D2S_SMS_TEMPLATE_' . strtoupper($message['purpose'])),
                'SignName' => $this->required('D2S_SMS_SIGN_NAME'),
                'TemplateParamSet' => [$message['code'], '10'],
            ], JSON_THROW_ON_ERROR));
            $response = $client->SendSms($request);
            if (count($response->SendStatusSet ?? []) !== 1 || $response->SendStatusSet[0]->Code !== 'Ok') {
                throw new RuntimeException('sms_rejected');
            }
            return;
        }
        $driver = self::configured($this->configuration,'D2S_MAIL_DRIVER');
        if ($driver === 'ses') {
            // SES is authoritative when selected. A failure never falls back to SMTP.
            $client = new SesClient(
                new Credential($this->required('D2S_SES_SECRET_ID'), $this->required('D2S_SES_SECRET_KEY')),
                self::configured($this->configuration,'D2S_SES_REGION') ?: 'ap-shanghai',
                self::profile('ses.tencentcloudapi.com'),
            );
            $templateId = filter_var($this->required('D2S_SES_TEMPLATE_' . strtoupper($message['purpose'])), FILTER_VALIDATE_INT);
            if ($templateId === false || $templateId <= 0) {
                throw new RuntimeException('ses_template_invalid');
            }
            $request = new SendEmailRequest();
            $parameters = [
                'FromEmailAddress' => AccountInput::contact('email', $this->required('D2S_SES_FROM')),
                'Destination' => [$target],
                'Subject' => 'Desktop2Stereo 账户验证码',
                'Template' => [
                    'TemplateID' => $templateId,
                    'TemplateData' => json_encode(['code' => $message['code'], 'minutes' => '10', 'purpose' => $message['purpose']], JSON_THROW_ON_ERROR),
                ],
            ];
            if (self::configured($this->configuration,'D2S_SES_REPLY_TO')) {
                $parameters['ReplyToAddresses'] = AccountInput::contact('email', $this->required('D2S_SES_REPLY_TO'));
            }
            $request->fromJsonString(json_encode($parameters, JSON_THROW_ON_ERROR));
            $response = $client->SendEmail($request);
            if (empty($response->MessageId)) {
                throw new RuntimeException('ses_rejected');
            }
            return;
        }
        if ($driver !== 'smtp') {
            throw new RuntimeException('mail_driver_unavailable');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->required('D2S_SMTP_HOST');
        $port = filter_var($this->required('D2S_SMTP_PORT'), FILTER_VALIDATE_INT);
        if (!in_array($port, [465, 587], true)) {
            throw new RuntimeException('smtp_secure_port_required');
        }
        $mail->Port = $port;
        $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = true;
        $mail->Username = $this->required('D2S_SMTP_USER');
        $mail->Password = $this->required('D2S_SMTP_PASSWORD');
        $mail->Timeout = 5;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(AccountInput::contact('email', $this->required('D2S_SMTP_FROM')), 'Desktop2Stereo');
        $mail->addAddress($target);
        $mail->Subject = 'Desktop2Stereo 账户验证码';
        $mail->Body = '您的验证码是 ' . $message['code'] . '，10分钟内有效。请勿向他人提供。';
        $mail->send();
    }

    private function required(string $key): string
    {
        $value = self::configured($this->configuration,$key);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('delivery_configuration_required');
        }
        return $value;
    }

    private static function configured(?array $configuration, string $key): string|false
    {
        return $configuration === null ? getenv($key) : ($configuration[$key] ?? false);
    }

    private static function profile(string $endpoint): ClientProfile
    {
        $http = new HttpProfile();
        $http->setEndpoint($endpoint);
        $http->setReqTimeout(5);
        $profile = new ClientProfile();
        $profile->setHttpProfile($http);
        return $profile;
    }
}
