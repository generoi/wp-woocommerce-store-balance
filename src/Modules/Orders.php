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

    /**
     * Statuses in which the order counts as placed and (to be) paid, so the
     * balance it was placed with has to be on it.
     */
    public const HELD_STATUSES = ['processing', 'completed', 'on-hold'];

    /** What a reopened order could not take back from its cards; unpaid. */
    public const META_SHORT = '_store_balance_short';

    public function register(): void
    {
        // Classic checkout.
        add_action('woocommerce_checkout_create_order', [$this, 'stage'], 20);
        add_action('woocommerce_checkout_order_processed', [$this, 'processClassic'], 20, 3);

        // Checkout block.
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'stageDraft'], 20);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'process'], 20);

        add_action('woocommerce_order_status_changed', [$this, 'statusChanged'], 20, 4);

        // Before a payment is recorded, so that an order which cannot take
        // its balance back never reaches "processing" at all.
        add_action('woocommerce_pre_payment_complete', [$this, 'beforePaymentComplete'], 5);
        add_filter('woocommerce_payment_complete_order_status', [$this, 'paymentCompleteStatus'], PHP_INT_MAX, 3);
        add_action('woocommerce_order_status_processing', [$this, 'shortfallSettled'], 5, 2);
        add_action('woocommerce_order_status_completed', [$this, 'shortfallSettled'], 5, 2);

        // The "pay for order" page.
        add_action('before_woocommerce_pay', [$this, 'beforePayPage'], 5);
        add_action('woocommerce_before_pay_action', [$this, 'beforePayAction'], 5);

        add_filter('woocommerce_order_fully_refunded_status', [$this, 'fullyRefundedStatus'], 20, 2);

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
                'amount' => Money::exact($line['amount']),
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
        //
        // Only when a balance is applied. An order without one keeps the
        // total WooCommerce worked out from its own lines.
        if ($order->get_meta(self::META_PENDING) && function_exists('WC') && WC()->cart) {
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
                    'note' => __('Checkout aborted', 'wp-woocommerce-store-balance'),
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
        if (! $order instanceof WC_Order || (! self::lines($order) && ! $order->get_meta(self::META_PENDING))) {
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
                // A paid order put in the trash is being tidied away, not
                // undone: the goods were delivered and the balance paid for
                // them.
                if ($to === 'trash' && in_array($from, wc_get_is_paid_statuses(), true)) {
                    return;
                }

                if ($state === self::STATE_DEBITED) {
                    $this->release($order, $to === 'trash' ? __('Order removed', 'wp-woocommerce-store-balance') : sprintf(
                        /* translators: %s: order status */
                        __('Order %s', 'wp-woocommerce-store-balance'),
                        strtolower(wc_get_order_status_name($to))
                    ), $to === 'refunded' ? CardRepository::TX_REFUND : CardRepository::TX_RELEASE);
                }

                return;
            }

            // Placed and paid, or about to be — a failed payment that went
            // through on the second attempt outside the checkout, a payment
            // that arrived for a cancelled order, an admin reopening it. The
            // balance has to be on the order again.
            //
            // Not on the way to "pending": that is a checkout starting over,
            // and the checkout takes the balance itself. Taking it here as
            // well, falling short and parking the order on hold would let the
            // checkout finish an order nobody was asked to pay for.
            if (in_array($to, self::HELD_STATUSES, true)) {
                $this->settle($order);
            }
        });
    }

    /**
     * Whether the order counts on a balance that no card has been debited
     * for: returned when the order failed or was cancelled, or staged by a
     * checkout that never got as far as taking it.
     */
    protected static function unsettled(WC_Order $order): float
    {
        if ($order->get_meta(self::META_STATE) === self::STATE_DEBITED) {
            return 0.0;
        }

        $pending = $order->get_meta(self::META_PENDING);

        if (is_array($pending) && $pending) {
            return Money::exact(array_sum(array_column($pending, 'amount')));
        }

        if ($order->get_meta(self::META_STATE) !== self::STATE_RELEASED) {
            return 0.0;
        }

        return Money::exact(array_sum(array_column(self::lines($order), 'restored')));
    }

    /**
     * Take the balance the order counts on, now. Call under the order's lock.
     * What cannot be taken is added back to the order's total and the order
     * is put on hold.
     */
    protected function settle(WC_Order $order, bool $hold = true): void
    {
        if (self::unsettled($order) <= 0) {
            return;
        }

        $pending = $order->get_meta(self::META_PENDING);

        // Staged but never taken: the same as returned in full.
        if (is_array($pending) && $pending) {
            $order->update_meta_data(self::META_LINES, array_map(static fn (array $line) => [
                'card_id' => (int) ($line['card_id'] ?? 0),
                'type' => (string) ($line['type'] ?? Card::TYPE_GIFT_CARD),
                'masked' => (string) ($line['masked'] ?? ''),
                'amount' => Money::exact($line['amount'] ?? 0),
                'restored' => Money::exact($line['amount'] ?? 0),
            ], array_filter($pending, 'is_array')));
            $order->update_meta_data(self::META_STATE, self::STATE_RELEASED);
            $order->delete_meta_data(self::META_PENDING);

            Logger::warning('An order reached a paid status with a balance that was staged but never taken', ['order_id' => $order->get_id()]);
        }

        $this->redebit($order, $hold);
    }

    /**
     * WooCommerce is about to record a payment. Its own copy of the order is
     * the one that gets the new status, so a shortfall is only written down
     * here; paymentCompleteStatus() turns it into "on hold".
     *
     * @param  int|mixed  $orderId
     */
    public function beforePaymentComplete($orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        Lock::order($order->get_id(), function () use ($order): void {
            $order->read_meta_data(true);
            $this->settle($order, false);
        });
    }

    /**
     * An order that is short stays on hold, however many times the gateway
     * says it has been paid: what it was paid is less than it is worth.
     *
     * @param  mixed  $status
     * @param  int|mixed  $orderId
     * @param  mixed  $order
     * @return mixed
     */
    public function paymentCompleteStatus($status, $orderId = 0, $order = null)
    {
        // Read again: the order WooCommerce is holding was loaded before the
        // shortfall was written.
        $fresh = wc_get_order($order instanceof WC_Order ? $order->get_id() : $orderId);

        return $fresh instanceof WC_Order && $fresh->get_meta(self::META_SHORT) !== '' ? 'on-hold' : $status;
    }

    /**
     * An order that is short cannot be moved on by a payment, so reaching
     * "processing" or "completed" means a person moved it: the shortfall is
     * theirs to have settled. Early, so that everything else waiting for the
     * order to be paid — its gift cards — sees it as paid.
     *
     * @param  int|mixed  $orderId
     * @param  mixed  $order
     */
    public function shortfallSettled($orderId, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        $order->read_meta_data(true);

        if ($order->get_meta(self::META_SHORT) !== '') {
            $order->delete_meta_data(self::META_SHORT);
            $order->save();
        }
    }

    /**
     * The "pay for order" page of an order whose balance is not on it — a
     * failed payment, a checkout that broke off. The customer is about to pay
     * through a gateway what the page shows, so the page has to show the full
     * price: the balance went back to their card, or never left it.
     */
    public function beforePayPage(): void
    {
        $order = wc_get_order(absint(get_query_var('order-pay')));

        if ($order instanceof WC_Order) {
            $this->withoutBalance($order);
        }
    }

    /**
     * The same on submit, for a page that was open before the balance went
     * back. The gateway's form was drawn with the old amount, so the customer
     * is sent round to look again.
     *
     * @param  mixed  $order
     */
    public function beforePayAction($order): void
    {
        if (! $order instanceof WC_Order || ! $this->withoutBalance($order)) {
            return;
        }

        wc_add_notice(__('The gift card or store credit on this order is no longer applied, so its total has changed. Please review it and pay again.', 'wp-woocommerce-store-balance'), 'error');

        if (wp_safe_redirect($order->get_checkout_payment_url())) {
            exit;
        }
    }

    /**
     * Make an order that counts on an untaken balance worth its full price.
     * True when that changed the order.
     */
    protected function withoutBalance(WC_Order $order): bool
    {
        if (! $order->has_status(['pending', 'failed']) || self::unsettled($order) <= 0) {
            return false;
        }

        $changed = false;

        Lock::order($order->get_id(), function () use ($order, &$changed): void {
            $order->read_meta_data(true);
            $amount = self::unsettled($order);

            if ($amount <= 0) {
                return;
            }

            // Whatever part of a line is still held stays; only the part that
            // is not on the order any more goes.
            $lines = [];

            foreach ($order->get_meta(self::META_STATE) === self::STATE_RELEASED ? self::lines($order) : [] as $line) {
                $line['amount'] = Money::exact($line['amount'] - $line['restored']);
                $line['restored'] = 0.0;

                if ($line['amount'] > 0) {
                    $lines[] = $line;
                }
            }

            $order->delete_meta_data(self::META_PENDING);

            if ($lines) {
                $order->update_meta_data(self::META_LINES, $lines);
                $order->update_meta_data(self::META_STATE, self::STATE_DEBITED);
            } else {
                $order->delete_meta_data(self::META_LINES);
                $order->delete_meta_data(self::META_STATE);
            }

            $order->set_total(wc_format_decimal(Money::exact((float) $order->get_total() + $amount)));
            $order->save();

            $order->add_order_note(sprintf(
                /* translators: %s: amount */
                __('The %s of gift card / store credit this order was placed with is not on it any more, so it has been added back to the total to pay.', 'wp-woocommerce-store-balance'),
                Money::plain($amount, $order->get_currency())
            ));

            $changed = true;
        });

        return $changed;
    }

    /**
     * WooCommerce marks an order "refunded" once the refunds add up to its
     * total. Here the total is only what the gateway was paid: refund that —
     * one returned item on an order mostly paid by gift card — and the order
     * would count as refunded in full, and all of the balance would go back
     * with the goods still out. So an order that holds a balance keeps its
     * status; setting it to Refunded by hand is what returns the balance.
     *
     * @param  mixed  $status
     * @param  int|mixed  $orderId
     * @return mixed
     */
    public function fullyRefundedStatus($status, $orderId = 0)
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order || self::held($order) <= 0) {
            return $status;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: amount */
            __('The amount paid through the payment method has been refunded. %s was paid with gift card / store credit and is still on this order: set the order to Refunded to return all of it to the cards, or change a card\'s balance by hand to return part of it.', 'wp-woocommerce-store-balance'),
            Money::plain(self::held($order), $order->get_currency())
        ));

        return false;
    }

    /**
     * The order is being trashed or deleted. What it holds goes back.
     *
     * @param  int|mixed  $orderId
     */
    public function removed($orderId): void
    {
        $order = $this->orderFor($orderId);

        // A paid order being tidied away keeps what it was paid with.
        if (! $order || ! self::lines($order) || self::wasPaid($order)) {
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
     * Whether the order is paid, or was when it went into the trash.
     */
    protected static function wasPaid(WC_Order $order): bool
    {
        $status = $order->get_status();

        if ($status === 'trash') {
            $before = $order->get_meta('_wp_trash_meta_status') ?: get_post_meta($order->get_id(), '_wp_trash_meta_status', true);
            $status = preg_replace('/^wc-/', '', (string) $before);
        }

        return in_array($status, wc_get_is_paid_statuses(), true);
    }

    /**
     * Back from the trash, in whatever status it had before.
     *
     * @param  int|mixed  $orderId
     */
    public function restored($orderId): void
    {
        $order = $this->orderFor($orderId);

        if (! $order || ! self::lines($order) || ! $order->has_status(self::HELD_STATUSES)) {
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
            $held = Money::exact($line['amount'] - $line['restored']);

            if ($held <= 0) {
                continue;
            }

            if ($cards->credit((int) $line['card_id'], $held, $type, ['order_id' => $order->get_id(), 'note' => $note])) {
                $line['restored'] = Money::exact($line['restored'] + $held);
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
    protected function redebit(WC_Order $order, bool $hold = true): void
    {
        $cards = Plugin::getInstance()->cards();
        $lines = self::lines($order);
        $short = 0.0;

        foreach ($lines as &$line) {
            $amount = Money::exact($line['restored']);

            if ($amount <= 0) {
                continue;
            }

            if ($cards->debit((int) $line['card_id'], $amount, ['order_id' => $order->get_id(), 'note' => __('Order reopened', 'wp-woocommerce-store-balance')])) {
                $line['restored'] = 0.0;
            } else {
                // That part is not paid from a balance after all. Taken off
                // the line, so the order's total goes up by it: the amount
                // due is on the order itself, not only in a note.
                $short += $amount;
                $line['amount'] = Money::exact($line['amount'] - $amount);
                $line['restored'] = 0.0;
            }
        }
        unset($line);

        $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['amount'] > 0));

        $order->update_meta_data(self::META_LINES, $lines);
        $order->update_meta_data(self::META_STATE, self::STATE_DEBITED);

        if ($short > 0) {
            $order->set_total(wc_format_decimal(Money::exact((float) $order->get_total() + $short)));
            $order->update_meta_data(self::META_SHORT, (string) Money::exact((float) $order->get_meta(self::META_SHORT) + $short));
        }

        $order->save();

        if ($short > 0) {
            Logger::error('Order reopened but the balance is no longer there', ['order_id' => $order->get_id(), 'short' => $short]);

            $note = sprintf(
                /* translators: %s: amount */
                __('This order was reopened, but %s of the gift card / store credit it was paid with has been spent elsewhere. That amount has been added back to the order total and is unpaid: collect it or cancel the order.', 'wp-woocommerce-store-balance'),
                Money::plain($short, $order->get_currency())
            );

            // On hold, so that it is not packed and shipped on money that is
            // not there. A person decides what happens next.
            if ($hold) {
                $order->update_status('on-hold', $note);
            } else {
                $order->add_order_note($note);
            }
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
                'value' => '-'.Money::price($amount, $order->get_currency()),
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
        return Money::exact(array_sum(array_column(self::lines($order), 'amount')));
    }

    /**
     * By how much the order's total has been lowered: what was taken from the
     * cards, or what the checkout has staged and is about to take. For code
     * that rebuilds the total from the order's lines, such as a gateway
     * sending an itemised amount.
     */
    public static function deducted(WC_Order $order): float
    {
        $pending = $order->get_meta(self::META_PENDING);

        if (is_array($pending) && $pending && $order->get_meta(self::META_STATE) !== self::STATE_DEBITED) {
            return Money::exact(array_sum(array_column($pending, 'amount')));
        }

        return self::applied($order);
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
            $amount = Money::exact($line['amount'] - $line['restored']);

            if ($amount > 0) {
                $held[$line['card_id']] = Money::exact(($held[$line['card_id']] ?? 0) + $amount);
            }
        }

        return $held;
    }

    public static function held(WC_Order $order): float
    {
        return Money::exact(array_sum(self::heldLines($order)));
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
