<?php

/**
 * Deleting the plugin.
 *
 * The tables hold money the shop owes its customers, so they are kept unless
 * the site says otherwise the way WooCommerce itself asks:
 *
 *     define('WC_REMOVE_ALL_DATA', true);
 *
 * Without that, deleting the plugin and installing it again loses nothing.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

// Gift cards waiting for their delivery date have nothing left to send them.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_store_balance_deliver_card');
}

if (! defined('WC_REMOVE_ALL_DATA') || WC_REMOVE_ALL_DATA !== true) {
    return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}store_balance_transactions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}store_balance_cards");
// phpcs:enable

delete_option('wc_store_balance_db_version');
delete_option('wc_store_balance_settings');
delete_option('wc_store_balance_flush_rewrite');
delete_option('wc_store_balance_seeded');
