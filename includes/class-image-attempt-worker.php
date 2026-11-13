<?php
/**
 * Gateway-side worker for asynchronous, idempotent image attempts (image contract 1.1.0).
 *
 * `POST /images` with `client_request_id` and `async=1` claims the attempt exactly
 * like the synchronous path, stores the prepared request in a queue row and
 * returns 202. A loopback request (the mechanism of /images/jobs) or, as a
 * fallback, a WP-Cron event runs REST_Proxy::dispatch_image_request(), which
 * calls the provider, saves the result and bills it in the same transaction as
 * the synchronous path. The attempts table stays the single source of truth for
 * evidence; the queue row only carries the payload, a worker lease and a
 * dispatch token.
 *
 * Safety rules:
 * - Only the queued -> running transition (an atomic UPDATE) starts a worker, so
 *   concurrent loopback and cron triggers never dispatch twice.
 * - A worker that dies after the provider handoff leaves the attempt unknown; it
 *   is reported as failed (`worker_lost`) and never re-dispatched.
 * - The payload (prompt, reference images, mask) is deleted when the job ends.
 * - A queue row is reserved (status `preparing`) right after the claim, so a
 *   concurrent replay reports `queued` instead of "outcome unknown" while the
 *   request is still being prepared.
 * - A job that stays queued longer than QUEUE_TIMEOUT_SECONDS is closed as a
 *   conclusive zero-cost rejection (`queue_timeout`).
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Image_Attempt_Worker
 */
class Image_Attempt_Worker {

	const TABLE_NAME          = 'alorbach_image_attempt_jobs';
	const CRON_HOOK           = 'alorbach_process_image_attempt';
	const LEASE_SECONDS       = 2100;
	const REDISPATCH_AFTER    = 5;
	const CRON_DELAY_SECONDS  = 20;
	const MAX_PAYLOAD_BYTES   = 201326592;
	const POLL_AFTER_MS       = 2000;
	const QUEUE_TIMEOUT_SECONDS = 86400;
	const PREPARE_GRACE_SECONDS = 120;
	const LEASE_MARGIN_SECONDS  = 300;
	const PACKET_MARGIN_BYTES   = 1048576;
	const PAYLOAD_OVERHEAD_BYTES = 4096;

	/** @var bool|null */
	private static $table_ready = null;

