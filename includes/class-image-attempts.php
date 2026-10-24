<?php
/**
 * Durable, owner-scoped evidence for direct image requests.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores direct-image request claims and recoverable successful responses.
 * Tables are created lazily on the first new direct-image request. Reading
 * evidence never creates or upgrades storage.
 */
class Image_Attempts {
	const TABLE_NAME = 'alorbach_image_attempts';
	const SCHEMA = 'image-attempt.v1';
	const SOURCE = 'gateway_attempt_v1';
	const MAX_RESULT_BYTES = 67108864;

	private static $table_ready = null;
	private static $diagnostics_column_ready = null;

	/** Return a canonical hash for the normalized request sent to the provider. */
	public static function request_hash( $owner_id, $client_request_id, array $request ) {
		$references = array();
		foreach ( is_array( $request['reference_images'] ?? null ) ? $request['reference_images'] : array() as $image ) {
			if ( ! is_array( $image ) ) {
				$references[] = array( 'sha256' => hash( 'sha256', wp_json_encode( $image ) ), 'mime_type' => '' );
				continue;
			}
			$data = (string) ( $image['b64_json'] ?? $image['b64_image'] ?? '' );
			$mime = strtolower( trim( (string) ( $image['mime_type'] ?? 'image/png' ) ) );
			$references[] = array(
				'mime_type' => $mime,
				'sha256'    => hash( 'sha256', $data ),
			);
		}

		$canonical = array(
			'schema'            => 'gateway-image-request.v1',
			'owner_id'          => (int) $owner_id,
			'client_request_id' => (string) $client_request_id,
			'model'             => (string) ( $request['model'] ?? '' ),
			'prompt'            => (string) ( $request['prompt'] ?? '' ),
			'size'              => (string) ( $request['size'] ?? '' ),
			'n'                 => (int) ( $request['n'] ?? 1 ),
			'quality'           => (string) ( $request['quality'] ?? '' ),
			'output_format'     => (string) ( $request['output_format'] ?? '' ),
			'background'        => (string) ( $request['background'] ?? '' ),
			'aspect_ratio'      => (string) ( $request['aspect_ratio'] ?? '' ),
			'provider_options'  => self::canonicalize( $request['provider_options'] ?? array() ),
			'mask_image'        => self::hash_binary_value( $request['mask_image'] ?? $request['mask'] ?? null ),
			'input_reference'   => self::hash_binary_value( $request['input_reference'] ?? null ),
			'input_reference_data_url' => self::hash_binary_value( $request['input_reference_data_url'] ?? null ),
			'frames'            => self::hash_binary_value( $request['frames'] ?? null ),
			'reference_images'  => $references,
		);
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	/** Classify a provider error without exposing its raw message or body. */
	public static function classify_error( $error_code, $error_data = array() ) {
		$error_code = sanitize_key( (string) $error_code );
		$error_data = is_array( $error_data ) ? $error_data : array();
		$not_sent = array( 'no_provider', 'no_api_key', 'invalid_provider', 'invalid_image_request', 'reference_images_unsupported', 'invalid_reference_image', 'image_ledger_engine_unavailable', 'image_ledger_engine_unsupported' );
		if ( in_array( $error_code, $not_sent, true ) ) {
			return array( 'dispatch_status' => 'not_sent', 'outcome' => 'rejected', 'source' => 'gateway_preflight_v1', 'cost_usd' => '0.00000000', 'provider_cost_usd' => '0.00000000', 'billing_status' => 'not_charged', 'failure_class' => in_array( $error_code, array( 'no_provider', 'no_api_key', 'invalid_provider', 'image_ledger_engine_unavailable', 'image_ledger_engine_unsupported' ), true ) ? 'local_configuration' : 'invalid_response' );
		}
		// Upstream status codes alone do not prove that the provider did not
		// accept or meter a request. Only the explicit local preflight errors
		// above are conclusive; everything after handoff remains unknown.
		$diagnostic = array(
			'dispatch_status' => 'possibly_sent',
			'outcome' => 'unknown',
			'source' => self::SOURCE,
			'failure_class' => 'transport_error',
			'upstream_status' => null,
			'provider_error_code' => null,
			'retry_after_seconds' => null,
		);
		if ( 'api_error' === $error_code && isset( $error_data['status'] ) && is_numeric( $error_data['status'] ) ) {
			$diagnostic['failure_class'] = 'provider_http_error';
			$diagnostic['upstream_status'] = min( 599, max( 100, absint( $error_data['status'] ) ) );
			$safe_fields = Image_Error_Diagnostics::normalize_fields( $error_data );
			$diagnostic['provider_error_code'] = $safe_fields['provider_error_code'];
			$diagnostic['provider_error_reason'] = $safe_fields['provider_error_reason'];
			$diagnostic['provider_error_param'] = $safe_fields['provider_error_param'];
			$diagnostic['provider_request_id'] = $safe_fields['provider_request_id'];
			$diagnostic['provider_error_message_sha256'] = $safe_fields['provider_error_message_sha256'];
			$diagnostic['provider_error_message'] = $safe_fields['provider_error_message'];
			$retry_after = $error_data['retry_after'] ?? null;
			if ( is_scalar( $retry_after ) && preg_match( '/^\d{1,6}$/', (string) $retry_after ) ) {
				$diagnostic['retry_after_seconds'] = min( 3600, absint( $retry_after ) );
			}
		}
		if ( 'invalid_image_response' === $error_code ) {
			$diagnostic['failure_class'] = 'invalid_response';
		}
		return $diagnostic;
	}

	/** Serialize claims by owner and client request ID before taking row locks. */
	public static function claim( $owner_id, $client_request_id, $request_hash, $client_request_hash = null ) {
		global $wpdb;
		$owner_id = absint( $owner_id );
		$client_request_id = self::sanitize_client_request_id( $client_request_id );
		if ( $owner_id < 1 || '' === $client_request_id ) {
			return self::claim_locked( $owner_id, $client_request_id, $request_hash, $client_request_hash );
		}
		if ( ! self::ensure_storage() ) {
			return new \WP_Error( 'image_attempt_storage_unavailable', __( 'This image attempt could not be recorded safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$lock_name = 'bws_img_claim_' . substr( hash( 'sha256', $owner_id . ':' . $client_request_id ), 0, 40 );
		$wpdb->last_error = '';
		$lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '' !== (string) $wpdb->last_error || '1' !== (string) $lock ) {
			return new \WP_Error( 'image_attempt_busy', __( 'This image request is already being claimed. Read its evidence and try again.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}
		try {
			return self::claim_locked( $owner_id, $client_request_id, $request_hash, $client_request_hash );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** Atomically create one immutable first claim; a client ID is never resent. */
	private static function claim_locked( $owner_id, $client_request_id, $request_hash, $client_request_hash = null ) {
		global $wpdb;
		if ( ! self::ensure_table() ) {
			return new \WP_Error( 'image_attempt_storage_unavailable', __( 'This image attempt could not be recorded safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}

		$owner_id = absint( $owner_id );
		$client_request_id = self::sanitize_client_request_id( $client_request_id );
		$request_hash = strtolower( trim( (string) $request_hash ) );
		$client_request_hash = self::normalize_hash_or_null( $client_request_hash );
		if ( $owner_id < 1 || '' === $client_request_id || ! preg_match( '/^[a-f0-9]{64}$/', $request_hash ) || ( null !== $client_request_hash && ! preg_match( '/^[a-f0-9]{64}$/', $client_request_hash ) ) ) {
			return new \WP_Error( 'image_attempt_binding_invalid', __( 'This image attempt cannot be bound to its saved request.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		$engine_check = self::verify_attempt_storage();
		if ( is_wp_error( $engine_check ) ) {
			return $engine_check;
		}

		$table = self::get_table_name();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'image_attempt_claim_failed', __( 'This image attempt could not be claimed safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$wpdb->last_error = '';
		$latest = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d AND client_request_id = %s ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE", $owner_id, $client_request_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attempt_claim_failed', __( 'This image attempt could not be claimed safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}

		if ( is_array( $latest ) ) {
			if ( class_exists( __NAMESPACE__ . '\\Image_Attestation' ) && Image_Attestation::has_conflict( $owner_id, $client_request_id ) ) {
				$wpdb->query( 'COMMIT' );
				return array( 'state' => 'blocked', 'attempt' => $latest );
			}
			if ( ! self::same_binding( $latest, $request_hash, $client_request_hash ) ) {
				$wpdb->query( 'COMMIT' );
				return array( 'state' => 'conflict', 'attempt' => $latest );
			}
			if ( 'succeeded' === (string) $latest['outcome'] ) {
				$wpdb->query( 'COMMIT' );
				return array( 'state' => 'succeeded', 'attempt' => $latest );
			}
			// A client_request_id names one immutable provider attempt. A retry
			// must use a new job UUID; never redispatch this key, even after a
			// conclusive local rejection.
			$wpdb->query( 'COMMIT' );
			return self::state_for_existing_row( $latest );
		} else {
			$attempt_number = 1;
		}

		$evidence_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::new_evidence_id();
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$table,
			array(
				'owner_id'           => $owner_id,
				'client_request_id'  => $client_request_id,
				'attempt_number'    => $attempt_number,
				'revision'          => 1,
			'request_hash'      => $request_hash,
				'client_request_hash' => $client_request_hash,
				'dispatch_status'   => 'not_sent',
				'outcome'           => 'unknown',
				'cost_usd'          => null,
				'evidence_id'       => $evidence_id,
				'source'            => self::SOURCE,
				'result_json'       => null,
				'result_hash'       => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', null, '%s', '%s', null, null, '%s', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$latest = self::latest_row( $owner_id, $client_request_id );
			if ( is_array( $latest ) && self::same_binding( $latest, $request_hash, $client_request_hash ) ) {
				return self::state_for_existing_row( $latest );
			}
			return new \WP_Error( 'image_attempt_claim_failed', __( 'This image attempt could not be claimed safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}

		$committed = $wpdb->query( 'COMMIT' );
		if ( false === $committed ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attempt_claim_failed', __( 'This image attempt could not be claimed safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$row = self::row_by_id( (int) $wpdb->insert_id, $owner_id );
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'image_attempt_claim_failed', __( 'This image attempt could not be claimed safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		return array( 'state' => 'claimed', 'attempt' => $row );
	}

	/** Mark the claim as possibly sent immediately before the provider call. */
	public static function mark_possibly_sent( array $attempt ) {
		return self::update_attempt( $attempt, array(
			'dispatch_status' => 'possibly_sent',
			'revision'        => absint( $attempt['revision'] ?? 0 ) + 1,
			'updated_at'      => current_time( 'mysql', true ),
		) );
	}

	/** Save an unresolved provider error or a confirmed pre-dispatch rejection. */
	public static function mark_error( array $attempt, array $classification ) {
		if ( ! self::ensure_storage() ) {
			return new \WP_Error( 'image_attempt_storage_unavailable', __( 'This image attempt could not be recorded safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}

		$values = array(
			'dispatch_status' => in_array( (string) ( $classification['dispatch_status'] ?? '' ), array( 'not_sent', 'possibly_sent' ), true ) ? $classification['dispatch_status'] : 'possibly_sent',
			'outcome'         => in_array( (string) ( $classification['outcome'] ?? '' ), array( 'rejected', 'unknown' ), true ) ? $classification['outcome'] : 'unknown',
			'cost_usd'        => self::normalize_cost( $classification['cost_usd'] ?? null ),
			'provider_cost_usd' => self::normalize_cost( $classification['provider_cost_usd'] ?? null ),
			'billing_status'  => in_array( (string) ( $classification['billing_status'] ?? '' ), array( 'pending', 'recorded', 'attested', 'not_charged', 'unresolved', 'conflict' ), true ) ? (string) $classification['billing_status'] : ( 'not_sent' === ( $classification['dispatch_status'] ?? '' ) ? 'not_charged' : 'unresolved' ),
			'failure_class' => in_array( (string) ( $classification['failure_class'] ?? '' ), array( 'local_configuration', 'transport_error', 'provider_http_error', 'invalid_response' ), true ) ? $classification['failure_class'] : null,
			'upstream_status' => isset( $classification['upstream_status'] ) ? min( 599, max( 100, absint( $classification['upstream_status'] ) ) ) : null,
			'provider_error_code' => self::safe_provider_error_code( $classification['provider_error_code'] ?? null ),
			'retry_after_seconds' => isset( $classification['retry_after_seconds'] ) ? min( 3600, absint( $classification['retry_after_seconds'] ) ) : null,
			'source'          => sanitize_key( (string) ( $classification['source'] ?? self::SOURCE ) ),
			'revision'        => absint( $attempt['revision'] ?? 0 ) + 1,
			'updated_at'      => current_time( 'mysql', true ),
		);

		if ( array_key_exists( 'provider_error_reason', $classification ) ) {
			$fields = Image_Error_Diagnostics::normalize_fields( $classification );
			$stored = array(
				'schema' => 'provider-error-diagnostics.v2',
				'provider_error_reason' => $fields['provider_error_reason'],
			);
			foreach ( array( 'provider_error_param', 'provider_request_id', 'provider_error_message_sha256', 'provider_error_message' ) as $key ) {
				if ( null !== $fields[ $key ] ) {
					$stored[ $key ] = $fields[ $key ];
				}
			}
			$json = wp_json_encode( $stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) || strlen( $json ) > 6144 ) {
				return new \WP_Error( 'image_attempt_storage_unavailable', __( 'This image attempt could not be recorded safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
			}
			$values['provider_error_diagnostics'] = $json;
		}

		return self::update_attempt( $attempt, $values );
	}

	/** Persist the complete successful response before returning it to the caller. */
	public static function mark_succeeded( array $attempt, array $result, $provider_cost_usd = null, $source = self::SOURCE ) {
		// Provider responses cannot supply Gateway billing fields before the
		// corresponding ledger transaction has committed.
		unset( $result['cost_uc'], $result['cost_credits'], $result['cost_usd'] );
		$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_RESULT_BYTES ) {
			return false;
		}
		return self::update_attempt( $attempt, array(
			'dispatch_status' => 'possibly_sent',
			'outcome'         => 'succeeded',
			'cost_usd'        => null,
			'provider_cost_usd' => self::normalize_cost( $provider_cost_usd ),
			'billing_status'  => 'pending',
			'source'          => sanitize_key( (string) $source ) ?: self::SOURCE,
			'result_json'     => $json,
			'result_hash'     => hash( 'sha256', $json ),
			'revision'        => absint( $attempt['revision'] ?? 0 ) + 1,
			'updated_at'      => current_time( 'mysql', true ),
		) );
	}

	/** Finalize user billing atomically with its Gateway ledger row. */
	public static function mark_billing_recorded( array $attempt, array $result, $user_cost_usd, $provider_cost_usd = null ) {
		$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_RESULT_BYTES ) {
			return false;
		}
		return self::update_attempt( $attempt, array(
			'cost_usd' => self::normalize_cost( $user_cost_usd ),
			'provider_cost_usd' => self::normalize_cost( $provider_cost_usd ),
			'billing_status' => 'recorded',
			'result_json' => $json,
			'result_hash' => hash( 'sha256', $json ),
			'revision' => absint( $attempt['revision'] ?? 0 ) + 1,
			'updated_at' => current_time( 'mysql', true ),
		) );
	}

	/** Read the owner's latest durable evidence without creating storage. */
	public static function lookup( $owner_id, $client_request_id ) {
		$owner_id = absint( $owner_id );
		$client_request_id = self::sanitize_client_request_id( $client_request_id );
		if ( $owner_id < 1 || '' === $client_request_id ) {
			return null;
		}
		$row = self::latest_row( $owner_id, $client_request_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( is_array( $row ) ) {
			$evidence = self::public_evidence( $row );
			if ( class_exists( __NAMESPACE__ . '\\Image_Attestation' ) && Image_Attestation::has_conflict( $owner_id, $client_request_id ) ) {
				$evidence['outcome'] = 'unknown';
				$evidence['cost_usd'] = null;
				$evidence['provider_cost_usd'] = null;
				$evidence['billing_status'] = 'conflict';
				$evidence['source'] = 'gateway_attestation_conflict_v1';
				$evidence['conflict'] = true;
				$evidence['retry_safe'] = false;
			}
			return $evidence;
		}
		return self::legacy_ledger_evidence( $owner_id, $client_request_id );
	}

	/** Read a persisted successful result, bound to the owner's evidence id and request hash. */
	public static function read_result( $owner_id, $client_request_id, $evidence_id, $request_hash ) {
		$owner_id = absint( $owner_id );
		$client_request_id = self::sanitize_client_request_id( $client_request_id );
		$row = self::latest_row( $owner_id, $client_request_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! is_array( $row ) || 'succeeded' !== (string) $row['outcome'] || '' === (string) ( $row['result_json'] ?? '' ) || ! self::can_release_result( $row ) || ( class_exists( __NAMESPACE__ . '\\Image_Attestation' ) && Image_Attestation::has_conflict( $owner_id, $client_request_id ) ) ) {
			return null;
		}
		if ( ! hash_equals( (string) $row['evidence_id'], sanitize_text_field( (string) $evidence_id ) ) || ! hash_equals( (string) $row['request_hash'], strtolower( trim( (string) $request_hash ) ) ) ) {
			return null;
		}
		$json = (string) $row['result_json'];
		if ( '' === (string) ( $row['result_hash'] ?? '' ) || ! hash_equals( (string) $row['result_hash'], hash( 'sha256', $json ) ) ) {
			return null;
		}
		$result = json_decode( $json, true );
		return is_array( $result ) ? $result : null;
	}

	/** Release persisted image bytes only after the user cost is settled. */
	public static function can_release_result( array $row ) {
		$cost = $row['cost_usd'] ?? null;
		if ( null === $cost || ! is_numeric( $cost ) || ! is_finite( (float) $cost ) || (float) $cost < 0 || ! empty( $row['conflict_status'] ) ) {
			return false;
		}
		if ( 'recorded' === (string) ( $row['billing_status'] ?? '' ) ) {
			return true;
		}
		return 'attested' === (string) ( $row['billing_status'] ?? '' ) && 'gateway_signed_attestation_v1' === (string) ( $row['source'] ?? '' );
	}

	/** Return an allowlisted public evidence projection. */
	public static function public_evidence( array $row ) {
		$cost = null;
		if ( array_key_exists( 'cost_usd', $row ) && null !== $row['cost_usd'] && is_numeric( $row['cost_usd'] ) && (float) $row['cost_usd'] >= 0 ) {
			$cost = number_format( (float) $row['cost_usd'], 8, '.', '' );
		}
		$provider_cost = null;
		if ( array_key_exists( 'provider_cost_usd', $row ) && null !== $row['provider_cost_usd'] && is_numeric( $row['provider_cost_usd'] ) && (float) $row['provider_cost_usd'] >= 0 ) {
			$provider_cost = number_format( (float) $row['provider_cost_usd'], 8, '.', '' );
		}
		$source = sanitize_key( (string) ( $row['source'] ?? self::SOURCE ) );
		$outcome = (string) ( $row['outcome'] ?? 'unknown' );
		$dispatch = (string) ( $row['dispatch_status'] ?? 'possibly_sent' );
		$billing = (string) ( $row['billing_status'] ?? 'unresolved' );
		$conflict = ! empty( $row['conflict_status'] );
		$retry_safe = ! $conflict && 'rejected' === $outcome && ( ( 'not_sent' === $dispatch && 'not_charged' === $billing && null !== $cost && 0.0 === (float) $cost && null !== $provider_cost && 0.0 === (float) $provider_cost ) || ( 'gateway_signed_attestation_v1' === $source && null !== $cost && null !== $provider_cost && in_array( $billing, array( 'recorded', 'attested' ), true ) ) );
		$evidence_status = 'gateway_ledger_v1' === $source ? 'cost_only' : ( in_array( $source, array( 'gateway_signed_attestation_v1', 'gateway_attestation_conflict_v1' ), true ) ? 'attestation_verified' : 'attempt_bound' );
		$provider_error_diagnostics = self::public_provider_error_diagnostics( $row['provider_error_diagnostics'] ?? null );

		return array(
			'schema'              => self::SCHEMA,
			'client_request_id'   => self::sanitize_client_request_id( $row['client_request_id'] ?? '' ),
			'request_hash'        => self::valid_hash_or_null( $row['request_hash'] ?? null ),
			'client_request_hash' => self::valid_hash_or_null( $row['client_request_hash'] ?? null ),
			'dispatch_status'     => in_array( (string) ( $row['dispatch_status'] ?? '' ), array( 'not_sent', 'possibly_sent' ), true ) ? (string) $row['dispatch_status'] : 'possibly_sent',
			'outcome'             => in_array( (string) ( $row['outcome'] ?? '' ), array( 'rejected', 'succeeded', 'unknown' ), true ) ? (string) $row['outcome'] : 'unknown',
			'cost_usd'            => $cost,
			'provider_cost_usd'   => $provider_cost,
			'billing_status'      => in_array( (string) ( $row['billing_status'] ?? '' ), array( 'pending', 'recorded', 'attested', 'not_charged', 'unresolved', 'conflict' ), true ) ? (string) $row['billing_status'] : 'unresolved',
			'evidence_id'         => sanitize_text_field( (string) ( $row['evidence_id'] ?? '' ) ),
			'source'              => sanitize_key( (string) ( $row['source'] ?? self::SOURCE ) ),
			'revision'            => absint( $row['revision'] ?? 0 ),
			'evidence_status'     => $evidence_status,
			'retry_safe'          => $retry_safe,
			'conflict'            => $conflict,
			'failure_class'       => in_array( (string) ( $row['failure_class'] ?? '' ), array( 'local_configuration', 'transport_error', 'provider_http_error', 'invalid_response' ), true ) ? (string) $row['failure_class'] : null,
			'upstream_status'     => isset( $row['upstream_status'] ) && is_numeric( $row['upstream_status'] ) ? absint( $row['upstream_status'] ) : null,
			'provider_error_code' => self::safe_provider_error_code( $row['provider_error_code'] ?? null ),
			'provider_error_reason' => $provider_error_diagnostics['provider_error_reason'],
			'provider_error_param' => $provider_error_diagnostics['provider_error_param'],
			'provider_request_id' => $provider_error_diagnostics['provider_request_id'],
			'provider_error_message_sha256' => $provider_error_diagnostics['provider_error_message_sha256'],
			'provider_error_message' => $provider_error_diagnostics['provider_error_message'],
			'retry_after_seconds' => isset( $row['retry_after_seconds'] ) && is_numeric( $row['retry_after_seconds'] ) ? min( 3600, absint( $row['retry_after_seconds'] ) ) : null,
		);
	}

	/** Validate an immutable BuchWerk request binding supplied by its server. */
	public static function valid_client_request_hash( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : null;
	}

	/** Normalize provider id and hash inputs without retaining image bytes. */
	public static function sanitize_client_request_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return strlen( $value ) <= 128 && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $value ) ? $value : '';
	}

	private static function state_for_existing_row( array $row ) {
		if ( 'succeeded' === (string) $row['outcome'] ) {
			return array( 'state' => 'succeeded', 'attempt' => $row );
		}
		if ( 'rejected' === (string) $row['outcome'] ) {
			return array( 'state' => 'rejected', 'attempt' => $row );
		}
		return array( 'state' => 'blocked', 'attempt' => $row );
	}

	private static function same_binding( array $row, $request_hash, $client_request_hash ) {
		$stored_client_hash = self::normalize_hash_or_null( $row['client_request_hash'] ?? null );
		return hash_equals( (string) $row['request_hash'], (string) $request_hash )
			&& ( null === $stored_client_hash ? null === $client_request_hash : ( null !== $client_request_hash && hash_equals( $stored_client_hash, $client_request_hash ) ) );
	}

	private static function update_attempt( array $attempt, array $values ) {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return false;
		}
		$where = array(
			'attempt_id' => absint( $attempt['attempt_id'] ?? 0 ),
			'owner_id'   => absint( $attempt['owner_id'] ?? 0 ),
			'revision'   => absint( $attempt['revision'] ?? 0 ),
		);
		$result = $wpdb->update( self::get_table_name(), $values, $where );
		if ( false === $result || 1 !== (int) $result ) {
			return false;
		}
		return self::row_by_id( $where['attempt_id'], $where['owner_id'] );
	}

	/** Expose a raw row only to trusted Gateway recovery code. */
	public static function latest_raw( $owner_id, $client_request_id ) {
		return self::latest_row( absint( $owner_id ), self::sanitize_client_request_id( $client_request_id ) );
	}

	/** Verify that claim storage can honor row locks and rollbacks. */
	public static function verify_attempt_storage() {
		if ( ! self::ensure_table() ) {
			return new \WP_Error( 'image_attempt_engine_unavailable', __( 'Image attempt storage is unavailable.', 'alorbach-ai-gateway' ), array( 'status' => 503, 'storage_component' => 'attempts' ) );
		}
		return self::verify_innodb( self::get_table_name(), 'attempt' );
	}

	/** Verify both tables before any provider dispatch or billing transaction. */
	public static function verify_transactional_storage() {
		$attempt_check = self::verify_attempt_storage();
		if ( is_wp_error( $attempt_check ) ) {
			return $attempt_check;
		}
		return self::verify_innodb( Ledger::get_table_name(), 'ledger' );
	}

	/** Verify an existing table engine without changing schema or table state. */
	private static function verify_innodb( $table, $component ) {
		global $wpdb;
		$wpdb->last_error = '';
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $status ) || ! isset( $status['Engine'] ) ) {
			return new \WP_Error( 'image_' . $component . '_engine_unavailable', __( 'Transactional image storage could not be verified.', 'alorbach-ai-gateway' ), array( 'status' => 503, 'storage_component' => $component ) );
		}
		$engine = strtolower( trim( (string) $status['Engine'] ) );
		if ( 'innodb' !== $engine ) {
			return new \WP_Error( 'image_' . $component . '_engine_unsupported', __( 'Transactional image storage is required for this request.', 'alorbach-ai-gateway' ), array( 'status' => 503, 'storage_component' => $component ) );
		}
		return true;
	}

	/** Expose the attempts table name to trusted reconciliation code. */
	public static function table_name() {
		return self::get_table_name();
	}

	/** Ensure storage only when a mutating direct-image operation is underway. */
	public static function ensure_storage() {
		return self::ensure_table();
	}

	private static function row_by_id( $attempt_id, $owner_id ) {
		global $wpdb;
		if ( $attempt_id < 1 || ! self::table_exists() ) {
			return null;
		}
		$table = self::get_table_name();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_id = %d AND owner_id = %d LIMIT 1", $attempt_id, $owner_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function latest_row( $owner_id, $client_request_id ) {
		global $wpdb;
		if ( $owner_id < 1 || '' === $client_request_id ) {
			return null;
		}
		$wpdb->last_error = '';
		if ( ! self::table_exists() ) {
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'image_attempt_storage_unavailable', __( 'The image evidence could not be read safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
			}
			return null;
		}
		$table = self::get_table_name();
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d AND client_request_id = %s ORDER BY attempt_number DESC LIMIT 1", $owner_id, $client_request_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'image_attempt_storage_unavailable', __( 'The image evidence could not be read safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		return is_array( $row ) ? $row : null;
	}

	private static function legacy_ledger_evidence( $owner_id, $client_request_id ) {
		global $wpdb;
		$signature = hash( 'sha256', wp_json_encode( array( (int) $owner_id, 'image', 'client_request_id', (string) $client_request_id ) ) );
		$table = Ledger::get_table_name();
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT transaction_id, uc_amount, api_cost_uc FROM {$table} WHERE user_id = %d AND transaction_type = %s AND request_signature = %s LIMIT 1", $owner_id, 'image_deduction', $signature ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'image_attempt_storage_unavailable', __( 'The image evidence could not be read safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$user_cost = null;
		if ( isset( $row['uc_amount'] ) && is_numeric( $row['uc_amount'] ) ) {
			$user_cost = number_format( abs( (float) $row['uc_amount'] ) / 1000000, 8, '.', '' );
		}
		$provider_cost = null;
		if ( isset( $row['api_cost_uc'] ) && is_numeric( $row['api_cost_uc'] ) && (float) $row['api_cost_uc'] >= 0 ) {
			$provider_cost = number_format( (float) $row['api_cost_uc'] / 1000000, 8, '.', '' );
		}

		return array(
			'schema'              => self::SCHEMA,
			'client_request_id'   => $client_request_id,
			'request_hash'        => null,
			'client_request_hash' => null,
			'dispatch_status'     => 'possibly_sent',
			'outcome'             => 'unknown',
			'cost_usd'            => $user_cost,
			'provider_cost_usd'   => $provider_cost,
			'billing_status'      => 'recorded',
			'evidence_id'         => hash( 'sha256', 'alorbach_ledger_v1:' . absint( $row['transaction_id'] ) . ':' . $signature ),
			'source'              => 'gateway_ledger_v1',
			'revision'            => 1,
			'evidence_status'     => 'cost_only',
			'retry_safe'          => false,
			'conflict'            => false,
			'failure_class'       => null,
			'upstream_status'     => null,
			'provider_error_code' => null,
			'retry_after_seconds' => null,
		);
	}

	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	private static function table_exists() {
		global $wpdb;
		$table = self::get_table_name();
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return is_string( $found ) && $table === $found;
	}

	private static function ensure_table() {
		global $wpdb;
		if ( true === self::$table_ready ) {
			return self::ensure_diagnostics_column();
		}
		$table = self::get_table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( is_string( $found ) && $table === $found ) {
			self::$table_ready = true;
			return self::ensure_diagnostics_column();
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			attempt_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_id bigint(20) unsigned NOT NULL,
			client_request_id varchar(128) NOT NULL,
			attempt_number int(10) unsigned NOT NULL DEFAULT 1,
			revision int(10) unsigned NOT NULL DEFAULT 1,
			request_hash char(64) DEFAULT NULL,
			client_request_hash char(64) DEFAULT NULL,
			dispatch_status varchar(16) NOT NULL,
			outcome varchar(16) NOT NULL,
			cost_usd decimal(20,8) DEFAULT NULL,
			provider_cost_usd decimal(20,8) DEFAULT NULL,
			billing_status varchar(16) NOT NULL DEFAULT 'unresolved',
			conflict_status tinyint(1) NOT NULL DEFAULT 0,
			failure_class varchar(32) DEFAULT NULL,
			upstream_status smallint(5) unsigned DEFAULT NULL,
			provider_error_code varchar(64) DEFAULT NULL,
			provider_error_diagnostics longtext DEFAULT NULL,
			retry_after_seconds smallint(5) unsigned DEFAULT NULL,
			evidence_id char(36) NOT NULL,
			source varchar(64) NOT NULL,
			result_json longtext DEFAULT NULL,
			result_hash char(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (attempt_id),
			UNIQUE KEY owner_request_attempt (owner_id,client_request_id,attempt_number),
			KEY owner_request (owner_id,client_request_id),
			KEY owner_evidence (owner_id,evidence_id)
		) {$charset_collate} ENGINE=InnoDB;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::$table_ready = self::table_exists();
		return self::$table_ready && self::ensure_diagnostics_column();
	}

	/**
	 * Add the optional diagnostics column during a mutating storage preflight.
	 * Read-only evidence endpoints never call this method.
	 */
	private static function ensure_diagnostics_column() {
		global $wpdb;
		if ( true === self::$diagnostics_column_ready ) {
			return true;
		}
		$table = self::get_table_name();
		if ( ! self::table_exists() ) {
			return false;
		}

		$wpdb->last_error = '';
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'provider_error_diagnostics' ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( is_array( $column ) && 'provider_error_diagnostics' === ( $column['Field'] ?? '' ) ) {
			self::$diagnostics_column_ready = true;
			return true;
		}

		$wpdb->last_error = '';
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN provider_error_diagnostics longtext DEFAULT NULL" );
		$wpdb->last_error = '';
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'provider_error_diagnostics' ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $column ) || 'provider_error_diagnostics' !== ( $column['Field'] ?? '' ) ) {
			return false;
		}
		self::$diagnostics_column_ready = true;
		return true;
	}

	private static function public_provider_error_diagnostics( $raw ) {
		$empty = array(
			'provider_error_reason' => null,
			'provider_error_param' => null,
			'provider_request_id' => null,
			'provider_error_message_sha256' => null,
			'provider_error_message' => null,
		);
		if ( ! is_string( $raw ) || strlen( $raw ) > 6144 ) {
			return $empty;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! in_array( ( $decoded['schema'] ?? '' ), array( 'provider-error-diagnostics.v1', 'provider-error-diagnostics.v2' ), true ) ) {
			return $empty;
		}
		$fields = Image_Error_Diagnostics::normalize_fields( $decoded );
		return array(
			'provider_error_reason' => $fields['provider_error_reason'],
			'provider_error_param' => $fields['provider_error_param'],
			'provider_request_id' => $fields['provider_request_id'],
			'provider_error_message_sha256' => $fields['provider_error_message_sha256'],
			'provider_error_message' => $fields['provider_error_message'],
		);
	}

	private static function normalize_hash_or_null( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value ? null : $value;
	}

	private static function valid_hash_or_null( $value ) {
		$value = self::normalize_hash_or_null( $value );
		return null !== $value && preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : null;
	}

	private static function normalize_cost( $value ) {
		if ( null === $value || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) {
			return null;
		}
		return number_format( (float) $value, 8, '.', '' );
	}

	private static function safe_provider_error_code( $value ) {
		return Image_Error_Diagnostics::normalize_code( $value );
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize( $item );
			}
		}
		return $value;
	}

	private static function hash_binary_value( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$value = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return hash( 'sha256', (string) $value );
	}

	private static function new_evidence_id() {
		return sprintf( '%08x-%04x-4%03x-%04x-%012x', mt_rand( 0, 0xffffffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ), mt_rand( 0x8000, 0xbfff ), mt_rand( 0, 0xffffffffffff ) );
	}
}
