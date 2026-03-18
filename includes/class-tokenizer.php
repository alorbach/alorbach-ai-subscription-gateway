<?php
/**
 * BPE tokenizer wrapper for pre-flight token estimation.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tokenizer
 */
class Tokenizer {

	/**
	 * Model to encoding mapping.
	 *
	 * @var array
	 */
	private static $model_encoding = array(
		'gpt-4o'           => 'o200k_base',
		'gpt-4o-mini'      => 'o200k_base',
		'gpt-4.1'          => 'o200k_base',
		'gpt-4.1-mini'     => 'o200k_base',
		'gpt-4.1-nano'     => 'o200k_base',
		'gpt-4-turbo'      => 'cl100k_base',
		'gpt-4'            => 'cl100k_base',
		'o1'               => 'o200k_base',
		'o1-mini'          => 'o200k_base',
		'o4-mini'          => 'o200k_base',
		'gemini-2.0-flash' => 'cl100k_base',
		'gemini-2.5-flash' => 'cl100k_base',
		'gemini-2.5-pro'   => 'cl100k_base',
		'gemini-1.5-flash' => 'cl100k_base',
		'gemini-1.5-pro'   => 'cl100k_base',
		'gemini-1.0-pro'   => 'cl100k_base',
		'text-embedding-3-large' => 'cl100k_base',
		'text-embedding-3-small' => 'cl100k_base',
	);

	/**
	 * Count tokens for a string.
	 *
	 * @param string $text  Text to count.
	 * @param string $model Model name (e.g. gpt-4o).
	 * @return int Token count, or 0 on error.
	 */
	public static function count_tokens( $text, $model = 'gpt-4.1-mini' ) {
		// Allow plugins to override token counting entirely (e.g. to use a different library).
		$override = apply_filters( 'alorbach_count_tokens', null, $text, $model );
		if ( null !== $override ) {
			return (int) $override;
		}

		try {
			if ( class_exists( 'Yethee\Tiktoken\EncoderProvider' ) ) {
				$provider = new \Yethee\Tiktoken\EncoderProvider();
				// Use our own model→encoding map and resolve by encoding name so that
				// models not yet registered inside tiktoken's own model table (e.g.
				// gpt-4.1-mini) still get accurate BPE counts rather than falling
				// through to the character-heuristic below.
				$encoding = self::get_encoding_for_model( $model );
				$encoder  = $provider->getForEncoding( $encoding );
				$tokens   = $encoder->encode( $text );
				return is_array( $tokens ) ? count( $tokens ) : 0;
			}
		} catch ( \Throwable $e ) {
			// Fall through to heuristic
		}
		// Fallback: ~4 chars per token (English heuristic)
		return (int) ceil( strlen( $text ) / 4 );
	}

	/**
	 * Count tokens for messages array (OpenAI format).
	 *
	 * @param array  $messages Messages array.
	 * @param string $model    Model name.
	 * @return int Token count.
	 */
	public static function count_messages_tokens( $messages, $model = 'gpt-4.1-mini' ) {
		$str = wp_json_encode( $messages );
		return self::count_tokens( $str, $model );
	}

	/**
	 * Get encoding for model.
	 *
	 * @param string $model Model name.
	 * @return string Encoding name.
	 */
	public static function get_encoding_for_model( $model ) {
		return isset( self::$model_encoding[ $model ] ) ? self::$model_encoding[ $model ] : 'cl100k_base';
	}
}
