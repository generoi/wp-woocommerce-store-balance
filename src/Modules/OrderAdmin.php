<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\StoreCredit;
use WC_Order;

/**
 * The order edit screen: what was paid from a balance, which gift cards the
 * order bought, and the "Refund to store credit" box.
 */
class OrderAdmin implements Module
{
    public const NOTICE = 'wc_store_balance_order_notice_';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'metaBox'], 30, 2);
        add_action('woocommerce_admin_order_totals_after_tax', [$this, 'totalsRow']);
        add_action('woocommerce_after_order_itemmeta', [$this, 'itemDetails'], 10, 2);
        add_action('admin_post_wc_store_balance_refund_order', [$this, 'handleRefund']);
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * @param  string  $screen
     * @param  \WP_Post|WC_Order  $object
     */
    public function metaBox($screen, $object = null): void
    {
        // The order screen has two ids: the classic post screen, and the
        // HPOS one. wc_get_page_screen_id() returns whichever is in use.
        $orderScreen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        if (! in_array($screen, [$orderScreen, 'shop_order'], true)) {
            return;
        }

        add_meta_box(
            'wc-store-balance-order',
            __('Store credit', 'wp-woocommerce-store-balance'),
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
            $lines = Orders::lines($order);
            $refundable = StoreCredit::refundable($order);

            if ($lines) {
                echo '<p><strong>'.esc_html__('Paid from a balance', 'wp-woocommerce-store-balance').'</strong></p><ul class="wc-store-balance-order__lines">';

                foreach ($lines as $line) {
                    printf(
                        '<li><a href="%s">%s %s</a>: %s%s</li>',
                        esc_url(Admin::url(['view' => 'card', 'id' => $line['card_id']])),
                        esc_html(Admin::typeLabel($line['type'])),
                        esc_html($line['masked']),
                        wp_kses_post(wc_price($line['amount'], ['currency' => $currency])),
                        ($line['restored'] + $line['converted']) > 0
                            ? ' <small>('.esc_html(sprintf(
                                /* translators: %s: amount */
                                __('%s returned', 'wp-woocommerce-store-balance'),
                                wp_strip_all_tags(wc_price($line['restored'] + $line['converted'], ['currency' => $currency]))
                            )).')</small>'
                            : ''
                    );
                }

                echo '</ul><hr>';
            }

            // The meta box sits inside the order's own <form>, and a form
            // cannot be nested. The fields carry a `form` attribute pointing at
            // a separate form printed in the footer.
            echo '<p><strong>'.esc_html__('Refund to store credit', 'wp-woocommerce-store-balance').'</strong></p>';

            if (! $order->get_id() || $refundable <= 0) {
                echo '<p class="description">'.esc_html__('There is nothing left to refund on this order.', 'wp-woocommerce-store-balance').'</p>';

                return;
            }

            $guest = ! $order->get_customer_id();

            echo '<p class="description">'.esc_html(sprintf(
                /* translators: %s: amount */
                __('Give the customer store credit instead of money back. Up to %s. Nothing is sent to the payment provider.', 'wp-woocommerce-store-balance'),
                wp_strip_all_tags(wc_price($refundable, ['currency' => $currency]))
            )).'</p>';

            if ($guest) {
                echo '<p class="description">'.esc_html(sprintf(
                    /* translators: %s: email address */
                    __('This was a guest order. The credit goes on the account for %s; one is created if it does not exist.', 'wp-woocommerce-store-balance'),
                    $order->get_billing_email()
                )).'</p>';
            }

            $form = 'wc-store-balance-refund-form';

            echo '<p><label for="wc-store-balance-refund-amount">'.esc_html(sprintf(
                /* translators: %s: currency code */
                __('Amount (%s)', 'wp-woocommerce-store-balance'),
                $currency
            )).'</label><br>';
            echo '<input type="text" inputmode="decimal" class="wc_input_price" id="wc-store-balance-refund-amount" name="amount" form="'.esc_attr($form).'" value="'.esc_attr(wc_format_localized_price((string) $refundable)).'" style="width:100%"></p>';
            echo '<p><label for="wc-store-balance-refund-note">'.esc_html__('Reason (optional)', 'wp-woocommerce-store-balance').'</label><br>';
            echo '<input type="text" id="wc-store-balance-refund-note" name="note" form="'.esc_attr($form).'" style="width:100%"></p>';
            echo '<p><button type="submit" class="button" form="'.esc_attr($form).'" onclick="return confirm(\''.esc_js(__('Refund this amount to the customer as store credit?', 'wp-woocommerce-store-balance')).'\')">'.esc_html__('Refund to store credit', 'wp-woocommerce-store-balance').'</button></p>';

            add_action('admin_footer', static function () use ($order, $form): void {
                echo '<form id="'.esc_attr($form).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('wc_store_balance_refund_order_'.$order->get_id());
                echo '<input type="hidden" name="action" value="wc_store_balance_refund_order">';
                echo '<input type="hidden" name="order_id" value="'.esc_attr((string) $order->get_id()).'">';
                echo '</form>';
            });
        });
    }

    public function handleRefund(): void
    {
        $orderId = absint($_POST['order_id'] ?? 0);

        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You are not allowed to do this.', 'wp-woocommerce-store-balance'), 403);
        }

        check_admin_referer('wc_store_balance_refund_order_'.$orderId);

        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('That order does not exist.', 'wp-woocommerce-store-balance'), 404);
        }

        $amount = Money::parse(wp_unslash($_POST['amount'] ?? ''));
        $note = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));

        if ($amount === null) {
            $this->finish($order, __('Enter an amount greater than zero.', 'wp-woocommerce-store-balance'), 'error');
        }

        $card = StoreCredit::refundOrder($order, $amount, $note);

        if (is_wp_error($card)) {
            $this->finish($order, $card->get_error_message(), 'error');
        }

        $this->finish($order, sprintf(
            /* translators: %s: amount */
            __('%s refunded to the customer as store credit.', 'wp-woocommerce-store-balance'),
            wp_strip_all_tags(wc_price($card->balance, ['currency' => $card->currency]))
        ));
    }

    /**
     * @return never
     */
    protected function finish(WC_Order $order, string $message, string $type = 'success'): void
    {
        set_transient(self::NOTICE.get_current_user_id(), ['message' => $message, 'type' => $type], MINUTE_IN_SECONDS);
        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }

    public function notice(): void
    {
        $notice = get_transient(self::NOTICE.get_current_user_id());

        if (! is_array($notice)) {
            return;
        }

        delete_transient(self::NOTICE.get_current_user_id());

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $notice['type'] === 'error' ? 'error' : 'success',
            esc_html($notice['message'])
        );
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

        printf(
            '<tr><td class="label">%s:</td><td width="1%%"></td><td class="total">&minus;%s</td></tr>',
            esc_html(Orders::label(Orders::lines($order))),
            wp_kses_post(wc_price($applied, ['currency' => $order->get_currency()]))
        );
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

        foreach (GiftCardProduct::describe($data) as $label => $value) {
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
