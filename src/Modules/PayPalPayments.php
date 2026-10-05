<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Plugin;
use WC_Order;

/**
 * WooCommerce PayPal Payments.
 *
 * PayPal is sent an itemised amount: items, shipping, tax, discount. The
 * gateway builds it from the lines of the cart or order rather than from the
 * total, so a balance, which only lowers the total, is not in it: the shopper
 * would approve the full price in the PayPal window, or the sum would be
 * forced to match with a negative tax. The gateway has hooks for exactly this
 * (it uses them for WooCommerce Gift Cards); what the balance pays is reported
 * through them as a discount.
 */
class PayPalPayments implements Module
{
    public function register(): void
    {
        add_filter('woocommerce_paypal_payments_cart_extra_discount', [$this, 'cart'], 20);
        add_filter('woocommerce_paypal_payments_store_api_cart_extra_discount', [$this, 'cartMinorUnits'], 20);
        add_filter('woocommerce_paypal_payments_order_extra_discount', [$this, 'order'], 20, 2);
    }

    protected function applied(): float
    {
        $cart = Plugin::getInstance()->module(Cart::class);

        return $cart ? (float) ($cart->state()['applied_total'] ?? 0) : 0.0;
    }

    /**
     * @param  mixed  $extra
     */
    public function cart($extra): float
    {
        return (float) $extra + $this->applied();
    }

    /**
     * @param  mixed  $extra  in the currency's smallest unit
     */
    public function cartMinorUnits($extra): int
    {
        return (int) $extra + (int) round($this->applied() * 10 ** wc_get_price_decimals());
    }

    /**
     * @param  mixed  $extra
     * @param  mixed  $order
     */
    public function order($extra, $order = null): float
    {
        return (float) $extra + ($order instanceof WC_Order ? Orders::deducted($order) : 0.0);
    }
}
