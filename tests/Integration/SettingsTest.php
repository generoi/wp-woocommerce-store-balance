<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Settings;

class SettingsTest extends TestCase
{
    public function test_gift_cards_last_two_years_and_store_credit_one_out_of_the_box(): void
    {
        $this->assertSame(730, Settings::expiryDays(Card::TYPE_GIFT_CARD));
        $this->assertSame(365, Settings::expiryDays(Card::TYPE_STORE_CREDIT));
    }

    public function test_saved_settings_are_used(): void
    {
        Settings::save(['gift_card_expiry_days' => '1095', 'store_credit_expiry_days' => '90']);

        $this->assertSame(1095, Settings::expiryDays(Card::TYPE_GIFT_CARD));
        $this->assertSame(90, Settings::expiryDays(Card::TYPE_STORE_CREDIT));
    }

    /**
     * Zero is a setting, not an empty field: it means the card never expires.
     * It must not fall back to the default.
     */
    public function test_zero_days_means_no_expiry(): void
    {
        Settings::save(['gift_card_expiry_days' => '0', 'store_credit_expiry_days' => '0']);

        $this->assertSame(0, Settings::expiryDays(Card::TYPE_GIFT_CARD));
        $this->assertNull(Settings::expiryFor(Card::TYPE_GIFT_CARD));
        $this->assertNull(Settings::expiryFor(Card::TYPE_STORE_CREDIT));
    }

    public function test_nonsense_is_not_saved(): void
    {
        Settings::save(['gift_card_expiry_days' => '-30', 'store_credit_expiry_days' => 'soon']);

        $this->assertSame(0, Settings::all()['gift_card_expiry_days']);
        $this->assertSame(365, Settings::all()['store_credit_expiry_days']);

        Settings::save(['gift_card_expiry_days' => '999999']);

        $this->assertSame(36500, Settings::all()['gift_card_expiry_days']);
    }

    /**
     * The option is read on every card that is issued. Whatever is in it —
     * an import, another plugin, a hand edit — must not break issuing.
     */
    public function test_a_broken_option_falls_back_to_the_defaults(): void
    {
        foreach (['garbage', ['gift_card_expiry_days' => 'x'], ['gift_card_expiry_days' => [1]], null] as $stored) {
            update_option(Settings::OPTION, $stored);

            $this->assertSame(Settings::defaults(), Settings::all());
        }
    }

    public function test_the_expiry_counts_whole_days_from_now_or_from_a_given_moment(): void
    {
        $from = strtotime('2030-01-01 00:00:00 UTC');

        $this->assertSame($from + 10 * DAY_IN_SECONDS, Settings::expiryFor(Card::TYPE_GIFT_CARD, 10, $from));
        $this->assertEqualsWithDelta(time() + 730 * DAY_IN_SECONDS, Settings::expiryFor(Card::TYPE_GIFT_CARD), 5);
        $this->assertNull(Settings::expiryFor(Card::TYPE_GIFT_CARD, 0, $from));
    }

    /**
     * Some countries set a minimum validity for gift cards by law; a site
     * enforces that in code rather than trusting the settings screen.
     */
    public function test_the_validity_can_be_filtered_per_type(): void
    {
        add_filter('wc_store_balance_expiry_days', static fn (int $days, string $type) => $type === Card::TYPE_GIFT_CARD ? 1825 : $days, 10, 2);

        $this->assertSame(1825, Settings::expiryDays(Card::TYPE_GIFT_CARD));
        $this->assertSame(365, Settings::expiryDays(Card::TYPE_STORE_CREDIT));
    }

    public function test_a_filter_cannot_make_the_validity_negative(): void
    {
        add_filter('wc_store_balance_expiry_days', static fn () => -5);

        $this->assertSame(0, Settings::expiryDays(Card::TYPE_GIFT_CARD));
        $this->assertNull(Settings::expiryFor(Card::TYPE_GIFT_CARD));
    }

    /**
     * Changing the setting is about cards issued from now on. A card already
     * in someone's inbox keeps the date it was promised.
     */
    public function test_changing_the_setting_does_not_move_the_expiry_of_existing_cards(): void
    {
        $card = wc_store_balance_issue_store_credit($this->customer(), 10.0, 'EUR', ['send_email' => false]);

        Settings::save(['store_credit_expiry_days' => '30']);

        $this->assertSame($card->expiresAt, $this->cards->find($card->id)->expiresAt);
        $this->assertEqualsWithDelta(
            time() + 30 * DAY_IN_SECONDS,
            wc_store_balance_issue_store_credit($this->customer(), 10.0, 'EUR', ['send_email' => false])->expiresAt,
            5
        );
    }
}
