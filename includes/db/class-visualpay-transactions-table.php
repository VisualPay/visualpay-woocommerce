<?php
/**
 * Custom transactions table schema.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Transactions_Table
 */
class VisualPay_Transactions_Table {

	/**
	 * Table name without prefix.
	 */
	const TABLE = 'visualpay_transactions';

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create / upgrade table.
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			tracking_code varchar(100) NOT NULL DEFAULT '',
			tx_id varchar(255) DEFAULT NULL,
			status varchar(50) NOT NULL DEFAULT 'pending',
			currency_symbol varchar(50) NOT NULL DEFAULT '',
			currency_name varchar(100) NOT NULL DEFAULT '',
			network_code varchar(50) NOT NULL DEFAULT '',
			network_name varchar(100) NOT NULL DEFAULT '',
			usdt_amount decimal(18,8) DEFAULT NULL,
			paid_amount decimal(18,8) DEFAULT NULL,
			expected_amount decimal(18,8) DEFAULT NULL,
			base_amount decimal(18,8) DEFAULT NULL,
			network_fee decimal(18,8) DEFAULT NULL,
			wallet_address varchar(255) DEFAULT NULL,
			tx_id_url text DEFAULT NULL,
			payer_email varchar(190) DEFAULT NULL,
			payer_comment text DEFAULT NULL,
			raw_create_response longtext DEFAULT NULL,
			raw_status_response longtext DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY tracking_code (tracking_code),
			KEY tx_id (tx_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
