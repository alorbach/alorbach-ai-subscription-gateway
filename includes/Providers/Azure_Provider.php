<?php
/**
 * Azure OpenAI / Foundry API provider.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Azure_Provider
 */
class Azure_Provider extends Provider_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'azure';
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
	 * Whether the model ID targets Azure Speech Services transcription.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	public static function is_speech_transcription_model( $model ) {
		$model = strtolower( trim( (string) $model ) );
		return in_array( $model, array( 'azure-speech', 'speech' ), true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_video_request( $prompt, $model, $size, $duration_seconds, $credentials, $input_reference = array() ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		$seconds = max( 4, min( 12, (int) $duration_seconds ) );
		if ( ! in_array( $seconds, array( 4, 8, 12 ), true ) ) {
			$seconds = 8;
		}
		// Sora 2 uses OpenAI-style API: /openai/v1/videos with model, prompt, size, seconds.
		// Per Azure docs: Sora 2 aligns with OpenAI's native Sora 2 schema.
		$url  = $endpoint . '/openai/v1/videos';
		$body = array(
			'model'    => $model,
			'prompt'   => $prompt,
			'size'     => $size ?: '1280x720',
			'seconds'  => (string) $seconds,
		);
		$input_reference = is_array( $input_reference ) ? $input_reference : array();
		if ( ! empty( $input_reference ) ) {
			$b64 = isset( $input_reference['b64_json'] ) && is_string( $input_reference['b64_json'] ) ? trim( $input_reference['b64_json'] ) : '';
			$mime = isset( $input_reference['mime_type'] ) && is_string( $input_reference['mime_type'] ) ? strtolower( trim( $input_reference['mime_type'] ) ) : 'image/png';
			if ( '' === $b64 ) {
				return new \WP_Error( 'invalid_video_input_reference', __( 'Video input reference must include base64 image data.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
			$binary = base64_decode( $b64, true );
			if ( false === $binary || '' === $binary ) {
				return new \WP_Error( 'invalid_video_input_reference', __( 'Video input reference image data could not be decoded.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
			$extension = match ( $mime ) {
				'image/jpeg', 'image/jpg' => 'jpg',
				'image/webp'              => 'webp',
				default                   => 'png',
			};
			$content_type = in_array( $mime, array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' ), true ) ? $mime : 'image/png';
			if ( 'image/jpg' === $content_type ) {
				$content_type = 'image/jpeg';
			}
			$boundary = wp_generate_password( 24, false );
			$multipart = '';
			foreach ( $body as $name => $value ) {
				$multipart .= '--' . $boundary . "\r\n";
				$multipart .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
				$multipart .= (string) $value . "\r\n";
			}
			$multipart .= '--' . $boundary . "\r\n";
			$multipart .= 'Content-Disposition: form-data; name="input_reference"; filename="input-reference.' . $extension . '"' . "\r\n";
			$multipart .= 'Content-Type: ' . $content_type . "\r\n\r\n";
			$multipart .= $binary . "\r\n";
			$multipart .= '--' . $boundary . '--' . "\r\n";
			return array(
				'url'     => $url,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'api-key'     => $api_key,
				),
				'body'    => $multipart,
			);
		}
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type' => 'application/json',
				'api-key'     => $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * Build a Responses API request from a chat-style payload.
	 * Codex models (gpt-5.x-codex) require the v1 Responses API, not chat/completions.
	 *
	 * @param array  $body        Chat-style request body.
	 * @param array  $credentials Credentials with endpoint and api_key.
	 * @return array{url: string, headers: array, body: string}|WP_Error
	 */
	public function build_responses_request( $body, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}

		$model     = isset( $body['model'] ) ? (string) $body['model'] : 'gpt-5.3-codex';
		$body      = self::normalize_chat_body( $body, $model );
		$messages  = isset( $body['messages'] ) && is_array( $body['messages'] ) ? $body['messages'] : array();
		$instructions = '';
		$input        = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? (string) $message['role'] : 'user';
			$content = $message['content'] ?? '';

			if ( 'system' === $role ) {
				if ( is_string( $content ) && '' !== trim( $content ) ) {
					$instructions .= ( '' !== $instructions ? "\n" : '' ) . trim( $content );
				}
				continue;
			}

			$input[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		if ( empty( $input ) ) {
			$input[] = array(
				'role'    => 'user',
				'content' => '',
			);
		}

		$request_body = array(
			'model' => $model,
			'input' => $input,
		);

		if ( '' !== $instructions ) {
			$request_body['instructions'] = $instructions;
		}
		if ( isset( $body['temperature'] ) ) {
			$request_body['temperature'] = $body['temperature'];
		}
		if ( isset( $body['max_completion_tokens'] ) ) {
			$request_body['max_output_tokens'] = (int) $body['max_completion_tokens'];
		} elseif ( isset( $body['max_tokens'] ) ) {
			$request_body['max_output_tokens'] = (int) $body['max_tokens'];
		}

		$url = $endpoint . '/openai/v1/responses';
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type' => 'application/json',
				'api-key'     => $api_key,
			),
			'body'    => wp_json_encode( $request_body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		$model = $body['model'] ?? 'gpt-4o';
		$body  = self::normalize_chat_body( $body, $model );
		unset( $body['model'] );
		$url = $endpoint . '/openai/deployments/' . $model . '/chat/completions?api-version=2024-10-21';
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type' => 'application/json',
				'api-key'      => $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * Map FLUX model ID to Black Forest Labs API path.
	 *
	 * @param string $model Model ID (e.g. FLUX.2-pro, FLUX-1.1-pro).
	 * @return string|null BFL path or null if not FLUX.
	 */
	private static function get_flux_bfl_path( $model ) {
		$m = strtolower( $model );
		if ( strpos( $m, 'flux' ) !== 0 ) {
			return null;
		}
		if ( strpos( $m, 'flux.2-pro' ) === 0 || strpos( $m, 'flux-2-pro' ) === 0 ) {
			return 'providers/blackforestlabs/v1/flux-2-pro';
		}
		if ( strpos( $m, 'flux.1.1' ) !== false || strpos( $m, 'flux-1.1' ) !== false || strpos( $m, 'flux1.1' ) !== false ) {
			return 'providers/blackforestlabs/v1/flux-pro-1.1';
		}
		if ( strpos( $m, 'kontext' ) !== false ) {
			return 'providers/blackforestlabs/v1/flux-kontext-pro';
		}
		if ( strpos( $m, 'flux.2' ) === 0 || strpos( $m, 'flux-2' ) === 0 ) {
			return 'providers/blackforestlabs/v1/flux-2-pro';
		}
		return 'providers/blackforestlabs/v1/flux-2-pro';
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}

		$reference_images = is_array( $reference_images ) ? array_values( array_filter( $reference_images, 'is_array' ) ) : array();
		if ( ! empty( $reference_images ) ) {
			if ( strpos( $model, 'gpt-image' ) !== 0 ) {
				return new \WP_Error( 'reference_images_unsupported', __( 'Reference-image generation is supported only for GPT Image models.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}

			$boundary = wp_generate_password( 24, false );
			$body     = '';
			$fields   = array(
				'prompt'        => $prompt,
				'n'             => $n,
				'size'          => $size,
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
				$body .= 'Content-Disposition: form-data; name="image[]"; filename="reference-' . ( $index + 1 ) . '.' . $extension . '"' . "\r\n";
				$body .= 'Content-Type: ' . $content_type . "\r\n\r\n";
				$body .= $binary . "\r\n";
			}

			$body .= '--' . $boundary . '--' . "\r\n";

			return array(
				'url'     => $endpoint . '/openai/deployments/' . $model . '/images/edits?api-version=2025-04-01-preview',
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'api-key'      => $api_key,
				),
				'body'    => $body,
			);
		}

		$bfl_path = self::get_flux_bfl_path( $model );
		if ( $bfl_path ) {
			$dim = self::parse_size( $size );
			$body = array(
				'prompt'        => $prompt,
				'n'             => $n,
				'width'         => $dim[0],
				'height'        => $dim[1],
				'output_format' => strtolower( $output_format ?: 'png' ),
				'model'         => strtolower( $model ),
			);
			$url = $endpoint . '/' . $bfl_path . '?api-version=preview';
		} else {
			$body = array(
				'prompt' => $prompt,
				'n'      => $n,
				'size'   => $size,
			);
			if ( strpos( $model, 'gpt-image' ) === 0 ) {
				$body['quality']       = $quality ?: 'medium';
				$body['output_format'] = $output_format ?: 'png';
			}
			$url = $endpoint . '/openai/deployments/' . $model . '/images/generations?api-version=2025-04-01-preview';
		}
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type' => 'application/json',
				'api-key'      => $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * Parse size string (e.g. 1024x1024) into width and height.
	 *
	 * @param string $size Size string.
	 * @return int[] [width, height]
	 */
	private static function parse_size( $size ) {
		if ( preg_match( '/^(\d+)\s*x\s*(\d+)$/i', trim( $size ), $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		return array( 1024, 1024 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials, $format = null, $options = array() ) {
		if ( self::is_speech_transcription_model( $model ) ) {
			return $this->build_speech_transcribe_request( $file_path, $credentials, $format, $options );
		}
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		// gpt-audio-1.5 uses chat completions with audio input (audio → text), not audioTranscriptions.
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
				'modalities' => array( 'text' ),
				'messages'   => array(
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
			$url = $endpoint . '/openai/deployments/' . $model . '/chat/completions?api-version=2025-01-01-preview';
			return array(
				'url'             => $url,
				'headers'         => array(
					'Content-Type' => 'application/json',
					'api-key'      => $api_key,
				),
				'body'            => wp_json_encode( $body ),
				'response_format' => 'chat_completions',
			);
		}
		$api_ver = ( strpos( $model, 'gpt-4o-transcribe' ) !== false || strpos( $model, 'gpt-4o-mini-transcribe' ) !== false )
			? '2025-04-01-preview'
			: '2024-02-01';
		$boundary = wp_generate_password( 24, false );
		$body     = '';
		$response_format = ( strpos( $model, 'gpt-4o' ) !== false && strpos( $model, 'transcribe' ) !== false ) ? 'json' : 'verbose_json';
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="response_format"' . "\r\n\r\n" . $response_format . "\r\n";
		foreach ( array( 'word', 'segment' ) as $granularity ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="timestamp_granularities[]"' . "\r\n\r\n" . $granularity . "\r\n";
		}
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
		$url = $endpoint . '/openai/deployments/' . $model . '/audio/transcriptions?api-version=' . $api_ver;
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				'api-key'      => $api_key,
			),
			'body'    => $body,
		);
	}

	/**
	 * Build Azure Speech Services synchronous transcription request.
	 *
	 * @param string      $file_path   Audio file path.
	 * @param array       $credentials Azure credentials.
	 * @param string|null $format      Audio format hint.
	 * @param array       $options     Request options.
	 * @return array|\WP_Error
	 */
	private function build_speech_transcribe_request( $file_path, $credentials, $format = null, $options = array() ) {
		$endpoint = isset( $credentials['speech_endpoint'] ) ? rtrim( trim( (string) $credentials['speech_endpoint'] ), '/' ) : '';
		$api_key  = isset( $credentials['api_key'] ) ? trim( (string) $credentials['api_key'] ) : '';
		if ( '' === $endpoint || '' === $api_key ) {
			return new \WP_Error( 'no_api_key', __( 'Azure Speech Services not configured.', 'alorbach-ai-gateway' ) );
		}

		$api_version = isset( $credentials['speech_api_version'] ) && '' !== trim( (string) $credentials['speech_api_version'] )
			? trim( (string) $credentials['speech_api_version'] )
			: '2024-11-15';
		$locale = isset( $options['locale'] ) && '' !== trim( (string) $options['locale'] )
			? trim( (string) $options['locale'] )
			: ( isset( $options['language'] ) && '' !== trim( (string) $options['language'] )
				? trim( (string) $options['language'] )
				: ( isset( $credentials['speech_default_locale'] ) && '' !== trim( (string) $credentials['speech_default_locale'] )
					? trim( (string) $credentials['speech_default_locale'] )
					: 'en-US' ) );

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$audio_bytes = $wp_filesystem ? $wp_filesystem->get_contents( $file_path ) : false;
		if ( false === $audio_bytes || '' === $audio_bytes ) {
			return new \WP_Error( 'read_error', __( 'Could not read audio file.', 'alorbach-ai-gateway' ) );
		}

		$format = $format ? strtolower( (string) $format ) : strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$content_type_map = array(
			'wav'  => 'audio/wav',
			'mp3'  => 'audio/mpeg',
			'mp4'  => 'audio/mp4',
			'm4a'  => 'audio/mp4',
			'ogg'  => 'audio/ogg',
			'opus' => 'audio/ogg',
			'flac' => 'audio/flac',
			'webm' => 'audio/webm',
		);
		$content_type = isset( $content_type_map[ $format ] ) ? $content_type_map[ $format ] : 'application/octet-stream';
		$definition = array(
			'locales'                    => array( $locale ),
			'profanityFilterMode'        => 'None',
			'wordLevelTimestampsEnabled' => true,
		);

		$boundary = wp_generate_password( 24, false );
		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="audio"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= 'Content-Type: ' . $content_type . "\r\n\r\n";
		$body .= $audio_bytes . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="definition"' . "\r\n";
		$body .= 'Content-Type: application/json' . "\r\n\r\n";
		$body .= wp_json_encode( $definition ) . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		$endpoint_path = '/speechtotext/transcriptions:transcribe';
		return array(
			'url'              => $endpoint . $endpoint_path . '?api-version=' . rawurlencode( $api_version ),
			'headers'          => array(
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				'Ocp-Apim-Subscription-Key' => $api_key,
			),
			'body'             => $body,
			'response_format'  => 'azure_speech',
			'provider_details' => array(
				'provider'      => 'azure_speech',
				'endpoint_path' => $endpoint_path,
				'locale'        => $locale,
				'api_version'   => $api_version,
				'audio_format'  => $format,
			),
		);
	}

	/**
	 * Normalize Azure Speech transcription response to the Gateway transcription shape.
	 *
	 * @param array  $body_response Decoded provider response.
	 * @param string $raw_body      Raw response body.
	 * @param array  $request       Original provider request metadata.
	 * @return array
	 */
	public function parse_transcribe_response( $body_response, $raw_body, $request = array() ) {
		if ( ! is_array( $body_response ) ) {
			return array( 'text' => (string) $raw_body );
		}

		$combined_text = '';
		if ( ! empty( $body_response['combinedPhrases'] ) && is_array( $body_response['combinedPhrases'] ) ) {
			$parts = array();
			foreach ( $body_response['combinedPhrases'] as $phrase ) {
				if ( is_array( $phrase ) && isset( $phrase['text'] ) && '' !== trim( (string) $phrase['text'] ) ) {
					$parts[] = trim( (string) $phrase['text'] );
				}
			}
			$combined_text = trim( implode( ' ', $parts ) );
		}

		$words = array();
		$segments = array();
		$phrase_texts = array();
		if ( ! empty( $body_response['phrases'] ) && is_array( $body_response['phrases'] ) ) {
			foreach ( $body_response['phrases'] as $phrase ) {
				if ( ! is_array( $phrase ) ) {
					continue;
				}
				$phrase_text = isset( $phrase['text'] ) ? trim( (string) $phrase['text'] ) : '';
				$start = isset( $phrase['offsetMilliseconds'] ) ? ( (float) $phrase['offsetMilliseconds'] / 1000.0 ) : 0.0;
				$duration = isset( $phrase['durationMilliseconds'] ) ? ( (float) $phrase['durationMilliseconds'] / 1000.0 ) : 0.0;
				if ( '' !== $phrase_text ) {
					$phrase_texts[] = $phrase_text;
					$segments[] = array(
						'text'  => $phrase_text,
						'start' => $start,
						'end'   => $start + $duration,
					);
				}
				if ( ! empty( $phrase['words'] ) && is_array( $phrase['words'] ) ) {
					foreach ( $phrase['words'] as $word ) {
						if ( ! is_array( $word ) ) {
							continue;
						}
						$word_text = isset( $word['text'] ) ? trim( (string) $word['text'] ) : '';
						if ( '' === $word_text ) {
							continue;
						}
						$word_start = isset( $word['offsetMilliseconds'] ) ? ( (float) $word['offsetMilliseconds'] / 1000.0 ) : 0.0;
						$word_duration = isset( $word['durationMilliseconds'] ) ? ( (float) $word['durationMilliseconds'] / 1000.0 ) : 0.0;
						$words[] = array(
							'word'  => $word_text,
							'start' => $word_start,
							'end'   => $word_start + $word_duration,
						);
					}
				}
			}
		}

		$text = '' !== $combined_text ? $combined_text : trim( implode( ' ', $phrase_texts ) );
		$provider_details = isset( $request['provider_details'] ) && is_array( $request['provider_details'] ) ? $request['provider_details'] : array();
		$provider_details['word_count'] = count( $words );
		$provider_details['segment_count'] = count( $segments );
		if ( isset( $body_response['durationMilliseconds'] ) ) {
			$provider_details['duration_seconds'] = (float) $body_response['durationMilliseconds'] / 1000.0;
		}

		return array(
			'text'             => $text,
			'words'            => $words,
			'segments'         => $segments,
			'provider_details' => $provider_details,
		);
	}

	/**
	 * Check if endpoint is a Foundry-style host (services, models, or inference).
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return bool
	 */
	private static function is_foundry_endpoint( $endpoint ) {
		return (
			strpos( $endpoint, 'services.ai.azure.com' ) !== false ||
			strpos( $endpoint, 'models.ai.azure.com' ) !== false ||
			strpos( $endpoint, 'inference.ai.azure.com' ) !== false
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? trim( $credentials['endpoint'] ) : '';
		$api_key  = isset( $credentials['api_key'] ) ? trim( (string) $credentials['api_key'] ) : '';
		$speech_endpoint = isset( $credentials['speech_endpoint'] ) ? trim( (string) $credentials['speech_endpoint'] ) : '';
		if ( '' === $api_key ) {
			return array( 'success' => false, 'message' => __( 'Azure API key not configured.', 'alorbach-ai-gateway' ) );
		}
		if ( '' === $endpoint && '' === $speech_endpoint ) {
			return array( 'success' => false, 'message' => __( 'Azure endpoint not configured.', 'alorbach-ai-gateway' ) );
		}

		$checks = array();
		$last_error   = '';
		$last_body    = '';
		$last_url     = '';
		$debug_enabled = (bool) get_option( 'alorbach_debug_enabled', false ) && current_user_can( 'manage_options' );

		if ( '' !== $endpoint ) {
			$endpoint = rtrim( $endpoint, '/' );
			if ( strpos( $endpoint, '.azure.com' ) === false ) {
				$checks['azure_openai'] = array(
					'label'   => __( 'Azure OpenAI / Foundry', 'alorbach-ai-gateway' ),
					'success' => false,
					'message' => __( 'Endpoint must end with .azure.com (e.g. https://xxx.services.ai.azure.com)', 'alorbach-ai-gateway' ),
				);
			} else {
				$is_foundry = self::is_foundry_endpoint( $endpoint );
				$urls       = $is_foundry
					? array(
						$endpoint . '/openai/v1/models',
						$endpoint . '/openai/models?api-version=2024-12-01-preview',
						$endpoint . '/openai/models?api-version=2024-10-21',
						$endpoint . '/openai/deployments?api-version=2024-02-15-preview',
						$endpoint . '/openai/deployments?api-version=2023-03-15-preview',
					)
					: array(
						$endpoint . '/openai/models?api-version=2024-12-01-preview',
						$endpoint . '/openai/models?api-version=2024-10-21',
					);
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
						$last_url   = $url;
						continue;
					}
					$code = wp_remote_retrieve_response_code( $response );
					if ( $code < 400 ) {
						$last_error = '';
						break;
					}
					$body       = json_decode( wp_remote_retrieve_body( $response ), true );
					$last_error = isset( $body['error']['message'] ) ? sanitize_text_field( (string) $body['error']['message'] ) : __( 'Invalid Azure configuration.', 'alorbach-ai-gateway' );
					$last_body  = wp_remote_retrieve_body( $response );
					$last_url   = $url;
				}
				$checks['azure_openai'] = array(
					'label'   => __( 'Azure OpenAI / Foundry', 'alorbach-ai-gateway' ),
					'success' => '' === $last_error,
					'message' => '' === $last_error ? __( 'OK', 'alorbach-ai-gateway' ) : $last_error,
				);
				if ( $debug_enabled && '' !== $last_error ) {
					$checks['azure_openai']['_debug'] = array(
						'last_url' => $last_url,
						'last_body' => $last_body,
					);
				}
			}
		}

		$speech_result = $this->verify_speech_endpoint( $credentials );
		if ( ! empty( $speech_result['verified'] ) || '' !== $speech_endpoint ) {
			$checks['azure_speech'] = array(
				'label'   => __( 'Azure Speech', 'alorbach-ai-gateway' ),
				'success' => ! empty( $speech_result['success'] ),
				'message' => ! empty( $speech_result['message'] ) ? $speech_result['message'] : __( 'OK', 'alorbach-ai-gateway' ),
			);
		}

		$success = true;
		foreach ( $checks as $check ) {
			if ( empty( $check['success'] ) ) {
				$success = false;
				break;
			}
		}
		$result = array(
			'success' => $success,
			'checks'  => $checks,
		);
		if ( ! empty( $checks['azure_openai'] ) && ! empty( $checks['azure_speech'] ) ) {
			$result['message'] = $success
				? __( 'Azure OpenAI / Foundry and Azure Speech verified.', 'alorbach-ai-gateway' )
				: __( 'One or more Azure checks failed.', 'alorbach-ai-gateway' );
		} elseif ( ! empty( $checks['azure_openai'] ) ) {
			$result['message'] = $checks['azure_openai']['message'];
		} elseif ( ! empty( $checks['azure_speech'] ) ) {
			$result['message'] = $checks['azure_speech']['message'];
		}
		return $result;
	}

	/**
	 * Verify the optional Azure Speech Services endpoint for an Azure entry.
	 *
	 * @param array $credentials Azure credentials.
	 * @return array{success: bool, message?: string, verified?: bool}
	 */
	private function verify_speech_endpoint( $credentials ) {
		$speech_endpoint = isset( $credentials['speech_endpoint'] ) ? rtrim( trim( (string) $credentials['speech_endpoint'] ), '/' ) : '';
		if ( '' === $speech_endpoint ) {
			return array( 'success' => true, 'verified' => false );
		}

		$api_key = isset( $credentials['api_key'] ) ? trim( (string) $credentials['api_key'] ) : '';
		if ( '' === $api_key ) {
			return array(
				'success' => false,
				'message' => __( 'API key not configured.', 'alorbach-ai-gateway' ),
			);
		}

		$host = wp_parse_url( $speech_endpoint, PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if (
			'' === $host ||
			(
				! str_ends_with( $host, '.cognitiveservices.azure.com' ) &&
				! str_ends_with( $host, '.api.cognitive.microsoft.com' )
			)
		) {
			return array(
				'success' => false,
				'message' => __( 'Endpoint must be a Cognitive Services Speech endpoint, for example https://westus.api.cognitive.microsoft.com or https://name.cognitiveservices.azure.com.', 'alorbach-ai-gateway' ),
			);
		}

		$api_version = isset( $credentials['speech_api_version'] ) && '' !== trim( (string) $credentials['speech_api_version'] )
			? trim( (string) $credentials['speech_api_version'] )
			: '2024-11-15';
		$url = $speech_endpoint . '/speechtotext/models/base?api-version=' . rawurlencode( $api_version );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Ocp-Apim-Subscription-Key' => $api_key,
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 400 ) {
			return array( 'success' => true, 'verified' => true );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $body['error']['message'] )
			? sanitize_text_field( (string) $body['error']['message'] )
			: sprintf(
				/* translators: %d: HTTP response status code */
				__( 'HTTP %d from Azure Speech endpoint.', 'alorbach-ai-gateway' ),
				(int) $code
			);
		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return array();
		}
		$is_foundry   = self::is_foundry_endpoint( $endpoint );
		$deployments  = $this->fetch_deployments( $endpoint, $api_key );
		if ( ! empty( $deployments ) ) {
			return $deployments;
		}
		$urls = $is_foundry
			? array(
				$endpoint . '/openai/v1/models',
				$endpoint . '/openai/models?api-version=2024-12-01-preview',
				$endpoint . '/openai/models?api-version=2024-10-21',
			)
			: array(
				$endpoint . '/openai/models?api-version=2024-12-01-preview',
				$endpoint . '/openai/models?api-version=2024-10-21',
			);
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array( 'api-key' => $api_key ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				continue;
			}
			$items = array();
			foreach ( $body['data'] as $m ) {
				$id   = $m['id'] ?? '';
				if ( ! $id ) {
					continue;
				}
				$caps      = $m['capabilities'] ?? array();
				$inference = ! empty( $caps['inference'] );
				$chat      = ! empty( $caps['chat_completion'] );
				// Prefer API capabilities when available; fall back to ID-based classification.
				$type = ! empty( $caps['image_generation'] ) ? 'image' : self::classify_openai_model( $id );
				if ( $type === 'image' ) {
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'image', 'capabilities' => array( 'text_to_image' ) );
				} elseif ( $type === 'video' ) {
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'video', 'capabilities' => array( 'text_to_video' ) );
				} elseif ( $type === 'audio' ) {
					$audio_caps = strpos( $id, '-tts' ) !== false ? array( 'text_to_audio' ) : array( 'audio_to_text' );
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'audio', 'capabilities' => $audio_caps );
				} else {
					if ( ! $inference && ! $chat && ! empty( $caps ) ) {
						continue;
					}
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'text', 'capabilities' => $this->map_azure_capabilities( $caps ) );
				}
			}
			return $items;
		}
		return new \WP_Error( 'api_error', __( 'Could not fetch Azure models.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * Fetch Foundry deployments.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param string $api_key  API key.
	 * @return array|null Items or null.
	 */
	private function fetch_deployments( $endpoint, $api_key ) {
		$versions = array( '2024-02-15-preview', '2023-03-15-preview' );
		foreach ( $versions as $api_version ) {
			$url      = $endpoint . '/openai/deployments?api-version=' . $api_version;
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array( 'api-key' => $api_key ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				continue;
			}
			$items = array();
			foreach ( $body['data'] as $d ) {
				$id = $d['id'] ?? $d['name'] ?? '';
				if ( empty( $id ) ) {
					continue;
				}
				$type = self::classify_openai_model( $id );
				if ( $type === 'image' ) {
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'image', 'capabilities' => array( 'text_to_image' ) );
				} elseif ( $type === 'audio' ) {
					$audio_caps = strpos( $id, '-tts' ) !== false ? array( 'text_to_audio' ) : array( 'audio_to_text' );
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'audio', 'capabilities' => $audio_caps );
				} elseif ( $type === 'video' ) {
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'video', 'capabilities' => array( 'text_to_video' ) );
				} else {
					$items[] = array( 'id' => $id, 'provider' => 'azure', 'type' => 'text', 'capabilities' => self::infer_openai_capabilities( $id ) );
				}
			}
			return ! empty( $items ) ? $items : null;
		}
		return null;
	}

	/**
	 * Map Azure capabilities to our keys.
	 *
	 * @param array $caps Azure capabilities.
	 * @return array
	 */
	private function map_azure_capabilities( $caps ) {
		$out = array( 'text_to_text' );
		if ( ! empty( $caps['vision'] ) || ! empty( $caps['image_understanding'] ) ) {
			$out[] = 'image_to_text';
		}
		if ( ! empty( $caps['image_generation'] ) ) {
			$out[] = 'text_to_image';
		}
		return $out;
	}
}
