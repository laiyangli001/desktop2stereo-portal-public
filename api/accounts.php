<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\AccountService;
use D2S\services\Audit;
use D2S\services\BehaviorCaptcha;
use D2S\services\HttpRequest;
use D2S\services\PlatformSettings;
use D2S\services\SecretBox;
use D2S\services\VerificationService;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$now = time();
$context = Audit::context($_SERVER);
$context['policy_locale'] = d2s_locale_code();
$secrets = SecretBox::fromEnvironment();
$deliveryConfig = (new D2S\services\DeliverySettings($db,$secrets))->load();
$verification = new VerificationService($db, $secrets, $deliveryConfig);
$accounts = new AccountService($db, $verification, $deliveryConfig);
$sessions = new WebSessions($db);
$platformSettings = new PlatformSettings($db);
$captchaConfiguration = $platformSettings->captchaConfiguration();
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'POST' ? HttpRequest::json(true) : [];

if ($method === 'GET' && in_array($path, ['/api/v2/auth/captcha','/api/v2/admin/captcha'], true)) {
    header('Cache-Control: no-store, private');
    $realm = $path === '/api/v2/admin/captcha' ? 'admin' : 'user';
    $data = $captchaConfiguration['enabled']
        ? BehaviorCaptcha::generate($realm,$captchaConfiguration)
        : ['enabled'=>false];
} elseif ($method === 'POST' && $path === '/api/v2/auth/verification') {
    HttpRequest::strings($input, ['kind','contact','purpose']);
    $userId = null;
    if ($input['purpose'] === 'bind_contact') {
        $userId = HttpRequest::session('user', $sessions, true, $now)['id'];
    }
    $data = $verification->issue($input['kind'], $input['contact'], $input['purpose'], $userId, $context, $now);
} elseif ($method === 'POST' && $path === '/api/v2/auth/register') {
    HttpRequest::strings($input, ['username','password','kind','contact','challenge_id','code']);
    $input['invite_code']=is_string($_COOKIE['D2S_REFERRAL']??null)?$_COOKIE['D2S_REFERRAL']:'';
    $data = $accounts->register($input, $context, $now);
    http_response_code(201);
} elseif ($method === 'POST' && in_array($path, ['/api/v2/auth/login','/api/v2/admin/login'], true)) {
    HttpRequest::strings($input, ['identifier','password']);
    $realm = $path === '/api/v2/admin/login' ? 'admin' : 'user';
    if ($captchaConfiguration['enabled']) {
        $captchaId = $input['captcha_id'] ?? null;
        $captchaClicks = $input['captcha_clicks'] ?? null;
        if (!is_string($captchaId) || !is_array($captchaClicks) ||
            !BehaviorCaptcha::verify($realm, $captchaId, $captchaClicks)) {
            throw new Problem('captcha_invalid', 400);
        }
    }
    $session = $accounts->login($realm, $input['identifier'], $input['password'], $context, $now);
    HttpRequest::cookies($realm, $session);
    $data = ['account' => $session['account'], 'csrf_token' => $session['csrf_token'], 'expires_at' => $session['expires_at'],
        'consent_required' => $session['consent_required'] ?? false, 'consent_versions' => $session['consent_versions'] ?? []];
} elseif ($method === 'GET' && in_array($path, ['/api/v2/auth/session','/api/v2/admin/session'], true)) {
    $realm = $path === '/api/v2/admin/session' ? 'admin' : 'user';
    $owner = HttpRequest::session($realm, $sessions, false, $now);
    if ($realm === 'user') {
        $data = $accounts->profile($owner['id']);
    } else {
        $query = $db->prepare('SELECT permission FROM portal_admin_permissions WHERE admin_id=? ORDER BY permission');
        $query->execute([$owner['id']]);
        $data = ['id' => $owner['id'], 'username' => $owner['username'], 'permissions' => $query->fetchAll(PDO::FETCH_COLUMN)];
    }
} elseif ($method === 'POST' && in_array($path, ['/api/v2/auth/logout','/api/v2/admin/logout'], true)) {
    $realm = $path === '/api/v2/admin/logout' ? 'admin' : 'user';
    $owner = HttpRequest::session($realm, $sessions, true, $now, $realm === 'user');
    $cookie = $realm === 'admin' ? 'D2S_ADMIN_SESSION' : 'D2S_USER_SESSION';
    Database::transaction($db, static function (PDO $db) use ($sessions, $realm, $owner, $cookie, $context, $now): void {
        $sessions->revoke($_COOKIE[$cookie] ?? '');
        if ($realm === 'user') {
            Audit::user($db, $owner['id'], Audit::email($db, $owner['id']), 'LOGOUT', 'SUCCESS', $context, $now);
        } else {
            $query = $db->prepare('SELECT email FROM portal_admins WHERE id=?');
            $query->execute([$owner['id']]);
            Audit::admin($db, $owner['id'], (string)$query->fetchColumn(), 'LOGOUT_ADMIN', null, $context, 'SUCCESS', [], $now);
        }
    });
    HttpRequest::cookies($realm, null);
    $data = ['logged_out' => true];
} elseif ($method === 'POST' && $path === '/api/v2/auth/password-reset') {
    HttpRequest::strings($input, ['kind','contact','challenge_id','code','password']);
    $accounts->resetPassword($input['kind'], $input['contact'], $input['challenge_id'], $input['code'], $input['password'], $context, $now);
    $data = ['password_reset' => true];
} elseif ($method === 'POST' && in_array($path, ['/api/v2/me/locale','/api/v2/admin/locale'], true)) {
    $realm = $path === '/api/v2/admin/locale' ? 'admin' : 'user';
    HttpRequest::strings($input, ['locale']);
    $owner = HttpRequest::session($realm, $sessions, true, $now, $realm === 'user');
    $localeRegistry = new D2S\services\LocaleRegistry();
    $locale = $realm === 'admin'
        ? $localeRegistry->normalizeForAdmin($input['locale'])
        : $localeRegistry->normalize($input['locale'], true);
    if ($locale === null) {
        throw new Problem('locale_unavailable', 422);
    }
    $table = $realm === 'admin' ? 'portal_admins' : 'portal_users';
    $db->prepare("UPDATE $table SET preferred_locale=?,updated_at=? WHERE id=?")
        ->execute([$locale, $now, $owner['id']]);
    $data = ['preferred_locale'=>$locale];
} elseif ($method === 'POST' && $path === '/api/v2/me/consents') {
    $owner = HttpRequest::session('user', $sessions, true, $now, true);
    if (($input['legal_accepted'] ?? null) !== true) {
        throw new Problem('legal_consent_required');
    }
    $data = $accounts->acceptCurrentPolicies($owner['id'], $context, $now);
    $sessions->clearConsentRequirement($_COOKIE['D2S_USER_SESSION'] ?? '');
} elseif ($method === 'POST' && $path === '/api/v2/me/profile') {
    $owner = HttpRequest::session('user', $sessions, true, $now);
    HttpRequest::strings($input, ['display_name','region']);
    $data = $accounts->updateProfile($owner['id'], $input['display_name'], $input['region'], $context, $now);
} elseif ($method === 'POST' && $path === '/api/v2/me/password') {
    $owner = HttpRequest::session('user', $sessions, true, $now);
    HttpRequest::strings($input, ['current_password','new_password']);
    $accounts->changePassword($owner['id'], $input['current_password'], $input['new_password'], $context, $now);
    HttpRequest::cookies('user', null);
    $data = ['password_changed' => true, 'login_required' => true];
} elseif ($method === 'POST' && $path === '/api/v2/me/contact') {
    $owner = HttpRequest::session('user', $sessions, true, $now);
    HttpRequest::strings($input, ['kind','contact','challenge_id','code','current_password']);
    $accounts->bindContact($owner['id'], $input['kind'], $input['contact'], $input['challenge_id'], $input['code'], $input['current_password'], $context, $now);
    HttpRequest::cookies('user', null);
    $data = ['contact_verified' => true, 'login_required' => true];
} else {
    throw new Problem('not_found', 404);
}
echo json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
