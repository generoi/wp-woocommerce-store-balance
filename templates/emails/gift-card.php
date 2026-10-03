<?php

use GeneroWP\StoreBalance\Card;

/**
 * Gift card email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/store-balance/emails/gift-card.php.
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
    if ($card->senderName !== '') {
        printf(
            /* translators: 1: sender name, 2: amount */
            esc_html__('%1$s has sent you a gift card worth %2$s.', 'wp-woocommerce-store-balance'),
            '<strong>'.esc_html($card->senderName).'</strong>',
            '<strong>'.wp_kses_post($amount).'</strong>'
        );
    } else {
        printf(
            /* translators: %s: amount */
            esc_html__('You have received a gift card worth %s.', 'wp-woocommerce-store-balance'),
            '<strong>'.wp_kses_post($amount).'</strong>'
        );
    }
?>
</p>

<?php if ($card->message !== '') { ?>
    <blockquote style="margin: 0 0 24px; padding: 12px 16px; border-left: 4px solid #dddddd; font-style: italic;">
        <?php echo nl2br(esc_html($card->message)); ?>
    </blockquote>
<?php } ?>

<table cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px;">
    <tr>
        <td align="center" style="padding: 24px; border: 2px dashed #cccccc; border-radius: 8px;">
            <div style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px;">
                <?php esc_html_e('Your gift card code', 'wp-woocommerce-store-balance'); ?>
            </div>
            <div style="font-size: 24px; font-weight: bold; letter-spacing: 2px; font-family: monospace; padding: 8px 0;">
                <?php echo esc_html($card->formattedCode()); ?>
            </div>
            <div style="font-size: 20px;"><?php echo wp_kses_post($amount); ?></div>
        </td>
    </tr>
</table>

<p><strong><?php esc_html_e('How to use it', 'wp-woocommerce-store-balance'); ?></strong></p>
<ol>
    <li><?php esc_html_e('Add what you like to the cart.', 'wp-woocommerce-store-balance'); ?></li>
    <li><?php esc_html_e('Enter the code at checkout. It covers the order up to its value.', 'wp-woocommerce-store-balance'); ?></li>
    <li><?php esc_html_e('Anything left on the card stays there for next time.', 'wp-woocommerce-store-balance'); ?></li>
</ol>

<p>
    <a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Start shopping', 'wp-woocommerce-store-balance'); ?></a>
    &nbsp;·&nbsp;
    <a href="<?php echo esc_url($account_url); ?>"><?php esc_html_e('Save it to your account', 'wp-woocommerce-store-balance'); ?></a>
</p>

<?php if ($expires !== '') { ?>
    <p>
        <?php
    printf(
        /* translators: %s: date */
        esc_html__('The gift card is valid until %s.', 'wp-woocommerce-store-balance'),
        esc_html($expires)
    );
    ?>
    </p>
<?php } ?>

<p><small><?php esc_html_e('Keep this email: the code is like cash, and whoever has it can spend it.', 'wp-woocommerce-store-balance'); ?></small></p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
