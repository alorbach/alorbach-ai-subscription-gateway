<?php
/**
 * Verify core REST contract payload shapes from inside a loaded WordPress context.
 *
 * Run with wp-env / WP-CLI, for example:
 * wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/bin/verify-rest-contracts.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside a loaded WordPress context.\n" );
	exit( 1 );
}

$admin = get_user_by( 'login', 'admin' );
if ( ! $admin ) {
	fwrite( STDERR, "Could not resolve the admin user.\n" );
	exit( 1 );
}

wp_set_current_user( (int) $admin->ID );

/**
 * Perform a REST request and decode the response data.
 *
 * @param string $route  Route path.
 * @param string $method HTTP method.
 * @return array
 */
function alorbach_verify_request( $route, $method = 'GET' ) {
	$request  = new WP_REST_Request( $method, $route );
	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		$error = $response->as_error();
		throw new RuntimeException( sprintf( '%s failed: %s', $route, $error->get_error_message() ) );
	}

	return $response->get_data();
}

/**
 * Require object keys to exist.
 *
 * @param array  $payload Payload array.
 * @param array  $keys    Required keys.
 * @param string $label   Human label.
 * @return void
 */
function alorbach_require_keys( array $payload, array $keys, $label ) {
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $payload ) ) {
			throw new RuntimeException( sprintf( '%s is missing key "%s".', $label, $key ) );
		}
	}
}

/**
 * Require a payload key to be an array.
 *
 * @param array  $payload Payload array.
 * @param string $key     Key name.
 * @param string $label   Human label.
 * @return void
 */
function alorbach_require_array_key( array $payload, $key, $label ) {
	if ( ! isset( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
		throw new RuntimeException( sprintf( '%s key "%s" must be an array.', $label, $key ) );
	}
}

/**
 * Require a condition to be truthy.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function alorbach_require( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Attach JSON body params to a REST request.
 *
 * @param WP_REST_Request $request Request.
 * @param array           $params Params.
 * @return void
 */
function alorbach_set_json_body( WP_REST_Request $request, array $params ) {
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $params ) );
}

