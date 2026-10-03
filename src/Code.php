<?php

namespace GeneroWP\StoreBalance;

class Code
{
    /**
     * No I, O, 0 or 1: the code is read off an email and typed by hand.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 16;

    /**
     * 16 characters from a 32-character alphabet is 80 bits, drawn from the
     * CSPRNG. Guessing one is not a realistic attack; the attempt limit in the
     * cart is there for the log, not for the entropy.
     */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * What is stored and compared: uppercase, no separators.
     *
     * People paste codes with the dashes, with spaces, in lowercase, and with a
     * trailing newline from the email.
     */
    public static function normalize(string $input): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($input)) ?? '';
    }

    public static function isValid(string $input): bool
    {
        $code = self::normalize($input);

        return strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH;
    }

    /**
     * XXXX-XXXX-XXXX-XXXX, as shown to the person who owns it.
     */
    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), 4));
    }

    /**
     * Shown wherever someone other than the owner might see it — order emails,
     * the admin order screen, the cart of a shared computer.
     */
    public static function mask(string $code): string
    {
        $code = self::normalize($code);

        return '••••-'.substr($code, -4);
    }
}
