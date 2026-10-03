<?php

use GeneroWP\StoreBalance\Money;

/**
 * The gift card options on the product page.
 *
 * Override by copying to yourtheme/woocommerce/store-balance/product/gift-card-form.php.
 *
 * @var WC_Product $product
 * @var float[] $amounts
 * @var array{enabled: bool, min: float, max: float} $custom
 * @var array<string, string> $values
 * @var array<string, string> $errors keyed by field: amount, custom_amount, to, message, delivery
 * @var string $notices the result of the last "Add to cart", already rendered
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
$plain_price = static fn ($amount): string => Money::plain($amount);
$custom_range = sprintf(
    /* translators: 1: smallest amount, 2: largest amount */
    __('Between %1$s and %2$s.', 'wp-woocommerce-store-balance'),
    $plain_price($custom['min']),
    $plain_price($custom['max'])
);
$range_message = sprintf(
    /* translators: 1: smallest amount, 2: largest amount */
    __('Choose an amount between %1$s and %2$s.', 'wp-woocommerce-store-balance'),
    $plain_price($custom['min']),
    $plain_price($custom['max'])
);

/**
 * The attributes that tie a field to its hint and, when it has one, its error.
 */
$describe = static function (string $field, string $hint) use ($errors): string {
    $ids = isset($errors[$field]) ? "store-balance-{$field}-error {$hint}" : $hint;

    return sprintf(
        'aria-describedby="%s"%s',
        esc_attr($ids),
        isset($errors[$field]) ? ' aria-invalid="true"' : ''
    );
};

$error = static function (string $field) use ($errors): void {
    if (isset($errors[$field])) {
        printf(
            '<span id="store-balance-%s-error" class="store-balance__error">%s</span>',
            esc_attr($field),
            esc_html($errors[$field])
        );
    }
};
?>

<div class="store-balance-gift-card" data-store-balance-gift-card>

    <?php if ($notices !== '') { ?>
        <div class="store-balance-gift-card__notices" data-store-balance-notices tabindex="-1">
            <?php echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by wc_print_notices().?>
        </div>
    <?php } ?>

    <fieldset class="store-balance-gift-card__amounts">
        <legend><?php esc_html_e('Amount', 'wp-woocommerce-store-balance'); ?></legend>

        <div class="store-balance-gift-card__choices">
            <?php foreach ($amounts as $amount) { ?>
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
                    <input type="radio" name="store_balance_amount" value="custom" <?php checked($selected, 'custom'); ?>>
                    <span class="store-balance-gift-card__choice-label"><?php esc_html_e('Other amount', 'wp-woocommerce-store-balance'); ?></span>
                </label>
            <?php } elseif ($custom['enabled']) { ?>
                <input type="hidden" name="store_balance_amount" value="custom">
            <?php } ?>
        </div>

        <?php $error('amount'); ?>

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
                    <?php echo $describe('custom_amount', 'store-balance-custom-hint'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
                    data-min="<?php echo esc_attr((string) $custom['min']); ?>"
                    data-max="<?php echo esc_attr((string) $custom['max']); ?>"
                    data-range-message="<?php echo esc_attr($range_message); ?>"
                    data-number-message="<?php esc_attr_e('Enter the amount as a number, for example 50 or 49,90.', 'wp-woocommerce-store-balance'); ?>"
                >
                <?php $error('custom_amount'); ?>
                <span id="store-balance-custom-hint" class="store-balance__hint" <?php echo isset($errors['custom_amount']) ? 'hidden' : ''; ?>><?php echo esc_html($custom_range); ?></span>
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
                maxlength="200"
                autocomplete="off"
                <?php echo $describe('to', 'store-balance-to-hint'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
            >
            <?php $error('to'); ?>
            <span id="store-balance-to-hint" class="store-balance__hint">
                <?php esc_html_e('We email the gift card straight to them. Leave empty and we email it to you instead, to print or forward.', 'wp-woocommerce-store-balance'); ?>
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
                autocomplete="name"
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
                <?php echo $describe('message', 'store-balance-message-count'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
                data-store-balance-count
            ><?php echo esc_textarea($values['message']); ?></textarea>
            <?php $error('message'); ?>
            <span
                id="store-balance-message-count"
                class="store-balance__hint"
                aria-live="polite"
                data-store-balance-counter
                data-template="<?php
                    /* translators: 1: characters typed, 2: maximum characters */
                    echo esc_attr__('%1$s / %2$s characters', 'wp-woocommerce-store-balance');
?>"
                data-empty="<?php
    /* translators: %d: number of characters */
    echo esc_attr(sprintf(__('Up to %d characters.', 'wp-woocommerce-store-balance'), (int) $message_length));
?>"
            >
                <?php
/* translators: %d: number of characters */
printf(esc_html__('Up to %d characters.', 'wp-woocommerce-store-balance'), (int) $message_length);
?>
            </span>
        </p>

        <p class="form-row">
            <label for="store_balance_delivery">
                <?php esc_html_e('Delivery date', 'wp-woocommerce-store-balance'); ?>
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
                <?php echo $describe('delivery', 'store-balance-delivery-hint'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
            >
            <?php $error('delivery'); ?>
            <span id="store-balance-delivery-hint" class="store-balance__hint">
                <?php esc_html_e('The day the gift card is emailed. Leave empty to send it right after payment.', 'wp-woocommerce-store-balance'); ?>
            </span>
        </p>
    </fieldset>
</div>
