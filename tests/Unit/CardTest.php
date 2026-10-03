<?php

namespace GeneroWP\StoreBalance\Tests\Unit;

use GeneroWP\StoreBalance\Card;
use PHPUnit\Framework\TestCase;

class CardTest extends TestCase
{
    public function test_a_card_with_a_balance_is_usable(): void
    {
        $this->assertTrue($this->card()->isUsable());
    }

    public function test_an_empty_card_is_not_usable(): void
    {
        $this->assertFalse($this->card(['balance' => '0.0000'])->isUsable());
    }

    public function test_a_disabled_card_is_not_usable(): void
    {
        $this->assertFalse($this->card(['status' => Card::STATUS_DISABLED])->isUsable());
    }

    public function test_a_card_expires_at_its_expiry_time_not_after_it(): void
    {
        $card = $this->card(['expires_at' => '2030-01-01 00:00:00']);
        $expiry = strtotime('2030-01-01 00:00:00 UTC');

        $this->assertFalse($card->isExpired($expiry - 1));
        $this->assertTrue($card->isExpired($expiry));
        $this->assertFalse($card->isUsable($expiry));
    }

    public function test_a_card_without_an_expiry_never_expires(): void
    {
        $this->assertFalse($this->card(['expires_at' => null])->isExpired(PHP_INT_MAX));
    }

    /**
     * The tables store UTC. Read as local time, a card would expire hours
     * early or late depending on the server.
     */
    public function test_datetimes_are_read_as_utc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Helsinki');

        try {
            $card = $this->card(['expires_at' => '2030-06-01 12:00:00']);

            $this->assertSame(gmmktime(12, 0, 0, 6, 1, 2030), $card->expiresAt);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_store_credit_and_gift_cards_are_told_apart(): void
    {
        $this->assertTrue($this->card()->isGiftCard());
        $this->assertTrue($this->card(['type' => Card::TYPE_STORE_CREDIT])->isStoreCredit());
        $this->assertFalse($this->card(['type' => Card::TYPE_STORE_CREDIT])->isGiftCard());
    }

    public function test_a_card_bound_to_a_customer_is_redeemed(): void
    {
        $this->assertFalse($this->card()->isRedeemed());
        $this->assertTrue($this->card(['customer_id' => '7'])->isRedeemed());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function card(array $row = []): Card
    {
        return new Card($row + [
            'id' => '1',
            'code' => 'ABCDEFGH23456789',
            'type' => Card::TYPE_GIFT_CARD,
            'currency' => 'EUR',
            'initial_amount' => '50.0000',
            'balance' => '50.0000',
            'customer_id' => '0',
            'status' => Card::STATUS_ACTIVE,
            'expires_at' => null,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }
}
