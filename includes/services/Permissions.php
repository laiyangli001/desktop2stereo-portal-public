<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;
use PDO;

final class Permissions
{
    public const ALL = [
        'products.manage','announcements.manage','articles.manage','users.read','users.manage',
        'orders.read','orders.reconcile','orders.close','orders.resolve','orders.export',
        'refunds.manage','refunds.resolve','notifications.manage','bills.read','wallets.read','wallets.adjust',
        'referrals.manage','operations.read','settings.manage','payment.configure',
        'tickets.read','tickets.reply','audit.read','audit.export.request','audit.export.approve',
        'accounts.restrict','keys.manage','retention.manage','admins.manage','locales.manage',
    ];
    public const ROLE_LABELS = [
        'superadmin'=>'permissions.roles.superadmin','operations'=>'permissions.roles.operations',
        'finance'=>'permissions.roles.finance','support'=>'permissions.roles.support','business'=>'permissions.roles.business',
        'system'=>'permissions.roles.system','auditor'=>'permissions.roles.auditor','security'=>'permissions.roles.security',
    ];
    public const MODULES = [
        ['label'=>'permissions.modules.content','permissions'=>[
            'products.manage'=>'permissions.grants.products_manage','articles.manage'=>'permissions.grants.articles_manage',
            'announcements.manage'=>'permissions.grants.announcements_manage']],
        ['label'=>'permissions.modules.people','permissions'=>[
            'users.read'=>'permissions.grants.users_read','users.manage'=>'permissions.grants.users_manage','admins.manage'=>'permissions.grants.admins_manage']],
        ['label'=>'permissions.modules.orders','permissions'=>[
            'orders.read'=>'permissions.grants.orders_read','orders.export'=>'permissions.grants.orders_export',
            'orders.reconcile'=>'permissions.grants.orders_reconcile','orders.close'=>'permissions.grants.orders_close',
            'orders.resolve'=>'permissions.grants.orders_resolve','refunds.manage'=>'permissions.grants.refunds_manage','refunds.resolve'=>'permissions.grants.refunds_resolve']],
        ['label'=>'permissions.modules.finance','permissions'=>[
            'bills.read'=>'permissions.grants.bills_read','wallets.read'=>'permissions.grants.wallets_read',
            'wallets.adjust'=>'permissions.grants.wallets_adjust','referrals.manage'=>'permissions.grants.referrals_manage']],
        ['label'=>'permissions.modules.system','permissions'=>[
            'payment.configure'=>'permissions.grants.payment_configure',
            'settings.manage'=>'permissions.grants.settings_manage',
            'locales.manage'=>'permissions.grants.locales_manage',
            'notifications.manage'=>'permissions.grants.notifications_manage','operations.read'=>'permissions.grants.operations_read']],
        ['label'=>'permissions.modules.support','permissions'=>[
            'tickets.read'=>'permissions.grants.tickets_read','tickets.reply'=>'permissions.grants.tickets_reply']],
        ['label'=>'permissions.modules.security','permissions'=>[
            'accounts.restrict'=>'permissions.grants.accounts_restrict','audit.read'=>'permissions.grants.audit_read',
            'audit.export.request'=>'permissions.grants.audit_export_request',
            'keys.manage'=>'permissions.grants.keys_manage','retention.manage'=>'permissions.grants.retention_manage']],
    ];
    public const ROLES = [
        'superadmin' => self::ALL,
        'operations' => ['products.manage','announcements.manage','articles.manage','users.read','settings.manage','locales.manage','tickets.read','tickets.reply','notifications.manage','operations.read'],
        'support' => ['tickets.read', 'tickets.reply', 'orders.read'],
        'business' => ['products.manage', 'announcements.manage', 'articles.manage', 'users.read'],
        'finance' => ['orders.read', 'orders.reconcile', 'orders.close', 'orders.resolve', 'refunds.manage', 'refunds.resolve', 'notifications.manage', 'bills.read', 'wallets.read', 'wallets.adjust', 'referrals.manage', 'operations.read', 'orders.export', 'users.read'],
        'system' => ['settings.manage', 'payment.configure'],
        'auditor' => ['audit.read', 'audit.export.request'],
        'security' => ['audit.read', 'audit.export.request', 'accounts.restrict', 'keys.manage', 'retention.manage', 'admins.manage', 'users.read', 'users.manage'],
    ];

    public const DEPENDENCIES = [
        'users.manage'=>['users.read'],'wallets.adjust'=>['wallets.read','users.read'],'tickets.reply'=>['tickets.read'],
        'orders.export'=>['orders.read'],'orders.reconcile'=>['orders.read'],'orders.close'=>['orders.read'],
        'orders.resolve'=>['orders.read'],'refunds.manage'=>['orders.read'],'refunds.resolve'=>['orders.read'],
        'audit.export.request'=>['audit.read'],'audit.export.approve'=>['audit.export.request','audit.read'],
    ];

    public static function expand(array $permissions): array
    {
        foreach($permissions as$permission)$permissions=array_merge($permissions,self::DEPENDENCIES[$permission]??[]);
        return array_values(array_unique($permissions));
    }

    public static function require(PDO $db, int $adminId, string $permission): void
    {
        $query = $db->prepare('SELECT 1 FROM portal_admin_permissions WHERE admin_id=? AND permission=?');
        $query->execute([$adminId, $permission]);
        if ($query->fetchColumn() === false) {
            throw new Problem('permission_denied', 403);
        }
    }

    public static function requireAny(PDO $db, int $adminId, array $permissions): void
    {
        if ($permissions === []) {
            throw new Problem('permission_denied', 403);
        }
        $placeholders = implode(',',array_fill(0,count($permissions),'?'));
        $query = $db->prepare("SELECT 1 FROM portal_admin_permissions WHERE admin_id=? AND permission IN ($placeholders) LIMIT 1");
        $query->execute([$adminId,...array_values($permissions)]);
        if ($query->fetchColumn() === false) {
            throw new Problem('permission_denied', 403);
        }
    }
}
