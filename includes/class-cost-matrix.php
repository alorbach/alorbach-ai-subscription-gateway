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
	 * Get video generation cost (UC) per video.
	 *
	 * @param string $model Model ID (e.g. sora-2).
	 * @return int UC cost per video.
	 */
	public static function get_video_cost( $model = 'sora-2' ) {
		$costs = get_option( 'alorbach_video_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_video_costs', $costs );
		return isset( $costs[ $model ] ) ? (int) $costs[ $model ] : 400000;
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
	public static function apply_user_cost( $api_cost_uc ) {
		if ( ! get_option( 'alorbach_selling_enabled', false ) ) {
			return (int) $api_cost_uc;
		}
		$mult = (float) get_option( 'alorbach_selling_multiplier', 2.0 );
		$mult = max( 1.0, $mult );
		$user_cost = (int) round( $api_cost_uc * $mult );
		return apply_filters( 'alorbach_user_cost', $user_cost, $api_cost_uc, null );
	}

	/**
	 * Get costs for model when importing (always default tier).
	 *
	 * @param string $model Model name.
	 * @return array{input: int, output: int, cached: int}
	 */
	public static function get_import_costs( $model ) {
		return array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
	}

	/**
	 * Get costs for model (from options or defaults).
	 *
	 * @param string $model Model name.
	 * @return array
	 */
	private static function get_costs_for_model( $model ) {
		$saved   = get_option( 'alorbach_cost_matrix', array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$all     = apply_filters( 'alorbach_cost_matrix', $saved );
		$default = isset( $all['default'] ) ? $all['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		return isset( $all[ $model ] ) ? $all[ $model ] : $default;
	}
}
