<?php

namespace GeneroWP\StoreBalance\Modules;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use WC_Product;

/**
 * Turns a simple product into a gift card: the customer picks an amount, says
 * who it is for, and the amount becomes the price of that cart line.
 */
class GiftCardProduct implements Module
{
    public const META_ENABLED = '_store_balance_gift_card';

    public const META_AMOUNTS = '_store_balance_amounts';

    public const META_CUSTOM = '_store_balance_custom_amount';

    public const META_MIN = '_store_balance_min_amount';

    public const META_MAX = '_store_balance_max_amount';

    public const META_EXPIRY = '_store_balance_expiry_days';

    /** The key the gift card details live under on a cart item. */
    public const CART_KEY = 'store_balance_gift_card';

    public const MESSAGE_LENGTH = 500;

    /** @var array<string, mixed>|null Validated form input, carried from validation to the cart item. */
    protected ?array $validated = null;

    public function register(): void
    {
        // Product editor.
        add_filter('product_type_options', [$this, 'typeOption']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'productFields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProduct']);

        // What a gift card is, to the rest of WooCommerce.
        add_filter('woocommerce_product_get_tax_status', [$this, 'taxStatus'], 20, 2);
        add_filter('woocommerce_is_virtual', [$this, 'isVirtual'], 20, 2);
        add_filter('woocommerce_product_supports', [$this, 'supports'], 20, 3);
        add_filter('woocommerce_product_add_to_cart_url', [$this, 'addToCartUrl'], 20, 2);
        add_filter('woocommerce_product_add_to_cart_text', [$this, 'addToCartText'], 20, 2);
        add_filter('woocommerce_get_price_html', [$this, 'priceHtml'], 20, 2);
        add_filter('woocommerce_coupon_is_valid_for_product', [$this, 'couponValidForProduct'], 20, 2);
        add_filter('woocommerce_order_item_needs_processing', [$this, 'needsProcessing'], 20, 2);

