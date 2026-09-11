<?php
/**
 * Cost matrix for UC calculation per model.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cost_Matrix
 *
 * 1 UC = 0.000001 USD
 */
class Cost_Matrix {

	/**
	 * Parse a model key that may be a compound "entry_id::model_id" key.
	 *
	 * @param string $key Model key (plain ID or "entry_id::model_id").
	 * @return array{entry_id: string, model: string}
	 */
	public static function parse_model_key( $key ) {
		$key = (string) $key;
		$pos = strpos( $key, '::' );
		if ( false !== $pos ) {
			return array(
				'entry_id' => substr( $key, 0, $pos ),
				'model'    => substr( $key, $pos + 2 ),
			);
		}
		return array( 'entry_id' => '', 'model' => $key );
	}

	/**
	 * Plain model ID from a compound or plain key.
	 *
	 * @param string $model Model ID or entry_id::model_id key.
	 * @return string
	 */
	public static function get_plain_model_id( $model ) {
		return (string) self::parse_model_key( $model )['model'];
	}

	/**
	 * Whether the model is a GPT Image family deployment.
	 *
	 * @param string $model Model ID or compound key.
	 * @return bool
	 */
	public static function is_gpt_image_model( $model ) {
		return strpos( strtolower( self::get_plain_model_id( $model ) ), 'gpt-image' ) === 0;
	}

	/**
	 * Whether the model is Azure GPT-Image-2 or 2.5 (token-usage billed family).
	 *
	 * @param string $model Model ID or compound key.
	 * @return bool
	 */
	public static function is_gpt_image_2_family( $model ) {
		return strpos( strtolower( self::get_plain_model_id( $model ) ), 'gpt-image-2' ) === 0;
	}

	/**
	 * Whether the model is GPT Image 2.5 Sunburst or Flare (including dated snapshots).
	 *
	 * @param string $model Model ID or compound key.
	 * @return bool
	 */
	public static function is_gpt_image_2_5_model( $model ) {
		$id = strtolower( self::get_plain_model_id( $model ) );
		return strpos( $id, 'gpt-image-2.5-sunburst' ) === 0 || strpos( $id, 'gpt-image-2.5-flare' ) === 0;
	}

	/**
	 * All canonical image quality values the Gateway can persist.
	 *
	 * @return string[]
	 */
	public static function get_all_image_qualities() {
		return array( 'low', 'medium', 'high', 'xhigh', 'max' );
	}

	/**
	 * Qualities a specific model may advertise or accept.
	 *
	 * @param string $model Model ID or compound key.
	 * @return string[]
	 */
	public static function get_supported_image_qualities( $model ) {
		$id = strtolower( self::get_plain_model_id( $model ) );
		if ( strpos( $id, 'codex-image-' ) === 0 || 'codex-local:image' === $id ) {
			return array( 'medium', 'high' );
		}
		if ( self::is_gpt_image_2_5_model( $model ) ) {
			return array( 'low', 'medium', 'high', 'xhigh', 'max' );
		}
		return array( 'low', 'medium', 'high' );
	}

	/**
	 * Human-readable quality label for admin and demo UI.
	 *
	 * @param string $quality Canonical quality.
	 * @return string
	 */
	public static function get_image_quality_label( $quality ) {
		$quality = strtolower( trim( (string) $quality ) );
		$labels  = array(
			'low'    => __( 'Low', 'alorbach-ai-gateway' ),
			'medium' => __( 'Medium', 'alorbach-ai-gateway' ),
			'high'   => __( 'High', 'alorbach-ai-gateway' ),
			'xhigh'  => __( 'Extra high', 'alorbach-ai-gateway' ),
			'max'    => __( 'Max', 'alorbach-ai-gateway' ),
		);
		return $labels[ $quality ] ?? ucfirst( $quality );
	}

