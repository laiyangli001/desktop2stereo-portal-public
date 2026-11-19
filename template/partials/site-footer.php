<?php
declare(strict_types=1);
$footerSite = $siteData['site'];
$footerIcp = (string)($footerSite['icp_site_number'] ?? '');
$footerTopIcp = (string)($footerSite['icp_top_number'] ?? '');
$footerPublicSecurity = (string)($footerSite['public_security_number'] ?? '');
$footerPublicSecurityUrl = (string)($footerSite['public_security_url'] ?? '');
if ($footerPublicSecurityUrl === '') {
    $footerPublicSecurityUrl = 'https://beian.mps.gov.cn/';
}
?>
<footer class="site-footer" translate="no">
    <nav aria-label="<?= d2s_escape(d2s_tr('common.footer.navigation')) ?>">
        <a href="<?= d2s_escape(d2s_url('/about')) ?>"><?= d2s_escape(d2s_tr('common.footer.about')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/contact')) ?>"><?= d2s_escape(d2s_tr('common.footer.contact')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/account-recovery')) ?>"><?= d2s_escape(d2s_tr('common.footer.recovery')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/terms')) ?>"><?= d2s_escape(d2s_tr('common.footer.terms')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/privacy-policy')) ?>"><?= d2s_escape(d2s_tr('common.footer.privacy')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/legal-statement')) ?>"><?= d2s_escape(d2s_tr('common.footer.legal')) ?></a>
        <a href="<?= d2s_escape(d2s_url('/business-license')) ?>"><?= d2s_escape(d2s_tr('common.footer.license')) ?></a>
    </nav>
    <div class="legal-records">
        <?php if ($footerIcp !== ''): ?>
            <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= d2s_escape($footerIcp) ?> · d2s.site</a>
        <?php endif ?>
        <?php if ($footerTopIcp !== ''): ?>
            <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= d2s_escape($footerTopIcp) ?> · d2s.top</a>
        <?php endif ?>
        <?php if ((string)($footerSite['icp_license_number'] ?? '') !== ''): ?>
            <span><?= d2s_escape(d2s_tr('common.footer.icp')) ?>：<?= d2s_escape($footerSite['icp_license_number']) ?></span>
        <?php endif ?>
        <?php if ($footerPublicSecurity !== ''): ?>
            <a href="<?= d2s_escape($footerPublicSecurityUrl) ?>" target="_blank" rel="noopener noreferrer"><?= d2s_escape($footerPublicSecurity) ?></a>
        <?php endif ?>
    </div>
    <p class="copyright">© <?= date('Y') ?> Desktop2Stereo · <?= d2s_escape(d2s_tr('common.footer.copyright')) ?></p>
</footer>
