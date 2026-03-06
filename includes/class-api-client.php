<?php
/**
 * API client for AI providers (OpenAI, Azure, Google).
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Client
 */
class API_Client {

	/**
	 * Send chat completion request to provider.
	 *
	 * @param string $provider Provider: openai, azure, google.
	 * @param array  $body     Request body.
	 * @return array|WP_Error Response or error.
	 */
	public static function chat( $provider, $body ) {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();

		$url = '';
		$headers = array(
			'Content-Type' => 'application/json',
		);

		switch ( $provider ) {
			case 'openai':
				$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
				if ( empty( $api_key ) ) {
					return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
				}
				$url = 'https://api.openai.com/v1/chat/completions';
				$headers['Authorization'] = 'Bearer ' . $api_key;
				break;
			case 'azure':
				$endpoint = isset( $keys['azure_endpoint'] ) ? $keys['azure_endpoint'] : '';
				$api_key  = isset( $keys['azure'] ) ? $keys['azure'] : '';
				if ( empty( $endpoint ) || empty( $api_key ) ) {
					return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
				}
				$url = rtrim( $endpoint, '/' ) . '/openai/deployments/' . ( $body['model'] ?? 'gpt-4o' ) . '/chat/completions?api-version=2024-02-15-preview';
				$headers['api-key'] = $api_key;
				unset( $body['model'] );
				break;
			case 'google':
				$api_key = isset( $keys['google'] ) ? $keys['google'] : '';
				if ( empty( $api_key ) ) {
					return new \WP_Error( 'no_api_key', __( 'Google API key not configured.', 'alorbach-ai-gateway' ) );
				}
				$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . ( $body['model'] ?? 'gemini-pro' ) . ':generateContent?key=' . $api_key;
				// Google uses different format - simplified for now
				break;
			default:
				$provider = 'openai';
				$api_key  = isset( $keys['openai'] ) ? $keys['openai'] : '';
				if ( empty( $api_key ) ) {
					return new \WP_Error( 'no_api_key', __( 'API key not configured.', 'alorbach-ai-gateway' ) );
				}
				$url = 'https://api.openai.com/v1/chat/completions';
				$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_response = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', isset( $body_response['error']['message'] ) ? $body_response['error']['message'] : 'API error', array( 'status' => $code ) );
		}

		return $body_response;
	}

	/**
	 * Generate images via DALL-E.
	 *
	 * @param string $prompt Prompt.
	 * @param string $size   Size (1024x1024, 1792x1024, 1024x1792).
	 * @param int    $n      Number of images (1-10).
	 * @return array|WP_Error Response or error.
	 */
	public static function images( $prompt, $size = '1024x1024', $n = 1 ) {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}

		$body = array(
			'model'  => 'dall-e-3',
			'prompt' => $prompt,
			'size'   => $size,
			'n'      => min( 10, max( 1, (int) $n ) ) );
		$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body_response = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', isset( $body_response['error']['message'] ) ? $body_response['error']['message'] : 'API error', array( 'status' => $code ) );
		}
		return $body_response;
	}

	/**
	 * Transcribe audio via Whisper.
	 *
	 * @param string $file_path Path to audio file.
	 * @return array|WP_Error Response with 'text' or error.
	 */
	public static function transcribe( $file_path ) {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
		}
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( 'invalid_file', __( 'Audio file not found.', 'alorbach-ai-gateway' ) );
		}

		$boundary = wp_generate_password( 24, false );
		$body = '';
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n";
		$body .= 'whisper-1' . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body .= file_get_contents( $file_path ) . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		$response = wp_remote_post( 'https://api.openai.com/v1/audio/transcriptions', array(
			'headers' => array(
				'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
				'Authorization'  => 'Bearer ' . $api_key,
			),
			'body'    => $body,
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body_response = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', isset( $body_response['error']['message'] ) ? $body_response['error']['message'] : 'API error', array( 'status' => $code ) );
		}
		return is_array( $body_response ) ? $body_response : array( 'text' => (string) wp_remote_retrieve_body( $response ) );
	}
}
