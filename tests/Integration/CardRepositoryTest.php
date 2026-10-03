<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Code;
use GeneroWP\StoreBalance\Install;
use RuntimeException;

class CardRepositoryTest extends TestCase
{
    public function test_a_new_card_holds_its_full_amount(): void
    {
        $card = $this->giftCard(50, ['recipient_email' => 'friend@example.org', 'sender_name' => 'Aino', 'message' => 'Onnea!']);

        $this->assertGreaterThan(0, $card->id);
        $this->assertSame(Card::TYPE_GIFT_CARD, $card->type);
        $this->assertSame('EUR', $card->currency);
        $this->assertSame(50.0, $card->initialAmount);
        $this->assertSame(50.0, $card->balance);
        $this->assertSame('friend@example.org', $card->recipientEmail);
        $this->assertSame('Aino', $card->senderName);
        $this->assertSame('Onnea!', $card->message);
        $this->assertTrue($card->isActive());
        $this->assertFalse($card->isRedeemed());
        $this->assertTrue(Code::isValid($card->code));
    }

    /**
     * The ledger has to explain the balance from the first cent, or the
     * history of a card starts with money from nowhere.
     */
    public function test_creating_a_card_writes_the_first_ledger_row(): void
    {
        $card = $this->giftCard(50, ['order_id' => 123, 'note' => 'Bought']);
        $ledger = $this->ledger($card);

        $this->assertCount(1, $ledger);
        $this->assertSame(CardRepository::TX_ISSUE, $ledger[0]->type);
        $this->assertSame(50.0, (float) $ledger[0]->amount);
        $this->assertSame(50.0, (float) $ledger[0]->balance_after);
        $this->assertSame(123, (int) $ledger[0]->order_id);
        $this->assertSame('Bought', $ledger[0]->note);
    }

    public function test_a_card_created_for_a_customer_is_already_in_their_account(): void
    {
        $customerId = $this->customer();
        $card = $this->storeCredit($customerId, 20);

        $this->assertTrue($card->isRedeemed());
        $this->assertSame($customerId, $card->customerId);
        $this->assertNotNull($card->redeemedAt);
    }

    public function test_the_currency_is_stored_in_uppercase(): void
    {
        $this->assertSame('SEK', $this->giftCard(100, ['currency' => 'sek'])->currency);
    }

    public function test_a_card_cannot_be_created_without_a_positive_amount(): void
    {
        $this->expectException(RuntimeException::class);

        $this->giftCard(0);
    }

    public function test_a_card_cannot_be_created_with_a_negative_amount(): void
    {
        $this->expectException(RuntimeException::class);

        $this->giftCard(-10);
    }

    /**
     * The currency is what locks a card to a cart. A card without one could
     * be spent anywhere, or nowhere.
     */
    public function test_a_card_cannot_be_created_without_a_currency(): void
    {
        $this->expectException(RuntimeException::class);

        $this->giftCard(10, ['currency' => '']);
    }

    public function test_a_card_cannot_be_created_with_an_unknown_type(): void
    {
        $this->expectException(RuntimeException::class);

        $this->giftCard(10, ['type' => 'voucher']);
    }

    public function test_every_card_gets_its_own_code(): void
    {
        $codes = [];

        for ($i = 0; $i < 40; $i++) {
            $codes[] = $this->giftCard(10)->code;
        }

        $this->assertCount(40, array_unique($codes));
    }

    /**
     * 80 random bits do not collide in practice. If they ever do, the second
     * card must not be created rather than share a code with the first.
     */
    public function test_the_database_refuses_a_second_card_with_the_same_code(): void
    {
        global $wpdb;

        $card = $this->giftCard(10);

        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(Install::cardsTable(), [
            'code' => $card->code,
            'type' => Card::TYPE_GIFT_CARD,
            'currency' => 'EUR',
            'initial_amount' => '10.0000',
            'balance' => '10.0000',
            'created_at' => CardRepository::now(),
            'updated_at' => CardRepository::now(),
        ]);
        $wpdb->suppress_errors($suppress);

        $this->assertFalse($inserted);
    }

