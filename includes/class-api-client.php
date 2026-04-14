<?php
/**
 * API client for AI providers (OpenAI, Azure, Google, GitHub Models).
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

use Alorbach\AIGateway\Providers\Hugging_Face_Spaces_Provider;
use Alorbach\AIGateway\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Client
 */
class API_Client {

	/**
	 * Whether a model/provider combination supports partial image streaming.
	 *
	 * @param string      $model    Model ID.
	 * @param string|null $provider Optional provider override.
	 * @return bool
	 */
	public static function supports_partial_image_streaming( $model, $provider = null ) {
		$model    = (string) $model;
		$provider = $provider ?: self::get_provider_for_model( $model );

		if ( strpos( $model, 'gpt-image' ) !== 0 ) {
			return false;
		}

		if ( ! in_array( $provider, array( 'openai', 'azure' ), true ) ) {
			return false;
		}

		return function_exists( 'curl_init' );
	}

	/**
	 * Get the provider to use for a model based on configured API keys.
	 * GPT models work with OpenAI, Azure, or GitHub Models; uses whichever is configured (priority: openai > azure > github_models).
	 *
	 * @param string $model Model ID.
	 * @return string Provider: openai, azure, google, or github_models.
	 */
	public static function get_provider_for_model( $model ) {
		$helper = API_Keys_Helper::class;

		if ( strpos( (string) $model, 'hf-space:' ) === 0 && $helper::has_provider( 'huggingface_spaces' ) ) {
			return 'huggingface_spaces';
		}

		if ( strpos( $model, 'gemini' ) === 0 || strpos( $model, 'imagen-' ) === 0 || strpos( $model, 'veo-' ) === 0 ) {
			return 'google';
		}

		$imported_provider = self::get_provider_for_imported_model( $model );
		if ( $imported_provider && $helper::has_provider( $imported_provider ) ) {
			return $imported_provider;
		}

		// Hugging Face router models may use publisher/model:policy or publisher/model:provider.
		if ( strpos( $model, '/' ) !== false && strpos( $model, ':' ) !== false && $helper::has_provider( 'huggingface' ) ) {
			return 'huggingface';
		}

		// GitHub Models uses publisher/model format (e.g. azure-openai/gpt-5, openai/gpt-4.1).
		if ( strpos( $model, '/' ) !== false && $helper::has_provider( 'github_models' ) ) {
			return 'github_models';
		}

		if ( strpos( $model, '/' ) !== false && $helper::has_provider( 'huggingface' ) ) {
			return 'huggingface';
		}

		// Codex models: route to the dedicated OAuth provider when available.
		if ( strpos( $model, 'codex' ) !== false && $helper::has_provider( 'codex' ) ) {
			return 'codex';
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
	 * Resolve a provider from imported model metadata when possible.
	 *
	 * @param string $model Model ID.
	 * @return string|null
	 */
	private static function get_provider_for_imported_model( $model ) {
		$lookup_ids = array( (string) $model );
		if ( strpos( $model, '/' ) !== false && strpos( $model, ':' ) !== false ) {
			$lookup_ids[] = preg_replace( '/:[^\/:]+$/', '', (string) $model );
		}

		$cost_matrix = Cost_Matrix::get_cost_matrix();
		$rows        = isset( $cost_matrix['models'] ) && is_array( $cost_matrix['models'] ) ? $cost_matrix['models'] : array();
		foreach ( $lookup_ids as $lookup_id ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['model'] ) || $row['model'] !== $lookup_id ) {
					continue;
				}
				$entry_id = isset( $row['entry_id'] ) ? (string) $row['entry_id'] : '';
				if ( '' === $entry_id || $entry_id === 'legacy' ) {
					continue;
				}
				$entry = API_Keys_Helper::get_entry_by_id( $entry_id );
				if ( $entry && ! empty( $entry['enabled'] ) && ! empty( $entry['type'] ) ) {
					return (string) $entry['type'];
				}
			}
		}

