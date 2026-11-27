<?php

namespace App\Modules\Billing\Support;

/**
 * Money maths in integer cents, so sums of decimal amounts never drift.
 */
final class Cents
{
    public static function from(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