    /**
     * People paste the code from the email: with dashes, in lowercase, with a
     * space on the end.
     */
    public function test_a_card_is_found_by_its_code_however_it_is_typed(): void
    {
        $card = $this->giftCard(10);

        $this->assertSame($card->id, $this->cards->findByCode($card->code)->id);
        $this->assertSame($card->id, $this->cards->findByCode($card->formattedCode())->id);
        $this->assertSame($card->id, $this->cards->findByCode(' '.strtolower($card->formattedCode())."\n")->id);
        $this->assertNull($this->cards->findByCode('NOPE'));
        $this->assertNull($this->cards->findByCode(''));
    }

    public function test_a_debit_takes_the_amount_and_keeps_the_rest(): void
    {
        $card = $this->giftCard(50);

        $this->assertTrue($this->cards->debit($card->id, 19.9, ['order_id' => 7]));
        $this->assertSame(30.1, $this->balance($card));

        $row = $this->ledger($card)[1];

        $this->assertSame(CardRepository::TX_DEBIT, $row->type);
        $this->assertSame(-19.9, (float) $row->amount);
        $this->assertSame(30.1, (float) $row->balance_after);
        $this->assertSame(7, (int) $row->order_id);
    }

    public function test_a_card_can_be_emptied_exactly(): void
    {
        $card = $this->giftCard(50);

        $this->assertTrue($this->cards->debit($card->id, 50));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * The whole point of doing the debit in one UPDATE: a card can never go
     * below zero, whoever asks and however many ask at once.
     */
    public function test_a_debit_larger_than_the_balance_is_refused_and_leaves_no_trace(): void
    {
        $card = $this->giftCard(50);

        $this->assertFalse($this->cards->debit($card->id, 50.01));
        $this->assertSame(50.0, $this->balance($card));
        $this->assertSame([CardRepository::TX_ISSUE], $this->ledgerTypes($card));
    }

    public function test_the_second_of_two_debits_for_the_whole_balance_is_refused(): void
    {
        $card = $this->giftCard(50);

        $this->assertTrue($this->cards->debit($card->id, 50));
        $this->assertFalse($this->cards->debit($card->id, 50));
        $this->assertSame(0.0, $this->balance($card));
    }

    /**
     * A card is disabled when the order that bought it is cancelled. The
     * check has to be in the debit itself: the cart may have been calculated
     * while the card was still good.
     */
    public function test_a_disabled_card_cannot_be_debited(): void
    {
        $card = $this->giftCard(50);
        $this->cards->setStatus($card->id, Card::STATUS_DISABLED);

        $this->assertFalse($this->cards->debit($card->id, 10));
        $this->assertSame(50.0, $this->balance($card));
    }

    public function test_an_expired_card_cannot_be_debited(): void
    {
        $card = $this->giftCard(50, ['expires_at' => time() - HOUR_IN_SECONDS]);

        $this->assertFalse($this->cards->debit($card->id, 10));
        $this->assertSame(50.0, $this->balance($card));
    }

    public function test_a_card_that_has_not_expired_yet_can_be_debited(): void
    {
        $card = $this->giftCard(50, ['expires_at' => time() + HOUR_IN_SECONDS]);

        $this->assertTrue($this->cards->debit($card->id, 10));
    }

    /**
     * A negative debit would be a credit that bypasses every rule about who
     * may add money to a card.
     */
    public function test_a_debit_of_nothing_or_less_is_refused(): void
    {
        $card = $this->giftCard(50);

        $this->assertFalse($this->cards->debit($card->id, 0));
        $this->assertFalse($this->cards->debit($card->id, -5));
        $this->assertSame(50.0, $this->balance($card));
    }

    /**
     * 0.1 + 0.2 is not 0.3 in floating point. Three debits of ten cents must
     * still empty a card of thirty.
     */
    public function test_small_debits_add_up_exactly(): void
    {
        $card = $this->giftCard(0.3);

        $this->assertTrue($this->cards->debit($card->id, 0.1));
        $this->assertTrue($this->cards->debit($card->id, 0.1));
        $this->assertTrue($this->cards->debit($card->id, 0.1));
        $this->assertSame(0.0, $this->balance($card));
        $this->assertFalse($this->cards->debit($card->id, 0.01));
    }

    public function test_a_credit_puts_money_back_and_says_why(): void
    {
        $card = $this->giftCard(50);
        $this->cards->debit($card->id, 30);

        $this->assertTrue($this->cards->credit($card->id, 30, CardRepository::TX_RELEASE, ['order_id' => 9, 'note' => 'Order cancelled']));
        $this->assertSame(50.0, $this->balance($card));

        $row = $this->ledger($card)[2];

        $this->assertSame(CardRepository::TX_RELEASE, $row->type);
        $this->assertSame(30.0, (float) $row->amount);
        $this->assertSame(50.0, (float) $row->balance_after);
        $this->assertSame(9, (int) $row->order_id);
        $this->assertSame('Order cancelled', $row->note);
    }

    /**
     * Money taken from a card and returned belongs on that card, whatever
     * has happened to the card since.
     */
    public function test_a_credit_reaches_a_disabled_or_expired_card(): void
    {
        $disabled = $this->giftCard(50);
        $this->cards->debit($disabled->id, 20);
        $this->cards->setStatus($disabled->id, Card::STATUS_DISABLED);

        $expired = $this->giftCard(50, ['expires_at' => time() - HOUR_IN_SECONDS]);

        $this->assertTrue($this->cards->credit($disabled->id, 20));
        $this->assertTrue($this->cards->credit($expired->id, 20));
        $this->assertSame(50.0, $this->balance($disabled));
        $this->assertSame(70.0, $this->balance($expired));
    }

    public function test_a_credit_to_a_card_that_does_not_exist_is_refused(): void
    {
        $this->assertFalse($this->cards->credit(999999, 10));
        $this->assertFalse($this->cards->credit($this->giftCard(10)->id, 0));
    }

    /**
     * After any sequence of changes the last ledger row has to state the
     * balance the card actually has. Otherwise the history shown to the
     * customer and the admin does not add up.
     */
    public function test_the_ledger_follows_the_balance_through_every_change(): void
    {
        $card = $this->giftCard(100);

        $this->cards->debit($card->id, 30);
        $this->cards->debit($card->id, 500);
        $this->cards->credit($card->id, 10);
        $this->cards->adjust($card->id, 95, 'Correction');
        $this->cards->setStatus($card->id, Card::STATUS_DISABLED);
        $this->cards->setStatus($card->id, Card::STATUS_ACTIVE);

        $ledger = $this->ledger($card);

        $this->assertSame(
            [CardRepository::TX_ISSUE, CardRepository::TX_DEBIT, CardRepository::TX_RELEASE, CardRepository::TX_ADJUST, CardRepository::TX_DISABLE, CardRepository::TX_ENABLE],
            array_column($ledger, 'type')
        );
        $this->assertSame([100.0, -30.0, 10.0, 15.0, 0.0, 0.0], array_map('floatval', array_column($ledger, 'amount')));
        $this->assertSame([100.0, 70.0, 80.0, 95.0, 95.0, 95.0], array_map('floatval', array_column($ledger, 'balance_after')));
        $this->assertSame(95.0, $this->balance($card));
        $this->assertSame(95.0, array_sum(array_map('floatval', array_column($ledger, 'amount'))));
    }

    public function test_an_adjustment_cannot_make_a_balance_negative(): void
    {
        $card = $this->giftCard(50);

        $this->assertFalse($this->cards->adjust($card->id, -1));
        $this->assertSame(50.0, $this->balance($card));
    }

    public function test_a_gift_card_is_added_to_an_account_once(): void
    {
        $first = $this->customer();
        $second = $this->customer();
        $card = $this->giftCard(50);

        $this->assertTrue($this->cards->redeem($card->id, $first));
        $this->assertFalse($this->cards->redeem($card->id, $second));
        $this->assertFalse($this->cards->redeem($card->id, $first));

        $card = $this->cards->find($card->id);

        $this->assertSame($first, $card->customerId);
        $this->assertNotNull($card->redeemedAt);
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_REDEEM], $this->ledgerTypes($card));
        $this->assertSame(50.0, (float) $this->ledger($card)[1]->balance_after);
    }

    public function test_a_gift_card_cannot_be_added_to_nobody(): void
    {
        $card = $this->giftCard(50);

        $this->assertFalse($this->cards->redeem($card->id, 0));
        $this->assertFalse($this->cards->find($card->id)->isRedeemed());
    }

    /**
     * Store credit belongs to the account it was given to. It must not be
     * possible to move it by "redeeming" it somewhere else.
     */
    public function test_store_credit_cannot_be_moved_to_another_account(): void
    {
        $owner = $this->customer();
        $card = $this->storeCredit($owner, 50);

        $this->assertFalse($this->cards->redeem($card->id, $this->customer()));
        $this->assertSame($owner, $this->cards->find($card->id)->customerId);
    }

    public function test_only_known_statuses_are_accepted(): void
    {
        $card = $this->giftCard(50);

        $this->assertFalse($this->cards->setStatus($card->id, 'deleted'));
        $this->assertTrue($this->cards->find($card->id)->isActive());
    }

    /**
     * The order the cards come back in is the order they are spent in.
     */
    public function test_a_customers_cards_come_soonest_expiry_first_then_oldest(): void
    {
        $customerId = $this->customer();

        $never = $this->storeCredit($customerId, 10, ['expires_at' => null]);
        $late = $this->storeCredit($customerId, 10, ['expires_at' => time() + 300 * DAY_IN_SECONDS]);
        $soon = $this->storeCredit($customerId, 10, ['expires_at' => time() + 30 * DAY_IN_SECONDS]);
        $alsoNever = $this->storeCredit($customerId, 10, ['expires_at' => null]);
        $this->storeCredit($this->customer(), 10);

        $this->assertSame(
            [$soon->id, $late->id, $never->id, $alsoNever->id],
            array_map(static fn (Card $card) => $card->id, $this->cards->forCustomer($customerId))
        );
    }

    public function test_a_customers_cards_can_be_listed_by_type(): void
    {
        $customerId = $this->customer();
        $credit = $this->storeCredit($customerId, 10);
        $gift = $this->giftCard(10, ['customer_id' => $customerId]);

        $this->assertSame([$credit->id], array_column($this->cards->forCustomer($customerId, Card::TYPE_STORE_CREDIT), 'id'));
        $this->assertSame([$gift->id], array_column($this->cards->forCustomer($customerId, Card::TYPE_GIFT_CARD), 'id'));
        $this->assertSame([], $this->cards->forCustomer(0));
    }

    /**
     * The liability figure on the admin screen. An expired or withdrawn card
     * is no longer owed.
     */
    public function test_what_the_store_owes_leaves_out_expired_disabled_and_empty_cards(): void
    {
        $customerId = $this->customer();

        $this->giftCard(50);
        $this->giftCard(25);
        $this->giftCard(100, ['currency' => 'SEK']);
        $this->storeCredit($customerId, 30);
        $this->giftCard(40, ['expires_at' => time() - 10]);
        $disabled = $this->giftCard(60);
        $this->cards->setStatus($disabled->id, Card::STATUS_DISABLED);
        $empty = $this->giftCard(70);
        $this->cards->debit($empty->id, 70);

        $owed = [];

        foreach ($this->cards->outstanding() as $row) {
            $owed[$row->type.' '.$row->currency] = [(int) $row->cards, (float) $row->balance];
        }

        $this->assertSame([
            'giftcard EUR' => [2, 75.0],
            'giftcard SEK' => [1, 100.0],
            'store_credit EUR' => [1, 30.0],
        ], $owed);
    }

    public function test_the_admin_search_finds_a_card_by_code_recipient_or_customer(): void
    {
        $customerId = $this->customer('dev3-search@example.org');
        $card = $this->giftCard(50, ['recipient_email' => 'friend@example.org']);
        $credit = $this->storeCredit($customerId, 10);
        $this->giftCard(50, ['recipient_email' => 'other@example.org']);

        $this->assertSame([$card->id], array_column($this->cards->query(['search' => 'friend@']), 'id'));
        $this->assertSame([$card->id], array_column($this->cards->query(['search' => substr($card->formattedCode(), 0, 9)]), 'id'));
        $this->assertSame([$credit->id], array_column($this->cards->query(['search' => 'dev3-search']), 'id'));
        $this->assertSame(1, $this->cards->count(['search' => 'friend@']));
        $this->assertSame(3, $this->cards->count());
    }

    public function test_other_code_can_react_to_a_new_card(): void
    {
        $seen = null;
        add_action('wc_store_balance_card_created', static function (Card $card) use (&$seen): void {
            $seen = $card;
        });

        $card = $this->giftCard(50);

        $this->assertSame($card->id, $seen->id);
    }

    /**
     * An order paid with a card that has since expired is cancelled. Put back
     * on an expired card the money would be returned to nowhere: the customer
     * gets a month to spend it.
     */
    public function test_money_returned_to_an_expired_card_can_be_spent_for_another_month(): void
    {
        $card = $this->giftCard(50, ['expires_at' => time() + HOUR_IN_SECONDS]);
        $this->cards->debit($card->id, 30);

        global $wpdb;
        $wpdb->update(Install::cardsTable(), ['expires_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)], ['id' => $card->id]);

        $this->assertFalse($this->cards->find($card->id)->isUsable());
        $this->assertTrue($this->cards->credit($card->id, 30));

        $card = $this->cards->find($card->id);

        $this->assertEqualsWithDelta(time() + 30 * DAY_IN_SECONDS, $card->expiresAt, 5);
        $this->assertTrue($card->isUsable());
        $this->assertTrue($this->cards->debit($card->id, 50));
    }

    /**
     * Only a card that has already expired gets the extra month. If one that
     * is merely close to its date got it too, a declined payment the day
     * before expiry would be a way to buy another month.
     */
    public function test_money_returned_to_a_card_about_to_expire_does_not_extend_it(): void
    {
        $expiry = time() + 3 * DAY_IN_SECONDS;
        $card = $this->giftCard(50, ['expires_at' => $expiry]);
        $this->cards->debit($card->id, 30);

        $this->cards->credit($card->id, 30);

        $this->assertSame($expiry, $this->cards->find($card->id)->expiresAt);
    }

    /**
     * The grace period is a floor, not a new expiry date.
     */
    public function test_money_returned_to_a_card_with_time_left_does_not_shorten_it(): void
    {
        $expiry = time() + 400 * DAY_IN_SECONDS;
        $card = $this->giftCard(50, ['expires_at' => $expiry]);
        $never = $this->giftCard(50, ['expires_at' => null]);
        $this->cards->debit($card->id, 30);
        $this->cards->debit($never->id, 30);

        $this->cards->credit($card->id, 30);
        $this->cards->credit($never->id, 30);

        $this->assertSame($expiry, $this->cards->find($card->id)->expiresAt);
        $this->assertNull($this->cards->find($never->id)->expiresAt);
    }

    public function test_the_grace_period_can_be_filtered(): void
    {
        add_filter('wc_store_balance_returned_balance_grace_days', static fn () => 7);

        $card = $this->giftCard(50, ['expires_at' => time() - DAY_IN_SECONDS]);
        $this->cards->credit($card->id, 30);

        $this->assertEqualsWithDelta(time() + 7 * DAY_IN_SECONDS, $this->cards->find($card->id)->expiresAt, 5);
    }

    /**
     * The history a customer sees is a bank statement. Every row's balance
     * has to be the sum of everything above it — whichever kind of change
     * wrote the row.
     */
    public function test_every_ledger_row_states_the_running_sum(): void
    {
        $card = $this->giftCard(100);

        $this->cards->debit($card->id, 12.34);
        $this->cards->credit($card->id, 2.34, CardRepository::TX_REFUND);
        $this->cards->debit($card->id, 0.01);
        $this->cards->debit($card->id, 1000);
        $this->cards->adjust($card->id, 40);
        $this->cards->redeem($card->id, $this->customer());
        $this->cards->debit($card->id, 40);
        $this->cards->credit($card->id, 15.5);
        $this->cards->adjust($card->id, 0);

        $sum = 0.0;

        foreach ($this->ledger($card) as $i => $row) {
            $sum = round($sum + (float) $row->amount, 2);

            $this->assertSame($sum, (float) $row->balance_after, "Row {$i} ({$row->type})");
        }

        $this->assertSame(0.0, $this->balance($card));
        $this->assertCount(9, $this->ledger($card));
    }

    /**
     * "Set the balance to 40" on a card holding 100 took 60 away. The ledger
     * has to say 60, measured against what the card held at that instant.
     */
    public function test_an_adjustment_records_the_difference_it_made(): void
    {
        $card = $this->giftCard(100);
        $this->cards->debit($card->id, 25);

        $this->assertTrue($this->cards->adjust($card->id, 40, 'Correction'));
        $this->assertTrue($this->cards->adjust($card->id, 90));
        $this->assertTrue($this->cards->adjust($card->id, 0));

        $rows = array_slice($this->ledger($card), 2);

        $this->assertSame([-35.0, 50.0, -90.0], array_map('floatval', array_column($rows, 'amount')));
        $this->assertSame([40.0, 90.0, 0.0], array_map('floatval', array_column($rows, 'balance_after')));
        $this->assertSame('Correction', $rows[0]->note);
    }

    public function test_an_adjustment_of_a_card_that_does_not_exist_is_refused(): void
    {
        $this->assertFalse($this->cards->adjust(999999, 10));
    }

    /**
     * Characters that reorder or hide text make a name read one way in the
     * email and another on the admin screen.
     */
    public function test_invisible_characters_are_stripped_from_what_is_stored(): void
    {
        $card = $this->giftCard(10, ['sender_name' => "Ai\u{202E}no\u{200B}", 'message' => "Hei\u{2066}!\u{FEFF}"]);

        $this->assertSame('Aino', $card->senderName);
        $this->assertSame('Hei!', $card->message);
    }

    /**
     * Something other than a string under a key — a broken import, a filter —
     * must not take the request down or end up as "Array" on a card.
     */
    public function test_a_field_that_is_not_text_is_stored_empty(): void
    {
        $card = $this->giftCard(10, ['sender_name' => ['a'], 'message' => ['b'], 'recipient_email' => ['c']]);

        $this->assertSame('', $card->senderName);
        $this->assertSame('', $card->message);
        $this->assertSame('', $card->recipientEmail);
    }

    /**
     * A card that expires tomorrow is still good today. A declined payment
     * the day before expiry must not be a way to get another month.
     */
    public function test_money_returned_to_a_card_that_expires_tomorrow_leaves_its_date_alone(): void
    {
        $expiry = time() + DAY_IN_SECONDS;
        $card = $this->giftCard(50, ['expires_at' => $expiry]);

        $this->cards->debit($card->id, 50);
        $this->cards->credit($card->id, 50);
        $this->cards->credit($card->id, 1, CardRepository::TX_REFUND);

        $this->assertSame($expiry, $this->cards->find($card->id)->expiresAt);
    }
}
