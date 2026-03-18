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
	public function build_video_request( $prompt, $model, $size, $duration_seconds, $credentials ) {
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
	 * Build Responses API request for Codex models.
	 * Codex models (gpt-5.x-codex) require the v1 Responses API, not chat/completions.
	 *
	 * @param string $model      Model ID (e.g. gpt-5.3-codex).
	 * @param array  $credentials Credentials with endpoint and api_key.
	 * @return array{url: string, headers: array, body: string}|WP_Error
	 */
	public function build_responses_request( $model, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		$body = array(
			'model'             => $model,
			'input'             => 'Hi',
			'max_output_tokens' => 64,
		);
		$url = $endpoint . '/openai/v1/responses';
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
		$body  = $body;
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
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
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
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials, $format = null ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		// gpt-audio-1.5 uses chat completions with audio input (audio → text), not audioTranscriptions.
		if ( strpos( $model, 'gpt-audio' ) === 0 ) {
			$audio_bytes = is_readable( $file_path ) ? file_get_contents( $file_path ) : false;
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
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return array( 'success' => false, 'message' => __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		$endpoint = rtrim( $endpoint, '/' );
		if ( strpos( $endpoint, '.azure.com' ) === false ) {
			return array( 'success' => false, 'message' => __( 'Endpoint must end with .azure.com (e.g. https://xxx.services.ai.azure.com)', 'alorbach-ai-gateway' ) );
		}
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
		$last_error   = '';
		$last_body    = '';
		$last_url     = '';
		$debug_enabled = (bool) get_option( 'alorbach_debug_enabled', false ) && current_user_can( 'manage_options' );
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
				return array( 'success' => true );
			}
			$body       = json_decode( wp_remote_retrieve_body( $response ), true );
			$last_error = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid Azure configuration.', 'alorbach-ai-gateway' );
			$last_body  = wp_remote_retrieve_body( $response );
			$last_url   = $url;
		}
		$result = array( 'success' => false, 'message' => $last_error );
		if ( $debug_enabled ) {
			$result['_debug'] = array(
				'last_url'  => $last_url,
				'last_body' => $last_body,
			);
		}
		return $result;
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