	/** Register the WP-Cron fallback callback. */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}

	/**
	 * Refuse a request the queue cannot store (before the client request id is claimed).
	 * The limit is the smaller of MAX_PAYLOAD_BYTES and the database max_allowed_packet
	 * minus a margin; the check runs on the heavy fields (prompt, references, mask).
	 *
	 * @param array $payload Full request context as enqueue() stores it (the pre-claim call passes every
	 *                       known field; PAYLOAD_OVERHEAD_BYTES covers the fields computed after the claim).
	 * @return true|\WP_Error 413 image_request_too_large when it cannot fit.
	 */
	public static function payload_too_large( array $payload ) {
		$limit = self::payload_limit();
		$json  = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) + self::PAYLOAD_OVERHEAD_BYTES > $limit ) {
			return new \WP_Error( 'image_request_too_large', __( 'The image request is too large for the asynchronous queue; send fewer or smaller reference images or use a synchronous request.', 'alorbach-ai-gateway' ), array( 'status' => 413, 'max_payload_bytes' => $limit ) );
		}
		return true;
	}

	/** Create the queue table when missing (must run before a claim transaction: DDL would commit it). */
	public static function ensure_storage() {
		return self::ensure_table();
	}

	/** The one payload limit (pre-claim check and enqueue): min(MAX_PAYLOAD_BYTES, max_allowed_packet - margin). */
	private static function payload_limit() {
		global $wpdb;
		static $packet = null;
		if ( null === $packet ) {
			$row    = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$packet = is_array( $row ) ? (int) ( $row['Value'] ?? 0 ) : 0;
		}
		return $packet > 0 ? min( self::MAX_PAYLOAD_BYTES, max( 0, $packet - self::PACKET_MARGIN_BYTES ) ) : self::MAX_PAYLOAD_BYTES;
	}

	/**
	 * Reserve the queue row of a freshly claimed async attempt (status `preparing`).
	 *
	 * @param int   $owner_id Owner.
	 * @param array $attempt  Raw claimed attempt row.
	 * @return true|\WP_Error
	 */
	public static function reserve( $owner_id, array $attempt ) {
		global $wpdb;
		$attempt_id = absint( $attempt['attempt_id'] ?? 0 );
		// No dbDelta here: reserve() runs inside the claim transaction; the table is created before the claim (ensure_storage()).
		if ( $attempt_id < 1 || absint( $owner_id ) !== absint( $attempt['owner_id'] ?? 0 ) || ! self::table_exists() ) {
			return new \WP_Error( 'image_queue_unavailable', __( 'The image queue is unavailable.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'attempt_id'        => $attempt_id,
				'owner_id'          => absint( $owner_id ),
				'client_request_id' => (string) ( $attempt['client_request_id'] ?? '' ),
				'status'            => 'preparing',
				'token_hash'        => hash( 'sha256', wp_generate_password( 40, false, false ) ),
				'dispatch_count'    => 0,
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return false === $inserted ? new \WP_Error( 'image_queue_unavailable', __( 'The image request could not be queued.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) ) : true;
	}

	/**
	 * Queue a claimed attempt and trigger the worker.
	 *
	 * @param int   $owner_id Owner.
	 * @param array $attempt  Raw claimed attempt row.
	 * @param array $payload  Prepared request context (without the attempt row).
	 * @return true|\WP_Error
	 */
	public static function enqueue( $owner_id, array $attempt, array $payload ) {
		global $wpdb;
		$attempt_id = absint( $attempt['attempt_id'] ?? 0 );
		if ( $attempt_id < 1 || absint( $owner_id ) !== absint( $attempt['owner_id'] ?? 0 ) || ! self::ensure_table() ) {
			return new \WP_Error( 'image_queue_unavailable', __( 'The image queue is unavailable.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::payload_limit() ) {
			return new \WP_Error( 'image_queue_unavailable', __( 'The image request is too large to queue.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		$token = wp_generate_password( 40, false, false );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->last_error = '';
		// Normal path: promote the row reserved at claim time. The insert is the fallback for a missing reservation.
		$table    = self::table();
		$inserted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', token_hash = %s, payload = %s WHERE attempt_id = %d AND owner_id = %d AND status = 'preparing'", hash( 'sha256', $token ), $json, $attempt_id, absint( $owner_id ) ) );
		if ( 1 !== (int) $inserted ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'attempt_id'        => $attempt_id,
					'owner_id'          => absint( $owner_id ),
					'client_request_id' => (string) ( $attempt['client_request_id'] ?? '' ),
					'status'            => 'queued',
					'token_hash'        => hash( 'sha256', $token ),
					'payload'           => $json,
					'dispatch_count'    => 0,
					'created_at'        => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}
		if ( false === $inserted ) {
			return new \WP_Error( 'image_queue_unavailable', __( 'The image request could not be queued.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + self::CRON_DELAY_SECONDS, self::CRON_HOOK, array( $attempt_id ) );
		}
		self::dispatch( $attempt_id, $token );
		return true;
	}

	/**
	 * Read the queue row of an attempt; never creates storage.
	 *
	 * @param array|null $attempt Raw attempt row.
	 * @return array|null
	 */
	public static function get_job_for_attempt( $attempt ) {
		global $wpdb;
		if ( ! is_array( $attempt ) || empty( $attempt['attempt_id'] ) || ! self::table_exists() ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT attempt_id, owner_id, client_request_id, status, error_code, dispatch_count, last_dispatch_at, lease_expires_at, created_at, started_at, finished_at FROM {$table} WHERE attempt_id = %d AND owner_id = %d LIMIT 1", absint( $attempt['attempt_id'] ), absint( $attempt['owner_id'] ?? 0 ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Re-dispatch a stalled queued job or reap an expired lease.
	 *
	 * @param array|null $attempt Raw attempt row.
	 * @param array      $job     Queue row.
	 * @return bool Whether stored state changed.
	 */
	public static function maintain( $attempt, array $job ) {
		global $wpdb;
		if ( ! is_array( $attempt ) ) {
			return false;
		}
		$now   = time();
		$table = self::table();
		$open  = 'not_sent' === (string) ( $attempt['dispatch_status'] ?? '' ) && 'unknown' === (string) ( $attempt['outcome'] ?? '' );
		$age   = $now - self::to_timestamp( $job['created_at'] ?? '' );
		if ( in_array( $job['status'], array( 'preparing', 'queued' ), true ) && $age > ( 'queued' === $job['status'] ? max( 60, (int) apply_filters( 'alorbach_image_attempt_queue_timeout_seconds', self::QUEUE_TIMEOUT_SECONDS ) ) : self::PREPARE_GRACE_SECONDS ) ) {
			// Never started (preparation abandoned, or queued too long): a conclusive zero-cost rejection; the payload is dropped.
			$closed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', error_code = %s, finished_at = %s, payload = NULL, lease_expires_at = NULL WHERE attempt_id = %d AND status = %s", $open ? 'queue_timeout' : '', gmdate( 'Y-m-d H:i:s' ), absint( $job['attempt_id'] ), (string) $job['status'] ) );
			if ( 1 === (int) $closed && $open ) {
				Image_Attempts::mark_error( $attempt, array( 'dispatch_status' => 'not_sent', 'outcome' => 'rejected', 'source' => 'gateway_preflight_v1', 'cost_usd' => '0.00000000', 'provider_cost_usd' => '0.00000000', 'billing_status' => 'not_charged', 'failure_class' => 'local_configuration' ) );
			}
			return 1 === (int) $closed;
		}
		if ( 'queued' === $job['status'] ) {
			$last = ! empty( $job['last_dispatch_at'] ) ? self::to_timestamp( $job['last_dispatch_at'] ) : self::to_timestamp( $job['created_at'] );
			if ( $now - $last >= self::REDISPATCH_AFTER ) {
				$token = wp_generate_password( 40, false, false );
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET token_hash = %s WHERE attempt_id = %d AND status = 'queued'", hash( 'sha256', $token ), absint( $job['attempt_id'] ) ) );
				self::dispatch( absint( $job['attempt_id'] ), $token );
			}
			return false;
		}
		if ( 'running' === $job['status'] && ! empty( $job['lease_expires_at'] ) && self::to_timestamp( $job['lease_expires_at'] ) < $now ) {
			$reaped = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', error_code = %s, finished_at = %s, payload = NULL, lease_expires_at = NULL WHERE attempt_id = %d AND status = 'running' AND lease_expires_at < %s", 'worker_lost', gmdate( 'Y-m-d H:i:s' ), absint( $job['attempt_id'] ), gmdate( 'Y-m-d H:i:s' ) ) );
			if ( 1 === (int) $reaped && 'not_sent' === (string) ( $attempt['dispatch_status'] ?? '' ) && 'unknown' === (string) ( $attempt['outcome'] ?? '' ) ) {
				// The worker died before any provider handoff: conclusive, no cost.
				Image_Attempts::mark_error( $attempt, array( 'dispatch_status' => 'not_sent', 'outcome' => 'rejected', 'source' => 'gateway_preflight_v1', 'cost_usd' => '0.00000000', 'provider_cost_usd' => '0.00000000', 'billing_status' => 'not_charged', 'failure_class' => 'local_configuration' ) );
			}
			return 1 === (int) $reaped;
		}
		return false;
	}

	/**
	 * Loopback entry: verify the dispatch token and run the attempt.
	 *
	 * @param int    $attempt_id Attempt primary key.
	 * @param string $token      Plain dispatch token.
	 * @return array|\WP_Error
	 */
	public static function process_loopback( $attempt_id, $token ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( $attempt_id < 1 || '' === (string) $token || ! self::table_exists() ) {
			return new \WP_Error( 'invalid_attempt_token', __( 'Invalid image attempt token.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}
		$table = self::table();
		$hash  = $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$table} WHERE attempt_id = %d LIMIT 1", $attempt_id ) );
		if ( ! is_string( $hash ) || ! hash_equals( $hash, hash( 'sha256', (string) $token ) ) ) {
			return new \WP_Error( 'invalid_attempt_token', __( 'Invalid image attempt token.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}
		return array( 'attempt_id' => $attempt_id, 'result' => self::run( $attempt_id ) );
	}

	/**
	 * Run one queued attempt (loopback and WP-Cron entry point).
	 *
	 * @param int $attempt_id Attempt primary key.
	 * @return string started|skipped|failed
	 */
	public static function run( $attempt_id ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$job = self::lease( $attempt_id );
		if ( ! is_array( $job ) ) {
			return 'skipped';
		}
		$owner_id = absint( $job['owner_id'] );
		try {
			$payload = json_decode( (string) $job['payload'], true );
			$attempt = Image_Attempts::raw_by_id( $attempt_id, $owner_id );
			if ( ! is_array( $payload ) || ! is_array( $attempt ) ) {
				self::finish( $attempt_id, 'payload_invalid' );
				return 'failed';
			}
			if ( 'not_sent' !== (string) $attempt['dispatch_status'] || 'unknown' !== (string) $attempt['outcome'] ) {
				self::finish( $attempt_id, 'attempt_state_conflict' );
				return 'failed';
			}
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $owner_id );
			}
			self::extend_lease( $attempt_id, $payload );
			$payload['attempt'] = $attempt;
			$result             = REST_Proxy::dispatch_image_request( $payload, true );
			$code               = null;
			if ( is_wp_error( $result ) ) {
				$code = (string) $result->get_error_code();
				$data = $result->get_error_data();
				if ( 'image_request_rejected' === $code && is_array( $data ) && 402 === (int) ( $data['status'] ?? 0 ) ) {
					$code = 'insufficient_credits';
				}
			}
			self::finish( $attempt_id, $code );
			return null === $code ? 'started' : 'failed';
		} catch ( \Throwable $error ) {
			// Before the provider handoff was marked the request was not sent: conclusive, no cost.
			$released = REST_Proxy::release_unsent_image_attempt( $owner_id, (string) $job['client_request_id'] );
			self::finish( $attempt_id, $released ? 'worker_failed_before_send' : 'worker_exception' );
			return 'failed';
		}
	}

	/**
	 * Merge lifecycle fields for the status endpoints.
	 *
	 * @param array      $evidence Public evidence projection.
	 * @param array|null $row      Raw attempt row (null for ledger-only legacy evidence).
	 * @param array|null $job      Queue row, if the attempt was queued.
	 * @return array{attempt_id:string,status:string,execution_mode:string,result_available:bool,error:?array,queued_at:?string,started_at:?string,finished_at:?string,poll_after_ms:?int}
	 */
	public static function describe( array $evidence, $row, $job ) {
		$billing   = (string) ( $evidence['billing_status'] ?? '' );
		$outcome   = (string) ( $evidence['outcome'] ?? 'unknown' );
		$settled   = 'succeeded' === $outcome && in_array( $billing, array( 'recorded', 'attested' ), true ) && is_array( $row ) && Image_Attempts::can_release_result( $row );
		$mode      = is_array( $job ) ? 'async' : ( is_array( $row ) ? 'sync' : 'legacy' );
		$error     = null;
		$status    = 'failed';
		if ( ! empty( $evidence['conflict'] ) ) {
			$error = array( 'code' => 'attestation_conflict' );
		} elseif ( 'legacy' === $mode ) {
			$status = 'unknown';
		} elseif ( is_array( $job ) && 'queued' === $job['status'] ) {
			$status = 'queued';
		} elseif ( is_array( $job ) && 'preparing' === $job['status'] && 'not_sent' === (string) ( $evidence['dispatch_status'] ?? '' ) && 'unknown' === $outcome ) {
			$status = 'queued'; // Claimed, the request is still being prepared for the queue.
		} elseif ( is_array( $job ) && 'running' === $job['status'] ) {
			$status = 'running';
		} elseif ( $settled ) {
			$status = 'succeeded';
		} elseif ( ! is_array( $job ) && 'unknown' === $outcome && null === ( $evidence['failure_class'] ?? null ) && 'possibly_sent' === (string) ( $evidence['dispatch_status'] ?? '' ) && is_array( $row ) && ( time() - self::to_timestamp( $row['updated_at'] ?? '' ) ) < self::LEASE_SECONDS ) {
			$status = 'running'; // A synchronous request is still in flight.
		}
		if ( 'failed' === $status && null === $error ) {
			$code = is_array( $job ) && ! empty( $job['error_code'] ) ? (string) $job['error_code'] : '';
			if ( '' === $code ) {
				if ( 'succeeded' === $outcome ) {
					$code = 'image_billing_pending';
				} elseif ( 'rejected' === $outcome ) {
					$code = 'image_request_rejected';
				} elseif ( ! empty( $evidence['failure_class'] ) ) {
					$code = 'image_provider_error';
				} else {
					$code = 'outcome_unknown';
				}
			}
			$error = array( 'code' => $code );
		}
		if ( is_array( $error ) ) {
			$error['message'] = self::error_message( $error['code'] );
		}
		return array(
			'attempt_id'       => (string) ( $evidence['client_request_id'] ?? '' ),
			'status'           => $status,
			'execution_mode'   => $mode,
			'result_available' => 'succeeded' === $status,
			'error'            => $error,
			'queued_at'        => is_array( $job ) ? self::to_iso( $job['created_at'] ?? null ) : null,
			'started_at'       => is_array( $job ) ? self::to_iso( $job['started_at'] ?? null ) : null,
			'finished_at'      => is_array( $job ) ? self::to_iso( $job['finished_at'] ?? null ) : null,
			'poll_after_ms'    => in_array( $status, array( 'queued', 'running' ), true ) ? self::POLL_AFTER_MS : null,
		);
	}

	/** Generic, non-leaking message for a worker or evidence error code. */
	private static function error_message( $code ) {
		$messages = array(
			'insufficient_credits'   => __( 'The balance no longer covers this image.', 'alorbach-ai-gateway' ),
			'image_request_rejected' => __( 'The image request was rejected before dispatch.', 'alorbach-ai-gateway' ),
			'image_provider_error'   => __( 'The image provider request could not be completed.', 'alorbach-ai-gateway' ),
			'image_billing_pending'  => __( 'The image was produced but billing is not settled; do not resend this request.', 'alorbach-ai-gateway' ),
			'queue_timeout'          => __( 'The image request waited too long in the queue and was not sent; use a new client request id.', 'alorbach-ai-gateway' ),
			'worker_lost'            => __( 'The worker stopped before the outcome was known; do not resend this request.', 'alorbach-ai-gateway' ),
			'worker_failed_before_send' => __( 'The worker failed before the request was sent; nothing was charged. Use a new client request id.', 'alorbach-ai-gateway' ),
			'worker_exception'       => __( 'The worker failed unexpectedly; the outcome may be unknown.', 'alorbach-ai-gateway' ),
			'outcome_unknown'        => __( 'The outcome of this image request is unknown; do not resend this request.', 'alorbach-ai-gateway' ),
			'attestation_conflict'   => __( 'Conflicting reconciliation evidence blocks this result.', 'alorbach-ai-gateway' ),
		);
		return $messages[ $code ] ?? __( 'The image request could not be completed safely.', 'alorbach-ai-gateway' );
	}

	/** Atomically move queued -> running and return the full row (with payload). */
	private static function lease( $attempt_id ) {
		global $wpdb;
		if ( $attempt_id < 1 || ! self::table_exists() ) {
			return null;
		}
		$table    = self::table();
		$lease    = self::base_lease_seconds();
		$now      = gmdate( 'Y-m-d H:i:s' );
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', started_at = %s, lease_expires_at = %s WHERE attempt_id = %d AND status = 'queued'", $now, gmdate( 'Y-m-d H:i:s', time() + $lease ), $attempt_id ) );
		if ( 1 !== (int) $affected ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_id = %d LIMIT 1", $attempt_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Configured lease (filter alorbach_image_attempt_lease_seconds), 60 to 7200 seconds. */
	private static function base_lease_seconds() {
		return max( 60, min( 7200, (int) apply_filters( 'alorbach_image_attempt_lease_seconds', self::LEASE_SECONDS ) ) );
	}

	/**
	 * Stretch the stored lease so it is never shorter than the worst case of this job:
	 * n blocking provider calls (Hugging Face makes one per candidate) at the per-call
	 * timeout each, plus a margin.
	 *
	 * @param int   $attempt_id Attempt primary key.
	 * @param array $payload    Stored request context.
	 */
	private static function extend_lease( $attempt_id, array $payload ) {
		global $wpdb;
		$n      = max( 1, min( 10, (int) ( $payload['n'] ?? 1 ) ) );
		$refs   = is_array( $payload['reference_images'] ?? null ) ? $payload['reference_images'] : array();
		$model  = (string) ( $payload['model'] ?? '' );
		$plain  = (string) Cost_Matrix::parse_model_key( $model )['model'];
		$route  = API_Client::resolve_image_dispatch( $model, $refs );
		$timeout = API_Client::get_image_request_timeout_seconds( is_wp_error( $route ) ? '' : $route['provider'], $plain, (string) ( $payload['size'] ?? '' ), $n, $refs );
		$needed = $n * $timeout + self::LEASE_MARGIN_SECONDS;
		if ( $needed > self::base_lease_seconds() ) {
			$table = self::table();
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_expires_at = %s WHERE attempt_id = %d AND status = 'running'", gmdate( 'Y-m-d H:i:s', time() + $needed ), absint( $attempt_id ) ) );
		}
	}

	/** Close a job and drop its payload. */
	private static function finish( $attempt_id, $error_code ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'done', error_code = %s, finished_at = %s, payload = NULL, lease_expires_at = NULL WHERE attempt_id = %d AND status = 'running'", null === $error_code ? '' : substr( sanitize_key( (string) $error_code ), 0, 64 ), gmdate( 'Y-m-d H:i:s' ), absint( $attempt_id ) ) );
	}

	/** Trigger the loopback worker for a queued attempt. */
	private static function dispatch( $attempt_id, $token ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_dispatch_at = %s, dispatch_count = dispatch_count + 1 WHERE attempt_id = %d", gmdate( 'Y-m-d H:i:s' ), absint( $attempt_id ) ) );
		return Image_Jobs::dispatch_loopback( 'internal/images/attempts/process', wp_json_encode( array( 'attempt_id' => absint( $attempt_id ), 'token' => (string) $token ) ) );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	private static function table_exists() {
		global $wpdb;
		if ( true === self::$table_ready ) {
			return true;
		}
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$table_ready = is_string( $found ) && $table === $found;
		return self::$table_ready;
	}

	/** Create the queue table on the first async request (mutating path only). */
	private static function ensure_table() {
		global $wpdb;
		if ( self::table_exists() ) {
			return true;
		}
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			attempt_id bigint(20) unsigned NOT NULL,
			owner_id bigint(20) unsigned NOT NULL,
			client_request_id varchar(128) NOT NULL,
			status varchar(16) NOT NULL,
			token_hash char(64) NOT NULL,
			payload longtext DEFAULT NULL,
			error_code varchar(64) DEFAULT NULL,
			dispatch_count int(10) unsigned NOT NULL DEFAULT 0,
			last_dispatch_at datetime DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			started_at datetime DEFAULT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (attempt_id),
			KEY owner_request (owner_id,client_request_id),
			KEY status_lease (status,lease_expires_at)
		) {$charset_collate} ENGINE=InnoDB;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::$table_ready = null;
		return self::table_exists();
	}

	private static function to_timestamp( $mysql_utc ) {
		$time = strtotime( (string) $mysql_utc . ' UTC' );
		return false === $time ? 0 : $time;
	}

	private static function to_iso( $mysql_utc ) {
		if ( empty( $mysql_utc ) ) {
			return null;
		}
		$time = self::to_timestamp( $mysql_utc );
		return $time > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null;
	}
}
