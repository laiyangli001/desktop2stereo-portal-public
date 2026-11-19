<?php
declare(strict_types=1);

namespace D2S\domain;

final class Money
{
    public const MAXIMUM = 1000000000000;

    public static function currency(string $currency): string
    {
        if (!in_array($currency, ['CNY','USD'], true)) {
            throw new Problem('currency_invalid');
        }
        return $currency;
    }

    public static function decimal(string $value): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D',$value,$parts)) {
            throw new Problem('amount_decimal_invalid');
        }
        return self::positive((int)$parts[1]*100+(int)str_pad($parts[2] ?? '',2,'0'));
    }

    public static function positive(mixed $amount): int
    {
        if (!is_int($amount) || $amount < 1 || $amount > self::MAXIMUM) {
            throw new Problem('amount_minor_units_required');
        }
        return $amount;
    }
}
