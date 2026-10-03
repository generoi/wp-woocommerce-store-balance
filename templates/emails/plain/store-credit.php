<?php

use GeneroWP\StoreBalance\Card;

/**
 * Store credit email (plain text).
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

/* translators: %s: amount */
echo esc_html(sprintf(__('%s in store credit has been added to your account.', 'wp-woocommerce-store-balance'), $plain_amount))."\n\n";
echo esc_html__('There is no code to remember. Log in when you shop and the credit is used at checkout automatically. If you do not spend it all at once, the rest stays in your account.', 'wp-woocommerce-store-balance')."\n\n";

if ($expires !== '') {
    /* translators: %s: date */
    echo esc_html(sprintf(__('The credit is valid until %s.', 'wp-woocommerce-store-balance'), $expires))."\n\n";
}

echo esc_html__('See your store credit', 'wp-woocommerce-store-balance').': '.esc_url_raw($account_url)."\n";
echo esc_html__('Start shopping', 'wp-woocommerce-store-balance').': '.esc_url_raw($shop_url)."\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}

echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
