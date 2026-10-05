# wp-woocommerce-store-balance

Gift cards and store credit for WooCommerce, on one balance engine.

A gift card and a store credit are the same thing with a different origin: a balance in one currency that is spent like money. A gift card is bought and carries a code that can be passed on. Store credit is put on a customer's account by the shop, and has no code.

## What it does

- **Gift cards** sold as a product: preset amounts, an optional custom amount, a recipient, a message and a delivery date. The card is emailed to the recipient.
- **Store credit** on a customer account, added by an admin.
- **Spent at checkout like a payment.** The balance is taken off the finished total, after tax, shipping and coupons, so the VAT on the order does not change. Partial use leaves the rest on the card.
- **An account balance.** A gift card can be added to an account once and is then used at checkout automatically, with no code to type. Store credit always works that way.
- **A ledger.** Every change to a balance is a transaction row: issued, used, returned, refunded, adjusted.
- **Currency lock.** A balance can only be spent on an order in its own currency.
- Cart and checkout **blocks** (Store API) and the classic shortcode checkout. HPOS compatible.

## Why after tax

A gift card that can be spent on anything is a multi-purpose voucher: no VAT is due when it is sold, and the full VAT is due on the goods when it is spent. Store credit is money the shop owes the customer. Neither is a discount.

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

### Themes that add to the cart without a page load

A script that adds to the cart through the Store API usually sends a product id and a quantity and nothing else, so the gift card form is posted to the page the ordinary way by default.

To add gift cards the same way as other products, send the form's `store_balance_*` fields in the body of the `cart/add-item` request, and tell the plugin the theme does so:

```php
add_filter('wc_store_balance_ajax_add_to_cart', '__return_true');
```

```js
// Every named field of the gift card form, next to id and quantity.
{ id: 123, quantity: 1, store_balance_amount: '50', store_balance_to: 'friend@example.com' }
```

The fields are checked exactly as the form post is; a refusal comes back as a 400 with the reason.

A gift card product without an image shows the plugin's own gift card picture. It is added to the media library the first time an admin page is loaded; set a product image to replace it, or delete it from the media library to do without.

In the cart, the mini cart and the checkout the gift card's details are listed one per line with their labels (To, From, Message, Delivery).

### For the customer

My Account gets two pages, **Gift cards** and **Store credit**. Each shows the balance, the cards and their history. Gift cards also has a form to add a code to the account.

At checkout, a logged-in customer with a balance sees "Use my balance", ticked. A gift card code that has not been added to an account is entered under "Add a gift card".

When the balance is larger than the order, the card that expires soonest is used first, then the oldest.

## Several currencies

Each card has a currency: the currency of the order that bought it, or the one chosen when it was created by hand. It is only offered, and only accepted, when the cart is in that currency. The plugin reads `get_woocommerce_currency()` and nothing else, so it works with whatever sets the currency.

There is no conversion between currencies.

With a currency switcher that converts product prices as they are read, the amount of a gift card line is left alone: the customer chose it in the currency they are shopping in. Tested with WOOCS (FOX) in four currencies.

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

// What a customer can spend.
$balance = wc_store_balance_get_customer_balance($customerId, 'EUR');
```

The first returns the card, or a `WP_Error`.

### Filters

| Filter | Purpose |
| --- | --- |
| `wc_store_balance_modules` | The modules that are registered. Drop `Blocks` or `ClassicCheckout` to draw your own checkout UI. |
| `wc_store_balance_gift_card_amounts` | Preset amounts of a gift card product, per currency. |
| `wc_store_balance_gift_card_custom_amount` | Custom amount limits of a gift card product, per currency. |
| `wc_store_balance_currencies` | Currencies offered when creating a card in the admin. |
| `wc_store_balance_currency_url` | URL of the current page in the storefront that sells in a given currency. When set, a customer holding a balance in another currency gets a link to where it can be spent. |
| `wc_store_balance_expiry_days` | Days a new card is valid for. `0` for no expiry. |
| `wc_store_balance_delivery_time` | When a scheduled gift card is sent. |
| `wc_store_balance_max_manual_amount` | The most an admin can put on a card in one go. |
| `wc_store_balance_returned_balance_grace_days` | How long a card stays valid, at least, after a balance has been returned to it. |
| `wc_store_balance_email_locale` | The locale a card email is written in. |
| `wc_store_balance_cart_state` | The computed balance state of the cart. |
| `wc_store_balance_ajax_add_to_cart` | Return `true` when the theme adds gift cards to the cart itself through the Store API and sends the gift card fields. |
| `wc_store_balance_fallback_image_id` | The attachment shown for a gift card product without an image. Return 0 for none. |
| `wc_store_balance_client_ip` | The address code attempts are counted against. Set it if your proxy passes client-supplied `X-Forwarded-For` through. |

### Actions

| Action | When |
| --- | --- |
| `wc_store_balance_card_created` | A gift card or store credit has been created. |

### Templates

Copy a file from `templates/` to `yourtheme/woocommerce/store-balance/` to override it.

## The email language

A gift card carries the locale the buyer was shopping in, and store credit the customer's own. The email is written in that locale. A multilingual setup maps it to the language the recipient should get with `wc_store_balance_email_locale`.

## The log

Everything that goes wrong is written to the WooCommerce log, under the source `wp-woocommerce-store-balance`: **WooCommerce → Status → Logs**. That includes a checkout stopped because a balance had changed, a card that could not be issued, an email that was not sent, and any exception caught while drawing the cart.

## How an order is paid

1. The cart total is calculated as usual. The balance is taken off the result.
2. When the customer places the order, the cards are debited, before payment. The gateway is only asked for what is left. If a card can no longer cover its share the checkout stops and nothing stays debited.
3. If the order is cancelled, fails, is refunded in full, or is trashed or deleted, what it took is returned to the cards it came from. A card that has expired in the meantime stays valid long enough to spend what came back.

An order that is placed but never paid holds its share of the balance until WooCommerce cancels it (the "hold stock" time), or until the same customer places another order.

Everything that changes an order's balance runs under a per-order database lock, so a payment webhook and the customer's return arriving together cannot return a balance twice or issue a gift card twice.

What an order paid from a balance is kept in order meta (`_store_balance_lines`), and shown as a row in the order totals, in emails and on the order screen.

## Development

```sh
composer install
composer lint        # Pint
composer stan        # PHPStan
composer test        # unit tests, no WordPress needed
```

The integration suite boots WordPress and WooCommerce. `tests/bootstrap.php` finds WooCommerce in the plugins directory next to this one, so it runs in any local site against a separate test database:

```sh
WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php composer test:integration
```

`tests/seed.php` creates a gift card product, a customer with a balance and an unredeemed code on a local site:

```sh
wp eval-file wp-content/plugins/wp-woocommerce-store-balance/tests/seed.php
```

The scripts in `assets/` are written against the globals WooCommerce exposes and are not compiled. There is no build step.

## Removing the plugin

Deactivating keeps everything. While the plugin is inactive, gift card products are ordinary products sold at their lowest amount, and scheduled gift card emails are not sent, so unpublish gift card products first.

Deleting the plugin keeps the two tables: they hold money the shop owes its customers. They are dropped only when the site sets `WC_REMOVE_ALL_DATA`, the same switch WooCommerce uses.

## Not in this version

- Returns. Refunding an order as store credit is left to whatever handles returns; it can call `wc_store_balance_issue_store_credit()`.
- Conversion between currencies.
- Partial refund of a gift card line: the card is only deactivated when the whole order is cancelled or refunded.
- Import of balances from another system.
- Expiry reminders.
