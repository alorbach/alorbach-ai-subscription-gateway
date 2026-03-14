<?php
/**
 * API validation for keys and model endpoints.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

use Alorbach\AIGateway\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Validator
 */
class API_Validator {

	/**
	 * Verify API key for a provider.
	 *
	 * @param string $provider openai, azure, google, github_models.
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_key( $provider ) {
		$prov = Provider_Registry::get( $provider );
		if ( ! $prov ) {
			return array( 'success' => false, 'message' => __( 'Unknown provider.', 'alorbach-ai-gateway' ) );
		}
		$creds = API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return array( 'success' => false, 'message' => __( 'API key not configured for this provider.', 'alorbach-ai-gateway' ) );
		}
		return $prov->verify_key( $creds );
	}

	/**
	 * Verify OpenAI API key (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_openai_key() {
		return self::verify_key( 'openai' );
	}

	/**
	 * Verify Azure OpenAI key (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_azure_key() {
		return self::verify_key( 'azure' );
	}

	/**
	 * Verify Google API key (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_google_key() {
		return self::verify_key( 'google' );
	}

	/**
	 * Verify GitHub Models token (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_github_models_key() {
		return self::verify_key( 'github_models' );
	}

	/**
	 * Verify text model via minimal chat completion.
	 *
	 * @param string $provider  openai, azure, google, github_models.
	 * @param string $model     Model ID.
	 * @param string $entry_id  Optional. When set, use credentials for this specific entry.
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_text_model( $provider, $model, $entry_id = '' ) {
		if ( strpos( strtolower( $model ), 'sora' ) === 0 ) {
			return array( 'success' => false, 'message' => __( 'Sora is a video model. Use video generation to verify; the Test button uses the chat API.', 'alorbach-ai-gateway' ) );
		}
		$body = array(
			'model'      => $model,
			'messages'   => array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			'max_tokens' => 16,
		);
		$response = API_Client::chat( $provider, $body, $entry_id );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$content = isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '';
		return array( 'success' => true, 'result' => $content );
	}

	/**
	 * Verify image model via minimal image generation.
	 *
	 * @param string $size  Image size (e.g. 1024x1024).
	 * @param string $model Optional. Model ID (e.g. dall-e-3, gpt-image-1.5, imagen-4.0-generate-001). Uses default if empty.
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_image_model( $size, $model = '' ) {
		$model = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$response = API_Client::images( 'test', $size, 1, $model );
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
	 * @return array{success: bool, message?: string, result?: string}
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
	 * Verify video model via quick create (submits job, returns when API accepts).
	 * Does not wait for video completion (which can take minutes).
	 *
	 * @param string $model Model ID (e.g. sora-2).
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_video_model( $model = 'sora-2' ) {
		$response = API_Client::create_video( 'A simple test', $model );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$video_id = isset( $response['id'] ) ? $response['id'] : '';
		return array( 'success' => true, 'result' => sprintf( __( 'Video job created (ID: %s). Generation may take 2–5 minutes.', 'alorbach-ai-gateway' ), $video_id ) );
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
		$data_size   = $num_samples * 2;
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
