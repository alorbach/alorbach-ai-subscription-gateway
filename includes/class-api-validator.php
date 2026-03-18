<?php
/**
 * API validation for keys and model endpoints.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

use Alorbach\AIGateway\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Validator
 */
class API_Validator {

	/**
	 * Verify API key for a provider.
	 *
	 * @param string $provider  openai, azure, google, github_models.
	 * @param string $entry_id  Optional. When set, use credentials for this specific entry.
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_key( $provider, $entry_id = '' ) {
		$prov = Provider_Registry::get( $provider );
		if ( ! $prov ) {
			return array( 'success' => false, 'message' => __( 'Unknown provider.', 'alorbach-ai-gateway' ) );
		}
		$creds = ! empty( $entry_id )
			? API_Keys_Helper::get_credentials_for_entry( $entry_id )
			: API_Keys_Helper::get_credentials_for_provider( $provider );
		if ( ! $creds ) {
			return array( 'success' => false, 'message' => __( 'API key not configured for this provider.', 'alorbach-ai-gateway' ) );
		}
		return $prov->verify_key( $creds );
	}

	/**
	 * Verify OpenAI API key (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_openai_key() {
		return self::verify_key( 'openai' );
	}

	/**
	 * Verify Azure OpenAI key (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_azure_key() {
		return self::verify_key( 'azure' );
	}

	/**
	 * Verify Google API key (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_google_key() {
		return self::verify_key( 'google' );
	}

	/**
	 * Verify GitHub Models token (backward compat).
	 *
	 * @return array{success: bool, message?: string}
	 */
	public static function verify_github_models_key() {
		return self::verify_key( 'github_models' );
	}

	/**
	 * Verify text model via minimal chat completion.
	 *
	 * @param string $provider  openai, azure, google, github_models.
	 * @param string $model     Model ID.
	 * @param string $entry_id  Optional. When set, use credentials for this specific entry.
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_text_model( $provider, $model, $entry_id = '' ) {
		if ( strpos( strtolower( $model ), 'sora' ) === 0 ) {
			return array( 'success' => false, 'message' => __( 'Sora is a video model. Use video generation to verify; the Test button uses the chat API.', 'alorbach-ai-gateway' ) );
		}
		$body = array(
			'model'      => $model,
			'messages'   => array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			'max_tokens' => 64,
		);
		$response = API_Client::chat( $provider, $body, $entry_id );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$content = isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '';
		return array( 'success' => true, 'result' => $content );
	}

	/**
	 * Verify image model via minimal image generation.
	 *
	 * @param string $size  Image size (e.g. 1024x1024).
	 * @param string $model Optional. Model ID (e.g. dall-e-3, gpt-image-1.5, imagen-4.0-generate-001). Uses default if empty.
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_image_model( $size, $model = '' ) {
		$model = $model ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		// Always use lowest quality for tests to reduce cost and latency.
		$response = API_Client::images( 'test', $size, 1, $model, 'low' );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$img = '';
		if ( isset( $response['data'][0] ) ) {
			$d = $response['data'][0];
			if ( ! empty( $d['b64_json'] ) ) {
				$img = 'data:image/png;base64,' . $d['b64_json'];
			} elseif ( ! empty( $d['url'] ) ) {
				$img = $d['url'];
			}
		}
		return array( 'success' => true, 'result' => $img );
	}

	/**
	 * Models that support the audioTranscriptions API (audio → text).
	 * gpt-audio-1.5 and *-tts use different endpoints (chat completions / speech synthesis).
	 *
	 * @var string[]
	 */
	private static $transcription_models = array(
		'whisper-1',
		'gpt-4o-transcribe',
		'gpt-4o-mini-transcribe',
		'gpt-4o-transcribe-diarize',
	);