	/**
	 * Normalize a requested quality onto the model's supported set.
	 *
	 * @param string $quality Raw requested quality.
	 * @param string $model   Model ID or compound key.
	 * @return string
	 */
	public static function normalize_image_quality( $quality, $model ) {
		$quality = strtolower( trim( (string) $quality ) );
		$aliases = array(
			'extra_high'  => 'xhigh',
			'extra-high'  => 'xhigh',
			'extrahigh'   => 'xhigh',
			'extrahoch'   => 'xhigh',
			'extra hoch'  => 'xhigh',
			'extra-hoch'  => 'xhigh',
		);
		if ( isset( $aliases[ $quality ] ) ) {
			$quality = $aliases[ $quality ];
		}

		$supported = self::get_supported_image_qualities( $model );
		if ( in_array( $quality, $supported, true ) ) {
			return $quality;
		}

		$id = strtolower( self::get_plain_model_id( $model ) );
		if ( strpos( $id, 'codex-image-' ) === 0 || 'codex-local:image' === $id ) {
			return 'high';
		}

		$default = strtolower( trim( (string) get_option( 'alorbach_image_default_quality', 'medium' ) ) );
		if ( isset( $aliases[ $default ] ) ) {
			$default = $aliases[ $default ];
		}
		if ( in_array( $default, $supported, true ) ) {
			return $default;
		}

		return in_array( 'medium', $supported, true ) ? 'medium' : (string) ( $supported[0] ?? 'medium' );
	}

	/**
	 * Backgrounds a GPT Image model may send to the Images API.
	 *
	 * @param string $model Model ID or compound key.
	 * @return string[]
	 */
	public static function get_supported_image_backgrounds( $model ) {
		if ( ! self::is_gpt_image_model( $model ) ) {
			return array();
		}
		return array( 'auto', 'opaque', 'transparent' );
	}

	/**
	 * Normalize a requested background onto the model's supported set.
	 *
	 * @param string $background Raw requested background.
	 * @param string $model      Model ID or compound key.
	 * @return string Empty when the model does not support background.
	 */
	public static function normalize_image_background( $background, $model ) {
		$supported = self::get_supported_image_backgrounds( $model );
		if ( empty( $supported ) ) {
			return '';
		}
		$background = strtolower( trim( (string) $background ) );
		if ( in_array( $background, $supported, true ) ) {
			return $background;
		}
		return 'auto';
	}

	/**
	 * Transparent backgrounds require PNG or WebP; coerce JPEG to PNG.
	 *
	 * @param string $output_format Canonical or MIME format.
	 * @param string $background    Normalized background.
	 * @return string
	 */
	public static function coerce_output_format_for_background( $output_format, $background ) {
		if ( 'transparent' !== strtolower( trim( (string) $background ) ) ) {
			return $output_format;
		}
		$format = strtolower( trim( (string) $output_format ) );
		if ( in_array( $format, array( 'jpeg', 'jpg', 'image/jpeg', 'image/jpg' ), true ) ) {
			return strpos( $format, 'image/' ) === 0 ? 'image/png' : 'png';
		}
		return $output_format;
	}

	/**
	 * Default per-image reservation matrix for a GPT Image model.
	 *
	 * @param string $model Model ID or compound key.
	 * @return array<string,array<string,int>>
	 */
	public static function get_default_gpt_image_costs( $model ) {
		$costs = array(
			'low'    => array( '1024x1024' => 9000, '1024x1536' => 13000, '1536x1024' => 13000 ),
			'medium' => array( '1024x1024' => 34000, '1024x1536' => 50000, '1536x1024' => 50000 ),
			'high'   => array( '1024x1024' => 133000, '1024x1536' => 200000, '1536x1024' => 200000 ),
		);
		if ( self::is_gpt_image_2_family( $model ) ) {
			$extra_sizes = array( '2048x2048', '2048x1152', '3840x2160', '2160x3840', 'auto' );
			foreach ( $costs as $quality => $sizes ) {
				foreach ( $extra_sizes as $size ) {
					if ( isset( $sizes[ $size ] ) ) {
						continue;
					}
					if ( 'auto' === $size ) {
						$costs[ $quality ][ $size ] = (int) $sizes['1024x1024'];
						continue;
					}
					if ( preg_match( '/^(\d+)x(\d+)$/', $size, $dimensions ) ) {
						$area_multiplier            = ( (int) $dimensions[1] * (int) $dimensions[2] ) / ( 1024 * 1024 );
						$costs[ $quality ][ $size ] = (int) round( $sizes['1024x1024'] * $area_multiplier );
					}
				}
			}
		}
		if ( ! self::is_gpt_image_2_5_model( $model ) ) {
			return $costs;
		}
		foreach ( $costs['high'] as $size => $cost ) {
			$costs['xhigh'][ $size ] = (int) round( $cost * 1.5 );
			$costs['max'][ $size ]   = (int) round( $cost * 2 );
		}
		return $costs;
	}

