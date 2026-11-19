<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use D2S\domain\Money;
use D2S\domain\Problem;

/** Verified provider adapter. Callers must still lock, reconcile, audit and commit business effects. */
final readonly class WechatClient
{
    public const MAX_TOTAL = 10000000000;
    private WechatHttp $http;

    public function __construct(private array $config,int $now,?Closure $transport=null,?array $verificationKeys=null)
    {
        $crypto=WechatSettings::protocolConfiguration($config,$now);
        if($verificationKeys!==null)$crypto['verification_keys']=$verificationKeys;
        $this->http=new WechatHttp($crypto,$transport);
    }

    public function native(array $order,int $now,?string $notificationProfile=null): array
    {
        self::order($order);
        if($this->config['enabled']!=='true'||($order['status']??null)!=='pending'||
            !is_int($order['expires_at']??null)||$order['expires_at']<$now+60||$order['expires_at']>$now+7*86400){
            throw new Problem('wechat_checkout_unavailable',409);
        }
        $notify=$this->config['notify_url'];
        if($notificationProfile!==null){
            if(!preg_match('/^[a-f0-9]{64}$/D',$notificationProfile))throw new Problem('wechat_notification_profile_invalid');
            $notify.='/'.$notificationProfile;
        }
        $software=$order['product'] ?? 'desktop2stereo';
        $reply=$this->http->request('POST','/v3/pay/transactions/native',[
            'appid'=>$this->config['app_id'],'mchid'=>$this->config['mchid'],
            'description'=>$software==='wallet_recharge'?'Desktop2Stereo 钱包充值':($software==='desktop2stereo'?'Desktop2Stereo 正式授权':$software.' license'),
            'out_trade_no'=>self::reference($order),
            'notify_url'=>$notify,'time_expire'=>gmdate('Y-m-d\TH:i:s\Z',$order['expires_at']),
            'amount'=>['total'=>$order['amount'],'currency'=>'CNY'],
        ],$now);
        $data=self::successful($reply);
        foreach(['appid'=>$this->config['app_id'],'mchid'=>$this->config['mchid'],'out_trade_no'=>self::reference($order)]as$field=>$expected){
            if(isset($data[$field])&&$data[$field]!==$expected)throw new Problem('wechat_payment_mismatch');
        }
        $code=$data['code_url']??null;
        $url=is_string($code)?parse_url($code):false;
        $validRoute=is_array($url)&&(
            (($url['host']??'')==='wxpay'&&($url['path']??'')==='/bizpayurl')||
            (($url['host']??'')==='pay.weixin.qq.com'&&($url['path']??'')==='/bizpayurl/up'));
        if(!is_string($code)||strlen($code)>1024||!$validRoute||($url['scheme']??'')!=='weixin'||
            array_diff(array_keys($url),['scheme','host','path','query'])!==[]||
            !preg_match('/^pr=[A-Za-z0-9_.~%+=&\/-]{1,800}$/D',$url['query']??'')||
            preg_match('/%(?![A-Fa-f0-9]{2})/',$url['query'])){
            throw new Problem('wechat_code_url_invalid',502);
        }
        // No browser return or code URL can establish payment success.
        return ['code_url'=>$code,'expires_at'=>min($order['expires_at'],$now+7200)];
    }

    public function query(array $order,int $now): array
    {
        self::order($order);
        $reply=$this->http->request('GET','/v3/pay/transactions/out-trade-no/'.self::reference($order).'?mchid='.$this->config['mchid'],null,$now);
        if($reply['http_status']===404&&($reply['data']['code']??null)==='ORDER_NOT_EXIST')return ['state'=>'NOT_FOUND'];
        return $this->transaction(self::successful($reply),$order);
    }

    /** Validate already authenticated notification data with the immutable order/merchant context. */
    public function transaction(array $data,array $order): array
    {
        return self::normalizeTransaction($data,$order,$this->config['app_id'],$this->config['mchid']);
    }

    /** Internal normalization only: caller must authenticate provider data first. */
    public static function normalizeTransaction(array $data,array $order,string $appId,string $mchid): array
    {
        self::order($order);
        if(($data['appid']??null)!==$appId||($data['mchid']??null)!==$mchid||
            ($data['out_trade_no']??null)!==self::reference($order)||(isset($data['trade_type'])&&$data['trade_type']!=='NATIVE')){
            throw new Problem('wechat_payment_mismatch');
        }
        $state=$data['trade_state']??null;
        if(!in_array($state,['SUCCESS','REFUND','NOTPAY','CLOSED'],true))throw new Problem('wechat_transaction_state_unknown',502);
        $trade=$data['transaction_id']??null;
        if($trade!==null&&(!is_string($trade)||!preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$trade)))throw new Problem('wechat_payment_mismatch');
        if(in_array($state,['SUCCESS','REFUND'],true)&&$trade===null)throw new Problem('wechat_payment_mismatch');
        $amount=$data['amount']??null;$payerTotal=null;$total=null;
        if($amount!==null){
            if(!is_array($amount)||!is_int($amount['total']??null)||$amount['total']!==$order['amount']||
                ($amount['currency']??null)!=='CNY'||(isset($amount['payer_currency'])&&$amount['payer_currency']!=='CNY')){
                throw new Problem('wechat_payment_mismatch');
            }
            $total=$amount['total'];$payerTotal=$amount['payer_total']??null;
            if($payerTotal!==null&&(!is_int($payerTotal)||$payerTotal<0||$payerTotal>$total))throw new Problem('wechat_payment_mismatch');
        }
        if(in_array($state,['SUCCESS','REFUND'],true)&&$total===null)throw new Problem('wechat_payment_mismatch');
        $payer=$data['payer']??null;$identifier=null;
        if($payer!==null){
            if(!is_array($payer)||!is_string($payer['openid']??null)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$payer['openid'])){
                throw new Problem('wechat_payment_mismatch');
            }
            $identifier=$payer['openid'];
        }
        return ['state'=>$state,'order_id'=>$order['id'],'transaction_id'=>$trade,'amount'=>$total,'currency'=>$total===null?null:'CNY',
            'payer_total'=>$payerTotal,'payer_identifier'=>$identifier];
    }

    /** A signed 204 only acknowledges this request; caller must query before/after closing. */
    public function close(array $order,int $now): array
    {
        self::order($order);
        $reply=$this->http->request('POST','/v3/pay/transactions/out-trade-no/'.self::reference($order).'/close',['mchid'=>$this->config['mchid']],$now);
        if($reply['http_status']!==204||$reply['data']!==[]||!$reply['body_empty'])throw new Problem('wechat_close_unknown',502);
        return ['accepted'=>true];
    }

    public function refund(array $request,int $now,?string $notificationProfile=null): array
    {
        self::refundRequest($request);
        $reason=$request['reason']??null;
        if(!is_string($reason)||trim($reason)===''||!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason)>80||
            preg_match('/[\x00-\x1f\x7f]/',$reason))throw new Problem('wechat_refund_reason_invalid');
        $notify=$this->config['refund_notify_url'];
        if($notificationProfile!==null){
            if(!preg_match('/^[a-f0-9]{64}$/D',$notificationProfile))throw new Problem('wechat_notification_profile_invalid');
            $notify.='/'.$notificationProfile;
        }
        $reply=$this->http->request('POST','/v3/refund/domestic/refunds',[
            'transaction_id'=>$request['transaction_id'],'out_refund_no'=>'R'.$request['id'],'reason'=>$reason,'notify_url'=>$notify,
            'amount'=>['refund'=>$request['amount'],'total'=>$request['paid_amount'],'currency'=>'CNY'],
        ],$now);
        return $this->refundResult(self::successful($reply),$request);
    }

    public function refundQuery(array $request,int $now): array
    {
        self::refundRequest($request);
        $reply=$this->http->request('GET','/v3/refund/domestic/refunds/R'.$request['id'],null,$now);
        if($reply['http_status']===404&&($reply['data']['code']??null)==='RESOURCE_NOT_EXISTS')return ['state'=>'NOT_FOUND'];
        return $this->refundResult(self::successful($reply),$request);
    }

    private function refundResult(array $data,array $request): array
    {
        return self::normalizeRefund($data,$request,$this->config['app_id'],$this->config['mchid']);
    }

    /** Internal use only after authenticating the exact provider response or notification. */
    public static function normalizeRefund(array $data,array $request,string $appId,string $mchid): array
    {
        foreach(['mchid'=>$mchid,'appid'=>$appId]as$field=>$expected){
            if(isset($data[$field])&&$data[$field]!==$expected)throw new Problem('wechat_refund_mismatch');
        }
        $amount=$data['amount']??null;
        if(($data['out_refund_no']??null)!=='R'.$request['id']||($data['out_trade_no']??null)!==self::reference($request,'order_id')||
            ($data['transaction_id']??null)!==$request['transaction_id']||
            !is_string($data['refund_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$data['refund_id'])||
            !is_array($amount)||($amount['refund']??null)!==$request['amount']||($amount['total']??null)!==$request['paid_amount']||
            ($amount['currency']??null)!=='CNY')throw new Problem('wechat_refund_mismatch');
        $state=$data['status']??null;
        if(!in_array($state,['SUCCESS','PROCESSING','CLOSED','ABNORMAL'],true))throw new Problem('wechat_refund_state_unknown',502);
        $extra=[];
        foreach(['payer_total'=>$request['paid_amount'],'payer_refund'=>$request['amount'],'settlement_total'=>$request['paid_amount'],
            'settlement_refund'=>$request['amount'],'discount_refund'=>$request['amount']]as$field=>$ceiling){
            $value=$amount[$field]??null;
            if($value!==null&&(!is_int($value)||$value<0||$value>$ceiling))throw new Problem('wechat_refund_mismatch');
            $extra[$field]=$value;
        }
        if(($extra['payer_total']!==null&&$extra['payer_refund']!==null&&$extra['payer_refund']>$extra['payer_total'])||
            ($extra['settlement_total']!==null&&$extra['settlement_refund']!==null&&$extra['settlement_refund']>$extra['settlement_total'])||
            (isset($request['payer_total'])&&$extra['payer_total']!==$request['payer_total']))throw new Problem('wechat_refund_mismatch');
        return ['state'=>$state,'refund_id'=>$data['refund_id'],'out_refund_no'=>$data['out_refund_no'],
            'transaction_id'=>$data['transaction_id'],'order_id'=>$request['order_id'],'amount'=>$amount['refund'],
            'total'=>$amount['total'],'currency'=>'CNY']+$extra;
    }

    public function bill(string $date,int $now): array
    {
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date))throw new Problem('wechat_bill_date_invalid');
        $zone=new DateTimeZone('Asia/Shanghai');$day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
        $today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
        if($day===false||$day->format('Y-m-d')!==$date||$date>=$today)throw new Problem('wechat_bill_date_invalid');
        $reply=$this->http->request('GET','/v3/bill/tradebill?bill_date='.$date.'&bill_type=ALL&compress_type=GZIP',null,$now);
        $data=self::successful($reply);$download=$data['download_url']??null;$url=is_string($download)?parse_url($download):false;
        if(($data['hash_type']??null)!=='SHA1'||!is_string($data['hash_value']??null)||
            !preg_match('/^[a-fA-F0-9]{40}$/D',$data['hash_value'])||!is_string($download)||strlen($download)>2048||
            !is_array($url)||($url['scheme']??null)!=='https'||($url['host']??null)!=='api.mch.weixin.qq.com'||
            ($url['path']??null)!=='/v3/bill/downloadurl'||array_diff(array_keys($url),['scheme','host','path','query'])!==[]||
            !preg_match('/^token=[A-Za-z0-9_.~%+\/=-]{1,1536}$/D',$url['query']??'')||preg_match('/%(?![a-fA-F0-9]{2})/',$url['query'])){
            throw new Problem('wechat_bill_metadata_invalid',502);
        }
        return ['date'=>$date,'hash_type'=>'SHA1','hash_value'=>strtolower($data['hash_value']),'download_url'=>$download];
    }

    private static function successful(array $reply): array
    {
        if($reply['http_status']!==200)throw new Problem('wechat_provider_unknown',502);
        return $reply['data'];
    }

    /** Internal IDs remain opaque; only the immutable provider binding chooses the wire reference. */
    private static function reference(array $value,string $idField='id'): string
    {
        $reference=array_key_exists('merchant_order_no',$value)?$value['merchant_order_no']:($value[$idField]??null);
        if(!is_string($reference)||!preg_match('/^(?:[0-9]{16}|[a-f0-9]{32})$/D',$reference))throw new Problem('wechat_order_invalid');
        return $reference;
    }

    private static function order(array $order): void
    {
        if(!is_string($order['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$order['id'])||
            ($order['payment_method']??null)!=='wechat_native'||($order['currency']??null)!=='CNY'){
            throw new Problem('wechat_order_invalid');
        }
        $amount=Money::positive($order['amount']??null);
        if($amount>self::MAX_TOTAL)throw new Problem('wechat_amount_out_of_range');
    }

    private static function refundRequest(array $request): void
    {
        if(!is_string($request['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$request['id'])||
            !is_string($request['order_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$request['order_id'])||
            !is_string($request['transaction_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$request['transaction_id'])||
            ($request['currency']??null)!=='CNY')throw new Problem('wechat_refund_invalid');
        $amount=Money::positive($request['amount']??null);$paid=Money::positive($request['paid_amount']??null);
        if($amount>$paid||$paid>self::MAX_TOTAL||(isset($request['payer_total'])&&
            (!is_int($request['payer_total'])||$request['payer_total']<0||$request['payer_total']>$paid)))throw new Problem('wechat_refund_amount_invalid');
    }
}
