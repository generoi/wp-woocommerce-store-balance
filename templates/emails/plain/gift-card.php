<?php

use GeneroWP\StoreBalance\Card;

/**
 * Gift card email (plain text).
 *
 * @var Card $card
 * @var string $amount
 * @var string $expires
 * @var string $shop_url
 * @var string $account_url
 * @var string $email_heading
 * @var string $additional_content
 */
defined('ABSPATH') || exit;

$plain_amount = wp_strip_all_tags(html_entity_decode($amount));

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html(wp_strip_all_tags($email_heading));
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ($card->senderName !== '') {
    /* translators: 1: sender name, 2: amount */
    echo esc_html(sprintf(__('%1$s has sent you a gift card worth %2$s.', 'wp-woocommerce-store-balance'), $card->senderName, $plain_amount))."\n\n";
} else {
    /* translators: %s: amount */
    echo esc_html(sprintf(__('You have received a gift card worth %s.', 'wp-woocommerce-store-balance'), $plain_amount))."\n\n";
}

if ($card->message !== '') {
    echo '"'.esc_html($card->message)."\"\n\n";
}

echo esc_html__('Your gift card code', 'wp-woocommerce-store-balance').': '.esc_html($card->formattedCode())."\n\n";
echo esc_html__('Enter the code at checkout. It covers the order up to its value, and anything left stays on the card for next time.', 'wp-woocommerce-store-balance')."\n\n";
echo esc_html__('Start shopping', 'wp-woocommerce-store-balance').': '.esc_url_raw($shop_url)."\n";
echo esc_html__('Save it to your account', 'wp-woocommerce-store-balance').': '.esc_url_raw($account_url)."\n\n";

if ($expires !== '') {
    /* translators: %s: date */
    echo esc_html(sprintf(__('The gift card is valid until %s.', 'wp-woocommerce-store-balance'), $expires))."\n\n";
}

echo esc_html__('Keep this email: the code is like cash, and whoever has it can spend it.', 'wp-woocommerce-store-balance')."\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}

echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
