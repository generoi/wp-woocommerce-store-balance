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

    public const IP_LIMIT = 60;

    public const WINDOW = 10 * MINUTE_IN_SECONDS;

    public static function blocked(): bool
    {
        if ((int) get_transient(self::visitorKey()) >= self::VISITOR_LIMIT) {
            return true;
        }

        // A logged-in customer is held to their own limit only. The IP limit
        // exists to stop a script that keeps changing its cookie; applied to
        // accounts as well, it would let anyone on a shared address lock every
        // customer behind it out of their own gift cards.
        return ! get_current_user_id() && (int) get_transient(self::ipKey()) >= self::IP_LIMIT;
    }

    /**
     * Count a refused code.
     *
     * Every refusal counts against the visitor: "expired", "already in an
     * account" and "wrong currency" each tell a guesser that a code exists.
     * Only a code that does not exist counts against the IP address, so that
     * a household's honest mistakes with real cards do not add up to a lock
     * on everyone behind the same address.
     */
    public static function hit(bool $unknown = true): void
    {
        self::count(self::visitorKey(), self::VISITOR_LIMIT, 'visitor');

        if ($unknown) {
            self::count(self::ipKey(), self::IP_LIMIT, 'ip');
        }
    }

    /**
     * A fixed window: the count expires ten minutes after its first attempt.
     * Renewing the expiry on every attempt would let a trickle of requests
     * keep an address locked for good.
     */
    protected static function count(string $key, int $limit, string $scope): void
    {
        $started = (int) get_transient($key.'_t');

        if (! $started) {
            $started = time();
            set_transient($key.'_t', $started, self::WINDOW);
        }

        $attempts = (int) get_transient($key) + 1;

        set_transient($key, $attempts, max(1, $started + self::WINDOW - time()));

        if ($attempts === $limit) {
            Logger::warning('Gift card code attempts throttled', [
                'user_id' => get_current_user_id(),
                'ip' => self::ip(),
                'scope' => $scope,
            ]);
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
        $ip = class_exists(\WC_Geolocation::class) ? (string) \WC_Geolocation::get_ip_address() : '';

        /**
         * Filters the address attempts are counted against.
         *
         * WooCommerce takes it from X-Real-IP or X-Forwarded-For when they are
         * present. That is right behind a proxy that sets them, and wrong
         * behind one that passes the visitor's own headers through: there, a
         * script can claim a new address with every request. A site in that
         * position returns the address its proxy vouches for here.
         */
        return (string) apply_filters('wc_store_balance_client_ip', $ip);
    }
}
