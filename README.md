# wp-woocommerce-store-balance

Gift cards and store credit for WooCommerce, on one balance engine.

A gift card and a store credit are the same thing with a different origin: a balance in one currency that is spent like money. A gift card is bought and carries a code that can be passed on. Store credit is put on a customer's account, by a refund or by an admin, and has no code.

## What it does

- **Gift cards** sold as a product: preset amounts, an optional custom amount, a recipient, a message and a delivery date. The card is emailed to the recipient.
- **Store credit** on a customer account: added by an admin, or given instead of money back when an order is refunded.
- **Spent at checkout like a payment.** The balance is taken off the finished total, after tax, shipping and coupons, so the VAT on the order does not change. Partial use leaves the rest on the card.
- **An account balance.** A gift card can be added to an account once and is then used at checkout automatically, with no code to type. Store credit always works that way.
- **A ledger.** Every change to a balance is a transaction row: issued, used, returned, refunded, adjusted.
- **Currency lock.** A balance can only be spent on an order in its own currency.
- Cart and checkout **blocks** (Store API) and the classic shortcode checkout. HPOS compatible.

## Why after tax

A gift card that can be spent on anything is a multi-purpose voucher: no VAT is due when it is sold, and the full VAT is due on the goods when it is spent. Store credit from a return is money the shop owes the customer. Neither is a discount.

A coupon lowers the price, and with it the VAT. Paying with a balance must not. That is why this is not built on coupons, and why a gift card product is forced to tax status "none" whatever its settings say.

A free, promotional credit is a different thing: it is a price reduction, and an ordinary WooCommerce coupon is the right tool for it.

## Requirements

- PHP 8.0 or later
- WordPress 6.6 or later
- WooCommerce (developed and tested against 11.1)

## Installation

```sh
composer require generoi/wp-woocommerce-store-balance
```

Activate the plugin. The two tables are created on activation, or on the first request if the plugin was activated by a database import.

## Using it

### Selling gift cards

Edit a simple product and tick **Gift card** next to "Virtual" and "Downloadable". Enter the amounts to offer, and switch on **Custom amount** to let the customer type their own. The product's price fields are hidden: the price of a gift card is the amount chosen.

The card is created when the order is paid and emailed to the recipient, right away or on the date the buyer chose. If the order is later cancelled or refunded the card is deactivated.

### Store credit

**WooCommerce → Store balance → Add store credit** puts credit on a customer account. The customer gets an email with the amount and no code.

On an order's edit screen, the **Store credit** box refunds the order, or part of it, to the customer as store credit. Nothing is sent to the payment provider. A guest order gets a customer account to hold the credit.

### For the customer

My Account gets two pages, **Gift cards** and **Store credit**. Each shows the balance, the cards and their history. Gift cards also has a form to add a code to the account.

At checkout, a logged-in customer with a balance sees "Use my balance", ticked. A gift card code that has not been added to an account is entered under "Add a gift card".

When the balance is larger than the order, the card that expires soonest is used first, then the oldest.

## Several currencies

Each card has a currency: the currency of the order that bought or refunded it, or the one chosen when it was created by hand. It is only offered, and only accepted, when the cart is in that currency. The plugin reads `get_woocommerce_currency()` and nothing else, so it works with whatever sets the currency.

There is no conversion between currencies.

The amounts on a gift card product are plain numbers. A shop with several currencies returns the right set for each:

```php
add_filter('wc_store_balance_gift_card_amounts', function (array $amounts, WC_Product $product, string $currency) {
    return $currency === 'SEK' ? [250, 500, 1000] : $amounts;
}, 10, 3);

// The currencies offered when creating a card in the admin.
add_filter('wc_store_balance_currencies', fn () => ['EUR', 'SEK', 'DKK']);
```

## API

```php
// Put store credit on an account.
$card = wc_store_balance_issue_store_credit($customerId, 25.00, 'EUR', [
    'note' => 'Late delivery',
    'order_id' => $order->get_id(),
]);

// Refund an order to store credit, in the order's currency.
$card = wc_store_balance_refund_order_to_store_credit($order, 49.90, 'Returned');

// What a customer can spend.
$balance = wc_store_balance_get_customer_balance($customerId, 'EUR');
```

The first two return the card, or a `WP_Error`.

### Filters

| Filter | Purpose |
| --- | --- |
| `wc_store_balance_modules` | The modules that are registered. Drop `Blocks` or `ClassicCheckout` to draw your own checkout UI. |
| `wc_store_balance_gift_card_amounts` | Preset amounts of a gift card product, per currency. |
| `wc_store_balance_gift_card_custom_amount` | Custom amount limits of a gift card product, per currency. |
| `wc_store_balance_currencies` | Currencies offered when creating a card in the admin. |
| `wc_store_balance_expiry_days` | Days a new card is valid for. `0` for no expiry. |
| `wc_store_balance_delivery_time` | When a scheduled gift card is sent. |
| `wc_store_balance_email_locale` | The locale a card email is written in. |
| `wc_store_balance_cart_state` | The computed balance state of the cart. |

### Actions

| Action | When |
| --- | --- |
| `wc_store_balance_card_created` | A gift card or store credit has been created. |
| `wc_store_balance_order_refunded_to_store_credit` | An order has been refunded to store credit. |

### Templates

Copy a file from `templates/` to `yourtheme/woocommerce/store-balance/` to override it.

## The email language

A gift card carries the locale the buyer was shopping in, and store credit the customer's own. The email is written in that locale. A multilingual setup maps it to the language the recipient should get with `wc_store_balance_email_locale`.

## The log

Everything that goes wrong is written to the WooCommerce log, under the source `wp-woocommerce-store-balance`: **WooCommerce → Status → Logs**. That includes a checkout stopped because a balance had changed, a card that could not be issued, an email that was not sent, and any exception caught while drawing the cart.

## How an order is paid

1. The cart total is calculated as usual. The balance is taken off the result.
2. When the customer places the order, the cards are debited, before payment. The gateway is only asked for what is left. If a card can no longer cover its share the checkout stops and nothing stays debited.
3. If the order is cancelled, fails or is refunded in full, what it took is returned to the cards it came from.

What an order paid from a balance is kept in order meta (`_store_balance_lines`), and shown as a row in the order totals, in emails and on the order screen.

## Development

```sh
composer install
composer lint        # Pint
composer stan        # PHPStan
composer test        # unit tests, no WordPress needed
```

`tests/seed.php` creates a gift card product, a customer with a balance and an unredeemed code on a local site:

```sh
wp eval-file wp-content/plugins/wp-woocommerce-store-balance/tests/seed.php
```

The scripts in `assets/` are written against the globals WooCommerce exposes and are not compiled. There is no build step.

## Not in this version

- Conversion between currencies.
- Partial refund of a gift card line: the card is only deactivated when the whole order is cancelled or refunded.
- Import of balances from another system.
- Expiry reminders.
