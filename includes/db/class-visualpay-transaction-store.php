<?php
/**
 * Local transaction persistence.
 *
 * @package VisualPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VisualPay_Transaction_Store
 */
class VisualPay_Transaction_Store {

	/**
	 * Upsert from create response + order context.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $data     Create data.
	 * @param array $extra    Extra fields.
	 * @return int|false Row ID.
	 */
	public static function upsert_from_create( $order_id, array $data, array $extra = array() ) {
		$row = array(
			'order_id'            => (int) $order_id,
			'tracking_code'       => isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : '',
			'status'              => isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : 'pending',
			'currency_symbol'     => isset( $data['currency_symbol'] ) ? (string) $data['currency_symbol'] : '',
			'currency_name'       => isset( $data['currency_name'] ) ? (string) $data['currency_name'] : '',
			'network_code'        => isset( $data['network_code'] ) ? (string) $data['network_code'] : '',
			'network_name'        => isset( $data['network_name'] ) ? (string) $data['network_name'] : '',
			'usdt_amount'         => self::num( isset( $data['usdt_amount'] ) ? $data['usdt_amount'] : null ),
			'expected_amount'     => self::num(
				isset( $data['expected_amount'] )
					? $data['expected_amount']
					: ( isset( $data['pay_amount'] ) ? $data['pay_amount'] : null )
			),
			'base_amount'         => self::num( isset( $data['base_amount'] ) ? $data['base_amount'] : null ),
			'network_fee'         => self::num( isset( $data['network_fee'] ) ? $data['network_fee'] : null ),
			'wallet_address'      => isset( $data['wallet_address'] ) ? (string) $data['wallet_address'] : null,
			'payer_email'         => isset( $extra['email'] ) ? (string) $extra['email'] : null,
			'payer_comment'       => isset( $extra['comment'] ) ? (string) $extra['comment'] : null,
			'raw_create_response' => wp_json_encode( $data ),
			'expires_at'          => self::unix_to_mysql( isset( $data['expires_at'] ) ? $data['expires_at'] : null ),
		);

		return self::upsert( $row );
	}

	/**
	 * Update from status response.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $data     Status data.
	 * @return int|false
	 */
	public static function update_from_status( $order_id, array $data ) {
		$row = array(
			'order_id'            => (int) $order_id,
			'tracking_code'       => isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : '',
			'status'              => isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : '',
			'tx_id'               => isset( $data['tx_id'] ) ? (string) $data['tx_id'] : null,
			'tx_id_url'           => isset( $data['tx_id_url'] )
				? (string) $data['tx_id_url']
				: ( isset( $data['tx_explorer_url'] ) ? (string) $data['tx_explorer_url'] : null ),
			'currency_symbol'     => isset( $data['currency_symbol'] ) ? (string) $data['currency_symbol'] : ( isset( $data['currency_code'] ) ? (string) $data['currency_code'] : '' ),
			'currency_name'       => isset( $data['currency_name'] ) ? (string) $data['currency_name'] : '',
			'network_code'        => isset( $data['network_code'] ) ? (string) $data['network_code'] : '',
			'network_name'        => isset( $data['network_name'] ) ? (string) $data['network_name'] : '',
			'usdt_amount'         => self::num( isset( $data['usdt_amount'] ) ? $data['usdt_amount'] : null ),
			'paid_amount'         => self::num( isset( $data['paid_amount'] ) ? $data['paid_amount'] : null ),
			'expected_amount'     => self::num( isset( $data['expected_amount'] ) ? $data['expected_amount'] : null ),
			'network_fee'         => self::num( isset( $data['network_fee'] ) ? $data['network_fee'] : null ),
			'payer_email'         => isset( $data['payer_email'] ) ? (string) $data['payer_email'] : null,
			'payer_comment'       => isset( $data['payer_comment'] ) ? (string) $data['payer_comment'] : null,
			'raw_status_response' => wp_json_encode( $data ),
			'paid_at'             => self::unix_to_mysql( isset( $data['paid_at'] ) ? $data['paid_at'] : null ),
			'expires_at'          => self::unix_to_mysql( isset( $data['expires_at'] ) ? $data['expires_at'] : null ),
		);

		if ( empty( $row['currency_symbol'] ) && isset( $data['currency_code'] ) ) {
			$row['currency_symbol'] = (string) $data['currency_code'];
		}

		return self::upsert( $row );
	}

