<?php

namespace GeneroWP\StoreBalance;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use GeneroWP\StoreBalance\Modules\Account;
use GeneroWP\StoreBalance\Modules\Admin;
use GeneroWP\StoreBalance\Modules\Blocks;
use GeneroWP\StoreBalance\Modules\Cart;
use GeneroWP\StoreBalance\Modules\ClassicCheckout;
use GeneroWP\StoreBalance\Modules\Emails;
use GeneroWP\StoreBalance\Modules\FallbackImage;
use GeneroWP\StoreBalance\Modules\GiftCardProduct;
use GeneroWP\StoreBalance\Modules\Issuance;
use GeneroWP\StoreBalance\Modules\OrderAdmin;
use GeneroWP\StoreBalance\Modules\Orders;

class Plugin
{
    /**
     * Cart comes first: every later module reads the state it computes.
     *
     * @var class-string<Module>[]
     */
    public const MODULES = [
        Cart::class,
        Orders::class,
        GiftCardProduct::class,
        FallbackImage::class,
        Issuance::class,
        Emails::class,
        Account::class,
        Blocks::class,
        ClassicCheckout::class,
        Admin::class,
        OrderAdmin::class,
    ];

    public const FILTER_MODULES = 'wc_store_balance_modules';

    public const TEXT_DOMAIN = 'wp-woocommerce-store-balance';

    protected static ?self $instance = null;

    protected bool $booted = false;

    /** @var array<class-string<Module>, Module> */
    protected array $modules = [];

    protected ?CardRepository $cards = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self;
    }

    public function __construct()
    {
        add_action('before_woocommerce_init', [$this, 'declareCompatibility']);

        // plugins_loaded rather than construction: WooCommerce may load after
        // this file, and the modules filter has to be reachable from a site's
        // own plugins.
        add_action('plugins_loaded', [$this, 'boot']);
    }

    /**
     * Orders are only ever read and written through the CRUD classes and the
     * cart integration is a Store API extension, so both hold.
     */
    public function declareCompatibility(): void
    {
        if (! class_exists(FeaturesUtil::class)) {
            return;
        }

        FeaturesUtil::declare_compatibility('custom_order_tables', WC_STORE_BALANCE_FILE, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_STORE_BALANCE_FILE, true);
    }

    public function boot(): void
    {
        if ($this->booted || ! class_exists(\WooCommerce::class)) {
            return;
        }

        $this->booted = true;

        add_action('init', [Install::class, 'maybeUpgrade'], 5);

        /**
         * Filters the modules that will be registered.
         *
         * A site that renders its own checkout UI can drop Blocks or
         * ClassicCheckout and keep the engine.
         *
         * @param  class-string<Module>[]  $modules
         */
        $modules = apply_filters(self::FILTER_MODULES, self::MODULES);

        foreach ($modules as $module) {
            if (! is_subclass_of($module, Module::class)) {
                continue;
            }

            $this->modules[$module] = new $module;
            $this->modules[$module]->register();
        }
    }

    /**
     * @template T of Module
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function module(string $class): ?Module
    {
        return $this->modules[$class] ?? null;
    }

    public function cards(): CardRepository
    {
        return $this->cards ??= new CardRepository;
    }

    public static function url(string $path = ''): string
    {
        return plugins_url($path, WC_STORE_BALANCE_FILE);
    }

    public static function path(string $path = ''): string
    {
        return WC_STORE_BALANCE_PATH.'/'.ltrim($path, '/');
    }

    /**
     * Locate a template, letting a theme override it from
     * `woocommerce/store-balance/<name>`.
     *
     * @param  array<string, mixed>  $args
     */
    public static function template(string $name, array $args = [], bool $return = false): string
    {
        $html = wc_get_template_html($name, $args, 'woocommerce/store-balance/', self::path('templates/'));

        if (! $return) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return $html;
    }
}
