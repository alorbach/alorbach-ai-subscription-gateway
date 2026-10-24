<?php
/**
 * Verified external evidence for direct image attempts.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports owner-confirmed, issuer-signed provider attestations. The signing
 * key is selected only from server configuration; this class never fetches a
 * source URI or accepts a public key from the request.
 */
class Image_Attestation {
	const TABLE_NAME = 'alorbach_image_attestations';
	const SCHEMA = 'gateway-image-attestation.v1';
	const SOURCE = 'gateway_signed_attestation_v1';
	const CLAIM_FIELDS = array(
		'schema', 'issuer', 'attestation_id', 'owner_id', 'client_request_id',
		'client_request_hash', 'provider_case_id', 'issued_at', 'outcome',
		'cost_usd', 'provider_cost_usd', 'source_uri', 'artifact_sha256',
	);

	/** Detect a contradictory signed provider case across linked local jobs. */
	public static function has_conflict( $owner_id, $client_request_id ) {
		global $wpdb;
		$table = self::get_table_name();
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return true;
		}
		if ( ! is_string( $found ) || $table !== $found ) {
			return false;
		}
		$sql = "SELECT conflict_row.record_id FROM {$table} AS request_row INNER JOIN {$table} AS conflict_row ON request_row.issuer = conflict_row.issuer AND request_row.provider_case_id = conflict_row.provider_case_id WHERE request_row.owner_id = %d AND request_row.client_request_id = %s AND conflict_row.is_conflict = 1 LIMIT 1";
		$wpdb->last_error = '';
		$result = $wpdb->get_var( $wpdb->prepare( $sql, absint( $owner_id ), Image_Attempts::sanitize_client_request_id( $client_request_id ) ) );
		return '' !== (string) $wpdb->last_error || (bool) $result;
	}

	/** Verify and append one signed reconciliation statement. */
	public static function reconcile( $owner_id, $client_request_id, $expected_revision, $idempotency_key, array $envelope, $signature, $confirmed ) {
		global $wpdb;
		$owner_id = absint( $owner_id );
		$client_request_id = Image_Attempts::sanitize_client_request_id( $client_request_id );
		$idempotency_key = self::sanitize_id( $idempotency_key );
		if ( ! $confirmed || $owner_id < 1 || '' === $client_request_id || '' === $idempotency_key ) {
			return new \WP_Error( 'image_attestation_confirmation_required', __( 'Confirm the saved attempt and provide its recovery key.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( ! is_int( $expected_revision ) && ( ! is_string( $expected_revision ) || ! preg_match( '/^\d{1,10}$/', $expected_revision ) ) ) {
			return new \WP_Error( 'image_attestation_revision_invalid', __( 'The image attempt revision is invalid.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		$expected_revision = (int) $expected_revision;
		if ( $expected_revision < 0 ) {
			return new \WP_Error( 'image_attestation_revision_invalid', __( 'The image attempt revision is invalid.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		$verified = self::verify_envelope( $envelope, $signature );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$claims = $verified['claims'];
		if ( (int) $claims['owner_id'] !== $owner_id || ! hash_equals( $client_request_id, $claims['client_request_id'] ) ) {
			return self::invalid_attestation();
		}
		$bytes = $verified['bytes'];
		$sig = $verified['signature'];
		$digest = $verified['digest'];

		// DDL is limited to this authenticated, confirmed write path. GETs never
		// create storage or run migrations against the live Gateway database.
		if ( ! Image_Attempts::ensure_storage() || ! self::ensure_table() ) {
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$table = self::get_table_name();
		$attempt_table = Image_Attempts::table_name();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}

		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attempt_table} WHERE owner_id = %d AND client_request_id = %s ORDER BY attempt_number DESC LIMIT 1 FOR UPDATE", $owner_id, $client_request_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$legacy_evidence = is_array( $row ) ? null : Image_Attempts::lookup( $owner_id, $client_request_id );
		if ( is_wp_error( $legacy_evidence ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $legacy_evidence;
		}
		$existing_revision = is_array( $row ) ? absint( $row['revision'] ?? 0 ) : ( is_array( $legacy_evidence ) ? absint( $legacy_evidence['revision'] ?? 0 ) : 0 );
		$existing_revision = max( 0, $existing_revision );

		$wpdb->last_error = '';
		$replay = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d AND client_request_id = %s AND idempotency_key = %s LIMIT 1", $owner_id, $client_request_id, $idempotency_key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		if ( is_array( $replay ) ) {
			if ( hash_equals( (string) $replay['envelope_sha256'], $digest ) ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
				}
				return Image_Attempts::lookup( $owner_id, $client_request_id );
			}
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_idempotency_conflict', __( 'This recovery key was already used for different signed evidence.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$wpdb->last_error = '';
		$issuer_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE issuer = %s AND attestation_id = %s LIMIT 1", $claims['issuer'], $claims['attestation_id'] ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		if ( is_array( $issuer_record ) ) {
			if ( hash_equals( (string) $issuer_record['envelope_sha256'], $digest ) && (int) $issuer_record['owner_id'] === $owner_id && hash_equals( (string) $issuer_record['client_request_id'], $client_request_id ) ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
				}
				return Image_Attempts::lookup( $owner_id, $client_request_id );
			}
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_replay_conflict', __( 'The issuer reused an attestation ID with different signed claims.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		if ( (int) $expected_revision !== $existing_revision ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_revision_conflict', __( 'The image attempt changed. Read its current evidence before reconciling it.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}
		if ( is_array( $row ) ) {
			$bound_hash = Image_Attempts::valid_client_request_hash( $row['client_request_hash'] ?? null );
			if ( null === $bound_hash || ! hash_equals( $bound_hash, $claims['client_request_hash'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::invalid_attestation();
			}
		} elseif ( isset( $claims['client_request_hash'] ) && ! preg_match( '/^[a-f0-9]{64}$/', $claims['client_request_hash'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::invalid_attestation();
		}

		$case_lock_name = 'bws_img_case_' . substr( hash( 'sha256', $claims['issuer'] . ':' . $claims['provider_case_id'] ), 0, 40 );
		$case_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $case_lock_name ) );
		if ( '1' !== (string) $case_lock ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'image_attestation_busy', __( 'The provider evidence is being reconciled. Read the current attempt and try again.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$wpdb->last_error = '';
		$case_records = $wpdb->get_results( $wpdb->prepare( "SELECT owner_id, client_request_id, outcome, cost_usd, provider_cost_usd, source_uri, artifact_sha256, envelope_json FROM {$table} WHERE issuer = %s AND provider_case_id = %s", $claims['issuer'], $claims['provider_case_id'] ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $case_lock_name ) );
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$durable_user_cost = is_array( $row ) && null !== ( $row['cost_usd'] ?? null ) ? $row['cost_usd'] : ( is_array( $legacy_evidence ) ? ( $legacy_evidence['cost_usd'] ?? null ) : null );
		$durable_provider_cost = is_array( $row ) && null !== ( $row['provider_cost_usd'] ?? null ) ? $row['provider_cost_usd'] : ( is_array( $legacy_evidence ) ? ( $legacy_evidence['provider_cost_usd'] ?? null ) : null );
		$user_cost_conflict = null !== $durable_user_cost && null !== $claims['cost_usd'] && self::costs_differ( $durable_user_cost, $claims['cost_usd'] );
		$provider_cost_conflict = null !== $durable_provider_cost && null !== $claims['provider_cost_usd'] && self::costs_differ( $durable_provider_cost, $claims['provider_cost_usd'] );
		$conflict = $user_cost_conflict || $provider_cost_conflict || ( is_array( $row ) && ( ! empty( $row['conflict_status'] ) || 'not_sent' === (string) ( $row['dispatch_status'] ?? '' ) || ( in_array( (string) ( $row['outcome'] ?? '' ), array( 'rejected', 'succeeded' ), true ) && (string) $row['outcome'] !== $claims['outcome'] ) ) );
		foreach ( is_array( $case_records ) ? $case_records : array() as $case_record ) {
			if ( self::case_claims_conflict( $case_record, $claims ) ) {
				$conflict = true;
				break;
			}
		}
		$now = current_time( 'mysql', true );
		$signature_b64 = base64_encode( $sig );
		$inserted = $wpdb->insert(
			$table,
			array(
				'owner_id' => $owner_id,
				'client_request_id' => $client_request_id,
				'issuer' => $claims['issuer'],
				'attestation_id' => $claims['attestation_id'],
				'provider_case_id' => $claims['provider_case_id'],
				'issued_at' => $claims['issued_at'],
				'outcome' => $claims['outcome'],
				'cost_usd' => null === $claims['cost_usd'] ? null : self::format_cost( $claims['cost_usd'] ),
				'provider_cost_usd' => null === $claims['provider_cost_usd'] ? null : self::format_cost( $claims['provider_cost_usd'] ),
				'source_uri' => $claims['source_uri'],
				'artifact_sha256' => $claims['artifact_sha256'],
				'envelope_json' => $bytes,
				'signature_b64' => $signature_b64,
				'envelope_sha256' => $digest,
				'idempotency_key' => $idempotency_key,
				'is_conflict' => $conflict ? 1 : 0,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', null, null, '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $case_lock_name ) );
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}

		$new_revision = $existing_revision + 1;
		$legacy_has_ledger_cost = is_array( $legacy_evidence ) && 'gateway_ledger_v1' === (string) ( $legacy_evidence['source'] ?? '' );
		$existing_billing_status = is_array( $row ) ? (string) ( $row['billing_status'] ?? 'unresolved' ) : ( $legacy_has_ledger_cost ? 'recorded' : 'unresolved' );
		$next_user_cost = null !== $claims['cost_usd'] ? self::format_cost( $claims['cost_usd'] ) : $durable_user_cost;
		$next_provider_cost = null !== $claims['provider_cost_usd'] ? self::format_cost( $claims['provider_cost_usd'] ) : $durable_provider_cost;
		$next_billing_status = in_array( $existing_billing_status, array( 'recorded', 'attested' ), true ) ? $existing_billing_status : ( null !== $claims['cost_usd'] ? 'attested' : 'unresolved' );
		$projection = array(
			'revision' => max( 1, $new_revision ),
			'dispatch_status' => $conflict && is_array( $row ) ? $row['dispatch_status'] : 'possibly_sent',
			'outcome' => $conflict ? ( is_array( $row ) ? $row['outcome'] : 'unknown' ) : $claims['outcome'],
			'cost_usd' => $conflict ? $durable_user_cost : $next_user_cost,
			'provider_cost_usd' => $conflict ? $durable_provider_cost : $next_provider_cost,
			'billing_status' => $conflict ? $existing_billing_status : $next_billing_status,
			'source' => $conflict ? ( is_array( $row ) ? $row['source'] : 'gateway_attestation_conflict_v1' ) : self::SOURCE,
			'conflict_status' => $conflict ? 1 : 0,
			'updated_at' => $now,
		);
		if ( is_array( $row ) ) {
			$updated = $wpdb->update(
				$attempt_table,
				$projection,
				array( 'attempt_id' => absint( $row['attempt_id'] ), 'owner_id' => $owner_id, 'revision' => $existing_revision ),
				array( '%d', '%s', '%s', null, null, '%s', '%s', '%d', '%s' ),
				array( '%d', '%d', '%d' )
			);
			if ( false === $updated || 1 !== (int) $updated ) {
				$wpdb->query( 'ROLLBACK' );
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $case_lock_name ) );
				return new \WP_Error( 'image_attestation_revision_conflict', __( 'The image attempt changed. Read its current evidence before reconciling it.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
			}
		} else {
			$attempt_number = 1;
			$attempt_insert = $wpdb->insert(
				$attempt_table,
				array_merge(
					array(
						'owner_id' => $owner_id,
						'client_request_id' => $client_request_id,
						'attempt_number' => $attempt_number,
						'request_hash' => null,
						'client_request_hash' => $claims['client_request_hash'],
						'evidence_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : wp_generate_password( 36, false, false ),
						'result_json' => null,
						'result_hash' => null,
						'created_at' => $now,
					),
					$projection
				),
				array( '%d', '%s', '%d', null, '%s', '%s', null, null, '%s', '%d', '%s', '%s', null, null, '%s', '%s', '%d', '%s' )
			);
			if ( false === $attempt_insert ) {
				$wpdb->query( 'ROLLBACK' );
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $case_lock_name ) );
				return new \WP_Error( 'image_attestation_revision_conflict', __( 'The image attempt changed. Read its current evidence before reconciling it.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $case_lock_name ) );
			return new \WP_Error( 'image_attestation_storage_unavailable', __( 'The signed evidence could not be saved safely.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $case_lock_name ) );
		return Image_Attempts::lookup( $owner_id, $client_request_id );
	}

	/** Verify the fixed canonical envelope bytes with a server-pinned RSA key. */
	public static function verify_envelope( array $envelope, $signature ) {
		$claims = self::canonical_claims( $envelope );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		$bytes = wp_json_encode( $claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$key_config = self::pinned_key( $claims['issuer'] );
		$signature = (string) $signature;
		$sig = strlen( $signature ) <= 2048 ? base64_decode( $signature, true ) : false;
		if ( ! is_string( $bytes ) || ! is_array( $key_config ) || false === $sig || base64_encode( $sig ) !== $signature || strlen( $sig ) < 32 || ! function_exists( 'openssl_verify' ) || 1 !== openssl_verify( $bytes, $sig, $key_config['key'], OPENSSL_ALGO_SHA256 ) || ! in_array( 'outcome', $key_config['authorities'], true ) || ( null !== $claims['cost_usd'] && ! in_array( 'user_cost', $key_config['authorities'], true ) ) || ( null !== $claims['provider_cost_usd'] && ! in_array( 'provider_cost', $key_config['authorities'], true ) ) ) {
			return self::invalid_attestation();
		}
		return array( 'claims' => $claims, 'bytes' => $bytes, 'signature' => $sig, 'digest' => hash( 'sha256', $bytes ) );
	}

	/** Build claims with a stable field order and reject unrecognized fields. */
	public static function canonical_claims( array $envelope ) {
		if ( array_diff( array_keys( $envelope ), self::CLAIM_FIELDS ) || array_diff( self::CLAIM_FIELDS, array_keys( $envelope ) ) ) {
			return self::invalid_attestation();
		}
		$claims = array();
		foreach ( self::CLAIM_FIELDS as $field ) {
			$claims[ $field ] = $envelope[ $field ];
		}
		if ( self::SCHEMA !== $claims['schema'] || ! is_string( $claims['issuer'] ) || ! preg_match( '/^[a-zA-Z0-9._:-]{1,128}$/', $claims['issuer'] ) || ! is_string( $claims['attestation_id'] ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $claims['attestation_id'] ) || ! is_string( $claims['client_request_id'] ) || '' === Image_Attempts::sanitize_client_request_id( $claims['client_request_id'] ) || ! is_string( $claims['client_request_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $claims['client_request_hash'] ) || ! is_string( $claims['provider_case_id'] ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $claims['provider_case_id'] ) || ! is_string( $claims['issued_at'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $claims['issued_at'] ) || ! self::valid_issued_at( $claims['issued_at'] ) || ! in_array( $claims['outcome'], array( 'rejected', 'succeeded' ), true ) || ! is_string( $claims['source_uri'] ) || strlen( $claims['source_uri'] ) > 2048 || ! preg_match( '#^https://#i', $claims['source_uri'] ) || ! is_string( $claims['artifact_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $claims['artifact_sha256'] ) ) {
			return self::invalid_attestation();
		}
		if ( ! is_int( $claims['owner_id'] ) || $claims['owner_id'] < 1 ) {
			return self::invalid_attestation();
		}
		foreach ( array( 'cost_usd', 'provider_cost_usd' ) as $cost_field ) {
			if ( null !== $claims[ $cost_field ] && ( ! is_string( $claims[ $cost_field ] ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/', $claims[ $cost_field ] ) ) ) {
				return self::invalid_attestation();
			}
		}
		$claims['issuer'] = sanitize_text_field( $claims['issuer'] );
		$claims['attestation_id'] = sanitize_text_field( $claims['attestation_id'] );
		$claims['client_request_id'] = Image_Attempts::sanitize_client_request_id( $claims['client_request_id'] );
		$claims['provider_case_id'] = sanitize_text_field( $claims['provider_case_id'] );
		$claims['source_uri'] = esc_url_raw( $claims['source_uri'], array( 'https' ) );
		if ( '' === $claims['source_uri'] ) {
			return self::invalid_attestation();
		}
		$claims['artifact_sha256'] = strtolower( $claims['artifact_sha256'] );
		$claims['client_request_hash'] = strtolower( $claims['client_request_hash'] );
		return $claims;
	}

	/** Detect a changed signed conclusion for the same issuer and provider case. */
	private static function case_claims_conflict( array $record, array $claims ) {
		$previous_claims = json_decode( (string) ( $record['envelope_json'] ?? '' ), true );
		$previous_request_hash = is_array( $previous_claims ) ? (string) ( $previous_claims['client_request_hash'] ?? '' ) : '';
		return (int) ( $record['owner_id'] ?? 0 ) !== (int) $claims['owner_id']
			|| ! hash_equals( (string) ( $record['client_request_id'] ?? '' ), (string) $claims['client_request_id'] )
			|| ! hash_equals( $previous_request_hash, (string) $claims['client_request_hash'] )
			|| (string) ( $record['outcome'] ?? '' ) !== (string) $claims['outcome']
			|| self::nullable_costs_differ( $record['cost_usd'] ?? null, $claims['cost_usd'] )
			|| self::nullable_costs_differ( $record['provider_cost_usd'] ?? null, $claims['provider_cost_usd'] )
			|| ! hash_equals( (string) ( $record['source_uri'] ?? '' ), (string) $claims['source_uri'] )
			|| ! hash_equals( (string) ( $record['artifact_sha256'] ?? '' ), (string) $claims['artifact_sha256'] );
	}

	private static function nullable_costs_differ( $left, $right ) {
		if ( null === $left || null === $right ) {
			return false;
		}
		return self::costs_differ( $left, $right );
	}
	private static function format_cost( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^([0-9]+)(?:\.([0-9]{1,8}))?$/', $value, $matches ) ) {
			return '0.00000000';
		}
		$whole = ltrim( $matches[1], '0' );
		$whole = '' === $whole ? '0' : $whole;
		$fraction = str_pad( $matches[2] ?? '', 8, '0' );
		return $whole . '.' . $fraction;
	}

	private static function costs_differ( $left, $right ) {
		if ( ! is_numeric( $left ) || ! is_numeric( $right ) ) {
			return true;
		}
		return self::format_cost( $left ) !== self::format_cost( $right );
	}

	private static function valid_issued_at( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value && $date->getTimestamp() <= time() + 300;
	}

	private static function pinned_key( $issuer ) {
		$keys = apply_filters( 'alorbach_image_attestation_public_keys', array() );
		$config = is_array( $keys ) && isset( $keys[ $issuer ] ) && is_array( $keys[ $issuer ] ) ? $keys[ $issuer ] : array();
		$key = is_string( $config['public_key'] ?? null ) ? $config['public_key'] : '';
		$authorities = is_array( $config['authorities'] ?? null ) ? array_values( array_unique( array_map( 'sanitize_key', $config['authorities'] ) ) ) : array();
		$allowed = array( 'outcome', 'user_cost', 'provider_cost' );
		if ( '' === trim( $key ) || ! function_exists( 'openssl_pkey_get_public' ) || array_diff( $authorities, $allowed ) ) {
			return false;
		}
		$public = openssl_pkey_get_public( $key );
		if ( ! $public ) {
			return false;
		}
		$details = openssl_pkey_get_details( $public );
		return is_array( $details ) && OPENSSL_KEYTYPE_RSA === ( $details['type'] ?? null ) && ( $details['bits'] ?? 0 ) >= 2048 ? array( 'key' => $public, 'authorities' => $authorities ) : false;
	}

	private static function ensure_table() {
		global $wpdb;
		$table = self::get_table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( is_string( $found ) && $table === $found ) {
			return true;
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			record_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_id bigint(20) unsigned NOT NULL,
			client_request_id varchar(128) NOT NULL,
			issuer varchar(128) NOT NULL,
			attestation_id varchar(128) NOT NULL,
			provider_case_id varchar(128) NOT NULL,
			issued_at varchar(40) NOT NULL,
			outcome varchar(16) NOT NULL,
			cost_usd decimal(20,8) DEFAULT NULL,
			provider_cost_usd decimal(20,8) DEFAULT NULL,
			source_uri text NOT NULL,
			artifact_sha256 char(64) NOT NULL,
			envelope_json longtext NOT NULL,
			signature_b64 text NOT NULL,
			envelope_sha256 char(64) NOT NULL,
			idempotency_key varchar(128) NOT NULL,
			is_conflict tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (record_id),
			UNIQUE KEY issuer_attestation (issuer,attestation_id),
			UNIQUE KEY owner_request_idempotency (owner_id,client_request_id,idempotency_key),
			KEY issuer_case (issuer,provider_case_id),
			KEY owner_request (owner_id,client_request_id)
		) {$charset_collate} ENGINE=InnoDB;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return is_string( $found ) && $table === $found;
	}

	private static function legacy_revision( $owner_id, $client_request_id ) {
		$evidence = Image_Attempts::lookup( $owner_id, $client_request_id );
		return is_array( $evidence ) ? absint( $evidence['revision'] ?? 0 ) : 0;
	}

	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	private static function sanitize_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $value ) ? $value : '';
	}

	private static function invalid_attestation() {
		return new \WP_Error( 'image_attestation_invalid', __( 'The signed provider evidence could not be verified.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
	}
}
