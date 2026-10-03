<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Modules\Emails;
use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use GeneroWP\StoreBalance\Modules\Issuance;
use GeneroWP\StoreBalance\Plugin;
use WC_Order;

class IssuanceTest extends TestCase
{
    /**
     * An order for gift cards, placed through the checkout and not yet paid.
     *
     * @param  array<string, string>  $input
     * @param  array<string, string>  $productMeta
     */
    protected function giftCardOrder(array $input = [], int $quantity = 1, array $productMeta = []): WC_Order
    {
        $this->addGiftCardToCart($this->giftCardProduct([25.0, 50.0, 100.0], true, $productMeta), $input + [
            'store_balance_amount' => '50',
            'store_balance_to' => 'friend@example.org',
            'store_balance_from' => 'Aino',
            'store_balance_message' => 'Hyvää syntymäpäivää!',
        ], $quantity);

        return $this->placeOrder();
    }

    /**
     * @return Card[]
     */
    protected function cardsOf(WC_Order $order): array
    {
        return array_reverse($this->cards->forOrder($order->get_id()));
    }

    /**
     * A gift card is money. It must not exist before it has been paid for.
     */
    public function test_no_card_exists_before_the_order_is_paid(): void
    {
        $order = $this->giftCardOrder();

        $this->assertSame([], $this->cardsOf($order));

        $order->update_status('on-hold');

        $this->assertSame([], $this->cardsOf($order));
        $this->assertCount(0, $this->emailsTo('friend@example.org'));
    }

    public function test_a_paid_order_creates_the_card_the_buyer_described(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $cards = $this->cardsOf($order);

        $this->assertCount(1, $cards);

        $card = $cards[0];
        $item = current(wc_get_order($order->get_id())->get_items());

        $this->assertTrue($card->isGiftCard());
        $this->assertSame(50.0, $card->balance);
        $this->assertSame('EUR', $card->currency);
        $this->assertSame('friend@example.org', $card->recipientEmail);
        $this->assertSame('Aino', $card->senderName);
        $this->assertSame('Hyvää syntymäpäivää!', $card->message);
        $this->assertSame($order->get_id(), $card->orderId);
        $this->assertSame($item->get_id(), $card->orderItemId);
        $this->assertSame(0, $card->customerId);
        $this->assertSame([$card->id], $item->get_meta(Issuance::ITEM_CARDS));
        $this->assertSame($order->get_id(), (int) $this->ledger($card)[0]->order_id);
    }

    public function test_one_card_is_created_for_each_one_bought(): void
    {
        $order = $this->giftCardOrder([], 3);
        $order->payment_complete();

        $cards = $this->cardsOf($order);

        $this->assertCount(3, $cards);
        $this->assertCount(3, array_unique(array_column($cards, 'code')));
        $this->assertSame([50.0, 50.0, 50.0], array_column($cards, 'balance'));
        $this->assertCount(3, $this->emailsTo('friend@example.org'));
    }

    public function test_two_different_gift_cards_in_one_order_each_get_their_own_card(): void
    {
        $product = $this->giftCardProduct();

        $this->addGiftCardToCart($product, ['store_balance_amount' => '25', 'store_balance_to' => 'a@example.org']);
        $this->addGiftCardToCart($product, ['store_balance_amount' => 'custom', 'store_balance_custom_amount' => '120', 'store_balance_to' => 'b@example.org']);

        $order = $this->placeOrder();

        $this->assertSame(145.0, (float) $order->get_total());

        $order->payment_complete();
        $cards = $this->cardsOf($order);

        $this->assertSame(['a@example.org' => 25.0, 'b@example.org' => 120.0], array_column($cards, 'balance', 'recipientEmail'));
    }

