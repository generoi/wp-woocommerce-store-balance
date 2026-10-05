<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Install;
use GeneroWP\StoreBalance\Modules\Orders;
use GeneroWP\StoreBalance\Plugin;

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
        $this->assertSame([], preg_grep('/^store_balance/', array_keys($order->get_order_item_totals())));
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

        $this->assertArrayHasKey('store_balance_store_credit', $rows);
        $this->assertArrayNotHasKey('store_balance_giftcard', $rows);
        $this->assertSame('Store credit:', $rows['store_balance_store_credit']['label']);
        $this->assertStringContainsString('50', wp_strip_all_tags($rows['store_balance_store_credit']['value']));
        $this->assertStringStartsWith('-', $rows['store_balance_store_credit']['value']);
        $this->assertSame(array_search('order_total', $keys, true) - 1, array_search('store_balance_store_credit', $keys, true));
    }

    /**
     * A gift card and store credit used together are two payments, and are
     * shown as two — the same way the cart showed them.
     */
    public function test_the_order_totals_have_one_row_for_each_kind_of_balance(): void
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

        $rows = $mixed->get_order_item_totals();
        $keys = array_keys($rows);
        $total = array_search('order_total', $keys, true);

        $this->assertSame('Gift card:', $rows['store_balance_giftcard']['label']);
        $this->assertSame('Store credit:', $rows['store_balance_store_credit']['label']);
        $this->assertStringContainsString('20', wp_strip_all_tags($rows['store_balance_giftcard']['value']));
        $this->assertStringContainsString('20', wp_strip_all_tags($rows['store_balance_store_credit']['value']));
        $this->assertEqualsCanonicalizing(['store_balance_giftcard', 'store_balance_store_credit'], array_slice($keys, $total - 2, 2));
        $this->assertSame(149.0, (float) $mixed->get_total());
        $this->assertSame(['giftcard' => 20.0, 'store_credit' => 20.0], Orders::byType(Orders::lines($mixed)));

        $rows = $giftOnly->get_order_item_totals();

        $this->assertSame('Gift card:', $rows['store_balance_giftcard']['label']);
        $this->assertArrayNotHasKey('store_balance_store_credit', $rows);
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

    /**
     * The same data in two shapes is two chances to get it wrong. The lines
     * are exactly these five fields.
     */
    public function test_an_order_line_has_exactly_five_fields(): void
    {
        [$order] = $this->orderPaidPartlyWithStoreCredit(50);

        $this->assertSame(['card_id', 'type', 'masked', 'amount', 'restored'], array_keys(Orders::lines($order)[0]));
        $this->assertSame(['card_id', 'type', 'masked', 'amount', 'restored'], array_keys($order->get_meta(Orders::META_LINES)[0]));
    }

    protected function usesOrderTables(): bool
    {
        return OrderUtil::custom_orders_table_usage_is_enabled();
    }

    protected function untrash(\WC_Order $order): void
    {
        if ($this->usesOrderTables()) {
            $order->get_data_store()->untrash_order($order);
        } else {
            wp_untrash_post($order->get_id());
        }
    }

    /**
     * An order can leave without ever being cancelled: an admin tidying up
     * moves it to the trash. It was holding the customer's money.
     */
    public function test_trashing_an_order_gives_the_balance_back(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('on-hold');
        $orderId = $order->get_id();

        $order->delete(false);

        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame(CardRepository::TX_RELEASE, $this->ledger($card)[2]->type);
        $this->assertSame($orderId, (int) $this->ledger($card)[2]->order_id);
        $this->assertCount(3, $this->ledger($card));
        // Whichever hook got there first, the history says what happened.
        $this->assertSame('Order removed', $this->ledger($card)[2]->note);
    }

    public function test_an_order_taken_out_of_the_trash_takes_the_balance_again(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('on-hold');
        $orderId = $order->get_id();
        $order->delete(false);

        $this->untrash(wc_get_order($orderId));

        $restored = wc_get_order($orderId);

        $this->assertSame('on-hold', $restored->get_status());
        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held($restored));
        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE, CardRepository::TX_DEBIT],
            $this->ledgerTypes($card)
        );
    }

    public function test_deleting_an_unpaid_order_for_good_gives_the_balance_back(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('on-hold');
        $orderId = $order->get_id();

        $order->delete(true);

        $this->assertSame(50.0, $this->balance($card));
        $this->assertFalse(wc_get_order($orderId));
    }

    /**
     * Tidying away an order that was paid and delivered is not undoing it:
     * the balance paid for goods the customer has.
     */
    public function test_trashing_or_deleting_a_paid_order_keeps_the_balance_spent(): void
    {
        foreach ([false, true] as $forGood) {
            [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
            $order->payment_complete();
            wc_get_order($order->get_id())->update_status('completed');

            wc_get_order($order->get_id())->delete($forGood);

            $this->assertSame(0.0, $this->balance($card), $forGood ? 'deleted' : 'trashed');
            $this->assertCount(2, $this->ledger($card));
        }
    }

    public function test_a_paid_order_trashed_and_then_deleted_keeps_the_balance_spent(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->payment_complete();
        $orderId = $order->get_id();

        wc_get_order($orderId)->delete(false);
        $trashed = wc_get_order($orderId);

        if ($trashed) {
            $trashed->delete(true);
        }

        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * Trash, then "Delete permanently": two hooks for one order.
     */
    public function test_trashing_and_then_deleting_gives_the_balance_back_once(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('on-hold');
        $orderId = $order->get_id();

        $order->delete(false);
        wc_get_order($orderId)->delete(true);

        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE], $this->ledgerTypes($card));
    }

    public function test_a_cancelled_order_taken_out_of_the_trash_stays_released(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('cancelled');
        $orderId = $order->get_id();
        $order->delete(false);

        $this->untrash(wc_get_order($orderId));

        $this->assertSame(50.0, $this->balance($card));
    }

    public function test_trashing_an_order_that_used_no_balance_or_another_post_does_nothing(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());
        $order = $this->placeOrder();
        $postId = self::factory()->post->create();

        $order->delete(false);
        wp_trash_post($postId);
        wp_untrash_post($postId);
        wp_delete_post($postId, true);

        $this->assertNull(get_post($postId));
    }

    /**
     * The order that was refused still exists, with a "pay for order" link.
     * Left at the reduced total it could be paid for less than the goods
     * cost, with no balance behind the difference.
     */
    public function test_a_refused_checkout_leaves_the_order_at_its_full_price_with_nothing_staged(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 50);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->createOrderFromCart();

        $this->assertSame(139.0, (float) $order->get_total());

        $this->cards->debit($card->id, 50);

        try {
            $this->processOrder($order);
            $this->fail('The checkout went through.');
        } catch (Exception $e) {
            $order = wc_get_order($order->get_id());
        }

        $this->assertSame(189.0, (float) $order->get_total());
        $this->assertSame('', $order->get_meta(Orders::META_PENDING));
        $this->assertSame([], Orders::lines($order));
        $this->assertSame(38.4, round((float) $order->get_total_tax(), 2));

        $order->calculate_totals();

        $this->assertSame(189.0, (float) $order->get_total());
    }

    /**
     * A staged balance is a plan, not a payment. On anything but the
     * checkout's own draft it is a leftover, and an order must never be made
     * cheaper by money that was not taken.
     */
    public function test_a_staged_balance_does_not_lower_the_total_of_an_order_that_is_not_a_draft(): void
    {
        $staged = [['card_id' => 1, 'type' => 'giftcard', 'masked' => '••••-AAAA', 'amount' => 50.0, 'restored' => 0.0]];

        foreach (['pending', 'on-hold', 'processing', 'failed'] as $status) {
            $order = wc_create_order(['status' => $status]);
            $order->add_product($this->product(), 1);
            $order->update_meta_data(Orders::META_PENDING, $staged);
            $order->calculate_totals();

            $this->assertSame(189.0, (float) $order->get_total(), $status);
        }

        $draft = wc_create_order(['status' => 'checkout-draft']);
        $draft->add_product($this->product(), 1);
        $draft->update_meta_data(Orders::META_PENDING, $staged);
        $draft->calculate_totals();

        $this->assertSame(139.0, (float) $draft->get_total());
    }

    /**
     * The abandoned attempt is cancelled, not left lying around as an unpaid
     * order at a price that assumed a balance it no longer has.
     */
    public function test_an_abandoned_order_is_cancelled_when_it_gives_its_balance_back(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        WC()->session->set('order_awaiting_payment', $order->get_id());
        WC()->cart->add_to_cart($this->product(29)->get_id());

        $retried = $this->placeOrder();
        $abandoned = wc_get_order($order->get_id());

        $this->assertNotSame($abandoned->get_id(), $retried->get_id());
        $this->assertSame('cancelled', $abandoned->get_status());
        $this->assertFalse($abandoned->needs_payment());
        $this->assertSame(0.0, Orders::held($abandoned));
        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE, CardRepository::TX_DEBIT],
            $this->ledgerTypes($card)
        );
        $this->assertSame([$order->get_id(), $order->get_id(), $retried->get_id()], array_map('intval', array_column(array_slice($this->ledger($card), 1), 'order_id')));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * Only the customer's own abandoned attempt in this session is let go.
     * Another unpaid order of theirs — a bank transfer they are about to
     * make — keeps what it holds.
     */
    public function test_an_unpaid_order_that_is_not_this_sessions_is_left_alone(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 80);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product(50)->get_id());
        $first = $this->placeOrder();

        // A new visit: nothing is awaiting payment in this session.
        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product(189)->get_id());
        $second = $this->placeOrder();

        $first = wc_get_order($first->get_id());

        $this->assertSame(50.0, Orders::held($first));
        $this->assertSame(0.0, (float) $first->get_total());
        $this->assertSame(30.0, Orders::held($second));
        $this->assertSame(159.0, (float) $second->get_total());
        $this->assertSame(0.0, $this->balance($card));
    }

    public function test_an_order_already_paid_is_not_released_even_if_the_session_still_points_at_it(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('processing');

        WC()->session->set('order_awaiting_payment', $order->get_id());
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($this->product(29)->get_id());
        $this->placeOrder();

        $this->assertSame(50.0, Orders::held(wc_get_order($order->get_id())));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * A gateway's webhook and the customer's return both cancel the order,
     * each from its own copy loaded before the other finished. Each copy
     * says "debited". Only what is in the database decides.
     */
    public function test_two_stale_copies_of_an_order_cancelling_it_return_the_balance_once(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $one = wc_get_order($order->get_id());
        $two = wc_get_order($order->get_id());

        $one->update_status('cancelled');
        $two->update_status('failed');

        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE], $this->ledgerTypes($card));
    }

    public function test_the_ledger_adds_up_through_the_whole_life_of_an_order(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(80, 50);

        $orderId = $order->get_id();

        $order->update_status('cancelled');
        wc_get_order($orderId)->update_status('processing');
        wc_get_order($orderId)->delete(false);
        $this->untrash(wc_get_order($orderId));
        wc_get_order($orderId)->update_status('refunded');

        $sum = 0.0;

        foreach ($this->ledger($card) as $i => $row) {
            $sum = round($sum + (float) $row->amount, 2);

            $this->assertSame($sum, (float) $row->balance_after, "Row {$i} ({$row->type})");
        }

        $this->assertSame(80.0, $this->balance($card));
        // The trash is not in it: the order was paid when it went there.
        $this->assertCount(5, $this->ledger($card));
    }

    /**
     * The order was placed while the card was good and cancelled after it
     * expired. The customer gets the money back in a form they can use.
     */
    public function test_a_cancelled_order_returns_its_balance_to_an_expired_card_in_usable_form(): void
    {
        global $wpdb;

        [$order, $card, $customerId] = $this->orderPaidPartlyWithStoreCredit(50);

        $wpdb->update(Install::cardsTable(), ['expires_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)], ['id' => $card->id]);

        $order->update_status('cancelled');

        $this->assertSame(50.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
    }

    /**
     * The checkout block does not use WooCommerce's "order awaiting payment"
     * session key, so the plugin keeps its own list of the orders this
     * session paid from a balance. An abandoned one has to be found there.
     */
    public function test_an_abandoned_order_is_found_through_the_plugins_own_session_list(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $this->assertSame([$order->get_id()], WC()->session->get(Orders::SESSION_ORDERS));
        $this->assertNull(WC()->session->get('order_awaiting_payment'));

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($this->product(100)->get_id());

        $second = $this->placeOrder();
        $abandoned = wc_get_order($order->get_id());

        $this->assertSame('cancelled', $abandoned->get_status());
        $this->assertSame(0.0, Orders::held($abandoned));
        // Nothing is lost and nothing is counted twice: the 50 is either on
        // the card again or held by the new order.
        $this->assertSame(50.0, round($this->balance($card) + Orders::held($second), 2));
        $this->assertSame(100.0, round((float) $second->get_total() + Orders::held($second), 2));
        $this->assertNotContains($abandoned->get_id(), WC()->session->get(Orders::SESSION_ORDERS));

        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $abandoned->get_id()])));

        $this->assertStringContainsString('placed a new order instead', $notes);
    }

    /**
     * Back at the checkout the customer has to see the balance their own
     * abandoned attempt is sitting on, or they are asked to pay in full and
     * get the balance back only after they have.
     */
    public function test_the_cart_counts_what_an_abandoned_order_in_the_plugins_list_holds(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        $this->assertNull(WC()->session->get('order_awaiting_payment'));
        $this->assertSame([$order->get_id()], WC()->session->get(Orders::SESSION_ORDERS));

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($this->product(100)->get_id());

        $this->assertSame(50.0, $this->state()['account']['available']);
        $this->assertSame(50.0, $this->cartTotal());
    }

    /**
     * The customer was at their bank in another tab: the payment for the
     * abandoned order arrives after all. WooCommerce reopens it, and a live
     * order has to hold its money again.
     */
    public function test_a_late_payment_for_an_abandoned_order_takes_the_balance_again_when_it_is_still_there(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($this->product(100)->get_id());
        $this->cart()->setUseBalance(false);
        $second = $this->placeOrder();

        $this->assertSame('cancelled', wc_get_order($order->get_id())->get_status());
        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame(100.0, (float) $second->get_total());

        wc_get_order($order->get_id())->payment_complete('late-payment');

        $first = wc_get_order($order->get_id());

        $this->assertSame('processing', $first->get_status());
        $this->assertSame(50.0, Orders::held($first));
        $this->assertSame(139.0, (float) $first->get_total());
        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE, CardRepository::TX_DEBIT],
            $this->ledgerTypes($card)
        );
    }

    /**
     * The same late payment, but the second order has spent the balance by
     * then. The first order is paid at the gateway for 139 of its 189; it
     * must not be packed and shipped as if it were paid in full.
     */
    public function test_a_late_payment_for_an_abandoned_order_whose_balance_is_gone_puts_it_on_hold(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        WC()->session->set('order_awaiting_payment', $order->get_id());
        WC()->cart->add_to_cart($this->product(29)->get_id());
        $second = $this->placeOrder();

        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held($second));

        wc_get_order($order->get_id())->payment_complete('late-payment');

        $first = wc_get_order($order->get_id());
        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $first->get_id()])));

        $this->assertSame('on-hold', $first->get_status());
        $this->assertSame(0.0, Orders::held($first));
        // What the balance no longer pays is owed again, and shown as owed.
        $this->assertSame(189.0, (float) $first->get_total());
        $this->assertSame([], Orders::lines($first));
        $this->assertSame([], preg_grep('/^store_balance/', array_keys($first->get_order_item_totals())));
        $this->assertStringContainsString('unpaid', $notes);
        $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;/i', $notes);
        // The card is not driven below zero, and the second order keeps its money.
        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held(wc_get_order($second->get_id())));
        $this->assertSame('pending', wc_get_order($second->get_id())->get_status());

        // Recalculating must not take the lost balance off again.
        $first->calculate_totals();
        $this->assertSame(189.0, (float) $first->get_total());
    }

    /**
     * An order the balance covered in full shows a total of zero. Reopened
     * after the card was emptied elsewhere, it is an unpaid order for the
     * whole amount, and has to say so.
     */
    public function test_reopening_a_fully_covered_order_whose_card_was_emptied_makes_the_whole_amount_due(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(35.15, 35.15);

        $this->assertSame(0.0, (float) $order->get_total());

        $order->update_status('cancelled');
        $this->cards->debit($card->id, 35.15);
        wc_get_order($order->get_id())->update_status('processing');

        $order = wc_get_order($order->get_id());

        $this->assertSame('on-hold', $order->get_status());
        $this->assertSame(35.15, (float) $order->get_total());
        $this->assertSame([], Orders::lines($order));
        $this->assertTrue($order->needs_payment() || $order->has_status('on-hold'));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * Two cards paid; one is still good when the order is reopened. The
     * order keeps what it could take and owes exactly the rest.
     */
    public function test_a_partly_successful_re_debit_keeps_the_paid_card_and_drops_the_other(): void
    {
        $customerId = $this->customer();
        $kept = $this->storeCredit($customerId, 30, ['expires_at' => time() + 10 * DAY_IN_SECONDS]);
        $lost = $this->storeCredit($customerId, 40, ['expires_at' => time() + 20 * DAY_IN_SECONDS]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $order = $this->placeOrder();

        $this->assertSame(119.0, (float) $order->get_total());

        $order->update_status('cancelled');
        $this->cards->debit($lost->id, 15);
        wc_get_order($order->get_id())->update_status('processing');

        $order = wc_get_order($order->get_id());

        $this->assertSame('on-hold', $order->get_status());
        $this->assertSame(159.0, (float) $order->get_total());
        $this->assertSame([$kept->id], array_column(Orders::lines($order), 'card_id'));
        $this->assertSame([$kept->id => 30.0], Orders::heldLines($order));
        $this->assertSame(0.0, $this->balance($kept));
        $this->assertSame(25.0, $this->balance($lost));
        $this->assertSame(189.0, round((float) $order->get_total() + Orders::applied($order), 2));

        // Cancelled again, it returns only what it actually holds.
        $order->update_status('cancelled');

        $this->assertSame(30.0, $this->balance($kept));
        $this->assertSame(25.0, $this->balance($lost));
    }

    /**
     * The list remembers orders, not whether they were abandoned. One that
     * has been paid, or is waiting for a bank transfer, is not abandoned.
     */
    public function test_a_paid_or_on_hold_order_still_in_the_session_list_is_left_alone(): void
    {
        foreach (['on-hold', 'processing', 'completed'] as $status) {
            [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
            $order->update_status($status);

            $this->assertContains($order->get_id(), WC()->session->get(Orders::SESSION_ORDERS));

            WC()->cart->empty_cart();
            WC()->cart->add_to_cart($this->product(29)->get_id());
            $second = $this->placeOrder();

            $first = wc_get_order($order->get_id());

            $this->assertSame($status, $first->get_status());
            $this->assertSame(50.0, Orders::held($first), $status);
            $this->assertSame(0.0, $this->balance($card), $status);
            $this->assertSame(29.0, (float) $second->get_total(), $status);
        }
    }

    /**
     * Marked refunded by mistake and set back to processing: the balance
     * went back to the card, so the order has to take it again.
     */
    public function test_an_order_reopened_after_being_marked_refunded_takes_the_balance_again(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('processing');
        $order->update_status('refunded');

        $this->assertSame(50.0, $this->balance($card));

        wc_get_order($order->get_id())->update_status('processing');

        $order = wc_get_order($order->get_id());

        $this->assertSame('processing', $order->get_status());
        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held($order));
        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_REFUND, CardRepository::TX_DEBIT],
            $this->ledgerTypes($card)
        );
    }

    /**
     * Order tables announce a trashed order twice: through the trash hook
     * and as a change of status. Twice told, once returned — and the same
     * coming back.
     */
    public function test_trashing_and_restoring_twice_moves_the_balance_once_each_time(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('on-hold');
        $orderId = $order->get_id();

        $expected = [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT];

        foreach ([1, 2] as $round) {
            wc_get_order($orderId)->delete(false);
            $expected[] = CardRepository::TX_RELEASE;

            $this->assertSame(50.0, $this->balance($card), "Trash {$round}");
            $this->assertSame($expected, $this->ledgerTypes($card), "Trash {$round}");

            $this->untrash(wc_get_order($orderId));
            $expected[] = CardRepository::TX_DEBIT;

            $this->assertSame(0.0, $this->balance($card), "Restore {$round}");
            $this->assertSame($expected, $this->ledgerTypes($card), "Restore {$round}");
            $this->assertSame('on-hold', wc_get_order($orderId)->get_status());
        }
    }

    /**
     * Restored from the trash after the customer spent what came back: the
     * same rule as any reopened order that falls short.
     */
    public function test_an_order_restored_from_the_trash_without_its_balance_is_put_on_hold(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('on-hold');
        $orderId = $order->get_id();

        wc_get_order($orderId)->delete(false);
        $this->cards->debit($card->id, 50);
        $this->untrash(wc_get_order($orderId));

        $this->assertSame('on-hold', wc_get_order($orderId)->get_status());
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * Two gift cards and a store credit on one order: two kinds, two rows,
     * and the rows add up to what was taken off the total.
     */
    public function test_an_order_paid_with_several_cards_of_both_kinds_adds_them_up_per_kind(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 15.5);
        $first = $this->giftCard(20);
        $second = $this->giftCard(30.25);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($first->code);
        $this->cart()->applyCode($second->code);

        $order = $this->placeOrder();
        $rows = $order->get_order_item_totals();

        $this->assertSame(['giftcard' => 50.25, 'store_credit' => 15.5], Orders::byType(Orders::lines($order)));
        $this->assertSame(65.75, Orders::applied($order));
        $this->assertSame(123.25, (float) $order->get_total());
        $this->assertStringContainsString('50', wp_strip_all_tags($rows['store_balance_giftcard']['value']));
        $this->assertStringContainsString('25', wp_strip_all_tags($rows['store_balance_giftcard']['value']));
        $this->assertStringContainsString('15', wp_strip_all_tags($rows['store_balance_store_credit']['value']));
        $this->assertSame([], Orders::byType([]));
        $this->assertSame(['store_credit' => 5.0], Orders::byType([['type' => 'store_credit', 'amount' => 5], ['type' => 'voucher', 'amount' => 9]]));
    }

    /**
     * The order total is only what the gateway was paid. Refunding that used
     * to make WooCommerce call the order refunded in full, and all of the
     * balance went back while the customer kept the rest of the goods.
     */
    public function test_refunding_what_the_gateway_was_paid_does_not_return_the_balance(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->payment_complete();
        $order = wc_get_order($order->get_id());

        $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'One item returned']);

        $this->assertNotWPError($refund);
        $order = wc_get_order($order->get_id());
        $this->assertSame('processing', $order->get_status());
        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held($order));
        $this->assertStringContainsString('set the order to Refunded', implode(' ', array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()]))));

        // Asked for in so many words, it does go back.
        $order->update_status('refunded');
        $this->assertSame(50.0, $this->balance($card));
    }

    public function test_a_zero_refund_on_an_order_paid_in_full_by_a_balance_returns_nothing(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(200, 100);
        $order = wc_get_order($order->get_id());
        $this->assertSame(0.0, (float) $order->get_total());

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 0, 'reason' => 'Restock']);

        $this->assertNotSame('refunded', wc_get_order($order->get_id())->get_status());
        $this->assertSame(100.0, Orders::held(wc_get_order($order->get_id())));
    }

    public function test_an_order_without_a_balance_is_still_marked_refunded_by_a_full_refund(): void
    {
        WC()->cart->add_to_cart($this->product(100)->get_id());
        $order = $this->placeOrder();
        $order->payment_complete();

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => wc_get_order($order->get_id())->get_total()]);

        $this->assertSame('refunded', wc_get_order($order->get_id())->get_status());
    }

    /**
     * A failed order going back to "pending" is a checkout starting over. It
     * must not take the balance, fall short and end up on hold: the checkout
     * would then finish an order nobody was asked to pay for.
     */
    public function test_a_failed_order_going_back_to_pending_is_left_for_the_checkout(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('failed');
        $this->cards->debit($card->id, 50, ['note' => 'Spent elsewhere']);

        $order = wc_get_order($order->get_id());
        $order->update_status('pending');

        $order = wc_get_order($order->get_id());
        $this->assertSame('pending', $order->get_status());
        $this->assertTrue($order->needs_payment());
        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame('', $order->get_meta(Orders::META_SHORT));
    }

    public function test_a_payment_for_an_order_whose_balance_is_gone_never_reaches_processing(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('failed');
        $this->cards->debit($card->id, 50, ['note' => 'Spent elsewhere']);

        $reached = [];
        $spy = static function ($id) use (&$reached): void {
            $reached[] = $id;
        };
        add_action('woocommerce_order_status_processing', $spy);

        wc_get_order($order->get_id())->payment_complete('txn_1');

        remove_action('woocommerce_order_status_processing', $spy);

        $order = wc_get_order($order->get_id());
        $this->assertSame([], $reached);
        $this->assertSame('on-hold', $order->get_status());
        $this->assertSame(189.0, (float) $order->get_total());
        $this->assertSame(50.0, (float) $order->get_meta(Orders::META_SHORT));

        // The gateway saying so again changes nothing.
        $order->payment_complete('txn_1');
        $this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());

        // A person does.
        wc_get_order($order->get_id())->update_status('processing');
        $order = wc_get_order($order->get_id());
        $this->assertSame('processing', $order->get_status());
        $this->assertSame('', $order->get_meta(Orders::META_SHORT));
    }

    /**
     * The checkout saved the order at the reduced total and broke off before
     * taking the balance. Whoever pays it must not get the balance for free.
     */
    public function test_a_balance_that_was_staged_but_never_taken_is_taken_when_the_order_is_paid(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 50);
        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $order = $this->createOrderFromCart();
        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame(50.0, $this->balance($card));

        wc_get_order($order->get_id())->payment_complete();

        $order = wc_get_order($order->get_id());
        $this->assertSame('processing', $order->get_status());
        $this->assertSame(0.0, $this->balance($card));
        $this->assertSame(50.0, Orders::held($order));
        $this->assertEmpty($order->get_meta(Orders::META_PENDING));
    }

    public function test_the_pay_page_of_an_order_whose_balance_was_never_taken_asks_the_full_price(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 50);
        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $order = $this->createOrderFromCart();

        set_query_var('order-pay', $order->get_id());
        Plugin::getInstance()->module(Orders::class)->beforePayPage();
        set_query_var('order-pay', '');

        $order = wc_get_order($order->get_id());
        $this->assertSame(189.0, (float) $order->get_total());
        $this->assertEmpty($order->get_meta(Orders::META_PENDING));
        $this->assertSame(50.0, $this->balance($card));

        // Paid in full through the gateway: the balance is not touched.
        $order->payment_complete();
        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame('processing', wc_get_order($order->get_id())->get_status());
    }

    public function test_the_pay_page_of_a_failed_order_asks_the_full_price_and_leaves_the_card_alone(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);
        $order->update_status('failed');
        $this->assertSame(50.0, $this->balance($card));

        set_query_var('order-pay', $order->get_id());
        Plugin::getInstance()->module(Orders::class)->beforePayPage();
        set_query_var('order-pay', '');

        $order = wc_get_order($order->get_id());
        $this->assertSame(189.0, (float) $order->get_total());
        $this->assertSame([], Orders::lines($order));

        $order->payment_complete();
        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame('processing', wc_get_order($order->get_id())->get_status());
    }

    public function test_the_pay_page_of_an_order_that_holds_its_balance_changes_nothing(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(50);

        set_query_var('order-pay', $order->get_id());
        Plugin::getInstance()->module(Orders::class)->beforePayPage();
        set_query_var('order-pay', '');

        $order = wc_get_order($order->get_id());
        $this->assertSame(139.0, (float) $order->get_total());
        $this->assertSame(50.0, Orders::held($order));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * A currency switcher changes the price decimals with the currency of the
     * request. What goes back to a card is what was taken from it.
     */
    public function test_a_balance_returned_from_a_request_without_decimals_is_returned_to_the_cent(): void
    {
        [$order, $card] = $this->orderPaidPartlyWithStoreCredit(10.55);

        add_filter('wc_get_price_decimals', '__return_zero');
        wc_get_order($order->get_id())->update_status('cancelled');
        remove_filter('wc_get_price_decimals', '__return_zero');

        $this->assertSame(10.55, $this->balance($card));
    }
}
