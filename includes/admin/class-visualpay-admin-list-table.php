<?php

/**

 * Transactions list table.

 *

 * @package VisualPay_WooCommerce

 */



defined( 'ABSPATH' ) || exit;



if ( ! class_exists( 'WP_List_Table' ) ) {

	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

}



/**

 * Class VisualPay_Admin_List_Table

 */

class VisualPay_Admin_List_Table extends WP_List_Table {



	/**

	 * Records per page.

	 */

	const PER_PAGE = 20;



	/**

	 * Currency/network icons from active merchant catalog.

	 *

	 * @var array{currency: array<string, string>, network: array<string, string>}

	 */

	private $icon_maps = array(

		'currency'       => array(),

		'currency_names' => array(),

		'network'        => array(),

	);



	/**

	 * Constructor.

	 */

	public function __construct() {

		parent::__construct(

			array(

				'singular' => 'visualpay_transaction',

				'plural'   => 'visualpay-transactions',

				'ajax'     => false,

			)

		);

	}



	/**

	 * Columns.

	 *

	 * @return array

	 */

	public function get_columns() {

		return array(

			'order_id'        => __( 'Order', 'visualpay-for-woocommerce' ),

			'usdt_amount'     => __( 'Amount ($)', 'visualpay-for-woocommerce' ),

			'status'          => __( 'Status', 'visualpay-for-woocommerce' ),

			'tx_id'           => __( 'TX ID', 'visualpay-for-woocommerce' ),

			'currency_symbol' => __( 'Currency', 'visualpay-for-woocommerce' ),

			'network_name'    => __( 'Network', 'visualpay-for-woocommerce' ),

			'tracking_code'   => __( 'Tracking', 'visualpay-for-woocommerce' ),

			'created_at'      => __( 'Created', 'visualpay-for-woocommerce' ),

		);

	}



	/**

	 * Sortable columns.

	 *

	 * @return array

	 */

	protected function get_sortable_columns() {

		return array(

			'created_at' => array( 'created_at', true ),

			'order_id'   => array( 'order_id', false ),

			'status'     => array( 'status', false ),

		);

	}



	/**

	 * Hide default status views (rendered as tabs in the page template).

	 */

	public function views() {}



	/**

	 * Prepare items.

	 */

	public function prepare_items() {

		$this->icon_maps = VisualPay_Dashboard::get_catalog_icons();



		$per_page = self::PER_PAGE;

		$page     = $this->get_pagenum();

		$status   = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended



		$result = VisualPay_Transaction_Store::query(

			array(

				'per_page' => $per_page,

				'page'     => $page,

				'status'   => $status,

				'search'   => $search,

			)

		);



		$this->items           = $result['items'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );



		$add_args = array();

		if ( $status ) {

			$add_args['status'] = $status;

		}

		if ( '' !== $search ) {

			$add_args['s'] = $search;

		}



		$this->set_pagination_args(

			array(

				'total_items' => $result['total'],

				'per_page'    => $per_page,

				'total_pages' => (int) ceil( $result['total'] / $per_page ),

				'add_args'    => $add_args,

			)

		);

	}



	/**

	 * Render themed pagination footer.

	 *

	 * @param string $which top|bottom.

	 */

