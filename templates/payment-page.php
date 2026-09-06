<?php
/**
 * Standalone VisualPay payment page template.
 *
 * @package VisualPay_WooCommerce
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

$visualpay_amount_usd = VisualPay_Order_Handler::get_amount_usd( $order );
$visualpay_html_lang  = VisualPay_I18n::html_lang();
$visualpay_html_dir   = VisualPay_I18n::is_rtl() ? 'rtl' : 'ltr';
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( $visualpay_html_lang ); ?>" dir="<?php echo esc_attr( $visualpay_html_dir ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( VisualPay_I18n::t( 'pay_order', $order->get_order_number() ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="visualpay-payment-page<?php echo VisualPay_I18n::is_rtl() ? ' is-rtl' : ''; ?>">
	<div class="alert alert-danger alert-toast" id="toast" role="alert"></div>
	<div class="loading-overlay" id="loading"><div class="spinner-border" role="status"></div></div>

	<div class="page-wrap" id="formView">
		<div class="pay-card">
			<a class="back-link" id="backBtn" href="<?php echo esc_url( VisualPay_Payment_Page::get_back_url( $order ) ); ?>">
				<i class="bi bi-arrow-left"></i> <?php echo esc_html( VisualPay_I18n::t( 'back' ) ); ?>
			</a>
			<h1><?php echo esc_html( VisualPay_I18n::t( 'make_payment' ) ); ?></h1>
			<p class="subtitle">
				<?php echo esc_html( VisualPay_I18n::t( 'order_select_crypto', $order->get_order_number() ) ); ?>
			</p>

			<form id="paymentForm" novalidate>
				<div class="row g-3 form-pair align-items-start">
					<div class="col-md-6" style="margin-top: 0px !important;">
						<div class="form-field">
							<label class="form-label" for="currencySelect">
								<span><?php echo esc_html( VisualPay_I18n::t( 'cryptocurrency' ) ); ?> <span class="req">*</span></span>
							</label>
							<div class="select-with-icon">
								<img class="select-icon d-none" id="currencyIcon" alt="">
								<select class="form-select" id="currencySelect" required>
									<option value=""><?php echo esc_html( VisualPay_I18n::t( 'loading' ) ); ?></option>
								</select>
							</div>
						</div>
					</div>
					<div class="col-md-6" style="margin-top: 0px !important;">
						<div class="form-field">
							<label class="form-label">
								<span><?php echo esc_html( VisualPay_I18n::t( 'amount_usdt' ) ); ?></span>
							</label>
							<div class="amount-wrap amount-locked" aria-live="polite">
								<span class="amount-prefix">$</span>
								<span class="amount-value" id="amountUsd" data-amount="<?php echo esc_attr( wc_format_decimal( $visualpay_amount_usd, 2 ) ); ?>"><?php echo esc_html( wc_format_decimal( $visualpay_amount_usd, 2 ) ); ?></span>
							</div>
							<p class="approx-text" id="approxText"><?php echo esc_html( VisualPay_I18n::t( 'approx_dash' ) ); ?></p>
						</div>
					</div>
				</div>

				<div class="row g-3 form-pair form-pair-network">
					<div class="col-md-6">
						<div class="form-field">
							<label class="form-label" for="networkSelect">
								<span><?php echo esc_html( VisualPay_I18n::t( 'network' ) ); ?> <span class="req">*</span></span>
							</label>
							<div class="select-with-icon">
								<img class="select-icon d-none" id="networkIcon" alt="">
								<select class="form-select" id="networkSelect" required>
									<option value=""><?php echo esc_html( VisualPay_I18n::t( 'select_network' ) ); ?></option>
								</select>
							</div>
							<p class="hint-text"><?php echo esc_html( VisualPay_I18n::t( 'choose_network_hint' ) ); ?></p>
						</div>
					</div>
					<div class="col-md-6">
						<div class="warn-box" id="formWarn">
							<i class="bi bi-exclamation-triangle-fill"></i>
							<div id="formWarnText"><?php echo esc_html( VisualPay_I18n::t( 'select_crypto_warn' ) ); ?></div>
						</div>
						<button type="submit" class="btn btn-pay" id="payBtn">
							<?php echo esc_html( VisualPay_I18n::t( 'pay_now' ) ); ?> <i class="bi bi-arrow-right ms-1"></i>
						</button>
					</div>
				</div>
			</form>
		</div>
	</div>
	<footer class="secure-footer secure-footer--fixed">
		<i class="bi bi-shield-lock-fill"></i> <?php echo esc_html( VisualPay_I18n::t( 'secure_encrypted' ) ); ?>
	</footer>

	<div class="modal fade payment-modal" id="paymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div id="modalPending">
					<div class="sp-card">
						<div class="sp-card-head">
							<div class="sp-brand"><span class="sp-dot" aria-hidden="true"></span><?php echo esc_html( VisualPay_I18n::t( 'secure_payment' ) ); ?></div>
							<div class="sp-head-right">
								<div class="sp-order-id"><?php echo esc_html( VisualPay_I18n::t( 'order_id_prefix' ) ); ?> <b id="modalOrderNum">#<?php echo esc_html( $order->get_order_number() ); ?></b></div>
								<div class="sp-timer">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
									<span id="expireTimer">--:--</span>
								</div>
							</div>
						</div>

						<div class="sp-body">
							<div class="sp-top-row">
								<div class="sp-asset">
									<img class="sp-asset-icon sp-asset-icon--img" id="payCurrencyIcon" src="" alt="">
									<span class="sp-asset-icon sp-asset-icon--fallback d-none" id="payCurrencyFallback" aria-hidden="true"></span>
									<div>
										<div class="sp-asset-name" id="payAssetTitle">—</div>
										<div class="sp-asset-sub"><?php echo esc_html( VisualPay_I18n::t( 'send_exact_asset_only' ) ); ?></div>
									</div>
								</div>
								<div class="sp-network-badge" id="payNetworkBadge">
									<img class="sp-network-badge__icon d-none" id="payNetworkIcon" src="" alt="">
									<span class="sp-network-badge__fallback d-none" id="payNetworkFallback" aria-hidden="true"></span>
									<span id="payNetworkName">—</span>
								</div>
							</div>

							<div class="sp-col-left">
								<div class="sp-qr-wrap">
									<div class="sp-qr-box" id="qrWrap"><canvas id="qrCanvas"></canvas></div>
								</div>
							</div>

							<div class="sp-col-right">
								<div class="sp-amount-box">
									<div class="sp-amount-head">
										<div class="sp-amount-label"><?php echo esc_html( VisualPay_I18n::t( 'total_amount' ) ); ?></div>
									</div>
									<div class="sp-amount-value" id="totalAmount">—</div>
									<div class="sp-amount-hint"><?php echo esc_html( VisualPay_I18n::t( 'base_plus_fee' ) ); ?></div>
								</div>
								<div class="sp-warning">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
									<p id="modalWarnText"></p>
								</div>
							</div>

							<div class="sp-col-full">
								<div class="sp-field-label"><?php echo esc_html( VisualPay_I18n::t( 'wallet_address_label' ) ); ?></div>
								<div class="sp-address-row">
									<span class="sp-address-text" id="walletAddressText"></span>
									<input type="hidden" id="walletAddress" value="">
									<button type="button" class="sp-copy-btn" id="copyBtn" title="<?php echo esc_attr( VisualPay_I18n::t( 'copy_address' ) ); ?>" aria-label="<?php echo esc_attr( VisualPay_I18n::t( 'copy_address' ) ); ?>">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
									</button>
								</div>
								<button type="button" class="sp-cancel-btn" id="cancelBtn"><?php echo esc_html( VisualPay_I18n::t( 'cancel_payment' ) ); ?></button>
							</div>
						</div>

						<div class="sp-footnote">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
							<span><?php echo esc_html( VisualPay_I18n::t( 'auto_result_short' ) ); ?></span>
						</div>
					</div>
				</div>

				<div id="modalSuccess" class="d-none">
					<div class="result-panel">
						<div class="result-icon ok"><i class="bi bi-check-lg"></i></div>
						<h2><?php echo esc_html( VisualPay_I18n::t( 'payment_successful' ) ); ?></h2>
						<p><?php echo esc_html( VisualPay_I18n::t( 'payment_confirmed' ) ); ?></p>
						<div class="tx-list">
							<div class="tx-box">
								<div class="tx-head">
									<div class="tx-lbl"><?php echo esc_html( VisualPay_I18n::t( 'transaction_id' ) ); ?></div>
									<div class="tx-actions">
										<button type="button" class="tx-action" id="copyTxIdBtn" title="<?php echo esc_attr( VisualPay_I18n::t( 'copy_tx_id' ) ); ?>"><i class="bi bi-copy"></i></button>
									</div>
								</div>
								<div class="tx-val" id="successTxId">—</div>
							</div>
							<div class="tx-box">
								<div class="tx-head">
									<div class="tx-lbl"><?php echo esc_html( VisualPay_I18n::t( 'explorer_url' ) ); ?></div>
									<div class="tx-actions">
										<button type="button" class="tx-action" id="copyTxUrlBtn" title="<?php echo esc_attr( VisualPay_I18n::t( 'copy_explorer_url' ) ); ?>"><i class="bi bi-copy"></i></button>
										<a class="tx-action" id="openTxUrlBtn" href="#" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( VisualPay_I18n::t( 'open_explorer_url' ) ); ?>"><i class="bi bi-box-arrow-up-right"></i></a>
									</div>
								</div>
								<div class="tx-val is-link" id="successTxUrl">—</div>
							</div>
						</div>
						<a class="btn btn-new" id="successContinueBtn" href="#"><?php echo esc_html( VisualPay_I18n::t( 'continue_to_order' ) ); ?></a>
					</div>
				</div>

				<div id="modalFailed" class="d-none">
					<div class="result-panel">
						<div class="result-icon fail"><i class="bi bi-x-lg"></i></div>
						<h2><?php echo esc_html( VisualPay_I18n::t( 'payment_failed' ) ); ?></h2>
						<p id="failedMessage"><?php echo esc_html( VisualPay_I18n::t( 'payment_cancelled_retry' ) ); ?></p>
						<div class="result-actions">
							<a class="btn btn-new btn-back-order" id="failedBackBtn" href="<?php echo esc_url( VisualPay_Payment_Page::get_back_url( $order ) ); ?>"><?php echo esc_html( VisualPay_I18n::t( 'back' ) ); ?></a>
							<button type="button" class="btn btn-new" id="failedRetryBtn"><?php echo esc_html( VisualPay_I18n::t( 'try_again' ) ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade confirm-modal" id="confirmCancelModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><?php echo esc_html( VisualPay_I18n::t( 'cancel_payment_q' ) ); ?></h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body"><?php echo esc_html( VisualPay_I18n::t( 'cancel_confirm_body' ) ); ?></div>
				<div class="modal-footer">
					<button type="button" class="btn btn-keep" data-bs-dismiss="modal"><?php echo esc_html( VisualPay_I18n::t( 'keep_payment' ) ); ?></button>
					<button type="button" class="btn btn-confirm-cancel" id="confirmCancelBtn"><?php echo esc_html( VisualPay_I18n::t( 'yes_cancel' ) ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<?php wp_footer(); ?>
</body>
</html>
