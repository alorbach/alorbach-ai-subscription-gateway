<?php
/**
 * OpenAI API provider.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OpenAI_Provider
 */
class OpenAI_Provider extends Provider_Base {

	/**
	 * Text model prefixes.
	 *
	 * @var array
	 */
	private static $text_prefixes = array( 'gpt-', 'o1', 'o3', 'o4' );

	/**
	 * Image sizes for DALL-E.
	 *
	 * @var array
	 */
	private static $image_sizes = array( '1024x1024', '1792x1024', '1024x1792' );

	/**
	 * Video models.
	 *
	 * @var array
	 */
	private static $video_models = array( 'sora-2' );

	/**
	 * Audio models.
	 *
	 * @var array
	 */
	private static $audio_models = array( 'whisper-1', 'gpt-4o-transcribe' );

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'openai';
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
	public function supports_audio() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_video() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}
		$model = $body['model'] ?? '';
		$body  = self::normalize_chat_body( $body, $model );
		return array(
			'url'     => 'https://api.openai.com/v1/chat/completions',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}
		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'size'   => $size,
			'n'      => $n,
		);
		if ( strpos( $model, 'gpt-image' ) === 0 ) {
			$body['quality']       = $quality ?: 'medium';
			$body['output_format'] = $output_format ?: 'png';
		}
		return array(
			'url'     => 'https://api.openai.com/v1/images/generations',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}
		// OpenAI expects whisper-1, not whisper.
		if ( $model === 'whisper' ) {
			$model = 'whisper-1';
		}
		$boundary = wp_generate_password( 24, false );
		$body     = '--' . $boundary . "\r\n";
		$body    .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n" . $model . "\r\n";
		if ( $prompt !== '' ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="prompt"' . "\r\n\r\n" . $prompt . "\r\n";
		}
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body .= file_get_contents( $file_path ) . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";
		return array(
			'url'     => 'https://api.openai.com/v1/audio/transcriptions',
			'headers' => array(
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => $body,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_video_request( $prompt, $model, $size, $duration_seconds, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}
		$seconds = max( 4, min( 12, (int) $duration_seconds ) );
		if ( ! in_array( $seconds, array( 4, 8, 12 ), true ) ) {
			$seconds = 8;
		}
		$body = array(
			'prompt'   => $prompt,
			'model'    => $model,
			'size'     => $size ?: '1280x720',
			'seconds'  => (string) $seconds,
		);
		return array(
			'url'     => 'https://api.openai.com/v1/videos',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
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
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return array();
		}
		$items = array();
		foreach ( $body['data'] as $m ) {
			$id = isset( $m['id'] ) ? $m['id'] : '';
			if ( ! $id ) {
				continue;
			}
			$type = self::classify_openai_model( $id );
			if ( $type === 'text' ) {
				if ( ! self::matches_prefix( $id, self::$text_prefixes ) ) {
					continue;
				}
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'text',
					'capabilities' => self::infer_openai_capabilities( $id ),
				);
			} elseif ( $type === 'image' ) {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'image',
					'capabilities' => array( 'text_to_image' ),
				);
			} elseif ( $type === 'audio' ) {
				$caps = strpos( $id, '-tts' ) !== false ? array( 'text_to_audio' ) : array( 'audio_to_text' );
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'audio',
					'capabilities' => $caps,
				);
			} elseif ( $type === 'video' ) {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'video',
					'capabilities' => array( 'text_to_video' ),
				);
			}
		}
		// Add DALL-E sizes.
		$image_ids = array_column( $items, 'id' );
		foreach ( self::$image_sizes as $size ) {
			if ( ! in_array( $size, $image_ids, true ) ) {
				$items[] = array(
					'id'           => $size,
					'provider'     => 'openai',
					'type'         => 'image',
					'capabilities' => array( 'text_to_image' ),
				);
			}
		}
		// Add known video models.
		$video_ids = array_column( array_filter( $items, function ( $i ) {
			return ( $i['type'] ?? '' ) === 'video';
		} ), 'id' );
		foreach ( self::$video_models as $model ) {
			if ( ! in_array( $model, $video_ids, true ) ) {
				$items[] = array(
					'id'           => $model,
					'provider'     => 'openai',
					'type'         => 'video',
					'capabilities' => array( 'text_to_video' ),
				);
			}
		}
		// Add known audio models.
		$audio_ids = array_column( array_filter( $items, function ( $i ) {
			return ( $i['type'] ?? '' ) === 'audio';
		} ), 'id' );
		foreach ( self::$audio_models as $model ) {
			if ( ! in_array( $model, $audio_ids, true ) ) {
				$items[] = array(
					'id'           => $model,
					'provider'     => 'openai',
					'type'         => 'audio',
					'capabilities' => array( 'audio_to_text' ),
				);
			}
		}
		return $items;
	}
}
