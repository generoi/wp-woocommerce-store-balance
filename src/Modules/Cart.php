<?php

namespace GeneroWP\StoreBalance\Modules;

use GeneroWP\StoreBalance\Allocator;
use GeneroWP\StoreBalance\Card;
use GeneroWP\StoreBalance\Code;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Money;
use GeneroWP\StoreBalance\Plugin;
use GeneroWP\StoreBalance\Throttle;
use WC_Cart;
use WP_Error;

/**
 * Works out what the customer's gift cards and store credit pay for, and
 * lowers the cart total by that much.
 *
 * The balance is applied to the finished total — after tax, shipping and
 * coupons — because it is a means of payment, not a discount. A discount would
 * lower the VAT on the order; paying with a voucher must not.
 */
class Cart implements Module
{
    public const SESSION_CODES = 'store_balance_codes';

    public const SESSION_USE_BALANCE = 'store_balance_use_balance';

    /** What the balance paid in the totals the customer was last shown. */
    public const SESSION_SHOWN = 'store_balance_shown';

    public const MAX_CODES = 5;

    /** @var array<string, mixed>|null */
    protected ?array $state = null;

    public function register(): void
    {
        // Late, so every other adjustment to the total has been made.
        add_filter('woocommerce_calculated_total', [$this, 'applyToTotal'], 999, 2);
        add_action('woocommerce_cart_emptied', [$this, 'clearCodes']);
        add_action('wp_logout', [$this, 'clearCodes']);
    }

    /**
     * @param  float|string  $total
     * @param  mixed  $cart
     * @return float|string
     */
    public function applyToTotal($total, $cart)
    {
        if (! $cart instanceof WC_Cart) {
            return $total;
        }

        return Logger::guard('Applying balance to the cart total', function () use ($total, $cart) {
            $this->state = $this->compute((float) $total, $cart);

            return max(0, Money::round((float) $total - $this->state['applied_total']));
        }, $total);
    }

    /**
     * The result of the last calculation.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        if ($this->state === null && function_exists('WC') && WC()->cart) {
            WC()->cart->calculate_totals();
        }

        return $this->state ?? $this->emptyState();
    }

    /**
     * The totals are on their way to the customer's screen: write down what
     * the balance pays in them.
     */
    public function rememberShown(): void
    {
        if ($this->hasSession()) {
            WC()->session->set(self::SESSION_SHOWN, (string) Money::exact($this->state()['applied_total'] ?? 0));
        }
    }

