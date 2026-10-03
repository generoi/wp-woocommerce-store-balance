<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Modules\Cart;
use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use GeneroWP\StoreBalance\Plugin;
use WC_Order;
use WC_Product_Simple;
use WC_Tax;
use WP_UnitTestCase;

/**
 * A shop like the one the plugin was built for: euros, prices that include
 * 25.5 % VAT, and a customer with a cart.
 *
 * Every test runs in a database transaction that is rolled back, so cards,
 * orders and options never leak from one test into the next. What does not
 * live in the database — the session, the cart, the state the Cart module
 * keeps from its last calculation — is reset here.
 */
abstract class TestCase extends WP_UnitTestCase
{
    protected CardRepository $cards;

    public function set_up(): void
    {
        parent::set_up();

        $this->cards = Plugin::getInstance()->cards();

        update_option('woocommerce_currency', 'EUR');
        update_option('woocommerce_default_country', 'FI');
        update_option('woocommerce_calc_taxes', 'yes');
        update_option('woocommerce_prices_include_tax', 'yes');
        update_option('woocommerce_tax_based_on', 'base');
        update_option('woocommerce_tax_display_cart', 'incl');
        update_option('woocommerce_enable_coupons', 'yes');

        WC_Tax::_insert_tax_rate([
            'tax_rate_country' => 'FI',
            'tax_rate_state' => '',
            'tax_rate' => '25.5000',
            'tax_rate_name' => 'ALV',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order' => 1,
            'tax_rate_class' => '',
        ]);

        add_filter('woocommerce_session_handler', static fn () => MemorySession::class);

        // WooCommerce created its cookie-based session while booting, and
        // that one still listens for a logout so it can clear its cookie.
        foreach ($GLOBALS['wp_filter']['wp_logout']->callbacks ?? [] as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof \WC_Session_Handler) {
                    remove_action('wp_logout', $callback['function'], $priority);
                }
            }
        }

        reset_phpmailer_instance();
        $_POST = [];

        $this->actAs(0);
    }

    public function tear_down(): void
    {
        $_POST = [];
        $this->actAs(0);

        parent::tear_down();
    }

    /**
     * Become a customer (or a guest, with 0) with a fresh session and an
     * empty cart.
     */
    protected function actAs(int $userId): void
    {
        wp_set_current_user($userId);

        WC()->session = null;
        WC()->customer = null;
        WC()->cart = null;
        WC()->initialize_session();
        WC()->initialize_cart();

        // The module remembers its last calculation; that belonged to
        // whoever was shopping before.
        $this->cart()->clearCodes();
    }

    protected function cart(): Cart
    {
        return Plugin::getInstance()->module(Cart::class);
    }

    /**
     * The balance state after a fresh calculation of the cart.
     *
     * @return array<string, mixed>
     */
    protected function state(): array
    {
        WC()->cart->calculate_totals();

        return $this->cart()->state();
    }

    protected function cartTotal(): float
    {
        WC()->cart->calculate_totals();

        return (float) WC()->cart->get_total('edit');
    }

    protected function customer(string $email = ''): int
    {
        return self::factory()->user->create(array_filter(['role' => 'customer', 'user_email' => $email]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function giftCard(float $amount = 50.0, array $data = []): Card
    {
        return $this->cards->create($data + [
            'type' => Card::TYPE_GIFT_CARD,
            'amount' => $amount,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function storeCredit(int $customerId, float $amount = 50.0, array $data = []): Card
    {
        return $this->cards->create($data + [
            'type' => Card::TYPE_STORE_CREDIT,
            'amount' => $amount,
            'currency' => 'EUR',
            'customer_id' => $customerId,
        ]);
    }

    protected function balance(Card|int $card): float
    {
        return $this->cards->find(is_int($card) ? $card : $card->id)->balance;
    }

    /**
     * The ledger of a card, oldest first.
     *
     * @return array<int, object>
     */
    protected function ledger(Card|int $card): array
    {
        return array_reverse($this->cards->transactions([is_int($card) ? $card : $card->id], 100));
    }

    /**
     * @return string[]
     */
    protected function ledgerTypes(Card|int $card): array
    {
        return array_map(static fn (object $row) => $row->type, $this->ledger($card));
    }

    /**
     * Goods: taxable, shipped, 189 including VAT unless told otherwise.
     */
    protected function product(float $price = 189.0): WC_Product_Simple
    {
        $product = new WC_Product_Simple;
        $product->set_name('Saga wide toe boot');
        $product->set_regular_price((string) $price);
        $product->set_status('publish');
        $product->save();

        return $product;
    }

    /**
     * @param  float[]  $amounts
     * @param  array<string, string>  $meta
     */
    protected function giftCardProduct(array $amounts = [25.0, 50.0, 100.0], bool $custom = true, array $meta = []): WC_Product_Simple
    {
        $product = new WC_Product_Simple;
        $product->set_name('E-gift card');
        $product->set_regular_price((string) ($amounts ? min($amounts) : 10));
        $product->set_status('publish');
        // Left taxable on purpose: the plugin has to override it.
        $product->set_tax_status('taxable');
        $product->update_meta_data(GiftCardProduct::META_ENABLED, 'yes');
        $product->update_meta_data(GiftCardProduct::META_AMOUNTS, $amounts);
        $product->update_meta_data(GiftCardProduct::META_CUSTOM, $custom ? 'yes' : 'no');
        $product->update_meta_data(GiftCardProduct::META_MIN, '10');
        $product->update_meta_data(GiftCardProduct::META_MAX, '500');

        foreach ($meta as $key => $value) {
            $product->update_meta_data($key, $value);
        }

        $product->save();

        return $product;
    }

    /**
     * Add a gift card to the cart the way the product page does: the posted
     * form goes through the add-to-cart validation, and what passed is what
     * ends up on the cart line.
     *
     * @param  array<string, string>  $input
     */
    protected function addGiftCardToCart(WC_Product_Simple $product, array $input = [], int $quantity = 1): bool
    {
        $_POST = $input + ['store_balance_amount' => '50'];

        $passed = (bool) apply_filters('woocommerce_add_to_cart_validation', true, $product->get_id(), $quantity);

        if ($passed) {
            WC()->cart->add_to_cart($product->get_id(), $quantity);
        }

        $_POST = [];

        return $passed;
    }

    /**
     * @return array<string, string>
     */
    protected function checkoutData(): array
    {
        return [
            'billing_first_name' => 'Aino',
            'billing_last_name' => 'Virtanen',
            'billing_email' => 'aino@example.org',
            'billing_address_1' => 'Katu 1',
            'billing_city' => 'Helsinki',
            'billing_postcode' => '00100',
            'billing_country' => 'FI',
            'payment_method' => 'cheque',
        ];
    }

    /**
     * Create the order from the cart, as the classic checkout does up to the
     * point where the plugin has written down what the balance pays for.
     * No card has been touched yet.
     *
     * @param  array<string, string>  $data
     */
    protected function createOrderFromCart(array $data = []): WC_Order
    {
        WC()->cart->calculate_totals();

        $orderId = WC()->checkout()->create_order($data + $this->checkoutData());

        $this->assertIsInt($orderId, is_wp_error($orderId) ? $orderId->get_error_message() : '');

        return wc_get_order($orderId);
    }

    /**
     * The rest of the classic checkout, up to the payment: this is where the
     * cards are debited.
     */
    protected function processOrder(WC_Order $order): WC_Order
    {
        do_action('woocommerce_checkout_order_processed', $order->get_id(), $this->checkoutData(), $order);

        return wc_get_order($order->get_id());
    }

    /**
     * Place an order for what is in the cart. It is left "pending payment",
     * where the gateway would take over.
     *
     * @param  array<string, string>  $data
     */
    protected function placeOrder(array $data = []): WC_Order
    {
        return $this->processOrder($this->createOrderFromCart($data));
    }

    /**
     * A logged-in customer with store credit and the boots in the cart, then
     * through the checkout.
     *
     * @return array{0: WC_Order, 1: Card, 2: int} the order, the card, the customer
     */
    protected function orderPaidPartlyWithStoreCredit(float $credit = 50.0, float $price = 189.0): array
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, $credit);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product($price)->get_id());

        return [$this->placeOrder(), $card, $customerId];
    }

    /**
     * Subjects and recipients of every email sent during the test.
     *
     * @return array<int, object>
     */
    protected function sentEmails(): array
    {
        $mailer = tests_retrieve_phpmailer_instance();
        $sent = [];

        for ($i = 0; $mail = $mailer->get_sent($i); $i++) {
            $sent[] = $mail;
        }

        return $sent;
    }

    /**
     * @return array<int, object>
     */
    protected function emailsTo(string $address): array
    {
        return array_values(array_filter(
            $this->sentEmails(),
            static fn (object $mail) => in_array($address, array_column($mail->to, 0), true)
        ));
    }
}
