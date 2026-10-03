<?php

namespace GeneroWP\StoreBalance\Admin;

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Modules\Admin;
use GeneroWP\StoreBalance\Plugin;
use WP_List_Table;

if (! class_exists(WP_List_Table::class)) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

class CardsTable extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(['singular' => 'store-balance-card', 'plural' => 'store-balance-cards', 'ajax' => false]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'code' => __('Card', 'wp-woocommerce-store-balance'),
            'owner' => __('Customer / recipient', 'wp-woocommerce-store-balance'),
            'balance' => __('Balance', 'wp-woocommerce-store-balance'),
            'status' => __('Status', 'wp-woocommerce-store-balance'),
            'expires' => __('Expires', 'wp-woocommerce-store-balance'),
            'order' => __('Order', 'wp-woocommerce-store-balance'),
            'created' => __('Created', 'wp-woocommerce-store-balance'),
        ];
    }

    public function prepare_items(): void
    {
        $perPage = 25;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $args = [
            'type' => isset($_GET['type']) && in_array($_GET['type'], Card::types(), true) ? sanitize_key($_GET['type']) : null,
            'state' => isset($_GET['status']) && array_key_exists($_GET['status'], self::states()) ? sanitize_key($_GET['status']) : null,
            'search' => isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
        ];
        // phpcs:enable

        $cards = Plugin::getInstance()->cards();
        $total = $cards->count($args);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $cards->query($args + ['limit' => $perPage, 'offset' => ($this->get_pagenum() - 1) * $perPage]);
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $perPage]);
    }

    /**
     * @return array<string, string>
     */
    public static function states(): array
    {
        return [
            'usable' => __('Active', 'wp-woocommerce-store-balance'),
            'spent' => __('Spent', 'wp-woocommerce-store-balance'),
            'expired' => __('Expired', 'wp-woocommerce-store-balance'),
            'disabled' => __('Deactivated', 'wp-woocommerce-store-balance'),
        ];
    }

    public function no_items(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! empty($_GET['s']) || ! empty($_GET['type']) || ! empty($_GET['status'])) {
            esc_html_e('No cards match your search.', 'wp-woocommerce-store-balance');

            return;
        }

        esc_html_e('No gift cards or store credit yet.', 'wp-woocommerce-store-balance');
    }

    /**
     * The code is shown masked, as everywhere outside the card's own screen:
     * a list is what is on screen when someone looks over a shoulder.
     */

    /**
     * @param  Card  $card
     */
    protected function column_code($card): string
    {
        $user = $card->customerId ? get_userdata($card->customerId) : null;
        $who = $user ? (trim($user->first_name.' '.$user->last_name) ?: $user->display_name) : $card->recipientEmail;

        // On a narrow screen WordPress shows only this column. The line under
        // the title carries what the hidden columns would have said.
        return sprintf(
            '<a class="row-title" href="%s">%s <code class="wc-store-balance-admin__ref">%s</code></a><div class="wc-store-balance-admin__row-summary">%s &middot; %s &middot; %s</div>',
            esc_url(Admin::url(['view' => 'card', 'id' => $card->id])),
            esc_html(Admin::typeLabel($card->type)),
            esc_html($card->reference()),
            esc_html($who !== '' ? $who : '–'),
            wp_kses_post(wc_price($card->balance, ['currency' => $card->currency])),
            wp_kses_post(wp_strip_all_tags(Admin::statusHtml($card)))
        );
    }

    /**
     * @param  Card  $card
     */
    protected function column_owner($card): string
    {
        return Admin::ownerHtml($card);
    }

    /**
     * @param  Card  $card
     */
    protected function column_balance($card): string
    {
        $html = wc_price($card->balance, ['currency' => $card->currency]);

        if ($card->balance != $card->initialAmount) {
            $html .= '<br><small>'.sprintf(
                /* translators: %s: amount */
                esc_html__('of %s', 'wp-woocommerce-store-balance'),
                wc_price($card->initialAmount, ['currency' => $card->currency])
            ).'</small>';
        }

        return $html;
    }

    /**
     * @param  Card  $card
     */
    protected function column_status($card): string
    {
        return Admin::statusHtml($card);
    }

    /**
     * @param  Card  $card
     */
    protected function column_expires($card): string
    {
        return $card->expiresAt ? esc_html(wp_date(wc_date_format(), $card->expiresAt)) : '&ndash;';
    }

    /**
     * @param  Card  $card
     */
    protected function column_order($card): string
    {
        $order = $card->orderId ? wc_get_order($card->orderId) : null;

        return $order
            ? sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()))
            : '&ndash;';
    }

    /**
     * @param  Card  $card
     */
    protected function column_created($card): string
    {
        return esc_html(wp_date(wc_date_format(), $card->createdAt));
    }

    /**
     * @param  string  $which
     */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $type = isset($_GET['type']) ? sanitize_key($_GET['type']) : '';
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        // phpcs:enable

        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="filter-type">'.esc_html__('Filter by type', 'wp-woocommerce-store-balance').'</label>';
        echo '<select name="type" id="filter-type">';
        echo '<option value="">'.esc_html__('All types', 'wp-woocommerce-store-balance').'</option>';
        foreach (Card::types() as $option) {
            printf('<option value="%s" %s>%s</option>', esc_attr($option), selected($type, $option, false), esc_html(Admin::typeLabel($option)));
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="filter-status">'.esc_html__('Filter by status', 'wp-woocommerce-store-balance').'</label>';
        echo '<select name="status" id="filter-status">';
        echo '<option value="">'.esc_html__('All statuses', 'wp-woocommerce-store-balance').'</option>';
        foreach (self::states() as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($status, $value, false), esc_html($label));
        }
        echo '</select>';

        submit_button(__('Filter', 'wp-woocommerce-store-balance'), '', 'filter_action', false);
        echo '</div>';
    }
}