    /**
     * A gateway calls payment_complete, which sets the status, which fires
     * the status hooks; an admin then completes the order. Every one of
     * those is a reason to issue. The buyer paid for one card.
     */
    public function test_a_card_is_issued_once_however_many_hooks_fire(): void
    {
        $order = $this->giftCardOrder([], 2);

        $order->payment_complete();
        do_action('woocommerce_payment_complete', $order->get_id());
        $order = wc_get_order($order->get_id());
        $order->update_status('processing');
        $order->update_status('completed');
        do_action('woocommerce_order_status_completed', $order->get_id());

        $this->assertCount(2, $this->cardsOf($order));
        $this->assertCount(2, $this->emailsTo('friend@example.org'));
    }

    /**
     * There is nothing to pack. An order of only gift cards that waited in
     * "processing" would wait for someone to click it for no reason.
     */
    public function test_an_order_of_only_gift_cards_completes_on_payment(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
    }

    public function test_an_order_with_goods_as_well_waits_to_be_shipped_but_issues_the_card(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $this->assertSame('processing', wc_get_order($order->get_id())->get_status());
        $this->assertCount(1, $this->cardsOf($order));
    }

    /**
     * Bought for oneself: the card goes to the billing email, and there is
     * no "from" — a gift from yourself reads oddly in the email.
     */
    public function test_a_card_with_no_recipient_goes_to_the_buyer(): void
    {
        $order = $this->giftCardOrder(['store_balance_to' => '', 'store_balance_from' => 'Aino']);
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];

