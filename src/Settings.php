<?php

namespace GeneroWP\StoreBalance;

class Settings
{
    public const OPTION = 'wc_store_balance_settings';

    /**
     * @return array{gift_card_expiry_days: int, store_credit_expiry_days: int}
     */
    public static function defaults(): array
    {
        return [
            'gift_card_expiry_days' => 730,
            'store_credit_expiry_days' => 365,
        ];
    }

    /**
     * @return array{gift_card_expiry_days: int, store_credit_expiry_days: int}
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $settings = self::defaults();

        foreach ($settings as $key => $default) {
            if (is_array($stored) && isset($stored[$key]) && is_numeric($stored[$key])) {
                $settings[$key] = max(0, (int) $stored[$key]);
            }
        }

        return $settings;
    }

    /**
     * Days until a new card of this type expires. 0 means it never does.
     */
    public static function expiryDays(string $type): int
    {
        $settings = self::all();
        $days = $type === Card::TYPE_STORE_CREDIT
            ? $settings['store_credit_expiry_days']
            : $settings['gift_card_expiry_days'];

        /**
         * Filters the number of days a new card is valid for.
         *
         * @param  int  $days  0 for no expiry.
         * @param  string  $type  Card::TYPE_GIFT_CARD or Card::TYPE_STORE_CREDIT.
         */
        return max(0, (int) apply_filters('wc_store_balance_expiry_days', $days, $type));
    }

    /**
     * The expiry timestamp for a card issued now, or null for none.
     */
    public static function expiryFor(string $type, ?int $days = null, ?int $from = null): ?int
    {
        $days ??= self::expiryDays($type);

        return $days > 0 ? ($from ?? time()) + $days * DAY_IN_SECONDS : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function save(array $input): void
    {
        $settings = self::defaults();

        foreach ($settings as $key => $default) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $settings[$key] = max(0, min(36500, (int) $input[$key]));
            }
        }

        update_option(self::OPTION, $settings);
    }
}
