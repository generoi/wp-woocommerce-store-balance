<?php
/**
 * The gift card options on the product page.
 *
 * Override by copying to yourtheme/woocommerce/store-balance/product/gift-card-form.php.
 *
 * @var WC_Product $product
 * @var float[] $amounts
 * @var array{enabled: bool, min: float, max: float} $custom
 * @var array<string, string> $values
 * @var int $message_length
 * @var string $min_date
 * @var string $max_date
 */
defined('ABSPATH') || exit;

$selected = $values['amount'];

// Nothing chosen yet: start on the first amount, so the form is valid as it stands.
if ($selected === '') {
    $selected = $amounts ? (string) $amounts[0] : 'custom';
}

$symbol = get_woocommerce_currency_symbol();
$custom_range = sprintf(
    /* translators: 1: smallest amount, 2: largest amount */
    __('Between %1$s and %2$s.', 'wp-woocommerce-store-balance'),
    wp_strip_all_tags(wc_price($custom['min'])),
    wp_strip_all_tags(wc_price($custom['max']))
);
?>

<div class="store-balance-gift-card" data-store-balance-gift-card>

    <fieldset class="store-balance-gift-card__amounts">
        <legend><?php esc_html_e('Amount', 'wp-woocommerce-store-balance'); ?></legend>

        <div class="store-balance-gift-card__choices">
            <?php foreach ($amounts as $index => $amount) { ?>
                <label class="store-balance-gift-card__choice">
                    <input
                        type="radio"
                        name="store_balance_amount"
                        value="<?php echo esc_attr((string) $amount); ?>"
                        <?php checked((float) $selected === (float) $amount && $selected !== 'custom'); ?>
                    >
                    <span class="store-balance-gift-card__choice-label"><?php echo wp_kses_post(wc_price($amount, ['decimals' => floor($amount) == $amount ? 0 : wc_get_price_decimals()])); ?></span>
                </label>
            <?php } ?>

            <?php if ($custom['enabled'] && $amounts) { ?>
                <label class="store-balance-gift-card__choice">
                    <input type="radio" name="store_balance_amount" value="custom" <?php checked($selected, 'custom'); ?> data-store-balance-custom-toggle>
                    <span class="store-balance-gift-card__choice-label"><?php esc_html_e('Other amount', 'wp-woocommerce-store-balance'); ?></span>
                </label>
            <?php } elseif ($custom['enabled']) { ?>
                <input type="hidden" name="store_balance_amount" value="custom">
            <?php } ?>
        </div>

        <?php if ($custom['enabled']) { ?>
            <p class="form-row store-balance-gift-card__custom" data-store-balance-custom <?php echo $amounts && $selected !== 'custom' ? 'hidden' : ''; ?>>
                <label for="store_balance_custom_amount">
                    <?php
                    /* translators: %s: currency symbol */
                    printf(esc_html__('Your amount (%s)', 'wp-woocommerce-store-balance'), esc_html($symbol));
            ?>
                </label>
                <input
                    type="text"
                    inputmode="decimal"
                    class="input-text"
                    id="store_balance_custom_amount"
                    name="store_balance_custom_amount"
                    value="<?php echo esc_attr($values['custom_amount']); ?>"
                    autocomplete="off"
                    aria-describedby="store-balance-custom-hint"
                    data-min="<?php echo esc_attr((string) $custom['min']); ?>"
                    data-max="<?php echo esc_attr((string) $custom['max']); ?>"
                >
                <span id="store-balance-custom-hint" class="store-balance__hint"><?php echo esc_html($custom_range); ?></span>
            </p>
        <?php } ?>
    </fieldset>

    <fieldset class="store-balance-gift-card__details">
        <legend><?php esc_html_e('Who is it for?', 'wp-woocommerce-store-balance'); ?></legend>

        <p class="form-row">
            <label for="store_balance_to">
                <?php esc_html_e('Recipient\'s email', 'wp-woocommerce-store-balance'); ?>
                <span class="store-balance__optional"><?php esc_html_e('(optional)', 'wp-woocommerce-store-balance'); ?></span>
            </label>
            <input
                type="email"
                class="input-text"
                id="store_balance_to"
                name="store_balance_to"
                value="<?php echo esc_attr($values['to']); ?>"
                autocomplete="off"
                aria-describedby="store-balance-to-hint"
            >
            <span id="store-balance-to-hint" class="store-balance__hint">
                <?php esc_html_e('We email the gift card straight to them. Leave it empty to get it yourself and pass it on.', 'wp-woocommerce-store-balance'); ?>
            </span>
        </p>

        <p class="form-row">
            <label for="store_balance_from">
                <?php esc_html_e('Your name', 'wp-woocommerce-store-balance'); ?>
                <span class="store-balance__optional"><?php esc_html_e('(optional)', 'wp-woocommerce-store-balance'); ?></span>
            </label>
            <input
                type="text"
                class="input-text"
                id="store_balance_from"
                name="store_balance_from"
                value="<?php echo esc_attr($values['from']); ?>"
                maxlength="100"
                autocomplete="given-name"
                aria-describedby="store-balance-from-hint"
            >
            <span id="store-balance-from-hint" class="store-balance__hint">
                <?php esc_html_e('So they know who it is from.', 'wp-woocommerce-store-balance'); ?>
            </span>
        </p>

        <p class="form-row">
            <label for="store_balance_message">
                <?php esc_html_e('Message', 'wp-woocommerce-store-balance'); ?>
                <span class="store-balance__optional"><?php esc_html_e('(optional)', 'wp-woocommerce-store-balance'); ?></span>
            </label>
            <textarea
                class="input-text"
                id="store_balance_message"
                name="store_balance_message"
                rows="3"
                maxlength="<?php echo esc_attr((string) $message_length); ?>"
                aria-describedby="store-balance-message-count"
                data-store-balance-count
            ><?php echo esc_textarea($values['message']); ?></textarea>
            <span id="store-balance-message-count" class="store-balance__hint" data-store-balance-counter data-template="<?php
                /* translators: 1: characters typed, 2: maximum characters */
                echo esc_attr__('%1$s / %2$s characters', 'wp-woocommerce-store-balance');
?>">
                <?php
/* translators: %d: number of characters */
printf(esc_html__('Up to %d characters.', 'wp-woocommerce-store-balance'), (int) $message_length);
?>
            </span>
        </p>

        <p class="form-row">
            <label for="store_balance_delivery">
                <?php esc_html_e('Send on', 'wp-woocommerce-store-balance'); ?>
                <span class="store-balance__optional"><?php esc_html_e('(optional)', 'wp-woocommerce-store-balance'); ?></span>
            </label>
            <input
                type="date"
                class="input-text"
                id="store_balance_delivery"
                name="store_balance_delivery"
                value="<?php echo esc_attr($values['delivery']); ?>"
                min="<?php echo esc_attr($min_date); ?>"
                max="<?php echo esc_attr($max_date); ?>"
                aria-describedby="store-balance-delivery-hint"
            >
            <span id="store-balance-delivery-hint" class="store-balance__hint">
                <?php esc_html_e('Leave it empty to send the gift card as soon as you have paid.', 'wp-woocommerce-store-balance'); ?>
            </span>
        </p>
    </fieldset>
</div>
