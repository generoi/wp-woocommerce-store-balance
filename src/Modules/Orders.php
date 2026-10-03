<?php

namespace GeneroWP\StoreBalance\Modules;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Exception;
use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\CardRepository;
use GeneroWP\StoreBalance\Lock;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use WC_Order;

/**
 * Carries the balance from the cart onto the order, takes the money from the
 * cards, and gives it back when the order does not go through.
 *
 * The order total is what is left to pay through the gateway. What was paid
 * from a balance is kept beside it as lines in order meta:
 *
 *     [card_id, type, masked, amount, restored]
 *
 * `restored` is what went back to the card it came from. What the order still
 * holds is amount - restored.
 */
class Orders implements Module
{
    public const META_LINES = '_store_balance_lines';

    public const META_PENDING = '_store_balance_pending';

    public const META_STATE = '_store_balance_state';

    public const STATE_DEBITED = 'debited';

    public const STATE_RELEASED = 'released';

    /** Statuses in which the order does not hold the customer's money. */
    public const RELEASE_STATUSES = ['cancelled', 'failed', 'refunded'];

    public function register(): void
    {
        // Classic checkout.
        add_action('woocommerce_checkout_create_order', [$this, 'stage'], 20);
        add_action('woocommerce_checkout_order_processed', [$this, 'processClassic'], 20, 3);

        // Checkout block.
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'stageDraft'], 20);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'process'], 20);

        add_action('woocommerce_order_status_changed', [$this, 'statusChanged'], 20, 4);

        // An order can leave without ever being cancelled: trashed or deleted
        // while it still holds a balance. HPOS and the posts table announce
        // that through different hooks.
        add_action('woocommerce_trash_order', [$this, 'removed']);
        add_action('woocommerce_before_delete_order', [$this, 'removed']);
        add_action('wp_trash_post', [$this, 'removed']);
        add_action('before_delete_post', [$this, 'removed']);
        add_action('woocommerce_untrash_order', [$this, 'restored']);
        add_action('untrashed_post', [$this, 'restored']);
        add_action('woocommerce_order_after_calculate_totals', [$this, 'afterCalculateTotals'], 20, 2);
        add_filter('woocommerce_get_order_item_totals', [$this, 'totalsRow'], 20, 2);
    }

    /**
     * Write down what the cart applied, without touching any card yet.
     *
     * Kept apart from the debited lines on purpose: when a failed payment is
     * retried the same order comes through here again, and the lines that were
     * already debited have to survive until process() has released them.
     */
    public function stage(WC_Order $order): void
    {
        $cart = Plugin::getInstance()->module(Cart::class);
        $lines = $cart ? $cart->state()['lines'] : [];

        if ($lines) {
            $order->update_meta_data(self::META_PENDING, array_map(static fn (array $line) => [
                'card_id' => (int) $line['card_id'],
                'type' => (string) $line['type'],
                'masked' => (string) $line['masked'],
                'amount' => Money::round($line['amount']),
                'restored' => 0.0,
            ], $lines));
        } else {
            $order->update_meta_data(self::META_PENDING, []);
        }
    }

    /**
     * The classic checkout saves the order after its hook; the Store API does
     * not promise to, so the draft is saved here.
     */
    public function stageDraft(WC_Order $order): void
    {
        $this->stage($order);

        // The Store API has just recalculated the draft from its items, which
        // knows nothing about the balance. The cart total does; without this
        // an order the balance covers in full would still demand a payment
        // method.
        if (function_exists('WC') && WC()->cart) {
            $order->set_total(wc_format_decimal(max(0, Money::round((float) WC()->cart->get_total('edit')))));
        }

        $order->save();
    }

    /**
     * @param  array<string, mixed>  $posted
     *
     * @throws Exception
     */
    public function processClassic(int $orderId, array $posted, WC_Order $order): void
    {
        $this->process($order);
    }

    /**
     * Take the money. Runs when the customer places the order, before payment.
     *
     * Before rather than after: the gateway is only asked for the remainder,
     * so the balance has to be committed first or two orders could both count
     * on it. It is given back if the order is cancelled or the payment fails.
     *
     * @throws Exception when a card can no longer cover its share. The checkout
     *                   stops and nothing stays debited.
     */
    public function process(WC_Order $order): void
    {
        // What this request staged, before anything is re-read.
        $pending = $order->get_meta(self::META_PENDING);
        $pending = is_array($pending) ? $pending : [];

        $this->releaseAbandoned($order);

        Lock::order($order->get_id(), function () use ($order, $pending): void {
            $this->debitOrder($order, $pending);
        });
    }

    /** Session key: orders this session has paid from a balance. */
    public const SESSION_ORDERS = 'store_balance_orders';

    /**
     * The customer placed an order, did not pay, changed the cart and is
     * placing another. WooCommerce starts a new order for the new cart; the
     * old one would go on holding the balance until it is cancelled an hour
     * later, and this checkout would be refused for a balance that is only
     * tied up in the customer's own abandoned attempt.
     *
     * The old order is cancelled, not just emptied. If its payment arrives
     * after all — the customer was at their bank in another tab — WooCommerce
     * reopens it, and reopening re-debits the balance or, if this order has
     * spent it by then, puts the old one on hold. Either way it cannot ship
     * on money that was used twice.
     */
    protected function releaseAbandoned(WC_Order $current): void
    {
        if (! function_exists('WC') || ! WC()->session) {
            return;
        }

        $remembered = WC()->session->get(self::SESSION_ORDERS, []);

        $ids = array_unique(array_filter(array_map('absint', array_merge(
            is_array($remembered) ? $remembered : [],
            [WC()->session->get('order_awaiting_payment'), WC()->session->get('store_api_draft_order')]
        ))));

        foreach ($ids as $id) {
            if ($id === $current->get_id()) {
                continue;
            }

            $abandoned = wc_get_order($id);

            if (! $abandoned instanceof WC_Order
                || ! $abandoned->has_status(['pending', 'failed'])
                || ! self::lines($abandoned)
                || $abandoned->get_meta(self::META_STATE) !== self::STATE_DEBITED
            ) {
                continue;
            }

            // The status change returns the balance, under the order's lock.
            $abandoned->update_status('cancelled', __('The customer placed a new order instead of paying this one.', 'wp-woocommerce-store-balance'));
        }

        WC()->session->set(self::SESSION_ORDERS, []);
    }

    /**
     * Remember an order this session has paid from a balance, so that it can
     * be found again if it is abandoned. WooCommerce's own session keys point
     * at the newest order only.
     */
    protected function remember(WC_Order $order): void
    {
        if (! function_exists('WC') || ! WC()->session) {
            return;
        }

        $ids = WC()->session->get(self::SESSION_ORDERS, []);
        $ids = is_array($ids) ? array_map('absint', $ids) : [];
        $ids[] = $order->get_id();

        WC()->session->set(self::SESSION_ORDERS, array_values(array_unique($ids)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pending
     *
     * @throws Exception
     */
    protected function debitOrder(WC_Order $order, array $pending): void
    {
        // Under the lock: what another request for the same order — a double
        // click on "Place order" — has written in the meantime.
        $order->read_meta_data(true);

        // A retry of the same order: start from a clean slate.
        if (self::lines($order) && $order->get_meta(self::META_STATE) === self::STATE_DEBITED) {
            $this->release($order, __('Checkout retried', 'wp-woocommerce-store-balance'));
        }

        if (! $pending) {
            $order->delete_meta_data(self::META_PENDING);

            if (self::lines($order)) {
                $order->delete_meta_data(self::META_LINES);
                $order->delete_meta_data(self::META_STATE);
            }

            $order->save();

            return;
        }

        $cards = Plugin::getInstance()->cards();
        $done = [];

        foreach ($pending as $line) {
            $card = $cards->find((int) $line['card_id']);

            // A card that sits in an account is only spent by that account —
            // also when it was claimed between the cart and this request.
            $owned = $card && (! $card->isRedeemed() || $card->customerId === (int) $order->get_customer_id());

            if ($owned && $cards->debit((int) $line['card_id'], (float) $line['amount'], ['order_id' => $order->get_id()])) {
                $done[] = $line;

                continue;
            }

            foreach ($done as $undo) {
                $cards->credit((int) $undo['card_id'], (float) $undo['amount'], CardRepository::TX_RELEASE, [
                    'order_id' => $order->get_id(),
                    'note' => 'Checkout aborted',
                ]);
            }

            Logger::warning('Checkout stopped: a card could not cover its share', [
                'order_id' => $order->get_id(),
                'card_id' => (int) $line['card_id'],
                'amount' => (float) $line['amount'],
            ]);

            // Nothing is paid from a balance any more, so the order is worth
            // its full price again. Left with the reduced total it could be
            // paid through its "pay for order" link for less than the goods.
            $order->delete_meta_data(self::META_LINES);
            $order->delete_meta_data(self::META_STATE);
            $order->delete_meta_data(self::META_PENDING);
            $order->calculate_totals();
            $order->save();

            // The cart still holds the stale figures; make it look again.
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->calculate_totals();
            }

            $message = __('Your gift card or store credit balance has changed. Please review the order total and try again.', 'wp-woocommerce-store-balance');

            if (class_exists(RouteException::class) && did_action('woocommerce_store_api_checkout_update_order_meta')) {
                throw new RouteException('wc_store_balance_changed', $message, 409);
            }

            throw new Exception($message);
        }

        $order->update_meta_data(self::META_LINES, $pending);
        $order->update_meta_data(self::META_STATE, self::STATE_DEBITED);
        $order->delete_meta_data(self::META_PENDING);
        $order->save();

        $this->remember($order);

        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: list of masked card codes */
            __('%1$s paid with gift card / store credit (%2$s).', 'wp-woocommerce-store-balance'),
            Money::plain(self::applied($order), $order->get_currency()),
            implode(', ', array_column($pending, 'masked'))
        ));
    }

    public function statusChanged(int $orderId, string $from, string $to, $order): void
    {
        if (! $order instanceof WC_Order || ! self::lines($order)) {
            return;
        }

        Lock::order($order->get_id(), function () use ($order, $from, $to): void {
            // Two requests can both be moving this order to "cancelled". Only
            // the state as it is in the database, read under the lock, says
            // whether the balance has already gone back.
            $order->read_meta_data(true);
            $state = $order->get_meta(self::META_STATE);

            // HPOS trashes and restores an order as a change of status.
            if (in_array($to, self::RELEASE_STATUSES, true) || $to === 'trash') {
                if ($state === self::STATE_DEBITED) {
                    $this->release($order, sprintf(
                        /* translators: %s: order status */
                        __('Order %s', 'wp-woocommerce-store-balance'),
                        wc_get_order_status_name($to)
                    ), $to === 'refunded' ? CardRepository::TX_REFUND : CardRepository::TX_RELEASE);
                }

                return;
            }

            // Brought back to life — a failed payment that went through on the
            // second attempt outside the checkout, a payment that arrived for
            // a cancelled order, or an admin reopening it. An order that is
            // live again has to be paid again.
            if ((in_array($from, self::RELEASE_STATUSES, true) || $from === 'trash') && $state === self::STATE_RELEASED) {
                $this->redebit($order);
            }
        });
    }

    /**
     * The order is being trashed or deleted. What it holds goes back.
     *
     * @param  int|mixed  $orderId
     */
    public function removed($orderId): void
    {
        $order = $this->orderFor($orderId);

        if (! $order || ! self::lines($order)) {
            return;
        }

        Lock::order($order->get_id(), function () use ($order): void {
            $order->read_meta_data(true);

            if ($order->get_meta(self::META_STATE) === self::STATE_DEBITED) {
                $this->release($order, __('Order removed', 'wp-woocommerce-store-balance'));
            }
        });
    }

    /**
     * Back from the trash, in whatever status it had before.
     *
     * @param  int|mixed  $orderId
     */
    public function restored($orderId): void
    {
        $order = $this->orderFor($orderId);

        if (! $order || ! self::lines($order) || $order->has_status(array_merge(self::RELEASE_STATUSES, ['trash']))) {
            return;
        }

        Lock::order($order->get_id(), function () use ($order): void {
            $order->read_meta_data(true);

            if ($order->get_meta(self::META_STATE) === self::STATE_RELEASED) {
                $this->redebit($order);
            }
        });
    }

    /**
     * The post hooks fire for every post type; only orders are of interest.
     *
     * @param  int|mixed  $orderId
     */
    protected function orderFor($orderId): ?WC_Order
    {
        if (! is_numeric($orderId)) {
            return null;
        }

        if (doing_action('wp_trash_post') || doing_action('before_delete_post') || doing_action('untrashed_post')) {
            if (! in_array(get_post_type((int) $orderId), ['shop_order', 'shop_order_placehold'], true)) {
                return null;
            }
        }

        $order = wc_get_order((int) $orderId);

        return $order instanceof WC_Order && $order->get_type() === 'shop_order' ? $order : null;
    }

    /**
     * Give back to each card what the order still holds from it.
     */
    protected function release(WC_Order $order, string $note, string $type = CardRepository::TX_RELEASE): void
    {
        $cards = Plugin::getInstance()->cards();
        $lines = self::lines($order);
        $total = 0.0;
        $returned = [];

        foreach ($lines as &$line) {
            $held = Money::round($line['amount'] - $line['restored']);

            if ($held <= 0) {
                continue;
            }

            if ($cards->credit((int) $line['card_id'], $held, $type, ['order_id' => $order->get_id(), 'note' => $note])) {
                $line['restored'] = Money::round($line['restored'] + $held);
                $total += $held;
                $returned[] = self::label([$line]).' '.$line['masked'];
            } else {
                Logger::error('Could not return a balance to its card', [
                    'order_id' => $order->get_id(),
                    'card_id' => (int) $line['card_id'],
                    'amount' => $held,
                ]);
            }
        }
        unset($line);

        $order->update_meta_data(self::META_LINES, $lines);
        $order->update_meta_data(self::META_STATE, self::STATE_RELEASED);
        $order->save();

        if ($total > 0) {
            $order->add_order_note(sprintf(
                /* translators: 1: amount, 2: the cards it went back to, e.g. "Gift card ••••-AB12, Store credit #75" */
                __('%1$s returned to %2$s.', 'wp-woocommerce-store-balance'),
                Money::plain($total, $order->get_currency()),
                implode(', ', array_unique($returned))
            ));
        }
    }

    /**
     * Take back what release() returned. If a card has been spent elsewhere in
     * the meantime the order is short, and that is for a person to resolve.
     */
    protected function redebit(WC_Order $order): void
    {
        $cards = Plugin::getInstance()->cards();
        $lines = self::lines($order);
        $short = 0.0;

        foreach ($lines as &$line) {
            $amount = Money::round($line['restored']);

            if ($amount <= 0) {
                continue;
            }

            if ($cards->debit((int) $line['card_id'], $amount, ['order_id' => $order->get_id(), 'note' => 'Order reopened'])) {
                $line['restored'] = 0.0;
            } else {
                $short += $amount;
            }
        }
        unset($line);

        $order->update_meta_data(self::META_LINES, $lines);
        $order->update_meta_data(self::META_STATE, self::STATE_DEBITED);
        $order->save();

        if ($short > 0) {
            Logger::error('Order reopened but the balance is no longer there', ['order_id' => $order->get_id(), 'short' => $short]);

            // On hold, so that it is not packed and shipped on money that is
            // not there. A person decides what happens next.
            $order->update_status('on-hold', sprintf(
                /* translators: %s: amount */
                __('This order was reopened, but %s of the gift card / store credit it was paid with has been spent elsewhere. That amount is unpaid: collect it or cancel the order.', 'wp-woocommerce-store-balance'),
                Money::plain($short, $order->get_currency())
            ));
        }
    }

    /**
     * WooCommerce recalculates an order's total from its items — the
     * "Recalculate" button, or any code calling calculate_totals(). That total
     * knows nothing about the balance, so take it off again.
     */
    public function afterCalculateTotals($andTaxes, $order): void
    {
        if (! $order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            return;
        }

        // While the order is still a draft of the checkout the balance is only
        // staged; once placed, it is in the debited lines. A staged amount on
        // any other order is a leftover and must not lower its total.
        $pending = $order->get_meta(self::META_PENDING);
        $applied = is_array($pending) && $pending && $order->has_status('checkout-draft')
            ? Money::round(array_sum(array_column($pending, 'amount')))
            : self::applied($order);

        if ($applied > 0) {
            $order->set_total(wc_format_decimal(max(0, Money::round((float) $order->get_total() - $applied))));
        }
    }

    /**
     * The row in the totals table on the thank-you page, in My Account and in
     * every order email.
     *
     * @param  mixed  $rows
     * @param  mixed  $order
     * @return mixed
     */
    public function totalsRow($rows, $order)
    {
        if (! is_array($rows) || ! $order instanceof WC_Order) {
            return $rows;
        }

        $applied = self::applied($order);

        if ($applied <= 0) {
            return $rows;
        }

        // One row per kind, as in the cart: a gift card and store credit used
        // together are shown as the two payments they were.
        $row = [];

        foreach (self::byType(self::lines($order)) as $type => $amount) {
            $row['store_balance_'.$type] = [
                'label' => self::label([['type' => $type]]).':',
                'value' => '-'.wc_price($amount, ['currency' => $order->get_currency()]),
            ];
        }

        $position = array_search('order_total', array_keys($rows), true);

        if ($position === false) {
            return $rows + $row;
        }

        return array_slice($rows, 0, $position, true) + $row + array_slice($rows, $position, null, true);
    }

    /**
     * @return array<int, array{card_id: int, type: string, masked: string, amount: float, restored: float}>
     */
    public static function lines(WC_Order $order): array
    {
        $lines = $order->get_meta(self::META_LINES);

        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_map(static fn ($line) => [
            'card_id' => (int) ($line['card_id'] ?? 0),
            'type' => (string) ($line['type'] ?? Card::TYPE_GIFT_CARD),
            'masked' => (string) ($line['masked'] ?? ''),
            'amount' => (float) ($line['amount'] ?? 0),
            'restored' => (float) ($line['restored'] ?? 0),
        ], array_filter($lines, 'is_array')));
    }

    /**
     * What was paid from a balance when the order was placed.
     */
    public static function applied(WC_Order $order): float
    {
        return Money::round(array_sum(array_column(self::lines($order), 'amount')));
    }

    /**
     * What the order still holds, per card.
     *
     * @return array<int, float>
     */
    public static function heldLines(WC_Order $order): array
    {
        if ($order->get_meta(self::META_STATE) !== self::STATE_DEBITED) {
            return [];
        }

        $held = [];

        foreach (self::lines($order) as $line) {
            $amount = Money::round($line['amount'] - $line['restored']);

            if ($amount > 0) {
                $held[$line['card_id']] = Money::round(($held[$line['card_id']] ?? 0) + $amount);
            }
        }

        return $held;
    }

    public static function held(WC_Order $order): float
    {
        return Money::round(array_sum(self::heldLines($order)));
    }

    /**
     * What was paid per kind of balance, gift cards first.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, float> type => amount
     */
    public static function byType(array $lines): array
    {
        $totals = [];

        foreach (Card::types() as $type) {
            $amount = 0.0;

            foreach ($lines as $line) {
                if (($line['type'] ?? '') === $type) {
                    $amount += (float) ($line['amount'] ?? 0);
                }
            }

            if ($amount > 0) {
                $totals[$type] = Money::round($amount);
            }
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function label(array $lines): string
    {
        $types = array_unique(array_column($lines, 'type'));

        if ($types === [Card::TYPE_STORE_CREDIT]) {
            return __('Store credit', 'wp-woocommerce-store-balance');
        }

        if ($types === [Card::TYPE_GIFT_CARD]) {
            return __('Gift card', 'wp-woocommerce-store-balance');
        }

        return __('Gift card & store credit', 'wp-woocommerce-store-balance');
    }
}
