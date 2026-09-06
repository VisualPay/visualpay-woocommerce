<?php
/**
 * Dashboard data for the admin Overview page.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Dashboard
 */
class VisualPay_Dashboard {

	/**
	 * Coin accent colors for the supported currencies strip.
	 *
	 * @var array<string, string>
	 */
	private static $coin_colors = array(
		'USDT'  => '#26a17b',
		'USDC'  => '#2775ca',
		'BNB'   => '#f0b90b',
		'MATIC' => '#8247e5',
		'DAI'   => '#f5ac37',
		'SOL'   => '#14f195',
		'TRX'   => '#e6332a',
		'TON'   => '#0098ea',
	);

	/**
	 * Icon URLs keyed by currency symbol and network code (from active API catalog).
	 *
	 * @return array{currency: array<string, string>, currency_names: array<string, string>, network: array<string, string>}
	 */
	public static function get_catalog_icons() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array(
			'currency'       => array(),
			'currency_names' => array(),
			'network'        => array(),
		);

		$client = new VisualPay_API_Client();
		if ( ! $client->is_configured() ) {
			return $cache;
		}

		$result = $client->list_currencies();
		if ( is_wp_error( $result ) || empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
			return $cache;
		}

		foreach ( $result['data'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$symbol = isset( $item['currency_symbol'] ) ? strtoupper( (string) $item['currency_symbol'] ) : '';
			$icon   = VisualPay_Security::sanitize_icon_url( isset( $item['icon'] ) ? $item['icon'] : '' );
			$name   = isset( $item['currency_name'] ) ? sanitize_text_field( (string) $item['currency_name'] ) : '';

			if ( $symbol && $icon ) {
				$cache['currency'][ $symbol ] = $icon;
			}

			if ( $symbol && $name ) {
				$cache['currency_names'][ $symbol ] = $name;
			}

			$networks = isset( $item['supported_networks'] ) && is_array( $item['supported_networks'] )
				? $item['supported_networks']
				: array();

			foreach ( $networks as $net ) {
				if ( ! is_array( $net ) ) {
					continue;
				}

				$code     = isset( $net['network_code'] ) ? strtolower( (string) $net['network_code'] ) : '';
				$net_icon = VisualPay_Security::sanitize_icon_url( isset( $net['icon'] ) ? $net['icon'] : '' );

				if ( ! $code || ! $net_icon ) {
					continue;
				}

				$cache['network'][ $code ] = $net_icon;
				if ( $symbol ) {
					$cache['network'][ $symbol . '|' . $code ] = $net_icon;
				}
			}
		}

		return $cache;
	}

	/**
	 * Build all dashboard view data.
	 *
	 * @return array
	 */
	public static function get_view_data() {
		$client     = new VisualPay_API_Client();
		$connected  = false;
		$currencies = array();
		$coin_count = 0;
		$net_count  = 0;

		if ( $client->is_configured() ) {
			$res = $client->list_currencies();
			if ( ! is_wp_error( $res ) && ! empty( $res['data'] ) && is_array( $res['data'] ) ) {
				$connected  = true;
				$currencies = self::flatten_currencies( $res['data'] );
				$coin_count = count( array_unique( wp_list_pluck( $currencies, 'symbol' ) ) );
				$net_count  = count( array_unique( wp_list_pluck( $currencies, 'network' ) ) );
			}
		}

		$periods = self::period_bounds();
		$tx_stats = array();
		foreach ( self::transaction_period_labels() as $key => $label ) {
			$bounds = $periods[ $key ];
			$counts = VisualPay_Transaction_Store::counts_by_status_between(
				$bounds[0]->format( 'Y-m-d H:i:s' ),
				$bounds[1]->format( 'Y-m-d H:i:s' )
			);
			$tx_stats[] = array(
				'label'     => $label,
				'key'       => $key,
				'total'     => (int) $counts['total'],
				'pending'   => (int) $counts['pending'],
				'confirmed' => (int) $counts['confirmed'],
				'cancelled' => (int) $counts['cancelled'],
			);
		}

		$income_sums = self::income_period_sums( $periods );
		$rev_stats   = self::build_revenue_stats( $income_sums );
		$chart       = VisualPay_Transaction_Store::sum_confirmed_daily( 30 );

		return array(
			'connected'   => $connected,
			'currencies'  => $currencies,
			'coin_count'  => $coin_count,
			'net_count'   => $net_count,
			'tx_stats'    => $tx_stats,
			'rev_stats'   => $rev_stats,
			'chart'       => $chart,
			'chart_total' => array_sum( wp_list_pluck( $chart, 'total' ) ),
		);
	}

