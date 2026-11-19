<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use OpenSSLAsymmetricKey;

/** RSA2 uses OpenSSL SHA-256/PKCS#1 v1.5; no custom cryptographic primitive. */
final class AlipayRSA
{
    public static function key(string $input, bool $private): OpenSSLAsymmetricKey
    {
        if (strlen($input) > 16384 || str_contains($input,"\0")) { throw new Problem('alipay_key_invalid'); }
        $normalized = preg_replace('/-----BEGIN (?:RSA )?(?:PRIVATE|PUBLIC) KEY-----|-----END (?:RSA )?(?:PRIVATE|PUBLIC) KEY-----|\s+/', '', $input);
        $raw = is_string($normalized) ? base64_decode($normalized,true) : false;
        if (!is_string($raw) || $raw === '' || base64_encode($raw) !== $normalized) { throw new Problem('alipay_key_invalid'); }
        $key = false;
        foreach ($private ? ['PRIVATE KEY','RSA PRIVATE KEY'] : ['PUBLIC KEY','RSA PUBLIC KEY'] as $label) {
            $pem = "-----BEGIN $label-----\n".chunk_split(base64_encode($raw),64,"\n")."-----END $label-----\n";
            $key = $private ? @openssl_pkey_get_private($pem) : @openssl_pkey_get_public($pem);
            if ($key !== false) { break; }
        }
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 4096) {
            throw new Problem('alipay_key_invalid');
        }
        return $key;
    }

    public static function canonical(array $parameters, bool $notification): string
    {
        unset($parameters['sign']);
        if ($notification) { unset($parameters['sign_type']); }
        ksort($parameters,SORT_STRING);
        $pairs = [];
        foreach ($parameters as $name=>$value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/D',$name) || !is_string($value) ||
                !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) {
                throw new Problem('alipay_parameters_invalid');
            }
            if ($value !== '') { $pairs[] = $name.'='.$value; }
        }
        return implode('&',$pairs);
    }

    public static function sign(string $data, OpenSSLAsymmetricKey $key): string
    {
        if (!openssl_sign($data,$signature,$key,OPENSSL_ALGO_SHA256)) { throw new Problem('alipay_signing_failed',503); }
        return base64_encode($signature);
    }

    public static function verify(string $data, string $signature, OpenSSLAsymmetricKey $key): bool
    {
        $raw = base64_decode($signature,true);
        return is_string($raw) && base64_encode($raw) === $signature &&
            openssl_verify($data,$raw,$key,OPENSSL_ALGO_SHA256) === 1;
    }

    public static function form(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 65536 || preg_match('/%(?![a-fA-F0-9]{2})/',$raw)) {
            throw new Problem('alipay_notification_invalid');
        }
        $fields = explode('&',$raw);
        if (count($fields) > 128) { throw new Problem('alipay_notification_invalid'); }
        $parameters = [];
        foreach ($fields as $field) {
            $pair = explode('=',$field,2);
            if (count($pair) !== 2) { throw new Problem('alipay_notification_invalid'); }
            $name = urldecode($pair[0]);
            $value = urldecode($pair[1]);
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name) || array_key_exists($name,$parameters) ||
                !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) {
                throw new Problem('alipay_notification_invalid');
            }
            $parameters[$name] = $value;
        }
        return $parameters;
    }
}
