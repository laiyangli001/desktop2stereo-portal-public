<?php
declare(strict_types=1);
namespace D2S\services;
use Closure;
use D2S\domain\Problem;

final readonly class InternationalClient
{
    public function __construct(private string $provider,private array $config,private ?Closure $transport=null)
    {
        if(!in_array($provider,['paypal','stripe'],true))throw new Problem('payment_provider_invalid');
        PaymentConfigurations::validate($provider,$config,true);
    }

    public function create(array $order,string $returnUrl,string $cancelUrl): array
    {
        if($this->provider==='stripe'){
            $account=$this->request('GET','/v1/account');
            if(($account['id']??'')!==$this->config['account_id'])throw new Problem('payment_merchant_mismatch',502);
            $software=$order['product'] ?? 'desktop2stereo';
            $data=$this->request('POST','/v1/checkout/sessions',[
                'mode'=>'payment','adaptive_pricing'=>['enabled'=>false],'success_url'=>$returnUrl,'cancel_url'=>$cancelUrl,'client_reference_id'=>$order['id'],
                'metadata'=>['order_id'=>$order['id']],'payment_intent_data'=>['metadata'=>['order_id'=>$order['id']]],
                'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>strtolower($order['currency']),'unit_amount'=>(int)$order['amount'],
                    'product_data'=>['name'=>$software==='desktop2stereo'?'Desktop2Stereo license':$software.' license']]]],
                'expires_at'=>(int)$order['expires_at'],
            ],'checkout-'.$order['id']);
            $id=$data['id']??'';$url=$data['url']??'';
            if(!preg_match('/^cs_[A-Za-z0-9_]{5,255}$/D',$id)||!self::host($url,['checkout.stripe.com']))throw new Problem('payment_checkout_invalid',502);
        }else{
            $data=$this->request('POST','/v2/checkout/orders',[
                'intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$order['id'],'custom_id'=>$order['id'],
                    'invoice_id'=>$order['order_no']?:$order['id'],'payee'=>['merchant_id'=>$this->config['merchant_id']],
                    'amount'=>['currency_code'=>$order['currency'],'value'=>self::decimal((int)$order['amount'])]]],
                'payment_source'=>['paypal'=>['experience_context'=>['return_url'=>$returnUrl,'cancel_url'=>$cancelUrl,'user_action'=>'PAY_NOW']]],
            ],'checkout-'.$order['id']);
            $id=$data['id']??'';$url='';
            foreach($data['links']??[]as$link)if(in_array($link['rel']??'',['payer-action','approve'],true))$url=$link['href']??'';
            $hosts=$this->config['environment']==='sandbox'?['www.sandbox.paypal.com','sandbox.paypal.com']:['www.paypal.com','paypal.com'];
            if(!preg_match('/^[A-Z0-9]{8,64}$/D',$id)||!self::host($url,$hosts))throw new Problem('payment_checkout_invalid',502);
        }
        return ['id'=>$id,'url'=>$url];
    }

    public function inspect(string $providerOrderId): array
    {
        self::identifier($providerOrderId);
        if($this->provider==='stripe'){
            $data=$this->request('GET','/v1/checkout/sessions/'.rawurlencode($providerOrderId));
            if(($data['id']??'')!==$providerOrderId)throw new Problem('payment_reference_mismatch',502);
            $live=$this->config['environment']==='production';
            if(($data['livemode']??null)!==$live)throw new Problem('payment_environment_mismatch',502);
            $id=$data['payment_intent']??null;
            if(is_array($id))$id=$id['id']??null;
            return ['status'=>($data['payment_status']??'')==='paid'?'paid':(($data['status']??'')==='expired'?'expired':'pending'),
                'order_id'=>$data['client_reference_id']??'','payment_id'=>is_string($id)?$id:'',
                'currency'=>strtoupper($data['currency']??''),'amount'=>$data['amount_total']??null];
        }
        $data=$this->request('GET','/v2/checkout/orders/'.rawurlencode($providerOrderId));
        if(($data['id']??'')!==$providerOrderId)throw new Problem('payment_reference_mismatch',502);
        $units=$data['purchase_units']??[];
        if(count($units)!==1)throw new Problem('payment_purchase_unit_invalid',502);
        $unit=$units[0];$captures=$unit['payments']['captures']??[];
        $status=$data['status']??'';
        $result=['status'=>$status==='COMPLETED'?'paid':($status==='APPROVED'?'approved':($status==='VOIDED'?'expired':'pending')),
            'order_id'=>$unit['custom_id']??$unit['reference_id']??'','payment_id'=>'','currency'=>$unit['amount']['currency_code']??'',
            'amount'=>self::minor($unit['amount']['value']??'')];
        if(($unit['payee']['merchant_id']??'')!==$this->config['merchant_id'])throw new Problem('payment_merchant_mismatch',502);
        if($status==='COMPLETED'){
            if(count($captures)!==1||($captures[0]['status']??'')!=='COMPLETED')throw new Problem('payment_capture_invalid',502);
            $capture=$captures[0];
            if(self::minor($capture['amount']['value']??'')!==$result['amount']||($capture['amount']['currency_code']??'')!==$result['currency'])throw new Problem('payment_amount_mismatch',502);
            $result['payment_id']=$capture['id']??'';
        }
        return $result;
    }

    public function capture(string $providerOrderId,string $key): void
    {
        if($this->provider!=='paypal')return;
        self::identifier($providerOrderId);
        try{$this->request('POST','/v2/checkout/orders/'.rawurlencode($providerOrderId).'/capture',[],'capture-'.$key);}
        catch(Problem $error){
            if($this->inspect($providerOrderId)['status']!=='paid')throw $error;
        }
    }

    public function refund(string $paymentId,int $amount,string $currency,string $key): array
    {
        self::identifier($paymentId);
        if($this->provider==='stripe'){
            $result=$this->request('POST','/v1/refunds',['payment_intent'=>$paymentId,'amount'=>$amount,'metadata'=>['request_id'=>$key]],'refund-'.$key);
        }else{
            $result=$this->request('POST','/v2/payments/captures/'.rawurlencode($paymentId).'/refund',
                ['amount'=>['value'=>self::decimal($amount),'currency_code'=>$currency],'custom_id'=>$key],'refund-'.$key);
        }
        $id=$result['id']??'';self::identifier($id);
        return ['id'=>$id,'status'=>self::refundStatus((string)($result['status']??''))];
    }

    public function refundDetails(string $id): array
    {
        self::identifier($id);
        if($this->provider==='stripe'){
            $data=$this->request('GET','/v1/refunds/'.rawurlencode($id));$payment=$data['payment_intent']??'';
            return ['id'=>$data['id']??'','payment_id'=>is_string($payment)?$payment:'',
                'amount'=>$data['amount']??null,'currency'=>strtoupper($data['currency']??''),
                'status'=>self::refundStatus((string)($data['status']??'')),'request_id'=>$data['metadata']['request_id']??null];
        }
        $data=$this->request('GET','/v2/payments/refunds/'.rawurlencode($id));$payment='';
        foreach($data['links']??[]as$link){
            if(($link['rel']??'')==='up'&&preg_match('#^https://api(?:-m)?(?:\.sandbox)?\.paypal\.com/v2/payments/captures/([A-Z0-9]+)$#D',(string)($link['href']??''),$matches))$payment=$matches[1];
        }
        return ['id'=>$data['id']??'','payment_id'=>$payment,'amount'=>self::minor($data['amount']['value']??''),
            'currency'=>$data['amount']['currency_code']??'','status'=>self::refundStatus((string)($data['status']??'')),'request_id'=>$data['custom_id']??null];
    }

    public function webhook(array $headers,string $body,int $now): array
    {
        if(strlen($body)>1048576)throw new Problem('payment_event_too_large',413);
        $headers=array_change_key_case($headers,CASE_LOWER);
        if($this->provider==='stripe'){
            $fields=[];foreach(explode(',',(string)($headers['stripe-signature']??''))as$part){[$key,$value]=explode('=',trim($part),2)+['',''];$fields[$key][]=$value;}
            $timestamp=$fields['t'][0]??'';
            if(!ctype_digit($timestamp)||abs($now-(int)$timestamp)>300)throw new Problem('payment_signature_invalid',400);
            $signature=hash_hmac('sha256',$timestamp.'.'.$body,$this->config['webhook_secret']);$valid=false;
            foreach($fields['v1']??[]as$candidate)if(hash_equals($signature,$candidate))$valid=true;
            if(!$valid)throw new Problem('payment_signature_invalid',400);
        }
        try{$event=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('payment_event_invalid');}
        if(!is_array($event)||!is_string($event['id']??null))throw new Problem('payment_event_invalid');
        if($this->provider==='paypal'){
            $required=['paypal-auth-algo','paypal-cert-url','paypal-transmission-id','paypal-transmission-sig','paypal-transmission-time'];
            foreach($required as$key)if(!is_string($headers[$key]??null)||strlen($headers[$key])>4096)throw new Problem('payment_signature_invalid');
            $result=$this->request('POST','/v1/notifications/verify-webhook-signature',[
                'auth_algo'=>$headers['paypal-auth-algo'],'cert_url'=>$headers['paypal-cert-url'],
                'transmission_id'=>$headers['paypal-transmission-id'],'transmission_sig'=>$headers['paypal-transmission-sig'],
                'transmission_time'=>$headers['paypal-transmission-time'],'webhook_id'=>$this->config['webhook_id'],'webhook_event'=>$event,
            ]);
            if(($result['verification_status']??'')!=='SUCCESS')throw new Problem('payment_signature_invalid',400);
        }else{
            if(($event['livemode']??null)!==($this->config['environment']==='production'))throw new Problem('payment_environment_mismatch');
            if(isset($event['account'])&&$event['account']!==$this->config['account_id'])throw new Problem('payment_merchant_mismatch');
        }
        return $event;
    }

    private function request(string $method,string $path,array $data=[],?string $key=null): array
    {
        if($this->transport!==null)return($this->transport)($this->provider,$this->config,$method,$path,$data,$key);
        $host=$this->provider==='stripe'?'https://api.stripe.com':
            ($this->config['environment']==='sandbox'?'https://api-m.sandbox.paypal.com':'https://api-m.paypal.com');
        if(!str_starts_with($path,'/v1/')&&!str_starts_with($path,'/v2/'))throw new Problem('payment_endpoint_invalid');
        if($this->provider==='stripe'){
            $headers=['Authorization: Bearer '.$this->config['secret_key'],'Stripe-Version: 2024-11-20.acacia','Content-Type: application/x-www-form-urlencoded'];
            if($key!==null)$headers[]='Idempotency-Key: '.$key;
            return $this->http($method,$host.$path,$headers,$method==='POST'?http_build_query(self::formValues($data),'','&',PHP_QUERY_RFC3986):null);
        }
        $token=$this->http('POST',$host.'/v1/oauth2/token',[
            'Authorization: Basic '.base64_encode($this->config['client_id'].':'.$this->config['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ],'grant_type=client_credentials');
        if(!is_string($token['access_token']??null)||strlen($token['access_token'])>8192)throw new Problem('payment_authentication_failed',502);
        $headers=['Authorization: Bearer '.$token['access_token'],'Content-Type: application/json'];
        if($key!==null)$headers[]='PayPal-Request-Id: '.$key;
        return $this->http($method,$host.$path,$headers,$method==='POST'?json_encode($data===[]?new \stdClass():$data,JSON_THROW_ON_ERROR):null);
    }

    private static function formValues(array $values): array
    {
        foreach($values as$key=>$value)$values[$key]=is_array($value)?self::formValues($value):(is_bool($value)?($value?'true':'false'):$value);
        return $values;
    }

    private function http(string $method,string $url,array $headers,?string $body): array
    {
        $curl=curl_init($url);$response='';
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $bytes)use(&$response):int{
                if(strlen($response)+strlen($bytes)>2097152)return 0;$response.=$bytes;return strlen($bytes);
            }]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        if(PHP_OS_FAMILY==='Windows'&&defined('CURLSSLOPT_NATIVE_CA'))curl_setopt($curl,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);
        $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($ok===false||$status<200||$status>=300)throw new Problem('payment_provider_request_failed',502);
        try{$value=json_decode($response,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('payment_provider_response_invalid',502);}
        if(!is_array($value))throw new Problem('payment_provider_response_invalid',502);
        return $value;
    }

    private static function host(string $url,array $hosts): bool
    {
        $parts=parse_url($url);
        return is_array($parts)&&($parts['scheme']??'')==='https'&&in_array($parts['host']??'',$hosts,true)&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['port']);
    }

    private static function identifier(string $id): void
    {
        if(!preg_match('/^[A-Za-z0-9_-]{5,255}$/D',$id))throw new Problem('payment_reference_invalid',502);
    }

    private static function refundStatus(string $status): string
    {
        return match(strtoupper($status)){'SUCCEEDED','COMPLETED'=>'succeeded','FAILED','CANCELED','CANCELLED'=>'failed',default=>'submitted'};
    }

    public static function decimal(int $amount): string
    {
        return intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT);
    }

    public static function minor(string $value): int
    {
        if(!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D',$value,$parts))throw new Problem('payment_amount_invalid',502);
        return (int)$parts[1]*100+(int)str_pad($parts[2]??'',2,'0');
    }
}
