<?php
/**
 * WooCommerce payment gateway.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Gateway
 */
class VisualPay_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'visualpay';
		$this->icon               = VISUALPAY_WC_URL . 'assets/img/logo.png';
		$this->has_fields         = false;
		$this->method_title       = __( 'VisualPay', 'visualpay-for-woocommerce' );
		$this->method_description = __( 'Accept crypto payments via VisualPay Merchant API.', 'visualpay-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$plugin_settings = VisualPay_Settings::all();

		$this->enabled     = isset( $plugin_settings['enabled'] ) ? $plugin_settings['enabled'] : 'no';
		$this->title       = ! empty( $plugin_settings['title'] ) ? $plugin_settings['title'] : __( 'Cryptocurrency', 'visualpay-for-woocommerce' );
		$this->description = ! empty( $plugin_settings['description'] ) ? $plugin_settings['description'] : '';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin form fields (synced with VisualPay settings page).
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'visualpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable VisualPay Cryptocurrency payments', 'visualpay-for-woocommerce' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'visualpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'visualpay-for-woocommerce' ),
				'default'     => __( 'Cryptocurrency', 'visualpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'visualpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', 'visualpay-for-woocommerce' ),
				'default'     => __( 'Pay securely with cryptocurrency via VisualPay.', 'visualpay-for-woocommerce' ),
			),
			'api_notice'  => array(
				'title'       => __( 'API settings', 'visualpay-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: settings URL */
					__( 'Configure your merchant API key under %s.', 'visualpay-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=visualpay-settings' ) ) . '">' . esc_html__( 'VisualPay → Settings', 'visualpay-for-woocommerce' ) . '</a>'
				),
			),
		);
	}

	/**
	 * Persist WC gateway options into VisualPay settings.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$parent = parent::process_admin_options();

		VisualPay_Settings::update(
			array(
				'enabled'     => $this->get_option( 'enabled', 'no' ),
				'title'       => $this->get_option( 'title', 'Cryptocurrency' ),
				'description' => $this->get_option( 'description', '' ),
			)
		);

		return $parent;
	}

	/**
	 * Available only when enabled and API key present.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== VisualPay_Settings::get( 'enabled', 'no' ) ) {
			return false;
		}
		if ( ! VisualPay_Settings::get( 'api_key' ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Start payment: mark order awaiting crypto, redirect to payment page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Invalid order.', 'visualpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Lock USD amount server-side so the payment page cannot change it.
		VisualPay_Order_Handler::lock_amount_usd( $order );

		$order->update_status(
			'pending',
			__( 'Awaiting cryptocurrency payment via VisualPay.', 'visualpay-for-woocommerce' )
		);

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => VisualPay_Payment_Page::get_payment_url( $order ),
		);
	}
}
