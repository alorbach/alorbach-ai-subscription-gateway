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
		'gpt-4o'         => 'o200k_base',
		'gpt-4o-mini'    => 'o200k_base',
		'gpt-4-turbo'    => 'cl100k_base',
		'gpt-4'          => 'cl100k_base',
		'gpt-3.5-turbo'  => 'cl100k_base',
		'gpt-3.5-turbo-16k' => 'cl100k_base',
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
	public static function count_tokens( $text, $model = 'gpt-4o' ) {
		try {
			if ( class_exists( 'Yethee\Tiktoken\EncoderProvider' ) ) {
				$provider = new \Yethee\Tiktoken\EncoderProvider();
				$encoder  = $provider->getForModel( $model );
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
	public static function count_messages_tokens( $messages, $model = 'gpt-4o' ) {
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
