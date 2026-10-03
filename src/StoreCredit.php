<?php

namespace GeneroWP\StoreBalance;

use GeneroWP\StoreBalance\Modules\Emails;
use GeneroWP\StoreBalance\Modules\Orders;
use Throwable;
use WC_Order;
use WP_Error;

/**
 * Putting store credit on a customer account: loaded by an admin, or given
 * instead of a refund.
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

    /**
     * What can still be returned to the customer for an order: what they paid
     * through the gateway and have not been refunded, plus what they paid from
     * a balance and have not had back.
     */
    public static function refundable(WC_Order $order): float
    {
        return Money::round((float) $order->get_remaining_refund_amount() + Orders::held($order));
    }

    /**
     * Give the customer store credit instead of money back.
     *
     * The part of the order that was paid from a balance is converted first;
     * only what is left is recorded as a WooCommerce refund. That way a partial
     * refund does not flip the order to "refunded" while part of it stands.
     * Nothing is sent to the payment gateway: the money stays with the store.
     */
    public static function refundOrder(WC_Order $order, float $amount, string $note = ''): Card|WP_Error
    {
        $amount = Money::round($amount);
        $refundable = self::refundable($order);

        if ($amount <= 0) {
            return new WP_Error('wc_store_balance_invalid_amount', __('Enter an amount greater than zero.', 'wp-woocommerce-store-balance'));
        }

        if ($amount > $refundable) {
            return new WP_Error('wc_store_balance_refund_too_large', sprintf(
                /* translators: %s: amount */
                __('At most %s can be refunded for this order.', 'wp-woocommerce-store-balance'),
                wp_strip_all_tags(wc_price($refundable, ['currency' => $order->get_currency()]))
            ));
        }

        $customerId = self::customerFor($order);

        if (is_wp_error($customerId)) {
            return $customerId;
        }

        $fromBalance = Money::round(min($amount, Orders::held($order)));
        $fromOrder = Money::round($amount - $fromBalance);

        if ($fromBalance > 0) {
            Orders::convert($order, $fromBalance);
        }

        if ($fromOrder > 0) {
            $refund = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => $fromOrder,
                'reason' => $note !== '' ? $note : __('Refunded to store credit', 'wp-woocommerce-store-balance'),
                'refund_payment' => false,
                'restock_items' => false,
            ]);

            if (is_wp_error($refund)) {
                if ($fromBalance > 0) {
                    Orders::convert($order, -$fromBalance);
                }

                Logger::error('Refund to store credit: WooCommerce refused the refund', [
                    'order_id' => $order->get_id(),
                    'amount' => $fromOrder,
                    'error' => $refund->get_error_message(),
                ]);

                return $refund;
            }
        }

        $card = self::issue($customerId, $amount, $order->get_currency(), [
            'order_id' => $order->get_id(),
            'note' => $note !== '' ? $note : sprintf(
                /* translators: %s: order number */
                __('Refund of order #%s', 'wp-woocommerce-store-balance'),
                $order->get_order_number()
            ),
        ]);

        if (is_wp_error($card)) {
            // The refund is recorded but the credit is not: this needs a person.
            Logger::error('Refund to store credit: the refund was recorded but the credit could not be issued', [
                'order_id' => $order->get_id(),
                'amount' => $amount,
                'error' => $card->get_error_message(),
            ]);

            $order->add_order_note(sprintf(
                /* translators: %s: amount */
                __('Store credit of %s could not be issued after the refund was recorded. Add it by hand under WooCommerce → Store balance.', 'wp-woocommerce-store-balance'),
                wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()]))
            ));

            return $card;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: amount */
            __('%s refunded to the customer as store credit.', 'wp-woocommerce-store-balance'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()]))
        ));

        /**
         * Fires after an order has been refunded to store credit.
         */
        do_action('wc_store_balance_order_refunded_to_store_credit', $order, $card, $amount);

        return $card;
    }

    /**
     * Store credit lives on an account. A guest order gets one: an existing
     * account with the billing email if there is one, otherwise a new account,
     * for which WooCommerce sends its usual "set your password" email.
     */
    public static function customerFor(WC_Order $order): int|WP_Error
    {
        if ($order->get_customer_id() && get_userdata($order->get_customer_id())) {
            return $order->get_customer_id();
        }

        $email = $order->get_billing_email();

        if (! is_email($email)) {
            return new WP_Error('wc_store_balance_no_email', __('The order has no billing email, so there is no account to put the credit on.', 'wp-woocommerce-store-balance'));
        }

        $user = get_user_by('email', $email);

        if ($user) {
            $customerId = $user->ID;
        } else {
            $customerId = wc_create_new_customer($email, '', wp_generate_password(24), [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
            ]);

            if (is_wp_error($customerId)) {
                Logger::error('Could not create an account for store credit', [
                    'order_id' => $order->get_id(),
                    'error' => $customerId->get_error_message(),
                ]);

                return $customerId;
            }

            $order->add_order_note(__('An account was created for the customer to hold their store credit.', 'wp-woocommerce-store-balance'));
        }

        $order->set_customer_id($customerId);
        $order->save();

        return (int) $customerId;
    }
}
