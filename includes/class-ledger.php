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
	const DB_VERSION = '1.0';

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
			raw_input_tokens int(11) DEFAULT NULL,
			cached_tokens int(11) DEFAULT NULL,
			raw_output_tokens int(11) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			request_signature varchar(255) DEFAULT NULL,
			PRIMARY KEY (transaction_id),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY transaction_type (transaction_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'alorbach_db_version', self::DB_VERSION );
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
	 * @return int|false Insert ID or false.
	 */
	public static function insert_transaction( $user_id, $transaction_type, $model_used, $uc_amount, $raw_input_tokens = null, $cached_tokens = null, $raw_output_tokens = null, $request_signature = null ) {
		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'            => $user_id,
				'transaction_type'   => sanitize_text_field( $transaction_type ),
				'model_used'         => $model_used ? sanitize_text_field( $model_used ) : null,
				'uc_amount'          => (int) $uc_amount,
				'raw_input_tokens'   => $raw_input_tokens !== null ? (int) $raw_input_tokens : null,
				'cached_tokens'      => $cached_tokens !== null ? (int) $cached_tokens : null,
				'raw_output_tokens'  => $raw_output_tokens !== null ? (int) $raw_output_tokens : null,
				'request_signature'  => $request_signature ? sanitize_text_field( $request_signature ) : null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
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
			"SELECT COALESCE(ABS(SUM(uc_amount)), 0) FROM {$table} WHERE user_id = %d AND uc_amount < 0 AND created_at >= %s AND created_at < %s",
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
