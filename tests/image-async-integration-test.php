<?php
/**
 * Database-backed lifecycle test for the asynchronous image path (image contract 1.1.0).
 *
 * Runs inside a loaded WordPress with the real tables, REST routes, ledger and
 * Azure provider code. No provider is reached: every HTTP request to the fake
 * Azure endpoint is answered by a `pre_http_request` filter, loopback worker
 * dispatch is pointed at a non-routable host, and WP-Cron scheduling is
 * short-circuited (the worker is run in-process, exactly what the loopback or
 * cron trigger would do). API key entries, prices and the image catalog are
 * injected through option filters and never written. A throwaway user and its
 * attempt, queue and ledger rows are removed at the end.
 *
 * Run (Gateway mounted as a plugin in a WordPress environment):
 *   wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/tests/image-async-integration-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside a loaded WordPress context.\n" );
	exit( 1 );
}

use Alorbach\AIGateway\Cost_Matrix;
use Alorbach\AIGateway\Image_Attempt_Worker;
use Alorbach\AIGateway\Image_Attempts;
use Alorbach\AIGateway\Integration_Service;
use Alorbach\AIGateway\Ledger;
use Alorbach\AIGateway\REST_Proxy;

$GLOBALS['k5_failures'] = 0;
function k5_check( $condition, $message ) {
	if ( $condition ) {
		fwrite( STDOUT, "PASS $message\n" );
		return;
	}
	$GLOBALS['k5_failures']++;
	fwrite( STDOUT, "FAIL $message\n" );
}

function k5_png( $w, $h, $color_type ) {
	$chunk = static function ( $type, $data ) { return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) ); };
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, $color_type, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( "\x00\x00" ) ) . $chunk( 'IEND', '' );
}

/** Perform an internal REST call as a given user. */
function k5_rest( $user_id, $method, $route, array $params = array() ) {
	wp_set_current_user( (int) $user_id );
	$request = new WP_REST_Request( $method, '/alorbach/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

global $wpdb;
$suffix     = substr( md5( uniqid( '', true ) ), 0, 8 );
$entry_id   = 'f5f5f5f5-0000-4000-8000-' . str_pad( $suffix, 12, '0' );
$model      = 'gpt-image-2.5-sunburst';
$model_key  = $entry_id . '::' . $model;
$user_id    = 0;
$other_id   = 0;
$tables     = array( 'attempts' => $wpdb->prefix . 'alorbach_image_attempts', 'jobs' => $wpdb->prefix . 'alorbach_image_attempt_jobs' );
$provider_log = array();
$loopback_log = array();
$GLOBALS['k5_mode'] = 'ok';
$tiny_png = base64_encode( k5_png( 8, 8, 6 ) );

$cleanup = static function () use ( &$user_id, &$other_id, $wpdb, $tables ) {
	foreach ( array( $user_id, $other_id ) as $uid ) {
		if ( $uid > 0 ) {
			$wpdb->delete( Ledger::get_table_name(), array( 'user_id' => $uid ), array( '%d' ) );
			$wpdb->delete( $tables['jobs'], array( 'owner_id' => $uid ), array( '%d' ) );
			$wpdb->delete( $tables['attempts'], array( 'owner_id' => $uid ), array( '%d' ) );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		}
	}
};

try {
	// Injected configuration (never persisted).
	$entries = array( 'entries' => array( array( 'id' => $entry_id, 'type' => 'azure', 'name' => 'k5-test', 'api_key' => 'K5-FAKE-KEY', 'endpoint' => 'https://fake-azure.invalid', 'enabled' => true ) ) );
	$prices  = array( $model => array() );
	foreach ( array( 'low', 'medium', 'high', 'xhigh', 'max' ) as $quality ) {
		$prices[ $model ][ $quality ]['1024x1024'] = 50000;
	}
	add_filter(
		'pre_option_alorbach_api_keys',
		static function () use ( $entries ) {
			if ( ! empty( $GLOBALS['k5_entry_off'] ) ) {
				$entries['entries'][0]['enabled'] = false;
			}
			return $entries;
		}
	);
	add_filter( 'pre_update_option_alorbach_api_keys', static function ( $new, $old ) { return $old; }, 10, 2 );
	add_filter( 'pre_option_alorbach_image_model_costs', static function () use ( $prices ) { return $prices; } );
	add_filter( 'pre_option_alorbach_image_models', static function () use ( $model_key ) { return array( $model_key ); } );
	add_filter( 'alorbach_image_job_internal_hosts', static function () { return array( '203.0.113.1' ); } );
	add_filter( 'alorbach_bypass_rate_limit', '__return_true' );
	add_filter( 'pre_schedule_event', static function ( $pre, $event ) use ( &$loopback_log ) {
		$loopback_log[] = 'cron:' . ( $event->hook ?? '' );
		return true;
	}, 10, 2 );
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) use ( &$provider_log, &$loopback_log ) {
			if ( false !== strpos( $url, 'fake-azure.invalid' ) ) {
				$provider_log[] = array( 'url' => $url, 'headers' => $args['headers'] ?? array(), 'body' => (string) ( $args['body'] ?? '' ) );
				if ( ! empty( $GLOBALS['k5_probe_attempt'] ) ) {
					global $wpdb;
					$GLOBALS['k5_probe_lease'] = $wpdb->get_var( $wpdb->prepare( 'SELECT lease_expires_at FROM ' . $wpdb->prefix . 'alorbach_image_attempt_jobs WHERE attempt_id = %d', (int) $GLOBALS['k5_probe_attempt'] ) );
				}
				if ( 'http_500' === $GLOBALS['k5_mode'] ) {
					return array( 'headers' => array(), 'body' => '{"error":{"code":"server_error","message":"boom"}}', 'response' => array( 'code' => 500, 'message' => 'ERR' ), 'cookies' => array() );
				}
				return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( 'FAKE-IMAGE-BYTES' ) ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
			}
			if ( false !== strpos( $url, 'internal/images/attempts/process' ) ) {
				$loopback_log[] = 'loopback';
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
			}
			return $pre;
		},
		10,
		3
	);

	$user_id = wp_insert_user( array( 'user_login' => 'k5_async_' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'k5_async_' . $suffix . '@example.invalid', 'role' => 'subscriber' ) );
	$other_id = wp_insert_user( array( 'user_login' => 'k5_other_' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'k5_other_' . $suffix . '@example.invalid', 'role' => 'subscriber' ) );
	if ( is_wp_error( $user_id ) || is_wp_error( $other_id ) ) {
		throw new RuntimeException( 'Could not create the throwaway users.' );
	}
	$user_id  = (int) $user_id;
	$other_id = (int) $other_id;
	Ledger::insert_transaction( $user_id, 'k5_test_grant', null, 50000000 );
	Ledger::insert_transaction( $other_id, 'k5_test_grant', null, 50000000 );
	$expected_cost = Cost_Matrix::apply_user_cost( 50000, $model_key );

	// 1. Published contract through the real configuration pipeline.
	wp_set_current_user( $user_id );
	$config   = Integration_Service::get_integration_config( $user_id );
	$contract = null;
	foreach ( (array) ( $config['capabilities']['models'] ?? array() ) as $candidate ) {
		if ( is_array( $candidate ) && $model_key === ( $candidate['gateway_model_key'] ?? '' ) && 'direct_image' === ( $candidate['transport'] ?? '' ) ) {
			$contract = $candidate;
		}
	}
	k5_check( is_array( $contract ) && true === $contract['eligible'] && '1.1.0' === $contract['image_contract_version'], 'config: the injected Azure entry publishes an eligible direct_image 1.1.0 contract' );
	k5_check( is_array( $contract ) && true === $contract['server_dispatch_evidenced'] && true === $contract['dispatch_entry_exclusive'] && true === $contract['mask_supported'] && in_array( 'image_inpaint', $contract['operation_kinds'], true ), 'config: dispatch evidence and inpainting are published through GET /integration/config' );
	k5_check( false === strpos( (string) wp_json_encode( $config ), 'K5-FAKE-KEY' ) && false === strpos( (string) wp_json_encode( $config ), 'fake-azure.invalid' ), 'config: no credential or endpoint leaks' );

	$cid     = 'k5-async-' . $suffix;
	$prompt  = "Picture book page 1:\nA fox sleeps under a tree.\n\nStyle: watercolor";
	$base    = array( 'prompt' => $prompt, 'model' => $model_key, 'size' => '1024x1024', 'quality' => 'high', 'output_format' => 'png', 'client_request_id' => $cid );
	$balance0 = Ledger::get_balance( $user_id );

	// 2. Async requires a key; validation errors create no attempt.
	$no_key = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => '', 'async' => true ) ) );
	k5_check( 422 === $no_key->get_status() && 'async_requires_client_request_id' === ( $no_key->get_data()['code'] ?? '' ), 'async: async=1 without client_request_id is refused (422)' );
	$bad_prompt = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'prompt' => "bad\x00prompt", 'client_request_id' => $cid . '-bad', 'async' => true ) ) );
	k5_check( 422 === $bad_prompt->get_status() && 'invalid_prompt' === ( $bad_prompt->get_data()['code'] ?? '' ) && 404 === k5_rest( $user_id, 'GET', '/images/attempts/' . $cid . '-bad' )->get_status(), 'async: an invalid prompt is refused before any attempt is claimed' );
	$reference = array( array( 'b64_json' => base64_encode( k5_png( 64, 48, 2 ) ), 'mime_type' => 'image/png' ) );
	$bad_mask  = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid . '-mm', 'async' => true, 'reference_images' => $reference, 'mask_image' => base64_encode( k5_png( 10, 10, 6 ) ) ) ) );
	k5_check( 422 === $bad_mask->get_status() && 'mask_dimension_mismatch' === ( $bad_mask->get_data()['code'] ?? '' ) && 404 === k5_rest( $user_id, 'GET', '/images/attempts/' . $cid . '-mm' )->get_status(), 'mask: a dimension mismatch is a 422 without an attempt' );
	$no_alpha = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid . '-na', 'async' => true, 'reference_images' => $reference, 'mask_image' => base64_encode( k5_png( 64, 48, 2 ) ) ) ) );
	k5_check( 422 === $no_alpha->get_status() && 'mask_alpha_required' === ( $no_alpha->get_data()['code'] ?? '' ), 'mask: an RGB mask is a 422 (mask_alpha_required)' );
	k5_check( 0 === count( $provider_log ), 'async: validation failures never reach the provider' );

	// 3. Queue: 202, nothing dispatched, status and result gating.
	$queued = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'async' => true ) ) );
	$data   = $queued->get_data();
	k5_check( 202 === $queued->get_status() && 'queued' === ( $data['status'] ?? '' ) && $cid === ( $data['attempt_id'] ?? '' ) && false === ( $data['replayed'] ?? null ), 'async: POST /images async=1 answers 202 {attempt_id, status: queued}' );
	k5_check( 'async' === ( $data['execution_mode'] ?? '' ) && ! empty( $data['evidence_id'] ) && 64 === strlen( (string) ( $data['request_hash'] ?? '' ) ) && $expected_cost === ( $data['estimated_cost_uc'] ?? null ) && 2000 === ( $data['poll_after_ms'] ?? null ), '202 body: evidence_id, request_hash, estimated cost and poll hint' );
	k5_check( 0 === count( $provider_log ) && Ledger::get_balance( $user_id ) === $balance0, 'async: queueing makes no provider call and no charge' );
	k5_check( in_array( 'cron:' . Image_Attempt_Worker::CRON_HOOK, $loopback_log, true ), 'async: a WP-Cron fallback event is scheduled' );
	$status = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid );
	k5_check( 200 === $status->get_status() && 'queued' === $status->get_data()['status'] && false === $status->get_data()['result_available'] && 'not_sent' === $status->get_data()['dispatch_status'], 'status: GET /images/attempts/{id} reports queued with not_sent evidence' );
	$pending = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid . '/result' );
	k5_check( 409 === $pending->get_status() && 'image_attempt_result_pending' === ( $pending->get_data()['code'] ?? '' ), 'result: GET .../result is 409 while the job is queued' );
	$stranger = k5_rest( $other_id, 'GET', '/images/attempts/' . $cid );
	k5_check( 404 === $stranger->get_status() && 404 === k5_rest( $other_id, 'GET', '/images/attempts/' . $cid . '/result' )->get_status(), 'ownership: another user sees neither status nor result' );

	// 4. Idempotent replay while queued.
	$replay = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'async' => true ) ) );
	k5_check( 200 === $replay->get_status() && true === ( $replay->get_data()['replayed'] ?? null ) && 'queued' === $replay->get_data()['status'] && $data['evidence_id'] === $replay->get_data()['evidence_id'], 'replay: the same client_request_id returns the stored attempt (200, replayed)' );
	$changed = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'async' => true, 'prompt' => $prompt . ' changed' ) ) );
	k5_check( 409 === $changed->get_status(), 'replay: the same client_request_id with a different request is a 409 conflict' );
	$jobs_for_cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['jobs']} WHERE owner_id = %d AND client_request_id = %s", $user_id, $cid ) );
	k5_check( 1 === $jobs_for_cid && 0 === count( $provider_log ), 'replay: exactly one queue row and still no provider call' );

	// 5. Worker run: one provider call, one charge.
	$attempt_row = Image_Attempts::latest_raw( $user_id, $cid );
	$outcome     = Image_Attempt_Worker::run( (int) $attempt_row['attempt_id'] );
	k5_check( 'started' === $outcome && 1 === count( $provider_log ), 'worker: the queued attempt dispatches exactly once' );
	$sent = $provider_log[0];
	k5_check( false !== strpos( $sent['url'], 'fake-azure.invalid/openai/deployments/gpt-image-2.5-sunburst/images/generations' ) && 'K5-FAKE-KEY' === ( $sent['headers']['api-key'] ?? '' ), 'worker: the request goes to the entry endpoint with the entry key' );
	k5_check( json_decode( $sent['body'], true )['prompt'] === $prompt && false !== strpos( $sent['body'], 'A fox sleeps under a tree.\n\nStyle' ), 'prompt: newlines survive REST, hash, queue storage and the provider body' );
	k5_check( Ledger::get_balance( $user_id ) === $balance0 - $expected_cost, 'worker: the user is charged exactly once' );
	$done = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid )->get_data();
	k5_check( 'succeeded' === $done['status'] && true === $done['result_available'] && 'recorded' === $done['billing_status'] && 'succeeded' === $done['outcome'] && null !== $done['cost_usd'] && null === $done['error'] && ! empty( $done['finished_at'] ), 'status: succeeded with recorded billing, cost and finished_at' );
	$result = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid . '/result' );
	$bytes  = $result->get_data();
	k5_check( 200 === $result->get_status() && base64_encode( 'FAKE-IMAGE-BYTES' ) === ( $bytes['data'][0]['b64_json'] ?? '' ) && $expected_cost === ( $bytes['cost_uc'] ?? null ) && $cid === $bytes['attempt_id'], 'result: bytes (b64_json) and cost are released after billing without evidence parameters' );
	$bound = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid . '/result', array( 'evidence_id' => $done['evidence_id'], 'request_hash' => $done['request_hash'] ) );
	$wrong = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid . '/result', array( 'evidence_id' => $done['evidence_id'], 'request_hash' => str_repeat( '0', 64 ) ) );
	k5_check( 200 === $bound->get_status() && 404 === $wrong->get_status(), 'result: supplied evidence_id/request_hash must match' );

	// 6. No double dispatch or double charge from any later trigger.
	k5_check( 'skipped' === Image_Attempt_Worker::run( (int) $attempt_row['attempt_id'] ), 'worker: a second trigger (cron after loopback) is skipped' );
	$replay_done = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'async' => true ) ) );
	k5_check( 200 === $replay_done->get_status() && 'succeeded' === $replay_done->get_data()['status'] && true === $replay_done->get_data()['replayed'] && ! isset( $replay_done->get_data()['data'] ), 'replay: after completion async replay reports succeeded without bytes' );
	$sync_replay = k5_rest( $user_id, 'POST', '/images', $base );
	k5_check( 200 === $sync_replay->get_status() && base64_encode( 'FAKE-IMAGE-BYTES' ) === ( $sync_replay->get_data()['data'][0]['b64_json'] ?? '' ), 'replay: a synchronous request with the same key returns the stored result' );
	k5_check( 1 === count( $provider_log ) && Ledger::get_balance( $user_id ) === $balance0 - $expected_cost, 'replay: still one provider call and one charge after all replays' );

	// 7. Mask path through the queue.
	$cid_mask = $cid . '-mask';
	$mask_png = k5_png( 64, 48, 6 );
	$masked   = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_mask, 'async' => true, 'prompt' => 'Replace the sky', 'reference_images' => $reference, 'mask_image' => 'data:image/png;base64,' . base64_encode( $mask_png ) ) ) );
	k5_check( 202 === $masked->get_status(), 'mask: an inpainting request with a valid mask is queued (202)' );
	$mask_attempt = Image_Attempts::latest_raw( $user_id, $cid_mask );
	Image_Attempt_Worker::run( (int) $mask_attempt['attempt_id'] );
	$mask_sent = $provider_log[1] ?? array( 'url' => '', 'body' => '' );
	k5_check( false !== strpos( $mask_sent['url'], '/openai/deployments/gpt-image-2.5-sunburst/images/edits' ) && false !== strpos( $mask_sent['body'], "name=\"mask\"; filename=\"mask.png\"\r\nContent-Type: image/png\r\n\r\n" . $mask_png ) && false !== strpos( $mask_sent['body'], 'name="image[]"' ), 'mask: the worker sends images/edits with image[] and the exact mask PNG' );
	k5_check( 'succeeded' === k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_mask )->get_data()['status'], 'mask: the inpainting attempt succeeds' );

	// 8. Provider failure: unknown outcome, no charge, never resent.
	$balance_before_fail = Ledger::get_balance( $user_id );
	$GLOBALS['k5_mode']  = 'http_500';
	$cid_fail            = $cid . '-fail';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_fail, 'async' => true ) ) );
	$fail_attempt = Image_Attempts::latest_raw( $user_id, $cid_fail );
	$calls_before = count( $provider_log );
	k5_check( 'failed' === Image_Attempt_Worker::run( (int) $fail_attempt['attempt_id'] ) && $calls_before + 1 === count( $provider_log ), 'failure: the worker makes one provider call and reports failure' );
	$GLOBALS['k5_mode'] = 'ok';
	$failed = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_fail )->get_data();
	k5_check( 'failed' === $failed['status'] && 'image_provider_error' === $failed['error']['code'] && 'unknown' === $failed['outcome'] && 'possibly_sent' === $failed['dispatch_status'] && false === $failed['retry_safe'] && Ledger::get_balance( $user_id ) === $balance_before_fail, 'failure: status failed/image_provider_error, outcome unknown, not retry-safe, no charge' );
	k5_check( 404 === k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_fail . '/result' )->get_status(), 'failure: no result bytes are released' );
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_fail, 'async' => true ) ) );
	k5_check( $calls_before + 1 === count( $provider_log ) && 'skipped' === Image_Attempt_Worker::run( (int) $fail_attempt['attempt_id'] ), 'failure: replay and re-trigger never send the request again' );

	// 9. Funds withdrawn between queueing and running: rejected before the provider.
	$cid_poor = $cid . '-poor';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_poor, 'async' => true ) ) );
	$poor_attempt = Image_Attempts::latest_raw( $user_id, $cid_poor );
	$drain        = Ledger::get_balance( $user_id );
	Ledger::insert_transaction( $user_id, 'k5_test_drain', null, -$drain );
	$calls_before = count( $provider_log );
	Image_Attempt_Worker::run( (int) $poor_attempt['attempt_id'] );
	$poor = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_poor )->get_data();
	k5_check( $calls_before === count( $provider_log ) && 'failed' === $poor['status'] && 'insufficient_credits' === $poor['error']['code'] && 'not_sent' === $poor['dispatch_status'] && true === $poor['retry_safe'], 'funds: a balance that no longer covers the image fails as insufficient_credits, not sent, retry-safe' );
	Ledger::insert_transaction( $user_id, 'k5_test_regrant', null, $drain );

	// 10. Worker loss: lease expiry is reaped, never re-dispatched.
	$cid_lost = $cid . '-lost';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_lost, 'async' => true ) ) );
	$lost_attempt = Image_Attempts::latest_raw( $user_id, $cid_lost );
	$wpdb->update( $tables['jobs'], array( 'status' => 'running', 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 4000 ), 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 100 ) ), array( 'attempt_id' => (int) $lost_attempt['attempt_id'] ) );
	$calls_before = count( $provider_log );
	$lost = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_lost )->get_data();
	k5_check( 'failed' === $lost['status'] && 'worker_lost' === $lost['error']['code'] && 'not_sent' === $lost['dispatch_status'] && true === $lost['retry_safe'], 'reap: a worker lost before the handoff is a conclusive zero-cost rejection' );
	k5_check( $calls_before === count( $provider_log ) && 'skipped' === Image_Attempt_Worker::run( (int) $lost_attempt['attempt_id'] ), 'reap: a reaped job is never dispatched afterwards' );
	$cid_lost2 = $cid . '-lost2';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_lost2, 'async' => true ) ) );
	$lost2 = Image_Attempts::latest_raw( $user_id, $cid_lost2 );
	Image_Attempts::mark_possibly_sent( $lost2 );
	$wpdb->update( $tables['jobs'], array( 'status' => 'running', 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 100 ) ), array( 'attempt_id' => (int) $lost2['attempt_id'] ) );
	$lost2_status = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_lost2 )->get_data();
	k5_check( 'failed' === $lost2_status['status'] && 'worker_lost' === $lost2_status['error']['code'] && 'possibly_sent' === $lost2_status['dispatch_status'] && false === $lost2_status['retry_safe'], 'reap: a worker lost after the handoff leaves an unknown outcome that is not retry-safe' );

	// 11. A stalled queued job is re-dispatched by polling.
	$cid_kick = $cid . '-kick';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_kick, 'async' => true ) ) );
	$kick_attempt = Image_Attempts::latest_raw( $user_id, $cid_kick );
	$wpdb->update( $tables['jobs'], array( 'last_dispatch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'attempt_id' => (int) $kick_attempt['attempt_id'] ) );
	$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT dispatch_count FROM {$tables['jobs']} WHERE attempt_id = %d", (int) $kick_attempt['attempt_id'] ) );
	k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_kick );
	$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT dispatch_count FROM {$tables['jobs']} WHERE attempt_id = %d", (int) $kick_attempt['attempt_id'] ) );
	k5_check( $after === $before + 1, 'poll: a queued job whose last dispatch is old is dispatched again (token rotated)' );
	$bad_token = Image_Attempt_Worker::process_loopback( (int) $kick_attempt['attempt_id'], 'wrong-token' );
	k5_check( is_wp_error( $bad_token ) && 'invalid_attempt_token' === $bad_token->get_error_code(), 'loopback: a wrong dispatch token is refused' );

	// 13. Claim-to-enqueue window: a reserved claim reads "queued", an abandoned one is closed as not sent.
	$cid_prep = $cid . '-prep';
	$cid_bad  = $cid . '-prepfail';
	$failed_claim = Image_Attempts::claim( $user_id, $cid_bad, hash( 'sha256', 'prepfail' ), null, static function () { return new WP_Error( 'image_queue_unavailable', 'x', array( 'status' => 503 ) ); } );
	k5_check( is_wp_error( $failed_claim ) && 'image_queue_unavailable' === $failed_claim->get_error_code() && 404 === k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_bad )->get_status(), 'window: a failing reservation rolls the claim back (no attempt, the id is not burned)' );
	Image_Attempt_Worker::ensure_storage();
	$claimed  = Image_Attempts::claim( $user_id, $cid_prep, hash( 'sha256', 'prep' ), null, static function ( $row ) use ( $user_id ) { return Image_Attempt_Worker::reserve( $user_id, $row ); } );
	$prep_row = is_array( $claimed ) ? $claimed['attempt'] : array();
	k5_check( is_array( $claimed ) && 'claimed' === $claimed['state'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['jobs']} WHERE attempt_id = %d AND status = 'preparing'", (int) $prep_row['attempt_id'] ) ), 'window: the queue row is reserved inside the claim transaction (preparing)' );
	$during = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_prep )->get_data();
	k5_check( 'queued' === $during['status'] && 'async' === $during['execution_mode'] && null === $during['error'] && 'not_sent' === $during['dispatch_status'] && false === $during['retry_safe'], 'window: between claim and enqueue a concurrent replay reports queued/async, not outcome unknown' );
	$wpdb->update( $tables['jobs'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'attempt_id' => (int) $prep_row['attempt_id'] ) );
	$abandoned = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_prep )->get_data();
	k5_check( 'failed' === $abandoned['status'] && 'queue_timeout' === $abandoned['error']['code'] && 'not_sent' === $abandoned['dispatch_status'] && true === $abandoned['retry_safe'], 'window: an abandoned preparation is closed as a conclusive zero-cost rejection' );
	$cid_rel = $cid . '-rel';
	$claimed_rel = Image_Attempts::claim( $user_id, $cid_rel, hash( 'sha256', 'rel' ), null );
	REST_Proxy::release_unsent_image_attempt( $user_id, $cid_rel );
	$released = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_rel )->get_data();
	k5_check( 'rejected' === $released['outcome'] && 'not_sent' === $released['dispatch_status'] && 'not_charged' === $released['billing_status'] && true === $released['retry_safe'], 'exception: an attempt that never reached the provider handoff is released as rejected/not_charged' );
	$cid_rel2 = $cid . '-rel2';
	$claimed_rel2 = Image_Attempts::claim( $user_id, $cid_rel2, hash( 'sha256', 'rel2' ), null );
	Image_Attempts::mark_possibly_sent( $claimed_rel2['attempt'] );
	REST_Proxy::release_unsent_image_attempt( $user_id, $cid_rel2 );
	$kept = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_rel2 )->get_data();
	k5_check( 'unknown' === $kept['outcome'] && 'possibly_sent' === $kept['dispatch_status'], 'exception: after the handoff mark the attempt stays unknown' );

	// 14. A job queued for more than 24 hours is closed as not sent and its payload dropped.
	$cid_old = $cid . '-old';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_old, 'async' => true ) ) );
	$old_attempt = Image_Attempts::latest_raw( $user_id, $cid_old );
	$wpdb->update( $tables['jobs'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90000 ), 'last_dispatch_at' => gmdate( 'Y-m-d H:i:s', time() - 90000 ) ), array( 'attempt_id' => (int) $old_attempt['attempt_id'] ) );
	$calls_before = count( $provider_log );
	$old_status   = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_old )->get_data();
	$old_payload  = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$tables['jobs']} WHERE attempt_id = %d", (int) $old_attempt['attempt_id'] ) );
	k5_check( 'failed' === $old_status['status'] && 'queue_timeout' === $old_status['error']['code'] && 'not_sent' === $old_status['dispatch_status'] && 'not_charged' === $old_status['billing_status'] && true === $old_status['retry_safe'] && null === $old_payload, 'timeout: a job queued for more than 24 hours is closed (not_sent, not_charged, queue_timeout) and the payload is deleted' );
	k5_check( $calls_before === count( $provider_log ) && 'skipped' === Image_Attempt_Worker::run( (int) $old_attempt['attempt_id'] ), 'timeout: a timed-out job is never dispatched' );

	// 15. Oversized async requests are refused (413) before the id is claimed.
	$packet_row = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_A );
	$packet     = (int) ( $packet_row['Value'] ?? 0 );
	if ( $packet > 0 && $packet < 100000000 ) {
		$cid_big = $cid . '-big';
		$huge    = array( array( 'b64_json' => str_repeat( 'A', $packet ), 'mime_type' => 'image/png' ) );
		$too_big = Image_Attempt_Worker::payload_too_large( array( 'reference_images' => $huge ) );
		k5_check( is_wp_error( $too_big ) && 'image_request_too_large' === $too_big->get_error_code() && 413 === ( $too_big->get_error_data()['status'] ?? 0 ), 'size: a payload above max_allowed_packet minus the margin is refused (413 image_request_too_large)' );
		$big_response = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_big, 'async' => true, 'reference_images' => $huge ) ) );
		k5_check( 413 === $big_response->get_status() && 'image_request_too_large' === ( $big_response->get_data()['code'] ?? '' ) && 404 === k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_big )->get_status(), 'size: POST /images async=1 answers 413 and burns no client_request_id' );
		$limit_bytes = min( Image_Attempt_Worker::MAX_PAYLOAD_BYTES, $packet - Image_Attempt_Worker::PACKET_MARGIN_BYTES );
		$near_ok  = Image_Attempt_Worker::payload_too_large( array( 'x' => str_repeat( 'A', $limit_bytes - 2 * Image_Attempt_Worker::PAYLOAD_OVERHEAD_BYTES ) ) );
		$near_bad = Image_Attempt_Worker::payload_too_large( array( 'x' => str_repeat( 'A', $limit_bytes - 1000 ) ) );
		k5_check( true === $near_ok && is_wp_error( $near_bad ), 'size: the pre-claim limit is the enqueue limit minus the allowance for fields computed after the claim' );
		unset( $near_ok, $near_bad );
		unset( $huge, $too_big, $big_response );
	} else {
		fwrite( STDOUT, "SKIP size: max_allowed_packet unavailable or too large for a fixture ({$packet})\n" );
	}
	k5_check( true === Image_Attempt_Worker::payload_too_large( array( 'prompt' => 'small' ) ), 'size: a small payload is accepted' );

	// 16. The worker lease covers n x the per-call timeout plus a margin.
	$cid_lease = $cid . '-lease';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_lease, 'async' => true ) ) );
	$lease_attempt = Image_Attempts::latest_raw( $user_id, $cid_lease );
	$lease_cap     = static function () { return 60; };
	$timeout_1800  = static function () { return 1800; };
	add_filter( 'alorbach_image_attempt_lease_seconds', $lease_cap );
	add_filter( 'alorbach_image_request_timeout', $timeout_1800 );
	$GLOBALS['k5_probe_attempt'] = (int) $lease_attempt['attempt_id'];
	$GLOBALS['k5_probe_lease']   = null;
	$lease_started               = time();
	Image_Attempt_Worker::run( (int) $lease_attempt['attempt_id'] );
	$GLOBALS['k5_probe_attempt'] = 0;
	remove_filter( 'alorbach_image_attempt_lease_seconds', $lease_cap );
	remove_filter( 'alorbach_image_request_timeout', $timeout_1800 );
	$lease_span = is_string( $GLOBALS['k5_probe_lease'] ) ? strtotime( $GLOBALS['k5_probe_lease'] . ' UTC' ) - $lease_started : 0;
	k5_check( $lease_span >= 1800 + Image_Attempt_Worker::LEASE_MARGIN_SECONDS - 5, 'lease: a lease below the worst case is stretched to n x per-call timeout + margin (observed ' . $lease_span . ' s)' );

	// 17. A pre-dispatch refusal keeps its classified code (no collapse into image_provider_error).
	$cid_gone = $cid . '-gone';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_gone, 'async' => true ) ) );
	$gone_attempt = Image_Attempts::latest_raw( $user_id, $cid_gone );
	$GLOBALS['k5_entry_off'] = true;
	$calls_before = count( $provider_log );
	Image_Attempt_Worker::run( (int) $gone_attempt['attempt_id'] );
	$GLOBALS['k5_entry_off'] = false;
	$gone = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_gone )->get_data();
	k5_check( $calls_before === count( $provider_log ) && 'failed' === $gone['status'] && 'image_dispatch_entry_unavailable' === $gone['error']['code'] && 'not_sent' === $gone['dispatch_status'] && true === $gone['retry_safe'], 'dispatch: an entry that vanished before the handoff keeps image_dispatch_entry_unavailable (not_sent, retry-safe), not image_provider_error' );

	// 18. The mask gate applies to plain model names too.
	$plain_mask = k5_rest( $user_id, 'POST', '/images', array( 'prompt' => 'edit', 'model' => $model, 'size' => '1024x1024', 'quality' => 'high', 'reference_images' => $reference, 'mask_image' => base64_encode( k5_png( 64, 48, 6 ) ) ) );
	k5_check( 422 === $plain_mask->get_status() && 'direct_image_mask_unsupported' === ( $plain_mask->get_data()['code'] ?? '' ), 'mask: a plain model name (no entry, no direct-image contract) is refused with 422 direct_image_mask_unsupported' );

	// 19. A numeric prompt is accepted; other non-strings are not.
	$cid_num = $cid . '-num';
	$numeric = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_num, 'async' => true, 'prompt' => 12345 ) ) );
	k5_check( 202 === $numeric->get_status(), 'prompt: a numeric prompt is cast to a string and queued' );
	$array_prompt = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid . '-arr', 'async' => true, 'prompt' => array( 'x' ) ) ) );
	k5_check( 422 === $array_prompt->get_status() && 'invalid_prompt' === ( $array_prompt->get_data()['code'] ?? '' ), 'prompt: an array prompt is refused (422 invalid_prompt)' );

	// 20. An exception before the handoff releases the attempt and reports a matching code; finish() never reopens a closed job.
	$cid_boom = $cid . '-boom';
	k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_boom, 'async' => true ) ) );
	$boom_attempt = Image_Attempts::latest_raw( $user_id, $cid_boom );
	$thrower = static function () { throw new RuntimeException( 'boom before handoff' ); };
	add_filter( 'alorbach_image_request_timeout', $thrower );
	$calls_before = count( $provider_log );
	Image_Attempt_Worker::run( (int) $boom_attempt['attempt_id'] );
	remove_filter( 'alorbach_image_request_timeout', $thrower );
	$boom = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_boom )->get_data();
	k5_check( $calls_before === count( $provider_log ) && 'failed' === $boom['status'] && 'worker_failed_before_send' === $boom['error']['code'] && 'rejected' === $boom['outcome'] && 'not_sent' === $boom['dispatch_status'] && true === $boom['retry_safe'], 'exception: a worker exception before the handoff is released (rejected, not_charged) with a matching error code' );
	$finish = new ReflectionMethod( Image_Attempt_Worker::class, 'finish' );
	$finish->setAccessible( true );
	$finish->invoke( null, (int) $boom_attempt['attempt_id'], 'worker_exception' );
	$still = $wpdb->get_var( $wpdb->prepare( "SELECT error_code FROM {$tables['jobs']} WHERE attempt_id = %d", (int) $boom_attempt['attempt_id'] ) );
	k5_check( 'worker_failed_before_send' === $still, 'finish: an already closed job is not overwritten' );

	// 21. An async replay of a request rejected at validation reports the stored attempt (200, replayed), not a 409.
	$cid_inv = $cid . '-invalid';
	$invalid_body = array_merge( $base, array( 'client_request_id' => $cid_inv, 'async' => true, 'size' => '999x999' ) );
	$invalid_first = k5_rest( $user_id, 'POST', '/images', $invalid_body );
	$invalid_again = k5_rest( $user_id, 'POST', '/images', $invalid_body );
	k5_check( 422 === $invalid_first->get_status() && 200 === $invalid_again->get_status() && true === ( $invalid_again->get_data()['replayed'] ?? null ) && 'failed' === ( $invalid_again->get_data()['status'] ?? '' ) && 'rejected' === ( $invalid_again->get_data()['outcome'] ?? '' ), 'replay: an async replay of a validation-rejected request returns 200 replayed=true with the stored attempt' );
	$invalid_sync = k5_rest( $user_id, 'POST', '/images', array_merge( $invalid_body, array( 'async' => false ) ) );
	k5_check( 409 === $invalid_sync->get_status(), 'replay: a synchronous replay of a rejected attempt is still a 409' );

	// 12. Synchronous behavior is unchanged.
	$cid_sync = $cid . '-sync';
	$balance_sync = Ledger::get_balance( $user_id );
	$calls_before = count( $provider_log );
	$sync = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_sync ) ) );
	k5_check( 200 === $sync->get_status() && base64_encode( 'FAKE-IMAGE-BYTES' ) === ( $sync->get_data()['data'][0]['b64_json'] ?? '' ) && isset( $sync->get_data()['cost_uc'] ) && $calls_before + 1 === count( $provider_log ) && Ledger::get_balance( $user_id ) === $balance_sync - $expected_cost, 'sync: without async the request answers 200 with bytes and charges once' );
	$sync_status = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_sync )->get_data();
	k5_check( 'succeeded' === $sync_status['status'] && 'sync' === $sync_status['execution_mode'], 'sync: the status endpoint also describes synchronous attempts' );
	$sync_again = k5_rest( $user_id, 'POST', '/images', array_merge( $base, array( 'client_request_id' => $cid_sync ) ) );
	k5_check( 200 === $sync_again->get_status() && $calls_before + 1 === count( $provider_log ), 'sync: a replay returns the stored result without a second provider call' );
	$old_result = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_sync . '/result' );
	k5_check( 400 === $old_result->get_status(), 'sync: /result without evidence_id and request_hash is still refused for synchronous attempts' );
	$old_result_ok = k5_rest( $user_id, 'GET', '/images/attempts/' . $cid_sync . '/result', array( 'evidence_id' => $sync_status['evidence_id'], 'request_hash' => $sync_status['request_hash'] ) );
	k5_check( 200 === $old_result_ok->get_status(), 'sync: /result with the evidence binding works as before' );
} catch ( Throwable $error ) {
	$GLOBALS['k5_failures']++;
	fwrite( STDOUT, 'FAIL unexpected exception: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n" );
} finally {
	$cleanup();
}

if ( $GLOBALS['k5_failures'] > 0 ) {
	fwrite( STDERR, "\n" . $GLOBALS['k5_failures'] . " check(s) failed.\n" );
	exit( 1 );
}
fwrite( STDOUT, "\nAll async image attempt integration checks passed.\n" );
