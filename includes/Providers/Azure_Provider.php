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
	public function build_video_request( $prompt, $model, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
		$url  = $endpoint . '/openai/v1/video/generations/jobs?api-version=preview';
		$body = array(
			'prompt'    => $prompt,
			'width'     => 1280,
			'height'    => 720,
			'n_seconds' => 8,
			'model'     => $model,
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
	 * {@inheritdoc}
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
		}
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
	 * {@inheritdoc}
	 */
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials ) {
		$endpoint = isset( $credentials['endpoint'] ) ? rtrim( trim( $credentials['endpoint'] ), '/' ) : '';
		$api_key  = $credentials['api_key'] ?? '';
		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
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
		$body .= file_get_contents( $file_path ) . "\r\n";
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
			$body       = json_decode( wp_remote_retrieve_body( $response ), true );
			$last_error = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid Azure configuration.', 'alorbach-ai-gateway' );
		}
		return array( 'success' => false, 'message' => $last_error );
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
		$is_foundry = ( strpos( $endpoint, 'services.ai.azure.com' ) !== false );
		$deployments = $this->fetch_deployments( $endpoint, $api_key );
		if ( ! empty( $deployments ) ) {
			return $deployments;
		}
		$urls = $is_foundry
			? array(
				$endpoint . '/openai/v1/models',
				$endpoint . '/openai/models?api-version=2024-10-21',
			)
			: array( $endpoint . '/openai/models?api-version=2024-10-21' );
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
				$type      = self::classify_openai_model( $id );
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
		$url = $endpoint . '/openai/deployments?api-version=2023-03-15-preview';
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array( 'api-key' => $api_key ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return null;
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