	/**
	 * Flatten API currency catalog into coin/network rows.
	 *
	 * @param array $catalog API data.
	 * @return array<int, array{symbol:string,name:string,network:string,color:string,icon:string}>
	 */
	private static function flatten_currencies( array $catalog ) {
		$rows = array();

		foreach ( $catalog as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$symbol = isset( $item['currency_symbol'] ) ? strtoupper( (string) $item['currency_symbol'] ) : '';
			$name   = isset( $item['currency_name'] ) ? (string) $item['currency_name'] : $symbol;
			$nets   = isset( $item['supported_networks'] ) && is_array( $item['supported_networks'] )
				? $item['supported_networks']
				: array();

			foreach ( $nets as $net ) {
				if ( ! is_array( $net ) ) {
					continue;
				}

				$currency_icon = isset( $item['icon'] ) ? (string) $item['icon'] : '';
				$network_icon  = isset( $net['icon'] ) ? (string) $net['icon'] : '';
				$icon          = '' !== $currency_icon ? $currency_icon : $network_icon;

				$rows[] = array(
					'symbol'  => $symbol,
					'name'    => $name,
					'network' => isset( $net['network_name'] ) ? (string) $net['network_name'] : '',
					'color'   => self::coin_color( $symbol ),
					'icon'    => $icon,
				);
			}
		}

		return $rows;
	}

	/**
	 * Resolve accent color for a coin symbol.
	 *
	 * @param string $symbol Coin symbol.
	 * @return string
	 */
	public static function coin_accent_color( $symbol ) {
		return self::coin_color( $symbol );
	}

	/**
	 * Resolve accent color for a coin symbol.
	 *
	 * @param string $symbol Coin symbol.
	 * @return string
	 */
	private static function coin_color( $symbol ) {
		$symbol = strtoupper( (string) $symbol );
		return isset( self::$coin_colors[ $symbol ] ) ? self::$coin_colors[ $symbol ] : '#9098a3';
	}

	/**
	 * Period labels for transaction cards.
	 *
	 * @return array<string, string>
	 */
	private static function transaction_period_labels() {
		return array(
			'today'      => __( 'Today', 'visualpay-for-woocommerce' ),
			'yesterday'  => __( 'Yesterday', 'visualpay-for-woocommerce' ),
			'this_month' => __( 'This month', 'visualpay-for-woocommerce' ),
			'last_month' => __( 'Last month', 'visualpay-for-woocommerce' ),
		);
	}

	/**
	 * Date ranges used across dashboard cards.
	 *
	 * @return array<string, array{0:DateTimeImmutable,1:DateTimeImmutable}>
	 */
	private static function period_bounds() {
		$tz = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		$today_start = $now->setTime( 0, 0, 0 );
		$today_end   = $now->setTime( 23, 59, 59 );
		$yest_start  = $today_start->modify( '-1 day' );
		$yest_end    = $today_end->modify( '-1 day' );
		$month_start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$month_end   = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		$last_m_start = $month_start->modify( '-1 month' );
		$last_m_end   = $last_m_start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		$month2_start = $last_m_start->modify( '-1 month' );
		$month2_end   = $last_m_start->modify( '-1 day' )->setTime( 23, 59, 59 );
		$month3_start = $month2_start->modify( '-1 month' );
		$month3_end   = $month2_start->modify( '-1 day' )->setTime( 23, 59, 59 );

		$day2_start = $yest_start->modify( '-1 day' );
		$day3_start = $day2_start->modify( '-1 day' );
		$day4_start = $day3_start->modify( '-1 day' );

		return array(
			'today'          => array( $today_start, $today_end ),
			'yesterday'      => array( $yest_start, $yest_end ),
			'day_2'          => array( $day2_start, $day2_start->setTime( 23, 59, 59 ) ),
			'day_3'          => array( $day3_start, $day3_start->setTime( 23, 59, 59 ) ),
			'day_4'          => array( $day4_start, $day4_start->setTime( 23, 59, 59 ) ),
			'this_month'     => array( $month_start, $month_end ),
			'this_month_mtd' => array( $month_start, $today_end ),
			'last_month'     => array( $last_m_start, $last_m_end ),
			'last_month_mtd' => array( $last_m_start, $last_m_start->modify( '+' . ( (int) $now->format( 'j' ) - 1 ) . ' days' )->setTime( 23, 59, 59 ) ),
			'month_2'        => array( $month2_start, $month2_end ),
			'month_3'        => array( $month3_start, $month3_end ),
		);
	}

