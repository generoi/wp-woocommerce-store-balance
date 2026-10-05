<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Money;

/**
 * A balance is shown in its own currency, wherever it is shown.
 *
 * Currency switchers filter WooCommerce's currency symbol to the currency the
 * visitor is shopping in, whatever symbol was asked for. Left to wc_price(), a
 * card worth 100 euros reads "$100.00" on a dollar storefront.
 */
class MoneyFormatTest extends TestCase
{
    /**
     * Stands in for a switcher: every currency symbol becomes the active one's.
     */
    protected function forceSymbol(string $symbol): void
    {
        add_filter('woocommerce_currency_symbol', static fn () => $symbol, 9999);
    }

    public function test_an_amount_in_the_active_currency_uses_the_shops_own_format(): void
    {
        $this->assertSame(wp_strip_all_tags(html_entity_decode(wc_price(40), ENT_QUOTES, 'UTF-8')), Money::plain(40, get_woocommerce_currency()));
        $this->assertSame(Money::plain(40), Money::plain(40, get_woocommerce_currency()));
    }

    public function test_an_amount_in_another_currency_carries_its_own_code(): void
    {
        $this->forceSymbol('USD$');

        $other = get_woocommerce_currency() === 'SEK' ? 'GBP' : 'SEK';
        $plain = Money::plain(750, $other);

        $this->assertStringContainsString($other, $plain);
        $this->assertStringNotContainsString('USD$', $plain);
        $this->assertStringContainsString('750', $plain);
    }

    public function test_the_html_form_is_escaped_and_marked_up_like_a_price(): void
    {
        $html = Money::price(12.5, get_woocommerce_currency() === 'SEK' ? 'GBP' : 'SEK');

        $this->assertStringContainsString('woocommerce-Price-amount', $html);
        $this->assertSame($html, wp_kses_post($html));
    }

    public function test_a_lowercase_currency_code_is_the_same_currency(): void
    {
        $this->assertSame(Money::plain(40, get_woocommerce_currency()), Money::plain(40, strtolower(get_woocommerce_currency())));
    }

    /**
     * What the customer reads in My Account when they hold credit in two
     * currencies: each with its own.
     */
    public function test_a_customers_balances_are_not_all_shown_in_the_active_currency(): void
    {
        $this->forceSymbol('USD$');

        $active = get_woocommerce_currency();
        $other = $active === 'SEK' ? 'GBP' : 'SEK';

        $this->assertNotSame(Money::plain(100, $active), Money::plain(100, $other));
    }
}
