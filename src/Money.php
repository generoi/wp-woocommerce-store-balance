<?php

namespace GeneroWP\StoreBalance;

class Money
{
    /**
     * Round to the store's price decimals.
     *
     * Every amount that is compared or stored goes through here, so 0.1 + 0.2
     * never ends up as a balance of 0.30000000000000004.
     */
    public static function round(float|int|string $amount, ?int $decimals = null): float
    {
        $decimals ??= function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        return round((float) $amount, $decimals);
    }

    /**
     * The SQL literal for an amount. Passed as a string so MySQL does DECIMAL
     * arithmetic rather than float arithmetic.
     */
    public static function sql(float|int|string $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }

    public static function isPositive(float|int|string $amount): bool
    {
        return self::round($amount, 4) > 0;
    }

    /**
     * Parse an amount typed by a person: "50", "50,00", "1 000.50".
     *
     * Returns null for anything that is not a plain positive number, rather
     * than guessing.
     */
    public static function parse(mixed $input): ?float
    {
        if (is_int($input) || is_float($input)) {
            return $input > 0 ? self::round($input) : null;
        }

        if (! is_string($input)) {
            return null;
        }

        $value = preg_replace('/[\s\x{00A0}]+/u', '', trim($input)) ?? '';

        if ($value === '') {
            return null;
        }

        // "1.000,50" and "1,000.50": the last separator is the decimal one.
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
            $value = str_replace($decimal === ',' ? '.' : ',', '', $value);
        }

        $value = str_replace(',', '.', $value);

        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        $amount = self::round($value);

        return $amount > 0 ? $amount : null;
    }
}
