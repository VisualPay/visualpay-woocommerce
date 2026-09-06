<?php
/**
 * Admin menu registration.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Admin_Menu
 */
class VisualPay_Admin_Menu {

	/**
	 * Init.
	 */
	public static function init() {
		// After WooCommerce (Payments also uses position 56).
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 59 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		add_filter( 'plugin_action_links_' . VISUALPAY_WC_BASENAME, array( __CLASS__, 'plugin_action_links' ) );
	}

	/**
	 * Mark VisualPay screens on the admin body for layout CSS.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public static function admin_body_class( $classes ) {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === strpos( $page, 'visualpay-' ) ) {
			$classes .= ' visualpay-admin-screen';
			if ( 'visualpay-overview' === $page ) {
				$classes .= ' visualpay-admin-overview-screen';
			}
			if ( 'visualpay-settings' === $page ) {
				$classes .= ' visualpay-admin-settings-screen';
			}
			if ( 'visualpay-transactions' === $page ) {
				$classes .= ' visualpay-admin-transactions-screen';
			}
		}
		return $classes;
	}

	/**
	 * Settings shortcut on the Plugins screen.
	 *
	 * @param array $links Plugin action links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=visualpay-settings' ) ) . '">' . esc_html__( 'Settings', 'visualpay-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Register VisualPay top-level menu.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'VisualPay', 'visualpay-for-woocommerce' ),
			__( 'VisualPay', 'visualpay-for-woocommerce' ),
			'manage_woocommerce',
			'visualpay-overview',
			array( 'VisualPay_Admin_Overview', 'render' ),
			'dashicons-money-alt',
			58
		);

		add_submenu_page(
			'visualpay-overview',
			__( 'Overview', 'visualpay-for-woocommerce' ),
			__( 'Overview', 'visualpay-for-woocommerce' ),
			'manage_woocommerce',
			'visualpay-overview',
			array( 'VisualPay_Admin_Overview', 'render' )
		);

		add_submenu_page(
			'visualpay-overview',
			__( 'Transactions', 'visualpay-for-woocommerce' ),
			__( 'Transactions', 'visualpay-for-woocommerce' ),
			'manage_woocommerce',
			'visualpay-transactions',
			array( 'VisualPay_Admin_Transactions', 'render' )
		);

		add_submenu_page(
			'visualpay-overview',
			__( 'Settings', 'visualpay-for-woocommerce' ),
			__( 'Settings', 'visualpay-for-woocommerce' ),
			'manage_woocommerce',
			'visualpay-settings',
			array( 'VisualPay_Admin_Settings', 'render' )
		);
	}

	/**
	 * Enqueue admin assets on VisualPay screens.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'visualpay' ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'visualpay-admin',
			VISUALPAY_WC_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			VISUALPAY_WC_VERSION
		);

		wp_enqueue_script(
			'visualpay-admin',
			VISUALPAY_WC_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			VISUALPAY_WC_VERSION,
			true
		);

		wp_localize_script(
			'visualpay-admin',
			'VisualPayAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'visualpay_admin' ),
				'i18n'    => array(
					'loading'        => __( 'Loading...', 'visualpay-for-woocommerce' ),
					'error'          => __( 'Failed to load order details.', 'visualpay-for-woocommerce' ),
					'close'          => __( 'Close', 'visualpay-for-woocommerce' ),
					'testLoading'    => __( 'Testing...', 'visualpay-for-woocommerce' ),
					'testButton'     => __( 'Test', 'visualpay-for-woocommerce' ),
					'testSuccess'    => __( 'Success', 'visualpay-for-woocommerce' ),
					'testError'      => __( 'Error', 'visualpay-for-woocommerce' ),
					'testSuccessMsg' => __( 'Test completed successfully. Plugin connected to VisualPay.', 'visualpay-for-woocommerce' ),
					'testFailedMsg'  => __( 'Test failed. Plugin could not connect to VisualPay.', 'visualpay-for-woocommerce' ),
					'testFailed'     => __( 'Test failed. Plugin could not connect to VisualPay.', 'visualpay-for-woocommerce' ),
					'statusUpdated'  => __( 'VisualPay plugin enabled', 'visualpay-for-woocommerce' ),
					'statusDisabled' => __( 'VisualPay plugin disabled.', 'visualpay-for-woocommerce' ),
					'statusFailed'   => __( 'Failed to update status.', 'visualpay-for-woocommerce' ),
					'statusActive'   => __( 'Plugin status : Active', 'visualpay-for-woocommerce' ),
					'statusInactive' => __( 'Plugin status : Inactive', 'visualpay-for-woocommerce' ),
					'settingsSaved'  => __( 'Settings saved.', 'visualpay-for-woocommerce' ),
					'copied'         => __( 'Copied to clipboard.', 'visualpay-for-woocommerce' ),
					'copyFailed'     => __( 'Copy failed.', 'visualpay-for-woocommerce' ),
				),
			)
		);
	}
}
