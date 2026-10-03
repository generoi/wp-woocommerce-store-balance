<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

class PerformanceTest extends TestCase
{
    /**
     * Queries the plugin adds to one calculation of the cart, for a customer
     * with this many cards on their account.
     *
     * @return array{0: int, 1: float} queries, milliseconds
     */
    protected function costOfACalculation(int $cards, int $codes = 0): array
    {
        global $wpdb;

        $customerId = $this->customer();

        for ($i = 0; $i < $cards; $i++) {
            $this->storeCredit($customerId, 1, ['expires_at' => time() + ($cards - $i) * DAY_IN_SECONDS]);
        }

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        for ($i = 0; $i < $codes; $i++) {
            $this->cart()->applyCode($this->giftCard(1)->code);
        }

        // Warm whatever WooCommerce caches on the first run.
        WC()->cart->calculate_totals();

        $filter = [$this->cart(), 'applyToTotal'];

        remove_filter('woocommerce_calculated_total', $filter, 999);
        $before = $wpdb->num_queries;
        WC()->cart->calculate_totals();
        $without = $wpdb->num_queries - $before;

        add_filter('woocommerce_calculated_total', $filter, 999, 2);
        $before = $wpdb->num_queries;
        $start = microtime(true);
        WC()->cart->calculate_totals();
        $time = (microtime(true) - $start) * 1000;
        $with = $wpdb->num_queries - $before;

        if (getenv('WC_STORE_BALANCE_TESTS_VERBOSE')) {
            fwrite(STDERR, sprintf("\n%d cards, %d codes: %d queries added, %.2f ms for the whole calculation\n", $cards, $codes, $with - $without, $time));
        }

        return [$with - $without, $time];
    }

    /**
     * The cart is calculated several times on every cart and checkout
     * request. A query per card would make the checkout of the shop's best
     * customers the slowest one.
     */
    public function test_the_number_of_queries_does_not_grow_with_the_number_of_cards(): void
    {
        [$one] = $this->costOfACalculation(1);
        [$five] = $this->costOfACalculation(5);
        [$fifty] = $this->costOfACalculation(50);

        $this->assertSame($one, $five);
        $this->assertSame($one, $fifty);
        $this->assertLessThanOrEqual(2, $one);
    }

    /**
     * Codes are looked up one by one, but there are at most five of them.
     */
    public function test_each_applied_code_costs_one_query(): void
    {
        [$none] = $this->costOfACalculation(1);
        [$five] = $this->costOfACalculation(1, 5);

        $this->assertSame(5, $five - $none);
    }
}
