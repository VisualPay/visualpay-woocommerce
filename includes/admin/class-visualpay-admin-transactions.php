<?php
/**
 * Admin Transactions page.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Admin_Transactions
 */
class VisualPay_Admin_Transactions {

	/**
	 * Render page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$table = new VisualPay_Admin_List_Table();
		$table->prepare_items();

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$dashboard    = VisualPay_Dashboard::get_view_data();
		$connected    = ! empty( $dashboard['connected'] );
		$settings_url = admin_url( 'admin.php?page=visualpay-settings' );
		$counts       = VisualPay_Transaction_Store::counts_by_status();
		$total_count  = array_sum( array_map( 'intval', $counts ) );
		$base_url     = admin_url( 'admin.php?page=visualpay-transactions' );

		$tabs = array(
			'all' => array(
				'label' => __( 'All', 'visualpay-for-woocommerce' ),
				'count' => $total_count,
				'url'   => $base_url,
			),
		);

		foreach ( array( 'pending', 'confirmed', 'cancelled', 'expired' ) as $tab_status ) {
			$tabs[ $tab_status ] = array(
				'label' => ucfirst( $tab_status ),
				'count' => isset( $counts[ $tab_status ] ) ? (int) $counts[ $tab_status ] : 0,
				'url'   => add_query_arg( 'status', $tab_status, $base_url ),
			);
		}

		$active_tab = ( $status && isset( $tabs[ $status ] ) ) ? $status : 'all';

		?>
		<div class="wrap visualpay-admin visualpay-transactions-page">
			<div class="visualpay-dashboard visualpay-transactions-shell">
				<div class="visualpay-dashboard__topbar">
					<div class="visualpay-dashboard__brand">
						<div class="visualpay-dashboard__mark" aria-hidden="true"><span class="dashicons dashicons-list-view"></span></div>
						<div class="visualpay-dashboard__brand-text visualpay-transactions__brand-text">
							<h1><?php esc_html_e( 'Transactions', 'visualpay-for-woocommerce' ); ?></h1>
							<span><?php esc_html_e( 'All crypto payments processed through your store', 'visualpay-for-woocommerce' ); ?></span>
						</div>
					</div>
					<div class="visualpay-dashboard__topbar-right">
						<?php if ( $connected ) : ?>
							<div class="visualpay-dashboard__pill is-connected">
								<span class="visualpay-dashboard__pill-dot" aria-hidden="true"></span>
								<?php esc_html_e( 'Connected', 'visualpay-for-woocommerce' ); ?>
							</div>
						<?php else : ?>
							<a class="visualpay-dashboard__pill is-disconnected" href="<?php echo esc_url( $settings_url ); ?>">
								<span class="visualpay-dashboard__pill-dot" aria-hidden="true"></span>
								<?php esc_html_e( 'Not Connected', 'visualpay-for-woocommerce' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<form method="get" class="visualpay-transactions__form">
					<input type="hidden" name="page" value="visualpay-transactions">

					<div class="visualpay-transactions__toolbar">
						<div class="visualpay-transactions__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter by status', 'visualpay-for-woocommerce' ); ?>">
							<?php foreach ( $tabs as $tab_key => $tab ) : ?>
								<?php
								$tab_url = $tab['url'];
								if ( $search ) {
									$tab_url = add_query_arg( 's', $search, $tab_url );
								}
								?>
								<a
									class="visualpay-transactions__tab<?php echo $active_tab === $tab_key ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( $tab_url ); ?>"
									role="tab"
									aria-selected="<?php echo $active_tab === $tab_key ? 'true' : 'false'; ?>"
								>
									<?php echo esc_html( $tab['label'] ); ?>
									<span class="visualpay-transactions__tab-count">(<?php echo esc_html( (string) $tab['count'] ); ?>)</span>
								</a>
							<?php endforeach; ?>
						</div>

						<div class="visualpay-transactions__search">
							<?php if ( $status ) : ?>
								<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
							<?php endif; ?>
							<label class="screen-reader-text" for="visualpay-transactions-search"><?php esc_html_e( 'Search transactions', 'visualpay-for-woocommerce' ); ?></label>
							<input
								type="search"
								id="visualpay-transactions-search"
								name="s"
								value="<?php echo esc_attr( $search ); ?>"
								placeholder="<?php esc_attr_e( 'Tracking or Order Id', 'visualpay-for-woocommerce' ); ?>"
							>
							<button type="submit" class="visualpay-transactions__search-btn"><?php esc_html_e( 'Search', 'visualpay-for-woocommerce' ); ?></button>
						</div>
					</div>

					<div class="visualpay-transactions__panel">
						<?php $table->display(); ?>
					</div>
				</form>
			</div>
		</div>

		<div id="visualpay-order-modal" class="visualpay-modal" aria-hidden="true">
			<div class="visualpay-modal__backdrop" data-close="1"></div>
			<div class="visualpay-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="visualpay-modal-title">
				<div class="visualpay-modal__header">
					<h2 id="visualpay-modal-title"><?php esc_html_e( 'Order details', 'visualpay-for-woocommerce' ); ?></h2>
					<button type="button" class="visualpay-modal__close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'visualpay-for-woocommerce' ); ?>">&times;</button>
				</div>
				<div class="visualpay-modal__body" id="visualpay-modal-body">
					<p><?php esc_html_e( 'Loading...', 'visualpay-for-woocommerce' ); ?></p>
				</div>
			</div>
		</div>
		<div id="visualpay-toast" class="visualpay-toast" role="status" aria-live="polite" hidden></div>
		<?php
	}
}
