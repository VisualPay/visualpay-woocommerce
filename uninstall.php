<?php
/**
 * Uninstall VisualPay for WooCommerce.
 *
 * Fired when the plugin is deleted from the WordPress admin.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'visualpay_wc_settings' );
delete_option( 'visualpay_wc_db_version' );
delete_option( 'woocommerce_visualpay_settings' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}visualpay_transactions" );

flush_rewrite_rules( false );
