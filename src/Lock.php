<?php

namespace GeneroWP\StoreBalance;

/**
 * One order, one process at a time.
 *
 * A payment gateway's webhook and the customer's return to the shop arrive
 * together; so do a cron run and an admin's click. Each reads "this order has
 * not been handled yet" and each handles it — returning a balance twice, or
 * issuing a gift card twice. A named database lock makes the second one wait,
 * and by the time it gets in, the first has written down what it did.
 */
class Lock
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function order(int $orderId, callable $callback): mixed
    {
        global $wpdb;

        // Per site: several sites can share one database server.
        $name = 'wc_sb_'.md5($wpdb->prefix.'order'.$orderId);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 15)) === 1;

        if (! $locked) {
            // Going ahead unlocked would bring back the race; refusing would
            // strand the order. The callers re-read their state under the
            // lock and write it as they go, so the damage of running anyway is
            // bounded — but it should never happen, and has to be seen if it does.
            Logger::error('Could not get the lock for an order', ['order_id' => $orderId]);
        }

        try {
            return $callback();
        } finally {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }
}
