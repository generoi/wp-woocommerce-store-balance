<?php

namespace GeneroWP\StoreBalance\Modules;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use GeneroWP\StoreBalance\BlocksIntegration;
use GeneroWP\StoreBalance\Logger;
use GeneroWP\StoreBalance\Module;
use GeneroWP\StoreBalance\Plugin;

/**
 * The cart and checkout blocks.
 *
 * The blocks know nothing about balances, so two things are added to the
 * Store API: the cart response carries the balance state under `extensions`,
 * and the cart accepts three small commands — apply a code, remove one, and
 * switch the account balance on or off.
 */
class Blocks implements Module
{
    public const NAMESPACE = 'wc-store-balance';

    public function register(): void
    {
        if (did_action('woocommerce_blocks_loaded')) {
            $this->extendStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'extendStoreApi']);
        }

        foreach (['cart', 'checkout'] as $block) {
            add_action("woocommerce_blocks_{$block}_block_registration", static function ($registry): void {
                if (! $registry->is_registered(self::NAMESPACE)) {
                    $registry->register(new BlocksIntegration);
                }
            });
        }
    }

    public function extendStoreApi(): void
    {
        if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => CartSchema::IDENTIFIER,
            'namespace' => self::NAMESPACE,
            'data_callback' => [$this, 'data'],
            'schema_callback' => [$this, 'schema'],
            'schema_type' => ARRAY_A,
        ]);

        woocommerce_store_api_register_update_callback([
            'namespace' => self::NAMESPACE,
            'callback' => [$this, 'update'],
        ]);
    }

    /**
     * Amounts are in minor units, as strings — the same convention as every
     * other price in the Store API, so the blocks' own formatter prints them.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return Logger::guard('Store API cart data', function (): array {
            $cart = Plugin::getInstance()->module(Cart::class);
            $state = $cart ? $cart->state() : [];
            $money = static fn ($amount): string => (string) woocommerce_store_api_get_formatter('money')->format($amount);
            $account = $state['account'] ?? [];

            $other = [];
            foreach ($account['other_currencies'] ?? [] as $currency => $amount) {
                $other[] = wp_strip_all_tags(html_entity_decode(wc_price($amount, ['currency' => $currency])));
            }

            return [
                'applied_total' => $money($state['applied_total'] ?? 0),
                'original_total' => $money($state['original_total'] ?? 0),
                'label' => Orders::label($state['lines'] ?? []),
                'codes' => array_map(static fn (array $line): array => [
                    'id' => (int) $line['card_id'],
                    'masked' => (string) $line['masked'],
                    'amount' => $money($line['amount']),
                    'available' => $money($line['available']),
                    'reason' => (string) ($line['reason'] ?? ''),
                ], $state['codes'] ?? []),
                'account' => [
                    'available' => $money($account['available'] ?? 0),
                    'used' => $money($account['used'] ?? 0),
                    'gift_cards' => $money($account['gift_cards'] ?? 0),
                    'store_credit' => $money($account['store_credit'] ?? 0),
                    'other_currencies' => $other,
                ],
                'use_balance' => (bool) ($state['use_balance'] ?? true),
                'only_gift_cards' => (bool) ($state['only_gift_cards'] ?? false),
                'logged_in' => is_user_logged_in(),
            ];
        }, $this->emptyData());
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyData(): array
    {
        return [
            'applied_total' => '0',
            'original_total' => '0',
            'label' => '',
            'codes' => [],
            'account' => ['available' => '0', 'used' => '0', 'gift_cards' => '0', 'store_credit' => '0', 'other_currencies' => []],
            'use_balance' => true,
            'only_gift_cards' => false,
            'logged_in' => is_user_logged_in(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $amount = ['type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true];

        return [
            'applied_total' => ['description' => 'Amount paid from gift cards and store credit, in minor units.'] + $amount,
            'original_total' => ['description' => 'Cart total before the balance was applied, in minor units.'] + $amount,
            'label' => ['description' => 'What the applied balance is made of.', 'type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true],
            'codes' => ['description' => 'Gift card codes applied to the cart.', 'type' => 'array', 'context' => ['view', 'edit'], 'readonly' => true],
            'account' => ['description' => 'The balance on the customer account.', 'type' => 'object', 'context' => ['view', 'edit'], 'readonly' => true],
            'use_balance' => ['description' => 'Whether the account balance is used.', 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'only_gift_cards' => ['description' => 'Whether the cart holds nothing a balance can pay for.', 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'logged_in' => ['description' => 'Whether the customer is logged in.', 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
        ];
    }

    /**
     * @param  mixed  $data  Whatever the request body carried under `data`.
     *
     * @throws RouteException
     */
    public function update($data): void
    {
        $cart = Plugin::getInstance()->module(Cart::class);

        if (! $cart || ! is_array($data)) {
            return;
        }

        switch ((string) ($data['action'] ?? '')) {
            case 'apply':
                $result = $cart->applyCode(sanitize_text_field((string) ($data['code'] ?? '')));

                if (is_wp_error($result)) {
                    throw new RouteException((string) $result->get_error_code(), $result->get_error_message(), 400);
                }
                break;

            case 'remove':
                $cart->removeCard(absint($data['id'] ?? 0));
                break;

            case 'use_balance':
                $cart->setUseBalance(! empty($data['value']));
                break;
        }
    }
}
