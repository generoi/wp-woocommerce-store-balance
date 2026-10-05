<?php

namespace GeneroWP\StoreBalance\Modules;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use GeneroWP\StoreBalance\Input;
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

    /** What the last save changed or dropped, shown once in the product editor. */
    public const META_NOTICES = '_store_balance_notices';

    /** The key the gift card details live under on a cart item. */
    public const CART_KEY = 'store_balance_gift_card';

    public const MESSAGE_LENGTH = 500;

    public const NAME_LENGTH = 100;

    /** @var array<string, mixed>|null Validated form input, carried from validation to the cart item. */
    protected ?array $validated = null;

    /** @var array<string, string>|null Validation errors of this request, keyed by field. Null when nothing was submitted. */
    protected ?array $errors = null;

    /** Whether this request is adding a gift card through the Store API, with its details already read and checked. */
    protected bool $viaStoreApi = false;

    /** Whether this request added a gift card to the cart. */
    protected bool $added = false;

    /** @var \WeakMap<WC_Product, string>|null The chosen amount of each gift card line in the cart, by its product object. */
    protected ?\WeakMap $pinned = null;

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
        add_filter('woocommerce_coupon_get_items_to_apply', [$this, 'couponItems'], 20);
        add_filter('woocommerce_order_item_needs_processing', [$this, 'needsProcessing'], 20, 2);

        // Product page and cart.
        add_action('woocommerce_before_add_to_cart_button', [$this, 'form']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate'], 20, 3);
        add_filter('woocommerce_add_cart_item_data', [$this, 'cartItemData'], 20, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'setPrices'], 20);

        foreach (['price', 'regular_price', 'sale_price'] as $prop) {
            add_filter('woocommerce_product_get_'.$prop, [$this, 'pinnedPrice'], PHP_INT_MAX, 2);
        }
        add_filter('woocommerce_get_item_data', [$this, 'itemData'], 20, 2);
        add_filter('woocommerce_store_api_add_to_cart_data', [$this, 'storeApiData'], 20, 2);
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
        $symbol = get_woocommerce_currency_symbol();
        $notices = $product_object->get_meta(self::META_NOTICES);

        echo '<div class="options_group store-balance-gift-card-options" style="display:none">';

        // What the last save changed or left out. The product editor does not
        // show WooCommerce's own save errors, so they are kept and shown here —
        // until a save that has nothing to report. Clearing them on display
        // would lose them to the editor's own background reload of this box.
        if (is_array($notices) && $notices) {
            echo '<div class="notice notice-warning inline" style="margin:12px"><p><strong>'.esc_html__('From the last save:', 'wp-woocommerce-store-balance').'</strong><br>'.implode('<br>', array_map('esc_html', $notices)).'</p></div>';
        }

        echo '<p class="form-field"><span class="description" style="margin:0;display:block">'.esc_html__('The price of a gift card is the amount the customer chooses, so the price fields are hidden. Gift cards are always virtual and sold without VAT: the VAT is charged on what the card is later spent on.', 'wp-woocommerce-store-balance').'</span></p>';

        woocommerce_wp_text_input([
            'id' => self::META_AMOUNTS,
            'label' => sprintf(
                /* translators: %s: currency symbol */
                __('Amounts (%s)', 'wp-woocommerce-store-balance'),
                $symbol
            ),
            'value' => is_array($amounts) ? self::formatAmounts($amounts) : '',
            'placeholder' => '25; 50; 100',
            'description' => __('The amounts the customer can choose from, separated by semicolons. For example: 25; 50; 100', 'wp-woocommerce-store-balance'),
        ]);

        woocommerce_wp_checkbox([
            'id' => self::META_CUSTOM,
            'label' => __('Custom amount', 'wp-woocommerce-store-balance'),
            'description' => __('Let the customer enter their own amount.', 'wp-woocommerce-store-balance'),
            'value' => $product_object->get_meta(self::META_CUSTOM) === 'yes' ? 'yes' : 'no',
        ]);

        woocommerce_wp_text_input([
            'id' => self::META_MIN,
            'label' => sprintf(
                /* translators: %s: currency symbol */
                __('Smallest custom amount (%s)', 'wp-woocommerce-store-balance'),
                $symbol
            ),
            'value' => $product_object->get_meta(self::META_MIN) ?: '5',
            'data_type' => 'price',
        ]);

        woocommerce_wp_text_input([
            'id' => self::META_MAX,
            'label' => sprintf(
                /* translators: %s: currency symbol */
                __('Largest custom amount (%s)', 'wp-woocommerce-store-balance'),
                $symbol
            ),
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
        ?>
        <style>
        /*
         * The price and tax fields do not apply to a gift card; left visible
         * they would only say things that are not true of it. A class and a
         * rule rather than .hide(): WooCommerce shows and hides these groups
         * itself whenever a product option changes, and would undo it.
         */
        #woocommerce-product-data.store-balance-is-gift-card .options_group.pricing,
        #woocommerce-product-data.store-balance-is-gift-card ._tax_status_field,
        #woocommerce-product-data.store-balance-is-gift-card ._tax_class_field {
            display: none !important;
        }
        </style>
        <script>
        jQuery(function ($) {
            var toggle = function () {
                var on = $('#<?php echo esc_js(self::META_ENABLED); ?>').is(':checked') && $('#product-type').val() === 'simple';
                $('.store-balance-gift-card-options').toggle(on);
                $('#woocommerce-product-data').toggleClass('store-balance-is-gift-card', on);

                if (on && !$('#_virtual').is(':checked')) {
                    $('#_virtual').prop('checked', true).trigger('change');
                }
            };
            $(document.body).on('change', '#<?php echo esc_js(self::META_ENABLED); ?>, #product-type', toggle);
            $(document.body).on('woocommerce-product-type-change', toggle);
            toggle();
        });
        </script>
        <?php
    }

    /**
     * "25; 50; 12,50". A semicolon between amounts, because a comma is a
     * decimal separator in half the world: a list written with commas cannot
     * be told apart from one amount with decimals when it is read back.
     *
     * @param  array<int, mixed>  $amounts
     */
    public static function formatAmounts(array $amounts): string
    {
        return implode('; ', array_map(static function ($amount): string {
            $amount = (float) $amount;

            return floor($amount) == $amount
                ? (string) (int) $amount
                : wc_format_localized_price(wc_format_decimal($amount, wc_get_price_decimals()));
        }, $amounts));
    }

    /**
     * Read a list of amounts typed by a person.
     *
     * @return array{amounts: float[], rejected: string[]}
     */
    public static function parseAmounts(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return ['amounts' => [], 'rejected' => []];
        }

        if (preg_match('/[;\n]/', $input)) {
            $tokens = [];

            // A semicolon list with a stray comma in it: "25; 50 ,100". A
            // comma with a space on either side separates; "12,50" does not.
            foreach (preg_split('/[;\n]+/', $input) ?: [] as $part) {
                $tokens = array_merge($tokens, preg_split('/\s+,\s*|\s*,\s+/', $part) ?: []);
            }
        } elseif (preg_match('/,\s/', $input)) {
            // "25, 50, 100": a comma followed by a space separates.
            $tokens = preg_split('/,\s+/', $input);
        } elseif (substr_count($input, ',') > 1) {
            // "25,50,100": more than one comma cannot be one number.
            $tokens = explode(',', $input);
        } else {
            $tokens = [$input];
        }

        $amounts = [];
        $rejected = [];

        foreach ($tokens ?: [] as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $amount = Money::parse($token);

            if ($amount === null) {
                $rejected[] = $token;
            } else {
                $amounts[] = $amount;
            }
        }

        $amounts = array_values(array_unique($amounts));
        sort($amounts);

        return ['amounts' => $amounts, 'rejected' => $rejected];
    }

    public function saveProduct(WC_Product $product): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the nonce before this hook.
        $enabled = $product->is_type('simple') && ! empty($_POST[self::META_ENABLED]);

        $product->update_meta_data(self::META_ENABLED, $enabled ? 'yes' : 'no');

        if (! $enabled) {
            return;
        }

        $notices = [];
        $parsed = self::parseAmounts(Input::text(wp_unslash($_POST[self::META_AMOUNTS] ?? '')));
        $amounts = $parsed['amounts'];

        if ($parsed['rejected']) {
            $notices[] = sprintf(
                /* translators: %s: list of values */
                __('These amounts were not understood and were left out: %s. Write amounts as numbers separated by semicolons, for example 25; 50; 100.', 'wp-woocommerce-store-balance'),
                implode(', ', $parsed['rejected'])
            );
        }

        $custom = ! empty($_POST[self::META_CUSTOM]);
        $min = Money::parse(Input::text(wp_unslash($_POST[self::META_MIN] ?? ''))) ?? 5.0;
        $max = Money::parse(Input::text(wp_unslash($_POST[self::META_MAX] ?? ''))) ?? 1000.0;

        if ($max < $min) {
            [$min, $max] = [$max, $min];
            $notices[] = __('The smallest custom amount was larger than the largest, so the two were swapped.', 'wp-woocommerce-store-balance');
        }

        // A gift card with nothing to choose from cannot be bought.
        if (! $amounts && ! $custom) {
            $custom = true;
            $notices[] = __('The gift card had no amounts, so "Custom amount" was switched on. Add amounts to offer fixed choices.', 'wp-woocommerce-store-balance');
        }

        $expiry = Input::text(wp_unslash($_POST[self::META_EXPIRY] ?? ''));
        // phpcs:enable

        $product->update_meta_data(self::META_AMOUNTS, $amounts);
        $product->update_meta_data(self::META_CUSTOM, $custom ? 'yes' : 'no');
        $product->update_meta_data(self::META_MIN, wc_format_decimal($min));
        $product->update_meta_data(self::META_MAX, wc_format_decimal($max));
        $product->update_meta_data(self::META_EXPIRY, is_numeric($expiry) ? (string) max(0, (int) $expiry) : '');

        if ($notices) {
            $product->update_meta_data(self::META_NOTICES, $notices);
        } else {
            $product->delete_meta_data(self::META_NOTICES);
        }

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
     * The product check above only covers coupons that discount products. A
     * coupon that takes an amount off the whole cart is spread over every
     * line, gift cards included, unless they are taken out here.
     *
     * @param  mixed  $items
     * @return mixed
     */
    public function couponItems($items)
    {
        if (! is_array($items)) {
            return $items;
        }

        return array_filter($items, static function ($item): bool {
            $object = is_object($item) ? ($item->object ?? null) : null;

            if (is_array($object) && ! empty($object[self::CART_KEY])) {
                return false;
            }

            return ! (is_object($item) && isset($item->product) && self::isGiftCard($item->product));
        });
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

        // Only after a failed attempt: a form that still holds the last gift
        // card after a successful add invites a second, accidental purchase.
        $failed = $this->errors !== null;
        // phpcs:disable WordPress.Security.NonceVerification -- repopulating the form after a failed add to cart.
        $posted = static fn (string $key) => $failed && isset($_POST[$key]) ? sanitize_textarea_field(Input::text(wp_unslash($_POST[$key]))) : '';
        // phpcs:enable

        // The shopper has just pressed "Add to cart" on this form, so the
        // answer belongs here — not wherever the theme happens to print
        // notices, which may be nowhere, or on the next page they visit.
        // Printing empties the queue, so nothing is shown twice.
        $notices = '';

        if (($failed || $this->added) && function_exists('wc_notice_count') && wc_notice_count() > 0) {
            $notices = wc_print_notices(true);
        }

        Plugin::template('product/gift-card-form.php', [
            'product' => $product,
            'amounts' => self::amounts($product),
            'custom' => self::customAmount($product),
            'values' => [
                'amount' => $posted('store_balance_amount'),
                'custom_amount' => $posted('store_balance_custom_amount'),
                'to' => $posted('store_balance_to'),
                'from' => $posted('store_balance_from'),
                'message' => $posted('store_balance_message'),
                'delivery' => $posted('store_balance_delivery'),
            ],
            'errors' => $this->errors ?? [],
            'notices' => $notices,
            /**
             * Filters whether the theme adds gift cards to the cart itself,
             * through the Store API, with the gift card fields in the request.
             *
             * By default the plugin makes the gift card form post to the page
             * the ordinary way, because a script that adds to the cart without
             * a page load usually sends a product id and a quantity and
             * nothing else. A theme whose script sends the fields returns true.
             */
            'ajax' => (bool) apply_filters('wc_store_balance_ajax_add_to_cart', false, $product),
            'message_length' => self::MESSAGE_LENGTH,
            'min_date' => wp_date('Y-m-d'),
            'max_date' => wp_date('Y-m-d', time() + YEAR_IN_SECONDS),
        ]);
    }

    public function assets(): void
    {
        if (! function_exists('is_product')) {
            return;
        }

        // The gift card details under a line item are also shown on the
        // thank-you page and in My Account.
        if (is_product() || is_checkout() || is_account_page()) {
            wp_enqueue_style('wc-store-balance', Plugin::url('assets/frontend.css'), [], WC_STORE_BALANCE_VERSION);
        }

        if (! is_product()) {
            return;
        }

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

        // The Store API runs this filter too, for compatibility. Its request
        // has no $_POST; the details came in its JSON body and are checked.
        if ($this->viaStoreApi) {
            return $passed;
        }

        // phpcs:ignore WordPress.Security.NonceVerification -- adding to the cart is not a privileged action.
        $result = $this->parse($product, wp_unslash($_POST));

        if ($result['errors']) {
            $this->errors = $result['errors'];

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
     * @return array{data: array<string, mixed>, errors: array<string, string>} errors keyed by the field they belong to
     */
    public function parse(WC_Product $product, array $input): array
    {
        $errors = [];
        $amounts = self::amounts($product);
        $custom = self::customAmount($product);
        $choice = Input::text($input['store_balance_amount'] ?? '');
        $amount = null;

        if ($choice === 'custom' || ($choice === '' && ! $amounts)) {
            $typed = Input::text($input['store_balance_custom_amount'] ?? '');
            $amount = Money::parse($typed);
            $range = sprintf(
                /* translators: 1: smallest amount, 2: largest amount */
                __('Choose an amount between %1$s and %2$s.', 'wp-woocommerce-store-balance'),
                Money::plain($custom['min']),
                Money::plain($custom['max'])
            );

            if (! $custom['enabled']) {
                $amount = null;
                $errors['amount'] = __('Choose one of the amounts.', 'wp-woocommerce-store-balance');
            } elseif (trim($typed) === '') {
                $errors['custom_amount'] = __('Enter the amount for the gift card.', 'wp-woocommerce-store-balance').' '.$range;
            } elseif ($amount === null) {
                $errors['custom_amount'] = __('Enter the amount as a number, for example 50 or 49,90.', 'wp-woocommerce-store-balance');
            } elseif ($amount < $custom['min'] || $amount > $custom['max']) {
                $errors['custom_amount'] = $range;
                $amount = null;
            }
        } else {
            $picked = Money::parse($choice);

            // Compared against the list rather than trusted: the form posts a number.
            if ($picked !== null && in_array($picked, $amounts, true)) {
                $amount = $picked;
            } else {
                $errors['amount'] = __('Choose an amount for the gift card.', 'wp-woocommerce-store-balance');
            }
        }

        $to = trim(Input::text($input['store_balance_to'] ?? ''));

        if ($to !== '' && (! is_email($to) || strlen($to) > 200)) {
            $errors['to'] = __('Enter a valid email address for the recipient, or leave it empty to receive the gift card yourself.', 'wp-woocommerce-store-balance');
        }

        $message = sanitize_textarea_field(Input::text($input['store_balance_message'] ?? ''));

        if (mb_strlen($message) > self::MESSAGE_LENGTH) {
            $errors['message'] = sprintf(
                /* translators: %d: number of characters */
                __('The message can be at most %d characters.', 'wp-woocommerce-store-balance'),
                self::MESSAGE_LENGTH
            );
        }

        $delivery = trim(Input::text($input['store_balance_delivery'] ?? ''));

        if ($delivery !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $delivery, wp_timezone());
            $today = new \DateTimeImmutable('today', wp_timezone());

            if (! $date || $date->format('Y-m-d') !== $delivery) {
                $errors['delivery'] = __('Enter a valid delivery date.', 'wp-woocommerce-store-balance');
            } elseif ($date < $today) {
                $errors['delivery'] = __('The delivery date cannot be in the past.', 'wp-woocommerce-store-balance');
            } elseif ($date > $today->modify('+1 year')) {
                $errors['delivery'] = __('The delivery date can be at most one year from now.', 'wp-woocommerce-store-balance');
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
                // Cut rather than refused: nobody's name is longer than this,
                // and a paid order must never fail to produce its card over it.
                'from' => Input::limit(sanitize_text_field(Input::text($input['store_balance_from'] ?? '')), self::NAME_LENGTH),
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
        $this->added = true;

        return $data;
    }

    /** The fields of the gift card form, as the Store API accepts them in an add-item request. */
    public const FIELDS = [
        'store_balance_amount',
        'store_balance_custom_amount',
        'store_balance_to',
        'store_balance_from',
        'store_balance_message',
        'store_balance_delivery',
    ];

    /**
     * Adding a gift card through the Store API.
     *
     * A theme that adds to the cart without a page load posts to
     * `cart/add-item`, not to the product page. The gift card's details travel
     * in that request's body under the same names as the form fields:
     *
     *     { "id": 123, "quantity": 1, "store_balance_amount": "50",
     *       "store_balance_to": "friend@example.com", ... }
     *
     * They are checked here exactly as the form post is, and become the cart
     * item's data.
     *
     * @param  mixed  $data
     * @param  mixed  $request
     * @return mixed
     *
     * @throws RouteException
     */
    public function storeApiData($data, $request)
    {
        if (! is_array($data) || ! $request instanceof \WP_REST_Request) {
            return $data;
        }

        $product = wc_get_product(absint($data['id'] ?? 0));

        if (! self::isGiftCard($product)) {
            return $data;
        }

        $this->viaStoreApi = false;
        $input = [];

        foreach (self::FIELDS as $field) {
            if ($request->has_param($field)) {
                $input[$field] = $request->get_param($field);
            }
        }

        // No details at all: the "Add to cart" button of a product grid, or a
        // script that only knows a product id. There is an amount to choose.
        if (! $input) {
            return $data;
        }

        $result = $this->parse($product, $input);

        if ($result['errors']) {
            throw new RouteException('wc_store_balance_invalid_gift_card', esc_html(implode(' ', $result['errors'])), 400);
        }

        $data['cart_item_data'] = (array) ($data['cart_item_data'] ?? []);
        $data['cart_item_data'][self::CART_KEY] = $result['data'];
        $this->viaStoreApi = true;

        return $data;
    }

    /**
     * A gift card with no amount would sell at the "from" price to nobody.
     */
    public function validateStoreApi($product, $request): void
    {
        if (! self::isGiftCard($product)) {
            return;
        }

        // Good for one item only. A batch request can add several, and the
        // details of one gift card must not wave the next one through.
        $checked = $this->viaStoreApi;
        $this->viaStoreApi = false;

        if ($checked) {
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
                $amount = wc_format_decimal($item[self::CART_KEY]['amount']);

                // All three, or an amount below the product's "from" price
                // shows up in the cart as a sale with a "Save" badge.
                $item['data']->set_regular_price($amount);
                $item['data']->set_sale_price('');
                $item['data']->set_price($amount);

                $this->pinned ??= new \WeakMap;
                $this->pinned[$item['data']] = $amount;
            }
        }
    }

    /**
     * The amount is in the currency the customer is shopping in: they typed
     * "500" on a page showing kronor. A currency switcher converts every
     * product price from the shop's base currency as it is read, and would
     * turn those 500 kronor into five thousand. The price of a gift card line
     * is the amount chosen and nothing else, so it is given back unchanged
     * after every other filter has run.
     *
     * Keyed on the product object of the cart line, not on the product: the
     * same gift card product can be in the cart twice with different amounts.
     */
    public function pinnedPrice($price, $product)
    {
        if ($this->pinned === null || ! $product instanceof WC_Product || ! isset($this->pinned[$product])) {
            return $price;
        }

        return current_filter() === 'woocommerce_product_get_sale_price' ? '' : $this->pinned[$product];
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
            $rows[] = self::detailRow($label, $value);
        }

        $quantity = (int) ($item['quantity'] ?? 1);

        if ($quantity > 1) {
            $rows[] = self::detailRow(
                __('Quantity', 'wp-woocommerce-store-balance'),
                sprintf(
                    empty($item[self::CART_KEY]['to'])
                        /* translators: %d: number of gift cards */
                        ? __('%d separate gift cards, each emailed to you', 'wp-woocommerce-store-balance')
                        /* translators: %d: number of gift cards */
                        : __('%d separate gift cards, each emailed to the same recipient', 'wp-woocommerce-store-balance'),
                    $quantity
                )
            );
        }

        return $rows;
    }

    /**
     * A row under the line item in the cart. The wrapper gives the plugin's
     * stylesheet something stable to hold on to: the classes WooCommerce puts
     * on the row are made from the label, so they change with the language.
     *
     * @return array{key: string, value: string, display: string}
     */
    private static function detailRow(string $label, string $value): array
    {
        return [
            'key' => $label,
            'value' => $value,
            'display' => '<span class="wc-store-balance-detail">'.esc_html($value).'</span>',
        ];
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
            : __('Your own email address', 'wp-woocommerce-store-balance');

        if (! empty($data['from'])) {
            $rows[__('From', 'wp-woocommerce-store-balance')] = (string) $data['from'];
        }

        if (! empty($data['message'])) {
            $rows[__('Message', 'wp-woocommerce-store-balance')] = (string) $data['message'];
        }

        if (! empty($data['delivery'])) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['delivery'], wp_timezone());
            $rows[__('Delivery', 'wp-woocommerce-store-balance')] = sprintf(
                /* translators: %s: date */
                __('By email on %s', 'wp-woocommerce-store-balance'),
                $date ? wp_date(wc_date_format(), $date->getTimestamp()) : (string) $data['delivery']
            );
        } else {
            $rows[__('Delivery', 'wp-woocommerce-store-balance')] = __('By email, as soon as the payment is confirmed', 'wp-woocommerce-store-balance');
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
