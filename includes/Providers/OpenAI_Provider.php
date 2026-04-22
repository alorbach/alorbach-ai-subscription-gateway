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
	private static $image_sizes = array( '1024x1024', '1024x1536', '1536x1024', '1792x1024', '1024x1792', '2048x2048', '2048x1152', '3840x2160', '2160x3840', 'auto' );

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
	public function get_image_job_capabilities( $model = '' ) {
		$model = (string) $model;
		$supports_preview_images = function_exists( 'curl_init' ) && strpos( $model, 'gpt-image' ) === 0;

		return array(
			'async_jobs'        => $supports_preview_images,
			'provider_progress' => $supports_preview_images,
			'preview_images'    => $supports_preview_images,
		);
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
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}

		$reference_images = is_array( $reference_images ) ? array_values( array_filter( $reference_images, 'is_array' ) ) : array();

		if ( ! empty( $reference_images ) ) {
			if ( strpos( $model, 'gpt-image' ) !== 0 ) {
				return new \WP_Error( 'reference_images_unsupported', __( 'Reference-image generation is supported only for GPT Image models.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}

			$boundary = wp_generate_password( 24, false );
			$body     = '';

			$fields = array(
				'model'         => $model,
				'prompt'        => $prompt,
				'size'          => $size,
				'n'             => $n,
				'quality'       => $quality ?: 'medium',
				'output_format' => $output_format ?: 'png',
			);

			foreach ( $fields as $name => $value ) {
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
				$body .= (string) $value . "\r\n";
			}

			foreach ( $reference_images as $index => $item ) {
				$b64 = isset( $item['b64_json'] ) && is_string( $item['b64_json'] ) ? trim( $item['b64_json'] ) : '';
				$mime = isset( $item['mime_type'] ) && is_string( $item['mime_type'] ) ? trim( $item['mime_type'] ) : 'image/png';
				if ( '' === $b64 ) {
					return new \WP_Error( 'invalid_reference_image', __( 'Reference images must include base64 image data.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
				}

				$binary = base64_decode( $b64, true );
				if ( false === $binary || '' === $binary ) {
					return new \WP_Error( 'invalid_reference_image', __( 'Reference image data could not be decoded.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
				}

				$extension = match ( strtolower( $mime ) ) {
					'image/jpeg', 'image/jpg' => 'jpg',
					'image/webp'              => 'webp',
					default                   => 'png',
				};
				$content_type = in_array( strtolower( $mime ), array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' ), true ) ? strtolower( $mime ) : 'image/png';

				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="image"; filename="reference-' . ( $index + 1 ) . '.' . $extension . '"' . "\r\n";
				$body .= 'Content-Type: ' . $content_type . "\r\n\r\n";
				$body .= $binary . "\r\n";
			}

			$body .= '--' . $boundary . '--' . "\r\n";

			return array(
				'url'     => 'https://api.openai.com/v1/images/edits',
				'headers' => array(
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => $body,
			);
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
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials, $format = null ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}
		// OpenAI expects whisper-1, not whisper.
		if ( $model === 'whisper' ) {
			$model = 'whisper-1';
		}
		// gpt-audio uses chat completions with audio input (audio → text), not audio/transcriptions.
		if ( strpos( $model, 'gpt-audio' ) === 0 ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$audio_bytes = $wp_filesystem ? $wp_filesystem->get_contents( $file_path ) : false;
			if ( $audio_bytes === false || strlen( $audio_bytes ) < 100 ) {
				return new \WP_Error( 'read_error', __( 'Could not read audio file or file is too small.', 'alorbach-ai-gateway' ) );
			}
			if ( ! $format ) {
				$ext    = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
				$format = in_array( $ext, array( 'wav', 'mp3', 'flac', 'opus', 'm4a', 'webm' ), true ) ? $ext : 'wav';
			}
			$format = in_array( $format, array( 'wav', 'mp3', 'flac', 'opus', 'm4a', 'webm' ), true ) ? $format : 'wav';
			$audio_b64 = base64_encode( $audio_bytes );
			// System message must always state that the user provides audio; custom prompt adds format instructions.
			$base = __( 'You are a transcription assistant. The user will provide audio in the next message. Transcribe it.', 'alorbach-ai-gateway' );
			$sys  = $prompt ? $base . ' ' . $prompt : $base . ' ' . __( 'Output only the transcribed text, nothing else. For silent or unclear audio, output a single period.', 'alorbach-ai-gateway' );
			$body = array(
				'model'       => $model,
				'modalities'  => array( 'text' ),
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => $sys,
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'         => 'input_audio',
								'input_audio'  => array(
									'data'   => $audio_b64,
									'format' => $format,
								),
							),
						),
					),
				),
			);
			return array(
				'url'             => 'https://api.openai.com/v1/chat/completions',
				'headers'         => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'            => wp_json_encode( $body ),
				'response_format' => 'chat_completions',
			);
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
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$body .= $wp_filesystem->get_contents( $file_path ) . "\r\n";
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
		return self::make_verified_get(
			'https://api.openai.com/v1/models',
			array( 'Authorization' => 'Bearer ' . $api_key ),
			__( 'Invalid API key.', 'alorbach-ai-gateway' )
		);
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
