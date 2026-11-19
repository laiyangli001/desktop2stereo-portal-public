<?php
declare(strict_types=1);

namespace D2S\services;

use RuntimeException;

final readonly class SecretBox
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $decoded = base64_decode($encodedKey, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('master_key_required');
        }
        $this->key = $decoded;
    }

    public static function fromEnvironment(): self
    {
        return new self(getenv('D2S_MASTER_KEY') ?: '');
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key));
    }

    public function decrypt(string $encoded, string $context): string
    {
        $raw = base64_decode($encoded, true);
        $length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($raw) || strlen($raw) <= $length) {
            throw new RuntimeException('encrypted_value_invalid');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $length), $context, substr($raw, 0, $length), $this->key,
        );
        if ($plaintext === false) {
            throw new RuntimeException('encrypted_value_invalid');
        }
        return $plaintext;
    }

    public function digest(string $input): string
    {
        return hash_hmac('sha256', $input, $this->key);
    }
}
