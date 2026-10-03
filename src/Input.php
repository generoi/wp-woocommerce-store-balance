<?php

namespace GeneroWP\StoreBalance;

/**
 * Reading what a request sent. Anything can arrive under any key — an array
 * where a string is expected is the classic way to turn a form into a 500.
 */
class Input
{
    /**
     * The value as a string, or an empty string if it is not a scalar.
     */
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? self::clean((string) $value) : '';
    }

    /**
     * Without the invisible characters that reorder or hide text: the
     * bidirectional overrides and isolates, and zero-width marks. A message
     * that reads one way in an email and another way in the admin is a
     * phishing tool.
     */
    public static function clean(string $value): string
    {
        return preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $value) ?? $value;
    }

    /**
     * At most $length characters, cut on a character boundary.
     */
    public static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
