<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use WC_Order;

/**
 * The order edit screen: what was paid from a balance, and which gift cards
 * the order bought.
 */
class OrderAdmin implements Module
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'metaBox'], 30, 2);
        add_action('woocommerce_admin_order_totals_after_tax', [$this, 'totalsRow']);
        add_action('woocommerce_after_order_itemmeta', [$this, 'itemDetails'], 10, 2);
    }

    /**
     * Only on an order that was paid from a balance: an empty box on every
     * other order would be noise.
     *
     * @param  string  $screen
     * @param  \WP_Post|WC_Order|null  $object
     */
    public function metaBox($screen, $object = null): void
    {
        // The order screen has two ids: the classic post screen, and the
        // HPOS one. wc_get_page_screen_id() returns whichever is in use.
        $orderScreen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        if (! in_array($screen, [$orderScreen, 'shop_order'], true)) {
            return;
        }

        $order = $object instanceof WC_Order ? $object : ($object instanceof \WP_Post ? wc_get_order($object->ID) : null);

        if (! $order instanceof WC_Order || ! Orders::lines($order)) {
            return;
        }

        add_meta_box(
            'wc-store-balance-order',
            __('Paid with gift card / store credit', 'wp-woocommerce-store-balance'),
            [$this, 'renderMetaBox'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * @param  \WP_Post|WC_Order  $object
     */
    public function renderMetaBox($object): void
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID);

        if (! $order instanceof WC_Order) {
            return;
        }

        Logger::guard('Order meta box', function () use ($order): void {
            $currency = $order->get_currency();

            echo '<ul class="wc-store-balance-order__lines">';

            foreach (Orders::lines($order) as $line) {
                printf(
                    '<li><a href="%s">%s %s</a>: %s%s</li>',
                    esc_url(Admin::url(['view' => 'card', 'id' => $line['card_id']])),
                    esc_html(Admin::typeLabel($line['type'])),
                    esc_html($line['masked']),
                    wp_kses_post(wc_price($line['amount'], ['currency' => $currency])),
                    $line['restored'] > 0
                        ? '<br><small>'.esc_html(sprintf(
                            /* translators: %s: amount */
                            __('%s returned to the card', 'wp-woocommerce-store-balance'),
                            Money::plain($line['restored'], $currency)
                        )).'</small>'
                        : ''
                );
            }

            echo '</ul>';

            $held = Orders::held($order);
            $applied = Money::plain(Orders::applied($order), $currency);

            if ($held <= 0) {
                echo '<p class="description">'.esc_html__('This amount has been returned to the cards it came from.', 'wp-woocommerce-store-balance').'</p>';

                return;
            }

            // What WooCommerce's own Refund button can and cannot do here is
            // not something staff should have to find out by trying.
            if ((float) $order->get_total() > 0) {
                echo '<p class="description">'.esc_html(sprintf(
                    /* translators: 1: amount paid through the payment method, 2: payment method name */
                    __('WooCommerce\'s Refund button only covers the %1$s paid by %2$s.', 'wp-woocommerce-store-balance'),
                    Money::plain($order->get_total(), $currency),
                    $order->get_payment_method_title() ?: __('the payment method', 'wp-woocommerce-store-balance')
                )).'</p>';
            } else {
                echo '<p class="description">'.esc_html__('Nothing was paid through a payment method, so WooCommerce\'s Refund button has nothing to refund.', 'wp-woocommerce-store-balance').'</p>';
            }

            echo '<p class="description">'.esc_html(sprintf(
                /* translators: %s: amount */
                __('Setting the order to Cancelled or Refunded returns the full %s to the cards automatically.', 'wp-woocommerce-store-balance'),
                $applied
            )).'</p>';
            echo '<p class="description">'.sprintf(
                /* translators: %s: link to the "Add store credit" screen */
                esc_html__('To give back only part of it, use %s and note the order number. Do not do both.', 'wp-woocommerce-store-balance'),
                '<a href="'.esc_url(Admin::url(['view' => 'add-credit'])).'">'.esc_html__('Add store credit', 'wp-woocommerce-store-balance').'</a>'
            ).'</p>';
        });
    }

    /**
     * The row in the order's totals box. Without it the admin sees items that
     * add up to more than the order total, with nothing to say why.
     */
    public function totalsRow($orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        $applied = Orders::applied($order);

        if ($applied <= 0) {
            return;
        }

        foreach (Orders::byType(Orders::lines($order)) as $type => $amount) {
            printf(
                '<tr><td class="label">%s:</td><td width="1%%"></td><td class="total">&minus;%s</td></tr>',
                esc_html(Orders::label([['type' => $type]])),
                wp_kses_post(wc_price($amount, ['currency' => $order->get_currency()]))
            );
        }
    }

    /**
     * Under a gift card line item: who it was for, and the cards it produced.
     */
    public function itemDetails($itemId, $item): void
    {
        if (! $item instanceof \WC_Order_Item_Product) {
            return;
        }

        $data = $item->get_meta(Issuance::ITEM_DATA);

        if (! is_array($data)) {
            return;
        }

        echo '<div class="wc-store-balance-order__item"><table class="display_meta"><tbody>';

        $rows = GiftCardProduct::describe($data);
        $issuance = Plugin::getInstance()->module(Issuance::class);
        $status = $issuance ? $issuance->deliveryStatus($item) : '';

        if ($status !== '') {
            $rows[__('Delivery', 'wp-woocommerce-store-balance')] = $status;
        }

        foreach ($rows as $label => $value) {
            printf('<tr><th>%s:</th><td>%s</td></tr>', esc_html($label), nl2br(esc_html($value)));
        }

        $ids = array_filter(array_map('absint', (array) $item->get_meta(Issuance::ITEM_CARDS)));

        if ($ids) {
            $links = [];

            foreach ($ids as $id) {
                $card = Plugin::getInstance()->cards()->find($id);

                if ($card) {
                    $links[] = sprintf('<a href="%s">%s</a>', esc_url(Admin::url(['view' => 'card', 'id' => $id])), esc_html($card->maskedCode()));
                }
            }

            printf('<tr><th>%s:</th><td>%s</td></tr>', esc_html__('Gift card', 'wp-woocommerce-store-balance'), wp_kses_post(implode(', ', $links)));
        } else {
            printf('<tr><th>%s:</th><td>%s</td></tr>', esc_html__('Gift card', 'wp-woocommerce-store-balance'), esc_html__('Created when the order is paid', 'wp-woocommerce-store-balance'));
        }

        echo '</tbody></table></div>';
    }
}
