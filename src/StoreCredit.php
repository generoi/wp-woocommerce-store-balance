<?php

namespace GeneroWP\StoreBalance;

use GeneroWP\StoreBalance\Modules\Emails;
use Throwable;
use WP_Error;

/**
 * Putting store credit on a customer account. Only the shop does this: an
 * admin on the Store balance screen, or code calling the public function.
 */
class StoreCredit
{
    /**
     * @param  array{note?: string, order_id?: int, expires_at?: int|null, send_email?: bool}  $args
     */
    public static function issue(int $customerId, float $amount, string $currency = '', array $args = []): Card|WP_Error
    {
        $amount = Money::round($amount);
        $currency = strtoupper($currency ?: get_woocommerce_currency());
        $user = get_userdata($customerId);

        if (! $user) {
            return new WP_Error('wc_store_balance_no_customer', __('Store credit needs a customer account.', 'wp-woocommerce-store-balance'));
        }

        if ($amount <= 0) {
            return new WP_Error('wc_store_balance_invalid_amount', __('Enter an amount greater than zero.', 'wp-woocommerce-store-balance'));
        }

        if (! array_key_exists($currency, get_woocommerce_currencies())) {
            return new WP_Error('wc_store_balance_invalid_currency', __('Unknown currency.', 'wp-woocommerce-store-balance'));
        }

        try {
            $card = Plugin::getInstance()->cards()->create([
                'type' => Card::TYPE_STORE_CREDIT,
                'amount' => $amount,
                'currency' => $currency,
                'customer_id' => $customerId,
                'recipient_email' => $user->user_email,
                'order_id' => (int) ($args['order_id'] ?? 0),
                'note' => (string) ($args['note'] ?? ''),
                'locale' => get_user_locale($customerId),
                'expires_at' => array_key_exists('expires_at', $args)
                    ? $args['expires_at']
                    : Settings::expiryFor(Card::TYPE_STORE_CREDIT),
            ]);
        } catch (Throwable $e) {
            Logger::exception($e, 'Issuing store credit', ['customer_id' => $customerId, 'amount' => $amount, 'currency' => $currency]);

            return new WP_Error('wc_store_balance_issue_failed', __('The store credit could not be created. The error has been logged.', 'wp-woocommerce-store-balance'));
        }

        if ($args['send_email'] ?? true) {
            $emails = Plugin::getInstance()->module(Emails::class);

            if ($emails) {
                $emails->send($card);
            }
        }

        return $card;
    }
}