	protected function pagination( $which = 'top' ) {

		if ( 'top' === $which ) {

			return;

		}



		if ( empty( $this->_pagination_args['total_items'] ) ) {

			return;

		}



		$total_items = (int) $this->_pagination_args['total_items'];

		$per_page    = (int) $this->_pagination_args['per_page'];

		$total_pages = (int) $this->_pagination_args['total_pages'];

		$current     = max( 1, $this->get_pagenum() );

		$from        = ( ( $current - 1 ) * $per_page ) + 1;

		$to          = min( $total_items, $current * $per_page );



		$add_args = isset( $this->_pagination_args['add_args'] ) && is_array( $this->_pagination_args['add_args'] )

			? $this->_pagination_args['add_args']

			: array();



		echo '<div class="visualpay-transactions__pagination">';



		echo '<span class="visualpay-transactions__pagination-summary">';

		printf(
			/* translators: 1: first item number, 2: last item number, 3: total items */
			esc_html__( 'Showing %1$d–%2$d of %3$d', 'visualpay-for-woocommerce' ),
			absint( $from ),
			absint( $to ),
			absint( $total_items )
		);

		echo '</span>';



		if ( $total_pages > 1 ) {

			$base = esc_url(

				add_query_arg(

					array_merge(

						$add_args,

						array(

							'page'  => 'visualpay-transactions',

							'paged' => '%#%',

						)

					),

					admin_url( 'admin.php' )

				)

			);



			$page_links = paginate_links(

				array(

					'base'      => $base,

					'format'    => '',

					'prev_text' => '&lsaquo;',

					'next_text' => '&rsaquo;',

					'total'     => $total_pages,

					'current'   => $current,

					'type'      => 'array',

					'end_size'  => 1,

					'mid_size'  => 2,

				)

			);



			if ( ! empty( $page_links ) && is_array( $page_links ) ) {

				echo '<nav class="visualpay-transactions__pagination-links" aria-label="' . esc_attr__( 'Transactions pagination', 'visualpay-for-woocommerce' ) . '">';

				foreach ( $page_links as $link ) {

					echo wp_kses_post( $link );

				}

				echo '</nav>';

			}

		}



		echo '</div>';

	}



	/**

	 * Render table with an inner scroll region so the page itself stays locked on desktop.

	 */

	public function display() {

		$singular = $this->_args['singular'];



		$this->display_tablenav( 'top' );



		$this->screen->render_screen_reader_content( 'heading_list' );

		?>

		<div class="visualpay-transactions__table-scroll">

			<table class="wp-list-table <?php echo implode( ' ', array_map( 'sanitize_html_class', $this->get_table_classes() ) ); ?>">

				<?php $this->print_table_description(); ?>

				<thead>

					<tr>

						<?php $this->print_column_headers(); ?>

					</tr>

				</thead>

				<tbody id="the-list"
					<?php
					if ( $singular ) {
						echo " data-wp-lists='list:" . esc_attr( $singular ) . "'";
					}
					?>
				>
					<?php $this->display_rows_or_placeholder(); ?>
				</tbody>
			</table>
		</div>
		<?php

		$this->display_tablenav( 'bottom' );
	}



	/**

	 * Render bottom table navigation with themed pagination only.

	 *

	 * @param string $which top|bottom.

	 */

	public function display_tablenav( $which = 'top' ) {

		if ( 'top' === $which ) {

			return;

		}



		if ( empty( $this->_pagination_args['total_items'] ) ) {

			return;

		}



		echo '<div class="tablenav bottom visualpay-transactions__tablenav">';

		$this->pagination( $which );

		echo '</div>';

	}



	/**

	 * Default column.

	 *

	 * @param object $item Item.

	 * @param string $column_name Column.

	 * @return string

	 */

	protected function column_default( $item, $column_name ) {

		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '—';

	}



