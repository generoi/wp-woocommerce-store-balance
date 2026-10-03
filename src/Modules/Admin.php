<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Admin\CardsTable;
use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\Settings;
use GeneroWP\StoreBalance\StoreCredit;
use Throwable;

/**
 * WooCommerce → Store balance: every gift card and store credit, what the shop
 * owes in total, and the forms to add credit or create a card by hand.
 */
class Admin implements Module
{
    public const PAGE = 'wc-store-balance';

    public const CAPABILITY = 'manage_woocommerce';

    public const NOTICE = 'wc_store_balance_notice_';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 60);
        add_filter('woocommerce_screen_ids', [$this, 'screenIds']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        foreach (['add_credit', 'add_gift_card', 'card_action', 'save_settings'] as $action) {
            add_action('admin_post_wc_store_balance_'.$action, [$this, 'handle'.str_replace('_', '', ucwords($action, '_'))]);
        }
    }

    public function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Store balance', 'wp-woocommerce-store-balance'),
            __('Store balance', 'wp-woocommerce-store-balance'),
            self::CAPABILITY,
            self::PAGE,
            [$this, 'page']
        );
    }

    /**
     * Listed as a WooCommerce screen so its styles and the customer search
     * load here.
     *
     * @param  string[]  $ids
     * @return string[]
     */
    public function screenIds($ids)
    {
        $ids[] = 'woocommerce_page_'.self::PAGE;

        return $ids;
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_'.self::PAGE) {
            return;
        }

        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('wc-store-balance-admin', Plugin::url('assets/admin.css'), [], WC_STORE_BALANCE_VERSION);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => self::PAGE] + $args, admin_url('admin.php'));
    }

    public static function typeLabel(string $type): string
    {
        return $type === Card::TYPE_STORE_CREDIT
            ? __('Store credit', 'wp-woocommerce-store-balance')
            : __('Gift card', 'wp-woocommerce-store-balance');
    }

    public static function statusHtml(Card $card): string
    {
        if (! $card->isActive()) {
            return '<mark class="order-status status-cancelled"><span>'.esc_html__('Deactivated', 'wp-woocommerce-store-balance').'</span></mark>';
        }

        if ($card->isExpired()) {
            return '<mark class="order-status status-failed"><span>'.esc_html__('Expired', 'wp-woocommerce-store-balance').'</span></mark>';
        }

        if (! Money::isPositive($card->balance)) {
            return '<mark class="order-status status-on-hold"><span>'.esc_html__('Used up', 'wp-woocommerce-store-balance').'</span></mark>';
        }

        if ($card->deliverAt && ! $card->deliveredAt && $card->deliverAt > time()) {
            return '<mark class="order-status status-pending"><span>'.esc_html__('Scheduled', 'wp-woocommerce-store-balance').'</span></mark>';
        }

        return '<mark class="order-status status-processing"><span>'.esc_html__('Active', 'wp-woocommerce-store-balance').'</span></mark>';
    }

    public static function ownerHtml(Card $card): string
    {
        $user = $card->customerId ? get_userdata($card->customerId) : null;

        if ($user) {
            $name = trim($user->first_name.' '.$user->last_name) ?: $user->display_name;

            return sprintf(
                '<a href="%s">%s</a><br><small>%s</small>',
                esc_url(get_edit_user_link($user->ID)),
                esc_html($name),
                esc_html($user->user_email)
            );
        }

        return $card->recipientEmail !== ''
            ? esc_html($card->recipientEmail).'<br><small>'.esc_html__('Not added to an account', 'wp-woocommerce-store-balance').'</small>'
            : '&ndash;';
    }

    /**
     * Currencies a card can be created in. One by default; a shop that sells
     * in several adds them here.
     *
     * @return string[]
     */
    public static function currencies(): array
    {
        /**
         * Filters the currencies offered when creating a card in the admin.
         *
         * @param  string[]  $currencies  ISO 4217 codes.
         */
        $currencies = (array) apply_filters('wc_store_balance_currencies', [get_option('woocommerce_currency', 'EUR')]);

        return array_values(array_unique(array_filter(array_map('strtoupper', array_map('strval', $currencies)))));
    }

    public function page(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'cards';
        $tabs = [
            'cards' => __('All cards', 'wp-woocommerce-store-balance'),
            'add-credit' => __('Add store credit', 'wp-woocommerce-store-balance'),
            'add-gift-card' => __('Create gift card', 'wp-woocommerce-store-balance'),
            'settings' => __('Settings', 'wp-woocommerce-store-balance'),
        ];

        echo '<div class="wrap woocommerce wc-store-balance-admin">';
        echo '<h1 class="wp-heading-inline">'.esc_html__('Store balance', 'wp-woocommerce-store-balance').'</h1>';
        echo '<hr class="wp-header-end">';

        $this->notice();

        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            printf(
                '<a href="%s" class="nav-tab %s">%s</a>',
                esc_url(self::url($key === 'cards' ? [] : ['view' => $key])),
                ($view === $key || ($view === 'card' && $key === 'cards')) ? 'nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';

        Logger::guard('Admin page', function () use ($view): void {
            switch ($view) {
                case 'card':
                    $this->cardView();
                    break;
                case 'add-credit':
                    $this->addCreditView();
                    break;
                case 'add-gift-card':
                    $this->addGiftCardView();
                    break;
                case 'settings':
                    $this->settingsView();
                    break;
                default:
                    $this->cardsView();
            }
        });

        echo '</div>';
    }

    protected function cardsView(): void
    {
        $outstanding = Plugin::getInstance()->cards()->outstanding();

        echo '<div class="wc-store-balance-admin__summary">';

        if (! $outstanding) {
            echo '<div class="wc-store-balance-admin__stat"><span>'.esc_html__('Outstanding balance', 'wp-woocommerce-store-balance').'</span><strong>'.wp_kses_post(wc_price(0)).'</strong></div>';
        }

        foreach ($outstanding as $row) {
            printf(
                '<div class="wc-store-balance-admin__stat"><span>%s</span><strong>%s</strong><small>%s</small></div>',
                esc_html(sprintf(
                    /* translators: 1: card type, 2: currency code */
                    __('%1$s owed (%2$s)', 'wp-woocommerce-store-balance'),
                    self::typeLabel($row->type),
                    $row->currency
                )),
                wp_kses_post(wc_price((float) $row->balance, ['currency' => $row->currency])),
                esc_html(sprintf(
                    /* translators: %d: number of cards */
                    _n('%d card with a balance', '%d cards with a balance', (int) $row->cards, 'wp-woocommerce-store-balance'),
                    (int) $row->cards
                ))
            );
        }

        echo '</div>';

        $table = new CardsTable;
        $table->prepare_items();

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        $table->search_box(__('Search code, email or customer', 'wp-woocommerce-store-balance'), 'store-balance');
        $table->display();
        echo '</form>';
    }

    protected function cardView(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $card = Plugin::getInstance()->cards()->find(absint($_GET['id'] ?? 0));

        if (! $card) {
            echo '<p>'.esc_html__('That card does not exist.', 'wp-woocommerce-store-balance').'</p>';

            return;
        }

        $order = $card->orderId ? wc_get_order($card->orderId) : null;
        $date = static fn (?int $time) => $time ? wp_date(wc_date_format().' '.wc_time_format(), $time) : '–';

        $rows = [
            __('Type', 'wp-woocommerce-store-balance') => esc_html(self::typeLabel($card->type)),
            __('Status', 'wp-woocommerce-store-balance') => self::statusHtml($card),
            __('Balance', 'wp-woocommerce-store-balance') => '<strong>'.wc_price($card->balance, ['currency' => $card->currency]).'</strong> '.sprintf(
                /* translators: %s: amount */
                esc_html__('of %s', 'wp-woocommerce-store-balance'),
                wc_price($card->initialAmount, ['currency' => $card->currency])
            ),
            __('Currency', 'wp-woocommerce-store-balance') => esc_html($card->currency),
            __('Customer / recipient', 'wp-woocommerce-store-balance') => self::ownerHtml($card),
        ];

        if ($card->isGiftCard()) {
            $rows[__('Code', 'wp-woocommerce-store-balance')] = '<code>'.esc_html($card->formattedCode()).'</code>';
            $rows[__('Sent to', 'wp-woocommerce-store-balance')] = esc_html($card->recipientEmail ?: '–');
            $rows[__('From', 'wp-woocommerce-store-balance')] = esc_html($card->senderName ?: '–');
            $rows[__('Message', 'wp-woocommerce-store-balance')] = $card->message !== '' ? nl2br(esc_html($card->message)) : '–';
        }

        $rows[__('Email', 'wp-woocommerce-store-balance')] = $card->deliveredAt
            ? esc_html(sprintf(
                /* translators: %s: date and time */
                __('Sent %s', 'wp-woocommerce-store-balance'),
                $date($card->deliveredAt)
            ))
            : ($card->deliverAt
                ? esc_html(sprintf(
                    /* translators: %s: date and time */
                    __('Scheduled for %s', 'wp-woocommerce-store-balance'),
                    $date($card->deliverAt)
                ))
                : esc_html__('Not sent', 'wp-woocommerce-store-balance'));
        $rows[__('Valid until', 'wp-woocommerce-store-balance')] = esc_html($card->expiresAt ? $date($card->expiresAt) : __('No expiry', 'wp-woocommerce-store-balance'));
        $rows[__('Created', 'wp-woocommerce-store-balance')] = esc_html($date($card->createdAt));

        if ($order) {
            $rows[__('Order', 'wp-woocommerce-store-balance')] = sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()));
        }

        echo '<div class="wc-store-balance-admin__columns">';
        echo '<div class="wc-store-balance-admin__main">';
        echo '<h2>'.esc_html(self::typeLabel($card->type).' '.($card->isStoreCredit() ? '#'.$card->id : $card->maskedCode())).'</h2>';
        echo '<table class="widefat striped wc-store-balance-admin__details"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.wp_kses_post($value).'</td></tr>';
        }
        echo '</tbody></table>';

        $this->transactionsTable($card);
        echo '</div>';

        echo '<div class="wc-store-balance-admin__side">';
        $this->cardActions($card);
        echo '</div>';
        echo '</div>';
    }

    protected function transactionsTable(Card $card): void
    {
        $labels = [
            CardRepository::TX_ISSUE => __('Issued', 'wp-woocommerce-store-balance'),
            CardRepository::TX_REDEEM => __('Added to an account', 'wp-woocommerce-store-balance'),
            CardRepository::TX_DEBIT => __('Used', 'wp-woocommerce-store-balance'),
            CardRepository::TX_RELEASE => __('Returned', 'wp-woocommerce-store-balance'),
            CardRepository::TX_REFUND => __('Refunded', 'wp-woocommerce-store-balance'),
            CardRepository::TX_ADJUST => __('Adjusted', 'wp-woocommerce-store-balance'),
            CardRepository::TX_DISABLE => __('Deactivated', 'wp-woocommerce-store-balance'),
            CardRepository::TX_ENABLE => __('Activated', 'wp-woocommerce-store-balance'),
        ];

        echo '<h2>'.esc_html__('History', 'wp-woocommerce-store-balance').'</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Date', 'wp-woocommerce-store-balance'), __('Event', 'wp-woocommerce-store-balance'), __('Amount', 'wp-woocommerce-store-balance'), __('Balance after', 'wp-woocommerce-store-balance'), __('Order', 'wp-woocommerce-store-balance'), __('By', 'wp-woocommerce-store-balance'), __('Note', 'wp-woocommerce-store-balance')] as $heading) {
            echo '<th scope="col">'.esc_html($heading).'</th>';
        }
        echo '</tr></thead><tbody>';

        foreach (Plugin::getInstance()->cards()->transactions([$card->id], 200) as $tx) {
            $amount = (float) $tx->amount;
            $order = (int) $tx->order_id ? wc_get_order((int) $tx->order_id) : null;
            $user = (int) $tx->user_id ? get_userdata((int) $tx->user_id) : null;

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(wp_date(wc_date_format().' '.wc_time_format(), strtotime($tx->created_at.' UTC'))),
                esc_html($labels[$tx->type] ?? $tx->type),
                $amount == 0.0 ? '&ndash;' : ($amount > 0 ? '+' : '&minus;').wp_kses_post(wc_price(abs($amount), ['currency' => $card->currency])),
                wp_kses_post(wc_price((float) $tx->balance_after, ['currency' => $card->currency])),
                $order ? sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number())) : '&ndash;',
                $user ? esc_html($user->display_name) : '&ndash;',
                esc_html((string) $tx->note)
            );
        }

        echo '</tbody></table>';
    }

    protected function cardActions(Card $card): void
    {
        $form = function (string $do, string $button, string $class = 'button', string $fields = '') use ($card): void {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wc-store-balance-admin__action">';
            wp_nonce_field('wc_store_balance_card_action');
            echo '<input type="hidden" name="action" value="wc_store_balance_card_action">';
            echo '<input type="hidden" name="id" value="'.esc_attr((string) $card->id).'">';
            echo '<input type="hidden" name="do" value="'.esc_attr($do).'">';
            echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below from escaped parts.
            echo '<button type="submit" class="'.esc_attr($class).'">'.esc_html($button).'</button>';
            echo '</form>';
        };

        echo '<div class="postbox"><div class="inside">';
        echo '<h3>'.esc_html__('Actions', 'wp-woocommerce-store-balance').'</h3>';

        if ($card->recipientEmail !== '') {
            $form('resend', $card->deliveredAt ? __('Send the email again', 'wp-woocommerce-store-balance') : __('Send the email now', 'wp-woocommerce-store-balance'));
        }

        if ($card->isActive()) {
            $form('disable', __('Deactivate', 'wp-woocommerce-store-balance'), 'button', '<p class="description">'.esc_html__('A deactivated card cannot be spent. The balance is kept and it can be activated again.', 'wp-woocommerce-store-balance').'</p>');
        } else {
            $form('enable', __('Activate', 'wp-woocommerce-store-balance'), 'button button-primary');
        }

        echo '<hr>';
        echo '<h3>'.esc_html__('Correct the balance', 'wp-woocommerce-store-balance').'</h3>';

        $form(
            'adjust',
            __('Set balance', 'wp-woocommerce-store-balance'),
            'button',
            '<p><label for="sb-balance">'.esc_html(sprintf(
                /* translators: %s: currency code */
                __('New balance (%s)', 'wp-woocommerce-store-balance'),
                $card->currency
            )).'</label><br><input type="text" inputmode="decimal" id="sb-balance" name="balance" class="wc_input_price" value="'.esc_attr(wc_format_localized_price((string) $card->balance)).'" required></p>'
            .'<p><label for="sb-note">'.esc_html__('Reason', 'wp-woocommerce-store-balance').'</label><br><input type="text" id="sb-note" name="note" class="regular-text" required></p>'
        );

        echo '</div></div>';
    }

    protected function addCreditView(): void
    {
        $days = Settings::expiryDays(Card::TYPE_STORE_CREDIT);

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wc-store-balance-admin__form">';
        wp_nonce_field('wc_store_balance_add_credit');
        echo '<input type="hidden" name="action" value="wc_store_balance_add_credit">';
        echo '<p class="description">'.esc_html__('Store credit is tied to the customer\'s account. They do not get a code: it is used at checkout automatically when they are logged in.', 'wp-woocommerce-store-balance').'</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="sb-customer">'.esc_html__('Customer', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<select class="wc-customer-search" id="sb-customer" name="customer_id" data-placeholder="'.esc_attr__('Search for a customer…', 'wp-woocommerce-store-balance').'" data-allow_clear="true" style="width:25em" required></select>';
        echo '</td></tr>';

        $this->amountRows();

        echo '<tr><th scope="row"><label for="sb-note">'.esc_html__('Reason', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="text" class="regular-text" id="sb-note" name="note" placeholder="'.esc_attr__('E.g. goodwill for a late delivery', 'wp-woocommerce-store-balance').'">';
        echo '<p class="description">'.esc_html__('For your own records. The customer does not see it.', 'wp-woocommerce-store-balance').'</p>';
        echo '</td></tr>';

        $this->expiryRow($days);

        echo '<tr><th scope="row">'.esc_html__('Email', 'wp-woocommerce-store-balance').'</th><td>';
        echo '<label><input type="checkbox" name="send_email" value="1" checked> '.esc_html__('Tell the customer by email', 'wp-woocommerce-store-balance').'</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Add store credit', 'wp-woocommerce-store-balance'));
        echo '</form>';
    }

    protected function addGiftCardView(): void
    {
        $days = Settings::expiryDays(Card::TYPE_GIFT_CARD);

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wc-store-balance-admin__form">';
        wp_nonce_field('wc_store_balance_add_gift_card');
        echo '<input type="hidden" name="action" value="wc_store_balance_add_gift_card">';
        echo '<p class="description">'.esc_html__('Create a gift card without an order: a prize, a replacement, or a balance moved from another system.', 'wp-woocommerce-store-balance').'</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->amountRows();

        echo '<tr><th scope="row"><label for="sb-recipient">'.esc_html__('Recipient\'s email', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="email" class="regular-text" id="sb-recipient" name="recipient_email">';
        echo '<p class="description">'.esc_html__('Leave empty to only create the code and hand it over yourself.', 'wp-woocommerce-store-balance').'</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="sb-sender">'.esc_html__('From', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="text" class="regular-text" id="sb-sender" name="sender_name" value="'.esc_attr(get_bloginfo('name')).'">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="sb-message">'.esc_html__('Message', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<textarea class="large-text" rows="3" id="sb-message" name="message" maxlength="'.esc_attr((string) GiftCardProduct::MESSAGE_LENGTH).'"></textarea>';
        echo '</td></tr>';

        $this->expiryRow($days);

        echo '<tr><th scope="row">'.esc_html__('Email', 'wp-woocommerce-store-balance').'</th><td>';
        echo '<label><input type="checkbox" name="send_email" value="1" checked> '.esc_html__('Email the gift card to the recipient', 'wp-woocommerce-store-balance').'</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Create gift card', 'wp-woocommerce-store-balance'));
        echo '</form>';
    }

    protected function amountRows(): void
    {
        $currencies = self::currencies();

        echo '<tr><th scope="row"><label for="sb-amount">'.esc_html__('Amount', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="text" inputmode="decimal" class="wc_input_price" id="sb-amount" name="amount" required style="width:10em"> ';

        if (count($currencies) > 1) {
            echo '<label class="screen-reader-text" for="sb-currency">'.esc_html__('Currency', 'wp-woocommerce-store-balance').'</label>';
            echo '<select id="sb-currency" name="currency">';
            foreach ($currencies as $currency) {
                echo '<option value="'.esc_attr($currency).'">'.esc_html($currency).'</option>';
            }
            echo '</select>';
            echo '<p class="description">'.esc_html__('A balance can only be spent on orders in its own currency.', 'wp-woocommerce-store-balance').'</p>';
        } else {
            echo '<input type="hidden" name="currency" value="'.esc_attr($currencies[0] ?? '').'">'.esc_html($currencies[0] ?? '');
        }

        echo '</td></tr>';
    }

    protected function expiryRow(int $days): void
    {
        echo '<tr><th scope="row"><label for="sb-expires">'.esc_html__('Valid until', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="date" id="sb-expires" name="expires" value="'.esc_attr($days > 0 ? wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS) : '').'" min="'.esc_attr(wp_date('Y-m-d', time() + DAY_IN_SECONDS)).'">';
        echo '<p class="description">'.esc_html__('Leave empty for no expiry.', 'wp-woocommerce-store-balance').'</p>';
        echo '</td></tr>';
    }

    protected function settingsView(): void
    {
        $settings = Settings::all();

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wc-store-balance-admin__form">';
        wp_nonce_field('wc_store_balance_save_settings');
        echo '<input type="hidden" name="action" value="wc_store_balance_save_settings">';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ([
            'gift_card_expiry_days' => __('Gift cards are valid for', 'wp-woocommerce-store-balance'),
            'store_credit_expiry_days' => __('Store credit is valid for', 'wp-woocommerce-store-balance'),
        ] as $key => $label) {
            echo '<tr><th scope="row"><label for="sb-'.esc_attr($key).'">'.esc_html($label).'</label></th><td>';
            echo '<input type="number" min="0" step="1" class="small-text" id="sb-'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr((string) $settings[$key]).'"> '.esc_html__('days', 'wp-woocommerce-store-balance');
            echo '<p class="description">'.esc_html__('0 means no expiry. Applies to cards created from now on; existing ones keep their date.', 'wp-woocommerce-store-balance').'</p>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">'.sprintf(
            /* translators: %s: link to the log screen */
            esc_html__('Errors and refused operations are written to the %s, under the source "wp-woocommerce-store-balance".', 'wp-woocommerce-store-balance'),
            '<a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=logs&source='.Logger::SOURCE)).'">'.esc_html__('WooCommerce log', 'wp-woocommerce-store-balance').'</a>'
        ).'</p>';
        submit_button();
        echo '</form>';
    }

    public function handleAddCredit(): void
    {
        $this->authorize('wc_store_balance_add_credit');

        $amount = Money::parse(wp_unslash($_POST['amount'] ?? ''));
        $customerId = absint($_POST['customer_id'] ?? 0);

        if ($amount === null) {
            $this->redirect(['view' => 'add-credit'], __('Enter an amount greater than zero.', 'wp-woocommerce-store-balance'), 'error');
        }

        if (! $customerId) {
            $this->redirect(['view' => 'add-credit'], __('Choose a customer.', 'wp-woocommerce-store-balance'), 'error');
        }

        $card = StoreCredit::issue($customerId, $amount, $this->postedCurrency(), [
            'note' => sanitize_text_field(wp_unslash($_POST['note'] ?? '')),
            'expires_at' => $this->postedExpiry(),
            'send_email' => ! empty($_POST['send_email']),
        ]);

        if (is_wp_error($card)) {
            $this->redirect(['view' => 'add-credit'], $card->get_error_message(), 'error');
        }

        $this->redirect(['view' => 'card', 'id' => $card->id], sprintf(
            /* translators: %s: amount */
            __('%s store credit added to the customer\'s account.', 'wp-woocommerce-store-balance'),
            wp_strip_all_tags(wc_price($card->balance, ['currency' => $card->currency]))
        ));
    }

    public function handleAddGiftCard(): void
    {
        $this->authorize('wc_store_balance_add_gift_card');

        $amount = Money::parse(wp_unslash($_POST['amount'] ?? ''));
        $recipient = sanitize_email(wp_unslash($_POST['recipient_email'] ?? ''));
        $rawRecipient = trim((string) wp_unslash($_POST['recipient_email'] ?? ''));

        if ($amount === null) {
            $this->redirect(['view' => 'add-gift-card'], __('Enter an amount greater than zero.', 'wp-woocommerce-store-balance'), 'error');
        }

        if ($rawRecipient !== '' && ! is_email($recipient)) {
            $this->redirect(['view' => 'add-gift-card'], __('Enter a valid email address for the recipient.', 'wp-woocommerce-store-balance'), 'error');
        }

        try {
            $card = Plugin::getInstance()->cards()->create([
                'type' => Card::TYPE_GIFT_CARD,
                'amount' => $amount,
                'currency' => $this->postedCurrency(),
                'recipient_email' => $recipient,
                'sender_name' => sanitize_text_field(wp_unslash($_POST['sender_name'] ?? '')),
                'message' => sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')),
                'expires_at' => $this->postedExpiry(),
                'note' => 'Created by hand',
            ]);
        } catch (Throwable $e) {
            Logger::exception($e, 'Creating a gift card by hand');
            $this->redirect(['view' => 'add-gift-card'], __('The gift card could not be created. The error has been logged.', 'wp-woocommerce-store-balance'), 'error');
        }

        $sent = false;

        if (! empty($_POST['send_email']) && $recipient !== '') {
            $emails = Plugin::getInstance()->module(Emails::class);
            $sent = $emails ? $emails->send($card) : false;
        }

        $this->redirect(['view' => 'card', 'id' => $card->id], $sent
            ? __('Gift card created and emailed to the recipient.', 'wp-woocommerce-store-balance')
            : __('Gift card created. It has not been emailed: the code is shown below.', 'wp-woocommerce-store-balance'));
    }

    public function handleCardAction(): void
    {
        $this->authorize('wc_store_balance_card_action');

        $cards = Plugin::getInstance()->cards();
        $card = $cards->find(absint($_POST['id'] ?? 0));

        if (! $card) {
            $this->redirect([], __('That card does not exist.', 'wp-woocommerce-store-balance'), 'error');
        }

        $back = ['view' => 'card', 'id' => $card->id];

        switch (sanitize_key($_POST['do'] ?? '')) {
            case 'disable':
                $cards->setStatus($card->id, Card::STATUS_DISABLED, 'Deactivated by hand');
                $this->redirect($back, __('Card deactivated.', 'wp-woocommerce-store-balance'));
                // no break
            case 'enable':
                $cards->setStatus($card->id, Card::STATUS_ACTIVE, 'Activated by hand');
                $this->redirect($back, __('Card activated.', 'wp-woocommerce-store-balance'));
                // no break
            case 'adjust':
                $raw = trim((string) wp_unslash($_POST['balance'] ?? ''));
                $balance = $raw === '0' || $raw === '0,00' || $raw === '0.00' ? 0.0 : Money::parse($raw);
                $note = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));

                if ($balance === null) {
                    $this->redirect($back, __('Enter the new balance as a number.', 'wp-woocommerce-store-balance'), 'error');
                }

                if ($note === '') {
                    $this->redirect($back, __('Give a reason for the correction. It is kept in the card\'s history.', 'wp-woocommerce-store-balance'), 'error');
                }

                $cards->adjust($card->id, $balance, $note);
                $this->redirect($back, __('Balance corrected.', 'wp-woocommerce-store-balance'));
                // no break
            case 'resend':
                $emails = Plugin::getInstance()->module(Emails::class);

                if ($emails && $emails->send($card)) {
                    $this->redirect($back, sprintf(
                        /* translators: %s: email address */
                        __('Email sent to %s.', 'wp-woocommerce-store-balance'),
                        $card->recipientEmail
                    ));
                }

                $this->redirect($back, __('The email could not be sent. The error has been logged.', 'wp-woocommerce-store-balance'), 'error');
        }

        $this->redirect($back);
    }

    public function handleSaveSettings(): void
    {
        $this->authorize('wc_store_balance_save_settings');

        Settings::save(wp_unslash($_POST));

        $this->redirect(['view' => 'settings'], __('Settings saved.', 'wp-woocommerce-store-balance'));
    }

    protected function authorize(string $nonce): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'wp-woocommerce-store-balance'), 403);
        }

        check_admin_referer($nonce);
    }

    protected function postedCurrency(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $currency = strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? '')));
        $allowed = self::currencies();

        return in_array($currency, $allowed, true) ? $currency : ($allowed[0] ?? get_woocommerce_currency());
    }

    /**
     * End of the chosen day in the shop's timezone; null for no expiry.
     */
    protected function postedExpiry(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $value = sanitize_text_field(wp_unslash($_POST['expires'] ?? ''));
        $date = $value !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone()) : false;

        return $date ? $date->setTime(23, 59, 59)->getTimestamp() : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return never
     */
    protected function redirect(array $args, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            set_transient(self::NOTICE.get_current_user_id(), ['message' => $message, 'type' => $type], MINUTE_IN_SECONDS);
        }

        wp_safe_redirect(self::url($args));
        exit;
    }

    protected function notice(): void
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
}
