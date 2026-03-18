<?php
/**
 * Google (Gemini) API provider.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Google_Provider
 */
class Google_Provider extends Provider_Base {

	/**
	 * Text model prefixes.
	 *
	 * @var array
	 */
	private static $text_prefixes = array( 'gemini-' );

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'google';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_chat() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_images() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return null;
		}
		$is_imagen = ( strpos( $model, 'imagen-' ) === 0 );
		if ( ! $is_imagen ) {
			return null;
		}
		$n = min( 4, max( 1, (int) $n ) );
		$aspect = '1:1';
		if ( preg_match( '/^(\d+)x(\d+)$/', $size, $m ) ) {
			$w = (int) $m[1];
			$h = (int) $m[2];
			if ( $w > $h ) {
				$aspect = ( $w / $h ) >= 1.7 ? '16:9' : '4:3';
			} elseif ( $h > $w ) {
				$aspect = ( $h / $w ) >= 1.7 ? '9:16' : '3:4';
			}
		}
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':predict?key=' . $api_key;
		$body = array(
			'instances'  => array( array( 'prompt' => $prompt ) ),
			'parameters' => array(
				'sampleCount' => $n,
				'aspectRatio' => $aspect,
			),
		);
		return array(
			'url'     => $url,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Google API key not configured.', 'alorbach-ai-gateway' ) );
		}
		$model = $body['model'] ?? 'gemini-pro';
		$body  = self::normalize_chat_body( $body, $model );
		$body  = self::messages_to_contents( $body );
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * Convert OpenAI-style messages to Gemini contents format.
	 *
	 * @param array $body Request body with messages.
	 * @return array Body with contents (and generationConfig for maxOutputTokens).
	 */
	private static function messages_to_contents( $body ) {
		$messages = $body['messages'] ?? array();
		if ( empty( $messages ) ) {
			return $body;
		}
		$contents = array();
		foreach ( $messages as $msg ) {
			$role = isset( $msg['role'] ) ? $msg['role'] : 'user';
			$content = isset( $msg['content'] ) ? $msg['content'] : '';
			if ( $role === 'system' ) {
				if ( ! isset( $body['systemInstruction'] ) ) {
					$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $content ) ) );
				} else {
					// Append additional system messages into the same systemInstruction.
					$body['systemInstruction']['parts'][] = array( 'text' => $content );
				}
				continue;
			}
			$gemini_role = ( $role === 'assistant' ) ? 'model' : 'user';
			$contents[] = array(
				'role'  => $gemini_role,
				'parts' => array( array( 'text' => $content ) ),
			);
		}
		unset( $body['messages'] );
		$body['contents'] = $contents;
		if ( isset( $body['max_tokens'] ) ) {
			if ( ! isset( $body['generationConfig'] ) ) {
				$body['generationConfig'] = array();
			}
			$body['generationConfig']['maxOutputTokens'] = (int) $body['max_tokens'];
			unset( $body['max_tokens'] );
		}
		if ( isset( $body['max_completion_tokens'] ) ) {
			if ( ! isset( $body['generationConfig'] ) ) {
				$body['generationConfig'] = array();
			}
			$body['generationConfig']['maxOutputTokens'] = (int) $body['max_completion_tokens'];
			unset( $body['max_completion_tokens'] );
		}
		// Disable thinking for short requests (e.g. model verification) - Gemini 2.5/3 have thinking by default.
		$model = $body['model'] ?? '';
		$max_out = isset( $body['generationConfig']['maxOutputTokens'] ) ? (int) $body['generationConfig']['maxOutputTokens'] : 0;
		if ( ( strpos( $model, 'gemini-2.5' ) === 0 || strpos( $model, 'gemini-3' ) === 0 ) && $max_out > 0 && $max_out <= 128 ) {
			if ( ! isset( $body['generationConfig'] ) ) {
				$body['generationConfig'] = array();
			}
			$body['generationConfig']['thinkingConfig'] = array( 'thinkingBudget' => 0 );
		}
		return $body;
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
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
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return array();
		}
		$items   = array();
		$base_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key . '&pageSize=100';
		$url     = $base_url;
		do {
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['models'] ) || ! is_array( $body['models'] ) ) {
				break;
			}
			foreach ( $body['models'] as $m ) {
				$name = $m['name'] ?? '';
				if ( strpos( $name, 'models/' ) === 0 ) {
					$name = substr( $name, 7 );
				}
				if ( empty( $name ) ) {
					continue;
				}
				$methods = $m['supportedGenerationMethods'] ?? array();
				$has_image = in_array( 'generateImages', $methods, true ) || in_array( 'generateImage', $methods, true );
				$has_content = in_array( 'generateContent', $methods, true );
				$is_imagen = ( strpos( $name, 'imagen-' ) === 0 );
				$is_gemini_image = ( strpos( $name, 'gemini-' ) === 0 && ( strpos( $name, '-image' ) !== false || strpos( $name, 'image-' ) !== false ) );
				$is_veo = ( strpos( $name, 'veo-' ) === 0 );
				if ( $is_veo ) {
					$items[] = array(
						'id'           => $name,
						'provider'     => 'google',
						'type'         => 'video',
						'capabilities' => array( 'text_to_video' ),
					);
				} elseif ( $is_imagen || ( $has_image && ( $is_gemini_image || ! $has_content ) ) ) {
					$items[] = array(
						'id'           => $name,
						'provider'     => 'google',
						'type'         => 'image',
						'capabilities' => array( 'text_to_image' ),
					);
				} elseif ( self::matches_prefix( $name, self::$text_prefixes ) && $has_content ) {
					$items[] = array(
						'id'           => $name,
						'provider'     => 'google',
						'type'         => 'text',
						'capabilities' => $this->map_google_capabilities( $methods ),
						'max_tokens'   => isset( $m['outputTokenLimit'] ) ? (int) $m['outputTokenLimit'] : 0,
					);
				}
			}
			$next = isset( $body['nextPageToken'] ) ? trim( (string) $body['nextPageToken'] ) : '';
			$url  = $next ? $base_url . '&pageToken=' . rawurlencode( $next ) : '';
		} while ( $url !== '' );
		return $items;
	}

	/**
	 * Map supportedGenerationMethods to capability keys.
	 *
	 * @param array $methods Supported methods.
	 * @return array
	 */
	private function map_google_capabilities( $methods ) {
		$out = array();
		if ( ! is_array( $methods ) ) {
			return array( 'text_to_text' );
		}
		$has_content = in_array( 'generateContent', $methods, true );
		$has_image   = in_array( 'generateImages', $methods, true ) || in_array( 'generateImage', $methods, true );
		if ( $has_content ) {
			$out[] = 'text_to_text';
			$out[] = 'image_to_text';
		}
		if ( $has_image ) {
			$out[] = 'text_to_image';
		}
		return ! empty( $out ) ? $out : array( 'text_to_text' );
	}
}
