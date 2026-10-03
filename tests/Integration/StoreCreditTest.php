<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\StoreCredit;

class StoreCreditTest extends TestCase
{
    public function test_store_credit_goes_on_the_customers_account(): void
    {
        $customerId = $this->customer('dev3-credit@example.org');

        $card = wc_store_balance_issue_store_credit($customerId, 25.0, 'EUR', ['note' => 'Late delivery', 'order_id' => 55]);

        $this->assertInstanceOf(Card::class, $card);
        $this->assertTrue($card->isStoreCredit());
        $this->assertSame($customerId, $card->customerId);
        $this->assertSame(25.0, $card->balance);
        $this->assertSame('EUR', $card->currency);
        $this->assertSame('dev3-credit@example.org', $card->recipientEmail);
        $this->assertSame(55, $card->orderId);
        $this->assertSame('Late delivery', $this->ledger($card)[0]->note);
        $this->assertSame(25.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
    }

    public function test_store_credit_is_valid_for_a_year_by_default(): void
    {
        $card = StoreCredit::issue($this->customer(), 25.0);

        $this->assertEqualsWithDelta(time() + 365 * DAY_IN_SECONDS, $card->expiresAt, 5);
    }

    public function test_store_credit_can_be_given_its_own_expiry_or_none(): void
    {
        $customerId = $this->customer();
        $date = strtotime('+30 days');

        $this->assertNull(StoreCredit::issue($customerId, 25.0, 'EUR', ['expires_at' => null])->expiresAt);
        $this->assertSame($date, StoreCredit::issue($customerId, 25.0, 'EUR', ['expires_at' => $date])->expiresAt);
    }

    public function test_the_shop_currency_is_used_when_none_is_given(): void
    {
        $this->assertSame('EUR', StoreCredit::issue($this->customer(), 25.0)->currency);
    }

    /**
     * The customer did not ask for the credit, so they have to be told. The
     * email carries no code: there is nothing to type, and nothing to steal.
     */
    public function test_the_customer_is_told_by_email_without_a_code(): void
    {
        $customerId = $this->customer('dev3-mail@example.org');

        $card = StoreCredit::issue($customerId, 25.0);
        $mails = $this->emailsTo('dev3-mail@example.org');

        $this->assertCount(1, $mails);
        $this->assertStringContainsString('25', $mails[0]->subject);
        $this->assertStringNotContainsString($card->code, $mails[0]->body);
        $this->assertStringNotContainsString($card->formattedCode(), $mails[0]->body);
        $this->assertStringNotContainsString(substr($card->code, -4), $mails[0]->body);
        $this->assertNotNull($this->cards->find($card->id)->deliveredAt);
    }

    public function test_the_email_can_be_left_out(): void
    {
        $customerId = $this->customer('dev3-quiet@example.org');

        StoreCredit::issue($customerId, 25.0, 'EUR', ['send_email' => false]);

        $this->assertCount(0, $this->emailsTo('dev3-quiet@example.org'));
    }

    public function test_store_credit_needs_an_account_an_amount_and_a_real_currency(): void
    {
        $customerId = $this->customer();

        $this->assertSame('wc_store_balance_no_customer', StoreCredit::issue(999999, 25.0)->get_error_code());
        $this->assertSame('wc_store_balance_invalid_amount', StoreCredit::issue($customerId, 0.0)->get_error_code());
        $this->assertSame('wc_store_balance_invalid_amount', StoreCredit::issue($customerId, -5.0)->get_error_code());
        $this->assertSame('wc_store_balance_invalid_currency', StoreCredit::issue($customerId, 5.0, 'XXY')->get_error_code());
        $this->assertSame([], $this->cards->forCustomer($customerId));
    }

    /**
     * Credit is given per occasion — a late delivery, a goodwill gesture —
     * and each has its own expiry and its own line in the history. Two
     * credits must not be merged into one card.
     */
    public function test_each_credit_is_its_own_card(): void
    {
        $customerId = $this->customer();

        $first = StoreCredit::issue($customerId, 10.0);
        $second = StoreCredit::issue($customerId, 15.0);

        $this->assertNotSame($first->id, $second->id);
        $this->assertCount(2, $this->cards->forCustomer($customerId, Card::TYPE_STORE_CREDIT));
        $this->assertSame(25.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
    }

    public function test_the_customer_balance_counts_only_what_can_be_spent_in_that_currency(): void
    {
        $customerId = $this->customer();

        $this->storeCredit($customerId, 10);
        $this->giftCard(20, ['customer_id' => $customerId]);
        $this->storeCredit($customerId, 300, ['currency' => 'SEK']);
        $this->storeCredit($customerId, 40, ['expires_at' => time() - 10]);
        $disabled = $this->storeCredit($customerId, 80);
        $this->cards->setStatus($disabled->id, Card::STATUS_DISABLED);

        $this->assertSame(30.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
        $this->assertSame(30.0, wc_store_balance_get_customer_balance($customerId));
        $this->assertSame(10.0, wc_store_balance_get_customer_balance($customerId, 'EUR', Card::TYPE_STORE_CREDIT));
        $this->assertSame(20.0, wc_store_balance_get_customer_balance($customerId, 'EUR', Card::TYPE_GIFT_CARD));
        $this->assertSame(300.0, wc_store_balance_get_customer_balance($customerId, 'sek'));
        $this->assertSame(0.0, wc_store_balance_get_customer_balance(0));
    }
}