	/**
	 * Verify audio model via minimal Whisper transcription.
	 *
	 * @param string $model Model (e.g. whisper-1, gpt-4o-transcribe).
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_audio_model( $model = 'whisper-1' ) {
		$model = $model ?: 'whisper-1';
		$supports_transcription = in_array( $model, self::$transcription_models, true )
			|| strpos( $model, '-transcribe' ) !== false
			|| strpos( $model, 'gpt-audio' ) === 0;
		if ( ! $supports_transcription ) {
			$is_tts = ( strpos( $model, '-tts' ) !== false );
			if ( $is_tts ) {
				return array(
					'success' => false,
					'message' => __( 'TTS models use the speech synthesis API, not transcription. The Test button only verifies transcription models (whisper-1, gpt-4o-transcribe, gpt-4o-mini-transcribe).', 'alorbach-ai-gateway' ),
				);
			}
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: model id, 2: list of supported models */
					__( 'Model %1$s may not support the transcription API. Supported: %2$s.', 'alorbach-ai-gateway' ),
					$model,
					implode( ', ', self::$transcription_models )
				),
			);
		}
		try {
			$tmp = self::create_minimal_wav();
			if ( ! $tmp ) {
				return array( 'success' => false, 'message' => __( 'Could not create test audio.', 'alorbach-ai-gateway' ) );
			}
			$response = API_Client::transcribe( $tmp, $model );
			wp_delete_file( $tmp );
			if ( is_wp_error( $response ) ) {
				return array( 'success' => false, 'message' => $response->get_error_message() );
			}
			$text = isset( $response['text'] ) ? $response['text'] : '';
			return array( 'success' => true, 'result' => $text );
		} catch ( \Throwable $e ) {
			return array( 'success' => false, 'message' => $e->getMessage() );
		}
	}

	/**
	 * Verify video model via quick create (submits job, returns when API accepts).
	 * Does not wait for video completion (which can take minutes).
	 *
	 * @param string $model Model ID (e.g. sora-2).
	 * @return array{success: bool, message?: string, result?: string}
	 */
	public static function verify_video_model( $model = 'sora-2' ) {
		$response = API_Client::create_video( 'A simple test', $model );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$video_id = isset( $response['id'] ) ? $response['id'] : '';
		return array( 'success' => true, 'result' => sprintf( __( 'Video job created (ID: %s). Generation may take 2–5 minutes.', 'alorbach-ai-gateway' ), $video_id ) );
	}

	/**
	 * Create WAV file with synthesized speech for testing.
	 * Uses formant synthesis to approximate "Hello, this is a test."
	 * First tries plugin asset; falls back to inline generation.
	 *
	 * @return string|false Temp file path or false.
	 */
	private static function create_minimal_wav() {
		$asset_path = ALORBACH_PLUGIN_DIR . 'assets/audio/test-speech.wav';
		if ( is_readable( $asset_path ) ) {
			return self::copy_asset_to_temp( $asset_path );
		}
		return self::generate_test_speech_wav();
	}

	/**
	 * Copy asset WAV to temp file (preserves .wav extension).
	 *
	 * @param string $asset_path Path to asset file.
	 * @return string|false Temp file path or false.
	 */
	private static function copy_asset_to_temp( $asset_path ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = \wp_tempnam( 'alorbach-wav-' );
		if ( ! $tmp ) {
			return false;
		}
		$tmp_wav = preg_replace( '/\.tmp$/i', '.wav', $tmp );
		if ( copy( $asset_path, $tmp_wav ) ) {
			if ( $tmp !== $tmp_wav && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $tmp_wav;
		}
		wp_delete_file( $tmp );
		return false;
	}

	/**
	 * Generate WAV with formant synthesis approximating "Hello, this is a test."
	 *
	 * @return string|false Temp file path or false.
	 */
	private static function generate_test_speech_wav() {
		$sample_rate = 16000;
		$syllables   = array(
			array( 0.15, 700, 1220, 5000 ),   // he-
			array( 0.12, 500, 1500, 4500 ),   // -llo
			array( 0.08, 0, 0, 0 ),          // pause
			array( 0.12, 400, 1600, 5000 ),   // thi-
			array( 0.10, 400, 1600, 4500 ),   // -s
			array( 0.08, 0, 0, 0 ),          // pause
			array( 0.10, 400, 1600, 4500 ),   // is
			array( 0.08, 0, 0, 0 ),          // pause
			array( 0.15, 700, 1220, 5000 ),   // a
			array( 0.08, 0, 0, 0 ),          // pause
			array( 0.12, 500, 1500, 5000 ),   // te-
			array( 0.15, 500, 1500, 4500 ),   // -st
		);

		$samples = '';
		foreach ( $syllables as $syl ) {
			list( $dur, $f1, $f2, $amp ) = $syl;
			$n = (int) ( $sample_rate * $dur );
			for ( $i = 0; $i < $n; $i++ ) {
				$t = $i / $sample_rate;
				if ( $amp > 0 && $f1 > 0 ) {
					$env = sin( $t * M_PI / $dur );
					$s  = $env * $amp * ( 0.6 * sin( 2 * M_PI * $f1 * $t ) + 0.4 * sin( 2 * M_PI * $f2 * $t ) );
				} else {
					$s = 0;
				}
				$s   = (int) max( -32768, min( 32767, $s ) );
				$samples .= pack( 'v', $s < 0 ? $s + 65536 : $s );
			}
		}

		$data_size = strlen( $samples );
		$file_size = 36 + $data_size;
		$header    = pack( 'A4V', 'RIFF', $file_size - 8 );
		$header   .= pack( 'A4', 'WAVE' );
		$header   .= pack( 'A4VvvVVvv', 'fmt ', 16, 1, 1, $sample_rate, $sample_rate * 2, 2, 16 );
		$header   .= pack( 'A4V', 'data', $data_size );
		$wav       = $header . $samples;

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = \wp_tempnam( 'alorbach-wav-' );
		if ( ! $tmp ) {
			return false;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $tmp, $wav, FS_CHMOD_FILE ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		$tmp_wav = preg_replace( '/\.tmp$/i', '.wav', $tmp );
		if ( $tmp_wav !== $tmp && rename( $tmp, $tmp_wav ) ) {
			return $tmp_wav;
		}
		wp_delete_file( $tmp );
		return false;
	}
}
