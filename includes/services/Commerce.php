<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Money;
use D2S\domain\HistoryCursor;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

/** Per-software purchases; all state transitions lock user before entitlement/order/wallet. */
final readonly class Commerce
{
    public function __construct(private PDO $db, private ?PaymentProfiles $profiles = null, private ?WechatProfiles $wechat = null, private ?string $paymentConfigurationId = null) {}

    public function catalog(?int $userId = null, string $product = 'desktop2stereo'): array
    {
        ProductCatalog::code($product);
        $query = $this->db->prepare('SELECT purchases_enabled,image_path FROM portal_products WHERE code=? AND enabled=1 AND deleted_at IS NULL');
        $query->execute([$product]);
        $item = $query->fetch();
        if ($item === false || !ProductCatalog::hasPublishedContent($this->db,$product)) {
            throw new Problem('product_not_found',404);
        }
        $owned = false;
        if ($userId !== null) {
            $select = $this->db->prepare("SELECT kind FROM portal_licenses WHERE user_id=? AND product=?");
            $select->execute([$userId,$product]);
            $owned = $select->fetchColumn() === 'formal';
        }
        $select = $this->db->prepare('SELECT currency,amount,revision FROM portal_product_prices WHERE product_code=? AND enabled=1 ORDER BY currency');
        $select->execute([$product]);
        $prices = $select->fetchAll();
        $methods = ['wallet'];
        try { if ($this->wechatProfiles()->available()) { array_unshift($methods,'wechat_native'); } }
        catch (\Throwable) { /* A broken configuration must not expose a payment channel. */ }
        try { if ($this->profiles()->available()) { array_unshift($methods,'alipay_pc'); } }
        catch (\Throwable) { /* Unavailable merchant configuration keeps this channel closed. */ }
        $choices=[];
        try{
            $choices=(new PaymentConfigurations($this->db,SecretBox::fromEnvironment()))->available();
            foreach($choices as$choice)if(in_array($choice['provider'],['paypal','stripe'],true)&&!in_array($choice['provider'],$methods,true))$methods[]=$choice['provider'];
        }catch(\Throwable){}
        $presentation=(new ProductTranslations($this->db))->publicContent(
            $product, function_exists('d2s_locale_code') ? d2s_locale_code() : 'zh-Hans'
        );
        return ['product'=>$product,'image_path'=>$item['image_path'],'purchases_enabled'=>(bool)$item['purchases_enabled'],'prices'=>$prices,
            'payment_methods'=>$methods,'payment_configurations'=>$choices,'presentation'=>$presentation,'formal_owned'=>$owned];
    }

    public function storefront(string $locale): array
    {
        return ['items'=>(new ProductCatalog($this->db))->publicList($locale)];
    }


    public function savePrice(int $adminId, string $currency, mixed $amount, bool $enabled, array $context, int $now): void
    {
        Money::currency($currency);
        Money::positive($amount);
        Database::transaction($this->db, function (PDO $db) use ($adminId,$currency,$amount,$enabled,$context,$now): void {
            Permissions::require($db,$adminId,'products.manage');
            $db->query("SELECT code FROM portal_products WHERE code='desktop2stereo' FOR UPDATE")->fetchColumn();
            $db->prepare('INSERT INTO portal_product_prices (product_code,currency,amount,revision,enabled,updated_at)
                VALUES (\'desktop2stereo\',?,?,1,?,?) ON DUPLICATE KEY UPDATE amount=?,revision=revision+1,enabled=?,updated_at=?')
                ->execute([$currency,$amount,(int)$enabled,$now,$amount,(int)$enabled,$now]);
            $db->exec("INSERT INTO portal_catalog_prices(currency,amount,revision,enabled,updated_at)
                SELECT currency,amount,revision,enabled,updated_at FROM portal_product_prices WHERE product_code='desktop2stereo'
                ON DUPLICATE KEY UPDATE amount=VALUES(amount),revision=VALUES(revision),enabled=VALUES(enabled),updated_at=VALUES(updated_at)");
            $this->adminEvent($adminId,'MODIFY_PRODUCT_PRICE',$currency,$context,
                ['amount'=>$amount,'currency'=>$currency,'enabled'=>$enabled],$now);
        });
    }

    public function setPurchases(int $adminId, bool $enabled, array $context, int $now, string $product = 'desktop2stereo'): void
    {
        ProductCatalog::code($product);
        Database::transaction($this->db, function (PDO $db) use ($adminId,$enabled,$context,$now,$product): void {
            Permissions::require($db,$adminId,'products.manage');
            $select = $db->prepare('SELECT code FROM portal_products WHERE code=? AND deleted_at IS NULL FOR UPDATE');
            $select->execute([$product]);
            if ($select->fetchColumn() === false) { throw new Problem('product_not_found',404); }
            $db->prepare('UPDATE portal_products SET purchases_enabled=?,lock_version=lock_version+1,updated_at=? WHERE code=?')
                ->execute([(int)$enabled,$now,$product]);
            $this->adminEvent($adminId,'TOGGLE_PURCHASES',$product,$context,['enabled'=>$enabled],$now);
        });
    }

    public function quote(int $userId, string $currency, array $context, int $now, string $product = 'desktop2stereo'): array
    {
        Money::currency($currency);
        return Database::transaction($this->db, function (PDO $db) use ($userId,$currency,$now,$product): array {
            $this->account($userId);
            $this->purchasable($userId,$product);
            $price = $this->price($currency,$product);
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO portal_quotes
                (id,user_id,product,currency,amount,price_revision,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$userId,$product,$currency,$price['amount'],$price['revision'],$now,$now+900]);
            return ['id'=>$id,'product'=>$product,'currency'=>$currency,'amount'=>(int)$price['amount'],
                'price_revision'=>(int)$price['revision'],'expires_at'=>$now+900];
        });
    }

    public function createOrder(int $userId, string $quoteId, string $method, string $key, array $context, int $now, string $locale='zh-Hans'): array
    {
        self::id($quoteId);
        $registry=new LocaleRegistry();
        $locale=$registry->normalizeForAdmin($locale)??$registry->defaultLocale();
        if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key)) { throw new Problem('idempotency_key_invalid'); }
        if (!in_array($method,['wallet','alipay_pc','wechat_native','paypal','stripe'],true)) { throw new Problem('payment_method_unavailable',409); }
        return Database::transaction($this->db, function (PDO $db) use ($userId,$quoteId,$method,$key,$context,$now,$locale): array {
            $this->account($userId);
            $select = $db->prepare('SELECT * FROM portal_orders WHERE user_id=? AND idempotency_key=? FOR UPDATE');
            $select->execute([$userId,$key]);
            $old = $select->fetch();
            if ($old !== false) {
                if ($old['quote_id'] !== $quoteId || $old['payment_method'] !== $method ||
                    ($this->paymentConfigurationId!==null&&$old['payment_configuration_id']!==$this->paymentConfigurationId)) {
                    throw new Problem('idempotency_conflict',409);
                }
                return $this->view($old);
            }
            $select = $db->prepare('SELECT * FROM portal_quotes WHERE id=? AND user_id=?');
            $select->execute([$quoteId,$userId]);
            $quote = $select->fetch();
            if ($quote === false) { throw new Problem('quote_not_found',404); }
            $this->purchasable($userId,$quote['product']);
            if (in_array($method,['alipay_pc','wechat_native'],true) && ($quote['currency'] !== 'CNY' || (int)$quote['amount']>10000000000)) {
                throw new Problem('payment_method_unavailable',409);
            }
            $price = $this->price($quote['currency'],$quote['product']);
            if ((int)$quote['expires_at'] <= $now || (int)$quote['created_at'] > $now) {
                throw new Problem('quote_expired',409);
            }
            if ((int)$quote['amount'] !== (int)$price['amount'] || (int)$quote['price_revision'] !== (int)$price['revision']) {
                throw new Problem('quote_changed',409);
            }
            $select = $db->prepare('SELECT id FROM portal_orders WHERE pending_user_id=? AND product=? FOR UPDATE');
            $select->execute([$userId,$quote['product']]);
            if ($select->fetchColumn() !== false) { throw new Problem('pending_order_exists',409); }
            $select = $db->prepare('SELECT id FROM portal_orders WHERE quote_id=?');
            $select->execute([$quoteId]);
            if ($select->fetchColumn() !== false) { throw new Problem('quote_already_used',409); }
            $id = bin2hex(random_bytes(16));
            $orderNumber=(new OrderNumbers(Database::independent($db)))->allocate($id,$now);
            // Wallet has no external payer identifier; keep this field NULL rather than inventing one.
            $db->prepare('INSERT INTO portal_orders
                (id,user_id,idempotency_key,quote_id,product,currency,amount,payment_method,email,source_ip,
                status,pending_user_id,created_at,expires_at,updated_at,order_no,locale_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?)')
                ->execute([$id,$userId,$key,$quoteId,$quote['product'],$quote['currency'],$quote['amount'],$method,
                    Audit::email($db,$userId),$context['source_ip'] ?? null,'pending',$userId,$now,$now+($method==='stripe'?3600:900),$now,$orderNumber,$locale]);
            if ($method === 'alipay_pc') {
                OrderNumbers::bindMerchant($db,$id,$method,$orderNumber,$now);
                $this->profiles()->capture($id,$now);
            }
            if ($method === 'wechat_native') {
                OrderNumbers::bindMerchant($db,$id,$method,$orderNumber,$now);
                $this->wechatProfiles()->capture($id,$now);
            }
            if(in_array($method,['paypal','stripe'],true))(new ExternalPayments($db,SecretBox::fromEnvironment()))->captureConfiguration($id,$method,$quote['currency'],$this->paymentConfigurationId,$now);
            return $this->order($userId,$id);
        });
    }

    public function checkout(int $userId, string $id, int $now): array
    {
        self::id($id);
        return Database::transaction($this->db,function () use ($userId,$id,$now): array {
            $this->account($userId);
            $order = $this->lockedOrder($userId,$id);
            if ($order['product'] !== 'wallet_recharge') {
                $this->purchasable($userId,$order['product']);
            } elseif ($order['product'] !== 'wallet_recharge' || !WalletRecharges::enabled($this->db)) {
                throw new Problem('payment_method_unavailable',409);
            }
            if ($order['payment_method'] !== 'alipay_pc' || !$this->profiles()->available()) {
                throw new Problem('payment_method_unavailable',409);
            }
            $order['amount'] = (int)$order['amount'];
            $order['expires_at'] = (int)$order['expires_at'];
            $order['merchant_order_no']=OrderNumbers::merchant($this->db,$id,'alipay_pc');
            return (new AlipayClient($this->profiles()->read($id)))->pagePay($order,$now);
        });
    }

    public function payWallet(int $userId, string $orderId, array $context, int $now): array
    {
        self::id($orderId);
        return Database::transaction($this->db, function (PDO $db) use ($userId,$orderId,$context,$now): array {
            $this->account($userId);
            $order = $this->lockedOrder($userId,$orderId);
            if ($order['payment_method'] !== 'wallet') { throw new Problem('wallet_mixing_forbidden',409); }
            if ($order['status'] === 'paid') { return $this->view($order); }
            $this->purchasable($userId,$order['product']);
            if ($order['status'] !== 'pending') { throw new Problem('order_not_payable',409); }
            if ((int)$order['expires_at'] <= $now || (int)$order['created_at'] > $now) {
                throw new Problem('order_expired',409);
            }
            $db->prepare('INSERT IGNORE INTO portal_wallets (user_id,currency,balance,updated_at) VALUES (?,?,0,?)')
                ->execute([$userId,$order['currency'],$now]);
            $select = $db->prepare('SELECT balance FROM portal_wallets WHERE user_id=? AND currency=? FOR UPDATE');
            $select->execute([$userId,$order['currency']]);
            $balance = (int)$select->fetchColumn();
            $amount = (int)$order['amount'];
            if ($balance < $amount) { throw new Problem('wallet_insufficient',409); }
            $db->prepare('UPDATE portal_wallets SET balance=?,updated_at=? WHERE user_id=? AND currency=?')
                ->execute([$balance-$amount,$now,$userId,$order['currency']]);
            $db->prepare('INSERT INTO portal_wallet_entries
                (user_id,currency,amount,balance_after,reference,order_id,event_type,created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$userId,$order['currency'],-$amount,$balance-$amount,'purchase:'.$orderId,$orderId,'PURCHASE',$now]);
            if (!OrderFulfillment::grant($db,$order,$now)) { throw new Problem('license_unavailable',409); }
            $db->prepare("UPDATE portal_orders SET status='paid',paid_at=?,pending_user_id=NULL,updated_at=? WHERE id=?")
                ->execute([$now,$now,$orderId]);
            Audit::user($db,$userId,Audit::email($db,$userId),'PURCHASE','SUCCESS',$context,$now);
            return $this->order($userId,$orderId);
        });
    }

    public function cancel(int $userId, string $orderId, array $context, int $now): array
    {
        self::id($orderId);
        return Database::transaction($this->db, function (PDO $db) use ($userId,$orderId,$context,$now): array {
            $this->account($userId);
            $order = $this->lockedOrder($userId,$orderId);
            if ($order['status'] === 'cancelled') { return $this->view($order); }
            if ($order['status'] !== 'pending') { throw new Problem('order_not_cancellable',409); }
            // Only local wallet orders can be cancelled locally; future external orders must query/close at provider.
            if ($order['payment_method'] !== 'wallet') { throw new Problem('external_close_required',409); }
            $db->prepare("UPDATE portal_orders SET status='cancelled',pending_user_id=NULL,updated_at=? WHERE id=?")
                ->execute([$now,$orderId]);
            Audit::user($db,$userId,Audit::email($db,$userId),'PURCHASE','BLOCKED',$context,$now);
            return $this->order($userId,$orderId);
        });
    }

    public function order(int $userId, string $id): array
    {
        self::id($id);
        $select = $this->db->prepare('SELECT * FROM portal_orders WHERE id=? AND user_id=?');
        $select->execute([$id,$userId]);
        $row = $select->fetch();
        if ($row === false) { throw new Problem('order_not_found',404); }
        return $this->view($row);
    }

    public function orders(int $userId, int $before = PHP_INT_MAX): array
    {
        return $this->orderHistory($userId,null,$before)['orders'];
    }

    public function orderHistory(int $userId, ?string $cursor = null, int $before = PHP_INT_MAX, ?string $product = null): array
    {
        if ($product !== null) { ProductCatalog::code($product); }
        $position=HistoryCursor::decode($cursor,'orders',$userId);
        $parameters=[$userId,$before];
        $filter='';
        if ($position!==null) {
            $filter=' AND (created_at<? OR (created_at=? AND id<?))';
            array_push($parameters,$position['time'],$position['time'],$position['id']);
        }
        if ($product !== null) { $filter.=' AND product=?'; $parameters[]=$product; }
        $select=$this->db->prepare("SELECT * FROM portal_orders WHERE user_id=? AND product<>'wallet_recharge' AND created_at<=?".$filter.' ORDER BY created_at DESC,id DESC LIMIT 51');
        $select->execute($parameters);$rows=$select->fetchAll();
        $more=count($rows)>50;
        if ($more) array_pop($rows);
        $last=$rows===[]?null:$rows[array_key_last($rows)];
        $select=$this->db->prepare("SELECT * FROM portal_orders WHERE pending_user_id=? AND user_id=? AND product<>'wallet_recharge'".
            ($product===null?'':' AND product=?').' ORDER BY created_at DESC,id DESC');
        $select->execute($product===null?[$userId,$userId]:[$userId,$userId,$product]);$pending=$select->fetchAll();
        return ['orders'=>array_map($this->view(...),$rows),'per_page'=>50,
            'next_cursor'=>$more?HistoryCursor::encode('orders',$userId,$last['id'],(int)$last['created_at']):null,
            'pending_order'=>$pending===[]?null:$this->view($pending[0]),'pending_orders'=>array_map($this->view(...),$pending)];
    }

    public function wallet(int $userId, ?string $cursor = null): array
    {
        $position=HistoryCursor::decode($cursor,'wallet',$userId);
        $select = $this->db->prepare('SELECT currency,balance FROM portal_wallets WHERE user_id=? ORDER BY currency');
        $select->execute([$userId]);
        $balances = ['CNY'=>0,'USD'=>0];
        foreach ($select->fetchAll() as $row) { $balances[$row['currency']] = (int)$row['balance']; }
        $filter=$position===null?'':' AND id<CAST(? AS UNSIGNED)';
        $select = $this->db->prepare('SELECT id,currency,amount,balance_after,event_type,order_id,created_at
            FROM portal_wallet_entries WHERE user_id=?'.$filter.' ORDER BY id DESC LIMIT 51');
        $select->execute($position===null?[$userId]:[$userId,$position['id']]);
        $rows=$select->fetchAll();$more=count($rows)>50;
        if ($more) array_pop($rows);
        foreach ($rows as &$row) $row['id']=(string)$row['id'];
        unset($row);
        return ['balances'=>$balances,'entries'=>$rows,'per_page'=>50,
            'next_cursor'=>$more?HistoryCursor::encode('wallet',$userId,(string)$rows[array_key_last($rows)]['id']):null];
    }

    private function profiles(): PaymentProfiles
    {
        return $this->profiles ?? new PaymentProfiles($this->db,SecretBox::fromEnvironment(),$this->paymentConfigurationId);
    }

    private function wechatProfiles(): WechatProfiles
    {
        return $this->wechat ?? new WechatProfiles($this->db,SecretBox::fromEnvironment(),$this->paymentConfigurationId);
    }


    private function account(int $userId): void
    {
        $select = $this->db->prepare('SELECT status FROM portal_users WHERE id=? FOR UPDATE');
        $select->execute([$userId]);
        if ($select->fetchColumn() !== 'active') { throw new Problem('account_restricted_or_unverified',403); }
        $select = $this->db->prepare('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL');
        $select->execute([$userId]);
        if ((int)$select->fetchColumn() < 1) { throw new Problem('account_restricted_or_unverified',403); }
        $select = $this->db->prepare('SELECT login_blocked,purchase_blocked FROM portal_user_controls WHERE user_id=? FOR UPDATE');
        $select->execute([$userId]);
        $control = $select->fetch();
        if ($control !== false && ((int)$control['login_blocked'] === 1 || (int)$control['purchase_blocked'] === 1)) {
            throw new Problem('account_purchase_blocked',403);
        }
    }

    private function license(int $userId, string $product): ?array
    {
        $select = $this->db->prepare('SELECT * FROM portal_licenses WHERE user_id=? AND product=? FOR UPDATE');
        $select->execute([$userId,$product]);
        return $select->fetch() ?: null;
    }

    /** Caller holds a transaction; account locks precede all order and license mutations. */
    public function assertPurchaseAllowed(int $userId, string $product): void
    {
        if (!$this->db->inTransaction()) { throw new \RuntimeException('purchase_transaction_required'); }
        $this->account($userId);
        $this->purchasable($userId,$product);
    }

    private function purchasable(int $userId, string $product): void
    {
        ProductCatalog::code($product);

        $license = $this->license($userId,$product);
        if ($license !== null && $license['kind'] === 'formal') { throw new Problem('formal_license_already_owned',409); }
        if ($license !== null && $license['status'] !== 'active') { throw new Problem('license_disabled',403); }
        $select = $this->db->prepare('SELECT enabled,purchases_enabled,deleted_at FROM portal_products WHERE code=? FOR SHARE');
        $select->execute([$product]);$item=$select->fetch();
        if ($item === false) { throw new Problem('product_not_found',404); }
        if (!(bool)$item['enabled'] || !(bool)$item['purchases_enabled'] || $item['deleted_at'] !== null ||
            !ProductCatalog::hasPublishedContent($this->db,$product,true)) {
            throw new Problem('purchases_closed',409);
        }
    }

    private function price(string $currency, string $product): array
    {
        $select = $this->db->prepare('SELECT * FROM portal_product_prices WHERE product_code=? AND currency=? AND enabled=1 FOR UPDATE');
        $select->execute([$product,$currency]);
        $price = $select->fetch();
        if ($price === false) { throw new Problem('price_unavailable',409); }
        return $price;
    }

    private function lockedOrder(int $userId, string $id): array
    {
        // The locked user serializes every order and entitlement mutation for this account.
        $select = $this->db->prepare('SELECT * FROM portal_orders WHERE id=? AND user_id=? FOR UPDATE');
        $select->execute([$id,$userId]);
        $row = $select->fetch();
        if ($row === false) { throw new Problem('order_not_found',404); }
        return $row;
    }

    private function adminEvent(int $id, string $operation, string $target, array $context, array $details, int $now): void
    {
        $query = $this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");
        $query->execute([$id]);
        $email = $query->fetchColumn();
        if ($email === false) { throw new Problem('administrator_inactive',403); }
        Audit::admin($this->db,$id,$email,$operation,$target,$context,'SUCCESS',$details,$now);
    }

    private function view(array $order): array
    {
        $order['order_no']??=null;
        return array_intersect_key($order,array_flip(['id','order_no','quote_id','product','currency','amount','payment_method','payment_configuration_id',
            'status','paid_at','refunded_amount','created_at','expires_at']));
    }

    private static function id(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Problem('resource_id_invalid'); }
    }
}
