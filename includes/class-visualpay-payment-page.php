<?php
/**
 * Dedicated cryptocurrency payment page.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Payment_Page
 */
class VisualPay_Payment_Page {

	const QUERY_VAR = 'visualpay_pay';

	/**
	 * Pretty permalink slug for the payment page.
	 */
	const ROUTE_SLUG = 'visualpay';

	/**
	 * Legacy slug kept for backward-compatible rewrite matching.
	 */
	const LEGACY_ROUTE_SLUG = 'visualpay-pay';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'request', array( __CLASS__, 'filter_request' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_restore_cart' ), 1 );
	}

	/**
	 * Back URL: restores cart from unpaid order then goes to cart.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_back_url( WC_Order $order ) {
		return add_query_arg(
			array(
				'visualpay_back' => '1',
				'order_id'       => $order->get_id(),
				'key'            => $order->get_order_key(),
				'_wpnonce'       => wp_create_nonce( 'visualpay_back_' . $order->get_id() ),
			),
			wc_get_cart_url()
		);
	}

	/**
	 * Restore cart items from unpaid VisualPay order when user clicks Back.
	 */
	public static function maybe_restore_cart() {
		if ( empty( $_GET['visualpay_back'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id || '' === $key || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'visualpay_back_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'visualpay-for-woocommerce' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || 'visualpay' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Cancel pending crypto invoice if any (best effort).
		$tracking = $order->get_meta( VisualPay_Order_Handler::META_TRACKING );
		$status   = strtolower( (string) $order->get_meta( VisualPay_Order_Handler::META_STATUS ) );
		if ( $tracking && 'pending' === $status ) {
			$client = new VisualPay_API_Client();
			$client->cancel_transaction( $tracking );
			VisualPay_Order_Handler::apply_status(
				$order,
				array(
					'status'        => 'cancelled',
					'tracking_code' => $tracking,
				)
			);
		}

		WC()->cart->empty_cart();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$variation_id = $item->get_variation_id();
			$product_id   = $variation_id ? $item->get_product_id() : $item->get_product_id();
			$qty          = max( 1, (int) $item->get_quantity() );
			$variation    = array();
			if ( $variation_id ) {
				foreach ( $item->get_meta_data() as $meta ) {
					if ( 0 === strpos( $meta->key, 'pa_' ) || 0 === strpos( $meta->key, 'attribute_' ) ) {
						$variation[ $meta->key ] = $meta->value;
					}
				}
			}
			WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation );
		}

		// Clean query args and stay on cart.
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Inject query var from pretty path even if rewrite rules are stale.
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	public static function filter_request( $query_vars ) {
		if ( self::is_route_path( self::request_path() ) ) {
			$query_vars[ self::QUERY_VAR ] = '1';
		}
		return $query_vars;
	}

	/**
	 * Rewrite rules.
	 */
	public static function register_rewrite() {
		add_rewrite_rule( '^' . self::ROUTE_SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . self::LEGACY_ROUTE_SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Whether current request is the VisualPay payment page.
	 *
	 * Works with pretty permalinks, plain permalinks, and path fallback
	 * (e.g. when rewrite rules were not flushed yet).
	 *
	 * @return bool
	 */
	public static function is_payment_request() {
		$query_var = get_query_var( self::QUERY_VAR );
		if ( '1' === (string) $query_var || 1 === $query_var || true === $query_var ) {
			return true;
		}

		if ( isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== (string) $raw && '0' !== (string) $raw ) {
				return true;
			}
		}

		return self::is_route_path( self::request_path() );
	}

	/**
	 * Build payment URL for order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_payment_url( WC_Order $order ) {
		$args = array(
			'order_id' => $order->get_id(),
			'key'      => $order->get_order_key(),
		);

		// Pretty permalinks: /visualpay/?order_id=&key=
		if ( get_option( 'permalink_structure' ) ) {
			return add_query_arg( $args, home_url( '/' . self::ROUTE_SLUG . '/' ) );
		}

		// Plain permalinks: /?visualpay_pay=1&order_id=&key=
		$args[ self::QUERY_VAR ] = '1';
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Validate and load order from request.
	 *
	 * @return WC_Order|null
	 */
	public static function get_order_from_request() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id || '' === $key ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		if ( 'visualpay' !== $order->get_payment_method() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Render payment template or redirect.
	 */
	public static function maybe_render() {
		if ( ! self::is_payment_request() ) {
			return;
		}

		self::send_security_headers();

		$order = self::get_order_from_request();
		if ( ! $order ) {
			wp_die( esc_html( VisualPay_I18n::t( 'invalid_payment_link' ) ), 403 );
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			$status = $order->get_meta( VisualPay_Order_Handler::META_STATUS );
			if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
				// Allow retry page UI; template handles failed state if needed.
			} else {
				wp_die( esc_html( VisualPay_I18n::t( 'order_not_payable' ) ), 403 );
			}
		}

		if ( ! VisualPay_Order_Handler::is_payable( $order ) ) {
			wp_die( esc_html( VisualPay_I18n::t( 'order_not_payable' ) ), 403 );
		}

		// Ensure amount is locked before rendering.
		if ( '' === $order->get_meta( VisualPay_Order_Handler::META_LOCKED_USD ) ) {
			VisualPay_Order_Handler::lock_amount_usd( $order );
			$order = wc_get_order( $order->get_id() );
		}

		self::enqueue_assets( $order );
		$status_row = VisualPay_Transaction_Store::get_by_order_id( $order->get_id() );
		include VISUALPAY_WC_PATH . 'templates/payment-page.php';
		exit;
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function enqueue_assets( WC_Order $order ) {
		$vendor = VISUALPAY_WC_URL . 'assets/vendor/';

		wp_enqueue_style( 'bootstrap-5', $vendor . 'bootstrap.min.css', array(), '5.3.3' );
		wp_enqueue_style( 'bootstrap-icons', $vendor . 'bootstrap-icons/bootstrap-icons.min.css', array(), '1.11.3' );
		wp_enqueue_style( 'visualpay-payment', VISUALPAY_WC_URL . 'assets/css/payment.css', array( 'bootstrap-5' ), VISUALPAY_WC_VERSION );

		wp_enqueue_script( 'bootstrap-5', $vendor . 'bootstrap.bundle.min.js', array(), '5.3.3', true );
		wp_enqueue_script( 'qrcode-js', $vendor . 'qrcode.min.js', array(), '1.5.1', true );
		wp_enqueue_script( 'visualpay-payment', VISUALPAY_WC_URL . 'assets/js/payment.js', array( 'jquery', 'bootstrap-5', 'qrcode-js' ), VISUALPAY_WC_VERSION, true );

		$existing = VisualPay_Transaction_Store::get_by_order_id( $order->get_id() );
		$existing_payload = null;
		if ( $existing && ! empty( $existing->tracking_code ) && 'pending' === strtolower( (string) $existing->status ) ) {
			$existing_payload = array(
				'tracking_code'   => $existing->tracking_code,
				'status'          => $existing->status,
				'currency_symbol' => $existing->currency_symbol,
				'currency_name'   => $existing->currency_name,
				'network_name'    => $existing->network_name,
				'network_code'    => $existing->network_code,
				'wallet_address'  => $existing->wallet_address,
				'pay_amount'      => $existing->expected_amount,
				'expires_at'      => $existing->expires_at ? strtotime( $existing->expires_at . ' UTC' ) : null,
			);
			if ( $existing->raw_create_response ) {
				$decoded = json_decode( $existing->raw_create_response, true );
				if ( is_array( $decoded ) ) {
					$existing_payload = array_merge( $existing_payload, $decoded );
				}
			}
			$existing_payload = VisualPay_Security::sanitize_create_response_for_client( $existing_payload );
		}

		wp_localize_script(
			'visualpay-payment',
			'VisualPayPay',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'visualpay_pay_' . $order->get_id() ),
				'orderId'     => $order->get_id(),
				'orderNumber' => $order->get_order_number(),
				'orderKey'    => $order->get_order_key(),
				// Display-only. Real charge amount is always computed server-side.
				'amountUsd'   => VisualPay_Order_Handler::get_amount_usd( $order ),
				'backUrl'     => self::get_back_url( $order ),
				'thankYouUrl' => $order->get_checkout_order_received_url(),
				'pollMs'      => 15000,
				'ttlMinutes'  => (int) VisualPay_Settings::get( 'ttl', 15 ),
				'isRtl'       => VisualPay_I18n::is_rtl(),
				'i18n'        => VisualPay_I18n::payment_js_strings(),
				'existingTx'  => $existing_payload,
			)
		);
	}

	/**
	 * Security headers for the standalone payment page.
	 */
	private static function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
		header( "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" );
	}

	/**
	 * Request path relative to the WordPress home path.
	 *
	 * @return string
	 */
	private static function request_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

		return self::normalize_request_path( $path );
	}

	/**
	 * Strip the site home path prefix when WordPress runs in a subdirectory.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private static function normalize_request_path( $path ) {
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' === $home_path ) {
			return $path;
		}

		if ( $path === $home_path ) {
			return '';
		}

		$prefix = $home_path . '/';
		if ( 0 === strpos( $path, $prefix ) ) {
			return substr( $path, strlen( $prefix ) );
		}

		return $path;
	}

	/**
	 * Whether the request path targets the VisualPay payment route.
	 *
	 * @param string $path Request path.
	 * @return bool
	 */
	private static function is_route_path( $path ) {
		return in_array( $path, array( self::ROUTE_SLUG, self::LEGACY_ROUTE_SLUG ), true );
	}
}
