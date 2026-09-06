<?php
/**
 * Frontend AJAX handlers (server-side API proxy).
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Ajax
 */
class VisualPay_Ajax {

	/**
	 * Register hooks.
	 */
	public static function init() {
		$actions = array(
			'visualpay_currencies',
			'visualpay_create_tx',
			'visualpay_status_tx',
			'visualpay_cancel_tx',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, str_replace( 'visualpay_', 'handle_', $action ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, str_replace( 'visualpay_', 'handle_', $action ) ) );
		}

		add_action( 'wp_ajax_visualpay_admin_order_modal', array( __CLASS__, 'handle_admin_order_modal' ) );
		add_action( 'wp_ajax_visualpay_admin_test_api_key', array( __CLASS__, 'handle_admin_test_api_key' ) );
		add_action( 'wp_ajax_visualpay_admin_toggle_gateway', array( __CLASS__, 'handle_admin_toggle_gateway' ) );
	}

	/**
	 * Verify order + nonce from request.
	 *
	 * @return WC_Order|\WP_Error
	 */
	private static function authorize_order_request() {
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
		$key      = isset( $_REQUEST['order_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_key'] ) ) : '';
		$nonce    = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'visualpay_pay_' . $order_id ) ) {
			return new WP_Error( 'visualpay_forbidden', __( 'Invalid security token.', 'visualpay-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			return new WP_Error( 'visualpay_forbidden', __( 'Invalid order.', 'visualpay-for-woocommerce' ), array( 'status' => 403 ) );
		}

		if ( 'visualpay' !== $order->get_payment_method() ) {
			return new WP_Error( 'visualpay_forbidden', __( 'Invalid payment method.', 'visualpay-for-woocommerce' ), array( 'status' => 403 ) );
		}

		return $order;
	}

	/**
	 * Simple per-order rate limit.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $action   Action key.
	 * @param int    $limit    Max hits.
	 * @param int    $window   Seconds.
	 * @return true|\WP_Error
	 */
	private static function rate_limit( $order_id, $action, $limit = 8, $window = 60 ) {
		$key   = 'visualpay_rl_' . $action . '_' . (int) $order_id;
		$hits  = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			return new WP_Error( 'visualpay_rate_limited', __( 'Too many requests. Please wait a moment.', 'visualpay-for-woocommerce' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, $window );
		return true;
	}

	/**
	 * Validate currency/network against merchant currency list from API.
	 *
	 * @param string $currency Currency symbol.
	 * @param string $network  Network code.
	 * @return true|\WP_Error
	 */
	private static function validate_currency_network( $currency, $network ) {
		$client = new VisualPay_API_Client();
		$list   = $client->list_currencies();
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$items = isset( $list['data'] ) && is_array( $list['data'] ) ? $list['data'] : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$sym = isset( $item['currency_symbol'] ) ? (string) $item['currency_symbol'] : '';
			if ( 0 !== strcasecmp( $sym, $currency ) ) {
				continue;
			}
			$networks = isset( $item['supported_networks'] ) && is_array( $item['supported_networks'] ) ? $item['supported_networks'] : array();
			foreach ( $networks as $net ) {
				if ( ! is_array( $net ) ) {
					continue;
				}
				$code = isset( $net['network_code'] ) ? (string) $net['network_code'] : '';
				if ( 0 === strcasecmp( $code, $network ) ) {
					$min = isset( $net['minimum_pay_usd'] )
						? (float) $net['minimum_pay_usd']
						: ( isset( $net['minimum_pay'] ) ? (float) $net['minimum_pay'] : 0 );
					$max = isset( $net['maximum_pay_usd'] )
						? (float) $net['maximum_pay_usd']
						: ( isset( $net['maximum_pay'] ) ? (float) $net['maximum_pay'] : 0 );
					$max_ttl = isset( $net['max_ttl'] ) ? max( 1, absint( $net['max_ttl'] ) ) : 30;
					return array(
						'ok'          => true,
						'minimum_pay' => $min,
						'maximum_pay' => $max,
						'max_ttl'     => $max_ttl,
						'currency'    => $item,
						'network'     => $net,
					);
				}
			}
			return new WP_Error( 'visualpay_invalid_network', __( 'Selected network is not valid for this currency.', 'visualpay-for-woocommerce' ), array( 'status' => 422 ) );
		}

		return new WP_Error( 'visualpay_invalid_currency', __( 'Selected cryptocurrency is not available.', 'visualpay-for-woocommerce' ), array( 'status' => 422 ) );
	}

	/**
	 * JSON error helper.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function send_error( WP_Error $error ) {
		$status = 400;
		$data   = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}
		wp_send_json_error(
			array(
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	/**
	 * Whether API rejected duplicate WooCommerce order_id.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function is_order_id_in_use_error( WP_Error $error ) {
		if ( 'visualpay_api_error' !== $error->get_error_code() ) {
			return false;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['response']['data']['order_id'] ) ) {
			return false;
		}

		$messages = $data['response']['data']['order_id'];
		if ( ! is_array( $messages ) ) {
			return false;
		}

		foreach ( $messages as $message ) {
			if ( false !== stripos( (string) $message, 'already in use' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create transaction; on duplicate API order_id, retry without order_id.
	 *
	 * Status lookup is tracking_code-only per OpenAPI, so recovery no longer polls by order_id.
	 *
	 * @param VisualPay_API_Client $client  API client.
	 * @param WC_Order             $order   Order.
	 * @param array                $payload Create payload.
	 * @return array|\WP_Error
	 */
	private static function create_transaction_with_recovery( VisualPay_API_Client $client, WC_Order $order, array $payload ) {
		$result = $client->create_transaction( $payload );
		if ( ! is_wp_error( $result ) || ! self::is_order_id_in_use_error( $result ) ) {
			return $result;
		}

		$retry = $payload;
		unset( $retry['order_id'] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[VisualPay] order_id in use for order #' . $order->get_id() . '; retrying create without order_id. ttl=' . (int) $retry['ttl'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $client->create_transaction( $retry );
	}

	/**
	 * Fail create when API data is incomplete.
	 *
	 * @param array       $data   API data.
	 * @param array|null  $result Full API response.
	 */
	private static function reject_incomplete_create_data( array $data, $result = null ) {
		$message = __( 'Failed to create transaction', 'visualpay-for-woocommerce' );

		if ( is_array( $result ) && ! empty( $result['message'] ) && is_string( $result['message'] ) ) {
			$message = (string) $result['message'];
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[VisualPay] Incomplete create response: ' . wp_json_encode( $result ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		wp_send_json_error( array( 'message' => $message ), 500 );
	}

	/**
	 * Currencies list.
	 */
	public static function handle_currencies() {
		$order = self::authorize_order_request();
		if ( is_wp_error( $order ) ) {
			self::send_error( $order );
		}

		if ( ! VisualPay_Order_Handler::is_payable( $order ) && ! $order->is_paid() ) {
			wp_send_json_error( array( 'message' => __( 'This order can no longer be paid.', 'visualpay-for-woocommerce' ) ), 403 );
		}

		$client = new VisualPay_API_Client();
		$result = $client->list_currencies();
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success( VisualPay_Security::sanitize_currency_catalog( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() ) );
	}

	/**
	 * Create transaction.
	 *
	 * Amount is NEVER taken from the client — only from locked server-side order total.
	 */
	public static function handle_create_tx() {
		$order = self::authorize_order_request();
		if ( is_wp_error( $order ) ) {
			self::send_error( $order );
		}

		$rl = self::rate_limit( $order->get_id(), 'create', 6, 60 );
		if ( is_wp_error( $rl ) ) {
			self::send_error( $rl );
		}

		if ( ! VisualPay_Order_Handler::is_payable( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This order can no longer be paid.', 'visualpay-for-woocommerce' ) ), 403 );
		}

		$existing = VisualPay_Transaction_Store::get_by_order_id( $order->get_id() );
		if ( $existing && 'pending' === strtolower( (string) $existing->status ) && ! empty( $existing->tracking_code ) ) {
			$payload = $existing->raw_create_response ? json_decode( $existing->raw_create_response, true ) : null;
			if ( is_array( $payload ) ) {
				wp_send_json_success( VisualPay_Security::sanitize_create_response_for_client( $payload ) );
			}
		}

		// Allow retry after cancelled/expired by clearing local pending meta.
		if ( $existing && in_array( strtolower( (string) $existing->status ), array( 'cancelled', 'expired' ), true ) ) {
			$order->delete_meta_data( VisualPay_Order_Handler::META_TRACKING );
			$order->update_meta_data( VisualPay_Order_Handler::META_STATUS, '' );
			$order->save();
		}

		$override_check = VisualPay_Security::reject_create_client_overrides();
		if ( is_wp_error( $override_check ) ) {
			self::send_error( $override_check );
		}

		// Only these fields are accepted from the browser (nonce verified in authorize_order_request()).
		$currency_raw = isset( $_POST['currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in authorize_order_request().
		$network_raw  = isset( $_POST['network_code'] ) ? sanitize_text_field( wp_unslash( $_POST['network_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in authorize_order_request().
		$currency     = VisualPay_Security::sanitize_identifier( $currency_raw );
		$network      = VisualPay_Security::sanitize_identifier( $network_raw );

		if ( '' === $currency || '' === $network ) {
			wp_send_json_error( array( 'message' => __( 'Currency and network are required.', 'visualpay-for-woocommerce' ) ), 422 );
		}

		$validated = self::validate_currency_network( $currency, $network );
		if ( is_wp_error( $validated ) ) {
			self::send_error( $validated );
		}

		// Server-side amount only (locked at Place Order / recalculated if missing).
		if ( '' === $order->get_meta( VisualPay_Order_Handler::META_LOCKED_USD ) ) {
			VisualPay_Order_Handler::lock_amount_usd( $order );
			$order = wc_get_order( $order->get_id() );
		}

		$amount = VisualPay_Order_Handler::get_amount_usd( $order );
		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order amount.', 'visualpay-for-woocommerce' ) ), 422 );
		}

		$min_pay = isset( $validated['minimum_pay'] ) ? (float) $validated['minimum_pay'] : 0;
		if ( $min_pay > 0 && $amount + 0.00001 < $min_pay ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: minimum USD */
						__( 'Minimum amount is $%s', 'visualpay-for-woocommerce' ),
						wc_format_decimal( $min_pay, 2 )
					),
				),
				422
			);
		}

		$max_pay = isset( $validated['maximum_pay'] ) ? (float) $validated['maximum_pay'] : 0;
		if ( $max_pay > 0 && $amount - 0.00001 > $max_pay ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: maximum USD */
						__( 'Maximum amount is $%s', 'visualpay-for-woocommerce' ),
						wc_format_decimal( $max_pay, 2 )
					),
				),
				422
			);
		}

		$email = sanitize_email( $order->get_billing_email() );
		$ttl   = VisualPay_Security::resolve_ttl_minutes(
			VisualPay_Settings::get( 'ttl', 15 ),
			isset( $validated['max_ttl'] ) ? (int) $validated['max_ttl'] : 30
		);

		$ttl_check = VisualPay_Security::validate_client_ttl_hint( $ttl );
		if ( is_wp_error( $ttl_check ) ) {
			self::send_error( $ttl_check );
		}

		// OpenAPI CreateTransactionRequest — do not send callback_url.
		// order_id is optional and must be a 6-digit number (100000–999999) when provided.
		$payload = array(
			'currency_symbol' => $currency,
			'network_code'    => $network,
			'amount_usd'      => $amount,
			'ttl'             => (int) $ttl,
			'comment'         => sprintf(
				/* translators: %s: WooCommerce order number */
				__( 'WooCommerce order #%s', 'visualpay-for-woocommerce' ),
				$order->get_order_number()
			),
		);

		$api_order_id = (int) $order->get_id();
		if ( $api_order_id >= 100000 && $api_order_id <= 999999 ) {
			$payload['order_id'] = $api_order_id;
		}

		if ( $email ) {
			$payload['email'] = $email;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[VisualPay] create payload for order #' . $order->get_id() . ': ' . wp_json_encode( $payload ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$client = new VisualPay_API_Client();
		$result = self::create_transaction_with_recovery( $client, $order, $payload );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$data = VisualPay_Security::normalize_api_transaction_data( $data );
		if ( empty( $data ) || empty( $data['tracking_code'] ) || empty( $data['wallet_address'] ) ) {
			self::reject_incomplete_create_data( $data, $result );
		}

		// Ensure API charged amount matches our locked amount (tolerance from API snapshot).
		$api_usdt = null;
		if ( isset( $data['usdt_amount'] ) && '' !== $data['usdt_amount'] && null !== $data['usdt_amount'] ) {
			$api_usdt = (float) $data['usdt_amount'];
		}

		$tolerance_percent = isset( $data['payment_tolerance_percent'] ) ? (float) $data['payment_tolerance_percent'] : null;
		if ( null !== $api_usdt && ! VisualPay_Security::amount_matches( $amount, $api_usdt, $tolerance_percent ) ) {
			if ( ! empty( $data['tracking_code'] ) ) {
				$client->cancel_transaction( $data['tracking_code'] );
			}
			wp_send_json_error( array( 'message' => __( 'Amount mismatch. Payment aborted for security.', 'visualpay-for-woocommerce' ) ), 409 );
		}

		$data['wallet_address'] = VisualPay_Security::sanitize_wallet_address( isset( $data['wallet_address'] ) ? $data['wallet_address'] : '' );
		if ( empty( $data['wallet_address'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create transaction', 'visualpay-for-woocommerce' ) ), 500 );
		}

		if ( isset( $data['qr_code'] ) ) {
			$data['qr_code'] = VisualPay_Security::sanitize_qr_code( $data['qr_code'] );
		}

		$comment = $payload['comment'];

		VisualPay_Order_Handler::save_create(
			$order,
			$data,
			array(
				'email'   => $email,
				'comment' => $comment,
			)
		);

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', __( 'Cryptocurrency payment pending confirmation.', 'visualpay-for-woocommerce' ) );
		}

		wp_send_json_success( VisualPay_Security::sanitize_create_response_for_client( $data ) );
	}

	/**
	 * Poll status.
	 */
	public static function handle_status_tx() {
		$order = self::authorize_order_request();
		if ( is_wp_error( $order ) ) {
			self::send_error( $order );
		}

		$rl = self::rate_limit( $order->get_id(), 'status', 30, 60 );
		if ( is_wp_error( $rl ) ) {
			self::send_error( $rl );
		}

		$tracking = $order->get_meta( VisualPay_Order_Handler::META_TRACKING );
		if ( ! $tracking ) {
			wp_send_json_error( array( 'message' => __( 'No payment in progress.', 'visualpay-for-woocommerce' ) ), 400 );
		}

		$client = new VisualPay_API_Client();
		$result = $client->get_transaction_status( array( 'tracking_code' => $tracking ) );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$data = VisualPay_Security::normalize_api_transaction_data( $data );
		if ( empty( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'visualpay-for-woocommerce' ) ), 404 );
		}

		// Force tracking binding before applying status.
		if ( ! empty( $data['tracking_code'] ) && ! hash_equals( (string) $tracking, (string) $data['tracking_code'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Transaction mismatch.', 'visualpay-for-woocommerce' ) ), 409 );
		}
		$data['tracking_code'] = $tracking;

		// Polling must never invent "cancelled" — only Cancel payment does that.
		$local_status = strtolower( (string) $order->get_meta( VisualPay_Order_Handler::META_STATUS ) );
		$data         = self::normalize_polled_status( $data, $local_status );

		$status = VisualPay_Order_Handler::apply_status( $order, $data );
		$order  = wc_get_order( $order->get_id() );

		wp_send_json_success(
			VisualPay_Security::sanitize_status_response_for_client(
				array_merge(
					$data,
					array(
						'normalized_status' => $status,
						'thank_you_url'     => $order ? $order->get_checkout_order_received_url() : '',
						'is_paid'           => $order ? $order->is_paid() : false,
					)
				)
			)
		);
	}

	/**
	 * Normalize status from polling / timer expiry.
	 *
	 * Rule: "cancelled" is stored only after Cancel payment confirmation.
	 * API TTL auto-cancel and UI timer=0 are always stored as "expired".
	 *
	 * @param array  $data         Normalized API status payload.
	 * @param string $local_status Current local VisualPay status meta.
	 * @return array
	 */
	private static function normalize_polled_status( array $data, $local_status = '' ) {
		$status       = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : '';
		$local_status = strtolower( (string) $local_status );

		// Never override a successful payment.
		if ( in_array( $status, array( 'confirmed', 'completed', 'paid' ), true ) ) {
			return $data;
		}

		// Already cancelled via Cancel payment — keep it.
		if ( 'cancelled' === $local_status ) {
			$data['status'] = 'cancelled';
			return $data;
		}

		$expires_at = 0;
		if ( ! empty( $data['expires_at'] ) ) {
			$expires_at = absint( $data['expires_at'] );
		} elseif ( ! empty( $data['expired_at'] ) ) {
			$expires_at = absint( $data['expired_at'] );
		}

		$client_expired = isset( $_POST['client_expired'] ) && '1' === (string) wp_unslash( $_POST['client_expired'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$now            = time();

		// UI timer ended: only honor when server TTL has actually elapsed (60s grace).
		if (
			$client_expired
			&& $expires_at > 0
			&& $now >= ( $expires_at - 60 )
			&& in_array( $status, array( 'pending', 'cancelled', 'expired', '' ), true )
		) {
			$data['status'] = 'expired';
			return $data;
		}

		// API often reports TTL expiry as "cancelled" — store as expired instead.
		if ( 'cancelled' === $status ) {
			$data['status'] = 'expired';
			return $data;
		}

		// Regular polls: expire once server time passes TTL while still pending.
		if ( 'pending' === $status && $expires_at > 0 && $now >= $expires_at ) {
			$data['status'] = 'expired';
		}

		return $data;
	}

	/**
	 * Cancel transaction (only path that may store status=cancelled).
	 */
	public static function handle_cancel_tx() {
		$order = self::authorize_order_request();
		if ( is_wp_error( $order ) ) {
			self::send_error( $order );
		}

		$rl = self::rate_limit( $order->get_id(), 'cancel', 6, 60 );
		if ( is_wp_error( $rl ) ) {
			self::send_error( $rl );
		}

		if ( $order->is_paid() ) {
			wp_send_json_error( array( 'message' => __( 'Paid orders cannot be cancelled.', 'visualpay-for-woocommerce' ) ), 400 );
		}

		$tracking = $order->get_meta( VisualPay_Order_Handler::META_TRACKING );
		if ( ! $tracking ) {
			wp_send_json_error( array( 'message' => __( 'No pending transaction to cancel.', 'visualpay-for-woocommerce' ) ), 400 );
		}

		$client = new VisualPay_API_Client();
		$result = $client->cancel_transaction( $tracking );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$data = array(
			'status'               => 'cancelled',
			'tracking_code'        => $tracking,
			'is_canceled_by_user'  => true,
		);

		$status_result = $client->get_transaction_status( array( 'tracking_code' => $tracking ) );
		if ( ! is_wp_error( $status_result ) && ! empty( $status_result['data'] ) && is_array( $status_result['data'] ) ) {
			$data = VisualPay_Security::normalize_api_transaction_data( $status_result['data'] );
			$data['tracking_code']       = $tracking;
			$data['status']              = 'cancelled';
			$data['is_canceled_by_user'] = true;
		}

		VisualPay_Order_Handler::apply_status( $order, $data );
		wp_send_json_success( VisualPay_Security::sanitize_status_response_for_client( $data ) );
	}

	/**
	 * Admin modal: order details for a transaction row.
	 */
	public static function handle_admin_order_modal() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'visualpay-for-woocommerce' ) ), 403 );
		}

		check_ajax_referer( 'visualpay_admin', 'nonce' );

		$tx_id    = isset( $_POST['tx_row_id'] ) ? absint( $_POST['tx_row_id'] ) : 0;
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		$row = null;
		if ( $tx_id ) {
			$row = VisualPay_Transaction_Store::get_by_id( $tx_id );
			if ( $row ) {
				$order_id = (int) $row->order_id;
			}
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'visualpay-for-woocommerce' ) ), 404 );
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'  => $item->get_name(),
				'qty'   => $item->get_quantity(),
				'total' => wp_strip_all_tags( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			);
		}

		wp_send_json_success(
			array(
				'order'       => array(
					'id'             => $order->get_id(),
					'number'         => $order->get_order_number(),
					'status'         => $order->get_status(),
					'total'          => wp_strip_all_tags( $order->get_formatted_order_total() ),
					'currency'       => $order->get_currency(),
					'payment_method' => $order->get_payment_method_title(),
					'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
					'billing_name'   => trim( $order->get_formatted_billing_full_name() ),
					'billing_email'  => $order->get_billing_email(),
					'edit_url'       => $order->get_edit_order_url(),
					'items'          => $items,
				),
				'transaction' => $row ? array(
					'id'              => (int) $row->id,
					'tracking_code'   => $row->tracking_code,
					'tx_id'           => $row->tx_id,
					'tx_id_url'       => $row->tx_id_url,
					'status'          => $row->status,
					'usdt_amount'     => $row->usdt_amount,
					'paid_amount'     => $row->paid_amount,
					'currency_symbol' => $row->currency_symbol,
					'currency_name'   => $row->currency_name,
					'network_name'    => $row->network_name,
					'created_at'      => $row->created_at,
					'paid_at'         => $row->paid_at,
				) : null,
			)
		);
	}

	/**
	 * Admin: test merchant API key via currencies list.
	 */
	public static function handle_admin_test_api_key() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}

		check_ajax_referer( 'visualpay_admin', 'nonce' );

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' === $api_key ) {
			$api_key = (string) VisualPay_Settings::get( 'api_key', '' );
		}

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => 'Test failed. Plugin could not connect to VisualPay.' ) );
		}

		$client = new VisualPay_API_Client( null, $api_key );
		$result = $client->list_currencies();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => 'Test failed. Plugin could not connect to VisualPay.' ) );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data ) ) {
			wp_send_json_error( array( 'message' => 'Test failed. Plugin could not connect to VisualPay.' ) );
		}

		wp_send_json_success( array( 'message' => 'Test completed successfully. Plugin connected to VisualPay.' ) );
	}

	/**
	 * Admin: toggle gateway enabled state.
	 */
	public static function handle_admin_toggle_gateway() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}

		check_ajax_referer( 'visualpay_admin', 'nonce' );

		$enabled = isset( $_POST['enabled'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 'yes' : 'no';

		VisualPay_Settings::update(
			array(
				'enabled' => $enabled,
			)
		);

		wp_send_json_success(
			array(
				'message' => 'yes' === $enabled ? 'VisualPay plugin enabled' : 'VisualPay plugin disabled.',
				'enabled' => $enabled,
			)
		);
	}
}
