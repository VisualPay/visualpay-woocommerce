<?php
/**
 * Main plugin bootstrap.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Plugin
 */
class VisualPay_Plugin {

	/**
	 * Singleton.
	 *
	 * @var VisualPay_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 *
	 * @return VisualPay_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->maybe_upgrade_db();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );

		VisualPay_Order_Handler::init();
		VisualPay_Payment_Page::init();
		VisualPay_Ajax::init();

		if ( is_admin() ) {
			VisualPay_Admin_Menu::init();
		}
	}

	/**
	 * Check WooCommerce.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice.
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'VisualPay for WooCommerce requires WooCommerce to be installed and active.', 'visualpay-for-woocommerce' );
		echo '</p></div>';
	}

	/**
	 * Ensure DB table exists after updates.
	 */
	private function maybe_upgrade_db() {
		$version = get_option( 'visualpay_wc_db_version' );
		if ( VISUALPAY_WC_VERSION === $version ) {
			return;
		}

		VisualPay_Transactions_Table::create_table();
		VisualPay_Settings::migrate_legacy();
		update_option( 'visualpay_wc_db_version', VISUALPAY_WC_VERSION );

		// Never flush rewrites on plugins_loaded — $wp_rewrite is not ready yet.
		add_action( 'init', array( __CLASS__, 'flush_rewrites_after_upgrade' ), 99 );
	}

	/**
	 * Refresh payment-page rewrite rules after a version upgrade.
	 */
	public static function flush_rewrites_after_upgrade() {
		VisualPay_Payment_Page::register_rewrite();
		flush_rewrite_rules( false );
	}

	/**
	 * Register gateway class.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'VisualPay_Gateway';
		return $gateways;
	}
}
