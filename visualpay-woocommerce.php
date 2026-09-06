<?php
/**
 * Plugin Name: VisualPay for WooCommerce
 * Plugin URI: https://visualpay.net/woocommerce
 * Description: Accept cryptocurrency payments via VisualPay Merchant API in WooCommerce.
 * Version: 1.0.2
 * Author: VisualPay
 * Author URI: https://visualpay.net
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * Text Domain: visualpay-for-woocommerce
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'VISUALPAY_WC_VERSION', '1.0.2' );
define( 'VISUALPAY_WC_FILE', __FILE__ );
define( 'VISUALPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'VISUALPAY_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'VISUALPAY_WC_BASENAME', plugin_basename( __FILE__ ) );

require_once VISUALPAY_WC_PATH . 'includes/class-visualpay-autoloader.php';
VisualPay_Autoloader::register();

register_activation_hook( __FILE__, array( 'VisualPay_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VisualPay_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin after plugins are loaded.
 */
function visualpay_wc_init() {
	VisualPay_Plugin::instance();
}
add_action( 'plugins_loaded', 'visualpay_wc_init', 20 );

/**
 * Declare HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
