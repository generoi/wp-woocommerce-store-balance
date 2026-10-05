<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Admin\CardsTable;
use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Input;
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

    /** @var array<string, string> What was typed into a form that came back with an error. */
    protected array $old = [];

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
            return '<mark class="order-status status-on-hold"><span>'.esc_html__('Spent', 'wp-woocommerce-store-balance').'</span></mark>';
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
            'cards' => __('Cards & credits', 'wp-woocommerce-store-balance'),
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
            echo '<div class="wc-store-balance-admin__stat"><span>'.esc_html__('Unspent gift cards and store credit', 'wp-woocommerce-store-balance').'</span><strong>'.wp_kses_post(wc_price(0)).'</strong></div>';
        }

        foreach ($outstanding as $row) {
            printf(
                '<div class="wc-store-balance-admin__stat"><span>%s</span><strong>%s</strong><small>%s</small></div>',
                esc_html(sprintf(
                    $row->type === Card::TYPE_STORE_CREDIT
                        /* translators: %s: currency code */
                        ? __('Unspent store credit (%s)', 'wp-woocommerce-store-balance')
                        /* translators: %s: currency code */
                        : __('Unspent gift cards (%s)', 'wp-woocommerce-store-balance'),
                    $row->currency
                )),
                wp_kses_post(Money::price((float) $row->balance, $row->currency)),
                esc_html(sprintf(
                    /* translators: %d: number of cards */
                    _n('%d active card', '%d active cards', (int) $row->cards, 'wp-woocommerce-store-balance'),
                    (int) $row->cards
                ))
            );
        }

        echo '</div>';

        $table = new CardsTable;
        $table->prepare_items();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(Input::text(wp_unslash($_GET['s']))) : '';

        if ($search !== '') {
            echo '<p class="subtitle">'.esc_html(sprintf(
                /* translators: %s: search term */
                __('Search results for: %s', 'wp-woocommerce-store-balance'),
                $search
            )).' <a href="'.esc_url(self::url()).'">'.esc_html__('Show all', 'wp-woocommerce-store-balance').'</a></p>';
        }

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        $table->search_box(__('Search', 'wp-woocommerce-store-balance'), 'store-balance');
        echo '<p class="description wc-store-balance-admin__search-hint">'.esc_html__('Search by gift card code (or its last four characters), store credit number, email or customer name.', 'wp-woocommerce-store-balance').'</p>';
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
            __('Balance', 'wp-woocommerce-store-balance') => '<strong>'.Money::price($card->balance, $card->currency).'</strong> '.sprintf(
                /* translators: %s: amount */
                esc_html__('of %s', 'wp-woocommerce-store-balance'),
                Money::price($card->initialAmount, $card->currency)
            ),
            __('Currency', 'wp-woocommerce-store-balance') => esc_html($card->currency),
            $card->isStoreCredit() ? __('Customer', 'wp-woocommerce-store-balance') : __('In the account of', 'wp-woocommerce-store-balance') => $card->customerId ? self::ownerHtml($card) : esc_html__('Nobody yet: it has not been added to an account', 'wp-woocommerce-store-balance'),
        ];

        if ($card->isGiftCard()) {
            $rows[__('Code', 'wp-woocommerce-store-balance')] = '<code class="wc-store-balance-admin__code">'.esc_html($card->formattedCode()).'</code>'
                .' <button type="button" class="button button-small" data-copy="'.esc_attr($card->formattedCode()).'" data-copied="'.esc_attr__('Copied', 'wp-woocommerce-store-balance').'"">'.esc_html__('Copy', 'wp-woocommerce-store-balance').'</button>';
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
        $rows[__('Expires', 'wp-woocommerce-store-balance')] = esc_html($card->expiresAt ? $date($card->expiresAt) : __('No expiry', 'wp-woocommerce-store-balance'));
        $rows[__('Created', 'wp-woocommerce-store-balance')] = esc_html($date($card->createdAt));

        if ($order) {
            $rows[__('Order', 'wp-woocommerce-store-balance')] = sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()));
        }

        echo '<p><a href="'.esc_url(self::url()).'">&larr; '.esc_html__('All cards and credits', 'wp-woocommerce-store-balance').'</a></p>';

        // A card switched off because its order was cancelled or refunded is
        // one click from being live again. Say what that click would do.
        if (! $card->isActive() && $order && $order->has_status(['cancelled', 'refunded', 'failed'])) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html(sprintf(
                /* translators: 1: order number, 2: order status, 3: amount */
                __('Deactivated because order #%1$s is %2$s. Activating it gives its owner %3$s that the shop has not been paid for.', 'wp-woocommerce-store-balance'),
                $order->get_order_number(),
                strtolower(wc_get_order_status_name($order->get_status())),
                Money::plain($card->balance, $card->currency)
            )).'</p></div>';
        }

        echo '<div class="wc-store-balance-admin__columns">';
        echo '<div class="wc-store-balance-admin__main">';
        echo '<h2>'.esc_html(self::typeLabel($card->type).' '.$card->reference()).'</h2>';
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
                $amount == 0.0 ? '&ndash;' : ($amount > 0 ? '+' : '&minus;').wp_kses_post(Money::price(abs($amount), $card->currency)),
                wp_kses_post(Money::price((float) $tx->balance_after, $card->currency)),
                $order ? sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number())) : '&ndash;',
                $user ? esc_html($user->display_name) : '&ndash;',
                esc_html((string) $tx->note)
            );
        }

        echo '</tbody></table>';
    }

    protected function cardActions(Card $card): void
    {
        $form = function (string $do, string $button, string $class = 'button', string $fields = '', string $confirm = '', string $after = '') use ($card): void {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wc-store-balance-admin__action"'.($confirm !== '' ? ' data-confirm="'.esc_attr($confirm).'"' : '').'>';
            wp_nonce_field('wc_store_balance_card_action');
            echo '<input type="hidden" name="action" value="wc_store_balance_card_action">';
            echo '<input type="hidden" name="id" value="'.esc_attr((string) $card->id).'">';
            echo '<input type="hidden" name="do" value="'.esc_attr($do).'">';
            echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below from escaped parts.
            echo '<button type="submit" class="'.esc_attr($class).'">'.esc_html($button).'</button>';
            echo $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below from escaped parts.
            echo '</form>';
        };

        $balance = Money::plain($card->balance, $card->currency);

        echo '<div class="postbox"><div class="inside">';
        echo '<h3>'.esc_html__('Actions', 'wp-woocommerce-store-balance').'</h3>';

        // Only for a card that can be spent: sending the code of a dead card
        // again tells the recipient they have money they do not have.
        if ($card->recipientEmail !== '' && $card->isUsable()) {
            $form(
                'resend',
                $card->deliveredAt ? __('Send the email again', 'wp-woocommerce-store-balance') : __('Send the email now', 'wp-woocommerce-store-balance'),
                'button',
                '',
                '',
                '<p class="description">'.esc_html(sprintf(
                    $card->isStoreCredit()
                        /* translators: %s: email address */
                        ? __('Tells %s how much store credit they have. No code is sent.', 'wp-woocommerce-store-balance')
                        /* translators: %s: email address */
                        : __('Sends the gift card, with its code, to %s.', 'wp-woocommerce-store-balance'),
                    $card->recipientEmail
                )).'</p>'
            );
        }

        if ($card->isActive()) {
            $form(
                'disable',
                __('Deactivate', 'wp-woocommerce-store-balance'),
                'button',
                '',
                '',
                '<p class="description">'.esc_html__('A deactivated card cannot be spent. The balance is kept and it can be activated again.', 'wp-woocommerce-store-balance').'</p>'
            );
        } else {
            $form(
                'enable',
                __('Activate', 'wp-woocommerce-store-balance'),
                'button',
                '',
                sprintf(
                    /* translators: %s: amount */
                    __('Activate this card? Its owner will be able to spend %s.', 'wp-woocommerce-store-balance'),
                    $balance
                )
            );
        }

        echo '<hr>';
        echo '<h3>'.esc_html__('Change the balance', 'wp-woocommerce-store-balance').'</h3>';

        $form(
            'adjust',
            __('Save new balance', 'wp-woocommerce-store-balance'),
            'button',
            '<p><label for="sb-balance">'.esc_html(sprintf(
                /* translators: %s: currency code */
                __('New balance (%s)', 'wp-woocommerce-store-balance'),
                $card->currency
            )).'</label><br><input type="text" inputmode="decimal" id="sb-balance" name="balance" class="wc_input_price" value="'.esc_attr(wc_format_localized_price(wc_format_decimal($card->balance, wc_get_price_decimals()))).'" required></p>'
            .'<p><label for="sb-note">'.esc_html__('Reason', 'wp-woocommerce-store-balance').'</label><br><input type="text" id="sb-note" name="note" class="regular-text" required>'
            .'<span class="description">'.esc_html__('Saved in the history. The customer does not see it.', 'wp-woocommerce-store-balance').'</span></p>',
            sprintf(
                /* translators: 1: current balance, 2: placeholder for the new balance, 3: placeholder for the difference */
                __('Change the balance from %1$s to %2$s (%3$s)?', 'wp-woocommerce-store-balance'),
                $balance,
                '{balance}',
                '{difference}'
            )
        );

        echo '</div></div>';

        // Money-changing actions ask first. The figure is read the way the
        // server will read it and shown back with the difference, so "1.000"
        // is confirmed as one thousand, not as whatever was typed.
        ?>
        <script>
        (function () {
            var current = <?php echo wp_json_encode((float) $card->balance); ?>;
            var original = <?php echo wp_json_encode((float) $card->initialAmount); ?>;
            var format = <?php echo wp_json_encode([
                'decimals' => wc_get_price_decimals(),
                'decimal' => wc_get_price_decimal_separator(),
                'thousand' => wc_get_price_thousand_separator(),
                'symbol' => html_entity_decode(get_woocommerce_currency_symbol($card->currency), ENT_QUOTES, 'UTF-8'),
                'pattern' => html_entity_decode(get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8'),
            ]); ?>;
            var above = <?php echo wp_json_encode(sprintf(
                /* translators: %s: amount */
                __('This is more than the card\'s original %s.', 'wp-woocommerce-store-balance'),
                Money::plain($card->initialAmount, $card->currency)
            )); ?>;

            function parse(value) {
                var v = String(value).replace(/[\s\u00A0\u202F]/g, '');

                if (v.indexOf(',') > -1 && v.indexOf('.') > -1) {
                    v = v.lastIndexOf(',') > v.lastIndexOf('.') ? v.replace(/\./g, '') : v.replace(/,/g, '');
                }

                v = v.replace(',', '.');

                return /^\d+(\.\d{0,2})?$/.test(v) ? parseFloat(v) : NaN;
            }

            // Written the way the shop writes prices everywhere else.
            function money(amount) {
                var parts = amount.toFixed(format.decimals).split('.');
                var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, format.thousand);
                var number = parts[1] ? whole + format.decimal + parts[1] : whole;

                return format.pattern.replace('%1$s', format.symbol).replace('%2$s', number);
            }

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!navigator.clipboard) {
                        return;
                    }

                    navigator.clipboard.writeText(button.dataset.copy).then(function () {
                        var label = button.textContent;

                        button.textContent = button.dataset.copied;
                        window.setTimeout(function () {
                            button.textContent = label;
                        }, 1500);
                    });
                });
            });

            document.querySelectorAll('.wc-store-balance-admin__action[data-confirm]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    var field = form.querySelector('[name="balance"]');
                    var message = form.dataset.confirm;

                    if (field) {
                        var amount = parse(field.value);

                        // Not a number: the server says so, with the form intact.
                        if (isNaN(amount)) {
                            return;
                        }

                        var difference = amount - current;

                        // Nothing to confirm; the server says so.
                        if (Math.abs(difference) < 0.005) {
                            return;
                        }

                        message = message
                            .replace('{balance}', money(amount))
                            .replace('{difference}', (difference >= 0 ? '+' : '\u2212') + money(Math.abs(difference)));

                        if (amount > original) {
                            message += '\n\n' + above;
                        }
                    }

                    if (!window.confirm(message)) {
                        event.preventDefault();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    protected function addCreditView(): void
    {
        $days = Settings::expiryDays(Card::TYPE_STORE_CREDIT);
        $customer = absint($this->old('customer_id')) ? get_userdata(absint($this->old('customer_id'))) : null;

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wc-store-balance-admin__form">';
        wp_nonce_field('wc_store_balance_add_credit');
        echo '<input type="hidden" name="action" value="wc_store_balance_add_credit">';
        echo '<p class="description">'.esc_html__('Store credit is tied to the customer\'s account. They do not get a code: it is used at checkout automatically when they are logged in.', 'wp-woocommerce-store-balance').'</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="sb-customer">'.esc_html__('Customer', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<select class="wc-customer-search" id="sb-customer" name="customer_id" data-placeholder="'.esc_attr__('Search for a customer…', 'wp-woocommerce-store-balance').'" data-allow_clear="true" style="width:25em" required>';
        if ($customer) {
            printf('<option value="%d" selected>%s</option>', (int) $customer->ID, esc_html(sprintf('%s (#%d – %s)', trim($customer->first_name.' '.$customer->last_name) ?: $customer->display_name, $customer->ID, $customer->user_email)));
        }
        echo '</select>';
        echo '</td></tr>';

        $this->amountRows();

        echo '<tr><th scope="row"><label for="sb-note">'.esc_html__('Reason', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="text" class="regular-text" id="sb-note" name="note" value="'.esc_attr($this->old('note')).'" placeholder="'.esc_attr__('E.g. goodwill for a late delivery, order #1234', 'wp-woocommerce-store-balance').'">';
        echo '<p class="description">'.esc_html__('Saved in the history. The customer does not see it.', 'wp-woocommerce-store-balance').'</p>';
        echo '</td></tr>';

        $this->expiryRow($days);

        echo '<tr><th scope="row">'.esc_html__('Email', 'wp-woocommerce-store-balance').'</th><td>';
        echo '<label><input type="checkbox" name="send_email" value="1" '.checked($this->old('send_email', '1'), '1', false).'> '.esc_html__('Tell the customer by email', 'wp-woocommerce-store-balance').'</label>';
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
        echo '<input type="email" class="regular-text" id="sb-recipient" name="recipient_email" value="'.esc_attr($this->old('recipient_email')).'">';
        echo '<p class="description">'.esc_html__('We email the gift card to them. Leave empty to only create the code: it is shown on the next screen for you to copy and hand over.', 'wp-woocommerce-store-balance').'</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="sb-sender">'.esc_html__('From', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="text" class="regular-text" id="sb-sender" name="sender_name" maxlength="'.esc_attr((string) GiftCardProduct::NAME_LENGTH).'" value="'.esc_attr($this->old('sender_name', get_bloginfo('name'))).'">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="sb-message">'.esc_html__('Message', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<textarea class="large-text" rows="3" id="sb-message" name="message" maxlength="'.esc_attr((string) GiftCardProduct::MESSAGE_LENGTH).'">'.esc_textarea($this->old('message')).'</textarea>';
        echo '</td></tr>';

        $this->expiryRow($days);

        echo '</tbody></table>';
        submit_button(__('Create gift card', 'wp-woocommerce-store-balance'));
        echo '</form>';
    }

    protected function amountRows(): void
    {
        $currencies = self::currencies();
        $chosen = $this->old('currency');

        echo '<tr><th scope="row"><label for="sb-amount">'.esc_html__('Amount', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<span class="wc-store-balance-admin__amount">';
        echo '<input type="text" inputmode="decimal" class="wc_input_price" id="sb-amount" name="amount" value="'.esc_attr($this->old('amount')).'" required style="width:10em"> ';

        if (count($currencies) > 1) {
            echo '<label class="screen-reader-text" for="sb-currency">'.esc_html__('Currency', 'wp-woocommerce-store-balance').'</label>';
            echo '<select id="sb-currency" name="currency">';
            foreach ($currencies as $currency) {
                echo '<option value="'.esc_attr($currency).'" '.selected($chosen, $currency, false).'>'.esc_html($currency).'</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="hidden" name="currency" value="'.esc_attr($currencies[0] ?? '').'"><span>'.esc_html($currencies[0] ?? '').'</span>';
        }

        echo '</span>';

        if (count($currencies) > 1) {
            echo '<p class="description">'.esc_html__('A balance can only be spent on orders in its own currency.', 'wp-woocommerce-store-balance').'</p>';
        }

        echo '</td></tr>';
    }

    protected function expiryRow(int $days): void
    {
        $value = $this->hasOld() ? $this->old('expires') : ($days > 0 ? wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS) : '');

        echo '<tr><th scope="row"><label for="sb-expires">'.esc_html__('Expires', 'wp-woocommerce-store-balance').'</label></th><td>';
        echo '<input type="date" id="sb-expires" name="expires" value="'.esc_attr($value).'" min="'.esc_attr(wp_date('Y-m-d', time() + DAY_IN_SECONDS)).'">';
        echo '<p class="description">'.esc_html__('The last day it can be used. Leave empty for no expiry.', 'wp-woocommerce-store-balance').'</p>';
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
            esc_html__('Problems are recorded in the %s.', 'wp-woocommerce-store-balance'),
            '<a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=logs&source='.Logger::SOURCE)).'">'.esc_html__('WooCommerce log', 'wp-woocommerce-store-balance').'</a>'
        ).'</p>';
        submit_button();
        echo '</form>';
    }

    /**
     * The most that can be put on one card by hand. A slipped key should not
     * be able to create a card worth a hundred million.
     */
    public static function maxAmount(): float
    {
        /**
         * Filters the largest amount an admin can put on a card in one go.
         */
        return (float) apply_filters('wc_store_balance_max_manual_amount', 10000);
    }

    /**
     * The amount typed into one of the "add" forms, or a redirect back with
     * the reason it was refused.
     */
    protected function postedAmount(string $view): float
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $amount = Money::parse(Input::text(wp_unslash($_POST['amount'] ?? '')));

        if ($amount === null) {
            $this->redirect(['view' => $view], __('Enter the amount as a number greater than zero, for example 25 or 24,90.', 'wp-woocommerce-store-balance'), 'error');
        }

        if ($amount > self::maxAmount()) {
            $this->redirect(['view' => $view], sprintf(
                /* translators: %s: amount */
                __('The amount is too large. At most %s can be added at a time.', 'wp-woocommerce-store-balance'),
                Money::plain(self::maxAmount(), $this->postedCurrency())
            ), 'error');
        }

        return $amount;
    }

    public function handleAddCredit(): void
    {
        $this->authorize('wc_store_balance_add_credit');

        $amount = $this->postedAmount('add-credit');
        $customerId = absint(Input::text($_POST['customer_id'] ?? ''));
        $customer = $customerId ? get_userdata($customerId) : null;

        if (! $customer) {
            $this->redirect(['view' => 'add-credit'], __('Choose a customer.', 'wp-woocommerce-store-balance'), 'error');
        }

        $expires = $this->postedExpiry('add-credit');
        $sendEmail = ! empty($_POST['send_email']);

        $card = StoreCredit::issue($customerId, $amount, $this->postedCurrency(), [
            'note' => sanitize_text_field(Input::text(wp_unslash($_POST['note'] ?? ''))),
            'expires_at' => $expires,
            'send_email' => false,
        ]);

        if (is_wp_error($card)) {
            $this->redirect(['view' => 'add-credit'], $card->get_error_message(), 'error');
        }

        $sent = false;

        if ($sendEmail) {
            $emails = Plugin::getInstance()->module(Emails::class);
            $sent = $emails ? $emails->send($card) : false;
        }

        $name = trim($customer->first_name.' '.$customer->last_name) ?: $customer->display_name;
        $message = sprintf(
            /* translators: 1: amount, 2: customer name */
            __('%1$s store credit added for %2$s.', 'wp-woocommerce-store-balance'),
            Money::plain($card->balance, $card->currency),
            $name
        );

        if ($sent) {
            /* translators: %s: email address */
            $message .= ' '.sprintf(__('Email sent to %s.', 'wp-woocommerce-store-balance'), $customer->user_email);
        } elseif ($sendEmail) {
            $message .= ' '.__('The email could not be sent; the problem has been logged.', 'wp-woocommerce-store-balance');
        } else {
            $message .= ' '.__('No email was sent.', 'wp-woocommerce-store-balance');
        }

        $this->redirect(['view' => 'card', 'id' => $card->id], $message);
    }

    public function handleAddGiftCard(): void
    {
        $this->authorize('wc_store_balance_add_gift_card');

        $amount = $this->postedAmount('add-gift-card');
        $rawRecipient = trim(Input::text(wp_unslash($_POST['recipient_email'] ?? '')));
        $recipient = sanitize_email($rawRecipient);

        if ($rawRecipient !== '' && (! is_email($recipient) || strlen($recipient) > 200)) {
            $this->redirect(['view' => 'add-gift-card'], __('Enter a valid email address for the recipient.', 'wp-woocommerce-store-balance'), 'error');
        }

        $expires = $this->postedExpiry('add-gift-card');

        try {
            $card = Plugin::getInstance()->cards()->create([
                'type' => Card::TYPE_GIFT_CARD,
                'amount' => $amount,
                'currency' => $this->postedCurrency(),
                'recipient_email' => $recipient,
                'sender_name' => sanitize_text_field(Input::text(wp_unslash($_POST['sender_name'] ?? ''))),
                'message' => sanitize_textarea_field(Input::text(wp_unslash($_POST['message'] ?? ''))),
                'expires_at' => $expires,
                'note' => __('Created in the admin', 'wp-woocommerce-store-balance'),
            ]);
        } catch (Throwable $e) {
            Logger::exception($e, 'Creating a gift card by hand');
            $this->redirect(['view' => 'add-gift-card'], __('The gift card could not be created. The error has been logged.', 'wp-woocommerce-store-balance'), 'error');
        }

        $back = ['view' => 'card', 'id' => $card->id];

        if ($recipient === '') {
            $this->redirect($back, __('Gift card created. Nothing was emailed: copy the code below and hand it over.', 'wp-woocommerce-store-balance'));
        }

        $emails = Plugin::getInstance()->module(Emails::class);

        if ($emails && $emails->send($card)) {
            $this->redirect($back, sprintf(
                /* translators: %s: email address */
                __('Gift card created and emailed to %s.', 'wp-woocommerce-store-balance'),
                $recipient
            ));
        }

        $this->redirect($back, __('Gift card created, but the email could not be sent; the problem has been logged. Use "Send the email now", or hand over the code below.', 'wp-woocommerce-store-balance'), 'error');
    }

    public function handleCardAction(): void
    {
        $this->authorize('wc_store_balance_card_action');

        $cards = Plugin::getInstance()->cards();
        $card = $cards->find(absint(Input::text($_POST['id'] ?? '')));

        if (! $card) {
            $this->redirect([], __('That card does not exist.', 'wp-woocommerce-store-balance'), 'error');
        }

        $back = ['view' => 'card', 'id' => $card->id];

        switch (sanitize_key(Input::text($_POST['do'] ?? ''))) {
            case 'disable':
                $cards->setStatus($card->id, Card::STATUS_DISABLED, __('Deactivated in the admin', 'wp-woocommerce-store-balance'));
                $this->redirect($back, __('Card deactivated. It can no longer be spent.', 'wp-woocommerce-store-balance'));
                // no break
            case 'enable':
                $cards->setStatus($card->id, Card::STATUS_ACTIVE, __('Activated in the admin', 'wp-woocommerce-store-balance'));
                $this->redirect($back, __('Card activated. It can be spent again.', 'wp-woocommerce-store-balance'));
                // no break
            case 'adjust':
                $balance = Money::parseAllowZero(Input::text(wp_unslash($_POST['balance'] ?? '')));
                $note = sanitize_text_field(Input::text(wp_unslash($_POST['note'] ?? '')));
                $limit = max(self::maxAmount(), $card->initialAmount);

                if ($balance === null) {
                    $this->redirect($back, __('Enter the new balance as a number, for example 25 or 24,90.', 'wp-woocommerce-store-balance'), 'error');
                }

                if ($balance > $limit) {
                    $this->redirect($back, sprintf(
                        /* translators: %s: amount */
                        __('The balance is too large. It can be at most %s.', 'wp-woocommerce-store-balance'),
                        Money::plain($limit, $card->currency)
                    ), 'error');
                }

                if ($note === '') {
                    $this->redirect($back, __('Give a reason for the change. It is kept in the card\'s history.', 'wp-woocommerce-store-balance'), 'error');
                }

                if ($balance === Money::round($card->balance)) {
                    $this->redirect($back, __('The balance is already that amount; nothing was changed.', 'wp-woocommerce-store-balance'));
                }

                $cards->adjust($card->id, $balance, $note);
                $this->redirect($back, sprintf(
                    /* translators: 1: old balance, 2: new balance */
                    __('Balance changed from %1$s to %2$s.', 'wp-woocommerce-store-balance'),
                    Money::plain($card->balance, $card->currency),
                    Money::plain($balance, $card->currency)
                ));
                // no break
            case 'resend':
                if (! $card->isUsable() || $card->recipientEmail === '') {
                    $this->redirect($back, __('The email was not sent: this card cannot be spent.', 'wp-woocommerce-store-balance'), 'error');
                }

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
        $currency = strtoupper(sanitize_text_field(Input::text(wp_unslash($_POST['currency'] ?? ''))));
        $allowed = self::currencies();

        return in_array($currency, $allowed, true) ? $currency : ($allowed[0] ?? get_woocommerce_currency());
    }

    /**
     * End of the chosen day in the shop's timezone; null for no expiry.
     */
    protected function postedExpiry(string $view): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $value = sanitize_text_field(Input::text(wp_unslash($_POST['expires'] ?? '')));

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        $time = $date ? $date->setTime(23, 59, 59)->getTimestamp() : null;

        // The date field's own limit is only a hint to the browser.
        if (! $time || $time <= time()) {
            $this->redirect(['view' => $view], __('The expiry date has to be in the future.', 'wp-woocommerce-store-balance'), 'error');
        }

        return $time;
    }

    /**
     * After an error the form is shown again with what was typed: retyping a
     * customer, an amount and a message because of one bad field is how
     * people end up entering the wrong amount the second time.
     *
     * @param  array<string, mixed>  $args
     * @return never
     */
    protected function redirect(array $args, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            $old = [];

            if ($type === 'error') {
                foreach (['customer_id', 'amount', 'currency', 'note', 'expires', 'recipient_email', 'sender_name', 'message'] as $key) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
                    $old[$key] = sanitize_textarea_field(Input::text(wp_unslash($_POST[$key] ?? '')));
                }

                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $old['send_email'] = empty($_POST['send_email']) ? '0' : '1';
            }

            set_transient(self::NOTICE.get_current_user_id(), ['message' => $message, 'type' => $type, 'old' => $old], MINUTE_IN_SECONDS);
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

        $this->old = is_array($notice['old'] ?? null) ? $notice['old'] : [];

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $notice['type'] === 'error' ? 'error' : 'success',
            esc_html($notice['message'])
        );
    }

    protected function hasOld(): bool
    {
        return $this->old !== [];
    }

    protected function old(string $key, string $default = ''): string
    {
        return $this->hasOld() ? (string) ($this->old[$key] ?? '') : $default;
    }
}
