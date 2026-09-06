<?php
/**
 * Plugin activation.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Activator
 */
class VisualPay_Activator {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		// Do not mutate site permalink_structure. Merchants should save
		// Settings → Permalinks once so /visualpay/ rewrite rules register.
		VisualPay_Transactions_Table::create_table();
		VisualPay_Payment_Page::register_rewrite();
		flush_rewrite_rules();

		$defaults = VisualPay_Settings::defaults();
		$existing = get_option( VisualPay_Settings::OPTION_KEY, array() );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			update_option( VisualPay_Settings::OPTION_KEY, $defaults );
		} else {
			update_option( VisualPay_Settings::OPTION_KEY, wp_parse_args( $existing, $defaults ) );
		}

		VisualPay_Settings::migrate_legacy();

		update_option( 'visualpay_wc_db_version', VISUALPAY_WC_VERSION );
	}
}
