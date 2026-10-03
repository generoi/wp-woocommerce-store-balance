<?php
/**
 * My Account: gift cards, and store credit.
 *
 * Override by copying to yourtheme/woocommerce/store-balance/myaccount/balance.php.
 *
 * @var string $type "giftcard" or "store_credit"
 * @var array<string, float> $balances currency => spendable balance
 * @var Card[] $active
 * @var Card[] $past
 * @var object[] $transactions
 * @var array<int, Card> $cards_by_id
 * @var string $prefill
 * @var string $redeem_url
 * @var string $shop_url
 */

use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Modules\Account;

defined('ABSPATH') || exit;

$is_gift_cards = $type === 'giftcard';

$transaction_labels = [
    CardRepository::TX_ISSUE => $is_gift_cards ? __('Gift card received', 'wp-woocommerce-store-balance') : __('Credit added', 'wp-woocommerce-store-balance'),
    CardRepository::TX_REDEEM => __('Added to your account', 'wp-woocommerce-store-balance'),
    CardRepository::TX_DEBIT => __('Used on an order', 'wp-woocommerce-store-balance'),
    CardRepository::TX_RELEASE => __('Returned from an order', 'wp-woocommerce-store-balance'),
    CardRepository::TX_REFUND => __('Refunded', 'wp-woocommerce-store-balance'),
    CardRepository::TX_ADJUST => __('Adjusted by the shop', 'wp-woocommerce-store-balance'),
    CardRepository::TX_DISABLE => __('Deactivated', 'wp-woocommerce-store-balance'),
    CardRepository::TX_ENABLE => __('Reactivated', 'wp-woocommerce-store-balance'),
];
?>

