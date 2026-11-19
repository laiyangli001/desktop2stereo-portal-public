<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\LicensePolicy;
use D2S\domain\Problem;

final readonly class LicenseCredentials
{
    public function __construct(private LicenseSigner $signer, private CoreKeyWrapper $core, private string $product = 'desktop2stereo')
    {
    }

    public function registerSigningKey(\PDO $db, int $now): void
    {
        (new SigningKeys($db,$this->signer))->assertSigning($now);
    }

    /** Caller must lock and validate the current database entitlement and session first. */
    public function issue(array $license, string $deviceHash, string $devicePublicKey, int $now, int $sessionVersion): array
    {
        if (!in_array($license['kind'] ?? '',['trial','formal'],true) ||
            ($license['status'] ?? '') !== 'active' || $now <= 0 || $sessionVersion < 1 ||
            !preg_match('/^[a-f0-9]{64}$/D',$deviceHash)) {
            throw new Problem('license_unavailable',403);
        }
        $product = $license['product'] ?? 'desktop2stereo';
        if ($product !== $this->product) { throw new Problem('license_product_mismatch',403); }
        $expires = isset($license['expires_at']) ? (int)$license['expires_at'] : null;
        if ($license['kind'] === 'trial' && $expires === null) {
            throw new Problem('license_unavailable',403);
        }
        if ($expires !== null && $expires <= $now) {
            throw new Problem('license_expired',403);
        }
        $deadline = (new LicensePolicy())->offlineExpiresAt($now,$expires);
        $base = ['version'=>1,'license_id'=>$license['id'],'product'=>$product,
            'device_hash'=>$deviceHash,'issued_at'=>$now,'not_before'=>$now-60,'expires_at'=>$deadline,
            'session_version'=>$sessionVersion];
        $entitlementId = bin2hex(random_bytes(16));
        $offline = $base + ['entitlement_id'=>$entitlementId,'mode'=>'online','features'=>['runtime'],
            'trial'=>$license['kind'] === 'trial','offline_period_days'=>1] +
            $this->core->wrap($devicePublicKey,$entitlementId,$license['id'],$deviceHash);
        $grantId = bin2hex(random_bytes(16));
        $grant = $base + ['grant_id'=>$grantId] + $this->core->wrap($devicePublicKey,$grantId,$license['id'],$deviceHash);
        return ['offline_token'=>$this->signer->sign($offline),'core_grant'=>$this->signer->sign($grant),
            'offline_id'=>$entitlementId,'offline_expires_at'=>$deadline,
            'key_id'=>$this->signer->keyId,'heartbeat_seconds'=>900,'heartbeat_jitter_percent'=>10];
    }
}