	/**
	 * Sum confirmed income for dashboard periods.
	 *
	 * @param array $periods Period bounds.
	 * @return array<string, float>
	 */
	private static function income_period_sums( array $periods ) {
		$keys = array( 'today', 'yesterday', 'day_2', 'day_3', 'day_4', 'this_month_mtd', 'last_month_mtd', 'last_month', 'month_2', 'month_3' );
		$sums = array();

		foreach ( $keys as $key ) {
			if ( ! isset( $periods[ $key ] ) ) {
				$sums[ $key ] = 0.0;
				continue;
			}
			$sums[ $key ] = VisualPay_Transaction_Store::sum_confirmed_between(
				$periods[ $key ][0]->format( 'Y-m-d H:i:s' ),
				$periods[ $key ][1]->format( 'Y-m-d H:i:s' )
			);
		}

		return $sums;
	}

	/**
	 * Build revenue cards with growth labels.
	 *
	 * @param array<string, float> $sums Income sums.
	 * @return array<int, array{label:string,value:string,growth:string,dir:string}>
	 */
	private static function build_revenue_stats( array $sums ) {
		$avg_three = ( $sums['day_2'] + $sums['day_3'] + $sums['day_4'] ) / 3;
		$avg_two   = ( $sums['month_2'] + $sums['month_3'] ) / 2;

		$cards = array(
			array(
				'label'    => __( 'Today Income', 'visualpay-for-woocommerce' ),
				'current'  => $sums['today'],
				'baseline' => $sums['yesterday'],
				'context'  => __( 'vs yesterday', 'visualpay-for-woocommerce' ),
			),
			array(
				'label'    => __( 'Yesterday Income', 'visualpay-for-woocommerce' ),
				'current'  => $sums['yesterday'],
				'baseline' => $avg_three,
				'context'  => __( 'vs three last days', 'visualpay-for-woocommerce' ),
			),
			array(
				'label'    => __( 'This Month Income', 'visualpay-for-woocommerce' ),
				'current'  => $sums['this_month_mtd'],
				'baseline' => $sums['last_month_mtd'],
				'context'  => __( 'vs last month', 'visualpay-for-woocommerce' ),
			),
			array(
				'label'    => __( 'Last Month Income', 'visualpay-for-woocommerce' ),
				'current'  => $sums['last_month'],
				'baseline' => $avg_two,
				'context'  => __( 'vs two last months', 'visualpay-for-woocommerce' ),
			),
		);

		$out = array();
		foreach ( $cards as $card ) {
			$trend = self::format_trend( $card['current'], $card['baseline'] );
			$growth_label = 'flat' === $trend['dir']
				? $trend['growth']
				: $trend['growth'] . ' ' . $card['context'];

			$out[] = array(
				'label'  => $card['label'],
				'value'  => '$' . number_format( (float) $card['current'], 2 ),
				'growth' => $growth_label,
				'dir'    => $trend['dir'],
			);
		}

		return $out;
	}

	/**
	 * Format growth percentage label.
	 *
	 * @param float $current  Current amount.
	 * @param float $baseline Baseline amount.
	 * @return array{growth:string,dir:string}
	 */
	private static function format_trend( $current, $baseline ) {
		$current  = (float) $current;
		$baseline = (float) $baseline;

		if ( $baseline <= 0 ) {
			if ( $current <= 0 ) {
				return array(
					'growth' => __( 'No change', 'visualpay-for-woocommerce' ),
					'dir'    => 'flat',
				);
			}

			return array(
				'growth' => '+100%',
				'dir'    => 'up',
			);
		}

		$percent = ( ( $current - $baseline ) / $baseline ) * 100;
		$dir     = $percent >= 0 ? 'up' : 'down';

		if ( abs( $percent ) < 0.05 ) {
			return array(
				'growth' => __( 'No change', 'visualpay-for-woocommerce' ),
				'dir'    => 'flat',
			);
		}

		return array(
			'growth' => ( $percent >= 0 ? '+' : '' ) . number_format( $percent, 1 ) . '%',
			'dir'    => $dir,
		);
	}
}