	/**

	 * Order column with modal trigger.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_order_id( $item ) {

		return sprintf(

			'<a href="#" class="visualpay-open-order visualpay-transactions__order-link" data-tx-row-id="%d" data-order-id="%d">#%d</a>',

			(int) $item->id,

			(int) $item->order_id,

			(int) $item->order_id

		);

	}



	/**

	 * Status badge.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_status( $item ) {

		$status = strtolower( (string) $item->status );

		if ( ! in_array( $status, array( 'pending', 'confirmed', 'cancelled', 'expired' ), true ) ) {

			$status = 'pending';

		}



		return sprintf(

			'<span class="visualpay-transactions__badge visualpay-transactions__badge--%1$s"><span class="visualpay-transactions__badge-dot" aria-hidden="true"></span>%2$s</span>',

			esc_attr( $status ),

			esc_html( ucfirst( $status ) )

		);

	}



	/**

	 * TX ID with explorer link and copy action.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_tx_id( $item ) {

		if ( empty( $item->tx_id ) ) {

			return '<span class="visualpay-transactions__muted">—</span>';

		}



		$url = ! empty( $item->tx_id_url ) ? (string) $item->tx_id_url : '';



		return $this->render_copyable_value( (string) $item->tx_id, $url );

	}



	/**

	 * USD amount.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_usdt_amount( $item ) {

		if ( null === $item->usdt_amount || '' === $item->usdt_amount ) {

			return '<span class="visualpay-transactions__muted">—</span>';

		}



		return '<span class="visualpay-transactions__usd mono">$' . esc_html( number_format( (float) $item->usdt_amount, 2, '.', ',' ) ) . '</span>';

	}



	/**

	 * Currency column with icon.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_currency_symbol( $item ) {

		$symbol = strtoupper( (string) $item->currency_symbol );

		if ( '' === $symbol ) {

			return '<span class="visualpay-transactions__muted">—</span>';

		}



		$name = trim( (string) $item->currency_name );

		if ( '' === $name ) {

			$name = $this->resolve_currency_name( $symbol );

		}



		$icon = $this->resolve_currency_icon( $symbol );

		$label = '' !== $name ? $name : $symbol;



		return $this->render_icon_label(

			$icon,

			$label,

			substr( $label, 0, 1 ),

			VisualPay_Dashboard::coin_accent_color( $this->resolve_catalog_symbol( $symbol ) )

		);

	}



	/**

	 * Network column with icon (same layout as currency).

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_network_name( $item ) {

		$name = trim( (string) $item->network_name );

		if ( '' === $name ) {

			return '<span class="visualpay-transactions__muted">—</span>';

		}



		$symbol = strtoupper( (string) $item->currency_symbol );

		$lookup = $this->resolve_catalog_symbol( $symbol );

		$code   = strtolower( (string) $item->network_code );

		$icon   = '';



		if ( $lookup && $code && isset( $this->icon_maps['network'][ $lookup . '|' . $code ] ) ) {

			$icon = $this->icon_maps['network'][ $lookup . '|' . $code ];

		} elseif ( $code && isset( $this->icon_maps['network'][ $code ] ) ) {

			$icon = $this->icon_maps['network'][ $code ];

		}



		return $this->render_icon_label( $icon, $name, substr( $name, 0, 1 ), '#9098a3' );

	}



	/**

	 * Tracking code with copy action.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_tracking_code( $item ) {

		if ( empty( $item->tracking_code ) ) {

			return '<span class="visualpay-transactions__muted">—</span>';

		}



		return $this->render_copyable_value( (string) $item->tracking_code );

	}



	/**

	 * Created column.

	 *

	 * @param object $item Item.

	 * @return string

	 */

	protected function column_created_at( $item ) {

		if ( empty( $item->created_at ) ) {

			return '<span class="visualpay-transactions__muted">—</span>';

		}



		$timestamp = mysql2date( 'U', (string) $item->created_at, false );

		if ( ! $timestamp ) {

			return esc_html( (string) $item->created_at );

		}



		return '<span class="visualpay-transactions__created mono">' . esc_html( wp_date( 'Y/m/d H:i:s', $timestamp ) ) . '</span>';

	}



	/**

	 * No items text.

	 */

	public function no_items() {

		esc_html_e( 'No transactions found.', 'visualpay-for-woocommerce' );

	}



	/**

	 * Render icon + label cell shared by currency and network columns.

	 *

	 * @param string $icon_url        Remote icon URL.

	 * @param string $label           Display label.

	 * @param string $fallback_letter Fallback letter when icon is missing.

	 * @param string $fallback_color  Fallback background color.

	 * @return string

	 */

