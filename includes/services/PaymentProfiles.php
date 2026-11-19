<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final readonly class PaymentProfiles
{
    public function __construct(private PDO $db, private SecretBox $secrets, private ?string $configurationId=null) {}

    public function available(): bool
    {
        $config = $this->current();
        if ($config['enabled'] !== 'true') { return false; }
        AlipaySettings::validate($config,true);
        return true;
    }

    public function capture(string $orderId, int $now): void
    {
        $choice=(new PaymentConfigurations($this->db,$this->secrets))->resolve('alipay_pc','CNY',$this->configurationId);
        $config=$choice['configuration']??(new PaymentConfigurations($this->db,$this->secrets))->currentNative('alipay_pc');
        if($choice!==null)$this->db->prepare('UPDATE portal_orders SET payment_configuration_id=? WHERE id=?')->execute([$choice['id'],$orderId]);
        if ($config['enabled'] !== 'true') { throw new Problem('payment_method_unavailable',409); }
        AlipaySettings::validate($config,true);
        $cipher = $this->secrets->encrypt(json_encode($config,JSON_THROW_ON_ERROR),'payment-attempt:'.$orderId);
        $this->db->prepare('INSERT INTO portal_payment_attempts (id,provider,environment,app_id,seller_id,config_cipher,created_at)
            VALUES (?,?,?,?,?,?,?)')->execute([$orderId,'alipay_pc',$config['environment'],$config['app_id'],$config['seller_id'],$cipher,$now]);
    }

    public function read(string $orderId): array
    {
        $select = $this->db->prepare("SELECT config_cipher FROM portal_payment_attempts WHERE id=? AND provider='alipay_pc'");
        $select->execute([$orderId]);
        $cipher = $select->fetchColumn();
        if (!is_string($cipher)) { throw new Problem('payment_attempt_not_found',404); }
        return json_decode($this->secrets->decrypt($cipher,'payment-attempt:'.$orderId),true,16,JSON_THROW_ON_ERROR);
    }

    public function current(): array { return (new PaymentConfigurations($this->db,$this->secrets))->currentNative('alipay_pc',$this->configurationId); }

    public function payerHash(string $environment, ?string $buyer): ?string
    {
        // Only a keyed identifier is retained; never store the raw buyer ID or payment email.
        return $buyer === null || $buyer === '' ? null : $this->secrets->digest('alipay-buyer:'.$environment.':'.$buyer);
    }
}