        // Product page and cart.
        add_action('woocommerce_before_add_to_cart_button', [$this, 'form']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate'], 20, 3);
        add_filter('woocommerce_add_cart_item_data', [$this, 'cartItemData'], 20, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'setPrices'], 20);
        add_filter('woocommerce_get_item_data', [$this, 'itemData'], 20, 2);
        add_action('woocommerce_store_api_validate_add_to_cart', [$this, 'validateStoreApi'], 20, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'orderLineItem'], 20, 3);
    }

    public static function isGiftCard($product): bool
    {
        return $product instanceof WC_Product
            && $product->is_type('simple')
            && $product->get_meta(self::META_ENABLED) === 'yes';
    }

    /**
     * The amounts offered, in the currency the shop is showing.
     *
     * @return float[]
     */
    public static function amounts(WC_Product $product): array
    {
        $amounts = $product->get_meta(self::META_AMOUNTS);
        $amounts = is_array($amounts) ? array_values(array_filter(array_map('floatval', $amounts), static fn ($a) => $a > 0)) : [];

        /**
         * Filters the preset amounts of a gift card product.
         *
         * The stored amounts are plain numbers with no currency. A shop with
         * several currencies returns the right set for each one here.
         *
         * @param  float[]  $amounts
         * @param  WC_Product  $product
         * @param  string  $currency
         */
        $amounts = (array) apply_filters('wc_store_balance_gift_card_amounts', $amounts, $product, get_woocommerce_currency());

        sort($amounts);

        return array_values(array_unique(array_map(static fn ($a) => Money::round($a), $amounts)));
    }

    /**
     * @return array{enabled: bool, min: float, max: float}
     */
    public static function customAmount(WC_Product $product): array
    {
        $custom = [
            'enabled' => $product->get_meta(self::META_CUSTOM) === 'yes',
            'min' => (float) ($product->get_meta(self::META_MIN) ?: 5),
            'max' => (float) ($product->get_meta(self::META_MAX) ?: 1000),
        ];

        /**
         * Filters the custom amount limits of a gift card product, for the same
         * reason as the preset amounts: they depend on the currency.
         *
         * @param  array{enabled: bool, min: float, max: float}  $custom
         * @param  WC_Product  $product
         * @param  string  $currency
         */
        return (array) apply_filters('wc_store_balance_gift_card_custom_amount', $custom, $product, get_woocommerce_currency());
    }

    /**
     * @param  array<string, array<string, mixed>>  $options
     * @return array<string, array<string, mixed>>
     */
    public function typeOption($options)
    {
        $options['store_balance_gift_card'] = [
            'id' => self::META_ENABLED,
            'wrapper_class' => 'show_if_simple',
            'label' => __('Gift card', 'wp-woocommerce-store-balance'),
            'description' => __('Sell this product as a gift card: the customer chooses the amount and who receives it.', 'wp-woocommerce-store-balance'),
            'default' => 'no',
        ];

        return $options;
    }

    public function productFields(): void
    {
        global $product_object;

        if (! $product_object instanceof WC_Product) {
            return;
        }

        $amounts = $product_object->get_meta(self::META_AMOUNTS);

        echo '<div class="options_group store-balance-gift-card-options" style="display:none">';

        woocommerce_wp_text_input([
            'id' => self::META_AMOUNTS,
            'label' => sprintf(
                /* translators: %s: currency symbol */
                __('Amounts (%s)', 'wp-woocommerce-store-balance'),
                get_woocommerce_currency_symbol()
            ),
            'value' => is_array($amounts) ? implode(', ', array_map('wc_format_localized_decimal', $amounts)) : '',
            'placeholder' => '25, 50, 100',
            'description' => __('The amounts the customer can choose from, separated by commas.', 'wp-woocommerce-store-balance'),
            'desc_tip' => true,
        ]);

        woocommerce_wp_checkbox([
            'id' => self::META_CUSTOM,
            'label' => __('Custom amount', 'wp-woocommerce-store-balance'),
            'description' => __('Let the customer enter their own amount.', 'wp-woocommerce-store-balance'),
            'value' => $product_object->get_meta(self::META_CUSTOM) === 'yes' ? 'yes' : 'no',
        ]);

        woocommerce_wp_text_input([
            'id' => self::META_MIN,
            'label' => __('Smallest custom amount', 'wp-woocommerce-store-balance'),
            'value' => $product_object->get_meta(self::META_MIN) ?: '5',
            'data_type' => 'price',
        ]);

        woocommerce_wp_text_input([
            'id' => self::META_MAX,
            'label' => __('Largest custom amount', 'wp-woocommerce-store-balance'),
            'value' => $product_object->get_meta(self::META_MAX) ?: '1000',
            'data_type' => 'price',
        ]);

        woocommerce_wp_text_input([
            'id' => self::META_EXPIRY,
            'label' => __('Valid for (days)', 'wp-woocommerce-store-balance'),
            'value' => $product_object->get_meta(self::META_EXPIRY),
            'type' => 'number',
            'custom_attributes' => ['min' => '0', 'step' => '1'],
            'placeholder' => __('Shop default', 'wp-woocommerce-store-balance'),
            'description' => __('Leave empty to use the shop default. 0 means the card never expires.', 'wp-woocommerce-store-balance'),
            'desc_tip' => true,
        ]);

        echo '</div>';

        // The price of a gift card is the amount the customer picks, so the
        // price fields would only mislead.
        ?>
        <script>
        jQuery(function ($) {
            var toggle = function () {
                var on = $('#<?php echo esc_js(self::META_ENABLED); ?>').is(':checked') && $('#product-type').val() === 'simple';
                $('.store-balance-gift-card-options').toggle(on);
                $('.options_group.pricing').toggleClass('store-balance-hidden', on).css('display', on ? 'none' : '');
            };
            $(document.body).on('change', '#<?php echo esc_js(self::META_ENABLED); ?>, #product-type', toggle);
            $(document.body).on('woocommerce-product-type-change', toggle);
            toggle();
        });
        </script>
        <?php
    }

    public function saveProduct(WC_Product $product): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the nonce before this hook.
        $enabled = $product->is_type('simple') && ! empty($_POST[self::META_ENABLED]);

        $product->update_meta_data(self::META_ENABLED, $enabled ? 'yes' : 'no');

        if (! $enabled) {
            return;
        }

        $amounts = [];

        foreach (preg_split('/[;,\n]+|\s{2,}/', (string) wp_unslash($_POST[self::META_AMOUNTS] ?? '')) ?: [] as $raw) {
            $amount = Money::parse($raw);

            if ($amount !== null) {
                $amounts[] = $amount;
            }
        }

        $amounts = array_values(array_unique($amounts));
        sort($amounts);

        $custom = ! empty($_POST[self::META_CUSTOM]);
        $min = Money::parse(wp_unslash($_POST[self::META_MIN] ?? '')) ?? 5.0;
        $max = Money::parse(wp_unslash($_POST[self::META_MAX] ?? '')) ?? 1000.0;

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        // A gift card with nothing to choose from cannot be bought.
        if (! $amounts && ! $custom) {
            $custom = true;
            \WC_Admin_Meta_Boxes::add_error(__('The gift card had no amounts, so "Custom amount" was switched on. Add amounts to offer fixed choices.', 'wp-woocommerce-store-balance'));
        }

        $expiry = wp_unslash($_POST[self::META_EXPIRY] ?? '');
        // phpcs:enable

        $product->update_meta_data(self::META_AMOUNTS, $amounts);
        $product->update_meta_data(self::META_CUSTOM, $custom ? 'yes' : 'no');
        $product->update_meta_data(self::META_MIN, wc_format_decimal($min));
        $product->update_meta_data(self::META_MAX, wc_format_decimal($max));
        $product->update_meta_data(self::META_EXPIRY, is_numeric($expiry) ? (string) max(0, (int) $expiry) : '');

        // WooCommerce only sells a product that has a price. The real price is
        // set per cart line; this one is the "from" price for listings and sorting.
        $from = $amounts ? min($amounts) : $min;
        $product->set_regular_price((string) $from);
        $product->set_sale_price('');
        $product->set_virtual(true);
    }

    /**
     * A gift card is a multi-purpose voucher: no VAT when it is sold, VAT on the
     * goods when it is spent. Forced here rather than left to the product
     * setting, because getting it wrong charges VAT twice.
     */
    public function taxStatus($status, $product)
    {
        return self::isGiftCard($product) ? 'none' : $status;
    }

    public function isVirtual($virtual, $product)
    {
        return self::isGiftCard($product) ? true : $virtual;
    }

    /**
     * No one-click add to cart from a listing: there is an amount to choose.
     */
    public function supports($supports, $feature, $product)
    {
        return $feature === 'ajax_add_to_cart' && self::isGiftCard($product) ? false : $supports;
    }

    public function addToCartUrl($url, $product)
    {
        return self::isGiftCard($product) ? $product->get_permalink() : $url;
    }

    public function addToCartText($text, $product)
    {
        return self::isGiftCard($product) ? __('Choose amount', 'wp-woocommerce-store-balance') : $text;
    }

    public function priceHtml($html, $product)
    {
        if (! self::isGiftCard($product)) {
            return $html;
        }

        $amounts = self::amounts($product);
        $custom = self::customAmount($product);
        $min = $amounts ? min($amounts) : $custom['min'];
        $max = $amounts ? max($amounts) : $custom['max'];

        if ($custom['enabled']) {
            $min = min($min, $custom['min']);
            $max = max($max, $custom['max']);
        }

        return $min === $max ? wc_price($min) : wc_format_price_range(wc_format_decimal($min), wc_format_decimal($max));
    }

    /**
     * A discount code takes money off goods. Taking it off a gift card would be
     * selling money below face value.
     */
    public function couponValidForProduct($valid, $product)
    {
        return self::isGiftCard($product) ? false : $valid;
    }

    /**
     * Nothing to pack or ship, so an order of only gift cards completes on
     * payment instead of waiting in "processing".
     */
    public function needsProcessing($needs, $product)
    {
        return self::isGiftCard($product) ? false : $needs;
    }

    public function form(): void
    {
        global $product;

        if (! self::isGiftCard($product)) {
            return;
        }

        $user = wp_get_current_user();
        // phpcs:disable WordPress.Security.NonceVerification -- repopulating the form after a failed add to cart.
        $posted = static fn (string $key, string $default = '') => isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : $default;
        // phpcs:enable

        Plugin::template('product/gift-card-form.php', [
            'product' => $product,
            'amounts' => self::amounts($product),
            'custom' => self::customAmount($product),
            'values' => [
                'amount' => $posted('store_balance_amount'),
                'custom_amount' => $posted('store_balance_custom_amount'),
                'to' => $posted('store_balance_to'),
                'from' => $posted('store_balance_from', $user->exists() ? trim($user->first_name) : ''),
                'message' => $posted('store_balance_message'),
                'delivery' => $posted('store_balance_delivery'),
            ],
            'message_length' => self::MESSAGE_LENGTH,
            'min_date' => wp_date('Y-m-d'),
            'max_date' => wp_date('Y-m-d', time() + YEAR_IN_SECONDS),
        ]);
    }

    public function assets(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        wp_enqueue_style('wc-store-balance', Plugin::url('assets/frontend.css'), [], WC_STORE_BALANCE_VERSION);
        wp_enqueue_script('wc-store-balance-product', Plugin::url('assets/product.js'), [], WC_STORE_BALANCE_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    }

    /**
     * @param  bool  $passed
     * @param  int  $productId
     * @param  int  $quantity
     * @return bool
     */
    public function validate($passed, $productId, $quantity)
    {
        $product = wc_get_product($productId);

        if (! self::isGiftCard($product)) {
            return $passed;
        }

        // phpcs:ignore WordPress.Security.NonceVerification -- adding to the cart is not a privileged action.
        $result = $this->parse($product, wp_unslash($_POST));

        if ($result['errors']) {
            foreach ($result['errors'] as $error) {
                wc_add_notice($error, 'error');
            }

            return false;
        }

        $this->validated = $result['data'];

        return $passed;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{data: array<string, mixed>, errors: string[]}
     */
    public function parse(WC_Product $product, array $input): array
    {
        $errors = [];
        $amounts = self::amounts($product);
        $custom = self::customAmount($product);
        $choice = (string) ($input['store_balance_amount'] ?? '');
        $amount = null;

        if ($choice === 'custom' || ($choice === '' && ! $amounts)) {
            $amount = Money::parse($input['store_balance_custom_amount'] ?? '');

            if (! $custom['enabled']) {
                $amount = null;
                $errors[] = __('Choose one of the amounts.', 'wp-woocommerce-store-balance');
            } elseif ($amount === null) {
                $errors[] = __('Enter the amount for the gift card.', 'wp-woocommerce-store-balance');
            } elseif ($amount < $custom['min'] || $amount > $custom['max']) {
                $errors[] = sprintf(
                    /* translators: 1: smallest amount, 2: largest amount */
                    __('Choose an amount between %1$s and %2$s.', 'wp-woocommerce-store-balance'),
                    wp_strip_all_tags(wc_price($custom['min'])),
                    wp_strip_all_tags(wc_price($custom['max']))
                );
                $amount = null;
            }
        } else {
            $picked = Money::parse($choice);

            // Compared against the list rather than trusted: the form posts a number.
            if ($picked !== null && in_array($picked, $amounts, true)) {
                $amount = $picked;
            } else {
                $errors[] = __('Choose an amount for the gift card.', 'wp-woocommerce-store-balance');
            }
        }

        $to = trim((string) ($input['store_balance_to'] ?? ''));

        if ($to !== '' && ! is_email($to)) {
            $errors[] = __('Enter a valid email address for the recipient, or leave it empty to receive the gift card yourself.', 'wp-woocommerce-store-balance');
        }

        $message = sanitize_textarea_field((string) ($input['store_balance_message'] ?? ''));

        if (mb_strlen($message) > self::MESSAGE_LENGTH) {
            $errors[] = sprintf(
                /* translators: %d: number of characters */
                __('The message can be at most %d characters.', 'wp-woocommerce-store-balance'),
                self::MESSAGE_LENGTH
            );
        }

        $delivery = trim((string) ($input['store_balance_delivery'] ?? ''));

        if ($delivery !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $delivery, wp_timezone());
            $today = new \DateTimeImmutable('today', wp_timezone());

            if (! $date || $date->format('Y-m-d') !== $delivery) {
                $errors[] = __('Enter a valid delivery date.', 'wp-woocommerce-store-balance');
            } elseif ($date < $today) {
                $errors[] = __('The delivery date cannot be in the past.', 'wp-woocommerce-store-balance');
            } elseif ($date > $today->modify('+1 year')) {
                $errors[] = __('The delivery date can be at most one year from now.', 'wp-woocommerce-store-balance');
            } elseif ($date == $today) {
                // Today means now.
                $delivery = '';
            }
        }

        return [
            'errors' => $errors,
            'data' => [
                'amount' => $amount,
                'to' => sanitize_email($to),
                'from' => sanitize_text_field((string) ($input['store_balance_from'] ?? '')),
                'message' => $message,
                'delivery' => $delivery,
                'locale' => determine_locale(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int  $productId
     * @return array<string, mixed>
     */
    public function cartItemData($data, $productId)
    {
        if ($this->validated === null || ! self::isGiftCard(wc_get_product($productId))) {
            return $data;
        }

        $data[self::CART_KEY] = $this->validated;
        $this->validated = null;

        return $data;
    }

    /**
     * The Store API can add a product to the cart without the product page —
     * the "Add to cart" button of a product grid block, or a direct request.
     * A gift card with no amount would sell at the "from" price to nobody.
     */
    public function validateStoreApi($product, $request): void
    {
        if (! self::isGiftCard($product)) {
            return;
        }

        throw new RouteException(
            'wc_store_balance_choose_amount',
            esc_html__('Choose an amount for the gift card on its product page.', 'wp-woocommerce-store-balance'),
            400
        );
    }

    public function setPrices($cart): void
    {
        if (! $cart instanceof \WC_Cart) {
            return;
        }

        foreach ($cart->get_cart() as $item) {
            if (! empty($item[self::CART_KEY]['amount']) && $item['data'] instanceof WC_Product) {
                $item['data']->set_price(wc_format_decimal($item[self::CART_KEY]['amount']));
            }
        }
    }

    /**
     * What the cart and checkout show under the product name. The Store API
     * reads the same filter, so this covers the blocks too.
     *
     * @param  mixed  $rows
     * @param  array<string, mixed>  $item
     * @return mixed
     */
    public function itemData($rows, $item)
    {
        if (empty($item[self::CART_KEY]) || ! is_array($rows)) {
            return $rows;
        }

        foreach (self::describe($item[self::CART_KEY]) as $label => $value) {
            $rows[] = ['key' => $label, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string> label => value, for display
     */
    public static function describe(array $data): array
    {
        $rows = [];

        $rows[__('To', 'wp-woocommerce-store-balance')] = ! empty($data['to'])
            ? (string) $data['to']
            : __('You (sent to your own email)', 'wp-woocommerce-store-balance');

        if (! empty($data['from'])) {
            $rows[__('From', 'wp-woocommerce-store-balance')] = (string) $data['from'];
        }

        if (! empty($data['message'])) {
            $rows[__('Message', 'wp-woocommerce-store-balance')] = (string) $data['message'];
        }

        if (! empty($data['delivery'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['delivery'], wp_timezone());
            $rows[__('Delivery', 'wp-woocommerce-store-balance')] = $date ? wp_date(wc_date_format(), $date->getTimestamp()) : (string) $data['delivery'];
        } else {
            $rows[__('Delivery', 'wp-woocommerce-store-balance')] = __('By email, right after payment', 'wp-woocommerce-store-balance');
        }

        return $rows;
    }

    /**
     * @param  \WC_Order_Item_Product  $item
     * @param  string  $cartItemKey
     * @param  array<string, mixed>  $values
     */
    public function orderLineItem($item, $cartItemKey, $values): void
    {
        if (! empty($values[self::CART_KEY]) && is_array($values[self::CART_KEY])) {
            $item->update_meta_data(Issuance::ITEM_DATA, $values[self::CART_KEY]);
        }
    }
}
