<?php
/**
 * Immutable transaction ledger for credit accounting.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ledger
 */
class Ledger {

	const TABLE_NAME = 'alorbach_ledger';
	const DB_VERSION = '1.2';

	/**
	 * Create the ledger table.
	 */
	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			transaction_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			transaction_type varchar(32) NOT NULL,
			model_used varchar(64) DEFAULT NULL,
			uc_amount bigint(20) NOT NULL,
			api_cost_uc bigint(20) DEFAULT NULL,
			raw_input_tokens int(11) DEFAULT NULL,
			cached_tokens int(11) DEFAULT NULL,
			raw_output_tokens int(11) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			request_signature varchar(255) DEFAULT NULL,
			PRIMARY KEY (transaction_id),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY transaction_type (transaction_type),
			KEY request_signature (request_signature(64))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'alorbach_db_version', self::DB_VERSION );
	}

	/**
	 * Upgrade database schema if needed (e.g. after plugin update).
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'alorbach_db_version', '0' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
			self::create_table();
		}
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Insert a transaction.
	 *
	 * @param int         $user_id            User ID.
	 * @param string      $transaction_type   Type: subscription_credit, chat_deduction, image_deduction, audio_deduction, admin_credit, balance_forward.
	 * @param string|null $model_used         Model name.
	 * @param int         $uc_amount          Amount (positive for credit, negative for deduction).
	 * @param int|null    $raw_input_tokens   Input tokens.
	 * @param int|null    $cached_tokens      Cached tokens.
	 * @param int|null    $raw_output_tokens  Output tokens.
	 * @param string|null $request_signature  Request hash for idempotency.
	 * @param int|null    $api_cost_uc        Raw API cost in UC (for deductions; used to separate AI budget from user charge).
	 * @return int|false Insert ID or false.
	 */
	public static function insert_transaction( $user_id, $transaction_type, $model_used, $uc_amount, $raw_input_tokens = null, $cached_tokens = null, $raw_output_tokens = null, $request_signature = null, $api_cost_uc = null ) {
		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'            => $user_id,
				'transaction_type'   => sanitize_text_field( $transaction_type ),
				'model_used'         => $model_used ? sanitize_text_field( $model_used ) : null,
				'uc_amount'          => (int) $uc_amount,
				'api_cost_uc'        => $api_cost_uc !== null ? (int) $api_cost_uc : null,
				'raw_input_tokens'   => $raw_input_tokens !== null ? (int) $raw_input_tokens : null,
				'cached_tokens'      => $cached_tokens !== null ? (int) $cached_tokens : null,
				'raw_output_tokens'  => $raw_output_tokens !== null ? (int) $raw_output_tokens : null,
				'request_signature'  => $request_signature ? sanitize_text_field( $request_signature ) : null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get user balance (sum of all transactions).
	 *
	 * @param int $user_id User ID.
	 * @return int Balance in UC.
	 */
	public static function get_balance( $user_id ) {
		global $wpdb;
		$table = self::get_table_name();
		$sum   = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(uc_amount), 0) FROM {$table} WHERE user_id = %d",
			$user_id
		) );
		return (int) $sum;
	}

	/**
	 * Get usage (sum of negative transactions) for a period.
	 *
	 * @param int    $user_id User ID.
	 * @param string $start   Start date (Y-m-d).
	 * @param string $end     End date (Y-m-d).
	 * @return int Usage in UC (positive number).
	 */
	public static function get_usage( $user_id, $start, $end ) {
		global $wpdb;
		$table = self::get_table_name();
		$sum   = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(ABS(SUM(uc_amount)), 0) FROM {$table} WHERE user_id = %d AND uc_amount < 0 AND created_at >= %s AND created_at <= %s",
			$user_id,
			$start . ' 00:00:00',
			$end . ' 23:59:59'
		) );
		return (int) $sum;
	}

	/**
	 * Get usage for current month.
	 *
	 * @param int $user_id User ID.
	 * @return int Usage in UC.
	 */
	public static function get_usage_this_month( $user_id ) {
		$start = gmdate( 'Y-m-01' );
		$end   = gmdate( 'Y-m-t' );
		return self::get_usage( $user_id, $start, $end );
	}

	/**
	 * Get balances for all users in a single query.
	 *
	 * @return array Map of user_id (int) => balance (int) in UC.
	 */
	public static function get_all_balances() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.
		$rows  = $wpdb->get_results( "SELECT user_id, COALESCE(SUM(uc_amount), 0) AS balance FROM {$table} WHERE user_id > 0 GROUP BY user_id", ARRAY_A );
		$result = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row['user_id'] ] = (int) $row['balance'];
			}
		}
		return $result;
	}

	/**
	 * Get usage this month for all users in a single query.
	 *
	 * @return array Map of user_id (int) => usage (int) in UC.
	 */
	public static function get_all_usage_this_month() {
		global $wpdb;
		$table = self::get_table_name();
		$start = gmdate( 'Y-m-01' ) . ' 00:00:00';
		$end   = gmdate( 'Y-m-t' ) . ' 23:59:59';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, COALESCE(ABS(SUM(uc_amount)), 0) AS usage_uc FROM {$table} WHERE user_id > 0 AND uc_amount < 0 AND created_at >= %s AND created_at <= %s GROUP BY user_id",
			$start,
			$end
		), ARRAY_A );
		$result = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row['user_id'] ] = (int) $row['usage_uc'];
			}
		}
		return $result;
	}

	/**
	 * Get transactions with pagination.
	 *
	 * @param array $args {
	 *     Optional. Arguments.
	 *     @type int    $per_page   Rows per page. Default 50.
	 *     @type int    $page       1-based page number. Default 1.
	 *     @type int    $user_id    Filter by user ID. Default none.
	 *     @type string $date_from  Start date (Y-m-d). Default none.
	 *     @type string $date_to    End date (Y-m-d). Default none.
	 * }
	 * @return array{rows: array, total: int}
	 */
	public static function get_transactions( $args = array() ) {
		global $wpdb;
		$table = self::get_table_name();

		$per_page  = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset    = ( $page - 1 ) * $per_page;
		$user_id   = isset( $args['user_id'] ) ? (int) $args['user_id'] : null;
		$date_from = isset( $args['date_from'] ) ? sanitize_text_field( $args['date_from'] ) : null;
		$date_to   = isset( $args['date_to'] ) ? sanitize_text_field( $args['date_to'] ) : null;

		$where = array( '1=1' );
		$values = array();

		if ( $user_id !== null && $user_id > 0 ) {
			$where[] = 'l.user_id = %d';
			$values[] = $user_id;
		}
		if ( $date_from ) {
			$where[] = 'l.created_at >= %s';
			$values[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[] = 'l.created_at <= %s';
			$values[] = $date_to . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );

		$total = 0;
		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} l WHERE {$where_sql}",
				...$values
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant, not user input.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$rows = array();
		if ( $total > 0 ) {
			$values[] = $per_page;
			$values[] = $offset;
			if ( count( $values ) > 2 ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT l.transaction_id, l.user_id, l.transaction_type, l.model_used, l.uc_amount, l.api_cost_uc, l.raw_input_tokens, l.cached_tokens, l.raw_output_tokens, l.created_at FROM {$table} l WHERE {$where_sql} ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
					...$values
				), ARRAY_A );
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT transaction_id, user_id, transaction_type, model_used, uc_amount, api_cost_uc, raw_input_tokens, cached_tokens, raw_output_tokens, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				), ARRAY_A );
			}
		}

		return array( 'rows' => $rows ? $rows : array(), 'total' => $total );
	}

	/**
	 * Check if request signature already exists (idempotency).
	 *
	 * @param string $signature Request signature.
	 * @return bool
	 */
	public static function signature_exists( $signature ) {
		global $wpdb;
		$table = self::get_table_name();
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE request_signature = %s",
			$signature
		) );
		return (int) $count > 0;
	}
}
