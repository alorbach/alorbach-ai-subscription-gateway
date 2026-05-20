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

	echo "REST contract verification passed.\n";
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
