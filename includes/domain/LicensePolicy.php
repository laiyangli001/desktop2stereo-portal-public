<?php
declare(strict_types=1);

namespace D2S\domain;

use InvalidArgumentException;

/** Shared server policy; transport failures never extend cached entitlements. */
final readonly class LicensePolicy
{
    public function __construct(
        public int $heartbeatSeconds = 900,
        public int $jitterPercent = 10,
        public int $offlineSeconds = 86400,
        public int $trialDays = 30,
    ) {
        if ($heartbeatSeconds !== 900 || $jitterPercent !== 10 ||
            $offlineSeconds !== 86400 || $trialDays !== 30) {
            throw new InvalidArgumentException('unsupported_license_policy');
        }
    }

    public function nextHeartbeatSeconds(int $jitter): int
    {
        $bound = intdiv($this->heartbeatSeconds * $this->jitterPercent, 100);
        if ($jitter < -$bound || $jitter > $bound) {
            throw new InvalidArgumentException('heartbeat_jitter_out_of_range');
        }
        return $this->heartbeatSeconds + $jitter;
    }

    public function offlineExpiresAt(int $verifiedAt, ?int $entitlementExpiresAt): int
    {
        if ($verifiedAt <= 0 || $verifiedAt > PHP_INT_MAX - $this->offlineSeconds ||
            ($entitlementExpiresAt !== null && $entitlementExpiresAt <= $verifiedAt)) {
            throw new InvalidArgumentException('entitlement_unavailable');
        }
        $deadline = $verifiedAt + $this->offlineSeconds;
        return $entitlementExpiresAt === null ? $deadline : min($deadline, $entitlementExpiresAt);
    }
}
