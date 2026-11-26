<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

/** Preserve ERP precision while keeping ordinary amounts readable with at least two decimal places. */
final class CommercialDecimal
{
    public static function format(int|float|string $amount, int $scale = 4): string
    {
        $decimal = number_format((float) $amount, $scale, '.', '');
        while (str_contains($decimal, '.') && strlen(substr(strrchr($decimal, '.'), 1)) > 2 && str_ends_with($decimal, '0')) {
            $decimal = substr($decimal, 0, -1);
        }
        return $decimal;
    }
}
