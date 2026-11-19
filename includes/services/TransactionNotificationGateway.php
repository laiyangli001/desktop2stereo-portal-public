<?php
declare(strict_types=1);
namespace D2S\services;

use Closure;
use D2S\domain\AccountInput;
use D2S\domain\Money;
use D2S\domain\Problem;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ses\V20201002\SesClient;
use TencentCloud\Ses\V20201002\Models\SendEmailRequest;
use TencentCloud\Sms\V20210111\SmsClient;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;

final readonly class TransactionNotificationGateway implements MessageSender
{
    public function __construct(private array $config, private ?Closure $transport=null) {}

    public function send(array $message):void
    {
        $events=['ORDER_PAID','PAYMENT_REVIEW','REFUND_CONFIRMED'];
        if(!in_array($message['event_type']??null,$events,true)||!preg_match('/^[a-f0-9]{32}$/D',$message['id']??'')||
            !preg_match('/^[a-f0-9]{32}$/D',$message['order_id']??'')||
            !in_array($message['license_effect']??null,['activated','revoked','unchanged'],true)||
            (int)($message['template_version']??1)!==1)throw new RuntimeException('notification_message_invalid');
        $locale=$this->notificationLocale($message['locale_snapshot']??'');
        $amount=Money::positive($message['amount']??null);Money::currency($message['currency']??'');
        $target=AccountInput::contact($message['kind']??'',$message['target']??'');
        $orderNo=$message['order_no']??null;
        if($orderNo!==null&&(!is_string($orderNo)||!preg_match('/^[0-9]{16}$/D',$orderNo)))throw new RuntimeException('notification_message_invalid');
        $variables=$this->variables($message,$amount,$orderNo,$locale);
        if($message['kind']==='phone'){
            if(($this->config['D2S_SMS_ENABLED']??'')!=='true')throw new Problem('notification_channel_unconfigured',503);
            $this->required(['D2S_SMS_SECRET_ID','D2S_SMS_SECRET_KEY','D2S_SMS_APP_ID','D2S_SMS_SIGN_NAME']);
            $deliveryLocale=$this->providerLocale('sms',$locale);
            $variables=$this->variables($message,$amount,$orderNo,$deliveryLocale);
            $payload=['PhoneNumberSet'=>[$target],'SmsSdkAppId'=>$this->config['D2S_SMS_APP_ID'],'SignName'=>$this->config['D2S_SMS_SIGN_NAME'],
                'TemplateId'=>$this->providerTemplate('sms',$deliveryLocale),
                'TemplateParamSet'=>[$variables['event_title'],$variables['order_ref'],$variables['amount'],$variables['currency']],
                'SessionContext'=>$message['id']];
            $response=$this->dispatch('sms',$payload);
            if(count($response['SendStatusSet']??[])!==1||($response['SendStatusSet'][0]['Code']??null)!=='Ok')throw new RuntimeException('notification_delivery_rejected');
            return;
        }
        $driver=$this->config['D2S_MAIL_DRIVER']??'';
        if($driver==='ses'){
            $this->required(['D2S_SES_SECRET_ID','D2S_SES_SECRET_KEY','D2S_SES_FROM']);
            $deliveryLocale=$this->providerLocale('ses',$locale);
            $variables=$this->variables($message,$amount,$orderNo,$deliveryLocale);
            $payload=['FromEmailAddress'=>AccountInput::contact('email',$this->config['D2S_SES_FROM']),'Destination'=>[$target],
                'Subject'=>$variables['subject'],
                'Template'=>['TemplateID'=>$this->providerTemplate('ses',$deliveryLocale),'TemplateData'=>json_encode($variables,JSON_THROW_ON_ERROR)]];
            if(($this->config['D2S_SES_REPLY_TO']??'')!=='')$payload['ReplyToAddresses']=AccountInput::contact('email',$this->config['D2S_SES_REPLY_TO']);
            $response=$this->dispatch('ses',$payload);
            if(!is_string($response['MessageId']??null)||$response['MessageId']==='')throw new RuntimeException('notification_delivery_rejected');
            return;
        }
        if($driver!=='smtp')throw new Problem('notification_channel_unconfigured',503);
        $this->required(['D2S_SMTP_HOST','D2S_SMTP_USER','D2S_SMTP_PASSWORD','D2S_SMTP_FROM']);
        $port=filter_var($this->config['D2S_SMTP_PORT']??'',FILTER_VALIDATE_INT);
        if(!in_array($port,[465,587],true))throw new Problem('notification_channel_unconfigured',503);
        $payload=['from'=>AccountInput::contact('email',$this->config['D2S_SMTP_FROM']),'to'=>$target,'port'=>$port,
            'subject'=>$variables['subject'],'message_id'=>'<d2s-transaction-'.$message['id'].'@d2s.site>',
            'body'=>$variables['event_title']."\n".$variables['order_label'].': '.$variables['order_ref']."\n".
                $variables['amount_label'].': '.$variables['currency'].' '.$variables['amount']."\n".
                $variables['license_effect_label'].': '.$variables['license_effect_value']."\n".
                $variables['event_time_label'].': '.$variables['event_time']."\n".$variables['notice']];
        if(($this->dispatch('smtp',$payload)['accepted']??null)!==true)throw new RuntimeException('notification_delivery_rejected');
    }

    private function notificationLocale(mixed $snapshot):string
    {
        $fallback=$this->fallbackLocale();
        if(!is_string($snapshot))return $fallback;
        $locale=(new LocaleRegistry())->normalizeForAdmin($snapshot);
        return in_array($locale,['zh-Hans','en'],true)?$locale:$fallback;
    }

    private function fallbackLocale():string
    {
        $locale=$this->config['D2S_NOTIFICATION_TEMPLATE_FALLBACK']??'zh-Hans';
        return in_array($locale,['zh-Hans','en'],true)?$locale:'zh-Hans';
    }

    private function providerLocale(string $driver,string $requested):string
    {
        foreach(array_unique([$requested,$this->fallbackLocale()])as$locale){
            if($this->providerTemplate($driver,$locale)!=='')return $locale;
        }
        throw new Problem('notification_channel_unconfigured',503);
    }

    private function providerTemplate(string $driver,string $locale):string
    {
        if(!in_array($driver,['ses','sms'],true)||!in_array($locale,['zh-Hans','en'],true))return '';
        $prefix=$driver==='ses'?'D2S_SES_TEMPLATE_TRANSACTION':'D2S_SMS_TEMPLATE_TRANSACTION';
        $key=$prefix.($locale==='en'?'_EN':'');
        $value=trim((string)($this->config[$key]??''));
        return preg_match('/^[1-9][0-9]{0,15}$/D',$value)?$value:'';
    }

    private function variables(array $message,int $amount,?string $orderNo,string $locale):array
    {
        $english=$locale==='en';
        $titles=$english?[
            'ORDER_PAID'=>'Payment confirmed',
            'PAYMENT_REVIEW'=>'Payment received for review',
            'REFUND_CONFIRMED'=>'Refund confirmed',
        ]:[
            'ORDER_PAID'=>'付款确认',
            'PAYMENT_REVIEW'=>'到账待核对',
            'REFUND_CONFIRMED'=>'退款确认',
        ];
        $effects=$english?[
            'activated'=>'Activated',
            'revoked'=>'Revoked',
            'unchanged'=>'Unchanged',
        ]:[
            'activated'=>'已生效',
            'revoked'=>'已撤销',
            'unchanged'=>'未变更',
        ];
        $eventType=(string)$message['event_type'];
        $effect=(string)$message['license_effect'];
        $title=$titles[$eventType]??$titles['PAYMENT_REVIEW'];
        return [
            'locale'=>$locale,
            'subject'=>$english?'Desktop2Stereo — '.$title:'Desktop2Stereo · '.$title,
            'event_title'=>$title,
            'order_label'=>$english?'Order':'订单',
            'order_ref'=>$orderNo??substr((string)$message['order_id'],-8),
            'amount_label'=>$english?'Amount':'金额',
            'amount'=>intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT),
            'currency'=>$message['currency'],
            'license_effect_label'=>$english?'License effect':'授权状态',
            'license_effect'=>$effect,
            'license_effect_value'=>$effects[$effect]??$effects['unchanged'],
            'event_time_label'=>$english?'Time (UTC)':'时间（UTC）',
            'event_time'=>$this->localizedEventTime((int)$message['created_at'],$locale),
            'notice'=>$english?'This records the transaction event. Sign in to view the current account status.':'此邮件记录交易发生时的状态。请登录账户查看当前状态。',
            'event_id'=>$message['id'],
        ];
    }

    private function localizedEventTime(int $timestamp,string $locale):string
    {
        $formatter=new \IntlDateFormatter($locale,\IntlDateFormatter::MEDIUM,\IntlDateFormatter::SHORT,'UTC');
        $formatted=$formatter->format($timestamp);
        if(!is_string($formatted))throw new RuntimeException('notification_time_format_failed');
        return $formatted;
    }

    private function dispatch(string $driver,array $payload):array
    {
        if($this->transport!==null)return ($this->transport)($driver,$payload);
        if($driver==='smtp'){
            $mail=new PHPMailer(true);$mail->isSMTP();$mail->Host=$this->config['D2S_SMTP_HOST'];$mail->Port=$payload['port'];
            $mail->SMTPSecure=$payload['port']===465?PHPMailer::ENCRYPTION_SMTPS:PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth=true;$mail->Username=$this->config['D2S_SMTP_USER'];$mail->Password=$this->config['D2S_SMTP_PASSWORD'];
            $mail->Timeout=5;$mail->Timelimit=10;$mail->SMTPDebug=0;$mail->CharSet='UTF-8';
            $mail->setFrom($payload['from'],'Desktop2Stereo');$mail->addAddress($payload['to']);
            $mail->Subject=$payload['subject'];$mail->Body=$payload['body'];$mail->MessageID=$payload['message_id'];
            return ['accepted'=>$mail->send()];
        }
        $ses=$driver==='ses';$prefix=$ses?'D2S_SES_':'D2S_SMS_';
        $http=new HttpProfile();$http->setEndpoint($ses?'ses.tencentcloudapi.com':'sms.tencentcloudapi.com');$http->setReqTimeout(5);
        $profile=new ClientProfile();$profile->setHttpProfile($http);
        $credentials=new Credential($this->config[$prefix.'SECRET_ID'],$this->config[$prefix.'SECRET_KEY']);
        if($ses){
            $client=new SesClient($credentials,$this->config['D2S_SES_REGION']?:'ap-shanghai',$profile);
            $request=new SendEmailRequest();$request->fromJsonString(json_encode($payload,JSON_THROW_ON_ERROR));
            return json_decode($client->SendEmail($request)->toJsonString(),true,32,JSON_THROW_ON_ERROR);
        }
        $client=new SmsClient($credentials,$this->config['D2S_SMS_REGION']?:'ap-guangzhou',$profile);
        $request=new SendSmsRequest();$request->fromJsonString(json_encode($payload,JSON_THROW_ON_ERROR));
        return json_decode($client->SendSms($request)->toJsonString(),true,32,JSON_THROW_ON_ERROR);
    }

    private function required(array $keys):void
    {
        foreach($keys as$key)if(!is_string($this->config[$key]??null)||trim($this->config[$key])==='')throw new Problem('notification_channel_unconfigured',503);
    }
}
