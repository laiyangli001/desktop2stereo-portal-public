<?php
declare(strict_types=1);

namespace D2S\services;

use Closure;
use D2S\domain\Problem;

/** Fixed-origin, signed APIv3 JSON transport; no payment or refund state changes here. */
final readonly class WechatHttp
{
    private WechatProtocol $protocol;

    public function __construct(array $config, private ?Closure $transport = null)
    {
        $this->protocol = new WechatProtocol($config);
    }

    public function request(string $method, string $target, ?array $data, int $now): array
    {
        if (!self::allowed($method, $target) || ($method === 'GET' && $data !== null) ||
            ($method === 'POST' && ($data === null || array_is_list($data)))) {
            throw new Problem('wechat_endpoint_invalid');
        }
        $body = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Authorization: '.$this->protocol->authorization($method, $target, $body, $now),
            'Accept: application/json', 'Content-Type: application/json', 'User-Agent: Desktop2Stereo-Portal/2'];
        $packet = ['method' => $method, 'url' => WechatProtocol::API_ORIGIN.$target, 'headers' => $headers, 'body' => $body];
        $response = $this->transport === null ? self::send($packet) : ($this->transport)($packet);
        if (!is_array($response) || !is_int($response['status'] ?? null) || $response['status'] < 200 ||
            $response['status'] > 599 || !is_array($response['headers'] ?? null) || !is_string($response['body'] ?? null)) {
            throw new Problem('wechat_transport_unknown', 502);
        }
        // Provider failures are also untrusted until their exact bytes verify.
        $decoded = $this->protocol->response($response['headers'], $response['body'], $now);
        if ($response['status'] >= 300 && $response['status'] < 400) { throw new Problem('wechat_transport_unknown', 502); }
        return ['http_status' => $response['status'], 'data' => $decoded, 'body_empty' => $response['body'] === ''];
    }

    private static function allowed(string $method, string $target): bool
    {
        $id = '[A-Za-z0-9_-]{1,64}';
        if ($method === 'POST') {
            return in_array($target, ['/v3/pay/transactions/native', '/v3/refund/domestic/refunds'], true) ||
                preg_match('#^/v3/pay/transactions/out-trade-no/'.$id.'/close$#D', $target) === 1;
        }
        if ($method !== 'GET') { return false; }
        return preg_match('#^/v3/pay/transactions/(?:out-trade-no|id)/'.$id.'\\?mchid=[0-9]{8,15}$#D', $target) === 1 ||
            preg_match('#^/v3/refund/domestic/refunds/'.$id.'$#D', $target) === 1 ||
            preg_match('#^/v3/bill/tradebill\\?bill_date=[0-9]{4}-[0-9]{2}-[0-9]{2}&bill_type=ALL&compress_type=GZIP$#D', $target) === 1;
    }

    private static function send(array $packet): array
    {
        if (!extension_loaded('curl')) { throw new Problem('wechat_transport_unavailable', 503); }
        $curl = curl_init($packet['url']);
        if ($curl === false) { throw new Problem('wechat_transport_unavailable', 503); }
        $raw = '';
        $headers = [];
        $headerBytes = 0;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $packet['method'], CURLOPT_HTTPHEADER => $packet['headers'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 16384) { return 0; }
                if (str_starts_with($line, 'HTTP/')) { $headers = []; return strlen($line); }
                $pair = explode(':', $line, 2);
                if (count($pair) === 2 && str_starts_with(strtolower($pair[0]), 'wechatpay-')) {
                    $name = strtolower($pair[0]);
                    $value = trim($pair[1], " \t\r\n");
                    // Retain duplicates as invalid data instead of silently selecting a signature.
                    $headers[$name] = isset($headers[$name]) ? [$headers[$name], $value] : $value;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > WechatProtocol::MAX_BODY) { return 0; }
                $raw .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($packet['method'] === 'POST') { curl_setopt($curl, CURLOPT_POSTFIELDS, $packet['body']); }
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false) { throw new Problem('wechat_transport_unknown', 502); }
        return ['status' => $status, 'headers' => $headers, 'body' => $raw];
    }
}
