<?php

/**
 * The public API. Code outside this plugin — a returns flow, an import, a
 * loyalty rule — should call these rather than the classes.
 */

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\StoreCredit;

if (! function_exists('wc_store_balance_issue_store_credit')) {
    /**
     * Put store credit on a customer account.
     *
     * @param  array{note?: string, order_id?: int, expires_at?: int|null, send_email?: bool}  $args
     */
    function wc_store_balance_issue_store_credit(int $customerId, float $amount, string $currency = '', array $args = []): Card|WP_Error
    {
        return StoreCredit::issue($customerId, $amount, $currency, $args);
    }
}

if (! function_exists('wc_store_balance_refund_order_to_store_credit')) {
    /**
     * Refund an order, or part of it, as store credit in the order's currency.
     * A guest order gets a customer account.
     */
    function wc_store_balance_refund_order_to_store_credit(WC_Order $order, float $amount, string $note = ''): Card|WP_Error
    {
        return StoreCredit::refundOrder($order, $amount, $note);
    }
}

if (! function_exists('wc_store_balance_get_customer_balance')) {
    /**
     * What a customer can spend in one currency.
     *
     * @param  string|null  $type  Card::TYPE_GIFT_CARD, Card::TYPE_STORE_CREDIT, or null for both.
     */
    function wc_store_balance_get_customer_balance(int $customerId, string $currency = '', ?string $type = null): float
    {
        $currency = strtoupper($currency ?: get_woocommerce_currency());
        $total = 0.0;

        foreach (Plugin::getInstance()->cards()->forCustomer($customerId, $type) as $card) {
            if ($card->currency === $currency && $card->isUsable()) {
                $total += $card->balance;
            }
        }

        return Money::round($total);
    }
}
