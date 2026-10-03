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

    /**
     * A price as plain text, for an order note, an email subject or a log
     * line. wc_price() returns HTML; stripping the tags alone leaves
     * "50,00&nbsp;&euro;" behind.
     */
    public static function plain(float|int|string $amount, string $currency = ''): string
    {
        $html = wc_price((float) $amount, $currency !== '' ? ['currency' => $currency] : []);

        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Like parse(), but zero is an answer too: a balance can be set to nothing.
     */
    public static function parseAllowZero(mixed $input): ?float
    {
        if (is_string($input) && preg_match('/^\s*0+([.,]0*)?\s*$/', $input)) {
            return 0.0;
        }

        if ($input === 0 || $input === 0.0) {
            return 0.0;
        }

        return self::parse($input);
    }

    public static function isPositive(float|int|string $amount): bool
    {
        return self::round($amount, 4) > 0;
    }

    /**
     * Parse an amount typed by a person: "50", "50,00", "1 000.50", "50 €".
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

        $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($input)) ?? '';

        // A currency sign or code before or after the number: "50 €", "€50",
        // "50 EUR", "50 kr".
        $value = preg_replace('/^(?:[\p{Sc}]|[A-Za-z]{2,3}\.?)|(?:[\p{Sc}]|[A-Za-z]{2,3}\.?)$/u', '', $value) ?? '';

        if ($value === '') {
            return null;
        }

        // "1.000,50" and "1,000.50": the last separator is the decimal one.
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
            $value = str_replace($decimal === ',' ? '.' : ',', '', $value);
        }

        $value = str_replace(',', '.', $value);

        // "25." is someone who stopped typing; "25.999" is not an amount in a
        // currency with two decimals, and rounding it would charge a figure
        // the customer never entered.
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        if (! preg_match('/^\d+(\.\d{0,'.$decimals.'})?$/', $value)) {
            return null;
        }

        $amount = self::round($value);

        return $amount > 0 ? $amount : null;
    }
}
