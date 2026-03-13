<?php
/**
 * API validation for keys and model endpoints.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Validator
 */
class API_Validator {

	/**
	 * Verify OpenAI API key via GET /v1/models.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_openai_key() {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'message' => __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}

		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid API key.', 'alorbach-ai-gateway' );
			return array( 'success' => false, 'message' => $msg );
		}
		return array( 'success' => true );
	}

	/**
	 * Verify Azure OpenAI key via GET models.
	 * Supports both traditional (*.openai.azure.com) and Foundry (*.services.ai.azure.com) endpoints.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_azure_key() {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$endpoint = isset( $keys['azure_endpoint'] ) ? trim( $keys['azure_endpoint'] ) : '';
		$api_key  = isset( $keys['azure'] ) ? $keys['azure'] : '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return array( 'success' => false, 'message' => __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		$endpoint = rtrim( $endpoint, '/' );
		if ( strpos( $endpoint, '.azure.com' ) === false ) {
			return array( 'success' => false, 'message' => __( 'Endpoint must end with .azure.com (e.g. https://xxx.services.ai.azure.com)', 'alorbach-ai-gateway' ) );
		}

		$is_foundry = ( strpos( $endpoint, 'services.ai.azure.com' ) !== false );
		$urls       = $is_foundry
			? array(
				$endpoint . '/openai/v1/models',
				$endpoint . '/openai/models?api-version=2024-10-21',
			)
			: array( $endpoint . '/openai/models?api-version=2024-10-21' );

		$last_error = '';
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array( 'api-key' => $api_key ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 400 ) {
				return array( 'success' => true );
			}
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$last_error = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid Azure configuration.', 'alorbach-ai-gateway' );
		}
		return array( 'success' => false, 'message' => $last_error );
	}

	/**
	 * Verify Google API key via GET /v1beta/models.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_google_key() {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$api_key = isset( $keys['google'] ) ? $keys['google'] : '';
		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'message' => __( 'Google API key not configured.', 'alorbach-ai-gateway' ) );
		}

		$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid API key.', 'alorbach-ai-gateway' );
			return array( 'success' => false, 'message' => $msg );
		}
		return array( 'success' => true );
	}

	/**
	 * Verify text model via minimal chat completion.
	 *
	 * @param string $provider openai, azure, google.
	 * @param string $model    Model ID.
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_text_model( $provider, $model ) {
		if ( strpos( strtolower( $model ), 'sora' ) === 0 ) {
			return array( 'success' => false, 'message' => __( 'Sora is a video model. Use video generation to verify; the Test button uses the chat API.', 'alorbach-ai-gateway' ) );
		}
		$body = array(
			'model'       => $model,
			'messages'    => array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			'max_tokens'  => 16,
		);
		$response = API_Client::chat( $provider, $body );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$content = isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '';
		return array( 'success' => true, 'result' => $content );
	}

	/**
	 * Verify image model via minimal DALL-E generation.
	 *
	 * @param string $size Image size (e.g. 1024x1024).
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_image_model( $size ) {
		$response = API_Client::images( 'test', $size, 1 );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$img = '';
		if ( isset( $response['data'][0] ) ) {
			$d = $response['data'][0];
			if ( ! empty( $d['b64_json'] ) ) {
				$img = 'data:image/png;base64,' . $d['b64_json'];
			} elseif ( ! empty( $d['url'] ) ) {
				$img = $d['url'];
			}
		}
		return array( 'success' => true, 'result' => $img );
	}

	/**
	 * Verify audio model via minimal Whisper transcription.
	 *
	 * @param string $model Model (e.g. whisper-1, gpt-4o-transcribe).
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_audio_model( $model = 'whisper-1' ) {
		$tmp = self::create_minimal_wav();
		if ( ! $tmp ) {
			return array( 'success' => false, 'message' => __( 'Could not create test audio.', 'alorbach-ai-gateway' ) );
		}
		$response = API_Client::transcribe( $tmp, $model );
		@unlink( $tmp );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$text = isset( $response['text'] ) ? $response['text'] : '';
		return array( 'success' => true, 'result' => $text );
	}

	/**
	 * Create minimal 1-second silent WAV file.
	 *
	 * @return string|false Temp file path or false.
	 */
	private static function create_minimal_wav() {
		$sample_rate = 8000;
		$duration    = 1;
		$num_samples = $sample_rate * $duration;
		$data_size   = $num_samples * 2; // 16-bit mono
		$file_size   = 36 + $data_size;

		$header  = pack( 'A4V', 'RIFF', $file_size - 8 );
		$header .= pack( 'A4', 'WAVE' );
		$header .= pack( 'A4VvvVVvv', 'fmt ', 16, 1, 1, $sample_rate, $sample_rate * 2, 2, 16 );
		$header .= pack( 'A4V', 'data', $data_size );
		$samples = str_repeat( "\x00\x00", $num_samples );
		$wav     = $header . $samples;

		$tmp = wp_tempnam( 'alorbach-wav-' );
		if ( $tmp && false !== file_put_contents( $tmp, $wav ) ) {
			return $tmp;
		}
		if ( $tmp ) {
			@unlink( $tmp );
		}
		return false;
	}
}