try {
	$config  = alorbach_verify_request( '/alorbach/v1/integration/config' );
	$plans   = alorbach_verify_request( '/alorbach/v1/integration/plans' );
	$account = alorbach_verify_request( '/alorbach/v1/integration/account' );
	$models  = alorbach_verify_request( '/alorbach/v1/me/models' );

	alorbach_require_keys( $config, array( 'defaults', 'capabilities', 'plan_capabilities', 'billing_urls' ), '/integration/config' );
	alorbach_require_keys( $plans, array( 'plans' ), '/integration/plans' );
	alorbach_require_keys( $account, array( 'user_id', 'balance', 'usage_month', 'billing_urls', 'active_plan' ), '/integration/account' );
	alorbach_require_keys( $models, array( 'text', 'image', 'audio', 'video' ), '/me/models' );
	alorbach_require_keys( $models['image'], array( 'supports_progress', 'supports_provider_progress', 'supports_preview_images', 'progress_mode', 'job_endpoint' ), '/me/models.image' );
	alorbach_require_array_key( $models['image'], 'provider_progress_models', '/me/models.image' );
	alorbach_require_array_key( $models['image'], 'preview_models', '/me/models.image' );
	alorbach_require_array_key( $models['image']['model'], 'options', '/me/models.image.model' );

	if ( ! in_array( $models['image']['progress_mode'], array( 'provider', 'estimated' ), true ) ) {
		throw new RuntimeException( '/me/models.image.progress_mode must be "provider" or "estimated".' );
	}

	foreach ( $models['image']['preview_models'] as $preview_model ) {
		if ( ! in_array( $preview_model, $models['image']['provider_progress_models'], true ) ) {
			throw new RuntimeException( 'Every preview model must also be listed as a provider-progress model.' );
		}
	}

	$old_local_codex_enabled = get_option( 'alorbach_local_codex_enabled', true );
	$old_local_codex_audio_fee_uc = get_option( 'alorbach_local_codex_audio_fee_uc', 0 );
	$old_ai_bridge_enabled = get_option( 'alorbach_ai_bridge_enabled', '__alorbach_missing__' );
	$old_ai_bridge_audio_fee_uc = get_option( 'alorbach_ai_bridge_audio_fee_uc', '__alorbach_missing__' );
	$old_plans = get_option( 'alorbach_plans', '__alorbach_missing__' );
	$old_api_keys = get_option( 'alorbach_api_keys', '__alorbach_missing__' );
	try {
		$local_codex_verify_run_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'local-codex-audio-', true );
		\Alorbach\AIGateway\AI_Bridge::update_setting( 'enabled', true );
		\Alorbach\AIGateway\AI_Bridge::update_setting( 'audio_fee_uc', 0 );
		delete_option( 'alorbach_ai_bridge_contract_probe' );
		update_option( 'alorbach_local_codex_contract_probe', 'legacy-value', false );
		alorbach_require( 'legacy-value' === \Alorbach\AIGateway\AI_Bridge::get_setting( 'contract_probe' ) && 'legacy-value' === get_option( 'alorbach_ai_bridge_contract_probe' ), 'AI Bridge settings must migrate legacy option values on read.' );
		\Alorbach\AIGateway\AI_Bridge::update_setting( 'contract_probe', 'dual-value' );
		alorbach_require( 'dual-value' === get_option( 'alorbach_ai_bridge_contract_probe' ) && 'dual-value' === get_option( 'alorbach_local_codex_contract_probe' ), 'AI Bridge settings must dual-write canonical and legacy option names.' );
		delete_option( 'alorbach_ai_bridge_contract_probe' );
		delete_option( 'alorbach_local_codex_contract_probe' );
		$test_plans = is_array( $old_plans ) ? $old_plans : \Alorbach\AIGateway\Integration_Service::get_default_plans();
		foreach ( $test_plans as &$test_plan ) {
			$test_plan['capabilities'] = array( 'chat' => true, 'image' => true, 'audio' => true, 'video' => true );
			$test_plan['allowed_models'] = array( 'chat' => array(), 'image' => array(), 'audio' => array(), 'video' => array() );
		}
		unset( $test_plan );
		update_option( 'alorbach_plans', $test_plans, false );
		$migration_probe = array(
			array( 'id' => 'verify-localcodex', 'type' => 'codex', 'name' => 'localcodex', 'api_key' => 'legacy-secret', 'enabled' => false ),
			array( 'id' => 'verify-codex-oauth', 'type' => 'codex', 'name' => 'ChatGPT OAuth', 'api_key' => '', 'enabled' => true ),
			array( 'id' => 'verify-openai-localcodex', 'type' => 'openai', 'name' => 'localcodex', 'api_key' => 'openai-secret', 'enabled' => true ),
		);
		update_option( 'alorbach_api_keys', array( 'entries' => $migration_probe ), false );
		$migrated_entries = \Alorbach\AIGateway\API_Keys_Helper::get_entries();
		$migrated_by_id = array();
		foreach ( $migrated_entries as $migrated_entry ) {
			$migrated_by_id[ $migrated_entry['id'] ] = $migrated_entry;
		}
		alorbach_require( 'ai_bridge' === ( $migrated_by_id['verify-localcodex']['type'] ?? '' ) && empty( $migrated_by_id['verify-localcodex']['api_key'] ) && empty( $migrated_by_id['verify-localcodex']['enabled'] ), 'Only the legacy codex entry named localcodex must migrate to credential-free ai_bridge while preserving id and enabled state.' );
		alorbach_require( 'codex' === ( $migrated_by_id['verify-codex-oauth']['type'] ?? '' ), 'Named Codex OAuth entries must remain direct codex providers.' );
		alorbach_require( 'openai' === ( $migrated_by_id['verify-openai-localcodex']['type'] ?? '' ), 'Unrelated providers named localcodex must remain unchanged.' );
		if ( '__alorbach_missing__' === $old_api_keys ) {
			delete_option( 'alorbach_api_keys' );
		} else {
			update_option( 'alorbach_api_keys', $old_api_keys, false );
		}
		$local_codex_verify = \Alorbach\AIGateway\API_Validator::verify_key( 'codex_local' );
		$ai_bridge_verify = \Alorbach\AIGateway\API_Validator::verify_key( 'ai_bridge' );
		alorbach_require( ! empty( $local_codex_verify['success'] ) && false !== strpos( (string) ( $local_codex_verify['message'] ?? '' ), 'browser tray app' ), 'Legacy Local Codex validation must pass when AI Model Relay is enabled without a stored API key.' );
		alorbach_require( ! empty( $ai_bridge_verify['success'] ), 'The canonical AI Bridge provider must be registered and enabled.' );
		$local_codex_config = \Alorbach\AIGateway\Integration_Service::get_integration_config( 0 );
		alorbach_require( isset( $local_codex_config['ai_bridge'] ) && $local_codex_config['ai_bridge'] === $local_codex_config['local_codex'], '/integration/config must expose identical ai_bridge and local_codex compatibility objects.' );
		alorbach_require( 'codex-local:audio' === ( $local_codex_config['local_codex']['audio_model'] ?? '' ), '/integration/config must expose the Local Codex audio model id.' );
		alorbach_require( in_array( 'codex-local:audio', $local_codex_config['capabilities']['audio_models'] ?? array(), true ), '/integration/config must expose codex-local:audio in the audio catalog.' );
		alorbach_require( in_array( 'codex-local:audio:whisper-large-v3', $local_codex_config['capabilities']['audio_models'] ?? array(), true ), '/integration/config must expose Local Whisper submodels in the audio catalog.' );

		$ai_bridge_config = alorbach_verify_request( '/alorbach/v1/ai-bridge/config' );
		$legacy_bridge_config = alorbach_verify_request( '/alorbach/v1/local-codex/config' );
		alorbach_require( 'AI Model Relay' === ( $ai_bridge_config['product_name'] ?? '' ) && ( $ai_bridge_config['bridge_url'] ?? '' ) === ( $legacy_bridge_config['bridge_url'] ?? null ), 'Canonical and legacy bridge config routes must expose equivalent settings.' );
		alorbach_require( 'model-relay:*' === ( $ai_bridge_config['model_policy']['relay_wildcard'] ?? '' ), 'AI Bridge config must expose the capability-scoped relay wildcard policy.' );

		$relay_chat_create = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs' );
		alorbach_set_json_body( $relay_chat_create, array( 'type' => 'chat', 'payload' => array( 'model' => 'model-relay:cursor-cli:auto', 'messages' => array( array( 'role' => 'user', 'content' => 'Relay contract ' . $local_codex_verify_run_id ) ) ) ) );
		$relay_chat_response = rest_do_request( $relay_chat_create );
		$relay_chat_data = $relay_chat_response->get_data();
		alorbach_require( 200 === $relay_chat_response->get_status() && 'model-relay:cursor-cli:auto' === ( $relay_chat_data['payload']['model'] ?? '' ), 'Canonical AI Bridge jobs must sign Cursor chat models.' );
		$relay_chat_complete = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs/' . rawurlencode( (string) ( $relay_chat_data['job_id'] ?? '' ) ) . '/complete' );
		alorbach_set_json_body( $relay_chat_complete, array( 'job_token' => (string) ( $relay_chat_data['job_token'] ?? '' ), 'request_hash' => (string) ( $relay_chat_data['request_hash'] ?? '' ), 'result' => array( 'response' => array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Cursor relay reply' ) ) ), 'usage' => array( 'prompt_tokens' => 2, 'completion_tokens' => 3 ) ) ) ) );
		$relay_chat_complete_response = rest_do_request( $relay_chat_complete );
		alorbach_require( 200 === $relay_chat_complete_response->get_status() && 'Cursor relay reply' === ( $relay_chat_complete_response->get_data()['choices'][0]['message']['content'] ?? '' ) && ! empty( $relay_chat_complete_response->get_data()['ai_bridge'] ), 'Relay chat completion must preserve OpenAI-compatible choices and usage.' );
		alorbach_require( 200 !== rest_do_request( $relay_chat_complete )->get_status(), 'A completed relay job must reject duplicate completion.' );

		$relay_image_create = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs' );
		alorbach_set_json_body( $relay_image_create, array( 'type' => 'image', 'payload' => array( 'model' => 'model-relay:grok-cli:image', 'prompt' => 'Grok image contract ' . $local_codex_verify_run_id, 'reference_images' => array( 'data:image/png;base64,' . base64_encode( 'reference' ), array( 'b64_json' => base64_encode( 'structured reference' ), 'mime_type' => 'image/png', 'label' => 'identity' ) ) ) ) );
		$relay_image_response = rest_do_request( $relay_image_create );
		$relay_image_data = $relay_image_response->get_data();
		alorbach_require( 200 === $relay_image_response->get_status(), 'Canonical AI Bridge jobs must sign Grok reference-image requests.' );
		$relay_image_complete = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs/' . rawurlencode( (string) ( $relay_image_data['job_id'] ?? '' ) ) . '/complete' );
		alorbach_set_json_body( $relay_image_complete, array( 'job_token' => (string) ( $relay_image_data['job_token'] ?? '' ), 'request_hash' => (string) ( $relay_image_data['request_hash'] ?? '' ), 'result' => array( 'response' => array( 'data' => array( array( 'b64_json' => base64_encode( 'image contract' ) ) ) ) ) ) );
		$relay_image_complete_response = rest_do_request( $relay_image_complete );
		alorbach_require( 200 === $relay_image_complete_response->get_status() && ! empty( $relay_image_complete_response->get_data()['data'][0]['b64_json'] ), 'Relay image completion must preserve validated base64 image entries.' );

		$cross_capability_create = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs' );
		alorbach_set_json_body( $cross_capability_create, array( 'type' => 'image', 'payload' => array( 'model' => 'model-relay:cursor-cli:auto', 'prompt' => 'Reject cross capability ' . $local_codex_verify_run_id ) ) );
		$cross_capability_response = rest_do_request( $cross_capability_create );
		alorbach_require( 400 === $cross_capability_response->get_status() && 'invalid_local_codex_model' === ( $cross_capability_response->get_data()['code'] ?? '' ), 'Dynamic relay models must not cross capability boundaries.' );

		$malformed_relay_create = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs' );
		alorbach_set_json_body( $malformed_relay_create, array( 'type' => 'chat', 'payload' => array( 'model' => 'model-relay:custom:unsafe/model', 'messages' => array( array( 'role' => 'user', 'content' => 'Reject malformed model id' ) ) ) ) );
		$malformed_relay_response = rest_do_request( $malformed_relay_create );
		alorbach_require( 400 === $malformed_relay_response->get_status(), 'Malformed dynamic relay model IDs must be rejected.' );

		$future_relay_create = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs' );
		alorbach_set_json_body( $future_relay_create, array( 'type' => 'chat', 'payload' => array( 'model' => 'model-relay:future-backend:model.1', 'messages' => array( array( 'role' => 'user', 'content' => 'Allow future relay backend' ) ) ) ) );
		$future_relay_response = rest_do_request( $future_relay_create );
		alorbach_require( 200 === $future_relay_response->get_status(), 'Safely formed dynamic relay IDs must be accepted for the paired relay to enforce capability support.' );

		$video_create = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs' );
		alorbach_set_json_body( $video_create, array( 'type' => 'video', 'payload' => array( 'model' => 'model-relay:grok-cli:video', 'prompt' => 'Experimental relay video ' . $local_codex_verify_run_id, 'input_reference' => array( 'b64_json' => base64_encode( 'reference' ), 'mime_type' => 'image/png' ) ) ) );
		$video_create_response = rest_do_request( $video_create );
		$video_create_data = $video_create_response->get_data();
		alorbach_require( 200 === $video_create_response->get_status(), 'Canonical AI Bridge jobs must sign Grok experimental video requests.' );
		$video_complete = new WP_REST_Request( 'POST', '/alorbach/v1/ai-bridge/jobs/' . rawurlencode( (string) ( $video_create_data['job_id'] ?? '' ) ) . '/complete' );
		alorbach_set_json_body( $video_complete, array( 'job_token' => (string) ( $video_create_data['job_token'] ?? '' ), 'request_hash' => (string) ( $video_create_data['request_hash'] ?? '' ), 'result' => array( 'response' => array( 'b64_video' => base64_encode( 'video contract' ), 'mime_type' => 'video/mp4', 'experimental' => true ) ) ) );
		$video_complete_response = rest_do_request( $video_complete );
		$video_complete_data = $video_complete_response->get_data();
		alorbach_require( 200 === $video_complete_response->get_status() && ! empty( $video_complete_data['ai_bridge'] ) && ! empty( $video_complete_data['local_codex'] ) && ! empty( $video_complete_data['experimental'] ) && 'video/mp4' === ( $video_complete_data['data'][0]['mime_type'] ?? '' ), 'Grok video completion must retain experimental metadata and both compatibility markers.' );

		$wildcard_plan = array( 'capabilities' => array( 'chat' => true ), 'allowed_models' => array( 'chat' => array( 'model-relay:*' ) ) );
		$restricted_plan = array( 'capabilities' => array( 'chat' => true ), 'allowed_models' => array( 'chat' => array( 'codex-local:auto' ) ) );
		alorbach_require( \Alorbach\AIGateway\Integration_Service::plan_allows_capability( $wildcard_plan, 'chat', 'model-relay:grok-cli:auto' ), 'model-relay:* must allow relay models for its capability.' );
		alorbach_require( ! \Alorbach\AIGateway\Integration_Service::plan_allows_capability( $restricted_plan, 'chat', 'model-relay:grok-cli:auto' ), 'Existing exact Local Codex allowlists must not gain relay access automatically.' );
		alorbach_require( 'ai_bridge' === \Alorbach\AIGateway\API_Client::get_provider_for_model( 'model-relay:cursor-cli:auto' ), 'Relay models must resolve to the canonical ai_bridge provider.' );

		$local_audio_create = new WP_REST_Request( 'POST', '/alorbach/v1/local-codex/jobs' );
		alorbach_set_json_body(
			$local_audio_create,
			array(
				'type'    => 'transcribe',
				'payload' => array(
					'model'            => 'codex-local:audio:whisper-large-v3',
					'audio_base64'     => base64_encode( 'verify audio' ),
					'audio_format'     => 'mp3',
					'duration_seconds' => 3,
					'prompt'           => 'Return word timing. Verify run ' . $local_codex_verify_run_id,
				),
			)
		);
		$local_audio_create_response = rest_do_request( $local_audio_create );
		$local_audio_create_data = $local_audio_create_response->get_data();
		alorbach_require( 200 === $local_audio_create_response->get_status() && 'transcribe' === ( $local_audio_create_data['type'] ?? '' ) && 'codex-local:audio:whisper-large-v3' === ( $local_audio_create_data['payload']['model'] ?? '' ), 'Local Codex signed jobs must allow transcribe type and preserve explicit Whisper submodels.' );

		$local_future_audio_create = new WP_REST_Request( 'POST', '/alorbach/v1/local-codex/jobs' );
		alorbach_set_json_body(
			$local_future_audio_create,
			array(
				'type'    => 'transcribe',
				'payload' => array(
					'model'            => 'codex-local:audio:verify-future-asr.1',
					'audio_base64'     => base64_encode( 'verify future audio' ),
					'audio_format'     => 'mp3',
					'duration_seconds' => 3,
					'prompt'           => 'Return word timing. Verify run ' . $local_codex_verify_run_id,
				),
			)
		);
		$local_future_audio_create_response = rest_do_request( $local_future_audio_create );
		$local_future_audio_create_data     = $local_future_audio_create_response->get_data();
		alorbach_require( 200 === $local_future_audio_create_response->get_status() && 'codex-local:audio:verify-future-asr.1' === ( $local_future_audio_create_data['payload']['model'] ?? '' ), 'Local Codex signed jobs must allow future browser bridge ASR submodels.' );

		$local_audio_complete = new WP_REST_Request( 'POST', '/alorbach/v1/local-codex/jobs/' . rawurlencode( (string) ( $local_audio_create_data['job_id'] ?? '' ) ) . '/complete' );
		alorbach_set_json_body(
			$local_audio_complete,
			array(
				'job_token'    => (string) ( $local_audio_create_data['job_token'] ?? '' ),
				'request_hash' => (string) ( $local_audio_create_data['request_hash'] ?? '' ),
				'result'       => array(
					'success'  => true,
					'response' => array(
						'text'  => 'Forbidden heaven',
						'words' => array(
							array( 'word' => 'Forbidden', 'start' => 1.25, 'end' => 1.75 ),
							array( 'word' => 'heaven', 'start' => 1.75, 'end' => 2.4 ),
						),
					),
				),
			)
		);
		$local_audio_complete_response = rest_do_request( $local_audio_complete );
		$local_audio_complete_data = $local_audio_complete_response->get_data();
		alorbach_require( 200 === $local_audio_complete_response->get_status() && 'codex-local:audio:whisper-large-v3' === ( $local_audio_complete_data['model'] ?? '' ) && ! empty( $local_audio_complete_data['local_codex'] ) && 'Forbidden' === ( $local_audio_complete_data['words'][0]['word'] ?? '' ), 'Local Codex transcribe completion must normalize word timing output.' );
		global $wpdb;
		$local_audio_ledger_type = $wpdb->get_var( $wpdb->prepare( 'SELECT transaction_type FROM ' . \Alorbach\AIGateway\Ledger::get_table_name() . ' WHERE request_signature = %s', (string) ( $local_audio_create_data['request_hash'] ?? '' ) ) );
		alorbach_require( 'audio_deduction' === $local_audio_ledger_type, 'Local Codex transcribe completion must record an audio deduction ledger row.' );

		$local_audio_unknown = new WP_REST_Request( 'POST', '/alorbach/v1/local-codex/jobs' );
		alorbach_set_json_body(
			$local_audio_unknown,
			array(
				'type'    => 'transcribe',
				'payload' => array(
					'model'            => 'codex-local:audio:../not-real',
					'audio_base64'     => base64_encode( 'verify audio unknown' ),
					'audio_format'     => 'mp3',
					'duration_seconds' => 3,
					'prompt'           => 'Return word timing. Verify unknown run ' . $local_codex_verify_run_id,
				),
			)
		);
		$local_audio_unknown_response = rest_do_request( $local_audio_unknown );
		alorbach_require( 400 === $local_audio_unknown_response->get_status() && 'invalid_local_codex_model' === ( $local_audio_unknown_response->get_data()['code'] ?? '' ), 'Local Codex transcribe jobs must reject malformed future ASR submodels.' );

		$local_audio_invalid = new WP_REST_Request( 'POST', '/alorbach/v1/local-codex/jobs' );
		alorbach_set_json_body(
			$local_audio_invalid,
			array(
				'type'    => 'transcribe',
				'payload' => array(
					'model'            => 'codex-local:audio',
					'audio_base64'     => base64_encode( 'verify audio invalid' ),
					'audio_format'     => 'mp3',
					'duration_seconds' => 3,
					'prompt'           => 'Return word timing. Verify invalid run ' . $local_codex_verify_run_id,
				),
			)
		);
		$local_audio_invalid_create = rest_do_request( $local_audio_invalid )->get_data();
		$local_audio_invalid_complete = new WP_REST_Request( 'POST', '/alorbach/v1/local-codex/jobs/' . rawurlencode( (string) ( $local_audio_invalid_create['job_id'] ?? '' ) ) . '/complete' );
		alorbach_set_json_body(
			$local_audio_invalid_complete,
			array(
				'job_token'    => (string) ( $local_audio_invalid_create['job_token'] ?? '' ),
				'request_hash' => (string) ( $local_audio_invalid_create['request_hash'] ?? '' ),
				'result'       => array(
					'success'  => true,
					'response' => array( 'text' => 'plain transcript only' ),
				),
			)
		);
		$local_audio_invalid_response = rest_do_request( $local_audio_invalid_complete );
		alorbach_require( 400 === $local_audio_invalid_response->get_status(), 'Local Codex transcribe completion must reject missing word timing.' );

		\Alorbach\AIGateway\AI_Bridge::update_setting( 'enabled', false );
		$local_codex_disabled_verify = \Alorbach\AIGateway\API_Validator::verify_key( 'codex_local' );
		alorbach_require( empty( $local_codex_disabled_verify['success'] ) && false !== strpos( (string) ( $local_codex_disabled_verify['message'] ?? '' ), 'disabled' ), 'Local Codex validation must report disabled state clearly.' );
	} finally {
		delete_option( 'alorbach_ai_bridge_contract_probe' );
		delete_option( 'alorbach_local_codex_contract_probe' );
		update_option( 'alorbach_local_codex_enabled', $old_local_codex_enabled, false );
		update_option( 'alorbach_local_codex_audio_fee_uc', $old_local_codex_audio_fee_uc, false );
		if ( '__alorbach_missing__' === $old_ai_bridge_enabled ) {
			delete_option( 'alorbach_ai_bridge_enabled' );
		} else {
			update_option( 'alorbach_ai_bridge_enabled', $old_ai_bridge_enabled, false );
		}
		if ( '__alorbach_missing__' === $old_ai_bridge_audio_fee_uc ) {
			delete_option( 'alorbach_ai_bridge_audio_fee_uc' );
		} else {
			update_option( 'alorbach_ai_bridge_audio_fee_uc', $old_ai_bridge_audio_fee_uc, false );
		}
		if ( '__alorbach_missing__' === $old_plans ) {
			delete_option( 'alorbach_plans' );
		} else {
			update_option( 'alorbach_plans', $old_plans, false );
		}
		if ( '__alorbach_missing__' === $old_api_keys ) {
			delete_option( 'alorbach_api_keys' );
		} else {
			update_option( 'alorbach_api_keys', $old_api_keys, false );
		}
	}

	$azure_provider = \Alorbach\AIGateway\Providers\Provider_Registry::get( 'azure' );
	alorbach_require( $azure_provider instanceof \Alorbach\AIGateway\Providers\Azure_Provider, 'Azure provider must be registered.' );
	alorbach_require( 'azure' === \Alorbach\AIGateway\API_Client::get_provider_for_model( 'azure-speech' ), 'azure-speech must route through the existing Azure provider.' );
	alorbach_require( 'azure' === \Alorbach\AIGateway\API_Client::get_provider_for_model( 'speech' ), 'speech alias must route through the existing Azure provider.' );
	$original_api_keys = get_option( 'alorbach_api_keys', array() );
	\Alorbach\AIGateway\API_Keys_Helper::save_entries(
		array(
			array(
				'id'      => 'verify-openai-azure',
				'type'    => 'azure',
				'api_key' => 'azure-openai-key',
				'endpoint' => 'https://example.openai.azure.com',
				'enabled' => true,
			),
			array(
				'id'                    => 'verify-speech-azure',
				'type'                  => 'azure',
				'api_key'               => 'speech-secret',
				'speech_endpoint'       => 'https://westeurope.api.cognitive.microsoft.com',
				'speech_api_version'   => '2024-11-15',
				'speech_default_locale' => 'en-US',
				'enabled'               => true,
			),
		)
	);
	$speech_credentials = \Alorbach\AIGateway\API_Keys_Helper::get_azure_speech_credentials();
	update_option( 'alorbach_api_keys', $original_api_keys );
	alorbach_require( is_array( $speech_credentials ), 'Azure Speech credentials must be selected from a Speech-capable Azure entry.' );
	alorbach_require( 'https://westeurope.api.cognitive.microsoft.com' === ( $speech_credentials['speech_endpoint'] ?? '' ), 'Azure Speech credential selection must skip Azure OpenAI-only entries.' );
	alorbach_require( 'speech-secret' === ( $speech_credentials['api_key'] ?? '' ), 'Azure Speech credentials must use the shared Azure API key.' );

	$verify_urls = array();
	$verify_http = static function ( $preempt, $args, $url ) use ( &$verify_urls ) {
		$verify_urls[] = array(
			'url'     => $url,
			'headers' => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(),
		);
		if ( false !== strpos( $url, 'example.openai.azure.com/openai/models' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'data' => array() ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		}
		if ( false !== strpos( $url, 'example.cognitiveservices.azure.com/speechtotext/models/base?api-version=2024-11-15' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'values' => array() ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		}
		return $preempt;
	};
	add_filter( 'pre_http_request', $verify_http, 10, 3 );
	$verify_result = $azure_provider->verify_key(
		array(
			'api_key'             => 'shared-secret',
			'endpoint'            => 'https://example.openai.azure.com',
			'speech_endpoint'     => 'https://example.cognitiveservices.azure.com',
			'speech_api_version'  => '2024-11-15',
		)
	);
	remove_filter( 'pre_http_request', $verify_http, 10 );
	alorbach_require( ! empty( $verify_result['success'] ), 'Azure verify_key must validate a configured Cognitive Services Speech endpoint.' );
	alorbach_require( isset( $verify_result['checks']['azure_openai'] ), 'Azure verify_key must return a separate Azure OpenAI check result.' );
	alorbach_require( isset( $verify_result['checks']['azure_speech'] ), 'Azure verify_key must return a separate Azure Speech check result.' );
	alorbach_require( ! empty( $verify_result['checks']['azure_openai']['success'] ), 'Azure OpenAI check result must pass independently.' );
	alorbach_require( ! empty( $verify_result['checks']['azure_speech']['success'] ), 'Azure Speech check result must pass independently.' );
	$speech_verify_hit = false;
	foreach ( $verify_urls as $hit ) {
		if ( false !== strpos( $hit['url'], 'example.cognitiveservices.azure.com/speechtotext/models/base?api-version=2024-11-15' ) ) {
			$speech_verify_hit = true;
			alorbach_require( 'shared-secret' === ( $hit['headers']['Ocp-Apim-Subscription-Key'] ?? '' ), 'Azure Speech verification must use the shared Azure API key.' );
		}
	}
	alorbach_require( $speech_verify_hit, 'Azure verify_key must call the Cognitive Services Speech verification endpoint.' );

	$tmp_audio = wp_tempnam( 'alorbach-verify-speech-' );
	if ( ! $tmp_audio ) {
		throw new RuntimeException( 'Could not create temporary audio fixture.' );
	}
	file_put_contents( $tmp_audio, "RIFF\x24\x00\x00\x00WAVEfmt " . str_repeat( "\0", 64 ) );
	$speech_request = $azure_provider->build_transcribe_request(
		$tmp_audio,
		'azure-speech',
		'ignored prompt',
		array(
			'api_key'               => 'test-secret',
			'speech_endpoint'       => 'https://westeurope.api.cognitive.microsoft.com',
			'speech_api_version'   => '2024-11-15',
			'speech_default_locale' => 'en-US',
		),
		'wav',
		array( 'locale' => 'de-DE' )
	);
	wp_delete_file( $tmp_audio );
	alorbach_require( ! is_wp_error( $speech_request ), 'Azure Speech request should build with speech credentials.' );
	alorbach_require( false !== strpos( $speech_request['url'], '/speechtotext/transcriptions:transcribe?api-version=2024-11-15' ), 'Azure Speech request must target the Speech transcription endpoint.' );
	alorbach_require( isset( $speech_request['headers']['Ocp-Apim-Subscription-Key'] ), 'Azure Speech request must use Ocp-Apim-Subscription-Key.' );
	alorbach_require( false !== strpos( $speech_request['body'], '"wordLevelTimestampsEnabled":true' ), 'Azure Speech definition must request word-level timestamps.' );
	alorbach_require( false !== strpos( $speech_request['body'], '"locales":["de-DE"]' ), 'Azure Speech definition must use the request locale.' );

	$parsed_speech = $azure_provider->parse_transcribe_response(
		array(
			'durationMilliseconds' => 2000,
			'combinedPhrases'      => array( array( 'text' => 'Hello world' ) ),
			'phrases'              => array(
				array(
					'offsetMilliseconds'   => 250,
					'durationMilliseconds' => 1000,
					'text'                 => 'Hello world',
					'words'                => array(
						array( 'text' => 'Hello', 'offsetMilliseconds' => 250, 'durationMilliseconds' => 300 ),
						array( 'text' => 'world', 'offsetMilliseconds' => 650, 'durationMilliseconds' => 350 ),
					),
				),
			),
		),
		'',
		$speech_request
	);
	alorbach_require( 'Hello world' === $parsed_speech['text'], 'Azure Speech parser must use combined phrase text.' );
	alorbach_require( 2 === count( $parsed_speech['words'] ), 'Azure Speech parser must return word timestamps.' );
	alorbach_require( abs( $parsed_speech['words'][0]['start'] - 0.25 ) < 0.0001, 'Azure Speech word offsets must be converted to seconds.' );
	alorbach_require( abs( $parsed_speech['words'][0]['end'] - 0.55 ) < 0.0001, 'Azure Speech word durations must be converted to seconds.' );
	alorbach_require( 1 === count( $parsed_speech['segments'] ), 'Azure Speech parser must return phrase segments.' );
	alorbach_require( 2 === (int) $parsed_speech['provider_details']['word_count'], 'Azure Speech debug metadata must include word count.' );

	\Alorbach\AIGateway\API_Keys_Helper::save_entries(
		array(
			array(
				'id'                    => 'verify-speech-raw-response',
				'type'                  => 'azure',
				'api_key'               => 'speech-secret',
				'speech_endpoint'       => 'https://westeurope.api.cognitive.microsoft.com',
				'speech_api_version'   => '2024-11-15',
				'speech_default_locale' => 'en-US',
				'enabled'               => true,
			),
		)
	);
	$raw_speech_body = wp_json_encode(
		array(
			'durationMilliseconds' => 1000,
			'combinedPhrases'      => array( array( 'text' => 'Raw timing' ) ),
			'phrases'              => array(
				array(
					'offsetMilliseconds'   => 100,
					'durationMilliseconds' => 500,
					'text'                 => 'Raw timing',
					'words'                => array(
						array( 'text' => 'Raw', 'offsetMilliseconds' => 100, 'durationMilliseconds' => 200 ),
						array( 'text' => 'timing', 'offsetMilliseconds' => 350, 'durationMilliseconds' => 250 ),
					),
				),
			),
		)
	);
	$transcribe_http = static function ( $preempt, $args, $url ) use ( $raw_speech_body ) {
		if ( false !== strpos( $url, '/speechtotext/transcriptions:transcribe?api-version=2024-11-15' ) ) {
			return array(
				'headers'  => array(),
				'body'     => $raw_speech_body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		}
		return $preempt;
	};
	$tmp_audio = wp_tempnam( 'alorbach-verify-speech-raw-' );
	if ( ! $tmp_audio ) {
		throw new RuntimeException( 'Could not create temporary Azure Speech raw-response fixture.' );
	}
	file_put_contents( $tmp_audio, "RIFF\x24\x00\x00\x00WAVEfmt " . str_repeat( "\0", 64 ) );
	add_filter( 'pre_http_request', $transcribe_http, 10, 3 );
	$transcribed_speech = \Alorbach\AIGateway\API_Client::transcribe( $tmp_audio, 'azure-speech', '', 'wav', array( 'locale' => 'en-US' ) );
	remove_filter( 'pre_http_request', $transcribe_http, 10 );
	wp_delete_file( $tmp_audio );
	update_option( 'alorbach_api_keys', $original_api_keys );
	alorbach_require( ! is_wp_error( $transcribed_speech ), 'Azure Speech transcription should return the mocked response.' );
	alorbach_require( 'Raw timing' === $transcribed_speech['text'], 'Azure Speech transcription should still return normalized text.' );
	alorbach_require( isset( $transcribed_speech['provider_details']['raw_provider_response_body'] ), 'Azure Speech queue diagnostics must include the raw provider response body.' );
	alorbach_require( $raw_speech_body === $transcribed_speech['provider_details']['raw_provider_response_body'], 'Azure Speech raw provider response must be preserved exactly for queue comparison.' );
	alorbach_require( strlen( $raw_speech_body ) === (int) $transcribed_speech['provider_details']['raw_provider_response_bytes'], 'Azure Speech raw provider response byte count must be recorded.' );

	$openai_provider = \Alorbach\AIGateway\Providers\Provider_Registry::get( 'openai' );
	$tmp_audio = wp_tempnam( 'alorbach-verify-openai-audio-' );
	if ( ! $tmp_audio ) {
		throw new RuntimeException( 'Could not create temporary OpenAI audio fixture.' );
	}
	file_put_contents( $tmp_audio, "RIFF\x24\x00\x00\x00WAVEfmt " . str_repeat( "\0", 128 ) );
	$openai_request = $openai_provider->build_transcribe_request( $tmp_audio, 'whisper-1', '', array( 'api_key' => 'test-secret' ), 'wav' );
	wp_delete_file( $tmp_audio );
	alorbach_require( ! is_wp_error( $openai_request ), 'OpenAI transcription request should still build.' );
	alorbach_require( false !== strpos( $openai_request['url'], '/audio/transcriptions' ), 'OpenAI transcription endpoint must remain unchanged.' );
	alorbach_require( false !== strpos( $openai_request['body'], 'timestamp_granularities[]' ), 'OpenAI transcription must still request timestamp granularities.' );

	$unfiltered_config = \Alorbach\AIGateway\Integration_Service::get_integration_config( 0 );
	alorbach_require( in_array( 'azure-speech', $unfiltered_config['capabilities']['audio_models'] ?? array(), true ), '/integration/config must expose azure-speech in the audio catalog.' );
	alorbach_require( in_array( 'codex-local:audio', $unfiltered_config['capabilities']['audio_models'] ?? array(), true ), '/integration/config must expose codex-local:audio in the audio catalog.' );
	alorbach_require( in_array( 'codex-local:audio:whisper-small', $unfiltered_config['capabilities']['audio_models'] ?? array(), true ), '/integration/config must expose Local Whisper submodels in the unfiltered audio catalog.' );

	echo "REST contract verification passed.\n";
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
