<?php
/**
 * VisualPay Merchant API client.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_API_Client
 */
class VisualPay_API_Client {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $base_url Base URL.
	 * @param string|null $api_key  API key.
	 */
	public function __construct( $base_url = null, $api_key = null ) {
		$resolved_base  = $base_url ? $base_url : VisualPay_Settings::get( 'api_base' );
		$this->base_url = VisualPay_Security::normalize_api_base( is_string( $resolved_base ) ? $resolved_base : '' );
		$this->api_key  = $api_key ? $api_key : (string) VisualPay_Settings::get( 'api_key', '' );
	}

	/**
	 * Whether client is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->api_key
			&& '' !== $this->base_url
			&& 0 === stripos( $this->base_url, 'https://' );
	}

	/**
	 * List currencies.
	 *
	 * @return array|\WP_Error
	 */
	public function list_currencies() {
		return $this->request( 'GET', '/api/v1/merchant/currencies/list' );
	}

	/**
	 * Get balances.
	 *
	 * Note: balance endpoint is not part of the current Merchant OpenAPI surface;
	 * kept for optional admin diagnostics when the server still exposes it.
	 *
	 * @return array|\WP_Error
	 */
	public function get_balance() {
		return $this->request( 'GET', '/api/v1/merchant/balance' );
	}

	/**
	 * Create transaction.
	 *
	 * Body fields (OpenAPI CreateTransactionRequest):
	 * currency_symbol, network_code, amount_usd, ttl (required);
	 * order_id (optional 6-digit), email, comment, callback_url (optional — plugin does not send callback_url).
	 *
	 * @param array $payload Request body.
	 * @return array|\WP_Error
	 */
	public function create_transaction( array $payload ) {
		// Never send callback_url from the WooCommerce plugin.
		unset( $payload['callback_url'] );

		return $this->request( 'POST', '/api/v1/merchant/transaction/create', $payload );
	}

	/**
	 * Check transaction status.
	 *
	 * OpenAPI: lookup by tracking_code only.
	 *
	 * @param array $params Query params; tracking_code required.
	 * @return array|\WP_Error
	 */
	public function get_transaction_status( array $params ) {
		if ( empty( $params['tracking_code'] ) ) {
			return new WP_Error(
				'visualpay_missing_status_param',
				__( 'tracking_code is required to check transaction status.', 'visualpay-for-woocommerce' )
			);
		}

		return $this->request(
			'GET',
			'/api/v1/merchant/transaction/status',
			null,
			array(
				'tracking_code' => (string) $params['tracking_code'],
			)
		);
	}

	/**
	 * Recheck an expired/cancelled on-chain payment.
	 *
	 * @param string $tracking_code Tracking code.
	 * @return array|\WP_Error
	 */
	public function recheck_transaction( $tracking_code ) {
		return $this->request(
			'GET',
			'/api/v1/merchant/transaction/recheck',
			null,
			array(
				'tracking_code' => (string) $tracking_code,
			)
		);
	}

	/**
	 * Cancel transaction.
	 *
	 * @param string $tracking_code Tracking code.
	 * @return array|\WP_Error
	 */
	public function cancel_transaction( $tracking_code ) {
		return $this->request(
			'POST',
			'/api/v1/merchant/transaction/cancel',
			array( 'tracking_code' => $tracking_code )
		);
	}

	/**
	 * List transactions from API.
	 *
	 * @param array $params Query params.
	 * @return array|\WP_Error
	 */
	public function list_transactions( array $params = array() ) {
		return $this->request( 'GET', '/api/v1/merchant/transaction/list', null, $params );
	}

	/**
	 * Perform HTTP request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query string.
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, $body = null, array $query = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'visualpay_not_configured', __( 'VisualPay API key is not configured.', 'visualpay-for-woocommerce' ) );
		}

		if ( 0 !== stripos( $this->base_url, 'https://' ) ) {
			return new WP_Error( 'visualpay_insecure_api', __( 'VisualPay API base URL must use HTTPS.', 'visualpay-for-woocommerce' ) );
		}

		$url = $this->base_url . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'x-api-key'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'visualpay_invalid_response',
				__( 'Invalid response from VisualPay API.', 'visualpay-for-woocommerce' ),
				array(
					'http_code' => $code,
					'body'      => $raw,
				)
			);
		}

		if ( $code >= 400 || ( isset( $data['status'] ) && (int) $data['status'] >= 400 ) ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : __( 'VisualPay API error.', 'visualpay-for-woocommerce' );

			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				$first = reset( $data['data'] );
				if ( is_array( $first ) && isset( $first[0] ) ) {
					$message = (string) $first[0];
				}
			}

			return new WP_Error(
				'visualpay_api_error',
				$message,
				array(
					'http_code' => $code,
					'response'  => $data,
				)
			);
		}

		return $data;
	}
}
