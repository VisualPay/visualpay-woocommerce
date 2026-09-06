<?php
/**
 * Plugin settings helper.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Settings
 */
class VisualPay_Settings {

	const OPTION_KEY = 'visualpay_wc_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'     => 'yes',
			'api_key'     => '',
			'api_base'    => 'https://visualpay.net',
			'ttl'         => 15,
			'title'       => 'Cryptocurrency',
			'description' => 'Pay securely with cryptocurrency via VisualPay.',
			'language'    => 'en',
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = wp_parse_args( $stored, self::defaults() );

		// Drop legacy IRR conversion settings if still stored.
		unset( $merged['toman_per_usd'], $merged['irr_unit'] );

		if ( ! isset( $merged['ttl'] ) && isset( $merged['transaction_ttl'] ) ) {
			$merged['ttl'] = absint( $merged['transaction_ttl'] );
		}
		unset( $merged['transaction_ttl'] );

		$allowed = array_keys( VisualPay_I18n::languages() );
		if ( empty( $merged['language'] ) || ! in_array( $merged['language'], $allowed, true ) ) {
			$merged['language'] = 'en';
		}

		$merged['ttl']      = max( 5, min( 60, absint( $merged['ttl'] ) ) );
		$merged['api_base'] = VisualPay_Security::normalize_api_base( isset( $merged['api_base'] ) ? $merged['api_base'] : '' );

		return $merged;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Update settings (partial).
	 *
	 * @param array $data Settings to merge.
	 * @return array
	 */
	public static function update( array $data ) {
		$merged = wp_parse_args( $data, self::all() );
		unset( $merged['toman_per_usd'], $merged['irr_unit'], $merged['transaction_ttl'] );
		$merged['ttl']      = max( 5, min( 60, absint( $merged['ttl'] ) ) );
		$merged['api_base'] = VisualPay_Security::normalize_api_base( isset( $merged['api_base'] ) ? $merged['api_base'] : '' );
		update_option( self::OPTION_KEY, $merged );
		self::sync_gateway_enabled( $merged['enabled'] );
		return $merged;
	}

	/**
	 * Migrate legacy option keys into current schema.
	 */
	public static function migrate_legacy() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		$changed = false;

		if ( ! isset( $stored['ttl'] ) && isset( $stored['transaction_ttl'] ) ) {
			$stored['ttl'] = absint( $stored['transaction_ttl'] );
			$changed       = true;
		}

		if ( isset( $stored['transaction_ttl'] ) ) {
			unset( $stored['transaction_ttl'] );
			$changed = true;
		}

		$normalized_base = VisualPay_Security::normalize_api_base( isset( $stored['api_base'] ) ? $stored['api_base'] : '' );
		if ( ! isset( $stored['api_base'] ) || (string) $stored['api_base'] !== $normalized_base ) {
			$stored['api_base'] = $normalized_base;
			$changed            = true;
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, wp_parse_args( $stored, self::defaults() ) );
		}
	}

	/**
	 * Whether gateway is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === self::get( 'enabled', 'no' );
	}

	/**
	 * Keep WooCommerce gateway enabled flag in sync.
	 *
	 * @param string $enabled yes|no.
	 */
	public static function sync_gateway_enabled( $enabled ) {
		$gateway_settings = get_option( 'woocommerce_visualpay_settings', array() );
		if ( ! is_array( $gateway_settings ) ) {
			$gateway_settings = array();
		}
		$gateway_settings['enabled'] = ( 'yes' === $enabled ) ? 'yes' : 'no';
		update_option( 'woocommerce_visualpay_settings', $gateway_settings );
	}
}
