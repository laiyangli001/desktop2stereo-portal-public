<?php
declare(strict_types=1);
namespace D2S\services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use D2S\domain\Problem;
use PDO;

final readonly class AlipayBills
{
    public const MAXIMUM_BYTES = 33554432;
    public const HOST = 'dwbillcenter.alipay.com';

    public function __construct(private PDO $db, private PaymentProfiles $profiles, private ?Closure $transport = null,
        private ?Closure $resolver = null, private ?Closure $fetcher = null) {}

    public function download(int $adminId, string $date, ?string $orderId, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'bills.read');
        $select = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $select->execute([$adminId]);
        $email = $select->fetchColumn();
        if (!is_string($email)) { throw new Problem('admin_auth_invalid',401); }
        self::date($date,$now);
        if ($orderId !== null && !preg_match('/^[a-f0-9]{32}$/D',$orderId)) { throw new Problem('order_id_invalid'); }
        $details = ['bill_date'=>$date,'bill_type'=>'trade','source_order'=>$orderId];
        Audit::admin($this->db,$adminId,$email,'DOWNLOAD_BILL',$date,$context,'PENDING',$details+['phase'=>'intent'],$now);
        try {
            $config = $orderId === null ? $this->profiles->current() : $this->profiles->read($orderId);
            $proof = (new AlipayClient($config,$this->transport))->call('alipay.data.dataservice.bill.downloadurl.query',[
                'bill_type'=>'trade','bill_date'=>$date,
            ],$now);
            if (($proof['code'] ?? null) !== '10000') {
                throw new Problem(in_array($proof['sub_code'] ?? '',['BILL_NOT_EXIST','NO_BILL_DATA'],true) ? 'bill_not_available':'bill_query_failed',409);
            }
            foreach (['app_id','auth_app_id','seller_id','bill_date','bill_type'] as $key) {
                $expected = match ($key) { 'seller_id'=>$config['seller_id'],'bill_date'=>$date,'bill_type'=>'trade',default=>$config['app_id'] };
                if (isset($proof[$key]) && $proof[$key] !== $expected) { throw new Problem('alipay_identity_mismatch',502); }
            }
            $url = self::url($proof['bill_download_url'] ?? null);
            // Never expose/store the signed URL, which is a short-lived bearer credential.
            $addresses = $this->resolver === null ? self::resolve() : ($this->resolver)(self::HOST);
            if (!is_array($addresses) || $addresses === [] || count($addresses)>32) { throw new Problem('bill_host_unavailable',502); }
            foreach ($addresses as $ip) {
                if (!self::publicAddress($ip)) { throw new Problem('bill_host_unavailable',502); }
            }
            $body = $this->fetcher === null ? self::fetch($url,$addresses[0]) : ($this->fetcher)($url,$addresses[0]);
            if (!is_string($body) || strlen($body)>self::MAXIMUM_BYTES || strlen($body)<22 ||
                (!str_starts_with($body,"PK\x03\x04") && !str_starts_with($body,"PK\x05\x06"))) {
                throw new Problem('bill_file_invalid',502);
            }
            Audit::admin($this->db,$adminId,$email,'DOWNLOAD_BILL',$date,$context,'SUCCESS',
                $details+['phase'=>'result','bytes'=>strlen($body),'sha256'=>hash('sha256',$body),
                    'environment'=>$config['environment'],'app_id'=>$config['app_id']],$now);
            return ['filename'=>'alipay_trade_'.$date.'.zip','body'=>$body];
        } catch (\Throwable $error) {
            Audit::admin($this->db,$adminId,$email,'DOWNLOAD_BILL',$date,$context,'FAILURE',$details+['phase'=>'result'],$now);
            throw $error;
        }
    }

    public static function date(string $date, int $now): void
    {
        $monthly = preg_match('/^[0-9]{4}-[0-9]{2}$/D',$date) === 1;
        if (!$monthly && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date)) { throw new Problem('bill_date_invalid'); }
        $format = $monthly ? 'Y-m':'Y-m-d';
        $zone = new DateTimeZone('Asia/Shanghai');
        $parsed = DateTimeImmutable::createFromFormat('!'.$format,$date,$zone);
        $today = (new DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0);
        $maximum = $monthly ? $today->modify('first day of this month') : $today;
        $minimum = $maximum->modify('-6 years');
        if ($parsed === false || $parsed->format($format) !== $date || $parsed<$minimum || $parsed >= $maximum) {
            throw new Problem('bill_date_invalid');
        }
    }

    public static function url(mixed $url): string
    {
        if (!is_string($url) || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || filter_var($url,FILTER_VALIDATE_URL) === false) {
            throw new Problem('bill_url_invalid',502);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '',['http','https'],true) ||
            ($parts['host'] ?? '') !== self::HOST || ($parts['path'] ?? '') !== '/downloadBillFile.resource' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            (isset($parts['port']) && $parts['port'] !== ($parts['scheme'] === 'https' ? 443 : 80))) {
            throw new Problem('bill_url_invalid',502);
        }
        // Only the documented host/path may be upgraded. Never downgrade if its TLS is unavailable.
        return 'https://'.self::HOST.'/downloadBillFile.resource'.(isset($parts['query']) ? '?'.$parts['query']:'');
    }

    public static function publicAddress(mixed $ip): bool
    {
        if (!is_string($ip) || str_contains($ip,'%') || str_starts_with(strtolower($ip),'::ffff:') ||
            filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE) === false) { return false; }
        $packed = inet_pton($ip);
        return $packed !== false && (strlen($packed) === 4 ? ord($packed[0])<224 : ord($packed[0])!==255);
    }

    private static function resolve(): array
    {
        $records = dns_get_record(self::HOST,DNS_A | DNS_AAAA);
        if (!is_array($records)) { throw new Problem('bill_host_unavailable',502); }
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) { $addresses[] = $ip; }
        }
        return array_values(array_unique($addresses));
    }

    private static function fetch(string $url, string $ip): string
    {
        $body = '';
        $pin = str_contains($ip,':') ? '['.$ip.']' : $ip;
        $curl = curl_init($url);
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
            CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[self::HOST.':443:'.$pin],
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>self::MAXIMUM_BYTES) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) { throw new Problem('bill_download_failed',502); }
        return $body;
    }
}
