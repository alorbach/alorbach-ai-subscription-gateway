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
	 * Default costs per 1M input tokens (in UC).
	 * 1 USD = 1,000,000 UC.
	 *
	 * @var array
	 */
	private static $default_costs = array(
		'gpt-4o'        => array( 'input' => 2500000, 'output' => 10000000, 'cached' => 1250000 ),
		'gpt-4o-mini'   => array( 'input' => 150000, 'output' => 600000, 'cached' => 75000 ),
		'gpt-4-turbo'   => array( 'input' => 10000000, 'output' => 30000000, 'cached' => 5000000 ),
		'gpt-4'         => array( 'input' => 30000000, 'output' => 60000000, 'cached' => 15000000 ),
		'gpt-3.5-turbo' => array( 'input' => 500000, 'output' => 1500000, 'cached' => 250000 ),
	);

	/**
	 * DALL-E costs per image (UC).
	 *
	 * @var array
	 */
	private static $image_costs = array(
		'1024x1024' => 40000,
		'1792x1024' => 80000,
		'1024x1792' => 80000,
	);

	/**
	 * Whisper cost per second (UC).
	 *
	 * @var int
	 */
	private static $whisper_cost_per_second = 100;

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
		$costs = get_option( 'alorbach_image_costs', self::$image_costs );
		$costs = apply_filters( 'alorbach_image_costs', $costs );
		return isset( $costs[ $size ] ) ? (int) $costs[ $size ] : 40000;
	}

	/**
	 * Get Whisper transcription cost (UC) for given duration in seconds.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return int UC cost.
	 */
	public static function get_whisper_cost( $seconds ) {
		$rate = (int) apply_filters( 'alorbach_whisper_cost_per_second', self::$whisper_cost_per_second );
		return max( 0, (int) ceil( $seconds * $rate ) );
	}

	/**
	 * Get costs for model (from options or defaults).
	 *
	 * @param string $model Model name.
	 * @return array
	 */
	private static function get_costs_for_model( $model ) {
		$saved = get_option( 'alorbach_cost_matrix', array() );
		$all   = array_merge( self::$default_costs, is_array( $saved ) ? $saved : array() );
		$all   = apply_filters( 'alorbach_cost_matrix', $all );
		return isset( $all[ $model ] ) ? $all[ $model ] : array( 'input' => 500000, 'output' => 1500000, 'cached' => 250000 );
	}
}
