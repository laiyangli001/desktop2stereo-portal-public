<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use RuntimeException;
use SodiumException;

final readonly class CoreKeyWrapper
{
    public function __construct(private string $coreKey, public string $resourceHash)
    {
        if (strlen($coreKey) !== 32 || !preg_match('/^[a-f0-9]{64}$/D',$resourceHash)) {
            throw new RuntimeException('parallax_core_configuration_invalid');
        }
    }

    public static function fromEnvironment(string $product = 'desktop2stereo'): self
    {
        ProductCatalog::code($product);
        // Hex encoding preserves distinct product codes without environment-name collisions.
        $prefix = $product === 'desktop2stereo' ? 'D2S_' : 'D2S_PRODUCT_'.strtoupper(bin2hex($product)).'_';
        $hex = getenv($prefix.'PARALLAX_CORE_KEY_HEX') ?: '';
        if (!preg_match('/^[a-fA-F0-9]{64}$/D',$hex)) {
            throw new RuntimeException('parallax_core_configuration_invalid');
        }
        return new self(hex2bin($hex),strtolower(getenv($prefix.'PARALLAX_CORE_SHA256') ?: ''));
    }

    public static function deviceKey(string $encoded): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$encoded)) {
            throw new Problem('device_public_key_invalid');
        }
        $bytes = base64_decode(strtr($encoded,'-_','+/') . '=',true);
        if (!is_string($bytes) || strlen($bytes) !== 32 || LicenseSigner::encode($bytes) !== $encoded) {
            throw new Problem('device_public_key_invalid');
        }
        return $bytes;
    }

    public function wrap(string $deviceKey, string $grantId, string $licenseId, string $deviceHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$deviceHash) ||
            !preg_match('/^[A-Za-z0-9-]{1,64}$/D',$grantId) ||
            !preg_match('/^[A-Za-z0-9-]{1,64}$/D',$licenseId)) {
            throw new Problem('device_or_grant_invalid');
        }
        $public = self::deviceKey($deviceKey);
        $private = random_bytes(32);
        try {
            $ephemeral = sodium_crypto_scalarmult_base($private);
            try {
                $shared = sodium_crypto_scalarmult($private,$public);
            } catch (SodiumException) {
                throw new Problem('device_public_key_invalid');
            }
            $key = hash_hkdf('sha256',$shared,32,'d2s-parallax-core-v1-key-wrap','');
            $nonce = random_bytes(12);
            $aad = implode('|',[$grantId,$licenseId,$deviceHash,'parallax-core','1',$this->resourceHash]);
            $cipher = openssl_encrypt($this->coreKey,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
            if ($cipher === false) {
                throw new RuntimeException('core_key_wrap_failed');
            }
            return ['core_id'=>'parallax-core','core_version'=>1,'resource_sha256'=>$this->resourceHash,
                'key_wrap_ephemeral_public_key'=>LicenseSigner::encode($ephemeral),
                'key_wrap_nonce'=>LicenseSigner::encode($nonce),
                'wrapped_core_key'=>LicenseSigner::encode($cipher . $tag)];
        } finally {
            sodium_memzero($private);
            if (isset($shared)) {
                sodium_memzero($shared);
            }
            if (isset($key)) {
                sodium_memzero($key);
            }
        }
    }
}
