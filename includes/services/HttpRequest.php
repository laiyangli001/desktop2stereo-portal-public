<?php
declare(strict_types=1);

namespace D2S\services;

use D2S\domain\Problem;

final class HttpRequest
{
    public static function json(bool $recursiveArrays = false, int $maxBytes = 32768): array
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new Problem('json_content_type_required', 415);
        }
        self::assertOrigin();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            throw new Problem('request_too_large', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            throw new Problem('request_too_large', 413);
        }
        try {
            $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new Problem('json_object_required');
            }
            return $recursiveArrays ? self::arrayValue($object) : (array)$object;
        } catch (\JsonException) {
            throw new Problem('json_invalid');
        }
    }

    private static function arrayValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array)$value;
        }
        if (is_array($value)) {
            foreach ($value as $key=>$item) {
                $value[$key] = self::arrayValue($item);
            }
        }
        return $value;
    }

    public static function assertOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $allowed = explode(',', getenv('D2S_ALLOWED_ORIGINS') ?: (getenv('D2S_APP_URL') ?: 'https://d2s.site'));
        if ($origin !== null && !in_array($origin, array_map('trim', $allowed), true)) {
            throw new Problem('origin_denied', 403);
        }
    }

    public static function strings(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key]) > 1024) {
                throw new Problem('request_field_invalid');
            }
        }
        return $input;
    }

    public static function session(string $realm, WebSessions $sessions, bool $write, int $now,
        bool $allowPendingConsent = false): array
    {
        $name = $realm === 'admin' ? 'D2S_ADMIN_SESSION' : 'D2S_USER_SESSION';
        return $sessions->authenticate($realm, $_COOKIE[$name] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $write, $now,
            $allowPendingConsent);
    }

    public static function cookies(string $realm, ?array $session): void
    {
        $name = $realm === 'admin' ? 'D2S_ADMIN_SESSION' : 'D2S_USER_SESSION';
        $secure = str_starts_with(getenv('D2S_APP_URL') ?: 'https://d2s.site', 'https://');
        $options = ['path' => '/', 'secure' => $secure, 'samesite' => 'Lax', 'expires' => $session['expires_at'] ?? 1];
        setcookie($name, $session['token'] ?? '', $options + ['httponly' => true]);
        setcookie($name . '_CSRF', $session['csrf_token'] ?? '', $options + ['httponly' => false]);
    }
}
