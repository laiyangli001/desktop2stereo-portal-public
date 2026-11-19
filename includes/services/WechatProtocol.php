<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use OpenSSLAsymmetricKey;

/** APIv3 byte-level protocol only; verified data still requires business reconciliation. */
final readonly class WechatProtocol
{
    public const API_ORIGIN = 'https://api.mch.weixin.qq.com';
    public const MAX_BODY = 1048576;
    public const CLOCK_WINDOW = 300;

    public function __construct(private array $config)
    {
        if (!preg_match('/^[0-9]{8,15}$/D', $config['mchid'] ?? '') ||
            !preg_match('/^[A-Fa-f0-9]{1,40}$/D', $config['merchant_serial'] ?? '') ||
            !is_string($config['api_v3_key'] ?? null) || strlen($config['api_v3_key']) !== 32 ||
            !is_array($config['verification_keys'] ?? null) || $config['verification_keys'] === [] ||
            count($config['verification_keys']) > 8) {
            throw new Problem('wechat_configuration_invalid');
        }
        self::rsa($config['private_key'] ?? '', true);
        foreach ($config['verification_keys'] as $serial => $pem) {
            $serial = (string)$serial; // PHP coerces decimal-looking certificate map keys to integers.
            if (!self::serial($serial) || !is_string($pem)) {
                throw new Problem('wechat_verification_key_invalid');
            }
            self::verificationKey($serial, $pem, null);
        }
    }

    public function authorization(string $method, string $target, string $body, int $now, ?string $nonce = null): string
    {
        if (!in_array($method, ['GET', 'POST'], true) || $now < 1000000000 || $now > 9999999999 ||
            strlen($target) > 2048 || !preg_match('#^/v3/[A-Za-z0-9/_?=&.%-]+$#D', $target) ||
            str_contains($target, '..') || str_contains($target, '//') ||
            preg_match('/%(?![a-fA-F0-9]{2})/', $target) || strlen($body) > self::MAX_BODY ||
            ($method === 'GET' && $body !== '')) {
            throw new Problem('wechat_request_invalid');
        }
        if ($body !== '') { self::object($body); }
        $nonce ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $nonce)) { throw new Problem('wechat_nonce_invalid'); }
        $message = $method."\n".$target."\n".$now."\n".$nonce."\n".$body."\n";
        if (!openssl_sign($message, $signature, self::rsa($this->config['private_key'], true), OPENSSL_ALGO_SHA256)) {
            throw new Problem('wechat_signing_failed', 503);
        }
        return 'WECHATPAY2-SHA256-RSA2048 mchid="'.$this->config['mchid'].'",nonce_str="'.$nonce.
            '",signature="'.base64_encode($signature).'",timestamp="'.$now.'",serial_no="'.$this->config['merchant_serial'].'"';
    }

    /** Verify exact bytes before JSON parsing, including signed non-success responses. */
    public function response(array $headers, string $body, int $now): array
    {
        $this->verify($headers, $body, $now);
        return $body === '' ? [] : self::object($body);
    }

    public function notification(array $headers, string $body, int $now): array
    {
        $this->verify($headers, $body, $now);
        $envelope = self::object($body);
        $id = $envelope['id'] ?? null;
        $type = $envelope['event_type'] ?? null;
        $expected = match ($type) {
            'TRANSACTION.SUCCESS' => 'transaction',
            'REFUND.SUCCESS', 'REFUND.ABNORMAL', 'REFUND.CLOSED' => 'refund',
            default => throw new Problem('wechat_notification_unsupported'),
        };
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) ||
            ($envelope['resource_type'] ?? null) !== 'encrypt-resource' || !is_array($envelope['resource'] ?? null)) {
            throw new Problem('wechat_notification_invalid');
        }
        $resource = $envelope['resource'];
        $associated = array_key_exists('associated_data',$resource) ? $resource['associated_data'] : '';
        if (($resource['algorithm'] ?? null) !== 'AEAD_AES_256_GCM' ||
            ($resource['original_type'] ?? null) !== $expected ||
            !is_string($resource['nonce'] ?? null) || strlen($resource['nonce']) !== 12 ||
            !is_string($associated) || strlen($associated) > 256 ||
            !is_string($resource['ciphertext'] ?? null)) {
            throw new Problem('wechat_notification_invalid');
        }
        $cipher = self::base64($resource['ciphertext']);
        if ($cipher === null || strlen($cipher) <= 16) { throw new Problem('wechat_notification_invalid'); }
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', $this->config['api_v3_key'],
            OPENSSL_RAW_DATA, $resource['nonce'], substr($cipher, -16), $associated);
        if (!is_string($plain)) { throw new Problem('wechat_decryption_failed'); }
        return ['id' => $id, 'event_type' => $type, 'data' => self::object($plain)];
    }

    private function verify(array $headers, string $body, int $now): void
    {
        if (strlen($body) > self::MAX_BODY) { throw new Problem('wechat_response_invalid', 502); }
        $values = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) { throw new Problem('wechat_headers_invalid', 502); }
            $name = strtolower($name);
            if (!str_starts_with($name, 'wechatpay-')) { continue; }
            if (!is_string($value) || isset($values[$name]) || strlen($value) > 2048 ||
                preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new Problem('wechat_headers_invalid', 502);
            }
            $values[$name] = $value;
        }
        $timestamp = $values['wechatpay-timestamp'] ?? '';
        $nonce = $values['wechatpay-nonce'] ?? '';
        $serial = $values['wechatpay-serial'] ?? '';
        $signature = $values['wechatpay-signature'] ?? '';
        if (!preg_match('/^[1-9][0-9]{9}$/D', $timestamp) || abs($now - (int)$timestamp) > self::CLOCK_WINDOW ||
            !preg_match('/^[\x21-\x7e]{1,32}$/D', $nonce)) {
            throw new Problem('wechat_response_freshness_invalid', 502);
        }
        if (!self::serial($serial) || !isset($this->config['verification_keys'][$serial])) {
            throw new Problem('wechat_verification_key_unknown', 502);
        }
        $raw = self::base64($signature);
        if ($raw === null || str_starts_with($signature, 'WECHATPAY/SIGNTEST/') ||
            openssl_verify($timestamp."\n".$nonce."\n".$body."\n", $raw,
                self::verificationKey($serial, $this->config['verification_keys'][$serial], $now), OPENSSL_ALGO_SHA256) !== 1) {
            throw new Problem('wechat_signature_invalid', 502);
        }
    }

    private static function serial(string $serial): bool
    {
        return preg_match('/^(?:PUB_KEY_ID_[0-9]{10,64}|[A-Fa-f0-9]{1,40})$/D', $serial) === 1;
    }

    public static function verificationKey(string $serial, string $pem, ?int $now = null): OpenSSLAsymmetricKey
    {
        if (str_starts_with($serial, 'PUB_KEY_ID_')) { return self::rsa($pem, false); }
        if (strlen($pem) > 16384 || !str_starts_with(trim($pem), '-----BEGIN CERTIFICATE-----')) {
            throw new Problem('wechat_verification_key_invalid');
        }
        $certificate = @openssl_x509_read($pem);
        $details = $certificate === false ? false : openssl_x509_parse($certificate);
        if (!is_array($details) || strtoupper(ltrim($details['serialNumberHex'] ?? '', '0')) !== strtoupper(ltrim($serial, '0')) ||
            ($now !== null && ($now < $details['validFrom_time_t'] || $now > $details['validTo_time_t']))) {
            throw new Problem('wechat_verification_certificate_invalid', 502);
        }
        $key = openssl_pkey_get_public($certificate);
        return self::checkRsa($key);
    }

    public static function rsa(string $pem, bool $private): OpenSSLAsymmetricKey
    {
        if (strlen($pem) > 16384 || str_contains($pem, "\0") ||
            !str_starts_with(trim($pem), $private ? '-----BEGIN PRIVATE KEY-----' : '-----BEGIN PUBLIC KEY-----')) {
            throw new Problem('wechat_key_invalid');
        }
        return self::checkRsa($private ? @openssl_pkey_get_private($pem) : @openssl_pkey_get_public($pem));
    }

    private static function checkRsa(OpenSSLAsymmetricKey|false $key): OpenSSLAsymmetricKey
    {
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] !== 2048) {
            throw new Problem('wechat_key_invalid');
        }
        return $key;
    }

    private static function base64(string $value): ?string
    {
        $raw = base64_decode($value, true);
        return is_string($raw) && $raw !== '' && base64_encode($raw) === $value ? $raw : null;
    }

    private static function object(string $body): array
    {
        try { RawJson::fields($body); }
        catch (Problem) { throw new Problem('wechat_json_invalid', 502); }
        return json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
