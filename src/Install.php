<?php

namespace GeneroWP\StoreBalance;

class Install
{
    public const OPTION_VERSION = 'wc_store_balance_db_version';

    public const DB_VERSION = '1';

    public static function activate(): void
    {
        self::createTables();
        update_option(self::OPTION_VERSION, self::DB_VERSION);

        // The account endpoints are rewrite rules; flag a flush for the next
        // request, when they have been registered.
        update_option('wc_store_balance_flush_rewrite', 'yes');
    }

    /**
     * Composer-installed plugins are often activated by a database import
     * rather than through the admin, so the activation hook cannot be relied
     * on to have run.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION_VERSION) !== self::DB_VERSION) {
            self::activate();
        }

        if (get_option('wc_store_balance_flush_rewrite') === 'yes') {
            // Late on init so every endpoint has been added first.
            add_action('init', static function (): void {
                flush_rewrite_rules(false);
                delete_option('wc_store_balance_flush_rewrite');
            }, 999);
        }
    }

    public static function cardsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'store_balance_cards';
    }

    public static function transactionsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'store_balance_transactions';
    }

    /**
     * Amounts are DECIMAL, not float: the balance is money owed to a customer,
     * and the debit is done in SQL (`balance = balance - x`) so that two
     * concurrent checkouts cannot both spend it.
     */
    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $cards = self::cardsTable();
        $transactions = self::transactionsTable();

        dbDelta("CREATE TABLE {$cards} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(32) NOT NULL,
            type varchar(20) NOT NULL DEFAULT 'giftcard',
            currency char(3) NOT NULL,
            initial_amount decimal(19,4) NOT NULL DEFAULT 0,
            balance decimal(19,4) NOT NULL DEFAULT 0,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            recipient_email varchar(200) NOT NULL DEFAULT '',
            sender_name varchar(200) NOT NULL DEFAULT '',
            message text NULL,
            locale varchar(20) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            deliver_at datetime NULL,
            delivered_at datetime NULL,
            redeemed_at datetime NULL,
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY customer_id (customer_id),
            KEY order_id (order_id),
            KEY type (type),
            KEY recipient_email (recipient_email(191))
        ) {$collate};");

        dbDelta("CREATE TABLE {$transactions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            amount decimal(19,4) NOT NULL DEFAULT 0,
            balance_after decimal(19,4) NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY card_id (card_id),
            KEY order_id (order_id)
        ) {$collate};");
    }
}
