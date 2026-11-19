<?php
declare(strict_types=1);

namespace D2S\services;

use OpenSSLAsymmetricKey;
use RuntimeException;

final readonly class LicenseSigner
{
    private OpenSSLAsymmetricKey $key;

    public function __construct(string $pem, public string $keyId)
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $keyId)) {
            throw new RuntimeException('license_key_id_invalid');
        }
        $key = @openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || $details['type'] !== OPENSSL_KEYTYPE_EC ||
            !in_array($details['ec']['curve_name'] ?? '', ['prime256v1','secp256r1'], true)) {
            throw new RuntimeException('license_signing_key_invalid');
        }
        $this->key = $key;
    }

    public static function fromEnvironment(): self
    {
        $pem = getenv('D2S_LICENSE_PRIVATE_KEY_PEM') ?: '';
        if ($pem === '' && getenv('D2S_LICENSE_PRIVATE_KEY_B64')) {
            $decoded = base64_decode(getenv('D2S_LICENSE_PRIVATE_KEY_B64'), true);
            if (!is_string($decoded)) {
                throw new RuntimeException('license_signing_key_invalid');
            }
            $pem = $decoded;
        }
        return new self($pem, getenv('D2S_LICENSE_KEY_ID') ?: '');
    }

    public function jwk(): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        return ['kty'=>'EC','crv'=>'P-256','x'=>self::encode(str_pad($ec['x'],32,"\0",STR_PAD_LEFT)),
            'y'=>self::encode(str_pad($ec['y'],32,"\0",STR_PAD_LEFT)),
            'use'=>'sig','alg'=>'ES256','kid'=>$this->keyId];
    }

    public function sign(array $claims): string
    {
        $claims['key_id'] = $this->keyId;
        $header = self::encode(json_encode(['alg'=>'ES256','kid'=>$this->keyId,'typ'=>'JWT'],JSON_THROW_ON_ERROR));
        $payload = self::encode(json_encode($claims,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $payload;
        if (!openssl_sign($input,$der,$this->key,OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('license_signing_failed');
        }
        // P-256 ECDSA DER sequence is at most 72 bytes, with short-form lengths.
        if (strlen($der) < 8 || ord($der[0]) !== 0x30 || ord($der[1]) !== strlen($der)-2) {
            throw new RuntimeException('license_signature_invalid');
        }
        $position = 2;
        $raw = '';
        for ($i=0; $i<2; $i++) {
            if ($position+2 > strlen($der) || ord($der[$position]) !== 0x02) {
                throw new RuntimeException('license_signature_invalid');
            }
            $length = ord($der[$position+1]);
            $position += 2;
            if ($length < 1 || $length > 33 || $position+$length > strlen($der)) {
                throw new RuntimeException('license_signature_invalid');
            }
            $integer = ltrim(substr($der,$position,$length),"\0");
            if (strlen($integer) > 32) {
                throw new RuntimeException('license_signature_invalid');
            }
            $raw .= str_pad($integer,32,"\0",STR_PAD_LEFT);
            $position += $length;
        }
        if ($position !== strlen($der)) {
            throw new RuntimeException('license_signature_invalid');
        }
        return $input . '.' . self::encode($raw);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
}
