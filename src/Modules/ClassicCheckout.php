<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Input;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Plugin;

/**
 * The shortcode cart and checkout: the same state as the blocks, drawn with
 * the classic templates and three wc-ajax endpoints.
 */
class ClassicCheckout implements Module
{
    public const NONCE = 'wc_store_balance_cart';

    public function register(): void
    {
        add_action('woocommerce_cart_totals_before_order_total', [$this, 'totalsRows']);
        add_action('woocommerce_review_order_before_order_total', [$this, 'totalsRows']);

        add_action('woocommerce_proceed_to_checkout', [$this, 'form'], 5);
        add_action('woocommerce_review_order_before_payment', [$this, 'form']);

        add_action('wc_ajax_store_balance_apply', [$this, 'ajaxApply']);
        add_action('wc_ajax_store_balance_remove', [$this, 'ajaxRemove']);
        add_action('wc_ajax_store_balance_use_balance', [$this, 'ajaxUseBalance']);

        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        if (! function_exists('is_cart') || ! (is_cart() || is_checkout())) {
            return;
        }

        // The block versions of these pages bring their own script.
        if (has_block('woocommerce/cart') || has_block('woocommerce/checkout')) {
            return;
        }

        wp_enqueue_style('wc-store-balance', Plugin::url('assets/frontend.css'), [], WC_STORE_BALANCE_VERSION);
        wp_enqueue_script('wc-store-balance-classic', Plugin::url('assets/classic.js'), ['jquery'], WC_STORE_BALANCE_VERSION, ['in_footer' => true]);
        wp_localize_script('wc-store-balance-classic', 'wcStoreBalance', [
            'url' => \WC_AJAX::get_endpoint('%%endpoint%%'),
            'nonce' => wp_create_nonce(self::NONCE),
            'error' => __('Something went wrong. Please try again.', 'wp-woocommerce-store-balance'),
        ]);
    }

    public function totalsRows(): void
    {
        Logger::guard('Classic totals rows', function (): void {
            $cart = Plugin::getInstance()->module(Cart::class);
            $state = $cart ? $cart->state() : null;

            if (! $state || $state['applied_total'] <= 0) {
                return;
            }

            printf(
                '<tr class="store-balance-total"><th>%s</th><td data-title="%s">&minus;%s</td></tr>',
                esc_html(Orders::label($state['lines'])),
                esc_attr(Orders::label($state['lines'])),
                wp_kses_post(wc_price($state['applied_total']))
            );
        });
    }

    public function form(): void
    {
        Logger::guard('Classic balance form', function (): void {
            $cart = Plugin::getInstance()->module(Cart::class);

            if ($cart) {
                Plugin::template('checkout/balance-form.php', ['state' => $cart->state()]);
            }
        });
    }

    public function ajaxApply(): void
    {
        $cart = $this->cart();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in cart().
        $result = $cart->applyCode(sanitize_text_field(Input::text(wp_unslash($_POST['code'] ?? ''))));

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $this->done($cart, __('Gift card applied.', 'wp-woocommerce-store-balance'));
    }

    public function ajaxRemove(): void
    {
        $cart = $this->cart();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in cart().
        $cart->removeCard(absint(Input::text($_POST['id'] ?? '')));

        $this->done($cart, __('Gift card removed.', 'wp-woocommerce-store-balance'));
    }

    public function ajaxUseBalance(): void
    {
        $cart = $this->cart();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in cart().
        $cart->setUseBalance(! empty($_POST['value']));

        $this->done($cart, '');
    }

    /**
     * The form itself is not part of what WooCommerce redraws after
     * "update_checkout", so the answer carries the form as it now is.
     *
     * @return never
     */
    protected function done(Cart $cart, string $message): void
    {
        WC()->cart->calculate_totals();

        wp_send_json_success([
            'message' => $message,
            'html' => Plugin::template('checkout/balance-form.php', ['state' => $cart->state()], true),
        ]);
    }

    protected function cart(): Cart
    {
        $cart = Plugin::getInstance()->module(Cart::class);

        if (! $cart || ! check_ajax_referer(self::NONCE, 'security', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload the page and try again.', 'wp-woocommerce-store-balance')]);
        }

        return $cart;
    }
}
