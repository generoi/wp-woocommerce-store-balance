<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Modules\Account;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\Throttle;
use WP_Error;

class AccountTest extends TestCase
{
    protected function redeem(string $code, int $userId): Card|WP_Error
    {
        return Plugin::getInstance()->module(Account::class)->redeem($code, $userId);
    }

    public function test_a_gift_card_can_be_added_to_an_account(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50);

        $result = $this->redeem($card->formattedCode(), $customerId);

        $this->assertInstanceOf(Card::class, $result);
        $this->assertSame($customerId, $result->customerId);
        $this->assertSame(50.0, $result->balance);
        $this->assertSame(50.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
        $this->assertSame([CardRepository::TX_ISSUE, CardRepository::TX_REDEEM], $this->ledgerTypes($card));
    }

    public function test_the_code_is_accepted_as_it_was_pasted_from_the_email(): void
    {
        $card = $this->giftCard(50);

        $this->assertInstanceOf(Card::class, $this->redeem(' '.strtolower($card->formattedCode())."\n", $this->customer()));
    }

    /**
     * From then on it is used at checkout with nothing to type.
     */
    public function test_a_card_added_to_the_account_pays_at_checkout_without_its_code(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50);

        $this->redeem($card->code, $customerId);
        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());

        $this->assertSame(139.0, $this->cartTotal());
        $this->assertSame(50.0, $this->state()['account']['gift_cards']);
    }

    public function test_an_empty_code_is_refused(): void
    {
        $this->assertSame('wc_store_balance_empty_code', $this->redeem('  ', $this->customer())->get_error_code());
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->assertSame('wc_store_balance_invalid_code', $this->redeem('ABCD-EFGH-JKLM-NPQR', $this->customer())->get_error_code());
        $this->assertSame('wc_store_balance_invalid_code', $this->redeem('nonsense', $this->customer())->get_error_code());
    }

    public function test_a_disabled_card_is_refused_like_an_unknown_code(): void
    {
        $card = $this->giftCard(50);
        $this->cards->setStatus($card->id, Card::STATUS_DISABLED);

        $this->assertSame('wc_store_balance_invalid_code', $this->redeem($card->code, $this->customer())->get_error_code());
        $this->assertFalse($this->cards->find($card->id)->isRedeemed());
    }

    /**
     * Store credit has a code in its database row. If that row's code could
     * be redeemed, credit would move between accounts.
     */
    public function test_store_credit_cannot_be_added_to_another_account_by_its_code(): void
    {
        $owner = $this->customer();
        $credit = $this->storeCredit($owner, 50);

        $this->assertSame('wc_store_balance_invalid_code', $this->redeem($credit->code, $this->customer())->get_error_code());
        $this->assertSame($owner, $this->cards->find($credit->id)->customerId);
    }

    public function test_a_card_already_in_the_account_says_so(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50);
        $this->redeem($card->code, $customerId);

        $this->assertSame('wc_store_balance_in_account', $this->redeem($card->code, $customerId)->get_error_code());
        $this->assertSame(50.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
    }

    /**
     * The code was forwarded, or the email was read by two people. The first
     * to add it owns it.
     */
    public function test_a_card_in_someone_elses_account_cannot_be_taken(): void
    {
        $first = $this->customer();
        $second = $this->customer();
        $card = $this->giftCard(50);
        $this->redeem($card->code, $first);

        $this->assertSame('wc_store_balance_redeemed', $this->redeem($card->code, $second)->get_error_code());
        $this->assertSame($first, $this->cards->find($card->id)->customerId);
        $this->assertSame(0.0, wc_store_balance_get_customer_balance($second, 'EUR'));
    }

    public function test_an_expired_card_is_refused_with_its_expiry_date(): void
    {
        $card = $this->giftCard(50, ['expires_at' => strtotime('2024-03-01 12:00:00 UTC')]);

        $result = $this->redeem($card->code, $this->customer());

        $this->assertSame('wc_store_balance_expired', $result->get_error_code());
        $this->assertStringContainsString('2024', $result->get_error_message());
        $this->assertFalse($this->cards->find($card->id)->isRedeemed());
    }

    public function test_a_spent_card_is_refused(): void
    {
        $card = $this->giftCard(50);
        $this->cards->debit($card->id, 50);

        $this->assertSame('wc_store_balance_empty', $this->redeem($card->code, $this->customer())->get_error_code());
    }

    public function test_a_partly_spent_card_brings_what_it_has_left(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50);
        $this->cards->debit($card->id, 20);

        $this->assertSame(30.0, $this->redeem($card->code, $customerId)->balance);
    }

    /**
     * A card in another currency is still the customer's: it is kept on the
     * account for when they shop in that currency.
     */
    public function test_a_card_in_another_currency_can_be_added(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(500, ['currency' => 'SEK']);

        $this->assertInstanceOf(Card::class, $this->redeem($card->code, $customerId));
        $this->assertSame(0.0, wc_store_balance_get_customer_balance($customerId, 'EUR'));
        $this->assertSame(500.0, wc_store_balance_get_customer_balance($customerId, 'SEK'));
    }

    /**
     * The customer typed the code at checkout, then added the card to their
     * account. It is one card: it must not be counted as a code and as
     * balance.
     */
    public function test_a_code_sitting_in_the_cart_becomes_part_of_the_balance(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50);

        $this->actAs($customerId);
        WC()->cart->add_to_cart($this->product()->get_id());
        $this->cart()->applyCode($card->code);

        $this->redeem($card->code, $customerId);

        $state = $this->state();

        $this->assertSame([], $this->cart()->codes());
        $this->assertSame(50.0, $state['applied_total']);
        $this->assertSame(['account'], array_column($state['lines'], 'source'));
        $this->assertSame(139.0, $this->cartTotal());
    }

    /**
     * The form in My Account answers the same questions about a code as the
     * checkout does, so it is limited the same way.
     */
    public function test_guessing_codes_in_my_account_is_stopped_after_ten_wrong_attempts(): void
    {
        $customerId = $this->customer();
        $card = $this->giftCard(50);

        $this->actAs($customerId);

        for ($i = 0; $i < Throttle::VISITOR_LIMIT; $i++) {
            $this->assertSame('wc_store_balance_invalid_code', $this->redeem('ABCD-EFGH-JKLM-NPQR', $customerId)->get_error_code());
        }

        $this->assertSame('wc_store_balance_throttled', $this->redeem($card->code, $customerId)->get_error_code());
        $this->assertFalse($this->cards->find($card->id)->isRedeemed());
    }

    /**
     * Pressing the button twice on your own card is not guessing.
     */
    public function test_adding_your_own_card_again_does_not_count_as_an_attempt(): void
    {
        $customerId = $this->customer();
        $mine = $this->giftCard(50);

        $this->actAs($customerId);
        $this->redeem($mine->code, $customerId);

        for ($i = 0; $i < Throttle::VISITOR_LIMIT + 2; $i++) {
            $this->assertSame('wc_store_balance_in_account', $this->redeem($mine->code, $customerId)->get_error_code());
        }

        $this->assertInstanceOf(Card::class, $this->redeem($this->giftCard(20)->code, $customerId));
    }

    public function test_my_account_gets_a_page_for_gift_cards_and_one_for_store_credit(): void
    {
        $items = wc_get_account_menu_items();
        $keys = array_keys($items);

        $this->assertSame('Gift cards', $items[Account::ENDPOINT_GIFT_CARDS]);
        $this->assertSame('Store credit', $items[Account::ENDPOINT_STORE_CREDIT]);
        $this->assertSame(array_search('orders', $keys, true) + 1, array_search(Account::ENDPOINT_GIFT_CARDS, $keys, true));
        $this->assertArrayHasKey(Account::ENDPOINT_GIFT_CARDS, WC()->query->get_query_vars());
        $this->assertArrayHasKey(Account::ENDPOINT_STORE_CREDIT, WC()->query->get_query_vars());
    }

    /**
     * Each page shows its own kind and nothing of the other, and nothing of
     * anyone else's.
     */
    public function test_each_page_lists_only_its_own_kind_of_the_customers_own_cards(): void
    {
        $customerId = $this->customer();
        $gift = $this->giftCard(50, ['customer_id' => $customerId]);
        $credit = $this->storeCredit($customerId, 30);
        $other = $this->giftCard(77, ['customer_id' => $this->customer()]);

        $this->actAs($customerId);

        ob_start();
        do_action('woocommerce_account_'.Account::ENDPOINT_GIFT_CARDS.'_endpoint');
        $giftPage = wp_strip_all_tags(ob_get_clean());

        ob_start();
        do_action('woocommerce_account_'.Account::ENDPOINT_STORE_CREDIT.'_endpoint');
        $creditPage = wp_strip_all_tags(ob_get_clean());

        $this->assertStringContainsString('50,00', str_replace('.', ',', $giftPage));
        $this->assertStringNotContainsString('30,00', str_replace('.', ',', $giftPage));
        $this->assertStringNotContainsString(substr($other->code, -4), $giftPage);
        $this->assertStringContainsString('30,00', str_replace('.', ',', $creditPage));
        $this->assertStringNotContainsString('50,00', str_replace('.', ',', $creditPage));
        // Store credit has no code to show.
        $this->assertStringNotContainsString(substr($credit->code, -4), $creditPage);
        $this->assertStringNotContainsString($credit->formattedCode(), $creditPage);
        $this->assertNotSame('', $gift->code);
    }
}