<div class="store-balance store-balance--<?php echo esc_attr($type); ?>">

    <section class="store-balance__summary" aria-labelledby="store-balance-summary-title">
        <h2 id="store-balance-summary-title" class="store-balance__label">
            <?php echo $is_gift_cards ? esc_html__('Gift card balance', 'wp-woocommerce-store-balance') : esc_html__('Store credit balance', 'wp-woocommerce-store-balance'); ?>
        </h2>

        <?php if ($balances) { ?>
            <p class="store-balance__amount">
                <?php
                echo wp_kses_post(implode(' <span class="store-balance__plus">+</span> ', array_map(
                    static fn ($amount, $currency) => wc_price($amount, ['currency' => $currency]),
                    $balances,
                    array_keys($balances)
                )));
            ?>
            </p>
            <p class="store-balance__hint">
                <?php esc_html_e('Used automatically at checkout when you are logged in. You can switch it off there if you would rather save it.', 'wp-woocommerce-store-balance'); ?>
                <?php if (count($balances) > 1) { ?>
                    <?php esc_html_e('Each balance can be used for orders in its own currency.', 'wp-woocommerce-store-balance'); ?>
                <?php } ?>
            </p>
            <p><a class="button wp-element-button" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Start shopping', 'wp-woocommerce-store-balance'); ?></a></p>
        <?php } else { ?>
            <p class="store-balance__amount"><?php echo wp_kses_post(wc_price(0)); ?></p>
            <p class="store-balance__hint">
                <?php
            echo $is_gift_cards
                ? esc_html__('You have no gift cards in your account yet. Got a code? Add it below and it will be used at checkout automatically.', 'wp-woocommerce-store-balance')
                : esc_html__('You have no store credit. If you return an order you can choose to get store credit, and it will show up here.', 'wp-woocommerce-store-balance');
            ?>
            </p>
        <?php } ?>
    </section>

    <?php if ($is_gift_cards) { ?>
        <section class="store-balance__redeem" aria-labelledby="store-balance-redeem-title">
            <h2 id="store-balance-redeem-title"><?php esc_html_e('Add a gift card', 'wp-woocommerce-store-balance'); ?></h2>
            <form method="post" action="<?php echo esc_url($redeem_url); ?>" class="store-balance__form">
                <?php wp_nonce_field(Account::NONCE); ?>
                <p class="form-row">
                    <label for="store_balance_redeem_code"><?php esc_html_e('Gift card code', 'wp-woocommerce-store-balance'); ?></label>
                    <input
                        type="text"
                        class="input-text"
                        id="store_balance_redeem_code"
                        name="store_balance_redeem_code"
                        value="<?php echo esc_attr($prefill); ?>"
                        placeholder="XXXX-XXXX-XXXX-XXXX"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        maxlength="24"
                        required
                        aria-describedby="store-balance-redeem-hint"
                    >
                    <span id="store-balance-redeem-hint" class="store-balance__hint">
                        <?php esc_html_e('The 16-character code from your gift card email. Once added, the gift card belongs to this account and you no longer need the code.', 'wp-woocommerce-store-balance'); ?>
                    </span>
                </p>
                <p>
                    <button type="submit" class="button wp-element-button"><?php esc_html_e('Add to my account', 'wp-woocommerce-store-balance'); ?></button>
                </p>
            </form>
        </section>
    <?php } ?>

    <?php if ($active) { ?>
        <section aria-labelledby="store-balance-cards-title">
            <h2 id="store-balance-cards-title">
                <?php echo $is_gift_cards ? esc_html__('Your gift cards', 'wp-woocommerce-store-balance') : esc_html__('Your store credit', 'wp-woocommerce-store-balance'); ?>
            </h2>
            <table class="woocommerce-table shop_table shop_table_responsive store-balance__table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo $is_gift_cards ? esc_html__('Gift card', 'wp-woocommerce-store-balance') : esc_html__('Added', 'wp-woocommerce-store-balance'); ?></th>
                        <th scope="col"><?php esc_html_e('Balance', 'wp-woocommerce-store-balance'); ?></th>
                        <th scope="col"><?php esc_html_e('Valid until', 'wp-woocommerce-store-balance'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active as $card) { ?>
                        <tr>
                            <td data-title="<?php echo $is_gift_cards ? esc_attr__('Gift card', 'wp-woocommerce-store-balance') : esc_attr__('Added', 'wp-woocommerce-store-balance'); ?>">
                                <?php if ($is_gift_cards) { ?>
                                    <span class="store-balance__code"><?php echo esc_html($card->maskedCode()); ?></span>
                                    <?php if ($card->senderName !== '') { ?>
                                        <br><small>
                                            <?php
                                        /* translators: %s: sender name */
                                        printf(esc_html__('From %s', 'wp-woocommerce-store-balance'), esc_html($card->senderName));
                                        ?>
                                        </small>
                                    <?php } ?>
                                <?php } else { ?>
                                    <?php echo esc_html(wp_date(wc_date_format(), $card->createdAt)); ?>
                                <?php } ?>
                            </td>
                            <td data-title="<?php esc_attr_e('Balance', 'wp-woocommerce-store-balance'); ?>">
                                <?php echo wp_kses_post(wc_price($card->balance, ['currency' => $card->currency])); ?>
                                <?php if ($card->balance < $card->initialAmount) { ?>
                                    <br><small>
                                        <?php
                                    /* translators: %s: amount */
                                    printf(esc_html__('of %s', 'wp-woocommerce-store-balance'), wp_kses_post(wc_price($card->initialAmount, ['currency' => $card->currency])));
                                    ?>
                                    </small>
                                <?php } ?>
                            </td>
                            <td data-title="<?php esc_attr_e('Valid until', 'wp-woocommerce-store-balance'); ?>">
                                <?php echo $card->expiresAt ? esc_html(wp_date(wc_date_format(), $card->expiresAt)) : esc_html__('No expiry', 'wp-woocommerce-store-balance'); ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </section>
    <?php } ?>

    <?php if ($transactions) { ?>
        <section aria-labelledby="store-balance-history-title">
            <h2 id="store-balance-history-title"><?php esc_html_e('History', 'wp-woocommerce-store-balance'); ?></h2>
            <table class="woocommerce-table shop_table shop_table_responsive store-balance__table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Date', 'wp-woocommerce-store-balance'); ?></th>
                        <th scope="col"><?php esc_html_e('What happened', 'wp-woocommerce-store-balance'); ?></th>
                        <th scope="col"><?php esc_html_e('Amount', 'wp-woocommerce-store-balance'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction) { ?>
                        <?php
                        $card = $cards_by_id[(int) $transaction->card_id] ?? null;
                        $currency = $card ? $card->currency : get_woocommerce_currency();
                        $amount = (float) $transaction->amount;
                        $order = (int) $transaction->order_id ? wc_get_order((int) $transaction->order_id) : null;
                        $own_order = $order && (int) $order->get_customer_id() === get_current_user_id();
                        ?>
                        <tr>
                            <td data-title="<?php esc_attr_e('Date', 'wp-woocommerce-store-balance'); ?>">
                                <?php echo esc_html(wp_date(wc_date_format(), strtotime($transaction->created_at.' UTC'))); ?>
                            </td>
                            <td data-title="<?php esc_attr_e('What happened', 'wp-woocommerce-store-balance'); ?>">
                                <?php echo esc_html($transaction_labels[$transaction->type] ?? $transaction->type); ?>
                                <?php if ($own_order) { ?>
                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                        <?php
                                        /* translators: %s: order number */
                                        printf(esc_html__('(order #%s)', 'wp-woocommerce-store-balance'), esc_html($order->get_order_number()));
                                    ?>
                                    </a>
                                <?php } ?>
                            </td>
                            <td data-title="<?php esc_attr_e('Amount', 'wp-woocommerce-store-balance'); ?>">
                                <?php
                                if ($amount == 0.0) {
                                    echo '&ndash;';
                                } else {
                                    echo ($amount > 0 ? '+' : '&minus;').wp_kses_post(wc_price(abs($amount), ['currency' => $currency]));
                                }
                        ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </section>
    <?php } ?>

    <?php if ($past) { ?>
        <details class="store-balance__past">
            <summary>
                <?php
                /* translators: %d: number of cards */
                printf(esc_html(_n('%d used or expired', '%d used or expired', count($past), 'wp-woocommerce-store-balance')), count($past));
        ?>
            </summary>
            <ul>
                <?php foreach ($past as $card) { ?>
                    <li>
                        <?php
                echo wp_kses_post(wc_price($card->initialAmount, ['currency' => $card->currency]));
                    echo ' &ndash; ';

                    if (! $card->isActive()) {
                        esc_html_e('deactivated', 'wp-woocommerce-store-balance');
                    } elseif ($card->balance <= 0) {
                        esc_html_e('fully used', 'wp-woocommerce-store-balance');
                    } else {
                        /* translators: %s: date */
                        printf(esc_html__('expired on %s', 'wp-woocommerce-store-balance'), esc_html(wp_date(wc_date_format(), $card->expiresAt)));
                    }
                    ?>
                    </li>
                <?php } ?>
            </ul>
        </details>
    <?php } ?>
</div>
