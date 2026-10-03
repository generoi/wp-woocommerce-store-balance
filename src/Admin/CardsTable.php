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
            'code' => __('Code', 'wp-woocommerce-store-balance'),
            'type' => __('Type', 'wp-woocommerce-store-balance'),
            'owner' => __('Customer / recipient', 'wp-woocommerce-store-balance'),
            'balance' => __('Balance', 'wp-woocommerce-store-balance'),
            'status' => __('Status', 'wp-woocommerce-store-balance'),
            'expires' => __('Valid until', 'wp-woocommerce-store-balance'),
            'created' => __('Created', 'wp-woocommerce-store-balance'),
        ];
    }

    public function prepare_items(): void
    {
        $perPage = 25;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $args = [
            'type' => isset($_GET['type']) && in_array($_GET['type'], Card::types(), true) ? sanitize_key($_GET['type']) : null,
            'status' => isset($_GET['status']) && in_array($_GET['status'], [Card::STATUS_ACTIVE, Card::STATUS_DISABLED], true) ? sanitize_key($_GET['status']) : null,
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
        ];
        // phpcs:enable

        $cards = Plugin::getInstance()->cards();
        $total = $cards->count($args);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $cards->query($args + ['limit' => $perPage, 'offset' => ($this->get_pagenum() - 1) * $perPage]);
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $perPage]);
    }

    public function no_items(): void
    {
        esc_html_e('No gift cards or store credit yet.', 'wp-woocommerce-store-balance');
    }

    /**
     * @param  Card  $card
     */
    protected function column_code($card): string
    {
        return sprintf(
            '<a class="row-title" href="%s"><code>%s</code></a>',
            esc_url(Admin::url(['view' => 'card', 'id' => $card->id])),
            esc_html($card->isStoreCredit() ? '#'.$card->id : $card->formattedCode())
        );
    }

    /**
     * @param  Card  $card
     */
    protected function column_type($card): string
    {
        return esc_html(Admin::typeLabel($card->type));
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
        printf('<option value="%s" %s>%s</option>', esc_attr(Card::STATUS_ACTIVE), selected($status, Card::STATUS_ACTIVE, false), esc_html__('Active', 'wp-woocommerce-store-balance'));
        printf('<option value="%s" %s>%s</option>', esc_attr(Card::STATUS_DISABLED), selected($status, Card::STATUS_DISABLED, false), esc_html__('Deactivated', 'wp-woocommerce-store-balance'));
        echo '</select>';

        submit_button(__('Filter', 'wp-woocommerce-store-balance'), '', 'filter_action', false);
        echo '</div>';
    }
}
