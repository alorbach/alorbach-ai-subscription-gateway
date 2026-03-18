<?php
/**
 * API client for AI providers (OpenAI, Azure, Google, GitHub Models).
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

use Alorbach\AIGateway\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Client
 */
class API_Client {

	/**
	 * Get the provider to use for a model based on configured API keys.
	 * GPT models work with OpenAI, Azure, or GitHub Models; uses whichever is configured (priority: openai > azure > github_models).
	 *
	 * @param string $model Model ID.
	 * @return string Provider: openai, azure, google, or github_models.
	 */
	public static function get_provider_for_model( $model ) {
		$helper = API_Keys_Helper::class;

		if ( strpos( $model, 'gemini' ) === 0 || strpos( $model, 'imagen-' ) === 0 || strpos( $model, 'veo-' ) === 0 ) {
			return 'google';
		}

		// GitHub Models uses publisher/model format (e.g. azure-openai/gpt-5, openai/gpt-4.1).
		if ( strpos( $model, '/' ) !== false && $helper::has_provider( 'github_models' ) ) {
			return 'github_models';
		}

		$gpt_like = ( strpos( $model, 'gpt' ) === 0 || strpos( $model, 'o1' ) === 0 || strpos( $model, 'o3' ) === 0 || strpos( $model, 'o4' ) === 0 );
		if ( $gpt_like ) {
			if ( $helper::has_provider( 'openai' ) ) {
				return 'openai';
			}
			if ( $helper::has_provider( 'azure' ) ) {
				return 'azure';
			}
			if ( $helper::has_provider( 'github_models' ) ) {
				return 'github_models';
			}
			return 'openai';
		}

		// Sora video: OpenAI or Azure (priority: provider with video support).
		if ( strpos( strtolower( $model ), 'sora' ) === 0 ) {
			$prov_openai = Provider_Registry::get( 'openai' );
			$prov_azure  = Provider_Registry::get( 'azure' );
			if ( $prov_openai && $prov_openai->supports_video() && $helper::has_provider( 'openai' ) ) {
				return 'openai';
			}
			if ( $prov_azure && $prov_azure->supports_video() && $helper::has_provider( 'azure' ) ) {
				return 'azure';
			}
			if ( $helper::has_provider( 'azure' ) ) {
				return 'azure';
			}
			return 'openai';
		}

		return $helper::has_provider( 'azure' ) ? 'azure' : 'openai';
	}