	/**
	 * Get input cost per token (UC).
	 *
	 * @param string $model Model name or compound "entry_id::model_id" key.
	 * @return int UC per token.
	 */
	public static function get_input_cost_per_token( $model ) {
		$costs = self::get_costs_for_model( $model );
		return isset( $costs['input'] ) ? (int) $costs['input'] : 500000;
	}

	/**
	 * Get output cost per token (UC).
	 *
	 * @param string $model Model name.
	 * @return int UC per token.
	 */
	public static function get_output_cost_per_token( $model ) {
		$costs = self::get_costs_for_model( $model );
		return isset( $costs['output'] ) ? (int) $costs['output'] : 1500000;
	}

	/**
	 * Get cached input cost per token (UC).
	 *
	 * @param string $model Model name.
	 * @return int UC per token.
	 */
	public static function get_cached_cost_per_token( $model ) {
		$costs = self::get_costs_for_model( $model );
		return isset( $costs['cached'] ) ? (int) $costs['cached'] : 250000;
	}

	/**
	 * Get the maximum output tokens supported by a model.
	 * Ordered from most specific to least specific to avoid prefix collisions.
	 *
	 * @param string $model Model name.
	 * @return int Max tokens (4096 if unknown).
	 */
	public static function get_max_tokens( $model ) {
		// Strip compound key prefix ("entry_id::model_id") — only the plain model name is used for token limits.
		$parsed = self::parse_model_key( $model );
		$model  = $parsed['model'];

		// 1. Check values fetched live from provider APIs at import time.
		$stored = get_option( 'alorbach_model_max_tokens', array() );
		if ( is_array( $stored ) ) {
			// Exact match first.
			if ( isset( $stored[ $model ] ) && (int) $stored[ $model ] > 0 ) {
				return (int) $stored[ $model ];
			}
			// Substring match for namespaced IDs like "openai/gpt-5.3" or "anthropic/claude-4.6".
			$model_lower = strtolower( $model );
			foreach ( $stored as $stored_id => $stored_cap ) {
				if ( (int) $stored_cap > 0 && strpos( $model_lower, strtolower( $stored_id ) ) !== false ) {
					return (int) $stored_cap;
				}
			}
		}

		// 2. Fall back to the static table (covers OpenAI, Azure, and any provider
		//    whose API doesn't return output-token limits).
		$model_lower = strtolower( $model );
		// IMPORTANT: more-specific keys MUST come before less-specific prefixes they contain,
		// e.g. 'gpt-4.1-mini' before 'gpt-4.1' before 'gpt-4', 'o4-mini' before 'o4' etc.
		// Matching via strpos() means GitHub Models publisher/model slugs like
		// 'openai/gpt-5.3' or 'anthropic/claude-4.6' are covered automatically.
		$caps = array(
			// --- Google Gemini (current as of early 2026) ---
			'gemini-2.5-pro'         => 65536,
			'gemini-2.5-flash-lite'  => 8192,
			'gemini-2.5-flash'       => 65536,
			'gemini-2.0-flash-lite'  => 8192,
			'gemini-2.0-flash'       => 8192,
			'gemini-1.5-pro'         => 8192,
			'gemini-1.5-flash'       => 8192,
			'gemini-1.0-pro'         => 2048,
			// --- Anthropic Claude (via GitHub Models or direct API) ---
			// claude-3-7-sonnet has 64k output; 3-5 variants 8k; claude-4.x family 32k+.
			// Matches 'claude-4.6', 'claude-4-opus', 'anthropic/claude-4.5' etc.
			'claude-3-7-sonnet'      => 64000,
			'claude-3-5-sonnet'      => 8192,
			'claude-3-5-haiku'       => 8192,
			'claude-3-opus'          => 4096,
			'claude-3-haiku'         => 4096,
			'claude-4'               => 32768,  // covers claude-4.0, claude-4.5, claude-4.6, claude-4-opus …
			// --- OpenAI o-series reasoning models ---
			'o4-mini'                => 100000,
			'o3-mini'                => 100000,
			'o1-mini'                => 65536,
			'o4'                     => 100000,
			'o3'                     => 100000,
			'o1'                     => 32768,
			// --- GPT-5 family — covers gpt-5.0, gpt-5.3, gpt-5.4 etc. ---
			'gpt-5'                  => 32768,
			// --- GPT-4o variants (before plain gpt-4 to avoid prefix collision) ---
			'gpt-4o-mini'            => 16384,
			'gpt-4o'                 => 16384,
			// --- GPT-4.1 series (April 2025, 32k output) ---
			'gpt-4.1-nano'           => 32768,
			'gpt-4.1-mini'           => 32768,
			'gpt-4.1'                => 32768,
			// --- GPT-4.5 ---
			'gpt-4.5'                => 16384,
			// --- GPT-4 legacy ---
			'gpt-4-turbo'            => 4096,
			'gpt-4'                  => 8192,
			// --- GPT-3.5 ---
			'gpt-3.5-turbo'          => 4096,
		);
		foreach ( $caps as $key => $cap ) {
			if ( strpos( $model_lower, $key ) !== false ) {
				return $cap;
			}
		}
		// Unknown / future models: assume 32k output as a safe modern baseline.
		// Update this table when a new model family ships.
		return 32768;
	}

