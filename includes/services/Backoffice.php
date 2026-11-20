<?php
declare(strict_types=1);
namespace D2S\services;

use D2S\domain\AccountInput;
use D2S\domain\Problem;
use D2S\repositories\Database;
use PDO;

final readonly class Backoffice
{
    public function __construct(private PDO $db) {}

    public function dashboard(int $adminId, int $now): array
    {
        $permissions = $this->permissions($adminId);
        $start = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->setTime(0,0)->getTimestamp();
        $data = ['generated_at'=>$now,'timezone'=>'Asia/Shanghai','metrics'=>[],'todos'=>[]];
        if (in_array('orders.read',$permissions,true)) {
            $q=$this->db->prepare("SELECT currency,COUNT(*) order_count,COALESCE(SUM(amount-refunded_amount),0) net_amount
                FROM portal_orders WHERE paid_at>=? AND product<>'wallet_recharge'
                    AND status IN ('paid','refund_pending','partially_refunded','refunded') GROUP BY currency");
            $q->execute([$start]); $data['sales_today']=$q->fetchAll();
            foreach (['payment_review'=>'operations.todos.payment_review','refund_pending'=>'operations.todos.refund_pending'] as $status=>$title) {
                $data['todos'][]=$this->attentionTodo('order_'.$status,$title,
                    'SELECT COUNT(*) AS item_count,MIN(updated_at) AS oldest_at FROM portal_orders WHERE status=?',[$status],
                    '/admin/orders?status='.$status,'critical');
            }
            $data['todos'][]=$this->attentionTodo('external_refund','operations.todos.external_refunds',
                "SELECT COUNT(*) AS item_count,MIN(created_at) AS oldest_at FROM portal_external_refunds WHERE status IN ('pending','submitted','review')",[],
                '/admin/order-search','high');
            $data['todos'][]=$this->attentionTodo('checkout_review','operations.todos.checkout_review',
                "SELECT COUNT(*) AS item_count,MIN(updated_at) AS oldest_at FROM portal_external_checkouts WHERE status='opening' AND provider_order_id IS NULL AND updated_at<=?",[$now-300],
                '/admin/order-search','high');
            $data['metrics']['orders_today']=$this->count('SELECT COUNT(*) FROM portal_orders WHERE created_at>=?',[$start]);
        }
        if (in_array('users.read',$permissions,true)) {
            $data['metrics']['users']=$this->count('SELECT COUNT(*) FROM portal_users');
            $data['metrics']['registrations_today']=$this->count('SELECT COUNT(*) FROM portal_users WHERE created_at>=?',[$start]);
            $data['metrics']['formal_users']=$this->count("SELECT COUNT(DISTINCT user_id) FROM portal_licenses WHERE kind='formal' AND status='active'");
            $data['metrics']['formal_licenses']=$this->count("SELECT COUNT(*) FROM portal_licenses WHERE kind='formal' AND status='active'");
            $data['metrics']['active_trials']=$this->count("SELECT COUNT(*) FROM portal_licenses WHERE kind='trial' AND status='active' AND expires_at>?",[$now]);
        }
        if (in_array('tickets.read',$permissions,true)) {
            $data['todos'][]=$this->attentionTodo('tickets','operations.todos.tickets',
                "SELECT COUNT(*) AS item_count,MIN(updated_at) AS oldest_at FROM portal_support_tickets WHERE status IN ('OPEN','IN_PROGRESS')",[],
                '/admin/tickets','high');
        }
        if (in_array('referrals.manage',$permissions,true)) {
            $data['todos'][]=$this->attentionTodo('withdrawals','operations.todos.withdrawals',
                "SELECT COUNT(*) AS item_count,MIN(created_at) AS oldest_at FROM portal_commission_withdrawals WHERE status='pending'",[],
                '/admin/referrals','high');
        }
        if (in_array('notifications.manage',$permissions,true)) {
            $data['todos'][]=$this->attentionTodo('notifications','operations.todos.notifications',
                "SELECT COUNT(*) AS item_count,MIN(updated_at) AS oldest_at FROM portal_notification_outbox WHERE status IN ('failed','blocked')",[],
                '/admin/notifications','high');
        }
        $rank=['critical'=>0,'high'=>1,'medium'=>2,'normal'=>3];
        $data['todos']=array_values(array_filter($data['todos'],static fn(array $todo):bool=>$todo['count']>0));
        usort($data['todos'],static function(array $left,array $right)use($rank):int{
            $severity=($rank[$left['severity']]??9)<=>($rank[$right['severity']]??9);
            if($severity!==0)return $severity;
            $oldest=($left['oldest_at']??PHP_INT_MAX)<=>($right['oldest_at']??PHP_INT_MAX);
            return $oldest!==0?$oldest:strcmp($left['label'],$right['label']);
        });
        $data['todos_total']=array_sum(array_column($data['todos'],'count'));
        return $data;
    }

    private function attentionTodo(string $key,string $label,string $sql,array $arguments,string $url,string $severity): array
    {
        $query=$this->db->prepare($sql);
        $query->execute($arguments);
        $row=$query->fetch();
        return [
            'key'=>$key,'label'=>$label,'count'=>(int)($row['item_count']??0),
            'oldest_at'=>is_numeric($row['oldest_at']??null)?(int)$row['oldest_at']:null,
            'url'=>$url,'severity'=>$severity,
        ];
    }

    public function users(int $adminId, array $filters): array
    {
        Permissions::require($this->db,$adminId,'users.read');
        $now=time();
        [$where,$args]=$this->userFilter($filters,$now);
        $from=" FROM portal_users u LEFT JOIN portal_contacts e ON e.user_id=u.id AND e.kind='email'
            LEFT JOIN portal_contacts p ON p.user_id=u.id AND p.kind='phone'
            LEFT JOIN portal_licenses l ON l.user_id=u.id AND l.product='desktop2stereo'
            LEFT JOIN portal_user_controls c ON c.user_id=u.id";
        $total=$this->count('SELECT COUNT(*)'.$from.$where,$args);
        $offset=self::offset($filters);
        $q=$this->db->prepare("SELECT u.id,u.username,u.display_name,u.status,u.region,u.created_at,u.updated_at,
            u.last_login_at,u.last_login_ip,u.login_count,e.value email,p.value phone,
            e.verified_at email_verified_at,p.verified_at phone_verified_at,
            l.kind license_kind,l.status license_status,l.expires_at license_expires_at,
            (SELECT COUNT(*) FROM portal_licenses owned WHERE owned.user_id=u.id) license_count,
            (SELECT COUNT(*) FROM portal_licenses owned WHERE owned.user_id=u.id AND owned.kind='formal' AND owned.status='active') formal_license_count,
            (SELECT COUNT(*) FROM portal_licenses owned WHERE owned.user_id=u.id AND owned.kind='trial' AND owned.status='active' AND owned.expires_at>?) active_trial_count,
            (SELECT o.currency FROM portal_orders o WHERE o.user_id=u.id AND o.paid_at IS NOT NULL
                AND o.status IN ('paid','refund_pending','partially_refunded','refunded') ORDER BY o.paid_at DESC,o.id DESC LIMIT 1) last_payment_currency,
            (SELECT GROUP_CONCAT(DISTINCT o.currency ORDER BY o.currency) FROM portal_orders o WHERE o.user_id=u.id AND o.paid_at IS NOT NULL
                AND o.status IN ('paid','refund_pending','partially_refunded','refunded')) payment_currencies,
            (SELECT r.register_ip FROM portal_registration_records r WHERE r.user_id=u.id ORDER BY r.id LIMIT 1) register_ip,
            COALESCE(c.login_blocked,0) login_blocked".$from.$where." ORDER BY u.id DESC LIMIT 50 OFFSET ".$offset);
        $q->execute(array_merge([$now],$args)); $items=$q->fetchAll();
        $geoip=new IpCountry();$overview=new LicenseOverview($this->db);
        $locale=function_exists('d2s_locale_code')?d2s_locale_code():'en';
        foreach ($items as &$row) {
            $row['login_country']=$geoip->lookup($row['last_login_ip']);
            $row['register_country']=$geoip->lookup($row['register_ip']);
            $row['licenses']=$overview->all((int)$row['id'],$locale);

        }
        return ['items'=>$items,'total'=>$total,'offset'=>$offset,'limit'=>50,'geoip'=>$geoip->metadata(),
            'license_products'=>(new LicenseOverview($this->db))->productOptions(function_exists('d2s_locale_code')?d2s_locale_code():'en')];
    }

    public function user(int $adminId, int $id, array $context, int $now): array
    {
        Permissions::require($this->db,$adminId,'users.read');
        $q=$this->db->prepare('SELECT id,username,display_name,status,region,remark,created_at,updated_at,
            last_login_at,last_login_ip,login_count,password_changed_at,session_version FROM portal_users WHERE id=?');
        $q->execute([$id]);$user=$q->fetch();if(!$user)throw new Problem('user_not_found',404);
        $geoip=new IpCountry();
        $user['login_country']=$geoip->lookup($user['last_login_ip']);

        $q=$this->db->prepare('SELECT kind,value,verified_at FROM portal_contacts WHERE user_id=?');$q->execute([$id]);
        $user['contacts']=$q->fetchAll();

        $q=$this->db->prepare('SELECT kind,status,trial_started_at,expires_at,created_at,updated_at FROM portal_licenses WHERE user_id=? AND product=\'desktop2stereo\'');
        $q->execute([$id]);$user['license']=$q->fetch()?:null;
        $user['licenses']=(new LicenseOverview($this->db))->all($id,function_exists('d2s_locale_code')?d2s_locale_code():'en');
        $q=$this->db->prepare('SELECT product,device_hash,session_version,created_at,verified_at,expires_at FROM portal_client_sessions WHERE user_id=? ORDER BY product');
        $q->execute([$id]);$user['client_sessions']=$q->fetchAll();
        $q=$this->db->prepare('SELECT product_code,platform,last_client_login_at,last_version_reported_at,version_code,version_name,architecture FROM portal_product_user_activity WHERE user_id=? ORDER BY product_code,platform');
        $q->execute([$id]);$user['client_activity']=$q->fetchAll();
        $q=$this->db->prepare('SELECT product_code,platform,device_type,os_name,os_version,os_build,gpu_model,android_api_level,device_model,reported_at FROM portal_product_user_environment WHERE user_id=? ORDER BY product_code,platform,device_type');
        $q->execute([$id]);$user['client_environments']=$q->fetchAll();
        $q=$this->db->prepare('SELECT register_ip,register_time FROM portal_registration_records WHERE user_id=? ORDER BY id LIMIT 1');
        $q->execute([$id]);$registration=$q->fetch();
        $user['registration']=$registration?['time'=>$registration['register_time'],'ip'=>$registration['register_ip'],
            'country'=>$geoip->lookup($registration['register_ip'])]:null;
        $q=$this->db->prepare("SELECT currency,COUNT(*) paid_orders,SUM(amount) paid_amount,SUM(refunded_amount) refunded_amount,
            SUM(amount-refunded_amount) net_amount,MAX(paid_at) last_payment_at FROM portal_orders
            WHERE user_id=? AND paid_at IS NOT NULL AND status IN ('paid','refund_pending','partially_refunded','refunded') GROUP BY currency");
        $q->execute([$id]);$user['payments_by_currency']=$q->fetchAll();
        $q=$this->db->prepare("SELECT product,currency,COUNT(*) paid_orders,SUM(amount) paid_amount,SUM(refunded_amount) refunded_amount,
            SUM(amount-refunded_amount) net_amount,MAX(paid_at) last_payment_at FROM portal_orders
            WHERE user_id=? AND product<>'wallet_recharge' AND paid_at IS NOT NULL
                AND status IN ('paid','refund_pending','partially_refunded','refunded') GROUP BY product,currency ORDER BY product,currency");
        $q->execute([$id]);$user['software_payments']=$q->fetchAll();
        $q=$this->db->prepare('SELECT currency,balance,updated_at FROM portal_wallets WHERE user_id=?');$q->execute([$id]);$user['wallets']=$q->fetchAll();
        $q=$this->db->prepare('SELECT currency,available,reserved FROM portal_commission_wallets WHERE user_id=?');$q->execute([$id]);$user['commission_wallets']=$q->fetchAll();
        $user['effective_referrals']=ReferralProgram::effectiveBuyerCount($this->db,$id);
        $q=$this->db->prepare('SELECT currency,SUM(amount-reversed_amount) total_commission FROM portal_referral_commissions WHERE inviter_id=? GROUP BY currency');
        $q->execute([$id]);$user['commissions']=$q->fetchAll();
        $q=$this->db->prepare('SELECT outcome,source_ip,created_at FROM portal_login_history WHERE user_id=? ORDER BY id DESC LIMIT 20');
        $q->execute([$id]);$user['recent_logins']=$q->fetchAll();
        foreach($user['recent_logins'] as &$login){
            $login['country']=$geoip->lookup($login['source_ip']);

        }
        $user['geoip']=$geoip->metadata();
        $this->audit($adminId,'VIEW_USER',(string)$id,[],$context,$now);
        return $user;
    }

    public function updateUser(int $adminId,int $id,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'users.manage');
        $display=AccountInput::displayName(self::text($input,'display_name',80));
        $remark=self::text($input,'remark',1000);
        $status=$input['status']??'';
        if(!in_array($status,['active','disabled'],true))throw new Problem('status_invalid');
        $contacts=[];
        foreach(['email','phone']as$kind)if(array_key_exists($kind,$input)){
            $value=self::text($input,$kind,254);
            $contacts[$kind]=$value===''?'':AccountInput::contact($kind,$value);
        }
        try{
            return Database::transaction($this->db,function(PDO $db)use($adminId,$id,$input,$context,$now,$display,$remark,$status,$contacts):array{
                $q=$db->prepare('SELECT username,region,status,session_version FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch();
                if(!$before)throw new Problem('user_not_found',404);
                if(isset($input['expected_session_version'])&&$input['expected_session_version']!==(int)$before['session_version'])throw new Problem('record_changed',409);
                $username=array_key_exists('username',$input)?AccountInput::username(self::text($input,'username',32,true)):$before['username'];
                $region=array_key_exists('region',$input)?AccountInput::region(self::text($input,'region',8,true)):$before['region'];
                $password=$input['new_password']??'';
                if(!is_string($password))throw new Problem('password_policy');
                if($password!=='')AccountInput::password($password);
                $contactChanges=[];
                foreach($contacts as$kind=>$value){
                    $q=$db->prepare('SELECT value FROM portal_contacts WHERE user_id=? AND kind=? FOR UPDATE');$q->execute([$id,$kind]);$current=$q->fetchColumn();
                    if(($current===false?'':$current)===$value)continue;
                    if($value==='')$db->prepare('DELETE FROM portal_contacts WHERE user_id=? AND kind=?')->execute([$id,$kind]);
                    elseif($current===false)$db->prepare('INSERT INTO portal_contacts(user_id,kind,value,verified_at,created_at) VALUES(?,?,?,?,?)')->execute([$id,$kind,$value,$now,$now]);
                    else $db->prepare('UPDATE portal_contacts SET value=?,verified_at=? WHERE user_id=? AND kind=?')->execute([$value,$now,$id,$kind]);
                    $contactChanges[$kind]=['before'=>$current===false?null:$current,'after'=>$value===''?null:$value];
                }
                if($status==='active'&&$this->count('SELECT COUNT(*) FROM portal_contacts WHERE user_id=? AND verified_at IS NOT NULL',[$id])===0)throw new Problem('verified_contact_required');
                $revoke=$before['status']!==$status||$before['username']!==$username||$before['region']!==$region||$password!==''||$contactChanges!==[];
                $db->prepare('UPDATE portal_users SET username=?,region=?,display_name=?,remark=?,status=?,updated_at=GREATEST(updated_at+1,?),session_version=session_version+? WHERE id=?')
                    ->execute([$username,$region,$display,$remark,$status,$now,$revoke?1:0,$id]);
                if($password!=='')$db->prepare('UPDATE portal_users SET password_hash=?,password_changed_at=? WHERE id=?')
                    ->execute([password_hash($password,PASSWORD_DEFAULT),$now,$id]);
                if($revoke){
                    $db->prepare('DELETE FROM portal_web_sessions WHERE user_id=?')->execute([$id]);
                    $db->prepare('DELETE FROM portal_client_sessions WHERE user_id=?')->execute([$id]);
                }
                $this->audit($adminId,'UPDATE_USER',(string)$id,[
                    'previous_status'=>$before['status'],'status'=>$status,'password_reset'=>$password!=='',
                    'previous_username'=>$before['username'],'username'=>$username,'previous_region'=>$before['region'],'region'=>$region,
                    'contacts'=>$contactChanges,
                ],$context,$now);
                return ['saved'=>true,'sessions_revoked'=>$revoke];
            });
        }catch(\PDOException $e){
            if($e->getCode()!=='23000')throw $e;
            throw new Problem('account_or_contact_exists',409);
        }
    }

    public function adjustWallet(int $adminId,int $id,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'wallets.adjust');
        $currency=self::currency($input['currency']??'');
        $amount=$input['amount']??null;
        $reason=self::text($input,'reason',500,true);
        $key=self::text($input,'idempotency_key',80,true);
        if(!is_int($amount)||$amount===0||abs($amount)>1000000000000||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new Problem('wallet_adjustment_invalid');
        return Database::transaction($this->db,function(PDO $db)use($adminId,$id,$input,$context,$now,$currency,$amount,$reason,$key):array{
            $q=$db->prepare('SELECT id FROM portal_users WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new Problem('user_not_found',404);
            $reference='admin_adjust:'.$adminId.':'.$key;
            $q=$db->prepare('SELECT user_id,currency,amount,balance_after FROM portal_wallet_entries WHERE reference=?');$q->execute([$reference]);$old=$q->fetch();
            if($old){if((int)$old['user_id']!==$id||$old['currency']!==$currency||(int)$old['amount']!==$amount)throw new Problem('idempotency_conflict',409);return ['balance'=>(int)$old['balance_after'],'duplicate'=>true];}
            $db->prepare('INSERT IGNORE INTO portal_wallets(user_id,currency,balance,updated_at) VALUES(?,?,0,?)')->execute([$id,$currency,$now]);
            $q=$db->prepare('SELECT balance FROM portal_wallets WHERE user_id=? AND currency=? FOR UPDATE');$q->execute([$id,$currency]);$balance=(int)$q->fetchColumn()+$amount;
            if($balance<0||$balance>1000000000000)throw new Problem('wallet_balance_out_of_range',409);
            $db->prepare('UPDATE portal_wallets SET balance=?,updated_at=? WHERE user_id=? AND currency=?')->execute([$balance,$now,$id,$currency]);
            $db->prepare("INSERT INTO portal_wallet_entries(user_id,currency,amount,balance_after,reference,event_type,created_at) VALUES(?,?,?,?,?,'ADMIN_ADJUSTMENT',?)")
                ->execute([$id,$currency,$amount,$balance,$reference,$now]);
            $this->audit($adminId,'ADJUST_WALLET',(string)$id,['currency'=>$currency,'amount'=>$amount,'reason'=>$reason,'reference'=>$reference],$context,$now);
            return ['balance'=>$balance,'duplicate'=>false];
        });
    }

    public function ledger(int $adminId,array $filters): array
    {
        Permissions::require($this->db,$adminId,'wallets.read');
        $account=$filters['account']??'wallet';
        if(!in_array($account,['wallet','commission'],true))throw new Problem('ledger_account_invalid');
        $table=$account==='wallet'?'portal_wallet_entries':'portal_commission_entries';
        $where=[];$args=[];
        foreach(['user_id','currency','event_type']as$key){
            if(($filters[$key]??'')==='')continue;
            $value=$key==='user_id'?self::id($filters[$key]):($key==='currency'?self::currency($filters[$key]):self::text($filters,$key,32,true));
            $where[]='e.'.$key.'=?';$args[]=$value;
        }
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $offset=self::offset($filters);
        $total=$this->count("SELECT COUNT(*) FROM $table e".$clause,$args);
        $q=$this->db->prepare("SELECT e.id,e.user_id,u.username,e.currency,e.amount,e.balance_after,e.event_type,e.reference,e.order_id,e.created_at".
            ($account==='commission'?',e.reserved_delta,e.reserved_after':'')." FROM $table e JOIN portal_users u ON u.id=e.user_id".$clause." ORDER BY e.id DESC LIMIT 50 OFFSET ".$offset);
        $q->execute($args);
        return ['account'=>$account,'items'=>$q->fetchAll(),'total'=>$total,'offset'=>$offset,'limit'=>50];
    }

    public function orders(int $adminId,array $filters,bool $export=false): array
    {
        Permissions::require($this->db,$adminId,$export?'orders.export':'orders.read');
        $where=[];$args=[];
        foreach(['status','payment_method','currency','product'] as $field){
            if(($filters[$field]??'')==='')continue;
            $where[]='o.'.$field.'=?';$args[]=self::text($filters,$field,32,true);
        }
        if(($filters['q']??'')!==''){
            $value='%'.self::text($filters,'q',100,true).'%';
            $where[]='(o.order_no LIKE ? OR o.id LIKE ? OR u.username LIKE ?)';array_push($args,$value,$value,$value);
        }
        foreach(['from'=>'>=','until'=>'<']as$key=>$op){
            if(($filters[$key]??'')==='')continue;
            if(!ctype_digit((string)$filters[$key]))throw new Problem('date_range_invalid');
            $where[]='o.created_at'.$op.'?';$args[]=(int)$filters[$key];
        }
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $from=' FROM portal_orders o JOIN portal_users u ON u.id=o.user_id';
        $total=$this->count('SELECT COUNT(*)'.$from.$clause,$args);
        if($export&&$total>5000)throw new Problem('export_too_many_rows',409);
        $q=$this->db->prepare('SELECT o.id,o.order_no,u.username,o.product,o.currency,o.amount,o.refunded_amount,
            o.payment_method,o.status,o.created_at,o.paid_at,o.updated_at'.$from.$clause.' ORDER BY o.created_at DESC,o.id DESC LIMIT '.($export?'5000':'50 OFFSET '.self::offset($filters)));
        $q->execute($args);
        if($export)$this->audit($adminId,'EXPORT_ORDERS','orders',['rows'=>$total,'filters'=>$filters],Audit::context($_SERVER),time());
        return ['items'=>$q->fetchAll(),'total'=>$total,'offset'=>self::offset($filters),'limit'=>$export?5000:50];
    }

    public function diagnostics(int $adminId,string $orderId): array
    {
        Permissions::require($this->db,$adminId,'orders.read');
        if(!preg_match('/^[a-f0-9]{32}$/D',$orderId))throw new Problem('order_not_found',404);
        $q=$this->db->prepare('SELECT id,order_no,payment_method,status,currency,amount,refunded_amount,created_at,paid_at FROM portal_orders WHERE id=?');
        $q->execute([$orderId]);$order=$q->fetch();if(!$order)throw new Problem('order_not_found',404);
        $events=[];
        foreach(['portal_payment_events','portal_wechat_events','portal_external_events'] as $table){
            $q=$this->db->prepare("SELECT event_type,result,received_at FROM $table WHERE order_id=? ORDER BY received_at DESC LIMIT 100");
            $q->execute([$orderId]);array_push($events,...$q->fetchAll());
        }
        usort($events,static fn($a,$b)=>$b['received_at']<=>$a['received_at']);
        return ['order'=>$order,'events'=>$events,'notes'=>[
            'operations.diagnostics.no_callback',
            'operations.diagnostics.redacted',
        ]];
    }

    public function articles(int $adminId,string $locale='zh-Hans'): array
    {
        Permissions::require($this->db,$adminId,'articles.manage');
        $items=$this->db->query("SELECT * FROM portal_articles WHERE status<>'archived' ORDER BY updated_at DESC LIMIT 200")->fetchAll();
        $translations=new ArticleTranslations($this->db);
        $categoryTranslations=new ArticleCategories($this->db);
        foreach($items as &$item){
            $category=$categoryTranslations->publicLabel((string)$item['category_id'],$locale);
            $item['category']=$category['label'];
            $item['category_locale']=$category['locale'];
            if($item['body_format']==='html'){
                $item['body_zh']=ArticleContent::clean($item['body_zh']);
                $item['body_en']=ArticleContent::clean($item['body_en']);
            }
            $item['translation_status']=$translations->catalog($adminId,(string)$item['id'])['items'];
            $item['source_title']='';
            foreach($item['translation_status']as$translation){
                if($translation['locale']===$item['source_locale']){
                    $item['source_title']=$translation['draft_title']??$translation['published_title']??'';
                    break;
                }
            }
        }
        return ['items'=>$items];
    }

    public function product(int $adminId,?array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'products.manage');
        if($input!==null){
            if(!is_bool($input['enabled']??null))throw new Problem('field_invalid');
            Database::transaction($this->db,function(PDO $db)use($input,$adminId,$context,$now):void{
                $db->prepare("UPDATE portal_products SET enabled=?,updated_at=? WHERE code='desktop2stereo'")
                    ->execute([(int)$input['enabled'],$now]);
                $this->audit($adminId,'UPDATE_PRODUCT','desktop2stereo',['enabled'=>$input['enabled']],$context,$now);
            });
        }
        $product=$this->db->query("SELECT * FROM portal_products WHERE code='desktop2stereo'")->fetch();
        $product['translation_status']=(new ProductTranslations($this->db))->catalog($adminId,'desktop2stereo')['items'];
        return $product;
    }

    public function staff(int $adminId): array
    {
        Permissions::require($this->db,$adminId,'admins.manage');
        $items=$this->db->query('SELECT id,username,email,status,is_system,created_at,updated_at,last_login_at,last_login_ip,login_count,remark FROM portal_admins ORDER BY id')->fetchAll();
        foreach($items as &$item){$q=$this->db->prepare('SELECT permission FROM portal_admin_permissions WHERE admin_id=? ORDER BY permission');$q->execute([$item['id']]);$item['permissions']=$q->fetchAll(PDO::FETCH_COLUMN);}
        return ['items'=>$items,'roles'=>Permissions::ROLES,'role_labels'=>Permissions::ROLE_LABELS,'modules'=>Permissions::MODULES,'dependencies'=>Permissions::DEPENDENCIES];
    }

    public function saveStaff(int $adminId,array $input,array $context,int $now): array
    {
        Permissions::require($this->db,$adminId,'admins.manage');
        $id=($input['id']??'')===''?null:self::id($input['id']);
        $username=AccountInput::username(self::text($input,'username',32,true));
        $email=AccountInput::contact('email',self::text($input,'email',254,true));
        $roles=$input['roles']??[];
        if(!is_array($roles)||!array_is_list($roles)||array_filter($roles,static fn($v)=>!is_string($v))||array_diff($roles,array_keys(Permissions::ROLES)))throw new Problem('roles_invalid');
        $granted=[];
        foreach($roles as$role)$granted=array_merge($granted,Permissions::ROLES[$role]);
        $permissions=$input['permissions']??array_values(array_unique($granted));
        if(!is_array($permissions)||!array_is_list($permissions)||array_filter($permissions,static fn($v)=>!is_string($v))||array_diff($permissions,Permissions::ALL))throw new Problem('permissions_invalid');
        $permissions=in_array('superadmin',$roles,true)?Permissions::ALL:Permissions::expand($permissions);
        if(!array_key_exists('permissions',$input)&&$roles===[])throw new Problem('roles_invalid');
        $status=$input['status']??'active';if(!in_array($status,['active','disabled'],true))throw new Problem('status_invalid');
        $remark=self::text($input,'remark',1000);
        $password=$input['new_password']??'';
        if(!is_string($password)||($id===null&&$password===''))throw new Problem('password_policy');
        if($password!=='')AccountInput::password($password,true);
        try{
            return Database::transaction($this->db,function(PDO $db)use($adminId,$id,$context,$now,$username,$email,$roles,$permissions,$status,$remark,$password):array{
                // Serialize staff edits so concurrent changes cannot remove the final super administrator.
                $admins=$db->query('SELECT id,username,email,status FROM portal_admins ORDER BY id FOR UPDATE')->fetchAll();
                $permissionRows=$db->query('SELECT admin_id,permission FROM portal_admin_permissions ORDER BY admin_id,permission')->fetchAll();
                $grants=[];foreach($permissionRows as$row)$grants[(int)$row['admin_id']][]=$row['permission'];
                $before=null;
                foreach($admins as$admin)if((int)$admin['id']===$id)$before=$admin;
                if($id!==null&&!$before)throw new Problem('administrator_not_found',404);
                $willBeSuper=$status==='active'&&array_diff(Permissions::ALL,$permissions)===[];
                $anotherSuper=false;
                foreach($admins as$admin)if((int)$admin['id']!==$id&&$admin['status']==='active'&&array_diff(Permissions::ALL,$grants[(int)$admin['id']]??[])===[])$anotherSuper=true;
                if(!$willBeSuper&&!$anotherSuper)throw new Problem('last_super_administrator',409);
                $revoke=$before&&($before['username']!==$username||$before['status']!==$status||$password!==''||
                    array_diff($permissions,$grants[$id]??[])!==[]||array_diff($grants[$id]??[],$permissions)!==[]);
                if($before){
                    $db->prepare('UPDATE portal_admins SET username=?,email=?,status=?,remark=?,updated_at=GREATEST(updated_at+1,?),session_version=session_version+? WHERE id=?')
                        ->execute([$username,$email,$status,$remark,$now,$revoke?1:0,$id]);
                    $db->prepare('DELETE FROM portal_admin_permissions WHERE admin_id=?')->execute([$id]);
                    if($revoke)$db->prepare('DELETE FROM portal_web_sessions WHERE admin_id=?')->execute([$id]);
                }else{
                    $db->prepare('INSERT INTO portal_admins(username,email,password_hash,status,remark,created_at,updated_at,password_changed_at) VALUES(?,?,?,?,?,?,?,?)')
                        ->execute([$username,$email,password_hash($password,PASSWORD_DEFAULT),$status,$remark,$now,$now,$now]);
                    $id=(int)$db->lastInsertId();
                }
                if($before&&$password!=='')$db->prepare('UPDATE portal_admins SET password_hash=?,password_changed_at=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$now,$id]);
                $q=$db->prepare('INSERT INTO portal_admin_permissions(admin_id,permission) VALUES(?,?)');
                foreach($permissions as$permission)$q->execute([$id,$permission]);
                $operator=array_values(array_filter($admins,static fn($admin)=>(int)$admin['id']===$adminId))[0]??null;
                if(!$operator||$operator['status']!=='active')throw new Problem('administrator_inactive',403);
                Audit::admin($db,$adminId,$operator['email'],'SAVE_STAFF',(string)$id,$context,'SUCCESS',['roles'=>$roles,'permissions'=>$permissions,'status'=>$status],$now);
                return ['id'=>$id,'login_required'=>$id===$adminId&&$revoke];
            });
        }catch(\PDOException $e){
            if($e->getCode()!=='23000')throw $e;
            throw new Problem('account_or_contact_exists',409);
        }
    }

    public function permissions(int $adminId): array
    {
        $q=$this->db->prepare('SELECT permission FROM portal_admin_permissions WHERE admin_id=?');$q->execute([$adminId]);return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function audit(int $adminId,string $operation,string $target,array $details,array $context,int $now): void
    {
        $q=$this->db->prepare("SELECT email FROM portal_admins WHERE id=? AND status='active'");$q->execute([$adminId]);$email=$q->fetchColumn();
        if($email===false)throw new Problem('administrator_inactive',403);
        Audit::admin($this->db,$adminId,$email,$operation,$target,$context,'SUCCESS',$details,$now);
    }

    private function userFilter(array $filters,int $now): array
    {
        $where=[];$args=[];
        if(($filters['q']??'')!==''){$term='%'.self::text($filters,'q',100,true).'%';$where[]='(u.username LIKE ? OR u.display_name LIKE ? OR e.value LIKE ? OR p.value LIKE ?)';array_push($args,$term,$term,$term,$term);}
        if(($filters['status']??'')!==''){$where[]='u.status=?';$args[]=self::text($filters,'status',16,true);}
        $licenseFilter=[];
        if(($filters['product']??'')!==''){$licenseFilter[]='owned.product=?';$args[]=ProductCatalog::code(self::text($filters,'product',32,true));}
        if(($filters['license_kind']??'')!==''){
            $kind=self::text($filters,'license_kind',8,true);
            if(!in_array($kind,['trial','formal'],true))throw new Problem('status_invalid');
            $licenseFilter[]='owned.kind=?';$args[]=$kind;
        }
        if($licenseFilter!==[])$where[]='EXISTS(SELECT 1 FROM portal_licenses owned WHERE owned.user_id=u.id AND '.implode(' AND ',$licenseFilter).')';
        $activityWhere=[];
        if(($filters['product']??'')!==''){$activityWhere[]='activity.product_code=?';$args[]=ProductCatalog::code(self::text($filters,'product',32,true));}
        if(($filters['platform']??'')!==''){
            $platform=self::text($filters,'platform',16,true);
            if(!in_array($platform,['windows','macos','linux','android','unknown'],true))throw new Problem('client_platform_invalid');
            $activityWhere[]='activity.platform=?';$args[]=$platform;
        }
        if(($filters['version']??'')!==''){
            $version='%'.self::text($filters,'version',64,true).'%';
            $activityWhere[]='(activity.version_name LIKE ? OR CAST(activity.version_code AS CHAR) LIKE ?)';
            array_push($args,$version,$version);
        }
        if(($filters['activity']??'')!==''){
            $activityStatus=self::text($filters,'activity',16,true);
            if(!in_array($activityStatus,['recent','inactive','none'],true))throw new Problem('client_activity_filter_invalid');
            $activityClause=$activityWhere===[]?'':' AND '.implode(' AND ',$activityWhere);
            if($activityStatus==='none'){
                $where[]='NOT EXISTS(SELECT 1 FROM portal_product_user_activity activity WHERE activity.user_id=u.id'.$activityClause.')';
            }else{
                $cutoff=$now-30*86400;
                $timeCondition=$activityStatus==='recent'?'activity.last_client_login_at>=?':'activity.last_client_login_at<?';
                $where[]='EXISTS(SELECT 1 FROM portal_product_user_activity activity WHERE activity.user_id=u.id'.$activityClause.
                    ' AND activity.last_client_login_at IS NOT NULL AND '.$timeCondition.')';
                $args[]=$cutoff;
            }
        }elseif($activityWhere!==[]){
            $where[]='EXISTS(SELECT 1 FROM portal_product_user_activity activity WHERE activity.user_id=u.id AND '.implode(' AND ',$activityWhere).')';
        }
        if(($filters['currency']??'')!==''){
            $where[]="EXISTS (SELECT 1 FROM portal_orders o WHERE o.user_id=u.id AND o.currency=? AND o.paid_at IS NOT NULL
                AND o.status IN ('paid','refund_pending','partially_refunded','refunded'))";
            $args[]=self::currency($filters['currency']);
        }
        return [$where?' WHERE '.implode(' AND ',$where):'',$args];
    }

    private function count(string $sql,array $args=[]): int
    {
        $q=$this->db->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();
    }

    public static function id(mixed $value): int
    {
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new Problem('identifier_invalid');return $id;
    }

    public static function hexId(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))throw new Problem('identifier_invalid');return $value;
    }

    public static function offset(array $filters): int
    {
        $value=$filters['offset']??0;
        $offset=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
        if($offset===false)throw new Problem('pagination_invalid');return $offset;
    }

    public static function text(array $input,string $key,int $max,bool $required=false): string
    {
        $value=$input[$key]??'';
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new Problem('field_invalid');
        $value=trim($value);if($required&&$value==='')throw new Problem('field_required');return $value;
    }

    public static function currency(mixed $value): string
    {
        if(!in_array($value,['CNY','USD'],true))throw new Problem('currency_invalid');return $value;
    }

    public static function maskContact(?string $value,string $kind): ?string
    {
        if($value===null)return null;
        if($kind==='email'){[$local,$domain]=explode('@',$value,2)+['',''];return substr($local,0,1).'***@'.$domain;}
        return substr($value,0,3).'****'.substr($value,-4);
    }

    public static function maskIp(?string $value): ?string
    {
        if($value===null)return null;
        if(filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))return preg_replace('/\.[0-9]+$/','.*',$value);
        if(filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))return implode(':',array_slice(explode(':',inet_ntop(inet_pton($value))),0,3)).':*';
        return null;
    }
}