		$image_models = get_option( 'alorbach_image_models', array() );
		$image_models = is_array( $image_models ) ? $image_models : array();
		foreach ( $lookup_ids as $lookup_id ) {
			if ( ! in_array( $lookup_id, $image_models, true ) ) {
				continue;
			}
			if ( strpos( $lookup_id, 'hf-space:' ) === 0 && API_Keys_Helper::has_provider( 'huggingface_spaces' ) ) {
				return 'huggingface_spaces';
			}
			if ( strpos( $lookup_id, 'gemini-' ) === 0 || strpos( $lookup_id, 'imagen-' ) === 0 ) {
				return 'google';
			}
			if ( strpos( $lookup_id, 'gpt-image' ) === 0 || strpos( $lookup_id, 'dall-e' ) === 0 ) {
				return API_Keys_Helper::has_provider( 'openai' ) ? 'openai' : ( API_Keys_Helper::has_provider( 'azure' ) ? 'azure' : null );
			}
			if ( strpos( $lookup_id, '/' ) !== false && API_Keys_Helper::has_provider( 'huggingface' ) ) {
				return 'huggingface';
			}
		}

		$video_costs = get_option( 'alorbach_video_costs', array() );
		$video_costs = is_array( $video_costs ) ? $video_costs : array();
		foreach ( $lookup_ids as $lookup_id ) {
			if ( ! array_key_exists( $lookup_id, $video_costs ) ) {
				continue;
			}
			if ( strpos( strtolower( $lookup_id ), 'veo-' ) === 0 ) {
				return 'google';
			}
			if ( strpos( strtolower( $lookup_id ), 'sora' ) === 0 ) {
				return API_Keys_Helper::has_provider( 'openai' ) ? 'openai' : ( API_Keys_Helper::has_provider( 'azure' ) ? 'azure' : null );
			}
			if ( strpos( $lookup_id, '/' ) !== false && API_Keys_Helper::has_provider( 'huggingface' ) ) {
				return 'huggingface';
			}
		}

		$audio_costs = get_option( 'alorbach_audio_costs', array() );
		$audio_costs = is_array( $audio_costs ) ? $audio_costs : array();
		foreach ( $lookup_ids as $lookup_id ) {
			if ( ! array_key_exists( $lookup_id, $audio_costs ) ) {
				continue;
			}
			if ( strpos( $lookup_id, 'whisper-' ) === 0 || strpos( $lookup_id, 'gpt-' ) === 0 ) {
				return API_Keys_Helper::has_provider( 'openai' ) ? 'openai' : ( API_Keys_Helper::has_provider( 'azure' ) ? 'azure' : null );
			}
			if ( strpos( $lookup_id, '/' ) !== false && API_Keys_Helper::has_provider( 'huggingface' ) ) {
				return 'huggingface';
			}
		}

