<?php
/**
 * Admin Overview page.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Admin_Overview
 */
class VisualPay_Admin_Overview {

	/**
	 * Render page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$data         = VisualPay_Dashboard::get_view_data();
		$chart_points = wp_list_pluck( $data['chart'], 'total' );
		$settings_url = admin_url( 'admin.php?page=visualpay-settings' );

		?>
		<script type="application/json" id="visualpay-dashboard-data"><?php echo wp_json_encode( array( 'chartPoints' => array_map( 'floatval', $chart_points ) ) ); ?></script>
		<div class="wrap visualpay-admin visualpay-overview">
			<div class="visualpay-dashboard">
				<div class="visualpay-dashboard__topbar">
					<div class="visualpay-dashboard__brand">
						<div class="visualpay-dashboard__mark" aria-hidden="true">
							<img
								class="visualpay-dashboard__mark-logo"
								src="<?php echo esc_url( VISUALPAY_WC_URL . 'assets/img/logo.png' ); ?>"
								alt=""
								width="22"
								height="22"
							>
						</div>
						<div class="visualpay-dashboard__brand-text">
							<h1><?php esc_html_e( 'VisualPay', 'visualpay-for-woocommerce' ); ?></h1>
							<span><?php esc_html_e( 'Crypto Payment Gateway for WooCommerce', 'visualpay-for-woocommerce' ); ?></span>
						</div>
					</div>
					<div class="visualpay-dashboard__topbar-right">
						<?php if ( $data['connected'] ) : ?>
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

				<div class="visualpay-dashboard__section-head">
					<h2><?php esc_html_e( 'Supported Cryptocurrencies', 'visualpay-for-woocommerce' ); ?></h2>
					<p>
						<?php
						if ( $data['connected'] && ! empty( $data['currencies'] ) ) {
							printf(
								/* translators: 1: coin count, 2: network count */
								esc_html__( '%1$d coins · %2$d networks', 'visualpay-for-woocommerce' ),
								(int) $data['coin_count'],
								(int) $data['net_count']
							);
						} elseif ( ! $data['connected'] ) {
							esc_html_e( 'Connect your API key to load supported currencies.', 'visualpay-for-woocommerce' );
						} else {
							esc_html_e( 'No currencies returned.', 'visualpay-for-woocommerce' );
						}
						?>
					</p>
				</div>
				<div class="visualpay-dashboard__panel visualpay-dashboard__coins-panel">
					<?php if ( ! empty( $data['currencies'] ) ) : ?>
						<?php
						$coin_count   = count( $data['currencies'] );
						$is_scrollable = $coin_count > 5;
						?>
						<div
							class="visualpay-dashboard__coin-carousel<?php echo $is_scrollable ? ' is-scrollable' : ''; ?>"
							data-visible="5"
						>
							<button
								type="button"
								class="visualpay-dashboard__coin-nav is-prev"
								aria-label="<?php esc_attr_e( 'Previous currencies', 'visualpay-for-woocommerce' ); ?>"
								<?php disabled( true ); ?>
							>
								<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
							</button>
							<div class="visualpay-dashboard__coin-viewport">
								<div class="visualpay-dashboard__coin-track">
									<?php foreach ( $data['currencies'] as $coin ) : ?>
										<div class="visualpay-dashboard__coin">
											<div class="visualpay-dashboard__coin-icon"<?php echo empty( $coin['icon'] ) ? ' style="background: ' . esc_attr( $coin['color'] ) . ';"' : ''; ?>>
												<?php if ( ! empty( $coin['icon'] ) ) : ?>
													<img
														src="<?php echo esc_url( $coin['icon'] ); ?>"
														alt="<?php echo esc_attr( $coin['symbol'] ); ?>"
														width="32"
														height="32"
														loading="lazy"
														decoding="async"
													>
												<?php else : ?>
													<?php echo esc_html( substr( $coin['symbol'], 0, 1 ) ); ?>
												<?php endif; ?>
											</div>
											<div class="visualpay-dashboard__coin-info">
												<div class="visualpay-dashboard__coin-name">
													<?php echo esc_html( $coin['symbol'] ); ?>
													<span>— <?php echo esc_html( $coin['name'] ); ?></span>
												</div>
												<div class="visualpay-dashboard__coin-net">
													<span class="visualpay-dashboard__net-badge"><?php echo esc_html( $coin['network'] ); ?></span>
												</div>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
							<button
								type="button"
								class="visualpay-dashboard__coin-nav is-next"
								aria-label="<?php esc_attr_e( 'Next currencies', 'visualpay-for-woocommerce' ); ?>"
								<?php disabled( ! $is_scrollable ); ?>
							>
								<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							</button>
						</div>
					<?php elseif ( ! $data['connected'] ) : ?>
						<p class="visualpay-dashboard__empty">
							<span class="visualpay-dashboard__empty-link">
								<span class="dashicons dashicons-warning visualpay-dashboard__empty-icon" aria-hidden="true"></span>
								<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Check your x-api-key in Settings', 'visualpay-for-woocommerce' ); ?></a>
							</span>
						</p>
					<?php else : ?>
						<p class="visualpay-dashboard__empty"><?php esc_html_e( 'No supported currencies returned.', 'visualpay-for-woocommerce' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="visualpay-dashboard__chart-card">
					<div class="visualpay-dashboard__chart-head">
						<h2><?php esc_html_e( 'Last 30 Days Revenue', 'visualpay-for-woocommerce' ); ?></h2>
						<div class="visualpay-dashboard__chart-total">
							<?php esc_html_e( 'Period total:', 'visualpay-for-woocommerce' ); ?>
							<b>$<?php echo esc_html( number_format( (float) $data['chart_total'], 2 ) ); ?></b>
						</div>
					</div>
					<svg class="visualpay-dashboard__chart-svg" viewBox="0 0 1000 220" preserveAspectRatio="none" id="visualpay-chart-svg" role="img" aria-label="<?php esc_attr_e( 'Last 30 days revenue chart', 'visualpay-for-woocommerce' ); ?>"></svg>
				</div>

				<div class="visualpay-dashboard__section-head">
					<h2><?php esc_html_e( 'Transactions', 'visualpay-for-woocommerce' ); ?></h2>
				</div>
				<div class="visualpay-dashboard__grid-4">
					<?php foreach ( $data['tx_stats'] as $stat ) : ?>
						<div class="visualpay-dashboard__stat-card">
							<div class="visualpay-dashboard__stat-top">
								<span class="visualpay-dashboard__stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
							</div>
							<div class="visualpay-dashboard__stat-value mono"><?php echo esc_html( (string) $stat['total'] ); ?></div>
							<div class="visualpay-dashboard__status-row">
								<?php if ( 'today' === $stat['key'] ) : ?>
									<div class="visualpay-dashboard__status-item">
										<span class="visualpay-dashboard__status-dot is-pending" aria-hidden="true"></span>
										<?php esc_html_e( 'Pending', 'visualpay-for-woocommerce' ); ?>
										<b><?php echo esc_html( (string) $stat['pending'] ); ?></b>
									</div>
								<?php endif; ?>
								<div class="visualpay-dashboard__status-item">
									<span class="visualpay-dashboard__status-dot is-confirmed" aria-hidden="true"></span>
									<?php esc_html_e( 'Confirmed', 'visualpay-for-woocommerce' ); ?>
									<b><?php echo esc_html( (string) $stat['confirmed'] ); ?></b>
								</div>
								<div class="visualpay-dashboard__status-item">
									<span class="visualpay-dashboard__status-dot is-cancelled" aria-hidden="true"></span>
									<?php esc_html_e( 'Cancelled', 'visualpay-for-woocommerce' ); ?>
									<b><?php echo esc_html( (string) $stat['cancelled'] ); ?></b>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="visualpay-dashboard__section-head">
					<h2><?php esc_html_e( 'Revenue', 'visualpay-for-woocommerce' ); ?></h2>
				</div>
				<div class="visualpay-dashboard__grid-4">
					<?php foreach ( $data['rev_stats'] as $rev ) : ?>
						<div class="visualpay-dashboard__stat-card">
							<div class="visualpay-dashboard__stat-top">
								<span class="visualpay-dashboard__stat-label"><?php echo esc_html( $rev['label'] ); ?></span>
								<span class="visualpay-dashboard__growth is-<?php echo esc_attr( $rev['dir'] ); ?>" aria-hidden="true">
									<?php
									echo esc_html(
										'up' === $rev['dir'] ? '▲' : ( 'down' === $rev['dir'] ? '▼' : '—' )
									);
									?>
								</span>
							</div>
							<div class="visualpay-dashboard__stat-value mono"><?php echo esc_html( $rev['value'] ); ?></div>
							<div class="visualpay-dashboard__status-row visualpay-dashboard__status-row--flat">
								<span class="visualpay-dashboard__growth is-<?php echo esc_attr( $rev['dir'] ); ?>"><?php echo esc_html( $rev['growth'] ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
