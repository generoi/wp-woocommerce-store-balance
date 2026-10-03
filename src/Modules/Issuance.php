<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Lock;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\Settings;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Creates the gift cards a paid order bought, and withdraws them when the
 * order is undone.
 */
class Issuance implements Module
{
    /** Order item meta: what the customer entered on the product page. */
    public const ITEM_DATA = '_store_balance_gift_card';

    /** Order item meta: ids of the cards issued for the line. */
    public const ITEM_CARDS = '_store_balance_card_ids';

    /** Order meta: cards disabled because the order was cancelled or refunded. */
    public const ORDER_DISABLED = '_store_balance_disabled_cards';

    public function register(): void
    {
        // All three, because not every route to "paid" fires every hook: a
        // gateway calls payment_complete, an admin or a script sets the status.
        // Issuing is idempotent, so the overlap costs nothing.
        add_action('woocommerce_payment_complete', [$this, 'issueForOrderId'], 20);
        add_action('woocommerce_order_status_processing', [$this, 'issueForOrderId'], 20);
        add_action('woocommerce_order_status_completed', [$this, 'issueForOrderId'], 20);

        add_action('woocommerce_order_status_cancelled', [$this, 'withdrawForOrderId'], 20);
        add_action('woocommerce_order_status_refunded', [$this, 'withdrawForOrderId'], 20);
        add_action('woocommerce_order_status_failed', [$this, 'withdrawForOrderId'], 20);

        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hiddenItemMeta']);
        add_action('woocommerce_order_item_meta_end', [$this, 'itemDetails'], 20, 4);
    }

