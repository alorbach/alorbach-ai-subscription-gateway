<?php
/** Offline regression checks for direct-image recovery primitives. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = '' ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value, $protocols = null ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_time( $type = 'mysql', $gmt = false ) { return '2026-10-24 00:00:00'; }
function apply_filters( $tag, $value ) { return 'alorbach_image_attestation_public_keys' === $tag && isset( $GLOBALS['test_attestation_keys'] ) ? $GLOBALS['test_attestation_keys'] : $value; }

class Image_Attempt_Test_DB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $row = array();
	public $query_log = array();
	public $columns = array( 'provider_error_diagnostics' => false );
	public $engines = array( 'wp_alorbach_image_attempts' => 'InnoDB', 'wp_alorbach_ledger' => 'InnoDB' );
	public function esc_like( $value ) { return $value; }
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "\\\\'", (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) ) {
			preg_match( "/LIKE '([^']+)'/", $query, $match );
			return $match[1] ?? null;
		}
		if ( false !== strpos( $query, 'SELECT GET_LOCK' ) || false !== strpos( $query, 'SELECT RELEASE_LOCK' ) ) {
			return '1';
		}
		return null;
	}
	public function get_row( $query, $format = null ) {
		if ( false !== strpos( $query, 'SHOW COLUMNS' ) ) {
			return ! empty( $this->columns['provider_error_diagnostics'] ) ? array( 'Field' => 'provider_error_diagnostics', 'Type' => 'longtext' ) : null;
		}
		if ( false !== strpos( $query, 'SHOW TABLE STATUS' ) ) {
			preg_match( "/Name = '([^']+)'/", $query, $match );
			$table = $match[1] ?? '';
			return isset( $this->engines[ $table ] ) ? array( 'Name' => $table, 'Engine' => $this->engines[ $table ] ) : null;
		}
		return $this->row;
	}
	public function query( $query ) {
		$this->query_log[] = $query;
		if ( false !== strpos( $query, 'ALTER TABLE' ) && false !== strpos( $query, 'provider_error_diagnostics' ) ) {
			$this->columns['provider_error_diagnostics'] = true;
		}
		return true;
	}
	public function update( $table, $values, $where ) {
		foreach ( $where as $key => $value ) {
			if ( ! isset( $this->row[ $key ] ) || (string) $this->row[ $key ] !== (string) $value ) {
				return 0;
			}
		}
		$this->row = array_merge( $this->row, $values );
		return 1;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ledger.php';
require_once dirname( __DIR__ ) . '/includes/class-image-error-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-image-attempts.php';
require_once dirname( __DIR__ ) . '/includes/class-image-attestation.php';

use Alorbach\AIGateway\Image_Attempts;
use Alorbach\AIGateway\Image_Attestation;

function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$base = array(
	'model' => 'provider::image-model',
	'prompt' => 'test only',
	'size' => '1024x1024',
	'n' => 1,
	'quality' => 'medium',
	'output_format' => 'png',
	'background' => 'opaque',
	'aspect_ratio' => '1:1',
	'provider_options' => array( 'steps' => 20, 'seed' => 7 ),
	'reference_images' => array( array( 'mime_type' => 'image/png', 'b64_json' => base64_encode( 'reference' ) ) ),
	'mask_image' => 'mask-bytes',
	'input_reference' => 'input-bytes',
);
$hash = Image_Attempts::request_hash( 41, 'job-41', $base );
check( 64 === strlen( $hash ), 'canonical Gateway request hash is SHA-256' );
foreach ( array( 'aspect_ratio', 'provider_options', 'reference_images', 'mask_image', 'input_reference', 'quality', 'output_format' ) as $field ) {
	$changed = $base;
	$changed[ $field ] = is_array( $changed[ $field ] ) ? array_merge( $changed[ $field ], array( 'changed' => true ) ) : $changed[ $field ] . '-changed';
	check( $hash !== Image_Attempts::request_hash( 41, 'job-41', $changed ), "{$field} affects the immutable request binding" );
}

$preflight = Image_Attempts::classify_error( 'no_api_key' );
check( 'not_sent' === $preflight['dispatch_status'] && 'rejected' === $preflight['outcome'], 'local missing-key failure proves no dispatch' );
check( '0.00000000' === $preflight['cost_usd'] && '0.00000000' === $preflight['provider_cost_usd'] && 'not_charged' === $preflight['billing_status'], 'known no-send failure records explicit zero user and provider cost' );
foreach ( array( 400, 429, 500 ) as $status ) {
	$error = Image_Attempts::classify_error( 'api_error', array( 'status' => $status, 'error_code' => 'invalid_request_error', 'retry_after' => 99999, 'body' => 'never expose' ) );
	check( 'possibly_sent' === $error['dispatch_status'] && 'unknown' === $error['outcome'], "upstream HTTP {$status} remains unknown after handoff" );
	check( ! isset( $error['body'] ) && 3600 === $error['retry_after_seconds'], 'diagnostics are bounded and exclude raw provider payloads' );
}
foreach ( array( 401, 403 ) as $status ) {
	$auth_error = Image_Attempts::classify_error( 'api_error', array( 'status' => $status ) );
	check( 'unknown' === $auth_error['outcome'] && 'possibly_sent' === $auth_error['dispatch_status'] && ! array_key_exists( 'cost_usd', $auth_error ) && ! array_key_exists( 'provider_cost_usd', $auth_error ) && ! array_key_exists( 'billing_status', $auth_error ), "HTTP {$status} remains generic without changing billing evidence" );
	check( null === $auth_error['provider_error_code'] && 'unknown' === $auth_error['provider_error_reason'], "HTTP {$status} does not invent a provider cause" );
}
$projection = Image_Attempts::public_evidence( array(
	'schema' => 'image-attempt.v1', 'client_request_id' => 'job-41', 'request_hash' => $hash,
	'client_request_hash' => hash( 'sha256', 'bws-binding' ), 'dispatch_status' => 'not_sent', 'outcome' => 'rejected',
	'cost_usd' => '0.00000000', 'provider_cost_usd' => '0.00000000', 'billing_status' => 'not_charged',
	'evidence_id' => 'evidence-41', 'source' => 'gateway_attempt_v1', 'revision' => 2, 'conflict_status' => 0,
) );
check( 'attempt_bound' === $projection['evidence_status'] && true === $projection['retry_safe'], 'no-send evidence projection is retry-safe only with explicit zero and no charge' );
check( null === $projection['provider_error_reason'] && null === $projection['provider_error_param'] && null === $projection['provider_request_id'], 'historical evidence without diagnostics remains readable with null safe fields' );
check( '0.00000000' === $projection['cost_usd'] && '0.00000000' === $projection['provider_cost_usd'], 'no-send evidence proves both user and provider cost are zero' );
$result_row = array( 'outcome' => 'succeeded', 'billing_status' => 'pending', 'cost_usd' => null, 'source' => 'gateway_attempt_v1' );
check( ! Image_Attempts::can_release_result( $result_row ), 'cached bytes stay private while billing is pending' );
$result_row['billing_status'] = 'recorded';
$result_row['cost_usd'] = '0.20';
check( Image_Attempts::can_release_result( $result_row ), 'committed user debit permits protected cache retrieval' );
$result_row['billing_status'] = 'attested';
$result_row['source'] = 'gateway_signed_attestation_v1';
check( Image_Attempts::can_release_result( $result_row ), 'verified signed user-cost evidence permits protected cache retrieval' );
$result_row['conflict_status'] = 1;
check( ! Image_Attempts::can_release_result( $result_row ), 'conflicting evidence blocks protected cache retrieval' );
$GLOBALS['wpdb'] = new Image_Attempt_Test_DB();
$GLOBALS['wpdb']->row = array(
	'owner_id' => 41, 'client_request_id' => 'job-41', 'revision' => 2, 'request_hash' => $hash,
	'client_request_hash' => hash( 'sha256', 'bws-binding' ), 'dispatch_status' => 'possibly_sent',
	'outcome' => 'succeeded', 'cost_usd' => null, 'provider_cost_usd' => null, 'billing_status' => 'pending',
	'conflict_status' => 0, 'source' => 'gateway_attempt_v1', 'evidence_id' => 'evidence-41',
	'result_json' => wp_json_encode( array( 'data' => array( array( 'b64_json' => 'private-test-bytes' ) ) ) ),
);
$GLOBALS['wpdb']->row['result_hash'] = hash( 'sha256', $GLOBALS['wpdb']->row['result_json'] );
check( null === Image_Attempts::read_result( 41, 'job-41', 'evidence-41', $hash ), 'read_result returns no bytes when ledger billing is pending' );
$GLOBALS['wpdb']->row['billing_status'] = 'recorded';
$GLOBALS['wpdb']->row['cost_usd'] = '0.20';
$settled_result = Image_Attempts::read_result( 41, 'job-41', 'evidence-41', $hash );
check( is_array( $settled_result ) && 'private-test-bytes' === $settled_result['data'][0]['b64_json'], 'read_result returns cached bytes after settled billing' );
check( false === in_array( 'ALTER TABLE wp_alorbach_image_attempts ADD COLUMN provider_error_diagnostics longtext DEFAULT NULL', $GLOBALS['wpdb']->query_log, true ), 'read-only evidence/result reads never upgrade storage' );

$test_db = $GLOBALS['wpdb'];
check( true === Image_Attempts::verify_transactional_storage(), 'both image tables pass when storage engines are InnoDB' );
$diagnostics_alters = array_values( array_filter( $test_db->query_log, static function ( $query ) { return false !== strpos( $query, 'ALTER TABLE' ) && false !== strpos( $query, 'provider_error_diagnostics' ); } ) );
check( 1 === count( $diagnostics_alters ) && true === $test_db->columns['provider_error_diagnostics'], 'mutating storage preflight idempotently adds the nullable diagnostics column' );
check( false === in_array( 'START TRANSACTION', $test_db->query_log, true ), 'diagnostics schema upgrade completes before any claim transaction' );
$test_db->engines['wp_alorbach_ledger'] = 'MyISAM';
$ledger_engine_error = Image_Attempts::verify_transactional_storage();
check( is_wp_error( $ledger_engine_error ) && 'image_ledger_engine_unsupported' === $ledger_engine_error->get_error_code(), 'unsupported ledger engine is identified fail-closed' );
$engine_preflight = Image_Attempts::classify_error( $ledger_engine_error->get_error_code() );
check( 'gateway_preflight_v1' === $engine_preflight['source'] && 'not_sent' === $engine_preflight['dispatch_status'] && '0.00000000' === $engine_preflight['cost_usd'] && '0.00000000' === $engine_preflight['provider_cost_usd'], 'ledger engine failure proves no dispatch and both costs zero' );
$test_db->engines['wp_alorbach_ledger'] = 'InnoDB';
$test_db->engines['wp_alorbach_image_attempts'] = 'MyISAM';
$test_db->query_log = array();
$claim_error = Image_Attempts::claim( 41, 'engine-check-job', hash( 'sha256', 'canonical-request' ), hash( 'sha256', 'client-binding' ) );
check( is_wp_error( $claim_error ) && 'image_attempt_engine_unsupported' === $claim_error->get_error_code(), 'nontransactional attempt storage rejects a claim' );
check( ! in_array( 'START TRANSACTION', $test_db->query_log, true ), 'claim never starts a transaction on unsupported attempt storage' );
$test_db->engines['wp_alorbach_image_attempts'] = 'InnoDB';

$test_db->row = array(
	'attempt_id' => 9, 'owner_id' => 41, 'client_request_id' => 'diagnostic-job', 'revision' => 1,
	'dispatch_status' => 'possibly_sent', 'outcome' => 'unknown', 'cost_usd' => null, 'provider_cost_usd' => null,
	'billing_status' => 'unresolved', 'failure_class' => null, 'upstream_status' => null,
	'provider_error_code' => null, 'retry_after_seconds' => null, 'source' => 'gateway_attempt_v1',
	'evidence_id' => 'diagnostic-evidence', 'request_hash' => $hash, 'client_request_hash' => null, 'conflict_status' => 0,
);
$private_message = 'private prompt and sk-credential-value';
$classification = Image_Attempts::classify_error( 'api_error', array(
	'status' => 400,
	'provider_error_code' => 'unsupported_parameter',
	'provider_error_reason' => 'unsupported_parameter',
	'provider_error_param' => 'quality',
	'provider_request_id' => 'req_ABC-123:xyz.456',
	'provider_error_message_sha256' => hash( 'sha256', $private_message ),
) );
$saved_diagnostic = Image_Attempts::mark_error( $test_db->row, $classification );
check( is_array( $saved_diagnostic ) && 'possibly_sent' === $saved_diagnostic['dispatch_status'] && 'unknown' === $saved_diagnostic['outcome'], 'provider HTTP error remains possibly sent with an unknown outcome' );
check( null === $saved_diagnostic['cost_usd'] && null === $saved_diagnostic['provider_cost_usd'] && 'unresolved' === $saved_diagnostic['billing_status'], 'provider diagnostics never infer either cost or billing state' );
$projected_diagnostic = Image_Attempts::public_evidence( $saved_diagnostic );
check( 'unsupported_parameter' === $projected_diagnostic['provider_error_code'] && 'unsupported_parameter' === $projected_diagnostic['provider_error_reason'] && 'quality' === $projected_diagnostic['provider_error_param'], 'allowlisted provider code, reason, and parameter survive the public projection' );
check( false === $projected_diagnostic['retry_safe'], 'provider diagnostics never make a possibly-sent attempt safe to retry' );
check( 'req_ABC-123:xyz.456' === $projected_diagnostic['provider_request_id'] && hash( 'sha256', $private_message ) === $projected_diagnostic['provider_error_message_sha256'], 'validated request ID and message fingerprint survive persistence' );
$stored_json = (string) $saved_diagnostic['provider_error_diagnostics'];
check( false === strpos( $stored_json, $private_message ) && false === strpos( wp_json_encode( $projected_diagnostic ), 'sk-credential-value' ), 'raw provider message, prompt, and credential never persist or project' );

$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
check( false !== $key, 'test RSA key generation' );
$details = openssl_pkey_get_details( $key );
$GLOBALS['test_attestation_keys'] = array( 'billing-test-v1' => array( 'public_key' => $details['key'], 'authorities' => array( 'outcome', 'user_cost', 'provider_cost' ) ) );
$envelope = array(
	'schema' => 'gateway-image-attestation.v1', 'issuer' => 'billing-test-v1', 'attestation_id' => 'att-1',
	'owner_id' => 41, 'client_request_id' => 'job-41', 'client_request_hash' => hash( 'sha256', 'bws-binding' ),
	'provider_case_id' => 'case-1', 'issued_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'outcome' => 'rejected',
	'cost_usd' => '0.25', 'provider_cost_usd' => '0.10', 'source_uri' => 'https://issuer.example/case-1',
	'artifact_sha256' => hash( 'sha256', 'artifact' ),
);
$canonical = Image_Attestation::canonical_claims( $envelope );
check( is_array( $canonical ), 'valid claims canonicalize' );
$bytes = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
check( openssl_sign( $bytes, $signature, $key, OPENSSL_ALGO_SHA256 ), 'test attestation signature' );
$verified = Image_Attestation::verify_envelope( $envelope, base64_encode( $signature ) );
check( is_array( $verified ) && hash_equals( hash( 'sha256', $bytes ), $verified['digest'] ), 'server-pinned RSA SHA-256 envelope verifies' );
$weak_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024 ) );
$weak_details = openssl_pkey_get_details( $weak_key );
$GLOBALS['test_attestation_keys']['billing-test-v1']['public_key'] = $weak_details['key'];
check( is_wp_error( Image_Attestation::verify_envelope( $envelope, base64_encode( $signature ) ) ), 'RSA issuer keys below 2048 bits are rejected' );
$GLOBALS['test_attestation_keys']['billing-test-v1']['public_key'] = $details['key'];
$tampered = $envelope;
$tampered['cost_usd'] = '0.26';
check( is_wp_error( Image_Attestation::verify_envelope( $tampered, base64_encode( $signature ) ) ), 'changed signed cost is rejected' );
$GLOBALS['test_attestation_keys']['billing-test-v1']['authorities'] = array( 'outcome', 'provider_cost' );
check( is_wp_error( Image_Attestation::verify_envelope( $envelope, base64_encode( $signature ) ) ), 'issuer without user-cost authority cannot attest a Gateway debit' );

fwrite( STDOUT, "PASS: Gateway image recovery offline regressions\n" );