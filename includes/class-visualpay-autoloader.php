<?php
/**
 * Simple PSR-4-ish autoloader for VisualPay classes.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Autoloader
 */
class VisualPay_Autoloader {

	/**
	 * Register autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload VisualPay classes.
	 *
	 * @param string $class Class name.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'VisualPay_' ) ) {
			return;
		}

		$map = array(
			'VisualPay_Plugin'               => 'includes/class-visualpay-plugin.php',
			'VisualPay_Activator'            => 'includes/class-visualpay-activator.php',
			'VisualPay_Deactivator'          => 'includes/class-visualpay-deactivator.php',
			'VisualPay_Settings'             => 'includes/class-visualpay-settings.php',
			'VisualPay_I18n'                 => 'includes/class-visualpay-i18n.php',
			'VisualPay_API_Client'           => 'includes/class-visualpay-api-client.php',
			'VisualPay_Dashboard'            => 'includes/class-visualpay-dashboard.php',
			'VisualPay_Gateway'              => 'includes/class-visualpay-gateway.php',
			'VisualPay_Order_Handler'        => 'includes/class-visualpay-order-handler.php',
			'VisualPay_Ajax'                 => 'includes/class-visualpay-ajax.php',
			'VisualPay_Security'             => 'includes/class-visualpay-security.php',
			'VisualPay_Payment_Page'         => 'includes/class-visualpay-payment-page.php',
			'VisualPay_Transactions_Table'   => 'includes/db/class-visualpay-transactions-table.php',
			'VisualPay_Transaction_Store'    => 'includes/db/class-visualpay-transaction-store.php',
			'VisualPay_Admin_Menu'           => 'includes/admin/class-visualpay-admin-menu.php',
			'VisualPay_Admin_Overview'       => 'includes/admin/class-visualpay-admin-overview.php',
			'VisualPay_Admin_Transactions'   => 'includes/admin/class-visualpay-admin-transactions.php',
			'VisualPay_Admin_Settings'       => 'includes/admin/class-visualpay-admin-settings.php',
			'VisualPay_Admin_List_Table'     => 'includes/admin/class-visualpay-admin-list-table.php',
		);

		if ( ! isset( $map[ $class ] ) ) {
			return;
		}

		$file = VISUALPAY_WC_PATH . $map[ $class ];
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
