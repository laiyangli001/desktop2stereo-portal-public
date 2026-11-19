<?php
declare(strict_types=1);
$userPath = isset($path) ? (string)$path : '';
$userCurrent = $userPath === '/feedback/new' ? '/feedback' : $userPath;
$userName = (string)($owner['username'] ?? 'Account');
$referralProgramEnabled ??= isset($db) && $db instanceof PDO
    && (bool)D2S\services\ReferralProgram::configuration($db)['enabled'];
$userPrimary = [
    ['/wallet', d2s_tr('accounts.nav.wallet'), '◇'],
    ['/notifications', d2s_tr('accounts.nav.notifications'), '≡'],
    ['/license', d2s_tr('accounts.nav.licenses'), '◎'],
    ['/referrals', d2s_tr('accounts.nav.referrals'), '↗'],
    ['/profile', d2s_tr('accounts.nav.profile'), '○'],
];
?>
<aside id="user-sidebar" class="user-nav user-sidebar" translate="no" aria-label="<?= d2s_escape(d2s_tr('accounts.nav.account')) ?>">
    <div class="user-sidebar-sticky">
        <div class="user-sidebar-heading">
            <span class="user-sidebar-avatar" aria-hidden="true"><?= d2s_escape(mb_substr($userName, 0, 1, 'UTF-8')) ?></span>
            <span class="user-sidebar-identity"><strong><?= d2s_escape($userName) ?></strong><small><?= d2s_escape(d2s_tr('accounts.nav.center')) ?></small></span>
            <button id="user-sidebar-toggle" class="user-sidebar-toggle" type="button" aria-controls="user-sidebar-menu" aria-expanded="false" aria-label="<?= d2s_escape(d2s_tr('accounts.nav.toggle')) ?>">☰</button>
        </div>
        <nav id="user-sidebar-menu" class="user-sidebar-menu" aria-label="<?= d2s_escape(d2s_tr('accounts.nav.functions')) ?>">
            <?php foreach ($userPrimary as $item): ?>
                <?php if ($item[0] === '/referrals' && !$referralProgramEnabled) continue; ?>
                <a class="user-nav-single<?= $userCurrent === $item[0] ? ' is-active' : '' ?>" href="<?= d2s_escape(d2s_url($item[0])) ?>"<?= $userCurrent === $item[0] ? ' aria-current="page"' : '' ?>>
                    <span class="user-nav-icon" aria-hidden="true"><?= d2s_escape($item[2]) ?></span><span class="user-nav-label"><?= d2s_escape($item[1]) ?></span>
                </a>
            <?php endforeach ?>

        </nav>
        <div class="user-sidebar-footer">
            <button id="logout" class="user-sidebar-logout" type="button" data-realm="user" data-redirect="<?= d2s_escape(d2s_url('/login')) ?>" data-error="<?= d2s_escape(d2s_tr('accounts.nav.sign_out_error')) ?>">
                <span class="user-sidebar-logout-icon" aria-hidden="true">⏻</span><span><?= d2s_escape(d2s_tr('accounts.nav.sign_out')) ?></span>
            </button>
            <p id="user-sidebar-status" class="user-sidebar-status" role="status" aria-live="polite"></p>
        </div>
    </div>
</aside>
<script src="<?= d2s_escape(d2s_asset('/assets/js/user-sidebar.js')) ?>" defer></script>
