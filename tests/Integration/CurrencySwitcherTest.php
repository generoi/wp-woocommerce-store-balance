<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

/**
 * A currency switcher converts every product price from the shop's base
 * currency as it is read. The amount of a gift card is not a base price: the
 * customer typed it in the currency they are shopping in.
 */
class CurrencySwitcherTest extends TestCase
{
    /**
     * Stands in for a switcher: every price read is multiplied by a rate, at
     * the late priority such plugins use.
     */
    protected function convertPrices(float $rate): void
    {
        foreach (['price', 'regular_price', 'sale_price'] as $prop) {
            add_filter('woocommerce_product_get_'.$prop, static fn ($price) => $price === '' ? $price : (string) ((float) $price * $rate), 9999);
        }
    }

    public function test_the_chosen_amount_is_not_converted_by_a_currency_switcher(): void
    {
        $this->convertPrices(9.41);

        $this->assertTrue($this->addGiftCardToCart($this->giftCardProduct([250.0, 500.0, 1000.0]), ['store_balance_amount' => '500']));

        WC()->cart->calculate_totals();
        $line = current(WC()->cart->get_cart());

        $this->assertSame(500.0, (float) $line['line_total']);
        $this->assertSame(500.0, (float) $line['data']->get_price());
        $this->assertSame(500.0, (float) WC()->cart->get_total('edit'));
    }

    /**
     * A custom amount below the product's lowest preset must not turn into a
     * "sale" when the switcher has converted the regular price upwards.
     */
    public function test_the_line_is_not_shown_as_on_sale(): void
    {
        $this->convertPrices(9.41);

        $this->addGiftCardToCart($this->giftCardProduct([250.0]), ['store_balance_amount' => 'custom', 'store_balance_custom_amount' => '150']);

        WC()->cart->calculate_totals();
        $product = current(WC()->cart->get_cart())['data'];

        $this->assertSame(150.0, (float) $product->get_regular_price());
        $this->assertSame('', $product->get_sale_price());
        $this->assertFalse($product->is_on_sale());
    }

    public function test_other_products_are_still_converted(): void
    {
        $this->convertPrices(2.0);

        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '50']);
        WC()->cart->add_to_cart($this->product(100)->get_id());
        WC()->cart->calculate_totals();

        $totals = array_map(static fn (array $line): float => (float) $line['line_total'], array_values(WC()->cart->get_cart()));
        sort($totals);

        // The gift card stays 50; the 100 product is 200 before tax.
        $this->assertSame(50.0, $totals[0]);
        $this->assertGreaterThan(100.0, $totals[1]);
    }

    /**
     * The same gift card product twice with different amounts: each line keeps
     * its own.
     */
    public function test_two_lines_of_the_same_gift_card_keep_their_own_amounts(): void
    {
        $this->convertPrices(9.41);
        $product = $this->giftCardProduct([250.0, 500.0, 1000.0]);

        $this->addGiftCardToCart($product, ['store_balance_amount' => '250']);
        $this->addGiftCardToCart($product, ['store_balance_amount' => '1000']);
        WC()->cart->calculate_totals();

        $totals = array_map(static fn (array $line): float => (float) $line['line_total'], array_values(WC()->cart->get_cart()));
        sort($totals);

        $this->assertSame([250.0, 1000.0], $totals);
    }
}
