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
}
