<?php
/**
 * Plugin deactivation.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Deactivator
 */
class VisualPay_Deactivator {

	/**
	 * Run on deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
