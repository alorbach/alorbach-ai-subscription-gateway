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
	 * @param string $size Size (e.g. 1024x1024).
	 * @return int UC cost.
	 */
	public static function get_image_cost( $size = '1024x1024' ) {
		$costs = get_option( 'alorbach_image_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$costs = apply_filters( 'alorbach_image_costs', $costs );
		return isset( $costs[ $size ] ) ? (int) $costs[ $size ] : 40000;
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
