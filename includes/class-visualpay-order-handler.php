<?php
/**
 * Order meta and payment completion.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Order_Handler
 */
class VisualPay_Order_Handler {

	const META_TRACKING   = '_visualpay_tracking_code';
	const META_TX_ID      = '_visualpay_tx_id';
	const META_TX_URL     = '_visualpay_tx_id_url';
	const META_USDT       = '_visualpay_usdt_amount';
	const META_PAID       = '_visualpay_paid_amount';
	const META_CURRENCY   = '_visualpay_currency_symbol';
	const META_NETWORK    = '_visualpay_network_code';
	const META_NETWORK_NAME = '_visualpay_network_name';
	const META_WALLET     = '_visualpay_wallet_address';
	const META_STATUS     = '_visualpay_status';
	const META_RESPONSE   = '_visualpay_response';
	const META_EXPECTED   = '_visualpay_expected_amount';
	const META_BASE       = '_visualpay_base_amount';
	const META_FEE        = '_visualpay_network_fee';
	const META_LOCKED_USD = '_visualpay_locked_usd';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin_payment_box' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer_payment_box' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'add_order_item_totals' ), 10, 2 );
	}

	/**
	 * Whether the order can still start/continue a VisualPay payment.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_payable( WC_Order $order ) {
		if ( 'visualpay' !== $order->get_payment_method() ) {
			return false;
		}
		if ( $order->is_paid() ) {
			return false;
		}
		if ( $order->has_status( array( 'refunded', 'completed', 'processing' ) ) ) {
			return false;
		}
		if ( $order->has_status( array( 'cancelled', 'failed' ) ) ) {
			$status = strtolower( (string) $order->get_meta( self::META_STATUS ) );
			return in_array( $status, array( 'cancelled', 'expired', '' ), true );
		}
		return $order->has_status( array( 'pending', 'on-hold' ) );
	}

	/**
	 * Lock the USD/USDT amount on the order (server-side, never from client).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function lock_amount_usd( WC_Order $order ) {
		$amount = self::calculate_amount_usd( $order );
		$order->update_meta_data( self::META_LOCKED_USD, wc_format_decimal( $amount, 2 ) );
		$order->save();
		return $amount;
	}

	/**
	 * Calculate USD amount from order total (store prices are USD).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function calculate_amount_usd( WC_Order $order ) {
		return round( max( 0, (float) $order->get_total() ), 2 );
	}

	/**
	 * Amount in USD/USDT for VisualPay API.
	 *
	 * Prefers locked server-side amount so Inspect Element / client JS cannot change it.
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function get_amount_usd( WC_Order $order ) {
		$locked = $order->get_meta( self::META_LOCKED_USD );
		if ( '' !== $locked && null !== $locked && false !== $locked ) {
			$amount = round( max( 0, (float) $locked ), 2 );
		} else {
			$amount = self::calculate_amount_usd( $order );
		}

		/**
		 * Filter VisualPay amount_usd sent to API.
		 *
		 * @param float    $amount Order total in USD.
		 * @param WC_Order $order  Order.
		 */
		return (float) apply_filters( 'visualpay_amount_usd', $amount, $order );
	}

	/**
	 * Save create response onto order + local DB.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Create data.
	 * @param array    $extra Extra.
	 */
	public static function save_create( WC_Order $order, array $data, array $extra = array() ) {
		$order_id = $order->get_id();

		$order->update_meta_data( self::META_TRACKING, isset( $data['tracking_code'] ) ? $data['tracking_code'] : '' );
		$order->update_meta_data( self::META_STATUS, isset( $data['status'] ) ? strtolower( $data['status'] ) : 'pending' );
		$order->update_meta_data( self::META_CURRENCY, isset( $data['currency_symbol'] ) ? $data['currency_symbol'] : '' );
		$order->update_meta_data( self::META_NETWORK, isset( $data['network_code'] ) ? $data['network_code'] : '' );
		$order->update_meta_data( self::META_NETWORK_NAME, isset( $data['network_name'] ) ? $data['network_name'] : '' );
		$order->update_meta_data( self::META_WALLET, isset( $data['wallet_address'] ) ? $data['wallet_address'] : '' );
		$order->update_meta_data( self::META_USDT, isset( $data['usdt_amount'] ) ? $data['usdt_amount'] : '' );
		$order->update_meta_data( self::META_EXPECTED, isset( $data['expected_amount'] ) ? $data['expected_amount'] : '' );
		$order->update_meta_data( self::META_BASE, isset( $data['base_amount'] ) ? $data['base_amount'] : '' );
		$order->update_meta_data( self::META_FEE, isset( $data['network_fee'] ) ? $data['network_fee'] : '' );
		$order->update_meta_data( self::META_RESPONSE, wp_json_encode( $data ) );
		$order->save();

		VisualPay_Transaction_Store::upsert_from_create( $order_id, $data, $extra );

		$order->add_order_note(
			sprintf(
				/* translators: 1: tracking code 2: currency 3: network */
				__( 'VisualPay payment created. Tracking: %1$s | %2$s / %3$s', 'visualpay-for-woocommerce' ),
				isset( $data['tracking_code'] ) ? $data['tracking_code'] : '-',
				isset( $data['currency_symbol'] ) ? $data['currency_symbol'] : '-',
				isset( $data['network_name'] ) ? $data['network_name'] : '-'
			)
		);
	}

	/**
	 * Apply status response; complete order if confirmed.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Status payload.
	 * @return string Normalized status.
	 */
	public static function apply_status( WC_Order $order, array $data ) {
		$status = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : '';

		// Bind status updates to this order's tracking / order_id — reject mismatched payloads.
		$known_tracking = (string) $order->get_meta( self::META_TRACKING );
		$resp_tracking  = isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : '';
		$resp_order_id  = isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;

		if ( $known_tracking && $resp_tracking && ! hash_equals( $known_tracking, $resp_tracking ) ) {
			return $status ? $status : 'mismatch';
		}
		if ( $resp_order_id && (int) $resp_order_id !== (int) $order->get_id() ) {
			return $status ? $status : 'mismatch';
		}

		$order->update_meta_data( self::META_STATUS, $status );
		$order->update_meta_data( self::META_RESPONSE, wp_json_encode( $data ) );

		if ( ! empty( $data['tracking_code'] ) && ! $known_tracking ) {
			$order->update_meta_data( self::META_TRACKING, $data['tracking_code'] );
		}
		if ( ! empty( $data['tx_id'] ) ) {
			$order->update_meta_data( self::META_TX_ID, $data['tx_id'] );
			$order->set_transaction_id( $data['tx_id'] );
		}
		if ( ! empty( $data['tx_id_url'] ) ) {
			$order->update_meta_data( self::META_TX_URL, $data['tx_id_url'] );
		} elseif ( ! empty( $data['tx_explorer_url'] ) ) {
			$order->update_meta_data( self::META_TX_URL, $data['tx_explorer_url'] );
		}
		if ( isset( $data['usdt_amount'] ) && '' !== $data['usdt_amount'] && null !== $data['usdt_amount'] ) {
			$order->update_meta_data( self::META_USDT, $data['usdt_amount'] );
		}
		if ( isset( $data['paid_amount'] ) && '' !== $data['paid_amount'] && null !== $data['paid_amount'] ) {
			$order->update_meta_data( self::META_PAID, $data['paid_amount'] );
		}
		if ( ! empty( $data['network_code'] ) ) {
			$order->update_meta_data( self::META_NETWORK, $data['network_code'] );
		}
		if ( ! empty( $data['network_name'] ) ) {
			$order->update_meta_data( self::META_NETWORK_NAME, $data['network_name'] );
		}
		if ( ! empty( $data['currency_name'] ) || ! empty( $data['currency_code'] ) ) {
			$sym = ! empty( $data['currency_symbol'] ) ? $data['currency_symbol'] : ( isset( $data['currency_code'] ) ? $data['currency_code'] : '' );
			if ( $sym ) {
				$order->update_meta_data( self::META_CURRENCY, $sym );
			}
		}

		VisualPay_Transaction_Store::update_from_status( $order->get_id(), $data );

		if ( 'confirmed' === $status ) {
			// Only complete when we have a tracking code tied to this order.
			$tracking_ok = $known_tracking || $resp_tracking;
			if ( $tracking_ok && ! $order->is_paid() ) {
				$tx = ! empty( $data['tx_id'] ) ? $data['tx_id'] : '';
				$order->payment_complete( $tx );
				$order->add_order_note(
					sprintf(
						/* translators: 1: tx id 2: usdt amount */
						__( 'VisualPay payment confirmed. TX: %1$s | USD: %2$s', 'visualpay-for-woocommerce' ),
						$tx ? $tx : '-',
						isset( $data['usdt_amount'] ) ? $data['usdt_amount'] : '-'
					)
				);
			}
		} elseif ( 'cancelled' === $status || 'expired' === $status ) {
			if ( ! $order->has_status( array( 'cancelled', 'failed', 'completed', 'processing', 'refunded' ) ) ) {
				$order->update_status(
					'cancelled' === $status ? 'cancelled' : 'failed',
					sprintf(
						/* translators: %s: status */
						__( 'VisualPay payment %s.', 'visualpay-for-woocommerce' ),
						$status
					)
				);
			}
		}

		$order->save();
		return $status;
	}

	/**
	 * Admin order meta box content.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_admin_payment_box( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'visualpay' !== $order->get_payment_method() ) {
			return;
		}

		$status   = $order->get_meta( self::META_STATUS );
		$tx_id    = $order->get_meta( self::META_TX_ID );
		$tx_url   = $order->get_meta( self::META_TX_URL );
		$usdt     = $order->get_meta( self::META_USDT );
		$paid     = $order->get_meta( self::META_PAID );
		$currency = $order->get_meta( self::META_CURRENCY );
		$network  = $order->get_meta( self::META_NETWORK_NAME );
		$tracking = $order->get_meta( self::META_TRACKING );
		$wallet   = $order->get_meta( self::META_WALLET );

		echo '<div class="visualpay-admin-order-box" style="margin-top:12px;padding:12px;background:#f8f9fa;border:1px solid #dcdcde;border-radius:4px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'VisualPay Payment', 'visualpay-for-woocommerce' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Method', 'visualpay-for-woocommerce' ) . ':</strong> Cryptocurrency</p>';
		echo '<p><strong>' . esc_html__( 'Status', 'visualpay-for-woocommerce' ) . ':</strong> ' . esc_html( $status ? $status : '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'USD Amount', 'visualpay-for-woocommerce' ) . ':</strong> ' . esc_html( $usdt ? $usdt : '-' ) . '</p>';
		if ( $paid ) {
			echo '<p><strong>' . esc_html__( 'Paid Amount', 'visualpay-for-woocommerce' ) . ':</strong> ' . esc_html( $paid ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Currency', 'visualpay-for-woocommerce' ) . ':</strong> ' . esc_html( $currency ? $currency : '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Network', 'visualpay-for-woocommerce' ) . ':</strong> ' . esc_html( $network ? $network : '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Tracking', 'visualpay-for-woocommerce' ) . ':</strong> ' . esc_html( $tracking ? $tracking : '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'TX ID', 'visualpay-for-woocommerce' ) . ':</strong> ';
		if ( $tx_url && $tx_id ) {
			echo '<a href="' . esc_url( $tx_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $tx_id ) . '</a>';
		} else {
			echo esc_html( $tx_id ? $tx_id : '-' );
		}
		echo '</p>';
		if ( $wallet ) {
			echo '<p><strong>' . esc_html__( 'Wallet', 'visualpay-for-woocommerce' ) . ':</strong> <code>' . esc_html( $wallet ) . '</code></p>';
		}
		echo '</div>';
	}

	/**
	 * Customer-facing payment details.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_customer_payment_box( $order ) {
		if ( ! $order instanceof WC_Order || 'visualpay' !== $order->get_payment_method() ) {
			return;
		}
		$tx_id  = $order->get_meta( self::META_TX_ID );
		$tx_url = $order->get_meta( self::META_TX_URL );
		$usdt   = $order->get_meta( self::META_USDT );
		if ( ! $tx_id && ! $usdt ) {
			return;
		}
		echo '<section class="woocommerce-visualpay-payment-details"><h2>' . esc_html__( 'Cryptocurrency Payment', 'visualpay-for-woocommerce' ) . '</h2><table class="woocommerce-table"><tbody>';
		if ( $usdt ) {
			echo '<tr><th>' . esc_html__( 'USD Amount', 'visualpay-for-woocommerce' ) . '</th><td>' . esc_html( $usdt ) . '</td></tr>';
		}
		if ( $tx_id ) {
			echo '<tr><th>' . esc_html__( 'Transaction ID', 'visualpay-for-woocommerce' ) . '</th><td>';
			if ( $tx_url ) {
				echo '<a href="' . esc_url( $tx_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $tx_id ) . '</a>';
			} else {
				echo esc_html( $tx_id );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Add totals rows.
	 *
	 * @param array    $totals Totals.
	 * @param WC_Order $order  Order.
	 * @return array
	 */
	public static function add_order_item_totals( $totals, $order ) {
		if ( ! $order instanceof WC_Order || 'visualpay' !== $order->get_payment_method() ) {
			return $totals;
		}
		$usdt = $order->get_meta( self::META_USDT );
		$tx   = $order->get_meta( self::META_TX_ID );
		if ( $usdt ) {
			$totals['visualpay_usd'] = array(
				'label' => __( 'Crypto USD amount:', 'visualpay-for-woocommerce' ),
				'value' => esc_html( $usdt ),
			);
		}
		if ( $tx ) {
			$totals['visualpay_tx'] = array(
				'label' => __( 'Crypto TX ID:', 'visualpay-for-woocommerce' ),
				'value' => esc_html( $tx ),
			);
		}
		return $totals;
	}
}
