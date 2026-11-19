<?php
declare(strict_types=1);

namespace D2S\domain;

final class AccountInput
{
    public static function username(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^(?![0-9]+$)[a-z0-9_-]{3,32}$/D', $value)) {
            throw new Problem('username_invalid');
        }
        return $value;
    }

    public static function contact(string $kind, string $value): string
    {
        $value = trim($value);
        if ($kind === 'email') {
            $value = strtolower($value);
            if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL) ||
                preg_match('/[^\x20-\x7e]/', $value)) {
                throw new Problem('email_invalid');
            }
            return $value;
        }
        if ($kind === 'phone') {
            if (preg_match('/^1[3-9][0-9]{9}$/D', $value)) {
                $value = '+86' . $value;
            }
            if (!preg_match('/^\+861[3-9][0-9]{9}$/D', $value)) {
                throw new Problem('phone_invalid');
            }
            return $value;
        }
        throw new Problem('contact_kind_invalid');
    }

    public static function identifier(string $value): array
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            return ['email', self::contact('email', $value)];
        }
        if (preg_match('/^(?:\+86)?[0-9]+$/D', $value)) {
            return ['phone', self::contact('phone', $value)];
        }
        return ['username', self::username($value)];
    }

    public static function password(string $value, bool $admin = false): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') < 6 || strlen($value) > 64 ||
            str_contains($value, "\0") ||
            ($admin && (!preg_match('/[a-z]/', $value) || !preg_match('/[A-Z]/', $value) ||
                !preg_match('/[0-9]/', $value)))) {
            throw new Problem('password_policy');
        }
        return $value;
    }

    public static function region(string $value): string
    {
        if (!in_array($value, ['CN', 'INTL'], true)) {
            throw new Problem('region_invalid');
        }
        return $value;
    }

    public static function displayName(string $value): string
    {
        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 80 ||
            preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new Problem('display_name_invalid');
        }
        return $value;
    }
}