        $this->assertSame('aino@example.org', $card->recipientEmail);
        $this->assertSame('', $card->senderName);
        $this->assertCount(1, array_filter(
            $this->emailsTo('aino@example.org'),
            static fn (object $mail) => str_contains($mail->body, $card->formattedCode())
        ));
    }

    public function test_a_gift_without_a_senders_name_is_from_the_buyer(): void
    {
        $order = $this->giftCardOrder(['store_balance_from' => '']);
        $order->payment_complete();

        $this->assertSame('Aino Virtanen', $this->cardsOf($order)[0]->senderName);
    }

    /**
     * No VAT is due when a multi-purpose voucher is sold; it is due when the
     * voucher is spent. Charging it here would charge it twice.
     */
    public function test_a_gift_card_is_sold_without_vat(): void
    {
        $order = $this->giftCardOrder();

        $this->assertSame(50.0, (float) $order->get_total());
        $this->assertSame(0.0, (float) $order->get_total_tax());
    }

    public function test_a_new_gift_card_is_valid_for_two_years_by_default(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $this->assertEqualsWithDelta(time() + 730 * DAY_IN_SECONDS, $this->cardsOf($order)[0]->expiresAt, 5);
    }

    public function test_a_product_can_set_its_own_validity(): void
    {
        $order = $this->giftCardOrder([], 1, [GiftCardProduct::META_EXPIRY => '30']);
        $order->payment_complete();

        $this->assertEqualsWithDelta(time() + 30 * DAY_IN_SECONDS, $this->cardsOf($order)[0]->expiresAt, 5);
    }

    public function test_a_product_valid_for_zero_days_never_expires(): void
    {
        $order = $this->giftCardOrder([], 1, [GiftCardProduct::META_EXPIRY => '0']);
        $order->payment_complete();

        $this->assertNull($this->cardsOf($order)[0]->expiresAt);
    }

    public function test_the_card_is_emailed_to_the_recipient_with_its_code(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];
        $mails = $this->emailsTo('friend@example.org');

        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Aino', $mails[0]->subject);
        $this->assertStringContainsString('50', $mails[0]->subject);
        $this->assertStringContainsString($card->formattedCode(), $mails[0]->body);
        $this->assertStringContainsString('Hyvää syntymäpäivää!', $mails[0]->body);
        $this->assertNotNull($card->deliveredAt);
    }

    /**
     * The code belongs to the recipient. The buyer's order confirmation, and
     * the order notes an admin reads, only ever show the last four characters.
     */
    public function test_the_buyer_never_sees_the_code_of_a_card_sent_to_someone_else(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];
        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertStringContainsString($card->maskedCode(), $notes);
        $this->assertStringNotContainsString($card->formattedCode(), $notes);

        foreach ($this->emailsTo('aino@example.org') as $mail) {
            $this->assertStringNotContainsString($card->formattedCode(), $mail->body);
            $this->assertStringNotContainsString($card->code, $mail->body);
        }
    }

    /**
     * A birthday present bought a week early. The card exists as soon as it
     * is paid for — the money has changed hands — but the email waits, and at
     * 08:00 shop time rather than midnight.
     */
    public function test_a_card_with_a_delivery_date_is_scheduled_instead_of_sent(): void
    {
        $date = (new \DateTimeImmutable('+10 days', wp_timezone()))->format('Y-m-d');
        $order = $this->giftCardOrder(['store_balance_delivery' => $date]);
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];
        $expected = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone())->setTime(8, 0)->getTimestamp();

        $this->assertSame($expected, $card->deliverAt);
        $this->assertNull($card->deliveredAt);
        $this->assertCount(0, $this->emailsTo('friend@example.org'));
        $this->assertSame($expected, as_next_scheduled_action(Emails::ACTION_DELIVER, [$card->id], 'wc-store-balance'));
    }

    public function test_the_scheduled_action_sends_the_card(): void
    {
        $date = (new \DateTimeImmutable('+10 days', wp_timezone()))->format('Y-m-d');
        $order = $this->giftCardOrder(['store_balance_delivery' => $date]);
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];

        do_action(Emails::ACTION_DELIVER, $card->id);

        $mails = $this->emailsTo('friend@example.org');

        $this->assertCount(1, $mails);
        $this->assertStringContainsString($card->formattedCode(), $mails[0]->body);
        $this->assertNotNull($this->cards->find($card->id)->deliveredAt);
    }

    /**
     * The recipient should get the full two years, not two years minus the
     * time the card sat waiting to be sent.
     */
    public function test_the_validity_of_a_scheduled_card_counts_from_its_delivery(): void
    {
        $date = (new \DateTimeImmutable('+10 days', wp_timezone()))->format('Y-m-d');
        $order = $this->giftCardOrder(['store_balance_delivery' => $date]);
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];

        $this->assertSame($card->deliverAt + 730 * DAY_IN_SECONDS, $card->expiresAt);
    }

    /**
     * The order was cancelled between payment and the delivery date. The
     * scheduled email must not hand out a card that has been withdrawn.
     */
    public function test_a_withdrawn_card_is_not_sent_when_its_delivery_date_comes(): void
    {
        $date = (new \DateTimeImmutable('+10 days', wp_timezone()))->format('Y-m-d');
        $order = $this->giftCardOrder(['store_balance_delivery' => $date]);
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];

        wc_get_order($order->get_id())->update_status('cancelled');
        do_action(Emails::ACTION_DELIVER, $card->id);

        $this->assertCount(0, $this->emailsTo('friend@example.org'));
    }

    public function test_cancelling_the_order_withdraws_its_cards(): void
    {
        $order = $this->giftCardOrder([], 2);
        $order->payment_complete();

        $order = wc_get_order($order->get_id());
        $order->update_status('cancelled');

        $cards = $this->cardsOf($order);

        $this->assertSame([Card::STATUS_DISABLED, Card::STATUS_DISABLED], array_column($cards, 'status'));
        $this->assertFalse($this->cards->debit($cards[0]->id, 10));
        $this->assertSame(CardRepository::TX_DISABLE, $this->ledger($cards[0])[1]->type);
        $this->assertEqualsCanonicalizing(array_column($cards, 'id'), wc_get_order($order->get_id())->get_meta(Issuance::ORDER_DISABLED));
    }

    public function test_a_full_refund_withdraws_the_cards(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 50.0]);

        $this->assertSame('refunded', wc_get_order($order->get_id())->get_status());
        $this->assertFalse($this->cardsOf($order)[0]->isActive());
    }

    /**
     * Cancelled by mistake and reopened: the recipient already has the
     * email. The code in it has to work again; a new card would leave them
     * holding a dead one.
     */
    public function test_reopening_the_order_reinstates_the_same_cards(): void
    {
        $order = $this->giftCardOrder([], 2);
        $order->payment_complete();
        $before = array_column($this->cardsOf($order), 'id');

        $order = wc_get_order($order->get_id());
        $order->update_status('cancelled');
        $order = wc_get_order($order->get_id());
        $order->update_status('completed');

        $cards = $this->cardsOf($order);

        $this->assertSame($before, array_column($cards, 'id'));
        $this->assertSame([Card::STATUS_ACTIVE, Card::STATUS_ACTIVE], array_column($cards, 'status'));
        $this->assertTrue($this->cards->debit($cards[0]->id, 10));
        $this->assertSame('', wc_get_order($order->get_id())->get_meta(Issuance::ORDER_DISABLED));
        $this->assertCount(2, $this->emailsTo('friend@example.org'));
    }

    /**
     * The shop is out of pocket if a card was spent and then its order
     * refunded. It cannot be undone automatically, so it must be visible.
     */
    public function test_withdrawing_a_card_that_was_already_spent_from_leaves_a_warning_on_the_order(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];
        $this->cards->debit($card->id, 20);

        wc_get_order($order->get_id())->update_status('cancelled');

        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertStringContainsString('already been partly spent', $notes);
        $this->assertFalse($this->cards->find($card->id)->isActive());
    }

    /**
     * An order can buy a gift card and be paid with a balance at once.
     * Cancelling it has to undo both, and neither may overwrite the other's
     * record on the order.
     */
    public function test_cancelling_an_order_that_bought_a_card_with_a_balance_undoes_both(): void
    {
        $customerId = $this->customer();
        $credit = $this->storeCredit($customerId, 100);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $order = $this->giftCardOrder();

        $this->assertSame(139.0, (float) $order->get_total());

        $order->payment_complete();
        $bought = $this->cardsOf($order)[0];

        wc_get_order($order->get_id())->update_status('cancelled');
        $order = wc_get_order($order->get_id());

        $this->assertSame(100.0, $this->balance($credit));
        $this->assertFalse($this->cards->find($bought->id)->isActive());
        $this->assertSame([$bought->id], $order->get_meta(Issuance::ORDER_DISABLED));
        $this->assertSame('released', $order->get_meta('_store_balance_state'));

        $order->update_status('processing');

        $this->assertSame(0.0, $this->balance($credit));
        $this->assertTrue($this->cards->find($bought->id)->isActive());
    }

    /**
     * A card an admin disabled on purpose — fraud, a complaint — is not the
     * order's to bring back.
     */
    public function test_reopening_an_order_does_not_enable_a_card_that_was_disabled_by_hand(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $card = $this->cardsOf($order)[0];
        $this->cards->setStatus($card->id, Card::STATUS_DISABLED, 'Reported stolen');

        $order = wc_get_order($order->get_id());
        $order->update_status('cancelled');
        $order = wc_get_order($order->get_id());
        $order->update_status('completed');

        $this->assertFalse($this->cards->find($card->id)->isActive());
    }

    public function test_the_order_shows_who_the_card_is_for_but_not_its_code(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        $order = wc_get_order($order->get_id());
        $card = $this->cardsOf($order)[0];
        $item = current($order->get_items());

        ob_start();
        do_action('woocommerce_order_item_meta_end', $item->get_id(), $item, $order, false);
        $html = ob_get_clean();

        $this->assertStringContainsString('friend@example.org', $html);
        $this->assertStringContainsString('Aino', $html);
        $this->assertStringNotContainsString($card->formattedCode(), $html);
        $this->assertStringNotContainsString(substr($card->code, -4), $html);
    }

    /**
     * Part of the order was refunded, it was cancelled, and someone reopens
     * it. Money went back to the buyer; the cards must not come back by
     * themselves on top of it.
     */
    public function test_reopening_an_order_that_has_refunds_does_not_reinstate_its_cards(): void
    {
        $order = $this->giftCardOrder([], 2);
        $order->payment_complete();
        $before = array_column($this->cardsOf($order), 'id');

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 50.0]);
        wc_get_order($order->get_id())->update_status('cancelled');
        wc_get_order($order->get_id())->update_status('completed');

        $cards = $this->cardsOf($order);
        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertSame($before, array_column($cards, 'id'));
        $this->assertSame([Card::STATUS_DISABLED, Card::STATUS_DISABLED], array_column($cards, 'status'));
        $this->assertStringContainsString('stay deactivated', $notes);
    }

    public function test_a_fully_refunded_order_that_is_reopened_keeps_its_cards_withdrawn(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();

        wc_create_refund(['order_id' => $order->get_id(), 'amount' => 50.0]);
        wc_get_order($order->get_id())->update_status('completed');

        $cards = $this->cardsOf($order);

        $this->assertCount(1, $cards);
        $this->assertFalse($cards[0]->isActive());
    }

    /**
     * The webhook and the customer's return both announce the payment, each
     * with a copy of the order loaded before the other one issued anything.
     */
    public function test_two_stale_copies_of_an_order_being_paid_issue_its_cards_once(): void
    {
        $order = $this->giftCardOrder([], 2);

        $one = wc_get_order($order->get_id());
        $two = wc_get_order($order->get_id());

        $one->payment_complete();
        $two->payment_complete();
        Plugin::getInstance()->module(Issuance::class)->issue($two);

        $this->assertCount(2, $this->cardsOf($order));
        $this->assertCount(2, $this->emailsTo('friend@example.org'));
    }

    /**
     * The shop owner reading the order has to be able to see why a card
     * stopped working.
     */
    public function test_withdrawing_a_card_is_written_on_the_order(): void
    {
        $order = $this->giftCardOrder();
        $order->payment_complete();
        $card = $this->cardsOf($order)[0];

        wc_get_order($order->get_id())->update_status('cancelled');

        $notes = implode("\n", array_map(static fn ($note) => $note->content, wc_get_order_notes(['order_id' => $order->get_id()])));

        $this->assertStringContainsString($card->maskedCode().' deactivated', $notes);
        $this->assertStringNotContainsString($card->formattedCode(), $notes);
        $this->assertDoesNotMatchRegularExpression('/&[a-z#0-9]+;/i', $notes);
    }

    /**
     * The buyer wants to know whether the present has been sent.
     */
    public function test_the_order_line_says_when_the_card_was_or_will_be_emailed(): void
    {
        $issuance = Plugin::getInstance()->module(Issuance::class);
        $date = (new \DateTimeImmutable('+10 days', wp_timezone()))->format('Y-m-d');

        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '25', 'store_balance_to' => 'now@example.org']);
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '50', 'store_balance_to' => 'later@example.org', 'store_balance_delivery' => $date]);
        $order = $this->placeOrder();

        foreach ($order->get_items() as $item) {
            $this->assertSame('', $issuance->deliveryStatus($item));
        }

        $order->payment_complete();
        $statuses = array_values(array_map([$issuance, 'deliveryStatus'], wc_get_order($order->get_id())->get_items()));

        $this->assertStringStartsWith('Emailed on', $statuses[0]);
        $this->assertStringStartsWith('Will be emailed on', $statuses[1]);
        // The promise is a day, not an hour the queue may not keep.
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}[:.]\d{2}/', $statuses[1]);

        wc_get_order($order->get_id())->update_status('cancelled');
        $statuses = array_values(array_map([$issuance, 'deliveryStatus'], wc_get_order($order->get_id())->get_items()));

        $this->assertSame(['Cancelled', 'Cancelled'], $statuses);
    }
}