	/**
	 * Calculate chat cost from usage.
	 *
	 * @param string $model            Model name.
	 * @param int    $prompt_tokens    Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @param int    $cached_tokens    Cached tokens (default 0).
	 * @return int Total cost in UC.
	 */
	public static function calculate_chat_cost( $model, $prompt_tokens, $completion_tokens, $cached_tokens = 0 ) {
		$input_cost   = self::get_input_cost_per_token( $model );
		$output_cost  = self::get_output_cost_per_token( $model );
		$cached_cost  = self::get_cached_cost_per_token( $model );

		$standard_input = max( 0, $prompt_tokens - $cached_tokens );
		$cost = ( $standard_input * $input_cost + $cached_tokens * $cached_cost + $completion_tokens * $output_cost ) / 1000000;
		return (int) round( $cost );
	}

	/**
	 * Get image generation cost (UC).
	 *
	 * For gpt-image models: uses alorbach_image_model_costs[model][quality][size].
	 * For DALL-E: uses flat alorbach_image_costs[size].
	 *
	 * @param string $size    Size/dimensions (e.g. 1024x1024).
	 * @param string $model   Model ID (e.g. gpt-image-1.5, dall-e-3). Default from options.
	 * @param string $quality Quality (low, medium, high, xhigh, max). Default from options.
	 * @return int UC cost.
	 */
	public static function get_image_cost( $size = '1024x1024', $model = null, $quality = null ) {
		$model   = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = $quality ?: get_option( 'alorbach_image_default_quality', 'medium' );
		$plain   = self::get_plain_model_id( $model );

		$model_costs = get_option( 'alorbach_image_model_costs', array() );
		$model_costs = is_array( $model_costs ) ? $model_costs : array();
		$model_costs = apply_filters( 'alorbach_image_model_costs', $model_costs );
		if ( isset( $model_costs[ $model ][ $quality ][ $size ] ) ) {
			return (int) $model_costs[ $model ][ $quality ][ $size ];
		}
		if ( $plain !== $model && isset( $model_costs[ $plain ][ $quality ][ $size ] ) ) {
			return (int) $model_costs[ $plain ][ $quality ][ $size ];
		}

		$costs = get_option( 'alorbach_image_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_image_costs', $costs );
		return isset( $costs[ $size ] ) ? (int) $costs[ $size ] : 40000;
	}

