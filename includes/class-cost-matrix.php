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
	 * Get input cost per token (UC).
	 *
	 * @param string $model Model name.
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
	 * @param string $quality Quality (low, medium, high). Default from options.
	 * @return int UC cost.
	 */
	public static function get_image_cost( $size = '1024x1024', $model = null, $quality = null ) {
		$model   = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = $quality ?: get_option( 'alorbach_image_default_quality', 'medium' );

		$model_costs = get_option( 'alorbach_image_model_costs', array() );
		$model_costs = is_array( $model_costs ) ? $model_costs : array();
		$model_costs = apply_filters( 'alorbach_image_model_costs', $model_costs );
		if ( isset( $model_costs[ $model ][ $quality ][ $size ] ) ) {
			return (int) $model_costs[ $model ][ $quality ][ $size ];
		}

		$costs = get_option( 'alorbach_image_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_image_costs', $costs );
		return isset( $costs[ $size ] ) ? (int) $costs[ $size ] : 40000;
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
		$costs = get_option( 'alorbach_video_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_video_costs', $costs );
		$base  = isset( $costs[ $model ] ) ? (int) $costs[ $model ] : 400000;
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
	 * @param string $model Model name.
	 * @return array
	 */
	private static function get_costs_for_model( $model ) {
		$all     = self::get_cost_matrix();
		$default = isset( $all['default'] ) ? $all['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		$models  = isset( $all['models'] ) && is_array( $all['models'] ) ? $all['models'] : array();
		foreach ( $models as $row ) {
			if ( isset( $row['model'] ) && $row['model'] === $model ) {
				return array(
					'input'  => isset( $row['input'] ) ? (int) $row['input'] : 400000,
					'output' => isset( $row['output'] ) ? (int) $row['output'] : 1600000,
					'cached' => isset( $row['cached'] ) ? (int) $row['cached'] : 40000,
				);
			}
		}
		return $default;
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
