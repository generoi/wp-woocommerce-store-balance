<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use WP_REST_Request;

/**
 * Adding a gift card through the Store API's `cart/add-item`.
 *
 * A theme that adds to the cart without a page load does not post the product
 * form. The amount and the recipient have to arrive some other way, or every
 * gift card is refused with "choose an amount" while one is plainly chosen.
 */
class StoreApiAddToCartTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $params
     */
    protected function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function addItemData(int $productId, array $params): array
    {
        return (array) apply_filters(
            'woocommerce_store_api_add_to_cart_data',
            ['id' => $productId, 'quantity' => 1, 'variation' => [], 'cart_item_data' => []],
            $this->request(['id' => $productId, 'quantity' => 1] + $params)
        );
    }

    public function test_the_fields_in_the_request_become_the_cart_items_gift_card(): void
    {
        $product = $this->giftCardProduct();

        $data = $this->addItemData($product->get_id(), [
            'store_balance_amount' => '50',
            'store_balance_to' => 'friend@example.com',
            'store_balance_from' => 'Anna',
            'store_balance_message' => 'Congratulations',
        ]);

        $card = $data['cart_item_data'][GiftCardProduct::CART_KEY];

        $this->assertSame(50.0, $card['amount']);
        $this->assertSame('friend@example.com', $card['to']);
        $this->assertSame('Anna', $card['from']);
        $this->assertSame('Congratulations', $card['message']);
    }

    public function test_a_custom_amount_is_accepted_the_same_way(): void
    {
        $data = $this->addItemData($this->giftCardProduct()->get_id(), [
            'store_balance_amount' => 'custom',
            'store_balance_custom_amount' => '49,90',
        ]);

        $this->assertSame(49.9, $data['cart_item_data'][GiftCardProduct::CART_KEY]['amount']);
    }

    /**
     * The same checks as the form post: the request is not a way around them.
     */
    public function test_an_amount_that_is_not_offered_is_refused(): void
    {
        $this->expectException(RouteException::class);

        $this->addItemData($this->giftCardProduct()->get_id(), ['store_balance_amount' => '7']);
    }

    public function test_a_bad_recipient_is_refused_with_the_reason(): void
    {
        try {
            $this->addItemData($this->giftCardProduct()->get_id(), ['store_balance_amount' => '50', 'store_balance_to' => 'not-an-email']);
            $this->fail('The request should have been refused.');
        } catch (RouteException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_array_values_do_not_error(): void
    {
        $this->expectException(RouteException::class);

        $this->addItemData($this->giftCardProduct()->get_id(), ['store_balance_amount' => ['50'], 'store_balance_to' => ['a@b.fi']]);
    }

    /**
     * With the details read, the compatibility filter the Store API also runs
     * must not look for them in $_POST and refuse the item.
     */
    public function test_the_classic_validation_filter_lets_the_item_through_afterwards(): void
    {
        $product = $this->giftCardProduct();

        $this->addItemData($product->get_id(), ['store_balance_amount' => '50']);

        $this->assertTrue(apply_filters('woocommerce_add_to_cart_validation', true, $product->get_id(), 1));
        $this->assertSame(0, wc_notice_count('error'));

        // And the Store API's own validation hook does not refuse it either.
        do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($product->get_id()), $this->request([]));
        $this->addToAssertionCount(1);
    }

    /**
     * No details at all is the "Add to cart" button of a product grid: still
     * refused, there is an amount to choose.
     */
    public function test_a_request_without_any_gift_card_field_is_still_refused(): void
    {
        $product = $this->giftCardProduct();
        $data = $this->addItemData($product->get_id(), []);

        $this->assertArrayNotHasKey(GiftCardProduct::CART_KEY, $data['cart_item_data']);

        $this->expectException(RouteException::class);
        do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($product->get_id()), $this->request([]));
    }

    /**
     * A batch request adds several items. The details of the first gift card
     * are not a pass for a second one sent without any.
     */
    public function test_one_checked_gift_card_does_not_let_the_next_one_through(): void
    {
        $product = $this->giftCardProduct();

        $this->addItemData($product->get_id(), ['store_balance_amount' => '50']);
        do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($product->get_id()), $this->request([]));

        $this->addItemData($product->get_id(), []);

        $this->expectException(RouteException::class);
        do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($product->get_id()), $this->request([]));
    }

    public function test_other_products_are_left_alone(): void
    {
        $data = $this->addItemData($this->product()->get_id(), ['store_balance_amount' => '50']);

        $this->assertSame([], $data['cart_item_data']);
    }
}
