<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Code;
use GeneroWP\StoreBalance\Input;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\Throttle;
use WP_Error;

/**
 * Two pages in My Account: gift cards and store credit. Same engine, same
 * template, kept apart because they are different things to the customer —
 * one arrived as a gift with a code, the other is money the shop owes them.
 */
class Account implements Module
{
    public const ENDPOINT_GIFT_CARDS = 'gift-cards';

    public const ENDPOINT_STORE_CREDIT = 'store-credit';

    public const NONCE = 'wc_store_balance_redeem';

    public function register(): void
    {
        add_filter('woocommerce_get_query_vars', [$this, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems']);
        add_filter('woocommerce_endpoint_'.self::ENDPOINT_GIFT_CARDS.'_title', [$this, 'giftCardsTitle']);
        add_filter('woocommerce_endpoint_'.self::ENDPOINT_STORE_CREDIT.'_title', [$this, 'storeCreditTitle']);
        add_action('woocommerce_account_'.self::ENDPOINT_GIFT_CARDS.'_endpoint', [$this, 'giftCardsPage']);
        add_action('woocommerce_account_'.self::ENDPOINT_STORE_CREDIT.'_endpoint', [$this, 'storeCreditPage']);
        add_action('template_redirect', [$this, 'handleRedeem']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_before_customer_login_form', [$this, 'loginNotice']);
    }

    /**
     * Registering them as WooCommerce query vars is what makes them endpoints:
     * WooCommerce adds the rewrite rules and routes the My Account page.
     *
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    public function queryVars($vars)
    {
        $vars[self::ENDPOINT_GIFT_CARDS] = self::ENDPOINT_GIFT_CARDS;
        $vars[self::ENDPOINT_STORE_CREDIT] = self::ENDPOINT_STORE_CREDIT;

        return $vars;
    }

    /**
     * After Orders, where a customer looks for anything to do with money.
     *
     * @param  array<string, string>  $items
     * @return array<string, string>
     */
    public function menuItems($items)
    {
        $new = [
            self::ENDPOINT_GIFT_CARDS => __('Gift cards', 'wp-woocommerce-store-balance'),
            self::ENDPOINT_STORE_CREDIT => __('Store credit', 'wp-woocommerce-store-balance'),
        ];

        $position = array_search('orders', array_keys($items), true);

        if ($position === false) {
            return $items + $new;
        }

        return array_slice($items, 0, $position + 1, true) + $new + array_slice($items, $position + 1, null, true);
    }

    public function giftCardsTitle(): string
    {
        return __('Gift cards', 'wp-woocommerce-store-balance');
    }

    public function storeCreditTitle(): string
    {
        return __('Store credit', 'wp-woocommerce-store-balance');
    }

    public function assets(): void
    {
        if (function_exists('is_account_page') && is_account_page()) {
            wp_enqueue_style('wc-store-balance', Plugin::url('assets/frontend.css'), [], WC_STORE_BALANCE_VERSION);
        }
    }

    /**
     * The link in the gift card email leads here. Someone who is not logged in
     * gets a bare login form, with nothing to say why, or that an account is
     * optional.
     */
    public function loginNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to show a notice.
        if (empty($_GET['code']) || ! is_string($_GET['code']) || ! Code::isValid(wp_unslash($_GET['code']))) {
            return;
        }

        wc_print_notice(__('Log in to save your gift card to your account. No account? You do not need one: enter the code in the cart or at checkout under "Have a gift card?".', 'wp-woocommerce-store-balance'), 'notice');
    }

    public function giftCardsPage(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only pre-fills a form field.
        $code = isset($_GET['code']) && is_string($_GET['code']) ? Code::normalize(sanitize_text_field(wp_unslash($_GET['code']))) : '';

        Plugin::template('myaccount/balance.php', $this->pageArgs(Card::TYPE_GIFT_CARD) + [
            'prefill' => Code::isValid($code) ? Code::format($code) : '',
            'redeem_url' => wc_get_account_endpoint_url(self::ENDPOINT_GIFT_CARDS),
        ]);
    }

    public function storeCreditPage(): void
    {
        Plugin::template('myaccount/balance.php', $this->pageArgs(Card::TYPE_STORE_CREDIT) + [
            'prefill' => '',
            'redeem_url' => '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageArgs(string $type): array
    {
        $cards = Plugin::getInstance()->cards()->forCustomer(get_current_user_id(), $type);
        $balances = [];
        $active = [];
        $past = [];

        foreach ($cards as $card) {
            if ($card->isUsable()) {
                $balances[$card->currency] = Money::round(($balances[$card->currency] ?? 0) + $card->balance);
                $active[] = $card;
            } else {
                $past[] = $card;
            }
        }

        return [
            'type' => $type,
            'balances' => $balances,
            'active' => $active,
            'past' => $past,
            'transactions' => Plugin::getInstance()->cards()->transactions(array_map(static fn (Card $card) => $card->id, $cards), 25),
            'cards_by_id' => array_column($cards, null, 'id'),
            'shop_url' => wc_get_page_permalink('shop'),
        ];
    }

    /**
     * Add a gift card to the logged-in customer's account.
     */
    public function handleRedeem(): void
    {
        if (! isset($_POST['store_balance_redeem_code']) || ! is_user_logged_in()) {
            return;
        }

        $url = wc_get_account_endpoint_url(self::ENDPOINT_GIFT_CARDS);

        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE)) {
            wc_add_notice(__('Your session expired. Please try again.', 'wp-woocommerce-store-balance'), 'error');
            wp_safe_redirect($url);
            exit;
        }

        $result = $this->redeem(sanitize_text_field(Input::text(wp_unslash($_POST['store_balance_redeem_code']))), get_current_user_id());

        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        } else {
            wc_add_notice(sprintf(
                $result->currency === get_woocommerce_currency()
                    /* translators: %s: amount */
                    ? __('Gift card added. %s is now in your account and will be used at checkout automatically.', 'wp-woocommerce-store-balance')
                    /* translators: 1: amount, 2: currency code */
                    : __('Gift card added. %1$s is now in your account. It can be used for orders paid in %2$s.', 'wp-woocommerce-store-balance'),
                wc_price($result->balance, ['currency' => $result->currency]),
                $result->currency
            ), 'success');
        }

        wp_safe_redirect($url);
        exit;
    }

    public function redeem(string $input, int $userId): Card|WP_Error
    {
        $cards = Plugin::getInstance()->cards();

        if (Throttle::blocked()) {
            return new WP_Error('wc_store_balance_throttled', __('Too many attempts. Please wait ten minutes and try again.', 'wp-woocommerce-store-balance'));
        }

        if (trim($input) === '') {
            return new WP_Error('wc_store_balance_empty_code', __('Enter a gift card code.', 'wp-woocommerce-store-balance'));
        }

        $result = $this->claim($input, $userId);

        if (is_wp_error($result) && $result->get_error_code() !== 'wc_store_balance_in_account') {
            Throttle::hit();
        }

        return $result;
    }

    protected function claim(string $input, int $userId): Card|WP_Error
    {
        $cards = Plugin::getInstance()->cards();
        $card = $cards->findByCode($input);

        if (! $card || ! $card->isGiftCard() || ! $card->isActive()) {
            return new WP_Error('wc_store_balance_invalid_code', __('We could not find that gift card code. Please check it and try again.', 'wp-woocommerce-store-balance'));
        }

        if ($card->customerId === $userId) {
            return new WP_Error('wc_store_balance_in_account', __('This gift card is already in your account.', 'wp-woocommerce-store-balance'));
        }

        if ($card->isRedeemed()) {
            return new WP_Error('wc_store_balance_redeemed', __('This gift card has already been added to another account.', 'wp-woocommerce-store-balance'));
        }

        if ($card->isExpired()) {
            return new WP_Error('wc_store_balance_expired', sprintf(
                /* translators: %s: date */
                __('This gift card expired on %s.', 'wp-woocommerce-store-balance'),
                wp_date(wc_date_format(), $card->expiresAt)
            ));
        }

        if (! Money::isPositive($card->balance)) {
            return new WP_Error('wc_store_balance_empty', __('This gift card has no balance left.', 'wp-woocommerce-store-balance'));
        }

        // The UPDATE only matches an unclaimed card; losing a race lands here.
        if (! $cards->redeem($card->id, $userId)) {
            return new WP_Error('wc_store_balance_redeemed', __('This gift card has already been added to another account.', 'wp-woocommerce-store-balance'));
        }

        // If the code was sitting in the cart it is now part of the balance.
        $cart = Plugin::getInstance()->module(Cart::class);

        if ($cart) {
            $cart->removeCode($card->code);
        }

        return $cards->find($card->id) ?? $card;
    }
}
