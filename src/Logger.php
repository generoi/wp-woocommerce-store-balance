<?php

namespace GeneroWP\StoreBalance;

use Throwable;

/**
 * Everything that goes wrong is written to WooCommerce's log under one source,
 * readable in WooCommerce → Status → Logs. The point is to be able to find out
 * afterwards why a balance was refused or a card was not issued.
 */
class Logger
{
    public const SOURCE = 'wp-woocommerce-store-balance';

    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        try {
            wc_get_logger()->log($level, $message, ['source' => self::SOURCE] + $context);
        } catch (Throwable $e) {
            // A logger that cannot write must not take the request down with it.
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function exception(Throwable $e, string $where, array $context = []): void
    {
        self::error(sprintf('%s: %s', $where, $e->getMessage()), $context + [
            'exception' => get_class($e),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Run a hook callback that must never break the page it runs on.
     *
     * Used for display and for the cart total filter: a bug here should cost
     * the customer the balance feature for one request, not the checkout.
     * Anything that moves money does not go through this — those errors have
     * to surface.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    public static function guard(string $where, callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            self::exception($e, $where);

            return $fallback;
        }
    }
}
