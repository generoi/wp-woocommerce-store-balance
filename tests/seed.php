<?php

/**
 * Demo data for a local site: a gift card product, a customer with a balance,
 * and one unredeemed gift card code to try at checkout.
 *
 *     wp eval-file wp-content/plugins/wp-woocommerce-store-balance/tests/seed.php
 *
 * Safe to run again: it adds what is missing and leaves the rest.
 */

use GeneroWP\StoreBalance\Modules\Account;
use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use GeneroWP\StoreBalance\Modules\Issuance;
use GeneroWP\StoreBalance\Plugin;

if (! defined('ABSPATH') || ! class_exists(Plugin::class)) {
    exit("Run through WP-CLI with the plugin active.\n");
}

$sku = 'STORE-BALANCE-GIFT-CARD';
$productId = wc_get_product_id_by_sku($sku);

if (! $productId) {
    $product = new WC_Product_Simple;
    $product->set_name('E-gift card');
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_virtual(true);
    $product->set_regular_price('25');
    $product->set_short_description('Sent by email. Choose the amount, who it is for and when it should arrive.');
    $product->update_meta_data(GiftCardProduct::META_ENABLED, 'yes');
    $product->update_meta_data(GiftCardProduct::META_AMOUNTS, [25.0, 50.0, 100.0]);
    $product->update_meta_data(GiftCardProduct::META_CUSTOM, 'yes');
    $product->update_meta_data(GiftCardProduct::META_MIN, 10);
    $product->update_meta_data(GiftCardProduct::META_MAX, 500);
    $productId = $product->save();
}

$product = wc_get_product($productId);

$user = get_user_by('login', 'customer');

if (! $user) {
    $userId = wc_create_new_customer('customer@example.com', 'customer', 'customer', ['first_name' => 'Maija', 'last_name' => 'Meikäläinen']);
    $user = get_user_by('id', $userId);
}

$billing = [
    'first_name' => 'Maija', 'last_name' => 'Meikäläinen', 'address_1' => 'Esimerkkikatu 1',
    'city' => 'Helsinki', 'postcode' => '00100', 'country' => 'FI', 'email' => $user->user_email, 'phone' => '0401234567',
];

foreach ($billing as $key => $value) {
    update_user_meta($user->ID, "billing_{$key}", $value);
    if (! in_array($key, ['email', 'phone'], true)) {
        update_user_meta($user->ID, "shipping_{$key}", $value);
    }
}

if (get_option('wc_store_balance_seeded')) {
    WP_CLI::success('Already seeded.');

    return;
}

$giftCardOrder = static function (array $buyer, int $customerId, float $amount, string $to, string $from, string $message) use ($product): WC_Order {
    $order = wc_create_order(['customer_id' => $customerId]);
    $itemId = $order->add_product($product, 1, ['subtotal' => $amount, 'total' => $amount]);
    $item = $order->get_item($itemId);
    $item->update_meta_data(Issuance::ITEM_DATA, [
        'amount' => $amount, 'to' => $to, 'from' => $from, 'message' => $message, 'delivery' => '', 'locale' => get_locale(),
    ]);
    $item->save();
    $order->set_address($buyer, 'billing');
    $order->set_payment_method('cheque');
    $order->calculate_totals();
    $order->update_status('completed', 'Seeded.');

    return $order;
};

// Maija buys a gift card for a friend: an unredeemed code to try at checkout.
$giftCardOrder($billing, $user->ID, 50, 'friend@example.com', 'Maija', 'Hyvää syntymäpäivää!');

// Pekka buys one for Maija, and she adds it to her account.
$giftCardOrder(
    ['first_name' => 'Pekka', 'last_name' => 'Puupää', 'email' => 'pekka@example.com', 'country' => 'FI'],
    0, 100, $user->user_email, 'Pekka', 'Uusille kengille!'
);

$cards = Plugin::getInstance()->cards();

foreach ($cards->query(['limit' => 50]) as $card) {
    if ($card->recipientEmail === $user->user_email && ! $card->isRedeemed()) {
        Plugin::getInstance()->module(Account::class)->redeem($card->code, $user->ID);
    }
}

// Maija buys something, sends it back and takes store credit.
$boots = null;

foreach (wc_get_products(['status' => 'publish', 'type' => 'simple', 'limit' => 20]) as $candidate) {
    if (! GiftCardProduct::isGiftCard($candidate) && (float) $candidate->get_price() > 0) {
        $boots = $candidate;
        break;
    }
}

if ($boots) {
    $order = wc_create_order(['customer_id' => $user->ID]);
    $order->add_product($boots, 1);
    $order->set_address($billing, 'billing');
    $order->set_address($billing, 'shipping');
    $order->set_payment_method('cheque');
    $order->calculate_totals();
    $order->update_status('completed', 'Seeded.');

    wc_store_balance_refund_order_to_store_credit($order, (float) $order->get_total(), 'Returned');
}

update_option('wc_store_balance_seeded', time());

foreach ($cards->query(['limit' => 50]) as $card) {
    WP_CLI::log(sprintf('%-13s %s  %-24s %8.2f %s  customer=%d', $card->type, $card->formattedCode(), $card->recipientEmail, $card->balance, $card->currency, $card->customerId));
}

WP_CLI::success('Seeded.');
