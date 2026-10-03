<?php

namespace GeneroWP\StoreBalance;

/**
 * Slows down guessing of gift card codes.
 *
 * A code is 80 random bits, so guessing one is not realistic; this is here so
 * that an attempt to do it anyway is cut short and shows up in the log.
 *
 * Counted twice: per visitor (their account, or their session) and per IP
 * address. The visitor limit alone is no limit at all — a script simply drops
 * its cookie. The IP limit is higher, because a whole office or household can
 * sit behind one address.
 */
class Throttle
{
    public const VISITOR_LIMIT = 10;

    public const IP_LIMIT = 40;

    public const WINDOW = 10 * MINUTE_IN_SECONDS;

    public static function blocked(): bool
    {
        return (int) get_transient(self::visitorKey()) >= self::VISITOR_LIMIT
            || (int) get_transient(self::ipKey()) >= self::IP_LIMIT;
    }

    /**
     * Count a code that was refused for any reason: unknown, expired, spent,
     * or belonging to someone else. Each of those answers tells a guesser
     * something, so each one costs an attempt.
     */
    public static function hit(): void
    {
        foreach ([self::visitorKey() => self::VISITOR_LIMIT, self::ipKey() => self::IP_LIMIT] as $key => $limit) {
            $attempts = (int) get_transient($key) + 1;

            set_transient($key, $attempts, self::WINDOW);

            if ($attempts === $limit) {
                Logger::warning('Gift card code attempts throttled', [
                    'user_id' => get_current_user_id(),
                    'ip' => self::ip(),
                    'scope' => $key === self::ipKey() ? 'ip' : 'visitor',
                ]);
            }
        }
    }

    protected static function visitorKey(): string
    {
        $id = get_current_user_id();

        if (! $id && function_exists('WC') && WC()->session) {
            $id = (string) WC()->session->get_customer_id();
        }

        return 'wc_sb_try_v_'.md5((string) ($id ?: self::ip()));
    }

    protected static function ipKey(): string
    {
        return 'wc_sb_try_ip_'.md5(self::ip());
    }

    protected static function ip(): string
    {
        return class_exists(\WC_Geolocation::class) ? (string) \WC_Geolocation::get_ip_address() : '';
    }
}
