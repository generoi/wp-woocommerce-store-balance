<?php

namespace GeneroWP\StoreBalance;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use GeneroWP\StoreBalance\Modules\Account;
use GeneroWP\StoreBalance\Modules\Blocks;

/**
 * Loads the script that draws the balance UI inside the cart and checkout
 * blocks.
 *
 * The script is written against the globals WooCommerce exposes (wp.element,
 * wc.blocksCheckout) rather than compiled, so the plugin ships without a build
 * step and without a second copy of React.
 */
class BlocksIntegration implements IntegrationInterface
{
    public const HANDLE = 'wc-store-balance-blocks';

    public function get_name(): string
    {
        return Blocks::NAMESPACE;
    }

    public function initialize(): void
    {
        wp_register_script(
            self::HANDLE,
            Plugin::url('assets/blocks.js'),
            ['wc-blocks-checkout', 'wc-price-format', 'wc-settings', 'wp-element', 'wp-plugins', 'wp-data', 'wp-a11y'],
            WC_STORE_BALANCE_VERSION,
            ['in_footer' => true]
        );

        wp_register_style('wc-store-balance-blocks', Plugin::url('assets/blocks.css'), [], WC_STORE_BALANCE_VERSION);

        add_action('wp_enqueue_scripts', static function (): void {
            if (wp_script_is(self::HANDLE, 'enqueued') || has_block('woocommerce/cart') || has_block('woocommerce/checkout')) {
                wp_enqueue_style('wc-store-balance-blocks');
            }
        }, 20);
    }

    /**
     * @return string[]
     */
    public function get_script_handles(): array
    {
        return [self::HANDLE];
    }

    /**
     * @return string[]
     */
    public function get_editor_script_handles(): array
    {
        return [];
    }

    /**
     * Strings are passed from PHP rather than translated in the script: with no
     * build step there are no JSON translation files to load.
     *
     * @return array<string, mixed>
     */
    public function get_script_data(): array
    {
        return [
            'namespace' => Blocks::NAMESPACE,
            'accountUrl' => wc_get_account_endpoint_url(Account::ENDPOINT_GIFT_CARDS),
            'loginUrl' => wc_get_page_permalink('myaccount'),
            'strings' => [
                'panelTitle' => __('Have a gift card?', 'wp-woocommerce-store-balance'),
                'inputLabel' => __('Gift card code', 'wp-woocommerce-store-balance'),
                'apply' => __('Apply', 'wp-woocommerce-store-balance'),
                'applying' => __('Applying…', 'wp-woocommerce-store-balance'),
                'emptyCode' => __('Enter a gift card code.', 'wp-woocommerce-store-balance'),
                'applied' => __('Gift card applied.', 'wp-woocommerce-store-balance'),
                'removed' => __('Gift card removed.', 'wp-woocommerce-store-balance'),
                /* translators: %s: masked gift card code */
                'remove' => __('Remove gift card %s', 'wp-woocommerce-store-balance'),
                'removeShort' => __('Remove', 'wp-woocommerce-store-balance'),
                /* translators: %s: masked gift card code */
                'giftCard' => __('Gift card %s', 'wp-woocommerce-store-balance'),
                /* translators: 1: amount used, 2: amount left */
                'usedLeft' => __('%1$s used · %2$s left on the card', 'wp-woocommerce-store-balance'),
                'notUsed' => __('Not used: the order is already covered', 'wp-woocommerce-store-balance'),
                /* translators: %s: amount */
                'useBalance' => __('Pay with my balance (%s available)', 'wp-woocommerce-store-balance'),
                /* translators: 1: gift card amount, 2: store credit amount */
                'breakdown' => __('%1$s in gift cards, %2$s in store credit', 'wp-woocommerce-store-balance'),
                /* translators: 1: amount used, 2: amount left */
                'accountUsedLeft' => __('%1$s used on this order · %2$s stays in your account', 'wp-woocommerce-store-balance'),
                'accountNotNeeded' => __('Not used: the order is already covered', 'wp-woocommerce-store-balance'),
                'accountSaved' => __('Saved for later. Tick to use it on this order.', 'wp-woocommerce-store-balance'),
                'balanceOn' => __('Your balance is used on this order.', 'wp-woocommerce-store-balance'),
                'balanceOff' => __('Your balance is not used on this order.', 'wp-woocommerce-store-balance'),
                /* translators: %s: list of amounts in other currencies */
                'otherCurrencies' => __('You also have %s, which can be used for orders in that currency.', 'wp-woocommerce-store-balance'),
                'onlyGiftCards' => __('Gift cards and store credit cannot be used to buy gift cards.', 'wp-woocommerce-store-balance'),
                /* translators: %s: amount */
                'excluded' => __('The gift card in your cart (%s) cannot be paid with a gift card or store credit, so that part is paid another way.', 'wp-woocommerce-store-balance'),
                'toPay' => __('Left to pay', 'wp-woocommerce-store-balance'),
                'covered_giftcard' => __('Your gift card covers this order. Nothing more to pay.', 'wp-woocommerce-store-balance'),
                'covered_store_credit' => __('Your store credit covers this order. Nothing more to pay.', 'wp-woocommerce-store-balance'),
                'covered_both' => __('Your gift card and store credit cover this order. Nothing more to pay.', 'wp-woocommerce-store-balance'),
                'genericError' => __('Something went wrong. Please try again.', 'wp-woocommerce-store-balance'),
            ],
        ];
    }
}
