<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use GeneroWP\StoreBalance\Modules\Issuance;
use GeneroWP\StoreBalance\Plugin;
use WC_Coupon;
use WC_Product_Simple;

class GiftCardProductTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{data: array<string, mixed>, errors: string[]}
     */
    protected function parse(array $input, ?WC_Product_Simple $product = null): array
    {
        return Plugin::getInstance()->module(GiftCardProduct::class)->parse($product ?? $this->giftCardProduct(), $input);
    }

    public function test_a_preset_amount_is_accepted_however_the_number_is_written(): void
    {
        foreach (['50', '50.00', '50,00', ' 50 '] as $typed) {
            $result = $this->parse(['store_balance_amount' => $typed]);

            $this->assertSame([], $result['errors'], $typed);
            $this->assertSame(50.0, $result['data']['amount'], $typed);
        }
    }

    /**
     * The form posts a number, and anyone can post another one. The price of
     * the cart line comes from here, so it is checked against the list.
     */
    public function test_an_amount_that_is_not_offered_is_refused(): void
    {
        foreach (['30', '0', '-50', '49.99', 'abc', '50 OR 1=1'] as $typed) {
            $result = $this->parse(['store_balance_amount' => $typed]);

            $this->assertCount(1, $result['errors'], $typed);
            $this->assertNull($result['data']['amount'], $typed);
        }
    }

    public function test_no_amount_at_all_is_refused(): void
    {
        $result = $this->parse([]);

        $this->assertCount(1, $result['errors']);
        $this->assertNull($result['data']['amount']);
    }

    public function test_a_custom_amount_within_the_limits_is_accepted(): void
    {
        foreach (['10' => 10.0, '500' => 500.0, '12,50' => 12.5, '75.25' => 75.25, '20 €' => 20.0] as $typed => $amount) {
            $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => (string) $typed]);

            $this->assertSame([], $result['errors'], (string) $typed);
            $this->assertSame($amount, $result['data']['amount'], (string) $typed);
        }
    }

    public function test_a_custom_amount_outside_the_limits_is_refused(): void
    {
        foreach (['9.99', '500.01', '100000', '0'] as $typed) {
            $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => $typed]);

            $this->assertCount(1, $result['errors'], $typed);
            $this->assertNull($result['data']['amount'], $typed);
        }
    }

    /**
     * The shop's own currency may be written next to the number, the way
     * people write prices.
     */
    public function test_an_amount_written_with_the_shops_currency_is_accepted(): void
    {
        foreach (['50 EUR', '€50', '50€', 'eur 50', '50 €'] as $typed) {
            $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => $typed]);

            $this->assertSame([], $result['errors'], $typed);
            $this->assertSame(50.0, $result['data']['amount'], $typed);
        }
    }

    /**
     * "50 SEK" typed in a euro shop is not fifty euros.
     */
    public function test_an_amount_with_another_currencys_code_is_refused(): void
    {
        foreach (['50 SEK', '50 kr', 'USD 50'] as $typed) {
            $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => $typed]);

            $this->assertSame(['custom_amount'], array_keys($result['errors']), $typed);
            $this->assertNull($result['data']['amount'], $typed);
        }
    }

    /**
     * Nor is "$50" or "£50": a sign says as much as a code does.
     */
    public function test_an_amount_with_another_currencys_sign_is_refused(): void
    {
        foreach (['$50', '£50', '50 ¥'] as $typed) {
            $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => $typed]);

            $this->assertSame(['custom_amount'], array_keys($result['errors']), $typed);
            $this->assertNull($result['data']['amount'], $typed);
        }
    }

    public function test_a_custom_amount_that_is_not_a_number_is_refused(): void
    {
        foreach (['', 'fifty', '-20', '1e3', '20 or so', '12.345'] as $typed) {
            $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => $typed]);

            $this->assertCount(1, $result['errors'], $typed);
            $this->assertNull($result['data']['amount'], $typed);
        }
    }

    /**
     * The shop offers 25, 50 and 100 and nothing else. Posting "custom" must
     * not get around that.
     */
    public function test_a_custom_amount_is_refused_when_the_product_does_not_allow_it(): void
    {
        $product = $this->giftCardProduct([25.0, 50.0], false);

        $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => '40'], $product);

        $this->assertCount(1, $result['errors']);
        $this->assertNull($result['data']['amount']);
    }

    public function test_a_product_with_no_presets_takes_the_custom_amount_without_being_told(): void
    {
        $product = $this->giftCardProduct([], true);

        $result = $this->parse(['store_balance_custom_amount' => '40'], $product);

        $this->assertSame([], $result['errors']);
        $this->assertSame(40.0, $result['data']['amount']);
    }

    public function test_the_recipient_is_optional_but_must_be_an_email_address(): void
    {
        $this->assertSame([], $this->parse(['store_balance_amount' => '50', 'store_balance_to' => ''])['errors']);
        $this->assertSame([], $this->parse(['store_balance_amount' => '50', 'store_balance_to' => ' friend@example.org '])['errors']);
        $this->assertSame('friend@example.org', $this->parse(['store_balance_amount' => '50', 'store_balance_to' => ' friend@example.org '])['data']['to']);

        foreach (['friend', 'friend@', '@example.org', 'a b@example.org'] as $typed) {
            $this->assertCount(1, $this->parse(['store_balance_amount' => '50', 'store_balance_to' => $typed])['errors'], $typed);
        }
    }

    public function test_the_message_can_be_at_most_five_hundred_characters(): void
    {
        $this->assertSame([], $this->parse(['store_balance_amount' => '50', 'store_balance_message' => str_repeat('ä', 500)])['errors']);
        $this->assertCount(1, $this->parse(['store_balance_amount' => '50', 'store_balance_message' => str_repeat('ä', 501)])['errors']);
    }

    /**
     * The message and the sender's name end up in an email and on the admin
     * screen. Markup has no business in either.
     */
    public function test_markup_is_stripped_from_what_the_buyer_typed(): void
    {
        $data = $this->parse([
            'store_balance_amount' => '50',
            'store_balance_from' => '<b>Aino</b><script>alert(1)</script>',
            'store_balance_message' => "Onnea!\n<img src=x onerror=alert(1)>Toinen rivi",
        ])['data'];

        $this->assertSame('Aino', $data['from']);
        $this->assertStringNotContainsString('<', $data['message']);
        $this->assertStringContainsString("Onnea!\n", $data['message']);
    }

    public function test_a_delivery_date_in_the_future_is_kept(): void
    {
        $date = (new \DateTimeImmutable('+30 days', wp_timezone()))->format('Y-m-d');

        $result = $this->parse(['store_balance_amount' => '50', 'store_balance_delivery' => $date]);

        $this->assertSame([], $result['errors']);
        $this->assertSame($date, $result['data']['delivery']);
    }

    /**
     * "Today" is the default of the date field. It means now, not 08:00
     * tomorrow because eight o'clock has already passed.
     */
    public function test_a_delivery_date_of_today_means_right_away(): void
    {
        $today = (new \DateTimeImmutable('today', wp_timezone()))->format('Y-m-d');

        $result = $this->parse(['store_balance_amount' => '50', 'store_balance_delivery' => $today]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('', $result['data']['delivery']);
    }

    public function test_a_delivery_date_in_the_past_too_far_ahead_or_not_a_date_is_refused(): void
    {
        $dates = [
            (new \DateTimeImmutable('-1 day', wp_timezone()))->format('Y-m-d'),
            (new \DateTimeImmutable('+1 year +2 days', wp_timezone()))->format('Y-m-d'),
            '2027-02-31',
            '31.12.2026',
            'tomorrow',
        ];

        foreach ($dates as $date) {
            $this->assertCount(1, $this->parse(['store_balance_amount' => '50', 'store_balance_delivery' => $date])['errors'], $date);
        }
    }

    public function test_exactly_one_year_ahead_is_still_allowed(): void
    {
        $date = (new \DateTimeImmutable('today +1 year', wp_timezone()))->format('Y-m-d');

        $this->assertSame([], $this->parse(['store_balance_amount' => '50', 'store_balance_delivery' => $date])['errors']);
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $result = $this->parse([
            'store_balance_amount' => '31',
            'store_balance_to' => 'nobody',
            'store_balance_message' => str_repeat('x', 600),
            'store_balance_delivery' => '2001-01-01',
        ]);

        $this->assertCount(4, $result['errors']);
    }

    /**
     * The email is written in the language the buyer was shopping in.
     */
    public function test_the_buyers_locale_travels_with_the_card(): void
    {
        $this->assertSame(determine_locale(), $this->parse(['store_balance_amount' => '50'])['data']['locale']);
    }

    public function test_only_a_simple_product_with_the_box_ticked_is_a_gift_card(): void
    {
        $this->assertTrue(GiftCardProduct::isGiftCard($this->giftCardProduct()));
        $this->assertFalse(GiftCardProduct::isGiftCard($this->product()));
        $this->assertFalse(GiftCardProduct::isGiftCard(null));
        $this->assertFalse(GiftCardProduct::isGiftCard(false));
    }

    /**
     * Forced rather than left to the product setting, because a shop owner
     * who leaves the default "taxable" would charge VAT twice.
     */
    public function test_a_gift_card_is_never_taxable_and_always_virtual(): void
    {
        $product = wc_get_product($this->giftCardProduct()->get_id());

        $this->assertSame('none', $product->get_tax_status());
        $this->assertFalse($product->is_taxable());
        $this->assertTrue($product->is_virtual());
        $this->assertFalse($product->needs_shipping());
    }

    public function test_the_cart_line_costs_the_amount_chosen_with_no_vat(): void
    {
        $this->assertTrue($this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']));

        WC()->cart->calculate_totals();

        $this->assertSame(100.0, (float) WC()->cart->get_total('edit'));
        $this->assertSame(0.0, (float) WC()->cart->get_total_tax());
    }

    public function test_the_quantity_multiplies_the_amount(): void
    {
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '25'], 3);

        $this->assertSame(75.0, $this->cartTotal());
    }

    /**
     * Two cards of the same product for two people are two lines. Merged
     * into one, the second recipient would get nothing.
     */
    public function test_cards_for_different_people_are_separate_cart_lines(): void
    {
        $product = $this->giftCardProduct();

        $this->addGiftCardToCart($product, ['store_balance_amount' => '25', 'store_balance_to' => 'a@example.org']);
        $this->addGiftCardToCart($product, ['store_balance_amount' => '100', 'store_balance_to' => 'b@example.org']);

        $this->assertCount(2, WC()->cart->get_cart());
        $this->assertSame(125.0, $this->cartTotal());
    }

    public function test_a_gift_card_without_a_valid_amount_does_not_reach_the_cart(): void
    {
        $this->assertFalse($this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '31']));
        $this->assertTrue(WC()->cart->is_empty());
        $this->assertSame(1, wc_notice_count('error'));

        wc_clear_notices();
    }

    /**
     * The Store API adds products without the product page: a product grid's
     * button, or a hand-written request. A gift card added that way would be
     * sold at its "from" price to nobody.
     */
    public function test_a_gift_card_cannot_be_added_through_the_store_api(): void
    {
        $this->expectException(RouteException::class);

        do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($this->giftCardProduct()->get_id()), []);
    }

    public function test_ordinary_products_are_still_added_through_the_store_api(): void
    {
        do_action('woocommerce_store_api_validate_add_to_cart', $this->product(), []);

        $this->assertTrue(apply_filters('woocommerce_add_to_cart_validation', true, $this->product()->get_id(), 1));
    }

    /**
     * A discount code on a gift card is money sold below face value: 20 % off
     * a 100 € card is 20 € handed out.
     */
    public function test_a_coupon_does_not_discount_a_gift_card(): void
    {
        $coupon = new WC_Coupon;
        $coupon->set_code('dev3twenty');
        $coupon->set_discount_type('percent');
        $coupon->set_amount(20);
        $coupon->save();

        WC()->cart->add_to_cart($this->product(100)->get_id());
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']);
        WC()->cart->apply_coupon('dev3twenty');

        $this->assertSame(180.0, $this->cartTotal());
        $this->assertSame(20.0, round((float) WC()->cart->get_discount_total() + (float) WC()->cart->get_discount_tax(), 2));

        wc_clear_notices();
    }

    public function test_a_fixed_cart_coupon_does_not_discount_a_gift_card_either(): void
    {
        $coupon = new WC_Coupon;
        $coupon->set_code('dev3fixed');
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount(30);
        $coupon->save();

        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']);
        WC()->cart->apply_coupon('dev3fixed');

        $this->assertSame(100.0, $this->cartTotal());

        wc_clear_notices();
    }

    public function test_the_cart_shows_who_the_card_is_for(): void
    {
        $this->addGiftCardToCart($this->giftCardProduct(), [
            'store_balance_amount' => '50',
            'store_balance_to' => 'friend@example.org',
            'store_balance_from' => 'Aino',
            'store_balance_message' => 'Onnea!',
        ]);

        $rows = apply_filters('woocommerce_get_item_data', [], current(WC()->cart->get_cart()));

        $shown = array_column($rows, 'value', 'key');

        $this->assertSame(['To', 'From', 'Message', 'Delivery'], array_keys($shown));
        $this->assertSame(['friend@example.org', 'Aino', 'Onnea!'], array_slice(array_values($shown), 0, 3));
        $this->assertNotSame('', $shown['Delivery']);
    }

    /**
     * The wrapper is what the stylesheet holds on to; what the buyer typed is
     * text inside it, never markup.
     */
    public function test_the_cart_details_are_marked_for_the_stylesheet_and_escaped(): void
    {
        $this->addGiftCardToCart($this->giftCardProduct(), [
            'store_balance_amount' => '50',
            'store_balance_from' => 'Tom & "Jerry"',
        ]);

        $rows = apply_filters('woocommerce_get_item_data', [], current(WC()->cart->get_cart()));
        $shown = array_column($rows, 'display', 'key');

        $this->assertSame('<span class="wc-store-balance-detail">Tom &amp; &quot;Jerry&quot;</span>', $shown['From']);

        foreach ($shown as $display) {
            $this->assertStringStartsWith('<span class="wc-store-balance-detail">', $display);
        }
    }

    public function test_what_the_buyer_entered_is_carried_onto_the_order_line(): void
    {
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '50', 'store_balance_to' => 'friend@example.org']);

        $item = current($this->placeOrder()->get_items());
        $data = $item->get_meta(Issuance::ITEM_DATA);

        $this->assertSame(50.0, $data['amount']);
        $this->assertSame('friend@example.org', $data['to']);
        $this->assertSame(50.0, (float) $item->get_total());
    }

    /**
     * A shop with several currencies offers round numbers in each: 25 € is
     * not 25 kr.
     */
    public function test_the_amounts_can_be_set_per_currency(): void
    {
        $product = $this->giftCardProduct();

        add_filter('woocommerce_currency', static fn () => 'SEK');
        add_filter('wc_store_balance_gift_card_amounts', static fn (array $amounts, $product, string $currency) => $currency === 'SEK' ? [500, 250] : $amounts, 10, 3);

        $this->assertSame([250.0, 500.0], GiftCardProduct::amounts($product));
        $this->assertSame([], $this->parse(['store_balance_amount' => '250'], $product)['errors']);
        $this->assertCount(1, $this->parse(['store_balance_amount' => '25'], $product)['errors']);
    }

    public function test_a_listing_sends_the_customer_to_the_product_page_to_choose(): void
    {
        $product = wc_get_product($this->giftCardProduct()->get_id());

        $this->assertSame($product->get_permalink(), $product->add_to_cart_url());
        $this->assertFalse($product->supports('ajax_add_to_cart'));
        $this->assertStringContainsString('10', wp_strip_all_tags($product->get_price_html()));
        $this->assertStringContainsString('500', wp_strip_all_tags($product->get_price_html()));
    }

    /**
     * A coupon for "30 off your order" is spread over the lines of the cart.
     * The gift card line has to be left out of that, and the goods have to
     * carry the whole discount — no cent lost or invented.
     */
    public function test_a_fixed_cart_coupon_on_a_mixed_cart_comes_off_the_goods_to_the_cent(): void
    {
        $coupon = new WC_Coupon;
        $coupon->set_code('dev3mixed');
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount(33.33);
        $coupon->save();

        WC()->cart->add_to_cart($this->product(19.99)->get_id());
        WC()->cart->add_to_cart($this->product(45.01)->get_id());
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '25']);
        WC()->cart->apply_coupon('dev3mixed');

        $this->assertSame(56.67, $this->cartTotal());
        $this->assertSame(33.33, round((float) WC()->cart->get_discount_total() + (float) WC()->cart->get_discount_tax(), 2));

        foreach (WC()->cart->get_cart() as $item) {
            if (! empty($item[GiftCardProduct::CART_KEY])) {
                $this->assertSame(25.0, (float) $item['line_total']);
                $this->assertSame((float) $item['line_subtotal'], (float) $item['line_total']);
            }
        }

        $order = $this->placeOrder();
        $giftLine = current(array_filter($order->get_items(), static fn ($item) => $item->get_meta(Issuance::ITEM_DATA)));

        $this->assertSame(56.67, (float) $order->get_total());
        $this->assertSame(25.0, (float) $giftLine->get_total());

        wc_clear_notices();
    }

    /**
     * Each message is printed next to the field it is about, so it has to
     * say which field that is.
     */
    public function test_each_error_names_the_field_it_belongs_to(): void
    {
        $result = $this->parse([
            'store_balance_amount' => '31',
            'store_balance_to' => 'nobody',
            'store_balance_message' => str_repeat('x', 600),
            'store_balance_delivery' => '2001-01-01',
        ]);

        $this->assertSame(['amount', 'to', 'message', 'delivery'], array_keys($result['errors']));

        $result = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => '9']);

        $this->assertSame(['custom_amount'], array_keys($result['errors']));

        foreach ($result['errors'] as $message) {
            $this->assertIsString($message);
            $this->assertNotSame('', $message);
        }
    }

    public function test_an_empty_custom_amount_and_one_that_is_not_a_number_get_different_answers(): void
    {
        $empty = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => '']);
        $words = $this->parse(['store_balance_amount' => 'custom', 'store_balance_custom_amount' => 'fifty']);

        $this->assertNotSame($empty['errors']['custom_amount'], $words['errors']['custom_amount']);
        // Tells the customer the range without HTML entities in it.
        $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;|</i', $empty['errors']['custom_amount']);
    }

    /**
     * Cut rather than refused: a paid order must never fail to produce its
     * card over the length of a name.
     */
    public function test_a_very_long_senders_name_is_cut_not_refused(): void
    {
        $result = $this->parse(['store_balance_amount' => '50', 'store_balance_from' => str_repeat('ä', 300)]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(str_repeat('ä', GiftCardProduct::NAME_LENGTH), $result['data']['from']);
        $this->assertSame(100, GiftCardProduct::NAME_LENGTH);
    }

    public function test_a_recipient_address_longer_than_the_column_is_refused(): void
    {
        $long = str_repeat('a', 60).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 30).'.org';

        $this->assertGreaterThan(200, strlen($long));
        $this->assertSame(['to'], array_keys($this->parse(['store_balance_amount' => '50', 'store_balance_to' => $long])['errors']));
    }

    /**
     * `store_balance_to[]=x` is one edit of a URL away. With warnings turned
     * into errors, as on this shop, an array where a string is expected is a
     * 500 on the product page.
     */
    public function test_arrays_where_text_is_expected_are_refused_without_a_warning(): void
    {
        $result = $this->parse([
            'store_balance_amount' => ['50'],
            'store_balance_custom_amount' => ['50'],
            'store_balance_to' => ['a@example.org'],
            'store_balance_from' => ['Aino'],
            'store_balance_message' => ['Hei'],
            'store_balance_delivery' => ['2030-01-01'],
        ]);

        $this->assertSame(['amount'], array_keys($result['errors']));
        $this->assertNull($result['data']['amount']);
        $this->assertSame(['to' => '', 'from' => '', 'message' => '', 'delivery' => ''], array_intersect_key($result['data'], ['to' => 1, 'from' => 1, 'message' => 1, 'delivery' => 1]));
    }

    public function test_an_array_posted_from_the_product_page_does_not_reach_the_cart(): void
    {
        $_POST = ['store_balance_amount' => ['50'], 'store_balance_to' => ['x']];

        $passed = apply_filters('woocommerce_add_to_cart_validation', true, $this->giftCardProduct()->get_id(), 1);

        $_POST = [];

        $this->assertFalse($passed);

        wc_clear_notices();
    }

    public function test_invisible_characters_are_stripped_from_the_form(): void
    {
        $data = $this->parse([
            'store_balance_amount' => '50',
            'store_balance_from' => "Ai\u{202E}no",
            'store_balance_message' => "On\u{200B}nea\u{2067}!",
        ])['data'];

        $this->assertSame('Aino', $data['from']);
        $this->assertSame('Onnea!', $data['message']);
    }

    /**
     * A comma is a decimal separator in half the world. "12,50; 20" is two
     * amounts, and has to still be two amounts after the editor has shown
     * them and saved them again.
     */
    public function test_the_amounts_survive_being_shown_and_saved_again(): void
    {
        update_option('woocommerce_price_decimal_sep', ',');
        update_option('woocommerce_price_thousand_sep', ' ');

        $parsed = GiftCardProduct::parseAmounts('12,50; 20');

        $this->assertSame([12.5, 20.0], $parsed['amounts']);
        $this->assertSame([], $parsed['rejected']);

        $shown = GiftCardProduct::formatAmounts($parsed['amounts']);

        $this->assertSame('12,50; 20', $shown);
        $this->assertSame([12.5, 20.0], GiftCardProduct::parseAmounts($shown)['amounts']);
        $this->assertSame($shown, GiftCardProduct::formatAmounts(GiftCardProduct::parseAmounts($shown)['amounts']));
    }

    public function test_the_amounts_survive_two_saves_of_the_product(): void
    {
        update_option('woocommerce_price_decimal_sep', ',');
        update_option('woocommerce_price_thousand_sep', ' ');

        $module = Plugin::getInstance()->module(GiftCardProduct::class);
        $product = $this->product();
        $typed = '12,50; 20; 1500; 99,90';

        foreach ([1, 2] as $save) {
            $_POST = [
                GiftCardProduct::META_ENABLED => 'on',
                GiftCardProduct::META_AMOUNTS => $typed,
                GiftCardProduct::META_MIN => '10',
                GiftCardProduct::META_MAX => '500',
            ];

            $module->saveProduct($product);
            $product->save();
            $product = wc_get_product($product->get_id());

            $this->assertSame([12.5, 20.0, 99.9, 1500.0], $product->get_meta(GiftCardProduct::META_AMOUNTS), "Save {$save}");
            $this->assertSame('', (string) $product->get_meta(GiftCardProduct::META_NOTICES), "Save {$save}");

            // What the editor shows next time is what gets posted next time.
            $typed = GiftCardProduct::formatAmounts($product->get_meta(GiftCardProduct::META_AMOUNTS));
        }

        $_POST = [];

        $this->assertSame('12,50; 20; 99,90; 1500', $typed);
        $this->assertSame('12.5', $product->get_regular_price());
    }

    public function test_a_shop_that_writes_decimals_with_a_point_round_trips_as_well(): void
    {
        update_option('woocommerce_price_decimal_sep', '.');
        update_option('woocommerce_price_thousand_sep', ',');

        $shown = GiftCardProduct::formatAmounts([12.5, 20.0, 1500.0]);

        $this->assertSame([12.5, 20.0, 1500.0], GiftCardProduct::parseAmounts($shown)['amounts']);
    }

    /**
     * The old editor asked for commas, and shop owners will keep typing them.
     */
    public function test_a_list_written_with_commas_is_still_understood(): void
    {
        $this->assertSame([25.0, 50.0, 100.0], GiftCardProduct::parseAmounts('25, 50, 100')['amounts']);
        $this->assertSame([25.0, 50.0, 100.0], GiftCardProduct::parseAmounts('25,50,100')['amounts']);
        $this->assertSame([25.0, 50.0], GiftCardProduct::parseAmounts("50\n25\n50")['amounts']);
        $this->assertSame([12.5], GiftCardProduct::parseAmounts('12,50')['amounts']);
        $this->assertSame([], GiftCardProduct::parseAmounts('  ')['amounts']);
    }

    /**
     * What could not be read is dropped — and said so, or the shop owner
     * finds out from a customer that the 75 € card is missing.
     */
    public function test_a_messy_save_tells_the_shop_owner_what_was_dropped(): void
    {
        $parsed = GiftCardProduct::parseAmounts('25; abc; 50; -10; 12.345');

        $this->assertSame([25.0, 50.0], $parsed['amounts']);
        $this->assertSame(['abc', '-10', '12.345'], $parsed['rejected']);

        $module = Plugin::getInstance()->module(GiftCardProduct::class);
        $product = $this->product();

        $_POST = [
            GiftCardProduct::META_ENABLED => 'on',
            GiftCardProduct::META_AMOUNTS => 'abc; ; xyz',
            GiftCardProduct::META_MIN => '500',
            GiftCardProduct::META_MAX => '10',
        ];
        $module->saveProduct($product);
        $_POST = [];

        $notices = $product->get_meta(GiftCardProduct::META_NOTICES);

        $this->assertCount(3, $notices);
        $this->assertStringContainsString('abc, xyz', $notices[0]);
        $this->assertSame('yes', $product->get_meta(GiftCardProduct::META_CUSTOM));
        $this->assertSame('10', $product->get_meta(GiftCardProduct::META_MIN));
        $this->assertSame('500', $product->get_meta(GiftCardProduct::META_MAX));
    }

    public function test_an_array_posted_to_the_product_editor_does_not_break_the_save(): void
    {
        $module = Plugin::getInstance()->module(GiftCardProduct::class);
        $product = $this->product();

        $_POST = [
            GiftCardProduct::META_ENABLED => 'on',
            GiftCardProduct::META_AMOUNTS => ['25'],
            GiftCardProduct::META_MIN => ['1'],
            GiftCardProduct::META_MAX => ['2'],
            GiftCardProduct::META_EXPIRY => ['3'],
        ];
        $module->saveProduct($product);
        $_POST = [];

        $this->assertSame([], $product->get_meta(GiftCardProduct::META_AMOUNTS));
        $this->assertSame('yes', $product->get_meta(GiftCardProduct::META_CUSTOM));
    }

    /**
     * A custom amount below the cheapest preset is not a discount: nothing
     * was ever more expensive.
     */
    public function test_a_custom_amount_below_the_from_price_is_not_shown_as_a_sale(): void
    {
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => 'custom', 'store_balance_custom_amount' => '10']);
        WC()->cart->calculate_totals();

        $product = current(WC()->cart->get_cart())['data'];

        $this->assertFalse($product->is_on_sale());
        $this->assertSame(10.0, (float) $product->get_price());
        $this->assertSame((float) $product->get_price(), (float) $product->get_regular_price());
    }

    /**
     * A list as people really type it: a semicolon here, a comma with the
     * space on the wrong side there.
     */
    public function test_a_list_with_mixed_separators_is_three_amounts(): void
    {
        $parsed = GiftCardProduct::parseAmounts('25; 50 ,100');

        $this->assertSame([25.0, 50.0, 100.0], $parsed['amounts']);
        $this->assertSame([], $parsed['rejected']);
        $this->assertSame([12.5, 25.0, 50.0], GiftCardProduct::parseAmounts('25; 12,50; 50')['amounts']);
    }

    /**
     * Cash on delivery calls an order "processing" before any money has
     * arrived, and "processing" is when the gift card goes out.
     */
    public function test_a_gift_card_cannot_be_paid_for_on_delivery(): void
    {
        $gateways = ['cod' => new \WC_Gateway_COD, 'bacs' => new \WC_Gateway_BACS];

        WC()->cart->add_to_cart($this->product()->get_id());
        $this->assertSame(['cod', 'bacs'], array_keys(apply_filters('woocommerce_available_payment_gateways', $gateways)));

        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '50']);
        $this->assertSame(['bacs'], array_keys(apply_filters('woocommerce_available_payment_gateways', $gateways)));

        add_filter('wc_store_balance_pay_later_gateways', '__return_empty_array');
        $this->assertSame(['cod', 'bacs'], array_keys(apply_filters('woocommerce_available_payment_gateways', $gateways)));
        remove_filter('wc_store_balance_pay_later_gateways', '__return_empty_array');
    }

    /**
     * An express payment button, an abandoned-cart link, another plugin: code
     * that calls add_to_cart() with a product id skips the validation filter.
     * The line used to go in at the "from" price with no gift card behind it.
     */
    public function test_a_gift_card_added_by_code_that_skips_the_form_is_refused(): void
    {
        $product = $this->giftCardProduct();

        $this->assertFalse(WC()->cart->add_to_cart($product->get_id()));
        $this->assertSame([], WC()->cart->get_cart());
        $this->assertStringContainsString('Choose an amount', implode(' ', array_column(wc_get_notices('error'), 'notice')));

        wc_clear_notices();
    }

    public function test_code_that_passes_the_form_fields_on_gets_a_proper_gift_card_line(): void
    {
        $product = $this->giftCardProduct();

        $_POST = ['store_balance_amount' => '50', 'store_balance_to' => 'friend@example.org'];
        $key = WC()->cart->add_to_cart($product->get_id());
        $_POST = [];

        $this->assertIsString($key);
        $line = WC()->cart->get_cart()[$key][GiftCardProduct::CART_KEY];
        $this->assertSame(50.0, $line['amount']);
        $this->assertSame('friend@example.org', $line['to']);
        $this->assertSame(get_woocommerce_currency(), $line['currency']);

        $_POST = ['store_balance_amount' => '7'];
        $this->assertFalse(WC()->cart->add_to_cart($product->get_id()));
        $_POST = [];

        wc_clear_notices();
    }

    public function test_a_restored_cart_line_keeps_its_details_only_if_the_amount_is_one_the_product_sells(): void
    {
        $product = $this->giftCardProduct();

        $key = WC()->cart->add_to_cart($product->get_id(), 1, 0, [], [GiftCardProduct::CART_KEY => ['amount' => 50.0, 'to' => 'friend@example.org']]);
        $this->assertIsString($key);

        $this->assertFalse(WC()->cart->add_to_cart($product->get_id(), 1, 0, [], [GiftCardProduct::CART_KEY => ['amount' => 0.01]]));
        $this->assertFalse(WC()->cart->add_to_cart($product->get_id(), 1, 0, [], [GiftCardProduct::CART_KEY => ['amount' => 99999]]));

        wc_clear_notices();
    }

    /**
     * "50" chosen on a page in euros is not 50 kronor.
     */
    public function test_a_gift_card_chosen_in_another_currency_is_taken_out_of_the_cart(): void
    {
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '50']);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->assertCount(2, WC()->cart->get_cart());

        $sek = static fn () => 'SEK';
        add_filter('woocommerce_currency', $sek);
        WC()->cart->calculate_totals();
        remove_filter('woocommerce_currency', $sek);

        $this->assertCount(1, WC()->cart->get_cart());
        $this->assertStringContainsString('another currency', implode(' ', array_column(wc_get_notices('notice'), 'notice')));

        wc_clear_notices();
    }

    public function test_a_gift_card_line_without_an_amount_is_taken_out_of_the_cart(): void
    {
        $product = $this->giftCardProduct();
        $key = WC()->cart->generate_cart_id($product->get_id());
        WC()->cart->cart_contents[$key] = ['key' => $key, 'product_id' => $product->get_id(), 'variation_id' => 0, 'variation' => [], 'quantity' => 1, 'data' => $product, 'data_hash' => ''];

        WC()->cart->calculate_totals();

        $this->assertSame([], WC()->cart->get_cart());

        wc_clear_notices();
    }
}