	/**
	 * Calculate the actual Azure GPT-Image-2 cost reported by the provider.
	 *
	 * The Images API returns text-input, image-input and image-output tokens.
	 * The per-image matrix is deliberately not used when this detailed usage is
	 * available; it remains the preflight reservation and fallback.
	 *
	 * @param string $model Model ID or compound entry_id::model_id key.
	 * @param array  $usage Provider usage payload.
	 * @return int|null API cost in UC, or null when usage-based billing does not apply.
	 */
	public static function calculate_image_usage_cost( $model, $usage ) {
		if ( ! self::is_gpt_image_2_family( $model ) || ! is_array( $usage ) ) {
			return null;
		}
		if ( 'azure' !== API_Client::get_provider_for_model( $model ) ) {
			return null;
		}

		$details      = isset( $usage['input_tokens_details'] ) && is_array( $usage['input_tokens_details'] ) ? $usage['input_tokens_details'] : array();
		$text_input   = max( 0, (int) ( $details['text_tokens'] ?? 0 ) );
		$image_input  = max( 0, (int) ( $details['image_tokens'] ?? 0 ) );
		$image_output = max( 0, (int) ( $usage['output_tokens'] ?? 0 ) );
		if ( 0 === $text_input && 0 === $image_input && 0 === $image_output ) {
			return null;
		}

		// Azure Data Zone is the conservative default for the configured production
		// deployment. The admin can switch tiers and Refresh Azure prices persists
		// the current Retail Prices API values for the selected tier.
		$rates = get_option( 'alorbach_azure_gpt_image_2_token_rates', array() );
		$rates = is_array( $rates ) ? $rates : array();
		$rates = array_merge(
			array(
				'text_input'  => 5500000,
				'image_input' => 8800000,
				'image_output' => 33000000,
			),
			$rates
		);
		$rates = apply_filters( 'alorbach_azure_gpt_image_2_token_rates', $rates, $model, $usage );

		$cost = (
			$text_input * max( 0, (int) $rates['text_input'] ) +
			$image_input * max( 0, (int) $rates['image_input'] ) +
			$image_output * max( 0, (int) $rates['image_output'] )
		) / 1000000;

		return max( 0, (int) round( $cost ) );
	}

	/**
	 * Get video generation cost (UC) for given model and duration.
	 * Stored cost is treated as cost for 8 seconds; scales linearly by duration.
	 *
	 * @param string $model            Model ID (e.g. sora-2).
	 * @param int    $duration_seconds Duration in seconds (4, 8, or 12). Default 8.
	 * @return int UC cost.
	 */
	public static function get_video_cost( $model = 'sora-2', $duration_seconds = 8 ) {
		$model_id = self::parse_model_key( $model )['model'];
		$costs = get_option( 'alorbach_video_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_video_costs', $costs );
		$base  = isset( $costs[ $model_id ] ) ? (int) $costs[ $model_id ] : 400000;
		$duration = max( 4, min( 12, (int) $duration_seconds ) );
		if ( ! in_array( $duration, array( 4, 8, 12 ), true ) ) {
			$duration = 8;
		}
		return (int) round( $base * ( $duration / 8 ) );
	}

