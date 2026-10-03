<?php

use GeneroWP\StoreBalance\Card;

/**
 * Store credit email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/store-balance/emails/store-credit.php.
 *
 * @var Card $card
 * @var string $amount
 * @var string $expires
 * @var string $shop_url
 * @var string $account_url
 * @var string $email_heading
 * @var string $additional_content
 * @var WC_Email $email
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p>
    <?php
    printf(
        /* translators: %s: amount */
        esc_html__('%s in store credit has been added to your account.', 'wp-woocommerce-store-balance'),
        '<strong>'.wp_kses_post($amount).'</strong>'
    );
?>
</p>

<p>
    <?php esc_html_e('There is no code to remember. Log in when you shop and the credit is used at checkout automatically. If you do not spend it all at once, the rest stays in your account.', 'wp-woocommerce-store-balance'); ?>
</p>

<?php if ($expires !== '') { ?>
    <p>
        <?php
    printf(
        /* translators: %s: date */
        esc_html__('The credit is valid until %s.', 'wp-woocommerce-store-balance'),
        '<strong>'.esc_html($expires).'</strong>'
    );
    ?>
    </p>
<?php } ?>

<p>
    <a href="<?php echo esc_url($account_url); ?>"><?php esc_html_e('See your store credit', 'wp-woocommerce-store-balance'); ?></a>
    &nbsp;·&nbsp;
    <a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Start shopping', 'wp-woocommerce-store-balance'); ?></a>
</p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
