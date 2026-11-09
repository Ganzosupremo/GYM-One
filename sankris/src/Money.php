<?php
declare(strict_types=1);

namespace Sankris;

/** Money is handled in integer cents internally to avoid float rounding. */
final class Money
{
    public static function toCents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function format($amount, string $currency = 'GTQ'): string
    {
        $symbol = ['GTQ' => 'Q', 'USD' => 'US$'][$currency] ?? ($currency . ' ');
        return $symbol . number_format((float) $amount, 2);
    }
}
