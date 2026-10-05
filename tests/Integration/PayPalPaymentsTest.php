<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Modules\Orders;

/**
 * WooCommerce PayPal Payments builds the amount it sends from the lines, not
 * from the total. What the balance pays has to reach it as a discount.
 */
class PayPalPaymentsTest extends TestCase
{
    public function test_the_cart_reports_what_the_balance_pays(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 50);
        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        WC()->cart->calculate_totals();

        $this->assertSame(50.0, apply_filters('woocommerce_paypal_payments_cart_extra_discount', 0.0, WC()->cart));
        $this->assertSame(12.5 + 50.0, apply_filters('woocommerce_paypal_payments_cart_extra_discount', 12.5, WC()->cart));
        $this->assertSame(5000, apply_filters('woocommerce_paypal_payments_store_api_cart_extra_discount', 0, WC()->cart));
    }

    public function test_a_cart_without_a_balance_reports_nothing(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());
        WC()->cart->calculate_totals();

        $this->assertSame(0.0, apply_filters('woocommerce_paypal_payments_cart_extra_discount', 0.0, WC()->cart));
        $this->assertSame(0, apply_filters('woocommerce_paypal_payments_store_api_cart_extra_discount', 0, WC()->cart));
    }

    public function test_the_order_reports_what_the_balance_paid_staged_or_taken(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 50);
        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->createOrderFromCart();
        $this->assertSame(50.0, apply_filters('woocommerce_paypal_payments_order_extra_discount', 0.0, $order));

        $order = $this->processOrder($order);
        $this->assertSame(50.0, Orders::held($order));
        $this->assertSame(50.0, apply_filters('woocommerce_paypal_payments_order_extra_discount', 0.0, $order));

        // Items + tax + shipping - discount is the total again.
        $lines = (float) $order->get_subtotal() + (float) $order->get_total_tax() + (float) $order->get_shipping_total();
        $this->assertEqualsWithDelta((float) $order->get_total(), $lines - 50.0, 0.005);
    }
}