	/**
	 * Get audio transcription cost (UC) for given duration and model.
	 *
	 * @param int    $seconds Duration in seconds.
	 * @param string $model   Model (e.g. whisper-1, gpt-4o-transcribe). Default whisper-1.
	 * @return int UC cost.
	 */
	public static function get_audio_cost( $seconds, $model = 'whisper-1' ) {
		$costs = get_option( 'alorbach_audio_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_audio_costs', $costs );
		$rate  = isset( $costs[ $model ] ) ? (int) $costs[ $model ] : 100;
		return max( 0, (int) ceil( $seconds * $rate ) );
	}

	/**
	 * @deprecated Use get_audio_cost() instead.
	 */
	public static function get_whisper_cost( $seconds ) {
		return self::get_audio_cost( $seconds, 'whisper-1' );
	}

	/**
	 * Apply selling markup to API cost. When selling disabled, returns API cost as-is (pass-through).
	 *
	 * @param int $api_cost_uc API cost in UC.
	 * @return int User cost in UC (amount to deduct from balance).
	 */
	public static function apply_user_cost( $api_cost_uc, $model = '' ) {
		if ( ! get_option( 'alorbach_selling_enabled', false ) ) {
			return (int) $api_cost_uc;
		}
		$mult = (float) get_option( 'alorbach_selling_multiplier', 2.0 );
		$mult = max( 1.0, $mult );
		$user_cost = (int) round( $api_cost_uc * $mult );
		return apply_filters( 'alorbach_user_cost', $user_cost, $api_cost_uc, $model );
	}

	/**
	 * Get costs for model when importing (always default tier).
	 *
	 * @param string $model Model name.
	 * @return array{input: int, output: int, cached: int}
	 */
	public static function get_import_costs( $model ) {
		$known = array(
			'gpt-4o'           => array( 'input' => 2500000,  'output' => 10000000, 'cached' => 250000 ),
			'gpt-4o-mini'      => array( 'input' => 150000,   'output' => 600000,   'cached' => 15000 ),
			'gpt-4.1'          => array( 'input' => 2000000,  'output' => 8000000,  'cached' => 200000 ),
			'gpt-4.1-mini'     => array( 'input' => 400000,   'output' => 1600000,  'cached' => 40000 ),
			'gpt-4.1-nano'     => array( 'input' => 100000,   'output' => 400000,   'cached' => 10000 ),
			'gpt-5'            => array( 'input' => 1250000,  'output' => 10000000, 'cached' => 125000 ),
			'gpt-5-mini'       => array( 'input' => 250000,   'output' => 2000000,  'cached' => 25000 ),
			'gpt-5-nano'       => array( 'input' => 50000,    'output' => 400000,   'cached' => 5000 ),
			'o1'               => array( 'input' => 15000000, 'output' => 60000000, 'cached' => 1500000 ),
			'o1-mini'          => array( 'input' => 3000000,  'output' => 12000000, 'cached' => 300000 ),
			'o3-pro'           => array( 'input' => 20000000, 'output' => 80000000, 'cached' => 2000000 ),
			'o4-mini'          => array( 'input' => 1100000,  'output' => 4400000,  'cached' => 110000 ),
			'gemini-2.0-flash' => array( 'input' => 75000,    'output' => 300000,   'cached' => 7500 ),
			'gemini-2.5-flash' => array( 'input' => 75000,    'output' => 300000,   'cached' => 7500 ),
			'gemini-2.5-pro'   => array( 'input' => 1250000,  'output' => 5000000,  'cached' => 125000 ),
			'gemini-1.5-flash' => array( 'input' => 75000,    'output' => 300000,   'cached' => 7500 ),
			'gemini-1.5-pro'   => array( 'input' => 1250000,  'output' => 5000000,  'cached' => 125000 ),
		);
		if ( isset( $known[ $model ] ) ) {
			return $known[ $model ];
		}
		return array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
	}

