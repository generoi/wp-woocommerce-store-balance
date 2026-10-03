<?php
/**
 * Gift card code and account balance, on the classic cart and checkout.
 *
 * Override by copying to yourtheme/woocommerce/store-balance/checkout/balance-form.php.
 *
 * @var array<string, mixed> $state
 */
defined('ABSPATH') || exit;

$account = $state['account'];
?>

<div class="store-balance-checkout" data-store-balance-checkout>
    <?php if ($state['only_gift_cards']) { ?>
        <p class="store-balance__hint"><?php esc_html_e('Gift cards and store credit cannot be used to buy gift cards.', 'wp-woocommerce-store-balance'); ?></p>
    <?php } else { ?>
        <div class="store-balance-checkout__row">
            <label class="screen-reader-text" for="store-balance-code"><?php esc_html_e('Gift card code', 'wp-woocommerce-store-balance'); ?></label>
            <input
                type="text"
                class="input-text"
                id="store-balance-code"
                placeholder="<?php esc_attr_e('Gift card code', 'wp-woocommerce-store-balance'); ?>"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                maxlength="24"
                data-store-balance-code
            >
            <button type="button" class="button wp-element-button" data-store-balance-apply>
                <?php esc_html_e('Apply gift card', 'wp-woocommerce-store-balance'); ?>
            </button>
        </div>
    <?php } ?>

    <p class="store-balance-checkout__message" role="alert" data-store-balance-message hidden></p>

    <?php if ($state['codes']) { ?>
        <ul class="store-balance-checkout__applied">
            <?php foreach ($state['codes'] as $line) { ?>
                <li>
                    <span>
                        <?php
                        /* translators: %s: masked gift card code */
                        printf(esc_html__('Gift card %s', 'wp-woocommerce-store-balance'), esc_html($line['masked']));

                if ($line['reason']) {
                    echo '<br><small>'.esc_html($line['reason']).'</small>';
                } elseif ($line['amount'] > 0 && $line['amount'] < $line['available']) {
                    echo '<br><small>';
                    /* translators: %s: amount */
                    printf(esc_html__('%s left on the card after this order', 'wp-woocommerce-store-balance'), wp_kses_post(wc_price($line['available'] - $line['amount'])));
                    echo '</small>';
                }
                ?>
                    </span>
                    <button type="button" class="store-balance-checkout__remove" data-store-balance-remove="<?php echo esc_attr((string) $line['card_id']); ?>">
                        <?php esc_html_e('Remove', 'wp-woocommerce-store-balance'); ?>
                    </button>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>

    <?php if ($account['available'] > 0 && ! $state['only_gift_cards']) { ?>
        <label class="store-balance-checkout__balance">
            <input type="checkbox" data-store-balance-use <?php checked($state['use_balance']); ?>>
            <span>
                <?php
                /* translators: %s: amount */
                printf(esc_html__('Use my balance (%s available)', 'wp-woocommerce-store-balance'), wp_kses_post(wc_price($account['available'])));
        ?>
            </span>
        </label>
    <?php } ?>
</div>
