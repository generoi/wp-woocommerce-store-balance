<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use Exception;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Modules\Orders;

class OrdersTest extends TestCase
{
    /**
     * Between "the order exists" and "the customer confirmed it" nothing may
     * be taken: a checkout that fails validation must cost the customer
     * nothing.
     */
    public function test_creating_the_order_writes_down_the_balance_without_taking_it(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 50);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->createOrderFromCart();

        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame([[
            'card_id' => $card->id,
            'type' => 'store_credit',
            'masked' => $card->reference(),
            'amount' => 50.0,
            'restored' => 0.0,
        ]], $order->get_meta(Orders::META_PENDING));
        $this->assertSame([], Orders::lines($order));
        $this->assertSame(0.0, Orders::held($order));
    }

    public function test_placing_the_order_takes_the_money_from_the_card(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame(Orders::STATE_DEBITED, $order->get_meta(Orders::META_STATE));
        $this->assertSame(50.0, Orders::applied($order));
        $this->assertSame(50.0, Orders::held($order));
        $this->assertSame([$card->id => 50.0], Orders::heldLines($order));
        $this->assertSame('', $order->get_meta(Orders::META_PENDING));

        $debit = $this->ledger($card)[1];

        $this->assertSame(CardRepository::TX_DEBIT, $debit->type);
        $this->assertSame(-50.0, (float) $debit->amount);
        $this->assertSame(0.0, (float) $debit->balance_after);
        $this->assertSame($order->get_id(), (int) $debit->order_id);
    }

    /**
     * The order is what the bookkeeping sees. Its VAT has to be the VAT on
     * the goods, whatever part of them a gift card paid for.
     */
    public function test_the_order_keeps_the_full_vat(): void
    {
        [$order] = $this->orderPaidPartlyWithStoreCredit(50);

        $this->assertSame(38.4, round((float) $order->get_total_tax(), 2));
        $this->assertSame(0.0, (float) $order->get_discount_total());
        $this->assertSame(189.0, round((float) $order->get_subtotal() + (float) $order->get_total_tax(), 2));
    }

    public function test_partial_use_leaves_the_rest_on_the_card(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(500);

        $this->assertSame(311.0, $this->balance($card));
        $this->assertSame(0.0, (float) $order->get_total());
        $this->assertSame(189.0, Orders::applied($order));
        $this->assertFalse($order->needs_payment());
    }

    public function test_a_gift_card_code_typed_by_a_guest_is_debited(): void
    {
        $card = $this->giftCard(50);

        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        $order = $this->placeOrder();

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame('giftcard', Orders::lines($order)[0]['type']);
    }

    public function test_an_order_without_a_balance_carries_nothing(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->placeOrder();

        $this->assertSame(189.0, (float) $order->get_total());
        $this->assertSame([], Orders::lines($order));
        $this->assertSame('', $order->get_meta(Orders::META_STATE));
        $this->assertArrayNotHasKey('store_balance', $order->get_order_item_totals());
    }

    public function test_the_gift_cards_in_the_order_are_not_paid_from_the_balance(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 500);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']);

        $order = $this->placeOrder();

        $this->assertSame(100.0, (float) $order->get_total());
        $this->assertSame(311.0, $this->balance($card));
    }

    /**
     * The cart was calculated a moment ago; the card was emptied since — in
     * another tab, by another order. The customer must not get goods for a
     * balance that is gone, and must not lose the part that was still there.
     */
    public function test_when_a_card_cannot_cover_its_share_the_checkout_stops_and_nothing_stays_debited(): void
    {
        $customerId = $this->customer();
        $first = $this->storeCredit($customerId, 30, ['expires_at' => time() + 10 * DAY_IN_SECONDS]);
        $second = $this->storeCredit($customerId, 40, ['expires_at' => time() + 20 * DAY_IN_SECONDS]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->createOrderFromCart();

        $this->assertSame(119.0, (float) $order->get_total());

        // Spent elsewhere between the calculation and the click.
        $this->cards->debit($second->id, 40);

        $stopped = false;

        try {
            $this->processOrder($order);
        } catch (Exception $e) {
            $stopped = true;
        }

        $order = wc_get_order($order->get_id());

        $this->assertTrue($stopped, 'The checkout went through.');
        $this->assertSame(30.0, $this->balance($first));
        $this->assertSame(0.0, $this->balance($second));
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE], $this->ledgerTypes($first));
        $this->assertSame(30.0, (float) $this->ledger($first)[2]->balance_after);
        $this->assertSame([], Orders::lines($order));
        $this->assertSame(0.0, Orders::held($order));
        $this->assertNotSame(Orders::STATE_DEBITED, $order->get_meta(Orders::META_STATE));
    }

    /**
     * After the checkout was stopped the customer sees the cart again. It has
     * to show the new, smaller balance — not ask them to confirm figures that
     * will fail a second time.
     */
    public function test_after_a_stopped_checkout_the_cart_shows_the_balance_as_it_is_now(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 50);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->createOrderFromCart();
        $this->cards->debit($card->id, 20);

        try {
            $this->processOrder($order);
            $this->fail('The checkout went through.');
        } catch (Exception $e) {
            $this->assertStringContainsString('balance has changed', $e->getMessage());
        }

        $this->assertSame(30.0, $this->balance($card));
        $this->assertSame(30.0, $this->cart()->state()['applied_total']);
        $this->assertSame(159.0, (float) WC()->cart->get_total('edit'));
    }

    /**
     * Two people have the same email with the code. One adds the card to
     * their account while the other is at the checkout with the code typed
     * in. The card now belongs to the account; the checkout must not take it.
     */
    public function test_a_code_claimed_by_an_account_after_the_cart_was_calculated_is_not_debited(): void
    {
        $card = $this->giftCard(50);

        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        $order = $this->createOrderFromCart();
        $this->cards->redeem($card->id, $this->customer());

        try {
            $this->processOrder($order);
            $this->fail('The checkout went through.');
        } catch (Exception $e) {
            $this->assertSame(50.0, $this->balance($card));
            $this->assertSame(0.0, Orders::held(wc_get_order($order->get_id())));
        }
    }

    public function test_a_cancelled_order_gives_the_balance_back_once(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->update_status('cancelled');

        $this->assertSame(50.0, $this->balance($card));

        $order = wc_get_order($order->get_id());

        $this->assertSame(Orders::STATE_RELEASED, $order->get_meta(Orders::META_STATE));
        $this->assertSame(0.0, Orders::held($order));
        $this->assertSame(50.0, Orders::lines($order)[0]['restored']);

        $release = $this->ledger($card)[2];

        $this->assertSame(CardRepository::TX_RELEASE, $release->type);
        $this->assertSame(50.0, (float) $release->amount);
        $this->assertSame($order->get_id(), (int) $release->order_id);

        // Whatever happens to a dead order afterwards, the money went back once.
        $order->update_status('failed');
        $order->update_status('cancelled');
        $order->update_status('refunded');

        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE], $this->ledgerTypes($card));
    }

    public function test_a_failed_payment_gives_the_balance_back(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->update_status('failed');

        $this->assertSame(50.0, $this->balance($card));
    }

    /**
     * A full refund through WooCommerce ends in the status "refunded". What
     * the gateway took goes back through the gateway; what the balance paid
     * has to go back to the balance, and be recorded as a refund.
     */
    public function test_a_refunded_order_gives_the_balance_back_as_a_refund(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('processing');

        $order->update_status('refunded');

        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame(CardRepository::TX_REFUND, $this->ledger($card)[2]->type);
    }

    public function test_a_paid_order_keeps_the_money(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->update_status('on-hold');
        $order->update_status('processing');
        $order->update_status('completed');

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_DEBIT], $this->ledgerTypes($card));
    }

    /**
     * An admin reopens a cancelled order, or a failed payment goes through on
     * a later attempt. The order is alive again, so it has to hold the money
     * again — otherwise the customer keeps both the goods and the balance.
     */
    public function test_reopening_a_cancelled_order_takes_the_balance_again(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->update_status('cancelled');
        $order->update_status('processing');

        $order = wc_get_order($order->get_id());

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(Orders::STATE_DEBITED, $order->get_meta(Orders::META_STATE));
        $this->assertSame(50.0, Orders::held($order));
        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE, CardRepository::TX_DEBIT],
            $this->ledgerTypes($card)
        );

        // And it can be cancelled again, with the same result as the first time.
        $order->update_status('cancelled');

        $this->assertSame(50.0, $this->balance($card));
    }

    public function test_a_failed_order_paid_later_takes_the_balance_again(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->update_status('failed');
        $order->update_status('on-hold');

        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * The balance went back and the customer spent it on something else.
     * The card must not go negative; a person has to sort out the order.
     */
    public function test_reopening_an_order_whose_balance_was_spent_elsewhere_flags_it_for_a_person(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->update_status('cancelled');
        $this->cards->debit($card->id, 40);
        $order->update_status('processing');

        $this->assertSame(10.0, $this->balance($card));

        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertStringContainsString('spent elsewhere', $notes);

        // It does not hold what it could not take, so it cannot give it back.
        wc_get_order($order->get_id())->update_status('cancelled');

        $this->assertSame(10.0, $this->balance($card));
    }

    /**
     * "Recalculate" on the order screen, or any plugin that calls
     * calculate_totals(), adds the items up from scratch. Without the
     * correction the order would suddenly be unpaid by the gift card amount.
     */
    public function test_recalculating_the_order_keeps_what_the_balance_paid(): void
    {
        [$order] = $this->orderPaidPartlyWithStoreCredit(50);

        $order->calculate_totals();
        $this->assertSame(139.0, (float) $order->get_total());

        $order->calculate_totals();
        $order->save();

        $order = wc_get_order($order->get_id());
        $order->calculate_totals();

        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame(38.4, round((float) $order->get_total_tax(), 2));
    }

    public function test_recalculating_an_order_paid_in_full_by_a_balance_keeps_it_at_zero(): void
    {
        [$order] = $this->orderPaidPartlyWithStoreCredit(500);

        $order->calculate_totals();

        $this->assertSame(0.0, (float) $order->get_total());
    }

    /**
     * The customer and the bookkeeper both need to see why the items add up
     * to more than was charged. The row sits right above the total.
     */
    public function test_the_order_totals_show_what_the_balance_paid(): void
    {
        [$order] = $this->orderPaidPartlyWithStoreCredit(50);

        $rows = $order->get_order_item_totals();
        $keys = array_keys($rows);

        $this->assertArrayHasKey('store_balance', $rows);
        $this->assertSame('Store credit:', $rows['store_balance']['label']);
        $this->assertStringContainsString('50', wp_strip_all_tags($rows['store_balance']['value']));
        $this->assertStringStartsWith('-', $rows['store_balance']['value']);
        $this->assertSame(array_search('order_total', $keys, true) - 1, array_search('store_balance', $keys, true));
    }

    public function test_the_totals_row_is_named_after_what_paid(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 20);
        $code = $this->giftCard(20);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($code->code);
        $mixed = $this->placeOrder();

        $this->actAs(0);
        $code = $this->giftCard(20);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($code->code);
        $giftOnly = $this->placeOrder();

        $this->assertSame('Gift card & store credit:', $mixed->get_order_item_totals()['store_balance']['label']);
        $this->assertSame('Gift card:', $giftOnly->get_order_item_totals()['store_balance']['label']);
    }

    public function test_an_order_note_records_what_was_paid_from_which_card(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertStringContainsString($card->reference(), $notes);
        $this->assertStringNotContainsString($card->code, $notes);
        $this->assertStringNotContainsString($card->formattedCode(), $notes);
        // Store credit has no code anyone ever sees — not even its last four.
        $this->assertStringNotContainsString(substr($card->code, -4), $notes);
    }

    /**
     * The payment failed and the customer is back at the checkout with the
     * same order. Its debit is still standing, so the card looks empty; the
     * cart has to count what the order holds as still theirs, or it would
     * ask for the full price.
     */
    public function test_a_retried_checkout_sees_what_the_order_already_holds(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        WC()->session->set('order_awaiting_payment', $order->get_id());

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(139.0, $this->cartTotal());
        $this->assertSame(50.0, $this->state()['account']['available']);
    }

    public function test_a_retried_checkout_does_not_debit_the_card_twice(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(500);

        WC()->session->set('order_awaiting_payment', $order->get_id());

        $retried = $this->placeOrder();

        $this->assertSame($order->get_id(), $retried->get_id(), 'WooCommerce did not resume the order.');
        $this->assertSame(311.0, $this->balance($card));
        $this->assertSame(0.0, (float) $retried->get_total());
        $this->assertSame(189.0, Orders::held($retried));
        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE, CardRepository::TX_DEBIT],
            $this->ledgerTypes($card)
        );
    }

    /**
     * On the retry the customer unticks "Use my balance" and pays the lot by
     * card. WooCommerce sees a different total and starts a new order; the
     * first one is abandoned as "pending payment" and must not keep the money.
     */
    public function test_a_retry_without_the_balance_returns_what_the_first_attempt_took(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        WC()->session->set('order_awaiting_payment', $order->get_id());
        $this->cart()->setUseBalance(false);

        $retried = $this->placeOrder();

        $this->assertSame(189.0, (float) $retried->get_total());
        $this->assertSame(0.0, Orders::held($retried));
        $this->assertSame(0.0, Orders::held(wc_get_order($order->get_id())), 'The abandoned order still holds the balance.');
        $this->assertSame(50.0, $this->balance($card));
    }

    /**
     * The payment page was closed, the customer came back and added a pair of
     * socks. WooCommerce starts a new order because the cart changed. The
     * cart tells them their balance is still there (it counts what the first
     * order holds), so the checkout has to be able to take it.
     */
    public function test_a_retry_with_a_changed_cart_can_still_be_paid_with_the_balance(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        WC()->session->set('order_awaiting_payment', $order->get_id());
        WC()->cart->add_to_cart($this->product(29)->get_id());

        $this->assertSame(168.0, $this->cartTotal());
        $this->assertSame(50.0, $this->state()['applied_total']);

        try {
            $retried = $this->placeOrder();
        } catch (Exception $e) {
            $this->fail('The checkout was stopped: '.$e->getMessage());
        }

        $this->assertNotSame($order->get_id(), $retried->get_id());
        $this->assertSame(168.0, (float) $retried->get_total());
        $this->assertSame(50.0, Orders::held($retried));
        $this->assertSame(0.0, Orders::held(wc_get_order($order->get_id())));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * The checkout block creates a draft order and recalculates it from its
     * items on every step. The balance has to survive that, and an order it
     * covers in full must not ask for a payment method.
     */
    public function test_the_checkout_block_draft_is_lowered_and_then_debited(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 50);

        $this->actAs($customerId);
        $product = $this->product();
        WC()->cart->add_to_cart($product->get_id());
        WC()->cart->calculate_totals();

        $order = wc_create_order(['status' => 'checkout-draft', 'customer_id' => $customerId]);
        $order->add_product($product, 1);
        $order->calculate_totals();

        $this->assertSame(189.0, (float) $order->get_total());

        do_action('woocommerce_store_api_checkout_update_order_meta', $order);

        $order = wc_get_order($order->get_id());

        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame(50.0, $this->balance($card));

        // The Store API recalculates the draft again before taking payment.
        $order->calculate_totals();
        $this->assertSame(139.0, (float) $order->get_total());

        do_action('woocommerce_store_api_checkout_order_processed', $order);

        $order = wc_get_order($order->get_id());

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held($order));

        $order->calculate_totals();
        $this->assertSame(139.0, (float) $order->get_total());
    }

    /**
     * The card's code is the money. Order meta is read by exports, webhooks
     * and other plugins; only the masked code belongs there.
     */
    public function test_the_order_never_stores_the_full_code(): void
    {
        $card = $this->giftCard(50);

        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        $order = $this->placeOrder();
        $meta = wp_json_encode(array_map(static fn ($meta) => $meta->get_data(), $order->get_meta_data()));

        $this->assertStringContainsString(substr($card->code, -4), $meta);
        $this->assertStringNotContainsString($card->code, $meta);
    }
}
