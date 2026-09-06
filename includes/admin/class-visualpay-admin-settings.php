<?php
/**
 * Admin Settings page.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Admin_Settings
 */
class VisualPay_Admin_Settings {

	/**
	 * Render settings.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$saved  = false;
		$errors = array();

		if ( isset( $_POST['visualpay_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['visualpay_settings_nonce'] ) ), 'visualpay_save_settings' ) ) {
			$api_key     = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			$ttl         = isset( $_POST['ttl'] ) ? absint( wp_unslash( $_POST['ttl'] ) ) : 15;
			$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Cryptocurrency';
			$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
			$language    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'en';

			$languages = VisualPay_I18n::languages();
			if ( ! array_key_exists( $language, $languages ) ) {
				$language = 'en';
			}

			$current = VisualPay_Settings::all();
			if ( '' === $api_key && ! empty( $current['api_key'] ) ) {
				$api_key = $current['api_key'];
			}

			if ( '' === $api_key ) {
				$errors[] = __( 'x-api-key is required.', 'visualpay-for-woocommerce' );
			}

			if ( $ttl < 5 || $ttl > 60 ) {
				$errors[] = __( 'Transaction TTL must be between 5 and 60.', 'visualpay-for-woocommerce' );
			}

			if ( empty( $errors ) ) {
				VisualPay_Settings::update(
					array(
						'api_key'     => $api_key,
						'ttl'         => $ttl,
						'title'       => $title ? $title : 'Cryptocurrency',
						'description' => $description,
						'language'    => $language,
					)
				);

				$gateway_settings = get_option( 'woocommerce_visualpay_settings', array() );
				if ( ! is_array( $gateway_settings ) ) {
					$gateway_settings = array();
				}
				$gateway_settings['title']       = $title ? $title : 'Cryptocurrency';
				$gateway_settings['description'] = $description;
				update_option( 'woocommerce_visualpay_settings', $gateway_settings );

				flush_rewrite_rules( false );
				$saved = true;
			}
		}

		$settings    = VisualPay_Settings::all();
		$masked_key  = '';
		$has_api_key = ! empty( $settings['api_key'] );

		if ( $has_api_key ) {
			$len        = strlen( $settings['api_key'] );
			$masked_key = $len > 12
				? substr( $settings['api_key'], 0, 6 ) . str_repeat( '•', 12 ) . substr( $settings['api_key'], -4 )
				: str_repeat( '•', $len );
		}

		$key_required = ! $has_api_key;
		$languages    = VisualPay_I18n::languages();
		$toast_attr   = $saved ? ' data-visualpay-toast-success="' . esc_attr__( 'Settings saved.', 'visualpay-for-woocommerce' ) . '"' : '';

		?>
		<div
			class="wrap visualpay-admin visualpay-settings-page"
			<?php echo $toast_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>
		>
			<div class="visualpay-dashboard visualpay-settings-shell">
				<div class="visualpay-dashboard__topbar">
					<div class="visualpay-dashboard__brand">
						<div class="visualpay-dashboard__mark" aria-hidden="true"><span class="dashicons dashicons-admin-generic"></span></div>
						<div class="visualpay-dashboard__brand-text">
							<h1><?php esc_html_e( 'Settings', 'visualpay-for-woocommerce' ); ?></h1>
							<span><?php esc_html_e( 'Configure your WooCommerce payment gateway', 'visualpay-for-woocommerce' ); ?></span>
						</div>
					</div>
					<div class="visualpay-dashboard__topbar-right">
						<div
							class="visualpay-dashboard__pill visualpay-settings-status-pill <?php echo 'yes' === $settings['enabled'] ? 'is-active' : 'is-inactive'; ?>"
							id="visualpay-gateway-status-pill"
						>
							<span class="visualpay-dashboard__pill-dot" aria-hidden="true"></span>
							<span class="visualpay-settings-status__label" id="visualpay-gateway-status-label">
								<?php
								echo 'yes' === $settings['enabled']
									? esc_html__( 'Plugin status : Active', 'visualpay-for-woocommerce' )
									: esc_html__( 'Plugin status : Inactive', 'visualpay-for-woocommerce' );
								?>
							</span>
							<label class="visualpay-settings-switch">
								<span class="screen-reader-text"><?php esc_html_e( 'Toggle plugin status', 'visualpay-for-woocommerce' ); ?></span>
								<input
									type="checkbox"
									id="visualpay-gateway-enabled"
									<?php checked( $settings['enabled'], 'yes' ); ?>
									data-current="<?php echo esc_attr( $settings['enabled'] ); ?>"
								>
								<span class="visualpay-settings-switch__track" aria-hidden="true"></span>
							</label>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $errors ) ) : ?>
					<?php foreach ( $errors as $error ) : ?>
						<div class="visualpay-settings-alert is-error"><?php echo esc_html( $error ); ?></div>
					<?php endforeach; ?>
				<?php endif; ?>

				<form method="post" action="" class="visualpay-settings-form">
					<?php wp_nonce_field( 'visualpay_save_settings', 'visualpay_settings_nonce' ); ?>

					<div class="visualpay-settings-columns">
						<div class="visualpay-settings-row visualpay-settings-row--stack">
							<div class="visualpay-settings-field">
								<div class="visualpay-settings-field__header">
									<label class="visualpay-settings-label" for="visualpay_api_key">
										<?php esc_html_e( 'x-api-key', 'visualpay-for-woocommerce' ); ?>
										<span class="visualpay-required" aria-hidden="true">*</span>
									</label>
									<button type="button" class="visualpay-settings-test-btn" id="visualpay-test-api-key">
										<?php esc_html_e( 'Test', 'visualpay-for-woocommerce' ); ?>
									</button>
								</div>
								<div class="visualpay-password-field">
									<input
										type="password"
										class="regular-text"
										id="visualpay_api_key"
										name="api_key"
										value=""
										autocomplete="off"
										placeholder="<?php echo esc_attr( $masked_key ? $masked_key : __( 'Paste merchant API key', 'visualpay-for-woocommerce' ) ); ?>"
										<?php echo $key_required ? 'required' : ''; ?>
									>
									<button
										type="button"
										class="visualpay-password-toggle"
										aria-label="<?php esc_attr_e( 'Show API key', 'visualpay-for-woocommerce' ); ?>"
										aria-pressed="false"
										data-label-show="<?php esc_attr_e( 'Show API key', 'visualpay-for-woocommerce' ); ?>"
										data-label-hide="<?php esc_attr_e( 'Hide API key', 'visualpay-for-woocommerce' ); ?>"
									>
										<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									</button>
								</div>
							</div>
							<div class="visualpay-settings-field">
								<label class="visualpay-settings-label" for="visualpay_ttl">
									<?php esc_html_e( 'Transaction TTL', 'visualpay-for-woocommerce' ); ?>
									<span class="visualpay-settings-label-hint"><?php esc_html_e( '(minutes)', 'visualpay-for-woocommerce' ); ?></span>
								</label>
								<input
									type="number"
									class="regular-text"
									id="visualpay_ttl"
									name="ttl"
									value="<?php echo esc_attr( (int) $settings['ttl'] ); ?>"
									min="5"
									max="60"
									step="1"
									required
								>
								<p class="description"><?php esc_html_e( 'The entered value is measured in minutes. From the time payment starts, the customer will have this many minutes to pay the invoice.', 'visualpay-for-woocommerce' ); ?></p>
							</div>
						</div>

						<div class="visualpay-settings-row visualpay-settings-row--stack">
							<div class="visualpay-settings-field">
								<label class="visualpay-settings-label" for="visualpay_title"><?php esc_html_e( 'Checkout title', 'visualpay-for-woocommerce' ); ?></label>
								<input
									type="text"
									class="regular-text"
									id="visualpay_title"
									name="title"
									value="<?php echo esc_attr( $settings['title'] ); ?>"
								>
							</div>
							<div class="visualpay-settings-field">
								<label class="visualpay-settings-label" for="visualpay_language"><?php esc_html_e( 'Language', 'visualpay-for-woocommerce' ); ?></label>
								<select class="regular-text" id="visualpay_language" name="language">
									<?php foreach ( $languages as $code => $label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['language'], $code ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Language used on the customer payment page. Default is English.', 'visualpay-for-woocommerce' ); ?></p>
							</div>
						</div>
					</div>

					<div class="visualpay-settings-row">
						<div class="visualpay-settings-col visualpay-settings-col--full">
							<label class="visualpay-settings-label" for="visualpay_description"><?php esc_html_e( 'Checkout description', 'visualpay-for-woocommerce' ); ?></label>
							<textarea
								class="large-text"
								rows="3"
								id="visualpay_description"
								name="description"
							><?php echo esc_textarea( $settings['description'] ); ?></textarea>
						</div>
					</div>

					<div class="visualpay-settings-footer">
						<button type="submit" class="visualpay-settings-submit"><?php esc_html_e( 'Save settings', 'visualpay-for-woocommerce' ); ?></button>
					</div>
				</form>
			</div>
			<div id="visualpay-toast" class="visualpay-toast" role="status" aria-live="polite" hidden></div>
		</div>
		<?php
	}
}
