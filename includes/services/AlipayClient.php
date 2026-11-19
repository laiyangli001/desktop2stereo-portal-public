<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Money;
use D2S\domain\Problem;

/** Merchant RSA2 public-key protocol. Provider identity/business settlement is a separate boundary. */
final readonly class AlipayClient
{
    public const METHODS = ['alipay.trade.page.pay','alipay.trade.query','alipay.trade.close','alipay.trade.refund',
        'alipay.trade.fastpay.refund.query','alipay.data.dataservice.bill.downloadurl.query'];

    public function __construct(private array $config, private ?Closure $transport = null)
    {
        AlipaySettings::validate($config,true);
    }

    public function parameters(string $method, array $business, int $now): array
    {
        if (!in_array($method,self::METHODS,true)) { throw new Problem('alipay_method_invalid'); }
        $parameters = [
            'app_id'=>$this->config['app_id'],'method'=>$method,'format'=>'JSON','charset'=>'utf-8',
            'sign_type'=>'RSA2','timestamp'=>self::timestamp($now),'version'=>'1.0',
            'biz_content'=>json_encode($business,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($method === 'alipay.trade.page.pay') {
            $parameters['notify_url'] = $this->config['notify_url'];
            $parameters['return_url'] = $this->config['return_url'];
        }
        $parameters['sign'] = AlipayRSA::sign(AlipayRSA::canonical($parameters,false),AlipayRSA::key($this->config['private_key'],true));
        return $parameters;
    }

    public function pagePay(array $order, int $now): array
    {
        if ($this->config['enabled'] !== 'true' || ($order['currency'] ?? null) !== 'CNY' ||
            ($order['payment_method'] ?? null) !== 'alipay_pc' || ($order['status'] ?? null) !== 'pending' ||
            !preg_match('/^[a-f0-9]{32}$/D',$order['id'] ?? '') || !is_int($order['expires_at'] ?? null) ||
            $order['expires_at']-$now<60 || $order['expires_at']-$now>15*86400) {
            throw new Problem('alipay_checkout_unavailable',409);
        }
        $reference=array_key_exists('merchant_order_no',$order)?$order['merchant_order_no']:'D2S'.$order['id'];
        if(!is_string($reference)||!preg_match('/^(?:[0-9]{16}|D2S[a-f0-9]{32})$/D',$reference))throw new Problem('alipay_checkout_unavailable',409);
        $amount = Money::positive($order['amount'] ?? null);
        if ($amount > 10000000000) { throw new Problem('alipay_amount_out_of_range'); }
        $software=$order['product'] ?? 'desktop2stereo';
        $business = ['out_trade_no'=>$reference,'product_code'=>'FAST_INSTANT_TRADE_PAY',
            'subject'=>$software==='wallet_recharge'?'Desktop2Stereo 钱包充值':($software==='desktop2stereo'?'Desktop2Stereo 正式授权':$software.' license'),
            'total_amount'=>intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT),
            'qr_pay_mode'=>$this->config['qr_pay_mode'],'time_expire'=>self::timestamp($order['expires_at'])];
        if ($this->config['qr_pay_mode'] === '4') { $business['qrcode_width'] = $this->config['qrcode_width']; }
        return ['gateway'=>AlipaySettings::GATEWAYS[$this->config['environment']],
            'parameters'=>$this->parameters('alipay.trade.page.pay',$business,$now),
            'qr_pay_mode'=>$this->config['qr_pay_mode'],'qrcode_width'=>(int)$this->config['qrcode_width']];
    }

    public function notification(string $raw): array
    {
        $parameters = AlipayRSA::form($raw);
        if (($parameters['sign_type'] ?? '') !== 'RSA2' ||
            !AlipayRSA::verify(AlipayRSA::canonical($parameters,true),$parameters['sign'] ?? '',
                AlipayRSA::key($this->config['alipay_public_key'],false))) {
            throw new Problem('alipay_signature_invalid');
        }
        if (($parameters['app_id'] ?? '') !== $this->config['app_id'] || ($parameters['seller_id'] ?? '') !== $this->config['seller_id'] ||
            (isset($parameters['auth_app_id']) && $parameters['auth_app_id'] !== $this->config['app_id'])) {
            throw new Problem('alipay_identity_mismatch');
        }
        return $parameters;
    }

    public function call(string $method, array $business, int $now): array
    {
        if ($method === 'alipay.trade.page.pay') { throw new Problem('alipay_method_invalid'); }
        $parameters = $this->parameters($method,$business,$now);
        $url = AlipaySettings::GATEWAYS[$this->config['environment']];
        $body = http_build_query($parameters,'','&',PHP_QUERY_RFC3986);
        $raw = $this->transport === null ? self::post($url,$body) : ($this->transport)($url,$body);
        return $this->response($method,$raw);
    }

    public function response(string $method, string $raw): array
    {
        if (!in_array($method,self::METHODS,true) || $method === 'alipay.trade.page.pay') { throw new Problem('alipay_method_invalid'); }
        $fields = RawJson::fields($raw);
        $key = str_replace('.','_',$method).'_response';
        if (!isset($fields[$key],$fields['sign']) || array_diff(array_keys($fields),[$key,'sign','sign_type']) !== []) {
            throw new Problem('alipay_response_invalid',502);
        }
        $signature = json_decode($fields['sign'],true,2,JSON_THROW_ON_ERROR);
        $type = isset($fields['sign_type']) ? json_decode($fields['sign_type'],true,2,JSON_THROW_ON_ERROR) : 'RSA2';
        $public = AlipayRSA::key($this->config['alipay_public_key'],false);
        $verified = $type === 'RSA2' && is_string($signature) && (
            AlipayRSA::verify($fields[$key],$signature,$public) ||
            // Match the official SDK's narrowly scoped escaped-slash compatibility.
            AlipayRSA::verify(str_replace('\\/','/',$fields[$key]),$signature,$public)
        );
        if (!$verified) { throw new Problem('alipay_signature_invalid',502); }
        $payload = json_decode($fields[$key],false,32,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!$payload instanceof \stdClass) { throw new Problem('alipay_response_invalid',502); }
        return (array)$payload;
    }

    private static function timestamp(int $now): string
    {
        return (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    }

    private static function post(string $url, string $body): string
    {
        $raw = '';
        $curl = curl_init($url);
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$raw): int {
                if (strlen($raw)+strlen($chunk)>1048576) { return 0; }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) { throw new Problem('alipay_transport_unknown',502); }
        return $raw;
    }
}
