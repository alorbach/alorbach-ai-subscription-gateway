<?php
/**
 * Base provider with shared logic.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class Provider_Base
 */
abstract class Provider_Base implements Provider_Interface {

	/**
	 * Models that require max_completion_tokens instead of max_tokens.
	 *
	 * @var array
	 */
	protected static $max_completion_tokens_models = array(
		'gpt-4o', 'gpt-4.1', 'gpt-5', 'o1', 'o3', 'o4', 'sora',
	);

	/**
	 * Normalize chat body: use max_completion_tokens for newer models.
	 *
	 * @param array  $body   Request body.
	 * @param string $model  Model ID.
	 * @return array Modified body.
	 */
	protected static function normalize_chat_body( $body, $model ) {
		if ( ! isset( $body['max_tokens'] ) ) {
			return $body;
		}
		$val       = $body['max_tokens'];
		$model_id  = strpos( $model, '/' ) !== false ? substr( $model, strrpos( $model, '/' ) + 1 ) : $model;
		$use_new   = false;
		foreach ( self::$max_completion_tokens_models as $prefix ) {
			if ( strpos( $model_id, $prefix ) === 0 || strpos( $model, $prefix ) === 0 ) {
				$use_new = true;
				break;
			}
		}
		if ( $use_new ) {
			unset( $body['max_tokens'] );
			$body['max_completion_tokens'] = $val;
		}
		return $body;
	}

	/**
	 * Default: no images support.
	 *
	 * @return null
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		return null;
	}

	/**
	 * Default: no transcribe support.
	 *
	 * @return null
	 */
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials, $format = null ) {
		return null;
	}

	/**
	 * Default: no video support.
	 *
	 * @param string $prompt           Prompt.
	 * @param string $model            Model (e.g. sora-2).
	 * @param string $size             Size (e.g. 1280x720).
	 * @param int    $duration_seconds Duration in seconds.
	 * @param array  $credentials      Credentials.
	 * @return null
	 */
	public function build_video_request( $prompt, $model, $size, $duration_seconds, $credentials ) {
		return null;
	}

	/**
	 * Default: no images.
	 *
	 * @return bool
	 */
	public function supports_images() {
		return false;
	}

	/**
	 * Default: no audio.
	 *
	 * @return bool
	 */
	public function supports_audio() {
		return false;
	}

	/**
	 * Default: no video.
	 *
	 * @return bool
	 */
	public function supports_video() {
		return false;
	}

	/**
	 * Classify OpenAI-style model ID into type.
	 *
	 * @param string $model_id Model ID.
	 * @return string text, image, video, audio.
	 */
	protected static function classify_openai_model( $model_id ) {
		// Image generation: gpt-image-*, dall-e-*, FLUX* (Azure/Foundry), imagen-*, gemini*-image*
		if ( strpos( $model_id, 'gpt-image' ) === 0 || strpos( $model_id, 'dall-e' ) === 0 ) {
			return 'image';
		}
		if ( strpos( strtolower( $model_id ), 'flux' ) === 0 ) {
			return 'image';
		}
		if ( strpos( strtolower( $model_id ), 'sora' ) === 0 ) {
			return 'video';
		}
		if ( strpos( $model_id, 'whisper' ) === 0 ) {
			return 'audio';
		}
		if ( strpos( $model_id, 'gpt-audio' ) === 0 ) {
			return 'audio';
		}
		if ( strpos( $model_id, '-transcribe' ) !== false || strpos( $model_id, '-tts' ) !== false ) {
			return 'audio';
		}
		if ( strpos( $model_id, 'realtime' ) !== false ) {
			return 'audio';
		}
		return 'text';
	}

	/**
	 * Infer capabilities for OpenAI-style text model.
	 *
	 * @param string $model_id Model ID.
	 * @return array Capability keys.
	 */
	protected static function infer_openai_capabilities( $model_id ) {
		$caps   = array( 'text_to_text' );
		$vision = array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'o1-mini', 'o4-mini' );
		foreach ( $vision as $v ) {
			if ( $model_id === $v || strpos( $model_id, $v . '-' ) === 0 ) {
				$caps[] = 'image_to_text';
				return $caps;
			}
		}
		if ( preg_match( '/^gpt-4[o1.]|^gpt-5|^o1-mini|^o4-mini/', $model_id ) ) {
			$caps[] = 'image_to_text';
		}
		return $caps;
	}

	/**
	 * Check if model ID matches any prefix.
	 *
	 * @param string   $model_id Model ID.
	 * @param string[] $prefixes Prefixes.
	 * @return bool
	 */
	protected static function matches_prefix( $model_id, $prefixes ) {
		foreach ( $prefixes as $p ) {
			if ( strpos( $model_id, $p ) === 0 ) {
				return true;
			}
		}
		return empty( $prefixes );
	}

	/**
	 * Perform a verified GET request and return a success/failure result array.
	 *
	 * Checks for WP_Error, HTTP 4xx/5xx, and extracts an error message from
	 * common response structures (body['error']['message'] or body['message']).
	 *
	 * @param string $url            Request URL.
	 * @param array  $headers        HTTP headers.
	 * @param string $fallback_error Fallback error message if none found in body.
	 * @return array{success: bool, message?: string}
	 */
	protected static function make_verified_get( $url, $headers = array(), $fallback_error = '' ) {
		$response = wp_remote_get( $url, array( 'headers' => $headers, 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message']
				: ( isset( $body['message'] ) ? $body['message'] : $fallback_error );
			return array( 'success' => false, 'message' => $msg );
		}
		return array( 'success' => true );
	}
}