	private function render_icon_label( $icon_url, $label, $fallback_letter, $fallback_color ) {

		if ( $icon_url ) {

			$icon_html = sprintf(

				'<img class="visualpay-transactions__asset-icon" src="%s" alt="" loading="lazy" decoding="async" />',

				esc_url( $icon_url )

			);

		} else {

			$icon_html = sprintf(

				'<span class="visualpay-transactions__currency-icon" style="background:%1$s">%2$s</span>',

				esc_attr( $fallback_color ),

				esc_html( $fallback_letter )

			);

		}



		return sprintf(

			'<span class="visualpay-transactions__currency"><span class="visualpay-transactions__asset-icon-wrap">%1$s</span><span>%2$s</span></span>',

			$icon_html,

			esc_html( $label )

		);

	}



	/**

	 * Render truncated value with copy button.

	 *

	 * @param string $value Full value to copy.

	 * @param string $url   Optional link URL for the label.

	 * @return string

	 */

	private function render_copyable_value( $value, $url = '' ) {

		$value   = (string) $value;

		$display = esc_html( self::truncate_start( $value, 10 ) );



		if ( $url ) {

			$text_html = sprintf(

				'<a class="visualpay-transactions__tx-id mono" href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s">%3$s</a>',

				esc_url( $url ),

				esc_attr( $value ),

				$display

			);

		} else {

			$text_html = sprintf(

				'<span class="visualpay-transactions__tracking mono" title="%1$s">%2$s</span>',

				esc_attr( $value ),

				$display

			);

		}



		return sprintf(

			'<span class="visualpay-transactions__copy-cell">%1$s<button type="button" class="visualpay-transactions__copy-btn" data-copy="%2$s" aria-label="%3$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></span>',

			$text_html,

			esc_attr( $value ),

			esc_attr__( 'Copy', 'visualpay-for-woocommerce' )

		);

	}



	/**

	 * Truncate long strings from the start.

	 *

	 * @param string $value  Value.

	 * @param int    $length Visible character limit.

	 * @return string

	 */

	private static function truncate_start( $value, $length = 10 ) {

		$value = (string) $value;

		if ( strlen( $value ) <= $length ) {

			return $value;

		}



		return substr( $value, 0, $length ) . '...';

	}



	/**
	 * Resolve catalog symbol from stored currency code (e.g. USDT_ARBITRUM -> USDT).
	 *
	 * @param string $symbol Stored symbol.
	 * @return string
	 */
	private function resolve_catalog_symbol( $symbol ) {
		$symbol = strtoupper( (string) $symbol );

		if ( '' === $symbol ) {
			return '';
		}

		if ( isset( $this->icon_maps['currency_names'][ $symbol ] ) || isset( $this->icon_maps['currency'][ $symbol ] ) ) {
			return $symbol;
		}

		if ( false !== strpos( $symbol, '_' ) ) {
			$base = strstr( $symbol, '_', true );
			if ( $base && ( isset( $this->icon_maps['currency_names'][ $base ] ) || isset( $this->icon_maps['currency'][ $base ] ) ) ) {
				return $base;
			}
		}

		return $symbol;
	}



	/**
	 * Resolve display name for a currency symbol.
	 *
	 * @param string $symbol Stored symbol.
	 * @return string
	 */
	private function resolve_currency_name( $symbol ) {
		$symbol = strtoupper( (string) $symbol );
		$lookup = $this->resolve_catalog_symbol( $symbol );

		if ( $lookup && isset( $this->icon_maps['currency_names'][ $lookup ] ) ) {
			return (string) $this->icon_maps['currency_names'][ $lookup ];
		}

		return '';
	}



	/**
	 * Resolve icon URL for a currency symbol.
	 *
	 * @param string $symbol Stored symbol.
	 * @return string
	 */
	private function resolve_currency_icon( $symbol ) {
		$symbol = strtoupper( (string) $symbol );
		$lookup = $this->resolve_catalog_symbol( $symbol );

		if ( $lookup && isset( $this->icon_maps['currency'][ $lookup ] ) ) {
			return (string) $this->icon_maps['currency'][ $lookup ];
		}

		return '';
	}

}

