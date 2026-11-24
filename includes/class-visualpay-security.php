<?php
/**
 * Security helpers: input validation and safe API output for clients.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Security
 */
class VisualPay_Security {

	/**
	 * POST keys that must never influence create-transaction API payload.
	 * Note: order_id / order_key are auth fields (commonData) — not forbidden here.
	 * API order_id is always derived server-side from the authorized WC order.
	 */
	const CREATE_FORBIDDEN_POST_KEYS = array(
		'amount_usd',
		'amount',
		'email',
		'comment',
		'callback_url',
		'ttl',
	);

	/**
	 * Reject client attempts to override server-controlled create fields.
	 *
	 * @return true|\WP_Error
	 */
	public static function reject_create_client_overrides() {
		foreach ( self::CREATE_FORBIDDEN_POST_KEYS as $key ) {
			if ( isset( $_POST[ $key ] ) || isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Caller verifies nonce before checking forbidden overrides.
				return new WP_Error(
					'visualpay_invalid_request',
					__( 'Invalid request: payment amount and metadata cannot be set by client.', 'visualpay-for-woocommerce' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Sanitize merchant API identifier (currency symbol, network code).
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Max length.
	 * @return string Empty string if invalid.
	 */
	public static function sanitize_identifier( $value, $max = 50 ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value || strlen( $value ) > $max ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Validate locked order amount against API usdt_amount using tolerance snapshot.
	 *
	 * @param float      $locked_usd          Locked WooCommerce amount.
	 * @param float|null $api_usdt            API usdt_amount.
	 * @param float|null $tolerance_percent   payment_tolerance_percent from API.
	 * @return bool
	 */
	public static function amount_matches( $locked_usd, $api_usdt, $tolerance_percent = null ) {
		if ( null === $api_usdt ) {
			return true;
		}

		$locked_usd = (float) $locked_usd;
		$api_usdt   = (float) $api_usdt;

		if ( $locked_usd <= 0 || $api_usdt <= 0 ) {
			return false;
		}

		$tolerance = null !== $tolerance_percent ? max( 0.0, (float) $tolerance_percent ) : 0.0;
		$allowed   = max( 0.05, $locked_usd * ( $tolerance / 100 ) );

		return abs( $api_usdt - $locked_usd ) <= $allowed;
	}

	/**
	 * Whether a positive payment covers the expected token amount, including overpayment.
	 * Convert the existing USD 0.05 allowance to token units using the creation quote.
	 */
	public static function amount_covers( $expected, $paid, $tolerance_percent = 0, $creation_usd = null ) {
		foreach ( array( $expected, $paid ) as $amount ) {
			if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount <= 0 ) {
				return false;
			}
		}
		if ( '' === $tolerance_percent || null === $tolerance_percent ) {
			$tolerance_percent = 0;
		}
		if ( ! is_numeric( $tolerance_percent ) || ! is_finite( (float) $tolerance_percent )
			|| (float) $tolerance_percent < 0 || (float) $tolerance_percent > 100 ) {
			return false;
		}
		$expected = (float) $expected;
		$allowed = $expected * ( (float) $tolerance_percent / 100 );
		if ( is_numeric( $creation_usd ) && is_finite( (float) $creation_usd ) && (float) $creation_usd > 0 ) {
			$allowed = max( $allowed, 0.05 * $expected / (float) $creation_usd );
		}
		// Relative epsilon handles binary floating-point boundaries without rounding small assets to zero.
		return (float) $paid + $expected * 1.0e-12 >= $expected - $allowed;
	}

	/**
	 * Resolve TTL minutes for create request.
	 *
	 * @param int $settings_ttl Plugin setting TTL.
	 * @param int $network_max  Network max_ttl from catalog.
	 * @return int
	 */
	public static function resolve_ttl_minutes( $settings_ttl, $network_max ) {
		$settings_ttl = max( 1, absint( $settings_ttl ) );
		$network_max  = max( 1, absint( $network_max ) );

		return max( 1, min( $settings_ttl, $network_max ) );
	}

	/**
	 * Validate optional client ttl hint matches server-resolved ttl.
	 *
	 * @param int $resolved Server ttl minutes.
	 * @return true|\WP_Error
	 */
	public static function validate_client_ttl_hint( $resolved ) {
		if ( ! isset( $_POST['ttl'] ) && ! isset( $_REQUEST['ttl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Caller verifies nonce before TTL hint validation.
			return true;
		}

		$posted = isset( $_POST['ttl'] ) ? absint( wp_unslash( $_POST['ttl'] ) ) : absint( wp_unslash( $_REQUEST['ttl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Caller verifies nonce before TTL hint validation.
		if ( (int) $posted !== (int) $resolved ) {
			return new WP_Error(
				'visualpay_invalid_request',
				__( 'Invalid request: payment TTL cannot be changed by client.', 'visualpay-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Map current Merchant API field names onto the plugin's canonical keys.
	 *
	 * CreateTransactionData / TransactionItem use pay_amount, amount_usd, expired_at, etc.
	 * Internal storage + payment UI still use expected_amount, usdt_amount, expires_at.
	 *
	 * @param array $data Raw API data.
	 * @return array
	 */
	public static function normalize_api_transaction_data( array $data ) {
		if ( ( ! isset( $data['expected_amount'] ) || '' === $data['expected_amount'] || null === $data['expected_amount'] )
			&& isset( $data['pay_amount'] ) && '' !== $data['pay_amount'] && null !== $data['pay_amount'] ) {
			$data['expected_amount'] = $data['pay_amount'];
		}

		if ( ( ! isset( $data['usdt_amount'] ) || '' === $data['usdt_amount'] || null === $data['usdt_amount'] )
			&& isset( $data['amount_usd'] ) && '' !== $data['amount_usd'] && null !== $data['amount_usd'] ) {
			$data['usdt_amount'] = $data['amount_usd'];
		}

		if ( empty( $data['expires_at'] ) && ! empty( $data['expired_at'] ) ) {
			$data['expires_at'] = $data['expired_at'];
		}

		if ( empty( $data['tx_id_url'] ) && ! empty( $data['tx_explorer_url'] ) ) {
			$data['tx_id_url'] = $data['tx_explorer_url'];
		}

		if ( ( ! isset( $data['commission'] ) || '' === $data['commission'] || null === $data['commission'] )
			&& isset( $data['platform_share'] ) && '' !== $data['platform_share'] && null !== $data['platform_share'] ) {
			$data['commission'] = $data['platform_share'];
		}

		if ( ( ! isset( $data['payment_fee'] ) || '' === $data['payment_fee'] || null === $data['payment_fee'] )
			&& isset( $data['payment_fee_percent'] ) && '' !== $data['payment_fee_percent'] && null !== $data['payment_fee_percent'] ) {
			$data['payment_fee'] = $data['payment_fee_percent'];
		}

		if ( empty( $data['currency_symbol'] ) && ! empty( $data['currency_code'] ) ) {
			$code = strtolower( (string) $data['currency_code'] );
			if ( false !== strpos( $code, '_' ) ) {
				$data['currency_symbol'] = strtoupper( (string) strtok( $code, '_' ) );
			}
		}

		if ( empty( $data['payer_email'] ) && ! empty( $data['email'] ) ) {
			$data['payer_email'] = $data['email'];
		}

		if ( ( ! isset( $data['payer_comment'] ) || '' === $data['payer_comment'] || null === $data['payer_comment'] )
			&& isset( $data['comment'] ) ) {
			$data['payer_comment'] = $data['comment'];
		}

		return $data;
	}

	/**
	 * Whitelist create-transaction API data before sending to browser.
	 *
	 * Only fields needed by the payment UI (payer must send pay_amount).
	 *
	 * @param array $data Raw API data.
	 * @return array
	 */
	public static function sanitize_create_response_for_client( array $data ) {
		$data = self::normalize_api_transaction_data( $data );

		$allowed = array(
			'tracking_code',
			'status',
			'currency_name',
			'currency_symbol',
			'network_code',
			'network_name',
			'pay_amount',
			'wallet_address',
			'qr_code',
			'expires_at',
			'expired_at',
		);

		return self::pick_and_sanitize( $data, $allowed );
	}

	/**
	 * Whitelist status API data before sending to browser.
	 *
	 * @param array $data Raw API/status data.
	 * @return array
	 */
	public static function sanitize_status_response_for_client( array $data ) {
		$data = self::normalize_api_transaction_data( $data );

		$allowed = array(
			'tracking_code',
			'status',
			'normalized_status',
			'currency_name',
			'currency_symbol',
			'network_code',
			'network_name',
			'pay_amount',
			'paid_amount',
			'tx_id',
			'tx_id_url',
			'expires_at',
			'expired_at',
			'thank_you_url',
			'is_paid',
		);

		return self::pick_and_sanitize( $data, $allowed );
	}

	/**
	 * Sanitize currency catalog for frontend select controls.
	 *
	 * @param array $items Raw API items.
	 * @return array
	 */
	public static function sanitize_currency_catalog( array $items ) {
		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$symbol = self::sanitize_identifier( isset( $item['currency_symbol'] ) ? $item['currency_symbol'] : '' );
			if ( '' === $symbol ) {
				continue;
			}

			$networks = array();
			if ( ! empty( $item['supported_networks'] ) && is_array( $item['supported_networks'] ) ) {
				foreach ( $item['supported_networks'] as $net ) {
					if ( ! is_array( $net ) ) {
						continue;
					}

					$code = self::sanitize_identifier( isset( $net['network_code'] ) ? $net['network_code'] : '' );
					if ( '' === $code ) {
						continue;
					}

					$networks[] = array(
						'network_name'     => sanitize_text_field( isset( $net['network_name'] ) ? (string) $net['network_name'] : '' ),
						'network_symbol'   => sanitize_text_field( isset( $net['network_symbol'] ) ? (string) $net['network_symbol'] : '' ),
						'network_code'     => $code,
						'network_fee'      => isset( $net['network_fee'] ) ? (float) $net['network_fee'] : 0,
						// OpenAPI: minimum_pay_usd / maximum_pay_usd (legacy alias: minimum_pay).
						'minimum_pay'      => isset( $net['minimum_pay_usd'] )
							? (float) $net['minimum_pay_usd']
							: ( isset( $net['minimum_pay'] ) ? (float) $net['minimum_pay'] : 0 ),
						'maximum_pay'      => isset( $net['maximum_pay_usd'] ) ? (float) $net['maximum_pay_usd'] : 0,
						'max_ttl'          => isset( $net['max_ttl'] ) ? max( 1, absint( $net['max_ttl'] ) ) : 30,
						'icon'             => self::sanitize_icon_url( isset( $net['icon'] ) ? $net['icon'] : '' ),
					);
				}
			}

			if ( empty( $networks ) ) {
				continue;
			}

			$out[] = array(
				'currency_name'       => sanitize_text_field( isset( $item['currency_name'] ) ? (string) $item['currency_name'] : '' ),
				'currency_symbol'     => $symbol,
				'currency_type'       => sanitize_text_field( isset( $item['currency_type'] ) ? (string) $item['currency_type'] : '' ),
				'price_in_usdt'       => isset( $item['price_in_usdt'] ) ? (float) $item['price_in_usdt'] : 1,
				'icon'                => self::sanitize_icon_url( isset( $item['icon'] ) ? $item['icon'] : '' ),
				'supported_networks'  => $networks,
			);
		}

		return $out;
	}

	/**
	 * Pick allowed keys and sanitize values.
	 *
	 * @param array $data    Source data.
	 * @param array $allowed Allowed keys.
	 * @return array
	 */
	private static function pick_and_sanitize( array $data, array $allowed ) {
		$out = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = self::sanitize_response_value( $key, $data[ $key ] );
			if ( null !== $value && '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Sanitize one response field.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Field value.
	 * @return mixed|null
	 */
	private static function sanitize_response_value( $key, $value ) {
		switch ( $key ) {
			case 'order_id':
			case 'expires_at':
			case 'expired_at':
			case 'paid_at':
			case 'created_at':
				return absint( $value );

			case 'is_paid':
			case 'is_canceled_by_user':
				return (bool) $value;

			case 'usdt_amount':
			case 'amount_usd':
			case 'expected_amount':
			case 'pay_amount':
			case 'commission':
			case 'platform_share':
			case 'merchant_share':
			case 'payment_fee':
			case 'payment_fee_percent':
			case 'payment_tolerance_percent':
			case 'paid_amount':
				return self::sanitize_decimal_string( $value );

			case 'wallet_address':
				return self::sanitize_wallet_address( $value );

			case 'qr_code':
				return self::sanitize_qr_code( $value );

			case 'tx_id_url':
			case 'tx_explorer_url':
			case 'thank_you_url':
			case 'payment_url':
				return esc_url_raw( (string) $value );

			case 'tx_id':
			case 'tracking_code':
			case 'status':
			case 'currency_name':
			case 'currency_symbol':
			case 'currency_code':
			case 'network':
			case 'network_code':
			case 'network_name':
			case 'normalized_status':
				return sanitize_text_field( (string) $value );

			case 'payer_email':
				$email = sanitize_email( (string) $value );
				return $email ? $email : null;

			default:
				return null;
		}
	}

	/**
	 * Sanitize numeric string amounts.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function sanitize_decimal_string( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = is_string( $value ) ? trim( $value ) : (string) $value;
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Normalize Merchant API base URL (HTTPS only; migrate legacy localhost).
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	public static function normalize_api_base( $url ) {
		$default = 'https://visualpay.net';
		$url     = untrailingslashit( trim( (string) $url ) );

		if ( '' === $url ) {
			return $default;
		}

		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( '' === $host || in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) {
			return $default;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		$normalized = 'https://' . $host;
		if ( $port && 443 !== $port ) {
			$normalized .= ':' . $port;
		}
		if ( '' !== $path && '/' !== $path ) {
			$normalized .= $path;
		}

		$normalized = untrailingslashit( esc_url_raw( $normalized ) );
		if ( '' === $normalized || 0 !== stripos( $normalized, 'https://' ) ) {
			return $default;
		}

		return $normalized;
	}

	/**
	 * Allow only https icon URLs.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function sanitize_icon_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize wallet address for display/copy.
	 *
	 * @param mixed $value Address.
	 * @return string
	 */
	public static function sanitize_wallet_address( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = preg_replace( '/[^\w:.\/-]/', '', $value );

		return substr( (string) $value, 0, 256 );
	}

	/**
	 * Allow QR data URI or plain address text only.
	 *
	 * @param mixed $value QR payload.
	 * @return string
	 */
	public static function sanitize_qr_code( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( 0 === stripos( $value, 'data:image/' ) ) {
			if ( ! preg_match( '#^data:image/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=\r\n]+$#', $value ) ) {
				return '';
			}
			return $value;
		}

		return self::sanitize_wallet_address( $value );
	}
}
