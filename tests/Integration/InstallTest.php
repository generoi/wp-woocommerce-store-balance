<?php

namespace GeneroWP\StoreBalance\Tests\Integration;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use GeneroWP\StoreBalance\Install;

class InstallTest extends TestCase
{
    /**
     * The test site starts from an empty database on every run, so this is
     * the activation on a clean install.
     */
    public function test_activation_creates_both_tables_on_a_clean_database(): void
    {
        global $wpdb;

        $this->assertSame(Install::cardsTable(), $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Install::cardsTable())));
        $this->assertSame(Install::transactionsTable(), $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', Install::transactionsTable())));
        $this->assertSame(Install::DB_VERSION, get_option(Install::OPTION_VERSION));
    }

    /**
     * A balance is money owed to a customer. Stored as a float it would drift;
     * the debit is also done in SQL, which only is exact on a DECIMAL.
     */
    public function test_amounts_are_stored_as_decimals(): void
    {
        global $wpdb;

        $columns = array_column($wpdb->get_results('DESCRIBE '.Install::cardsTable()), 'Type', 'Field');

        $this->assertSame('decimal(19,4)', $columns['balance']);
        $this->assertSame('decimal(19,4)', $columns['initial_amount']);

        $columns = array_column($wpdb->get_results('DESCRIBE '.Install::transactionsTable()), 'Type', 'Field');

        $this->assertSame('decimal(19,4)', $columns['amount']);
        $this->assertSame('decimal(19,4)', $columns['balance_after']);
    }

    /**
     * Uniqueness of a code is the database's job, not the generator's.
     */
    public function test_the_code_column_has_a_unique_index(): void
    {
        global $wpdb;

        $index = $wpdb->get_row('SHOW INDEX FROM '.Install::cardsTable()." WHERE Key_name = 'code'");

        $this->assertNotNull($index);
        $this->assertSame('0', (string) $index->Non_unique);
    }

    /**
     * maybeUpgrade() runs the installer whenever the stored version differs.
     * If dbDelta does not recognise its own tables it rewrites them every
     * time — slow at best, and a lock on the cards table at worst.
     */
    public function test_running_the_installer_again_changes_nothing(): void
    {
        $queries = [];
        $spy = static function ($query) use (&$queries) {
            if (preg_match('/^\s*(CREATE|ALTER|DROP)\b/i', $query)) {
                $queries[] = $query;
            }

            return $query;
        };

        $card = $this->giftCard(25);

        add_filter('query', $spy);
        Install::createTables();
        remove_filter('query', $spy);

        $this->assertSame([], $queries);
        $this->assertSame(25.0, $this->balance($card));
    }

    /**
     * Composer-installed plugins are often activated by a database import, so
     * the activation hook cannot be relied on.
     */
    public function test_a_site_that_never_ran_the_activation_hook_is_installed_on_the_next_request(): void
    {
        delete_option(Install::OPTION_VERSION);
        delete_option('wc_store_balance_flush_rewrite');

        Install::maybeUpgrade();

        $this->assertSame(Install::DB_VERSION, get_option(Install::OPTION_VERSION));
        // The My Account endpoints are rewrite rules: they 404 until flushed.
        $this->assertSame('yes', get_option('wc_store_balance_flush_rewrite'));
    }

    /**
     * Without the declaration WooCommerce shows an incompatibility warning,
     * and refuses to enable order tables or the checkout block for the shop.
     */
    public function test_the_plugin_declares_itself_compatible_with_order_tables_and_the_checkout_block(): void
    {
        $plugin = plugin_basename(WC_STORE_BALANCE_FILE);

        foreach (['custom_order_tables', 'cart_checkout_blocks'] as $feature) {
            $this->assertContains($plugin, FeaturesUtil::get_compatible_plugins_for_feature($feature)['compatible'], $feature);
        }
    }

    public function test_the_suite_runs_with_orders_in_their_own_tables(): void
    {
        if (getenv('WC_STORE_BALANCE_TESTS_LEGACY_ORDERS') !== false) {
            $this->markTestSkipped('Running against legacy order storage.');
        }

        $this->assertTrue(wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled());
    }
}
