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
	 * Get the provider to use for a model based on configured API keys.
	 * GPT models work with both OpenAI and Azure; uses whichever is configured.
	 *
	 * @param string $model Model ID.
	 * @return string Provider: openai, azure, or google.
	 */
	public static function get_provider_for_model( $model ) {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$has_openai = ! empty( $keys['openai'] );
		$has_azure  = ! empty( $keys['azure_endpoint'] ) && ! empty( $keys['azure'] );
		$has_google = ! empty( $keys['google'] );

		if ( strpos( $model, 'gemini' ) === 0 ) {
			return 'google';
		}
		if ( strpos( $model, 'gpt' ) === 0 || strpos( $model, 'o1' ) === 0 || strpos( $model, 'o3' ) === 0 || strpos( $model, 'o4' ) === 0 ) {
			if ( $has_openai ) {
				return 'openai';
			}
			if ( $has_azure ) {
				return 'azure';
			}
			return 'openai';
		}
		return $has_azure ? 'azure' : 'openai';
	}

	/**
	 * Models that require max_completion_tokens instead of max_tokens.
	 *
	 * @var array
	 */
	private static $max_completion_tokens_models = array(
		'gpt-4o', 'gpt-4.1', 'gpt-5', 'o1', 'o3', 'o4', 'sora',
	);

	/**
	 * Normalize chat body: use max_completion_tokens for newer models.
	 *
	 * @param array  $body   Request body.
	 * @param string $model Model ID.
	 * @return array Modified body.
	 */
	private static function normalize_chat_body( $body, $model ) {
		if ( ! isset( $body['max_tokens'] ) ) {
			return $body;
		}
		$val = $body['max_tokens'];
		$use_new = false;
		foreach ( self::$max_completion_tokens_models as $prefix ) {
			if ( strpos( $model, $prefix ) === 0 ) {
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
	 * Send chat completion request to provider.
	 *
	 * @param string $provider Provider: openai, azure, google.
	 * @param array  $body     Request body.
	 * @return array|WP_Error Response or error.
	 */
	public static function chat( $provider, $body ) {
		$model = $body['model'] ?? '';
		$body  = self::normalize_chat_body( $body, $model );

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
				$endpoint = isset( $keys['azure_endpoint'] ) ? rtrim( trim( $keys['azure_endpoint'] ), '/' ) : '';
				$api_key  = isset( $keys['azure'] ) ? $keys['azure'] : '';
				if ( empty( $endpoint ) || empty( $api_key ) ) {
					return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
				}
				$model = $body['model'] ?? 'gpt-4o';
				$url   = $endpoint . '/openai/deployments/' . $model . '/chat/completions?api-version=2024-10-21';
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
	 * Whether the given size/model is a deployment name (gpt-image-*, dall-e-*).
	 *
	 * @param string $size Size or model ID.
	 * @return bool
	 */
	private static function is_image_model_name( $size ) {
		return strpos( $size, 'gpt-image' ) === 0 || strpos( $size, 'dall-e' ) === 0;
	}

	/**
	 * Generate images via DALL-E or GPT-image (OpenAI or Azure).
	 *
	 * @param string $prompt         Prompt.
	 * @param string $size           Dimensions (1024x1024, 1792x1024, 1024x1792, etc.).
	 * @param int    $n              Number of images (1-10).
	 * @param string|null $model     Model ID (gpt-image-1.5, dall-e-3). Default from options.
	 * @param string|null $quality   Quality (low, medium, high). Default from options. For gpt-image only.
	 * @param string|null $output_format Output format (png, jpeg). Default from options. For gpt-image only.
	 * @return array|WP_Error Response or error.
	 */
	public static function images( $prompt, $size = '1024x1024', $n = 1, $model = null, $quality = null, $output_format = null ) {
		$keys   = get_option( 'alorbach_api_keys', array() );
		$keys   = is_array( $keys ) ? $keys : array();
		$n      = min( 10, max( 1, (int) $n ) );
		$model  = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = $quality ?: get_option( 'alorbach_image_default_quality', 'medium' );
		$output_format = $output_format ?: get_option( 'alorbach_image_default_output_format', 'png' );

		$provider = self::get_provider_for_model( $model );

		if ( $provider === 'azure' ) {
			$endpoint = isset( $keys['azure_endpoint'] ) ? rtrim( trim( $keys['azure_endpoint'] ), '/' ) : '';
			$api_key  = isset( $keys['azure'] ) ? $keys['azure'] : '';
			if ( empty( $endpoint ) || empty( $api_key ) ) {
				return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
			}
			$url   = $endpoint . '/openai/deployments/' . $model . '/images/generations?api-version=2025-04-01-preview';
			$body  = array(
				'prompt' => $prompt,
				'n'      => $n,
				'size'   => $size,
			);
			if ( strpos( $model, 'gpt-image' ) === 0 ) {
				$body['quality']       = $quality;
				$body['output_format'] = $output_format;
			}
			$response = wp_remote_post( $url, array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'api-key'     => $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			) );
		} else {
			$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
			if ( empty( $api_key ) ) {
				return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
			}
			$body  = array(
				'model'  => $model,
				'prompt' => $prompt,
				'size'   => $size,
				'n'      => $n,
			);
			if ( strpos( $model, 'gpt-image' ) === 0 ) {
				$body['quality']       = $quality;
				$body['output_format'] = $output_format;
			}
			$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			) );
		}

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
	 * Transcribe audio via Whisper (OpenAI or Azure).
	 *
	 * @param string $file_path Path to audio file.
	 * @param string $model    Model (e.g. whisper-1, gpt-4o-transcribe). Default whisper-1.
	 * @param string $prompt   Optional prompt for context (spelling, style). Supported by Whisper.
	 * @return array|WP_Error Response with 'text' or error.
	 */
	public static function transcribe( $file_path, $model = 'whisper-1', $prompt = '' ) {
		$model    = $model ?: 'whisper-1';
		$prompt   = is_string( $prompt ) ? trim( $prompt ) : '';
		$keys     = get_option( 'alorbach_api_keys', array() );
		$keys     = is_array( $keys ) ? $keys : array();
		$provider = self::get_provider_for_model( $model );
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( 'invalid_file', __( 'Audio file not found.', 'alorbach-ai-gateway' ) );
		}

		$boundary = wp_generate_password( 24, false );
		$body     = '';
		// OpenAI requires model in form; Azure uses deployment in URL, only needs file.
		if ( $provider !== 'azure' ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n";
			$body .= $model . "\r\n";
			if ( $prompt !== '' ) {
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="prompt"' . "\r\n\r\n";
				$body .= $prompt . "\r\n";
			}
		} elseif ( $prompt !== '' ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="prompt"' . "\r\n\r\n";
			$body .= $prompt . "\r\n";
		}
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body .= file_get_contents( $file_path ) . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		if ( $provider === 'azure' ) {
			$endpoint = isset( $keys['azure_endpoint'] ) ? rtrim( trim( $keys['azure_endpoint'] ), '/' ) : '';
			$api_key  = isset( $keys['azure'] ) ? $keys['azure'] : '';
			if ( empty( $endpoint ) || empty( $api_key ) ) {
				return new \WP_Error( 'no_api_key', __( 'Azure OpenAI not configured.', 'alorbach-ai-gateway' ) );
			}
			$api_ver = ( strpos( $model, 'gpt-4o-transcribe' ) !== false || strpos( $model, 'gpt-4o-mini-transcribe' ) !== false )
				? '2025-04-01-preview'
				: '2024-02-01';
			$url = $endpoint . '/openai/deployments/' . $model . '/audio/transcriptions?api-version=' . $api_ver;
			$response = wp_remote_post( $url, array(
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'api-key'     => $api_key,
				),
				'body'    => $body,
				'timeout' => 120,
			) );
		} else {
			$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
			if ( empty( $api_key ) ) {
				return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'alorbach-ai-gateway' ) );
			}
			$response = wp_remote_post( 'https://api.openai.com/v1/audio/transcriptions', array(
				'headers' => array(
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => $body,
				'timeout' => 120,
			) );
		}

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

	/**
	 * Generate video via Sora (OpenAI Videos API).
	 *
	 * Creates a video job, polls until completed, then fetches the content URL.
	 * Returns WP_Error if API is not configured or generation fails.
	 *
	 * @param string $prompt Text prompt for video generation.
	 * @param string $model  Model ID (e.g. sora-2, sora-2-pro). Default sora-2.
	 * @return array|WP_Error Response with data[url] or error.
	 */
	public static function video( $prompt, $model = 'sora-2' ) {
		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		$api_key = isset( $keys['openai'] ) ? $keys['openai'] : '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured. Video generation (Sora) requires an OpenAI API key.', 'alorbach-ai-gateway' ) );
		}

		$body = array(
			'prompt' => $prompt,
			'model'  => $model,
			'size'   => '1280x720',
			'seconds' => '8',
		);

		$create_response = wp_remote_post(
			'https://api.openai.com/v1/videos',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $create_response ) ) {
			return $create_response;
		}

		$code = wp_remote_retrieve_response_code( $create_response );
		$create_body = json_decode( wp_remote_retrieve_body( $create_response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $create_body['error']['message'] ) ? $create_body['error']['message'] : __( 'Video generation API error.', 'alorbach-ai-gateway' );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}

		$video_id = isset( $create_body['id'] ) ? $create_body['id'] : '';
		if ( empty( $video_id ) ) {
			return new \WP_Error( 'api_error', __( 'No video ID returned from API.', 'alorbach-ai-gateway' ) );
		}

		// Poll until completed or failed (max ~5 minutes).
		$max_polls = 60;
		$poll_interval = 5;
		for ( $i = 0; $i < $max_polls; $i++ ) {
			sleep( $poll_interval );
			$get_response = wp_remote_get(
				'https://api.openai.com/v1/videos/' . $video_id,
				array(
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
					'timeout' => 30,
				)
			);
			if ( is_wp_error( $get_response ) ) {
				return $get_response;
			}
			$get_body = json_decode( wp_remote_retrieve_body( $get_response ), true );
			$status = isset( $get_body['status'] ) ? $get_body['status'] : '';
			if ( $status === 'failed' ) {
				$err_msg = isset( $get_body['error']['message'] ) ? $get_body['error']['message'] : __( 'Video generation failed.', 'alorbach-ai-gateway' );
				return new \WP_Error( 'api_error', $err_msg );
			}
			if ( $status === 'completed' ) {
				// Fetch content URL. The content endpoint may return a redirect to the actual video.
				$content_response = wp_remote_get(
					'https://api.openai.com/v1/videos/' . $video_id . '/content',
					array(
						'headers'   => array( 'Authorization' => 'Bearer ' . $api_key ),
						'timeout'   => 60,
						'redirection' => 0,
					)
				);
				if ( is_wp_error( $content_response ) ) {
					return $content_response;
				}
				$content_code = wp_remote_retrieve_response_code( $content_response );
				$location = wp_remote_retrieve_header( $content_response, 'location' );
				if ( ( $content_code === 301 || $content_code === 302 ) && ! empty( $location ) ) {
					return array(
						'data' => array( array( 'url' => $location ) ),
					);
				}
				return new \WP_Error( 'api_error', __( 'Could not retrieve video content URL from API.', 'alorbach-ai-gateway' ) );
			}
		}

		return new \WP_Error( 'timeout', __( 'Video generation timed out. The video may still be processing.', 'alorbach-ai-gateway' ) );
	}
}