		return null;
	}

	/**
	 * Format provider name for user-facing messages.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	private static function get_provider_label( $provider ) {
		switch ( (string) $provider ) {
			case 'openai':
				return 'OpenAI';
			case 'azure':
				return 'Azure';
			case 'google':
				return 'Google';
			case 'github_models':
				return 'GitHub Models';
			case 'huggingface':
				return 'Hugging Face';
			case 'huggingface_spaces':
				return 'Hugging Face Spaces';
			default:
				return (string) $provider;
		}
	}

	/**
	 * Resolve an imported model to its configured entry ID.
	 *
	 * @param string $model Model ID.
	 * @return string
	 */
	private static function get_entry_id_for_imported_model( $model ) {
		$lookup_ids = array( (string) $model );
		if ( strpos( $model, '/' ) !== false && strpos( $model, ':' ) !== false ) {
			$lookup_ids[] = preg_replace( '/:[^\/:]+$/', '', (string) $model );
		}

		$cost_matrix = Cost_Matrix::get_cost_matrix();
		$rows        = isset( $cost_matrix['models'] ) && is_array( $cost_matrix['models'] ) ? $cost_matrix['models'] : array();
		foreach ( $lookup_ids as $lookup_id ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['model'] ) || $row['model'] !== $lookup_id ) {
					continue;
				}
				$entry_id = isset( $row['entry_id'] ) ? (string) $row['entry_id'] : '';
				if ( '' !== $entry_id && 'legacy' !== $entry_id ) {
					return $entry_id;
				}
			}
		}

		return '';
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
		if ( $provider === 'codex' ) {
			$body_response = self::parse_codex_sse( $raw_body );
			if ( is_wp_error( $body_response ) ) {
				return $body_response;
			}
			$body_response = self::normalize_codex_response( $body_response );
			return $body_response;
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
	 * Parse a Codex SSE stream and return the completed response object.
	 *
	 * @param string $raw Raw SSE response body.
	 * @return array|\WP_Error Completed response array or error.
	 */
	private static function parse_codex_sse( $raw ) {
		$completed = null;
		$lines = explode( "\n", $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strncmp( $line, 'data: ', 6 ) !== 0 ) {
				continue;
			}
			$json = substr( $line, 6 );
			if ( $json === '[DONE]' ) {
				break;
			}
			$event = json_decode( $json, true );
			if ( ! is_array( $event ) ) {
				continue;
			}
			// The response.completed event carries the full response object.
			if ( isset( $event['type'] ) && $event['type'] === 'response.completed' && isset( $event['response'] ) ) {
				$completed = $event['response'];
			}
		}
		if ( $completed === null ) {
			return new \WP_Error( 'codex_sse_error', __( 'No completed response in Codex SSE stream.', 'alorbach-ai-gateway' ) );
		}
		return $completed;
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
	public static function extract_api_error_message( $body, $raw_body, $code ) {
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
	 * Normalize an OpenAI Responses API response to Chat Completions format.
	 *
	 * @param array $body Decoded Responses API response body.
	 * @return array Chat Completions-compatible response.
	 */
	private static function normalize_codex_response( $body ) {
		if ( ! is_array( $body ) ) {
			return $body;
		}

		// Extract assistant text from output[] items of type 'message'.
		$content     = '';
		$finish      = 'stop';
		$output_list = isset( $body['output'] ) && is_array( $body['output'] ) ? $body['output'] : array();
		foreach ( $output_list as $item ) {
			if ( ! is_array( $item ) || ( isset( $item['type'] ) ? $item['type'] : '' ) !== 'message' ) {
				continue;
			}
			$content_parts = isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array();
			foreach ( $content_parts as $part ) {
				if ( is_array( $part ) && ( isset( $part['type'] ) ? $part['type'] : '' ) === 'output_text' ) {
					$content .= isset( $part['text'] ) ? $part['text'] : '';
				}
			}
		}

		// Map Responses API status to finish_reason.
		$status = isset( $body['status'] ) ? $body['status'] : 'completed';
		if ( $status === 'incomplete' ) {
			$finish = 'length';
		}

		// Normalize usage field names.
		$usage     = isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array();
		$input_tok = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$out_tok   = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
		$total_tok = isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : $input_tok + $out_tok;
		$cached    = isset( $usage['input_tokens_details']['cached_tokens'] )
			? (int) $usage['input_tokens_details']['cached_tokens'] : 0;

		return array(
			'id'      => isset( $body['id'] ) ? $body['id'] : '',
			'object'  => 'chat.completion',
			'model'   => isset( $body['model'] ) ? $body['model'] : '',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array( 'role' => 'assistant', 'content' => $content ),
					'finish_reason' => $finish,
				),
			),
			'usage'   => array(
				'prompt_tokens'         => $input_tok,
				'completion_tokens'     => $out_tok,
				'total_tokens'          => $total_tok,
				'prompt_tokens_details' => array( 'cached_tokens' => $cached ),
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
	public static function images( $prompt, $size = '1024x1024', $n = 1, $model = null, $quality = null, $output_format = null, $reference_images = array() ) {
		$n      = min( 10, max( 1, (int) $n ) );
		$model  = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = $quality ?: get_option( 'alorbach_image_default_quality', 'medium' );
		$output_format = $output_format ?: get_option( 'alorbach_image_default_output_format', 'png' );

		$provider = self::get_provider_for_model( $model );
		$prov     = Provider_Registry::get( $provider );
		if ( ! $prov || ! $prov->supports_images() ) {
			return new \WP_Error( 'no_provider', __( 'No image provider configured.', 'alorbach-ai-gateway' ) );
		}
		$entry_id = self::get_entry_id_for_imported_model( $model );
		$creds = '' !== $entry_id ? API_Keys_Helper::get_credentials_for_entry( $entry_id ) : API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return new \WP_Error( 'no_api_key', __( 'API key not configured.', 'alorbach-ai-gateway' ) );
		}
		if ( in_array( $provider, array( 'huggingface', 'huggingface_spaces' ), true ) && $n > 1 ) {
			$merged = array( 'data' => array() );
			for ( $index = 0; $index < $n; $index++ ) {
				$request = $prov->build_images_request( $prompt, $size, 1, $model, $quality, $output_format, $creds, $reference_images );
				if ( ! $request || is_wp_error( $request ) ) {
					return $request ?: new \WP_Error( 'no_provider', __( 'Image generation not supported.', 'alorbach-ai-gateway' ) );
				}
				$response = self::execute_image_request( $request );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				if ( ! empty( $response['data'] ) && is_array( $response['data'] ) ) {
					$merged['data'] = array_merge( $merged['data'], $response['data'] );
				}
			}
			return $merged;
		}
		$request = $prov->build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $creds, $reference_images );
		if ( ! $request || is_wp_error( $request ) ) {
			return $request ?: new \WP_Error( 'no_provider', __( 'Image generation not supported.', 'alorbach-ai-gateway' ) );
		}
		return self::execute_image_request( $request );
	}

	/**
	 * Execute one image-generation request and normalize the provider response.
	 *
	 * @param array $request Request array from the provider.
	 * @return array|\WP_Error
	 */
	private static function execute_image_request( $request ) {
		$response = wp_remote_post( $request['url'], array(
			'headers' => $request['headers'],
			'body'    => $request['body'],
			'timeout' => 120,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$raw_body = wp_remote_retrieve_body( $response );
		$body_response = json_decode( $raw_body, true );
		if ( $code >= 400 ) {
			$msg = self::extract_api_error_message( $body_response, $raw_body, $code );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}
		if ( strpos( strtolower( $content_type ), 'image/' ) === 0 && $raw_body !== '' ) {
			return array(
				'data' => array(
					array(
						'b64_json' => base64_encode( $raw_body ),
					),
				),
			);
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
	 * Stream image generation and emit partial previews when available.
	 *
	 * @param string        $prompt        Prompt.
	 * @param string        $size          Size.
	 * @param int           $n             Number of images.
	 * @param string|null   $model         Model.
	 * @param string|null   $quality       Quality.
	 * @param string|null   $output_format Output format.
	 * @param callable|null $on_event      Optional callback receiving event payloads.
	 * @return array|\WP_Error
	 */
	public static function stream_images( $prompt, $size = '1024x1024', $n = 1, $model = null, $quality = null, $output_format = null, $on_event = null, $reference_images = array() ) {
		$n             = min( 10, max( 1, (int) $n ) );
		$model         = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality       = $quality ?: get_option( 'alorbach_image_default_quality', 'medium' );
		$output_format = $output_format ?: get_option( 'alorbach_image_default_output_format', 'png' );
		$provider      = self::get_provider_for_model( $model );

		if ( ! empty( $reference_images ) || ! self::supports_partial_image_streaming( $model, $provider ) ) {
			return new \WP_Error( 'stream_not_supported', __( 'Partial image streaming is not supported for this model.', 'alorbach-ai-gateway' ) );
		}

		$prov = Provider_Registry::get( $provider );
		if ( ! $prov || ! $prov->supports_images() ) {
			return new \WP_Error( 'no_provider', __( 'No image provider configured.', 'alorbach-ai-gateway' ) );
		}

		$entry_id = self::get_entry_id_for_imported_model( $model );
		$creds = '' !== $entry_id ? API_Keys_Helper::get_credentials_for_entry( $entry_id ) : API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return new \WP_Error( 'no_api_key', __( 'API key not configured.', 'alorbach-ai-gateway' ) );
		}

		$request = $prov->build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $creds, $reference_images );
		if ( ! $request || is_wp_error( $request ) ) {
			return $request ?: new \WP_Error( 'no_provider', __( 'Image generation not supported.', 'alorbach-ai-gateway' ) );
		}

		$body = json_decode( $request['body'], true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_stream_request', __( 'Could not prepare streaming image request.', 'alorbach-ai-gateway' ) );
		}
		$body['stream']         = true;
		$body['partial_images'] = 3;
		$request['body']        = wp_json_encode( $body );
		$request['headers']['Accept'] = 'text/event-stream';

		$state = array(
			'preview_images' => array(),
			'final_images'   => array(),
			'usage'          => null,
			'raw'            => '',
		);
		$buffer = '';

		$ch = curl_init( $request['url'] );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, self::curl_headers( $request['headers'] ) );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $request['body'] );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $chunk ) use ( &$buffer, &$state, $on_event ) {
				$state['raw'] .= $chunk;
				$buffer       .= $chunk;

				while ( null !== ( $event = self::shift_stream_block( $buffer ) ) ) {
					self::consume_image_stream_event( $event, $state, $on_event );
				}

				return strlen( $chunk );
			}
		);

		$ok = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( $buffer !== '' ) {
			self::consume_image_stream_event( $buffer, $state, $on_event );
		}

		if ( false === $ok || $errno ) {
			return new \WP_Error( 'curl_stream_error', $error ?: __( 'Image stream failed.', 'alorbach-ai-gateway' ), array( 'status' => 502 ) );
		}

		if ( $code >= 400 ) {
			$body_response = json_decode( $state['raw'], true );
			$msg = self::extract_api_error_message( $body_response, $state['raw'], $code );
			return new \WP_Error( 'api_error', $msg, array( 'status' => $code ) );
		}

		if ( empty( $state['final_images'] ) ) {
			$decoded = json_decode( trim( $state['raw'] ), true );
			if ( is_array( $decoded ) ) {
				$final = self::extract_image_items_from_payload( $decoded );
				if ( ! empty( $final ) ) {
					$state['final_images'] = $final;
				}
				if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
					$state['usage'] = $decoded['usage'];
				}
			}
		}

		return array(
			'data'           => $state['final_images'],
			'preview_images' => $state['preview_images'],
			'usage'          => $state['usage'],
		);
	}

	/**
	 * Shift one SSE block from a stream buffer.
	 *
	 * @param string $buffer Mutable buffer.
	 * @return string|null
	 */
	private static function shift_stream_block( &$buffer ) {
		$positions = array();
		foreach ( array( "\r\n\r\n", "\n\n" ) as $delimiter ) {
			$pos = strpos( $buffer, $delimiter );
			if ( false !== $pos ) {
				$positions[] = array(
					'pos' => $pos,
					'len' => strlen( $delimiter ),
				);
			}
		}
		if ( empty( $positions ) ) {
			return null;
		}
		usort( $positions, function ( $a, $b ) {
			return $a['pos'] - $b['pos'];
		} );
		$first = $positions[0];
		$block = substr( $buffer, 0, $first['pos'] );
		$buffer = substr( $buffer, $first['pos'] + $first['len'] );
		return $block;
	}

	/**
	 * Convert headers array into cURL header lines.
	 *
	 * @param array $headers Header map.
	 * @return array
	 */
	private static function curl_headers( $headers ) {
		$lines = array();
		foreach ( $headers as $key => $value ) {
			$lines[] = $key . ': ' . $value;
		}
		return $lines;
	}

	/**
	 * Consume a streamed image event block.
	 *
	 * @param string        $block    Raw event block.
	 * @param array         $state    Mutable state.
	 * @param callable|null $on_event Optional callback.
	 * @return void
	 */
	private static function consume_image_stream_event( $block, &$state, $on_event = null ) {
		$block = trim( (string) $block );
		if ( '' === $block ) {
			return;
		}

		$data_lines = array();
		foreach ( preg_split( "/\r?\n/", $block ) as $line ) {
			$line = trim( $line );
			if ( strncmp( $line, 'data:', 5 ) === 0 ) {
				$data_lines[] = trim( substr( $line, 5 ) );
			}
		}

		if ( empty( $data_lines ) ) {
			return;
		}

		$json = trim( implode( "\n", $data_lines ) );
		if ( $json === '[DONE]' ) {
			return;
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		$images = self::extract_image_items_from_payload( $payload );
		if ( ! empty( $images ) ) {
			$is_preview = self::payload_looks_like_partial_image( $payload );
			$target_key = $is_preview ? 'preview_images' : 'final_images';
			$existing   = self::image_item_hashes( $state[ $target_key ] );
			$new_images = array();

			foreach ( $images as $image ) {
				$hash = md5( wp_json_encode( $image ) );
				if ( isset( $existing[ $hash ] ) ) {
					continue;
				}
				$existing[ $hash ] = true;
				$state[ $target_key ][] = $image;
				$new_images[] = $image;
			}

			if ( ! empty( $new_images ) && is_callable( $on_event ) ) {
				call_user_func(
					$on_event,
					array(
						'type'   => $is_preview ? 'preview_image' : 'final_image',
						'images' => $new_images,
						'raw'    => $payload,
					)
				);
			}
		}

		if ( isset( $payload['usage'] ) && is_array( $payload['usage'] ) ) {
			$state['usage'] = $payload['usage'];
		}
	}

	/**
	 * Determine whether a stream payload looks like a partial image update.
	 *
	 * @param array $payload Event payload.
	 * @return bool
	 */
	private static function payload_looks_like_partial_image( $payload ) {
		$joined = strtolower( wp_json_encode( $payload ) );
		return ( strpos( $joined, 'partial' ) !== false || strpos( $joined, 'preview' ) !== false );
	}

	/**
	 * Extract image items from a payload recursively.
	 *
	 * @param mixed $payload Event payload.
	 * @return array
	 */
	private static function extract_image_items_from_payload( $payload ) {
		$items = array();

		if ( is_array( $payload ) ) {
			if ( isset( $payload['b64_json'] ) && is_string( $payload['b64_json'] ) && $payload['b64_json'] !== '' ) {
				$items[] = array( 'b64_json' => $payload['b64_json'] );
			}
			if ( isset( $payload['url'] ) && is_string( $payload['url'] ) && $payload['url'] !== '' ) {
				$items[] = array( 'url' => $payload['url'] );
			}
			if ( isset( $payload['bytesBase64Encoded'] ) && is_string( $payload['bytesBase64Encoded'] ) && $payload['bytesBase64Encoded'] !== '' ) {
				$items[] = array( 'b64_json' => $payload['bytesBase64Encoded'] );
			}
			if ( isset( $payload['image_base64'] ) && is_string( $payload['image_base64'] ) && $payload['image_base64'] !== '' ) {
				$items[] = array( 'b64_json' => $payload['image_base64'] );
			}
			foreach ( $payload as $value ) {
				if ( is_array( $value ) ) {
					$items = array_merge( $items, self::extract_image_items_from_payload( $value ) );
				}
			}
		}

		$unique = array();
		$seen   = array();
		foreach ( $items as $item ) {
			$hash = md5( wp_json_encode( $item ) );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$unique[] = $item;
		}

		return $unique;
	}

	/**
	 * Build a hash set for image items.
	 *
	 * @param array $items Existing items.
	 * @return array
	 */
	private static function image_item_hashes( $items ) {
		$hashes = array();
		foreach ( $items as $item ) {
			$hashes[ md5( wp_json_encode( $item ) ) ] = true;
		}
		return $hashes;
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
		if ( ! $prov ) {
			return new \WP_Error( 'no_provider', __( 'No audio provider configured.', 'alorbach-ai-gateway' ) );
		}
		if ( ! $prov->supports_audio() ) {
			return new \WP_Error(
				'unsupported_provider',
				sprintf(
					/* translators: 1: provider name */
					__( 'Audio transcription is not supported for %1$s models yet.', 'alorbach-ai-gateway' ),
					self::get_provider_label( $provider )
				)
			);
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
		if ( ! $prov ) {
			return new \WP_Error( 'no_api_key', sprintf(
				/* translators: 1: provider name */
				__( 'API key not configured for %1$s. Video generation requires a configured API key for the model\'s provider.', 'alorbach-ai-gateway' ),
				self::get_provider_label( $provider )
			) );
		}
		if ( ! $prov->supports_video() ) {
			return new \WP_Error(
				'unsupported_provider',
				sprintf(
					/* translators: 1: provider name */
					__( 'Video generation is not supported for %1$s models yet.', 'alorbach-ai-gateway' ),
					self::get_provider_label( $provider )
				)
			);
		}
		$creds = API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return new \WP_Error( 'no_api_key', sprintf(
				/* translators: 1: provider name */
				__( 'API key not configured for %1$s. Video generation requires a configured API key for the model\'s provider.', 'alorbach-ai-gateway' ),
				self::get_provider_label( $provider )
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
		$max_polls     = (int) apply_filters( 'alorbach_video_poll_max', 60 );
		$poll_interval = (int) apply_filters( 'alorbach_video_poll_interval', 5 );
		// Set a bounded execution time limit covering the full polling window.
		set_time_limit( $max_polls * $poll_interval + 30 );
		$create_result = self::create_video( $prompt, $model, $size, $duration_seconds );
		if ( is_wp_error( $create_result ) ) {
			return $create_result;
		}
		$video_id  = $create_result['id'] ?? '';
		$provider  = $create_result['provider'] ?? 'openai';
		$creds     = API_Keys_Helper::get_credentials_for_provider( $provider );

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
		do_action( 'alorbach_video_poll_timeout', $video_id, $provider );
		return new \WP_Error( 'timeout', __( 'Video generation timed out. The video may still be processing.', 'alorbach-ai-gateway' ) );
	}

}
