<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Modules\Blocks;
use GeneroWP\StoreBalance\Modules\Cart;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\Throttle;
use WC_Coupon;

class CartTest extends TestCase
{
    public function test_a_cart_without_a_balance_is_left_alone(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());

        $state = $this->state();

        $this->assertSame(189.0, $this->cartTotal());
        $this->assertSame(189.0, $state['original_total']);
        $this->assertSame(0.0, $state['applied_total']);
        $this->assertSame([], $state['lines']);
        $this->assertSame([], $state['codes']);
    }

    public function test_a_gift_card_code_lowers_the_total_by_its_balance(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->assertInstanceOf(Card::class, $this->cart()->applyCode($card->formattedCode()));

        $state = $this->state();

        $this->assertSame(139.0, $this->cartTotal());
        $this->assertSame(189.0, $state['original_total']);
        $this->assertSame(50.0, $state['applied_total']);
        $this->assertSame([[
            'card_id' => $card->id,
            'type' => Card::TYPE_GIFT_CARD,
            'masked' => $card->maskedCode(),
            'amount' => 50.0,
            'source' => 'code',
        ]], $state['lines']);
        $this->assertSame(50.0, $state['codes'][0]['available']);
        $this->assertNull($state['codes'][0]['reason']);
    }

    /**
     * Applying a code is a promise, not a payment: the card is only debited
     * when the order is placed.
     */
    public function test_applying_a_code_does_not_touch_the_card(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->cart()->applyCode($card->code);
        $this->state();

        $this->assertSame(50.0, $this->balance($card));
        $this->assertCount(1, $this->ledger($card));
    }

    /**
     * The reason the plugin exists rather than a coupon: a balance is a means
     * of payment. The VAT on the goods is due in full however they are paid.
     */
    public function test_paying_with_a_balance_does_not_change_the_vat(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());
        WC()->cart->calculate_totals();

        $taxBefore = WC()->cart->get_total_tax();
        $subtotalBefore = WC()->cart->get_subtotal();

        $this->assertSame(38.4, round((float) $taxBefore, 2));

        $this->cart()->applyCode($card->code);

        $this->assertSame(139.0, $this->cartTotal());
        $this->assertSame($taxBefore, WC()->cart->get_total_tax());
        $this->assertSame($subtotalBefore, WC()->cart->get_subtotal());
        $this->assertSame(0.0, (float) WC()->cart->get_discount_total());
    }

    public function test_a_card_larger_than_the_cart_pays_for_all_of_it_and_no_more(): void
    {
        $card = $this->giftCard(500);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->cart()->applyCode($card->code);
        $state = $this->state();

        $this->assertSame(0.0, $this->cartTotal());
        $this->assertSame(189.0, $state['applied_total']);
        $this->assertSame(189.0, $state['lines'][0]['amount']);
        $this->assertSame(500.0, $state['codes'][0]['available']);
    }

    public function test_the_total_never_goes_below_zero_with_several_cards(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 150);
        $this->storeCredit($customerId, 150);
        $code = $this->giftCard(100);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($code->code);

        $state = $this->state();

        $this->assertSame(0.0, $this->cartTotal());
        $this->assertSame(189.0, $state['applied_total']);
        $this->assertSame(189.0, array_sum(array_column($state['lines'], 'amount')));
    }

    public function test_the_account_balance_is_used_without_being_asked(): void
    {
        $customerId = $this->customer();
        $credit = $this->storeCredit($customerId, 30);
        $gift = $this->giftCard(20, ['customer_id' => $customerId]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $state = $this->state();

        $this->assertTrue($state['use_balance']);
        $this->assertSame(139.0, $this->cartTotal());
        $this->assertSame(50.0, $state['account']['available']);
        $this->assertSame(50.0, $state['account']['used']);
        $this->assertSame(30.0, $state['account']['store_credit']);
        $this->assertSame(20.0, $state['account']['gift_cards']);
        $this->assertEqualsCanonicalizing([$credit->id, $gift->id], array_column($state['lines'], 'card_id'));
        $this->assertSame(['account', 'account'], array_column($state['lines'], 'source'));
    }

    public function test_someone_elses_balance_is_never_used(): void
    {
        $this->storeCredit($this->customer(), 100);

        $this->actAs($this->customer());
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->assertSame(189.0, $this->cartTotal());
        $this->assertSame(0.0, $this->state()['account']['available']);
    }

    public function test_a_guest_has_no_account_balance(): void
    {
        $this->storeCredit($this->customer(), 100);

        WC()->cart->add_to_cart($this->product()->get_id());

        $this->assertSame(189.0, $this->cartTotal());
    }

    /**
     * The balance is the customer's money: they may keep it for later.
     */
    public function test_unticking_use_my_balance_keeps_the_balance_but_shows_what_is_there(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 50);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->setUseBalance(false);

        $state = $this->state();

        $this->assertFalse($state['use_balance']);
        $this->assertSame(189.0, $this->cartTotal());
        $this->assertSame(50.0, $state['account']['available']);
        $this->assertSame(0.0, $state['account']['used']);
        $this->assertSame([], $state['lines']);

        $this->cart()->setUseBalance(true);

        $this->assertSame(139.0, $this->cartTotal());
    }

    /**
     * "Use my balance" is about the account. A code the customer typed in is
     * a separate decision and stays applied.
     */
    public function test_unticking_use_my_balance_leaves_a_typed_code_applied(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 50);
        $code = $this->giftCard(25);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($code->code);
        $this->cart()->setUseBalance(false);

        $this->assertSame(164.0, $this->cartTotal());
        $this->assertSame(['code'], array_column($this->state()['lines'], 'source'));
    }

    /**
     * What would lapse first is spent first, so the customer never loses a
     * balance they did not have to.
     */
    public function test_the_account_balance_is_spent_soonest_expiry_first_then_oldest(): void
    {
        $customerId = $this->customer();

        $never = $this->storeCredit($customerId, 40, ['expires_at' => null]);
        $late = $this->giftCard(40, ['customer_id' => $customerId, 'expires_at' => time() + 300 * DAY_IN_SECONDS]);
        $soon = $this->storeCredit($customerId, 40, ['expires_at' => time() + 10 * DAY_IN_SECONDS]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product(100)->get_id());

        $lines = $this->state()['lines'];

        $this->assertSame([$soon->id, $late->id, $never->id], array_column($lines, 'card_id'));
        $this->assertSame([40.0, 40.0, 20.0], array_column($lines, 'amount'));
        $this->assertSame(0.0, $this->cartTotal());
    }

    public function test_cards_with_the_same_expiry_are_spent_oldest_first(): void
    {
        $customerId = $this->customer();
        $expiry = time() + 100 * DAY_IN_SECONDS;

        $older = $this->storeCredit($customerId, 40, ['expires_at' => $expiry]);
        $newer = $this->storeCredit($customerId, 40, ['expires_at' => $expiry]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product(50)->get_id());

        $lines = $this->state()['lines'];

        $this->assertSame([$older->id, $newer->id], array_column($lines, 'card_id'));
        $this->assertSame([40.0, 10.0], array_column($lines, 'amount'));
    }

    /**
     * A code is something the customer chose to use on this order, and it is
     * not theirs to keep: it goes before the balance that is safe on the
     * account.
     */
    public function test_a_typed_code_is_spent_before_the_account_balance(): void
    {
        $customerId = $this->customer();
        $credit = $this->storeCredit($customerId, 100, ['expires_at' => time() + DAY_IN_SECONDS]);
        $code = $this->giftCard(80, ['expires_at' => time() + 700 * DAY_IN_SECONDS]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product(100)->get_id());
        $this->cart()->applyCode($code->code);

        $lines = $this->state()['lines'];

        $this->assertSame([$code->id, $credit->id], array_column($lines, 'card_id'));
        $this->assertSame([80.0, 20.0], array_column($lines, 'amount'));
    }

    public function test_expired_disabled_and_empty_cards_on_the_account_are_ignored(): void
    {
        $customerId = $this->customer();

        $this->storeCredit($customerId, 40, ['expires_at' => time() - 10]);
        $disabled = $this->storeCredit($customerId, 40);
        $this->cards->setStatus($disabled->id, Card::STATUS_DISABLED);
        $empty = $this->storeCredit($customerId, 40);
        $this->cards->debit($empty->id, 40);
        $good = $this->storeCredit($customerId, 15);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $state = $this->state();

        $this->assertSame(15.0, $state['account']['available']);
        $this->assertSame([$good->id], array_column($state['lines'], 'card_id'));
        $this->assertSame(174.0, $this->cartTotal());
    }

    /**
     * No conversion: a card is only money in its own currency.
     */
    public function test_a_code_in_another_currency_is_refused(): void
    {
        $card = $this->giftCard(500, ['currency' => 'SEK']);
        WC()->cart->add_to_cart($this->product()->get_id());

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_currency', $result->get_error_code());
        $this->assertStringContainsString('SEK', $result->get_error_message());
        $this->assertSame([], $this->cart()->codes());
        $this->assertSame(189.0, $this->cartTotal());
    }

    /**
     * A shop with a currency switcher: the customer applied the card, then
     * changed currency. The card stays in the list with the reason it is not
     * being used, because switching back makes it good again.
     */
    public function test_a_code_stays_applied_with_a_reason_when_the_cart_changes_currency(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        $this->assertSame(139.0, $this->cartTotal());

        $toSek = static fn () => 'SEK';
        add_filter('woocommerce_currency', $toSek);

        $state = $this->state();

        $this->assertSame(189.0, $this->cartTotal());
        $this->assertSame(0.0, $state['applied_total']);
        $this->assertSame([], $state['lines']);
        $this->assertCount(1, $state['codes']);
        $this->assertSame(0.0, $state['codes'][0]['amount']);
        $this->assertSame('EUR', $state['codes'][0]['currency']);
        $this->assertStringContainsString('EUR', $state['codes'][0]['reason']);
        $this->assertStringContainsString('SEK', $state['codes'][0]['reason']);
        $this->assertSame([$card->code], $this->cart()->codes());

        remove_filter('woocommerce_currency', $toSek);

        $this->assertSame(139.0, $this->cartTotal());
        $this->assertNull($this->state()['codes'][0]['reason']);
    }

    public function test_an_account_balance_in_another_currency_is_shown_but_not_used(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 300, ['currency' => 'SEK']);
        $this->storeCredit($customerId, 200, ['currency' => 'SEK']);
        $this->storeCredit($customerId, 10);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $state = $this->state();

        $this->assertSame(179.0, $this->cartTotal());
        $this->assertSame(10.0, $state['account']['available']);
        $this->assertSame(['SEK' => 500.0], $state['account']['other_currencies']);
    }

    /**
     * Buying a gift card with a gift card turns one code into another, and
     * store credit into something that can be handed to someone else.
     */
    public function test_a_balance_cannot_buy_a_gift_card(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 500);

        $this->actAs($customerId);
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']);

        $state = $this->state();

        $this->assertTrue($state['only_gift_cards']);
        $this->assertSame(0.0, (float) $state['eligible_total']);
        $this->assertSame(0.0, $state['applied_total']);
        $this->assertSame(100.0, $this->cartTotal());
    }

    public function test_a_code_cannot_be_applied_to_a_cart_of_only_gift_cards(): void
    {
        $card = $this->giftCard(50);
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']);

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_gift_card_cart', $result->get_error_code());
        $this->assertSame([], $this->cart()->codes());
    }

    public function test_in_a_mixed_cart_the_balance_pays_for_the_goods_only(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 500);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->addGiftCardToCart($this->giftCardProduct(), ['store_balance_amount' => '100']);

        $state = $this->state();

        $this->assertFalse($state['only_gift_cards']);
        $this->assertSame(289.0, $state['original_total']);
        $this->assertSame(189.0, $state['eligible_total']);
        $this->assertSame(189.0, $state['applied_total']);
        $this->assertSame(100.0, $this->cartTotal());
    }

    /**
     * A coupon comes off the price, a balance off what is left to pay. The
     * balance must be worked out after the coupon, or it would pay for a
     * discount.
     */
    public function test_a_coupon_is_taken_off_before_the_balance(): void
    {
        $coupon = new WC_Coupon;
        $coupon->set_code('dev3ten');
        $coupon->set_discount_type('percent');
        $coupon->set_amount(10);
        $coupon->save();

        $card = $this->giftCard(500);
        WC()->cart->add_to_cart($this->product(100)->get_id());
        WC()->cart->apply_coupon('dev3ten');
        $this->cart()->applyCode($card->code);

        $state = $this->state();

        $this->assertSame(90.0, $state['original_total']);
        $this->assertSame(90.0, $state['applied_total']);
        $this->assertSame(0.0, $this->cartTotal());
    }

    public function test_a_code_is_accepted_however_it_is_typed(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->assertNotWPError($this->cart()->applyCode('  '.strtolower($card->formattedCode()).' '));
        $this->assertSame([$card->code], $this->cart()->codes());
    }

    public function test_an_empty_code_is_refused(): void
    {
        $result = $this->cart()->applyCode('   ');

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_empty_code', $result->get_error_code());
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $result = $this->cart()->applyCode('ABCD-EFGH-JKLM-NPQR');

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_invalid_code', $result->get_error_code());
    }

    /**
     * A withdrawn card says nothing about itself: the answer is the same as
     * for a code that never existed.
     */
    public function test_a_disabled_card_is_refused_like_an_unknown_code(): void
    {
        $card = $this->giftCard(50);
        $this->cards->setStatus($card->id, Card::STATUS_DISABLED);

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_invalid_code', $result->get_error_code());
    }

    /**
     * Store credit has no code as far as anyone outside the database is
     * concerned. The row has one; it must open nothing.
     */
    public function test_store_credit_cannot_be_spent_by_code(): void
    {
        $credit = $this->storeCredit($this->customer(), 50);
        WC()->cart->add_to_cart($this->product()->get_id());

        $result = $this->cart()->applyCode($credit->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_invalid_code', $result->get_error_code());
        $this->assertSame(189.0, $this->cartTotal());
    }

    public function test_an_expired_card_is_refused_with_its_expiry_date(): void
    {
        $card = $this->giftCard(50, ['expires_at' => strtotime('2024-03-01 12:00:00 UTC')]);

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_expired', $result->get_error_code());
        $this->assertStringContainsString('2024', $result->get_error_message());
    }

    public function test_an_empty_card_is_refused(): void
    {
        $card = $this->giftCard(50);
        $this->cards->debit($card->id, 50);

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_empty', $result->get_error_code());
    }

    /**
     * Once a gift card is on an account its code is spent as a bearer
     * instrument: whoever still has the email cannot use it.
     */
    public function test_a_card_added_to_someone_elses_account_cannot_be_used_by_code(): void
    {
        $card = $this->giftCard(50, ['customer_id' => $this->customer()]);

        $this->actAs($this->customer());
        WC()->cart->add_to_cart($this->product()->get_id());

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_redeemed', $result->get_error_code());
        $this->assertSame(189.0, $this->cartTotal());
    }

    public function test_a_card_already_in_the_customers_own_account_is_not_applied_twice(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50, ['customer_id' => $customerId]);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_in_account', $result->get_error_code());
        $this->assertSame(139.0, $this->cartTotal());
    }

    public function test_the_same_code_is_not_applied_twice(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->cart()->applyCode($card->code);
        $result = $this->cart()->applyCode($card->formattedCode());

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_applied', $result->get_error_code());
        $this->assertSame(139.0, $this->cartTotal());
    }

    public function test_several_codes_are_used_in_the_order_they_were_typed(): void
    {
        $first = $this->giftCard(100);
        $second = $this->giftCard(100);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->cart()->applyCode($first->code);
        $this->cart()->applyCode($second->code);

        $lines = $this->state()['lines'];

        $this->assertSame([$first->id, $second->id], array_column($lines, 'card_id'));
        $this->assertSame([100.0, 89.0], array_column($lines, 'amount'));
    }

    public function test_at_most_five_codes_can_be_applied(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());

        for ($i = 0; $i < Cart::MAX_CODES; $i++) {
            $this->assertNotWPError($this->cart()->applyCode($this->giftCard(5)->code));
        }

        $result = $this->cart()->applyCode($this->giftCard(5)->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_too_many', $result->get_error_code());
        $this->assertSame(164.0, $this->cartTotal());
    }

    public function test_a_code_can_be_removed_by_the_card_it_belongs_to(): void
    {
        $keep = $this->giftCard(20);
        $drop = $this->giftCard(30);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($keep->code);
        $this->cart()->applyCode($drop->code);
        $this->state();

        $this->cart()->removeCard($drop->id);

        $this->assertSame([$keep->code], $this->cart()->codes());
        $this->assertSame(169.0, $this->cartTotal());
    }

    /**
     * The card was good when it was applied and is not any more — the order
     * that bought it was cancelled, or it was spent in another browser. The
     * line must disappear rather than promise money that is not there.
     */
    public function test_a_code_that_stops_being_good_drops_out_of_the_cart(): void
    {
        $disabled = $this->giftCard(20);
        $spent = $this->giftCard(30);
        $good = $this->giftCard(5);
        WC()->cart->add_to_cart($this->product()->get_id());

        foreach ([$disabled, $spent, $good] as $card) {
            $this->cart()->applyCode($card->code);
        }

        $this->assertSame(134.0, $this->cartTotal());

        $this->cards->setStatus($disabled->id, Card::STATUS_DISABLED);
        $this->cards->debit($spent->id, 30);

        $this->assertSame(184.0, $this->cartTotal());
        $this->assertSame([$good->code], $this->cart()->codes());
    }

    public function test_a_partly_spent_card_pays_what_it_has_left(): void
    {
        $card = $this->giftCard(50);
        $this->cards->debit($card->id, 35);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->cart()->applyCode($card->code);

        $this->assertSame(174.0, $this->cartTotal());
    }

    /**
     * Codes live in the session. On a shared computer the next customer must
     * not inherit them.
     */
    public function test_emptying_the_cart_or_logging_out_forgets_the_codes(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        WC()->cart->empty_cart();

        $this->assertSame([], $this->cart()->codes());

        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        do_action('wp_logout', 0);

        $this->assertSame([], $this->cart()->codes());
    }

    /**
     * Not for the entropy — 80 bits are not guessed — but so that someone
     * hammering the form shows up in the log and is slowed down.
     */
    public function test_guessing_codes_is_stopped_after_ten_wrong_attempts(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());

        for ($i = 0; $i < Throttle::VISITOR_LIMIT; $i++) {
            $this->assertSame('wc_store_balance_invalid_code', $this->cart()->applyCode('ABCD-EFGH-JKLM-NPQR')->get_error_code());
        }

        $result = $this->cart()->applyCode($card->code);

        $this->assertWPError($result);
        $this->assertSame('wc_store_balance_throttled', $result->get_error_code());
        $this->assertSame(189.0, $this->cartTotal());
    }

    /**
     * "Expired", "empty" and "belongs to an account" each confirm that a
     * guessed code exists. They cost an attempt like a wrong code does.
     */
    public function test_every_kind_of_refusal_counts_as_an_attempt(): void
    {
        $expired = $this->giftCard(50, ['expires_at' => time() - 10]);
        WC()->cart->add_to_cart($this->product()->get_id());

        for ($i = 0; $i < Throttle::VISITOR_LIMIT; $i++) {
            $this->assertSame('wc_store_balance_expired', $this->cart()->applyCode($expired->code)->get_error_code());
        }

        $this->assertSame('wc_store_balance_throttled', $this->cart()->applyCode($this->giftCard(50)->code)->get_error_code());
    }

    /**
     * The limit per visitor is no limit for a script that drops its cookie
     * after every nine tries. The address it comes from is counted as well.
     */
    public function test_a_new_session_does_not_reset_the_count_for_an_address(): void
    {
        WC()->cart->add_to_cart($this->product()->get_id());

        for ($i = 0; $i < Throttle::IP_LIMIT; $i++) {
            if ($i % (Throttle::VISITOR_LIMIT - 1) === 0) {
                $this->actAs(0);
            }

            $this->assertSame('wc_store_balance_invalid_code', $this->cart()->applyCode('ABCD-EFGH-JKLM-NPQR')->get_error_code(), "Attempt {$i}");
        }

        $this->actAs(0);

        $this->assertSame('wc_store_balance_throttled', $this->cart()->applyCode($this->giftCard(50)->code)->get_error_code());
    }

    /**
     * A guest typed the code in; before they paid, the card was added to an
     * account. From that moment it is that account's money, and the cart
     * that still lists it must let go of it.
     */
    public function test_a_code_added_to_an_account_in_the_meantime_drops_out_of_the_cart(): void
    {
        $card = $this->giftCard(50);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        $this->assertSame(139.0, $this->cartTotal());

        $this->cards->redeem($card->id, $this->customer());

        $this->assertSame(189.0, $this->cartTotal());
        $this->assertSame([], $this->cart()->codes());
    }

    public function test_the_state_can_be_filtered(): void
    {
        add_filter('wc_store_balance_cart_state', static function (array $state): array {
            $state['seen_by_filter'] = true;

            return $state;
        });

        WC()->cart->add_to_cart($this->product()->get_id());

        $this->assertTrue($this->state()['seen_by_filter']);
    }

    /**
     * What the cart block draws. Amounts in the Store API are minor units as
     * strings; anything else and the block prints 5000 € for a 50 € card.
     */
    public function test_the_store_api_cart_carries_the_balance_in_minor_units(): void
    {
        $customerId = $this->customer();
        $this->storeCredit($customerId, 30);
        $code = $this->giftCard(50);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($code->code);
        WC()->cart->calculate_totals();

        $data = Plugin::getInstance()->module(Blocks::class)->data();

        $this->assertSame('8000', $data['applied_total']);
        $this->assertSame('18900', $data['original_total']);
        $this->assertSame('3000', $data['account']['available']);
        $this->assertSame('3000', $data['account']['used']);
        $this->assertSame('5000', $data['codes'][0]['amount']);
        $this->assertSame($code->id, $data['codes'][0]['id']);
        $this->assertSame($code->maskedCode(), $data['codes'][0]['masked']);
        // The full code never leaves the server once it has been applied.
        $this->assertStringNotContainsString($code->code, wp_json_encode($data));
        $this->assertTrue($data['logged_in']);
        $this->assertSame('Gift card & store credit', $data['label']);
    }
}