    /**
     * Whether the balance pays less now than in the totals the customer last
     * saw: a card was spent, by someone else with the same code or in another
     * tab, between looking and pressing "Place order". The order must not go
     * through for more than the screen said. Telling once is enough: the new
     * figure is remembered, so the next attempt passes.
     */
    public function paysLessThanShown(): bool
    {
        if (! $this->hasSession()) {
            return false;
        }

        $shown = WC()->session->get(self::SESSION_SHOWN);
        $now = Money::exact($this->state()['applied_total'] ?? 0);

        if (! is_numeric($shown) || $now >= (float) $shown - 0.005) {
            return false;
        }

        WC()->session->set(self::SESSION_SHOWN, (string) $now);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyState(): array
    {
        return [
            'currency' => get_woocommerce_currency(),
            'original_total' => 0.0,
            'eligible_total' => 0.0,
            'excluded_total' => 0.0,
            'applied_total' => 0.0,
            'codes' => [],
            'account' => ['available' => 0.0, 'used' => 0.0, 'gift_cards' => 0.0, 'store_credit' => 0.0, 'other_currencies' => []],
            'use_balance' => $this->useBalance(),
            'lines' => [],
            'only_gift_cards' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(float $total, WC_Cart $cart): array
    {
        $state = $this->emptyState();
        $currency = $state['currency'];
        $decimals = wc_get_price_decimals();
        $cards = Plugin::getInstance()->cards();

        $giftCardLines = $this->giftCardLinesTotal($cart);

        $state['original_total'] = Money::round($total);
        // A balance cannot buy a gift card: that would turn store credit into a
        // transferable code and let one card be laundered into another.
        $state['eligible_total'] = max(0, Money::round($total - $giftCardLines));
        $state['excluded_total'] = min($giftCardLines, Money::round($total));
        $state['only_gift_cards'] = $giftCardLines > 0 && $state['eligible_total'] <= 0;

        $remaining = $state['eligible_total'];
        $held = $this->heldByCurrentOrder();
        $seen = [];

        foreach ($this->codes() as $code) {
            $card = $cards->findByCode($code);

            // Gone, spent, expired or disabled since it was applied: drop it
            // from the session rather than keep showing a dead line.
            if (! $card || ! $card->isActive() || $card->isExpired() || ! $card->isGiftCard() || isset($seen[$card->id])) {
                $this->forgetCode($code);

                continue;
            }

            // Added to an account since it was typed in here. From then on it
            // is spent from that account's balance and the code is dead —
            // including in a cart that still had it applied.
            if ($card->isRedeemed()) {
                $this->forgetCode($code);

                continue;
            }

            $available = Money::round($card->balance + ($held[$card->id] ?? 0));

            if ($available <= 0) {
                $this->forgetCode($code);

                continue;
            }

            $seen[$card->id] = true;
            $reason = null;
            $amount = 0.0;

            // Kept in the list with a reason rather than dropped: the customer
            // may switch back to the currency the card is in.
            if ($card->currency !== $currency) {
                $reason = $this->currencyMessage($card, $currency);
            } else {
                $amount = Money::round(min($remaining, $available));
                $remaining = Money::round($remaining - $amount);
            }

            $state['codes'][] = [
                'card_id' => $card->id,
                'code' => $card->code,
                'masked' => $card->maskedCode(),
                'currency' => $card->currency,
                'available' => $available,
                'amount' => $amount,
                'reason' => $reason,
            ];

            if ($amount > 0) {
                $state['lines'][] = ['card_id' => $card->id, 'type' => $card->type, 'masked' => $card->maskedCode(), 'amount' => $amount, 'source' => 'code'];
            }
        }

        $userId = get_current_user_id();

        if ($userId) {
            $accountCards = [];
            $available = [];

            foreach ($cards->forCustomer($userId) as $card) {
                if (isset($seen[$card->id]) || ! $card->isActive() || $card->isExpired()) {
                    continue;
                }

                $balance = Money::round($card->balance + ($held[$card->id] ?? 0));

                if ($balance <= 0) {
                    continue;
                }

                if ($card->currency !== $currency) {
                    $state['account']['other_currencies'][$card->currency] = Money::round(($state['account']['other_currencies'][$card->currency] ?? 0) + $balance);

                    continue;
                }

                $accountCards[$card->id] = $card;
                $available[$card->id] = $balance;
                $state['account']['available'] = Money::round($state['account']['available'] + $balance);
                $key = $card->isStoreCredit() ? 'store_credit' : 'gift_cards';
                $state['account'][$key] = Money::round($state['account'][$key] + $balance);
            }

            if ($state['use_balance'] && $remaining > 0 && $available) {
                $order = Allocator::sort(array_map(
                    static fn (Card $card) => ['id' => $card->id, 'expires' => $card->expiresAt],
                    array_values($accountCards)
                ));

                $sorted = [];
                foreach ($order as $entry) {
                    $sorted[$entry['id']] = $available[$entry['id']];
                }

                foreach (Allocator::allocate($remaining, $sorted, $decimals) as $cardId => $amount) {
                    $card = $accountCards[$cardId];
                    $state['lines'][] = ['card_id' => $cardId, 'type' => $card->type, 'masked' => $card->reference(), 'amount' => $amount, 'source' => 'account'];
                    $state['account']['used'] = Money::round($state['account']['used'] + $amount);
                    $remaining = Money::round($remaining - $amount);
                }
            }
        }

        $state['applied_total'] = Money::round(array_sum(array_column($state['lines'], 'amount')));

        /**
         * Filters the computed balance state for the cart.
         *
         * @param  array<string, mixed>  $state
         * @param  WC_Cart  $cart
         */
        return apply_filters('wc_store_balance_cart_state', $state, $cart);
    }

    /**
     * Add a gift card code to the cart.
     */
    public function applyCode(string $input): Card|WP_Error
    {
        if (! $this->hasSession()) {
            return new WP_Error('wc_store_balance_no_session', __('Your session has expired. Reload the page and try again.', 'wp-woocommerce-store-balance'));
        }

        if (Throttle::blocked()) {
            return new WP_Error('wc_store_balance_throttled', __('Too many attempts. Please wait ten minutes and try again.', 'wp-woocommerce-store-balance'));
        }

        if (trim($input) === '') {
            return new WP_Error('wc_store_balance_empty_code', __('Enter a gift card code.', 'wp-woocommerce-store-balance'));
        }

        $card = Plugin::getInstance()->cards()->findByCode($input);
        $error = $this->refusal($card);

        if ($error) {
            Throttle::hit($error->get_error_code() === 'wc_store_balance_invalid_code');

            return $error;
        }

        $codes = $this->codes();

        if (in_array($card->code, $codes, true)) {
            return new WP_Error('wc_store_balance_applied', __('This gift card is already applied.', 'wp-woocommerce-store-balance'));
        }

        if (count($codes) >= self::MAX_CODES) {
            return new WP_Error('wc_store_balance_too_many', sprintf(
                /* translators: %d: number of gift cards */
                __('You can use at most %d gift cards on one order.', 'wp-woocommerce-store-balance'),
                self::MAX_CODES
            ));
        }

        if ($this->state()['only_gift_cards']) {
            return new WP_Error('wc_store_balance_gift_card_cart', __('Gift cards cannot be used to buy gift cards.', 'wp-woocommerce-store-balance'));
        }

        $codes[] = $card->code;
        WC()->session->set(self::SESSION_CODES, $codes);
        $this->state = null;

        return $card;
    }

    public function removeCode(string $code): void
    {
        $this->forgetCode(Code::normalize($code));
        $this->state = null;
    }

    /**
     * Remove a code by the card it belongs to. The cart UI only ever sees the
     * masked code, so it refers to cards by id.
     */
    public function removeCard(int $cardId): void
    {
        foreach ($this->state()['codes'] as $line) {
            if ((int) $line['card_id'] === $cardId) {
                $this->removeCode($line['code']);
            }
        }
    }

    public function setUseBalance(bool $use): void
    {
        if ($this->hasSession()) {
            WC()->session->set(self::SESSION_USE_BALANCE, $use ? 'yes' : 'no');
            $this->state = null;
        }
    }

    /**
     * On unless the customer turned it off: the balance is their money, and
     * having to remember to tick a box to spend it helps nobody.
     */
    public function useBalance(): bool
    {
        return ! $this->hasSession() || WC()->session->get(self::SESSION_USE_BALANCE, 'yes') !== 'no';
    }

    /**
     * @return string[]
     */
    public function codes(): array
    {
        if (! $this->hasSession()) {
            return [];
        }

        $codes = WC()->session->get(self::SESSION_CODES, []);

        return is_array($codes) ? array_values(array_filter(array_map('strval', $codes))) : [];
    }

    public function clearCodes(): void
    {
        if ($this->hasSession()) {
            WC()->session->set(self::SESSION_CODES, []);
        }

        $this->state = null;
    }

    protected function forgetCode(string $code): void
    {
        if ($this->hasSession()) {
            WC()->session->set(self::SESSION_CODES, array_values(array_diff($this->codes(), [$code])));
        }
    }

    protected function hasSession(): bool
    {
        return function_exists('WC') && WC()->session !== null;
    }

    protected function giftCardLinesTotal(WC_Cart $cart): float
    {
        $total = 0.0;

        foreach ($cart->get_cart() as $item) {
            if (! empty($item[GiftCardProduct::CART_KEY])) {
                $total += (float) ($item['line_total'] ?? 0) + (float) ($item['line_tax'] ?? 0);
            }
        }

        return Money::round($total);
    }

    /**
     * What the order this session is still paying for has already taken.
     *
     * When a payment fails the order exists and the balance is debited, but the
     * customer is back at the checkout. Without this the cart would see the
     * cards as emptier than they are and ask for money they already put up.
     *
     * @return array<int, float> card id => amount
     */
    protected function heldByCurrentOrder(): array
    {
        if (! $this->hasSession()) {
            return [];
        }

        $held = [];
        // WooCommerce's keys point at the newest order only; the plugin's own
        // list also knows the ones before it.
        $remembered = WC()->session->get(Orders::SESSION_ORDERS, []);

        $orderIds = array_unique(array_filter(array_map('absint', array_merge(
            is_array($remembered) ? $remembered : [],
            [WC()->session->get('order_awaiting_payment'), WC()->session->get('store_api_draft_order')]
        ))));

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);

            if (! $order || ! $order->has_status(['pending', 'failed', 'checkout-draft'])) {
                continue;
            }

            foreach (Orders::heldLines($order) as $cardId => $amount) {
                $held[$cardId] = Money::round(($held[$cardId] ?? 0) + $amount);
            }
        }

        return $held;
    }

    protected function currencyMessage(Card $card, string $currency): string
    {
        return sprintf(
            /* translators: 1: the card's currency code, 2: the cart's currency code */
            __('This gift card is in %1$s and cannot be used for an order in %2$s.', 'wp-woocommerce-store-balance'),
            $card->currency,
            $currency
        );
    }

    /**
     * Why a code cannot be used in this cart, or null if it can.
     */
    protected function refusal(?Card $card): ?WP_Error
    {
        // Store credit is never spent by code, and says nothing about itself to
        // someone who guesses one.
        if (! $card || ! $card->isGiftCard() || ! $card->isActive()) {
            return new WP_Error('wc_store_balance_invalid_code', __('We could not find that gift card code. Please check it and try again.', 'wp-woocommerce-store-balance'));
        }

        if ($card->isExpired()) {
            return new WP_Error('wc_store_balance_expired', sprintf(
                /* translators: %s: date */
                __('This gift card expired on %s.', 'wp-woocommerce-store-balance'),
                wp_date(wc_date_format(), $card->expiresAt)
            ));
        }

        if ($card->isRedeemed()) {
            if ($card->customerId === get_current_user_id()) {
                return new WP_Error('wc_store_balance_in_account', __('This gift card is already in your account balance.', 'wp-woocommerce-store-balance'));
            }

            return new WP_Error('wc_store_balance_redeemed', __('This gift card has been added to an account. Log in to that account to use it.', 'wp-woocommerce-store-balance'));
        }

        if (! Money::isPositive($card->balance)) {
            return new WP_Error('wc_store_balance_empty', __('This gift card has no balance left.', 'wp-woocommerce-store-balance'));
        }

        $currency = get_woocommerce_currency();

        if ($card->currency !== $currency) {
            return new WP_Error('wc_store_balance_currency', $this->currencyMessage($card, $currency), ['currency' => $card->currency]);
        }

        return null;
    }
}