    public function issueForOrderId($orderId): void
    {
        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order) {
            $this->issue($order);
        }
    }

    public function issue(WC_Order $order): void
    {
        // "Paid" is announced more than once, and sometimes at the same
        // moment: a gateway's webhook and the customer's return to the shop.
        // Without the lock each of them would find no cards yet, and each
        // would create them.
        Lock::order($order->get_id(), function () use ($order): void {
            $this->issueLocked($order);
        });
    }

    protected function issueLocked(WC_Order $order): void
    {
        $order->read_meta_data(true);
        $this->reinstate($order);

        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            // As it is in the database now, not as this request loaded it.
            $item->read_meta_data(true);
            $data = $item->get_meta(self::ITEM_DATA);

            if (! is_array($data) || empty($data['amount'])) {
                continue;
            }

            $issued = array_filter(array_map('absint', (array) $item->get_meta(self::ITEM_CARDS)));
            $missing = $item->get_quantity() - count($issued);

            for ($i = 0; $i < $missing; $i++) {
                try {
                    $card = $this->create($order, $item, $data);
                } catch (Throwable $e) {
                    Logger::exception($e, 'Issuing a gift card', ['order_id' => $order->get_id(), 'order_item_id' => $item->get_id()]);

                    $order->add_order_note(sprintf(
                        /* translators: %s: product name */
                        __('A gift card for "%s" could not be created. The error has been logged; create it by hand under WooCommerce → Store balance.', 'wp-woocommerce-store-balance'),
                        $item->get_name()
                    ));

                    break;
                }

                // Saved after every card, so a failure halfway never leads to
                // the same card being issued twice on the next attempt.
                $issued[] = $card->id;
                $item->update_meta_data(self::ITEM_CARDS, $issued);
                $item->save();

                $order->add_order_note(sprintf(
                    /* translators: 1: masked gift card code, 2: recipient email */
                    __('Gift card %1$s issued to %2$s.', 'wp-woocommerce-store-balance'),
                    $card->maskedCode(),
                    $card->recipientEmail
                ));

                $emails = Plugin::getInstance()->module(Emails::class);

                if ($emails) {
                    $emails->deliver($card);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function create(WC_Order $order, WC_Order_Item_Product $item, array $data): Card
    {
        $deliverAt = null;

        if (! empty($data['delivery'])) {
            // Morning of that day in the shop's timezone: a gift should not
            // arrive at midnight.
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['delivery'], wp_timezone());
            $time = $date ? $date->setTime(8, 0)->getTimestamp() : null;

            /**
             * Filters when a scheduled gift card is sent.
             *
             * @param  int|null  $time  Unix timestamp.
             * @param  array<string, mixed>  $data  What the buyer entered.
             * @param  WC_Order  $order
             */
            $time = apply_filters('wc_store_balance_delivery_time', $time, $data, $order);

            if ($time && $time > time()) {
                $deliverAt = (int) $time;
            }
        }

        $product = $item->get_product();
        $days = $product ? $product->get_meta(GiftCardProduct::META_EXPIRY) : '';

        return Plugin::getInstance()->cards()->create([
            'type' => Card::TYPE_GIFT_CARD,
            'amount' => (float) $data['amount'],
            'currency' => $order->get_currency(),
            'recipient_email' => ! empty($data['to']) ? $data['to'] : $order->get_billing_email(),
            // No recipient means the buyer gets it themselves: there is no
            // "from" in that email, whatever name they typed.
            'sender_name' => empty($data['to'])
                ? ''
                : (! empty($data['from']) ? $data['from'] : trim($order->get_billing_first_name().' '.$order->get_billing_last_name())),
            'message' => (string) ($data['message'] ?? ''),
            'locale' => (string) ($data['locale'] ?? ''),
            'order_id' => $order->get_id(),
            'order_item_id' => $item->get_id(),
            'deliver_at' => $deliverAt,
            // Counted from the day the recipient gets it, not the day it was bought.
            'expires_at' => Settings::expiryFor(Card::TYPE_GIFT_CARD, is_numeric($days) ? (int) $days : null, $deliverAt),
        ]);
    }

    public function withdrawForOrderId($orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        Lock::order($order->get_id(), function () use ($order): void {
            $order->read_meta_data(true);
            $this->withdraw($order);
        });
    }

    protected function withdraw(WC_Order $order): void
    {

        $cards = Plugin::getInstance()->cards();
        $disabled = array_filter(array_map('absint', (array) $order->get_meta(self::ORDER_DISABLED)));

        foreach ($cards->forOrder($order->get_id()) as $card) {
            // Store credit issued by refunding this order is the refund itself.
            if (! $card->isGiftCard() || ! $card->isActive()) {
                continue;
            }

            if ($card->balance < $card->initialAmount) {
                Logger::warning('A gift card from a cancelled or refunded order had already been spent from', [
                    'order_id' => $order->get_id(),
                    'card_id' => $card->id,
                    'spent' => $card->initialAmount - $card->balance,
                ]);

                $order->add_order_note(sprintf(
                    /* translators: %s: masked gift card code */
                    __('Warning: gift card %s had already been partly spent when this order was cancelled or refunded.', 'wp-woocommerce-store-balance'),
                    $card->maskedCode()
                ));
            }

            if ($cards->setStatus($card->id, Card::STATUS_DISABLED, sprintf(
                /* translators: 1: order number, 2: order status */
                __('Order #%1$s %2$s', 'wp-woocommerce-store-balance'),
                $order->get_order_number(),
                strtolower(wc_get_order_status_name($order->get_status()))
            ))) {
                $disabled[] = $card->id;

                $order->add_order_note(sprintf(
                    /* translators: 1: masked gift card code, 2: amount */
                    __('Gift card %1$s deactivated (%2$s unspent).', 'wp-woocommerce-store-balance'),
                    $card->maskedCode(),
                    Money::plain($card->balance, $card->currency)
                ));
            }
        }

        if ($disabled) {
            $order->update_meta_data(self::ORDER_DISABLED, array_values(array_unique($disabled)));
            $order->save();
        }
    }

    /**
     * A cancelled order that is paid after all gets its cards back, not new ones.
     */
    protected function reinstate(WC_Order $order): void
    {
        $disabled = array_filter(array_map('absint', (array) $order->get_meta(self::ORDER_DISABLED)));

        if (! $disabled) {
            return;
        }

        // Money has gone back to the buyer. Reopening the order does not
        // undo that, so it must not bring the gift cards back either.
        if ((float) $order->get_total_refunded() > 0) {
            $order->add_order_note(__('This order has been refunded, so its gift cards stay deactivated. Activate them by hand under WooCommerce → Store balance if the customer has paid again.', 'wp-woocommerce-store-balance'));
            $order->delete_meta_data(self::ORDER_DISABLED);
            $order->save();

            return;
        }

        foreach ($disabled as $cardId) {
            Plugin::getInstance()->cards()->setStatus($cardId, Card::STATUS_ACTIVE, sprintf(
                /* translators: %s: order number */
                __('Order #%s paid', 'wp-woocommerce-store-balance'),
                $order->get_order_number()
            ));
        }

        $order->delete_meta_data(self::ORDER_DISABLED);
        $order->save();
    }

    /**
     * @param  string[]  $keys
     * @return string[]
     */
    public function hiddenItemMeta($keys)
    {
        return array_merge((array) $keys, [self::ITEM_DATA, self::ITEM_CARDS]);
    }

    /**
     * Who the gift card goes to, under the line item on the thank-you page, in
     * My Account and in order emails. The code itself is never shown here: it
     * belongs to the recipient.
     */
    public function itemDetails($itemId, $item, $order, $plainText = false): void
    {
        if (! $item instanceof WC_Order_Item_Product) {
            return;
        }

        $data = $item->get_meta(self::ITEM_DATA);

        if (! is_array($data)) {
            return;
        }

        $rows = GiftCardProduct::describe($data);

        if ($order instanceof WC_Order && empty($data['to'])) {
            $rows[__('To', 'wp-woocommerce-store-balance')] = $order->get_billing_email();
        }

        $status = $this->deliveryStatus($item);

        if ($status !== '') {
            $rows[__('Delivery', 'wp-woocommerce-store-balance')] = $status;
        }

        if ($plainText) {
            foreach ($rows as $label => $value) {
                echo "\n".esc_html($label).': '.esc_html($value);
            }

            return;
        }

        echo '<ul class="wc-item-meta store-balance-item-meta">';

        foreach ($rows as $label => $value) {
            printf(
                '<li><strong class="wc-item-meta-label">%s:</strong> %s</li>',
                esc_html($label),
                nl2br(esc_html($value))
            );
        }

        echo '</ul>';
    }

    /**
     * What has happened to the cards of a line item, for the buyer: sent, or
     * when they will be. Empty until the order is paid.
     */
    public function deliveryStatus(WC_Order_Item_Product $item): string
    {
        $ids = array_filter(array_map('absint', (array) $item->get_meta(self::ITEM_CARDS)));
        $card = $ids ? Plugin::getInstance()->cards()->find((int) reset($ids)) : null;

        if (! $card) {
            return '';
        }

        if (! $card->isActive()) {
            return __('Cancelled', 'wp-woocommerce-store-balance');
        }

        if ($card->deliveredAt) {
            return sprintf(
                /* translators: %s: date */
                __('Emailed on %s', 'wp-woocommerce-store-balance'),
                wp_date(wc_date_format(), $card->deliveredAt)
            );
        }

        if ($card->deliverAt) {
            return sprintf(
                /* translators: %s: date */
                __('Will be emailed on %s, in the morning', 'wp-woocommerce-store-balance'),
                wp_date(wc_date_format(), $card->deliverAt)
            );
        }

        return '';
    }
}