	/**
	 * Get costs for model (from options or defaults).
	 *
	 * Accepts a plain model name or a compound "entry_id::model_id" key.
	 * When entry_id is present, the row matching both entry_id and model is
	 * preferred; falls back to the first row matching the model name alone.
	 *
	 * @param string $model Model name or compound "entry_id::model_id" key.
	 * @return array
	 */
	private static function get_costs_for_model( $model ) {
		$parsed   = self::parse_model_key( $model );
		$model_id = $parsed['model'];
		$entry_id = $parsed['entry_id'];

		$all             = self::get_cost_matrix();
		$default         = isset( $all['default'] ) ? $all['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		$models          = isset( $all['models'] ) && is_array( $all['models'] ) ? $all['models'] : array();
		$first_name_match = null;
		foreach ( $models as $row ) {
			if ( ! isset( $row['model'] ) || $row['model'] !== $model_id ) {
				continue;
			}
			$row_costs = array(
				'input'  => isset( $row['input'] ) ? (int) $row['input'] : 400000,
				'output' => isset( $row['output'] ) ? (int) $row['output'] : 1600000,
				'cached' => isset( $row['cached'] ) ? (int) $row['cached'] : 40000,
			);
			// Prefer the row that exactly matches the requested entry_id.
			if ( $entry_id && isset( $row['entry_id'] ) && $row['entry_id'] === $entry_id ) {
				return $row_costs;
			}
			if ( null === $first_name_match ) {
				$first_name_match = $row_costs;
			}
		}
		return null !== $first_name_match ? $first_name_match : $default;
	}

	/**
	 * Get cost matrix (normalized). Runs migration if legacy format detected.
	 *
	 * @return array{default: array, models: array}
	 */
	public static function get_cost_matrix() {
		$saved = get_option( 'alorbach_cost_matrix', array() );
		$saved = is_array( $saved ) ? $saved : array();

		if ( isset( $saved['models'] ) && is_array( $saved['models'] ) ) {
			return apply_filters( 'alorbach_cost_matrix', $saved );
		}

		// Legacy format: flat model_id => costs. Migrate to new structure.
		$migrated = self::migrate_cost_matrix( $saved );
		if ( $migrated !== $saved ) {
			update_option( 'alorbach_cost_matrix', $migrated );
		}
		return apply_filters( 'alorbach_cost_matrix', $migrated );
	}

	/**
	 * Migrate legacy flat cost matrix to new models array format.
	 *
	 * @param array $saved Raw option value.
	 * @return array Migrated structure.
	 */
	public static function migrate_cost_matrix( $saved ) {
		$default = isset( $saved['default'] ) ? $saved['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		$models  = array();
		foreach ( $saved as $key => $val ) {
			if ( $key === 'default' || ! is_array( $val ) ) {
				continue;
			}
			$provider = \Alorbach\AIGateway\API_Client::get_provider_for_model( $key );
			$entry    = API_Keys_Helper::get_entry_by_type( $provider );
			$entry_id = $entry ? ( $entry['id'] ?? '' ) : '';
			if ( empty( $entry_id ) ) {
				$entry_id = 'legacy';
			}
			$models[] = array(
				'model'    => $key,
				'entry_id' => $entry_id,
				'input'    => isset( $val['input'] ) ? $val['input'] : '',
				'output'   => isset( $val['output'] ) ? $val['output'] : '',
				'cached'   => isset( $val['cached'] ) ? $val['cached'] : '',
			);
		}
		return array(
			'default' => $default,
			'models'  => $models,
		);
	}

	/**
	 * Save cost matrix. Expects structure with default and models.
	 *
	 * @param array $data Array with default and models keys.
	 */
	public static function save_cost_matrix( $data ) {
		$normalized = array(
			'default' => isset( $data['default'] ) ? $data['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 ),
			'models'  => isset( $data['models'] ) && is_array( $data['models'] ) ? $data['models'] : array(),
		);
		update_option( 'alorbach_cost_matrix', $normalized );
	}
}