	/**
	 * Send chat completion request to provider.
	 *
	 * @param string $provider  Provider: openai, azure, google, github_models.
	 * @param array  $body      Request body.
	 * @param string $entry_id  Optional. When set, use credentials for this specific entry.
	 * @return array|WP_Error Response or error.
	 */
	public static function chat( $provider, $body, $entry_id = '' ) {
		$prov = Provider_Registry::get( $provider );
		if ( ! $prov || ! $prov->supports_chat() ) {
			return new \WP_Error( 'invalid_provider', __( 'Invalid or unsupported provider.', 'alorbach-ai-gateway' ) );
		}

		// When a specific entry is requested, use only that entry (no fallback).
		if ( ! empty( $entry_id ) ) {
			$creds = API_Keys_Helper::get_credentials_for_entry( $entry_id );
			if ( ! $creds ) {
				return new \WP_Error( 'no_api_key', __( 'API key not configured for this entry.', 'alorbach-ai-gateway' ) );
			}
			return self::execute_chat_request( $prov, $body, $creds, $provider );
		}

		// Try each enabled entry in order; fall back on retryable errors.
		$entries = API_Keys_Helper::get_all_entries_for_type( $provider );
		if ( empty( $entries ) ) {
			return new \WP_Error( 'no_api_key', __( 'API key not configured for this provider.', 'alorbach-ai-gateway' ) );
		}
		$last_error = null;
		foreach ( $entries as $entry ) {
			$creds = API_Keys_Helper::get_credentials_for_entry( $entry['id'] );
			if ( ! $creds ) {
				continue;
			}
			$result = self::execute_chat_request( $prov, $body, $creds, $provider );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;
			// Stop fallback on auth errors — the key is definitively invalid.
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			if ( $status === 401 || $status === 403 ) {
				break;
			}
		}
		return $last_error ?: new \WP_Error( 'no_api_key', __( 'No working API key found.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * Execute a single chat completion HTTP request.
	 *
	 * @param \Alorbach\AIGateway\Providers\Provider_Interface $prov     Provider instance.
	 * @param array  $body     Request body.
	 * @param array  $creds    Credentials array.
	 * @param string $provider Provider type identifier.
	 * @return array|\WP_Error Response or error.
	 */
	private static function execute_chat_request( $prov, $body, $creds, $provider ) {
		$request = $prov->build_chat_request( $body, $creds );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		$response = wp_remote_post( $request['url'], array(
			'headers' => $request['headers'],
			'body'    => $request['body'],
			'timeout' => 60,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code          = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$body_response = json_decode( $raw_body, true );
		if ( $code >= 400 ) {
			$msg = self::extract_api_error_message( $body_response, $raw_body, $code );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}
		if ( $provider === 'google' ) {
			// Check for prompt blocking (Gemini returns 200 with promptFeedback when prompt is blocked).
			$feedback = $body_response['promptFeedback'] ?? null;
			if ( is_array( $feedback ) && ! empty( $feedback['blockReason'] ) ) {
				$reason = $feedback['blockReason'] ?? 'BLOCKED';
				$msg    = isset( $feedback['blockReasonMessage'] ) ? $feedback['blockReasonMessage'] : sprintf( __( 'Prompt blocked (%s)', 'alorbach-ai-gateway' ), $reason );
				return new \WP_Error( 'api_error', $msg, array( 'status' => 400 ) );
			}
			$body_response = self::normalize_gemini_response( $body_response );
		}
		return $body_response;
	}

	/**
	 * Detect audio format from file bytes (magic bytes).
	 *
	 * @param string $bytes Raw audio file content.
	 * @return string Format: wav, mp3, flac, opus, m4a, webm, or wav as fallback.
	 */
	public static function detect_audio_format( $bytes ) {
		$len = strlen( $bytes );
		if ( $len < 12 ) {
			return 'wav';
		}
		// RIFF....WAVE
		if ( substr( $bytes, 0, 4 ) === 'RIFF' && substr( $bytes, 8, 4 ) === 'WAVE' ) {
			return 'wav';
		}
		// ID3 (MP3 with ID3 tag)
		if ( substr( $bytes, 0, 3 ) === 'ID3' ) {
			return 'mp3';
		}
		// MP3 frame sync (FF FB or FF FA)
		if ( $len >= 2 && ( $bytes[0] === "\xFF" && ( $bytes[1] === "\xFB" || $bytes[1] === "\xFA" ) ) ) {
			return 'mp3';
		}
		// fLaC
		if ( substr( $bytes, 0, 4 ) === 'fLaC' ) {
			return 'flac';
		}
		// OggS (Ogg/Opus)
		if ( substr( $bytes, 0, 4 ) === 'OggS' ) {
			return 'opus';
		}
		// EBML (WebM)
		if ( substr( $bytes, 0, 4 ) === "\x1A\x45\xDF\xA3" ) {
			return 'webm';
		}
		// ftyp at offset 4 (MP4/M4A)
		if ( $len >= 8 && substr( $bytes, 4, 4 ) === 'ftyp' ) {
			return 'm4a';
		}
		return 'wav';
	}

	/**
	 * Extract error message from API response body (handles various formats).
	 *
	 * @param array|null $body     Parsed JSON body.
	 * @param string     $raw_body Raw response body.
	 * @param int        $code    HTTP status code.
	 * @return string Error message.
	 */
	private static function extract_api_error_message( $body, $raw_body, $code ) {
		// Never leak auth error details to callers.
		if ( $code === 401 || $code === 403 ) {
			return __( 'Authentication failed. Please check the API key configuration.', 'alorbach-ai-gateway' );
		}
		if ( is_array( $body ) ) {
			if ( ! empty( $body['error']['message'] ) ) {
				return (string) $body['error']['message'];
			}
			if ( ! empty( $body['message'] ) ) {
				return (string) $body['message'];
			}
			if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
				return $body['error'];
			}
			if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
				$first = reset( $body['errors'] );
				$msg = is_array( $first ) && isset( $first['message'] ) ? $first['message'] : ( is_string( $first ) ? $first : wp_json_encode( $first ) );
				return $msg;
			}
		}
		$raw = trim( (string) $raw_body );
		if ( $raw !== '' && strlen( $raw ) < 500 ) {
			return sprintf( __( 'HTTP %1$d: %2$s', 'alorbach-ai-gateway' ), $code, $raw );
		}
		return sprintf( __( 'API error (HTTP %d)', 'alorbach-ai-gateway' ), $code );
	}

	/**
	 * Normalize Gemini API response to OpenAI-style format.
	 *
	 * @param array $body Gemini response.
	 * @return array OpenAI-style { choices: [{ message: { content } }], usage?: {} }.
	 */
	private static function normalize_gemini_response( $body ) {
		$content = '';
		$candidates = $body['candidates'] ?? array();
		if ( ! empty( $candidates[0]['content']['parts'] ) ) {
			foreach ( $candidates[0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$content .= $part['text'];
				}
			}
		}
		$usage = $body['usageMetadata'] ?? array();
		return array(
			'choices' => array(
				array(
					'message' => array( 'content' => $content ),
					'index'   => 0,
				),
			),
			'usage'   => array(
				'prompt_tokens'     => isset( $usage['promptTokenCount'] ) ? (int) $usage['promptTokenCount'] : 0,
				'completion_tokens'  => isset( $usage['candidatesTokenCount'] ) ? (int) $usage['candidatesTokenCount'] : 0,
				'total_tokens'       => ( isset( $usage['promptTokenCount'] ) ? (int) $usage['promptTokenCount'] : 0 ) + ( isset( $usage['candidatesTokenCount'] ) ? (int) $usage['candidatesTokenCount'] : 0 ),
			),
		);
	}

	/**
	 * Generate images via DALL-E or GPT-image (OpenAI or Azure).
	 *
	 * @param string $prompt         Prompt.
	 * @param string $size           Dimensions (1024x1024, etc.).
	 * @param int    $n              Number of images (1-10).
	 * @param string|null $model     Model ID. Default from options.
	 * @param string|null $quality   Quality. Default from options.
	 * @param string|null $output_format Output format. Default from options.
	 * @return array|WP_Error Response or error.
	 */
	public static function images( $prompt, $size = '1024x1024', $n = 1, $model = null, $quality = null, $output_format = null ) {
		$n      = min( 10, max( 1, (int) $n ) );
		$model  = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = $quality ?: get_option( 'alorbach_image_default_quality', 'medium' );
		$output_format = $output_format ?: get_option( 'alorbach_image_default_output_format', 'png' );

		$provider = self::get_provider_for_model( $model );
		$prov     = Provider_Registry::get( $provider );
		if ( ! $prov || ! $prov->supports_images() ) {
			return new \WP_Error( 'no_provider', __( 'No image provider configured.', 'alorbach-ai-gateway' ) );
		}
		$creds = API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return new \WP_Error( 'no_api_key', __( 'API key not configured.', 'alorbach-ai-gateway' ) );
		}
		$request = $prov->build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $creds );
		if ( ! $request || is_wp_error( $request ) ) {
			return $request ?: new \WP_Error( 'no_provider', __( 'Image generation not supported.', 'alorbach-ai-gateway' ) );
		}
		$response = wp_remote_post( $request['url'], array(
			'headers' => $request['headers'],
			'body'    => $request['body'],
			'timeout' => 120,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$body_response = json_decode( $raw_body, true );
		if ( $code >= 400 ) {
			$msg = self::extract_api_error_message( $body_response, $raw_body, $code );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}
		// Normalize Google Imagen response to OpenAI-style format.
		if ( isset( $body_response['predictions'] ) && is_array( $body_response['predictions'] ) ) {
			$data = array();
			foreach ( $body_response['predictions'] as $pred ) {
				$b64 = isset( $pred['bytesBase64Encoded'] ) ? $pred['bytesBase64Encoded'] : ( isset( $pred['image']['imageBytes'] ) ? $pred['image']['imageBytes'] : '' );
				if ( $b64 ) {
					$data[] = array( 'b64_json' => $b64 );
				}
			}
			$body_response = array( 'data' => $data );
		}
		return $body_response;
	}

	/**
	 * Transcribe audio via Whisper (OpenAI or Azure).
	 *
	 * @param string      $file_path Path to audio file.
	 * @param string      $model    Model (e.g. whisper-1, gpt-4o-transcribe).
	 * @param string      $prompt   Optional prompt.
	 * @param string|null $format   Optional format (wav, mp3, flac, opus, m4a, webm). Auto-detected from path if null.
	 * @return array|WP_Error Response with 'text' or error.
	 */
	public static function transcribe( $file_path, $model = 'whisper-1', $prompt = '', $format = null ) {
		$model  = $model ?: 'whisper-1';
		$prompt = is_string( $prompt ) ? trim( $prompt ) : '';
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( 'invalid_file', __( 'Audio file not found.', 'alorbach-ai-gateway' ) );
		}
		$provider = self::get_provider_for_model( $model );
		$prov     = Provider_Registry::get( $provider );
		if ( ! $prov || ! $prov->supports_audio() ) {
			return new \WP_Error( 'no_provider', __( 'No audio provider configured.', 'alorbach-ai-gateway' ) );
		}
		$creds = API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return new \WP_Error( 'no_api_key', __( 'API key not configured.', 'alorbach-ai-gateway' ) );
		}
		$request = $prov->build_transcribe_request( $file_path, $model, $prompt, $creds, $format );
		if ( ! $request || is_wp_error( $request ) ) {
			return $request ?: new \WP_Error( 'no_provider', __( 'Transcription not supported.', 'alorbach-ai-gateway' ) );
		}
		$response = wp_remote_post( $request['url'], array(
			'headers' => $request['headers'],
			'body'    => $request['body'],
			'timeout' => 120,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$body_response = json_decode( $raw_body, true );
		if ( $code >= 400 ) {
			$msg = self::extract_api_error_message( $body_response, $raw_body, $code );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}
		// gpt-audio uses chat completions; response has choices[0].message.content.
		if ( ! empty( $request['response_format'] ) && $request['response_format'] === 'chat_completions' ) {
			$text = isset( $body_response['choices'][0]['message']['content'] )
				? (string) $body_response['choices'][0]['message']['content']
				: '';
			return array( 'text' => $text );
		}
		return is_array( $body_response ) ? $body_response : array( 'text' => (string) $raw_body );
	}

	/**
	 * Create video job via Sora (OpenAI or Azure) or Veo (Google). Returns job ID immediately without polling.
	 * Use for quick verification that the API accepts the request.
	 *
	 * @param string $prompt           Text prompt.
	 * @param string $model           Model ID (e.g. sora-2, veo-3.1-generate-preview).
	 * @param string $size            Size (e.g. 1280x720). Default 1280x720.
	 * @param int    $duration_seconds Duration in seconds (4, 8, or 12). Default 8.
	 * @return array|WP_Error Array with 'id' => job_id, 'provider' => provider, or WP_Error.
	 */
	public static function create_video( $prompt, $model = 'sora-2', $size = '1280x720', $duration_seconds = 8 ) {
		$provider = self::get_provider_for_model( $model );
		$prov     = Provider_Registry::get( $provider );
		if ( ! $prov || ! $prov->supports_video() ) {
			return new \WP_Error( 'no_api_key', sprintf(
				/* translators: 1: provider name */
				__( 'API key not configured for %1$s. Video generation requires a configured API key for the model\'s provider.', 'alorbach-ai-gateway' ),
				$provider === 'openai' ? 'OpenAI' : ( $provider === 'azure' ? 'Azure' : ( $provider === 'google' ? 'Google' : $provider ) )
			) );
		}
		$creds = API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return new \WP_Error( 'no_api_key', sprintf(
				/* translators: 1: provider name */
				__( 'API key not configured for %1$s. Video generation requires a configured API key for the model\'s provider.', 'alorbach-ai-gateway' ),
				$provider === 'openai' ? 'OpenAI' : ( $provider === 'azure' ? 'Azure' : ( $provider === 'google' ? 'Google' : $provider ) )
			) );
		}
		$request = $prov->build_video_request( $prompt, $model, $size, $duration_seconds, $creds );
		if ( ! $request || is_wp_error( $request ) ) {
			return $request ?: new \WP_Error( 'no_api_key', __( 'Video generation not configured for this provider.', 'alorbach-ai-gateway' ) );
		}
		$create_response = wp_remote_post( $request['url'], array(
			'headers' => $request['headers'],
			'body'    => $request['body'],
			'timeout' => 30,
		) );
		if ( is_wp_error( $create_response ) ) {
			return $create_response;
		}
		$code = wp_remote_retrieve_response_code( $create_response );
		$raw_body = wp_remote_retrieve_body( $create_response );
		$create_body = json_decode( $raw_body, true );
		if ( $code >= 400 ) {
			$msg = self::extract_api_error_message( $create_body, $raw_body, $code );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}
		$video_id = $create_body['id'] ?? '';
		if ( empty( $video_id ) ) {
			return new \WP_Error( 'api_error', __( 'No video ID returned from API.', 'alorbach-ai-gateway' ) );
		}
		return array( 'id' => $video_id, 'provider' => $provider );
	}

	/**
	 * Generate video via Sora (OpenAI or Azure) or Veo (Google).
	 *
	 * @param string $prompt           Text prompt.
	 * @param string $model            Model ID (e.g. sora-2).
	 * @param string $size             Size (e.g. 1280x720). Default 1280x720.
	 * @param int    $duration_seconds Duration in seconds (4, 8, or 12). Default 8.
	 * @return array|WP_Error Response with data[url] or error.
	 */
	public static function video( $prompt, $model = 'sora-2', $size = '1280x720', $duration_seconds = 8 ) {
		// Video polling can take several minutes; disable the PHP execution time limit.
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$create_result = self::create_video( $prompt, $model, $size, $duration_seconds );
		if ( is_wp_error( $create_result ) ) {
			return $create_result;
		}
		$video_id  = $create_result['id'] ?? '';
		$provider  = $create_result['provider'] ?? 'openai';
		$creds     = API_Keys_Helper::get_credentials_for_provider( $provider );
		$max_polls = 60;
		$poll_interval = 5;

		// Azure Sora 2 and OpenAI both use /v1/videos endpoint; Azure uses endpoint + api-key.
		$base_url = ( $provider === 'azure' && ! empty( $creds['endpoint'] ) )
			? rtrim( $creds['endpoint'], '/' ) . '/openai/v1/videos'
			: 'https://api.openai.com/v1/videos';
		$auth_header = ( $provider === 'azure' )
			? array( 'api-key' => $creds['api_key'] ?? '' )
			: array( 'Authorization' => 'Bearer ' . ( $creds['api_key'] ?? '' ) );

		$debug_enabled = (bool) get_option( 'alorbach_debug_enabled', false ) && current_user_can( 'manage_options' );

		for ( $i = 0; $i < $max_polls; $i++ ) {
			sleep( $poll_interval );
			$get_response = wp_remote_get(
				$base_url . '/' . $video_id,
				array(
					'headers' => $auth_header,
					'timeout' => 30,
				)
			);
			if ( is_wp_error( $get_response ) ) {
				return $get_response;
			}
			$get_body = json_decode( wp_remote_retrieve_body( $get_response ), true );
			$status = $get_body['status'] ?? '';
			// Azure Sora 1 uses "succeeded"; Sora 2 / OpenAI use "completed".
			if ( $status === 'failed' ) {
				$err_msg = $get_body['error']['message'] ?? __( 'Video generation failed.', 'alorbach-ai-gateway' );
				return new \WP_Error( 'api_error', $err_msg );
			}
			if ( $status === 'completed' || $status === 'succeeded' ) {
				$content_response = wp_remote_get(
					$base_url . '/' . $video_id . '/content',
					array(
						'headers'     => $auth_header,
						'timeout'     => 60,
						'redirection' => 0,
					)
				);
				if ( is_wp_error( $content_response ) ) {
					return $content_response;
				}
				$content_code   = wp_remote_retrieve_response_code( $content_response );
				$content_body   = wp_remote_retrieve_body( $content_response );
				$location       = wp_remote_retrieve_header( $content_response, 'location' );
				$content_type   = wp_remote_retrieve_header( $content_response, 'content-type' );

				// Redirect (301/302) with Location header.
				if ( ( $content_code === 301 || $content_code === 302 ) && ! empty( $location ) ) {
					return array( 'data' => array( array( 'url' => $location ) ) );
				}

				// 200 with video stream (OpenAI/Azure Sora 2 return binary directly).
				if ( $content_code === 200 && ! empty( $content_body ) ) {
					$is_json = strpos( (string) $content_type, 'application/json' ) !== false;
					$is_video = ! $is_json && ( strpos( (string) $content_type, 'video/' ) === 0 || strlen( $content_body ) > 1000 );
					if ( $is_video ) {
						$upload = wp_upload_bits( 'alorbach-video-' . wp_unique_id() . '.mp4', false, $content_body );
						if ( empty( $upload['error'] ) && ! empty( $upload['url'] ) ) {
							return array( 'data' => array( array( 'url' => $upload['url'] ) ) );
						}
					}
				}

				// Failure: attach debug info when enabled.
				$err_data = array( 'status' => 500 );
				if ( $debug_enabled ) {
					$body_preview = is_string( $content_body ) ? substr( $content_body, 0, 500 ) : '';
					if ( function_exists( 'mb_substr' ) && mb_strlen( $body_preview ) > 200 ) {
						$body_preview = mb_substr( $body_preview, 0, 200 ) . '...';
					} elseif ( strlen( $body_preview ) > 200 ) {
						$body_preview = substr( $body_preview, 0, 200 ) . '...';
					}
					$err_data['debug'] = array(
						'provider'       => $provider,
						'video_id'       => $video_id,
						'content_code'   => $content_code,
						'content_type'   => $content_type,
						'has_location'   => ! empty( $location ),
						'location'       => $location ?: null,
						'body_length'    => is_string( $content_body ) ? strlen( $content_body ) : 0,
						'body_preview'   => $body_preview,
						'poll_status'    => $status,
						'poll_response'  => $get_body,
					);
				}
				return new \WP_Error( 'api_error', __( 'Could not retrieve video content URL from API.', 'alorbach-ai-gateway' ), $err_data );
			}
		}
		return new \WP_Error( 'timeout', __( 'Video generation timed out. The video may still be processing.', 'alorbach-ai-gateway' ) );
	}

}