	/**
	 * Upsert by order_id.
	 *
	 * @param array $row Row data.
	 * @return int|false
	 */
	public static function upsert( array $row ) {
		global $wpdb;

		$table   = VisualPay_Transactions_Table::table_name();
		$now     = current_time( 'mysql' );
		$order_id = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;

		if ( $order_id <= 0 ) {
			return false;
		}

		$existing = self::get_by_order_id( $order_id );
		$row['updated_at'] = $now;

		if ( $existing ) {
			$merged = array_merge( (array) $existing, array_filter( $row, array( __CLASS__, 'is_not_null' ) ) );
			unset( $merged['id'] );
			$merged['created_at'] = $existing->created_at;
			$merged['updated_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $merged, array( 'order_id' => $order_id ) );
			return (int) $existing->id;
		}

		$row['created_at'] = $now;
		if ( empty( $row['tracking_code'] ) ) {
			$row['tracking_code'] = '';
		}
		if ( empty( $row['status'] ) ) {
			$row['status'] = 'pending';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $row );
		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get by order ID.
	 *
	 * @param int $order_id Order ID.
	 * @return object|null
	 */
	public static function get_by_order_id( $order_id ) {
		global $wpdb;
		$table = VisualPay_Transactions_Table::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", (int) $order_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row;
	}

	/**
	 * Get by row ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;
		$table = VisualPay_Transactions_Table::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row;
	}

	/**
	 * Count by status.
	 *
	 * @return array status => count
	 */
	public static function counts_by_status() {
		global $wpdb;
		$table = VisualPay_Transactions_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array(
			'pending'   => 0,
			'confirmed' => 0,
			'cancelled' => 0,
			'expired'   => 0,
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$status = strtolower( (string) $row['status'] );
				$out[ $status ] = (int) $row['cnt'];
			}
		}

		return $out;
	}

	/**
	 * Count transactions by status within a created_at range.
	 *
	 * @param string $from_mysql Y-m-d H:i:s.
	 * @param string $to_mysql   Y-m-d H:i:s.
	 * @return array{total:int,pending:int,confirmed:int,cancelled:int}
	 */
	public static function counts_by_status_between( $from_mysql, $to_mysql ) {
		global $wpdb;
		$table = VisualPay_Transactions_Table::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM {$table}
				WHERE created_at >= %s AND created_at <= %s
				GROUP BY status",
				$from_mysql,
				$to_mysql
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array(
			'total'     => 0,
			'pending'   => 0,
			'confirmed' => 0,
			'cancelled' => 0,
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$status = strtolower( (string) $row['status'] );
				$count  = (int) $row['cnt'];
				$out['total'] += $count;

				if ( 'pending' === $status ) {
					$out['pending'] += $count;
				} elseif ( 'confirmed' === $status ) {
					$out['confirmed'] += $count;
				} elseif ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
					$out['cancelled'] += $count;
				}
			}
		}

		return $out;
	}

	/**
	 * Daily confirmed revenue totals for the last N days (inclusive, site timezone).
	 *
	 * @param int $days Number of days.
	 * @return array<int, array{date:string,total:float}>
	 */
	public static function sum_confirmed_daily( $days = 30 ) {
		global $wpdb;

		$days  = max( 1, (int) $days );
		$table = VisualPay_Transactions_Table::table_name();
		$tz    = wp_timezone();
		$now   = new DateTimeImmutable( 'now', $tz );
		$start = $now->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(COALESCE(paid_at, updated_at, created_at)) AS day_key,
					COALESCE(SUM(usdt_amount), 0) AS total
				FROM {$table}
				WHERE status = %s
				AND COALESCE(paid_at, updated_at, created_at) >= %s
				AND COALESCE(paid_at, updated_at, created_at) <= %s
				GROUP BY day_key
				ORDER BY day_key ASC",
				'confirmed',
				$start->format( 'Y-m-d H:i:s' ),
				$end->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$totals = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$totals[ (string) $row['day_key'] ] = (float) $row['total'];
			}
		}

		$out = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day_key = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$out[]   = array(
				'date'  => $day_key,
				'total' => isset( $totals[ $day_key ] ) ? (float) $totals[ $day_key ] : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Sum confirmed USDT for a date range (inclusive, site timezone).
	 *
	 * @param string $from_mysql Y-m-d H:i:s.
	 * @param string $to_mysql   Y-m-d H:i:s.
	 * @return float
	 */
	public static function sum_confirmed_between( $from_mysql, $to_mysql ) {
		global $wpdb;
		$table = VisualPay_Transactions_Table::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(usdt_amount), 0) FROM {$table}
				WHERE status = %s
				AND COALESCE(paid_at, updated_at, created_at) >= %s
				AND COALESCE(paid_at, updated_at, created_at) <= %s",
				'confirmed',
				$from_mysql,
				$to_mysql
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $sum;
	}

	/**
	 * Query list for admin.
	 *
	 * @param array $args Args.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table    = VisualPay_Transactions_Table::table_name();
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;
		$status   = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$search_by = isset( $args['search_by'] ) ? sanitize_text_field( $args['search_by'] ) : '';

		$where  = array( '1=1' );
		$params = array();

		if ( $status && in_array( $status, array( 'pending', 'confirmed', 'cancelled', 'expired' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			if ( 'tx_id' === $search_by ) {
				$where[]  = 'tx_id LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			} elseif ( 'order_id' === $search_by ) {
				$where[]  = 'order_id = %d';
				$params[] = (int) $search;
			} else {
				$where[]  = '(tx_id LIKE %s OR tracking_code LIKE %s OR CAST(order_id AS CHAR) LIKE %s)';
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
		}

		$where_sql = implode( ' AND ', $where );

		// Table name is internal; WHERE clause uses $wpdb->prepare() placeholders when needed.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

		if ( $params ) {
			$total       = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$list_params = array_merge( $params, array( $per_page, $offset ) );
			$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$items = $wpdb->get_results( $wpdb->prepare( $list_sql, $per_page, $offset ) );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Convert unix timestamp to mysql datetime.
	 *
	 * @param mixed $unix Timestamp.
	 * @return string|null
	 */
	private static function unix_to_mysql( $unix ) {
		if ( empty( $unix ) ) {
			return null;
		}
		$unix = (int) $unix;
		if ( $unix <= 0 ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $unix );
	}

	/**
	 * Normalize numeric string.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function num( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Callback for array_filter.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_not_null( $value ) {
		return null !== $value && '' !== $value;
	}
}
