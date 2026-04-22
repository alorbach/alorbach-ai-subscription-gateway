<?php
/**
 * Local Codex CLI bridge for image generation.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Codex_Image_Bridge
 */
class Codex_Image_Bridge {

	/**
	 * Helper script path relative to the plugin root.
	 */
	const HELPER_RELATIVE_PATH = 'bin/codex-image-bridge.js';

	/**
	 * Verify that the local Codex bridge can run.
	 *
	 * @return array{success: bool, message?: string, details?: array}
	 */
	public static function verify_runtime() {
		$result = self::run_helper( 'check' );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$message = isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : __( 'Local Codex CLI bridge is available.', 'alorbach-ai-gateway' );
		return array(
			'success' => true,
			'message' => $message,
			'details' => isset( $result['details'] ) && is_array( $result['details'] ) ? $result['details'] : array(),
		);
	}

	/**
	 * Execute one local Codex image generation request.
	 *
	 * @param array $payload Request payload.
	 * @return array|\WP_Error
	 */
	public static function generate( $payload ) {
		$result = self::run_helper( 'generate', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$images = isset( $result['images'] ) && is_array( $result['images'] ) ? $result['images'] : array();
		$inline_images = isset( $result['image_data'] ) && is_array( $result['image_data'] ) ? $result['image_data'] : array();
		if ( empty( $images ) && empty( $inline_images ) ) {
			return new \WP_Error( 'codex_image_bridge_empty', __( 'Codex CLI did not return any generated image paths.', 'alorbach-ai-gateway' ) );
		}

		$data = array();
		foreach ( $inline_images as $image_data ) {
			$b64 = isset( $image_data['b64_json'] ) && is_string( $image_data['b64_json'] ) ? $image_data['b64_json'] : '';
			if ( '' === $b64 ) {
				continue;
			}
			$data[] = array(
				'b64_json' => $b64,
			);
		}

		foreach ( $images as $image_path ) {
			$image_path = is_string( $image_path ) ? $image_path : '';
			if ( '' === $image_path || ! file_exists( $image_path ) || ! is_readable( $image_path ) ) {
				continue;
			}
			$bytes = file_get_contents( $image_path );
			if ( false === $bytes || '' === $bytes ) {
				continue;
			}
			$data[] = array(
				'b64_json' => base64_encode( $bytes ),
			);
		}

		if ( empty( $data ) ) {
			return new \WP_Error( 'codex_image_bridge_unreadable', __( 'Codex CLI generated an image, but WordPress could not read the resulting file.', 'alorbach-ai-gateway' ) );
		}

		$response = array( 'data' => $data );
		if ( ! empty( $result['response_text'] ) && is_string( $result['response_text'] ) ) {
			$response['revised_prompt'] = $result['response_text'];
		}
		$response['internal_prompt'] = ! empty( $result['internal_prompt'] ) && is_string( $result['internal_prompt'] )
			? $result['internal_prompt']
			: self::build_internal_prompt( is_array( $payload ) ? $payload : array() );
		if ( ! empty( $result['usage'] ) && is_array( $result['usage'] ) ) {
			$response['usage'] = $result['usage'];
		}
		if ( ! empty( $result['details'] ) && is_array( $result['details'] ) ) {
			$response['provider_details'] = $result['details'];
		}

		return $response;
	}

	/**
	 * Rebuild the internal Codex prompt in PHP so jobs can store it even when an
	 * older host bridge process does not return it explicitly.
	 *
	 * @param array $payload Request payload.
	 * @return string
	 */
	private static function build_internal_prompt( $payload ) {
		$prompt  = isset( $payload['prompt'] ) ? trim( (string) $payload['prompt'] ) : '';
		$size    = isset( $payload['size'] ) ? trim( (string) $payload['size'] ) : '1024x1024';
		$quality = isset( $payload['quality'] ) ? strtolower( trim( (string) $payload['quality'] ) ) : 'high';
		$format  = isset( $payload['output_format'] ) ? trim( (string) $payload['output_format'] ) : 'png';

		if ( ! in_array( $quality, array( 'medium', 'high' ), true ) ) {
			$quality = 'high';
		}

		return implode(
			"\n",
			array(
				'Generate exactly one image using your built-in image generation tool.',
				'If your image tool supports model selection, prefer gpt-image-2 or a better current image model for this request.',
				'User prompt: ' . $prompt,
				'Requested size: ' . $size,
				'Preferred quality: ' . $quality . '.',
				'Preferred output format: ' . $format,
				'After the image has been generated, reply with a short plain-text confirmation only.',
			)
		);
	}

	/**
	 * Run the local bridge helper.
	 *
	 * @param string $mode    Helper mode.
	 * @param array  $payload Optional request payload.
	 * @return array|\WP_Error
	 */
	private static function run_helper( $mode, $payload = array() ) {
		$script = trailingslashit( ALORBACH_PLUGIN_DIR ) . self::HELPER_RELATIVE_PATH;
		if ( ! file_exists( $script ) ) {
			return new \WP_Error( 'codex_image_bridge_missing', __( 'Codex image bridge helper script is missing from the plugin.', 'alorbach-ai-gateway' ) );
		}

		if ( ! function_exists( 'proc_open' ) ) {
			return new \WP_Error( 'codex_image_bridge_proc_open', __( 'The local Codex image bridge requires proc_open, but it is disabled in this PHP environment.', 'alorbach-ai-gateway' ) );
		}

		$node_binary = (string) apply_filters( 'alorbach_codex_bridge_node_binary', 'node' );
		$command     = escapeshellarg( $node_binary ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( (string) $mode );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$env = array(
			'ALORBACH_CODEX_BINARY' => (string) apply_filters( 'alorbach_codex_bridge_codex_binary', 'codex' ),
		);
		$base_env = is_array( $_ENV ) ? $_ENV : array();

		$process = proc_open(
			$command,
			$descriptors,
			$pipes,
			ALORBACH_PLUGIN_DIR,
			array_merge( $base_env, $env )
		);

		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'codex_image_bridge_spawn', __( 'WordPress could not start the local Codex image bridge helper.', 'alorbach-ai-gateway' ) );
		}

		$input = wp_json_encode(
			array(
				'payload' => is_array( $payload ) ? $payload : array(),
			)
		);
		fwrite( $pipes[0], $input ? $input : '{}' );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		$decoded   = json_decode( (string) $stdout, true );

		if ( 0 !== (int) $exit_code ) {
			$message = '';
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$message = $decoded['message'];
			}
			if ( '' === $message && '' !== trim( (string) $stderr ) ) {
				$message = trim( (string) $stderr );
			}
			if ( '' === $message ) {
				$message = __( 'The local Codex image bridge failed.', 'alorbach-ai-gateway' );
			}

			$remote = self::run_remote_bridge( $mode, $payload, $message );
			return is_wp_error( $remote ) ? new \WP_Error( 'codex_image_bridge_failed', $remote->get_error_message() ) : $remote;
		}

		if ( ! is_array( $decoded ) ) {
			$message = trim( (string) $stderr );
			if ( '' === $message ) {
				$message = __( 'The local Codex image bridge returned invalid JSON.', 'alorbach-ai-gateway' );
			}
			$remote = self::run_remote_bridge( $mode, $payload, $message );
			return is_wp_error( $remote ) ? new \WP_Error( 'codex_image_bridge_invalid_json', $remote->get_error_message() ) : $remote;
		}

		if ( empty( $decoded['success'] ) ) {
			$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : __( 'The local Codex image bridge reported a failure.', 'alorbach-ai-gateway' );
			$remote = self::run_remote_bridge( $mode, $payload, $message );
			return is_wp_error( $remote ) ? new \WP_Error( 'codex_image_bridge_reported_failure', $remote->get_error_message() ) : $remote;
		}

		return $decoded;
	}

	/**
	 * Attempt host-bridge fallback for containerized WordPress environments.
	 *
	 * @param string $mode           Helper mode.
	 * @param array  $payload        Payload.
	 * @param string $failure_reason Local execution failure reason.
	 * @return array|\WP_Error
	 */
	private static function run_remote_bridge( $mode, $payload, $failure_reason = '' ) {
		$url = self::get_remote_bridge_url();
		if ( '' === $url ) {
			return new \WP_Error(
				'codex_image_bridge_no_remote',
				self::build_container_hint_message( $failure_reason )
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'mode'    => (string) $mode,
						'payload' => is_array( $payload ) ? $payload : array(),
					)
				),
				'timeout' => (int) apply_filters( 'alorbach_codex_bridge_remote_timeout', 600, $mode, $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'codex_image_bridge_remote_unreachable',
				self::build_container_hint_message( $response->get_error_message() )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( $code >= 400 || ! is_array( $decoded ) ) {
			return new \WP_Error(
				'codex_image_bridge_remote_failed',
				self::build_container_hint_message( is_array( $decoded ) && ! empty( $decoded['message'] ) ? (string) $decoded['message'] : trim( (string) $raw ) )
			);
		}

		return $decoded;
	}

	/**
	 * Get remote bridge URL when WordPress cannot execute host binaries directly.
	 *
	 * @return string
	 */
	private static function get_remote_bridge_url() {
		$url = apply_filters( 'alorbach_codex_bridge_remote_url', '' );
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' !== $url ) {
			return $url;
		}

		if ( self::looks_like_container_environment() ) {
			return 'http://host.docker.internal:8765/';
		}

		return '';
	}

	/**
	 * Detect a likely containerized development environment.
	 *
	 * @return bool
	 */
	private static function looks_like_container_environment() {
		return (
			file_exists( '/.dockerenv' ) ||
			( ! empty( $_SERVER['HOSTNAME'] ) && false !== strpos( (string) $_SERVER['HOSTNAME'], '-' ) )
		);
	}

	/**
	 * Build an actionable message for containerized setups.
	 *
	 * @param string $reason Underlying reason.
	 * @return string
	 */
	private static function build_container_hint_message( $reason = '' ) {
		$message = __( 'WordPress cannot reach a local Codex runtime from this PHP environment.', 'alorbach-ai-gateway' );
		if ( self::looks_like_container_environment() ) {
			$message .= ' ' . __( 'This usually happens in wp-env or Docker on Windows, where PHP runs in Linux but Codex CLI is installed on the Windows host.', 'alorbach-ai-gateway' );
			$message .= ' ' . __( 'Start the host bridge on Windows with `node wordpress-plugin/bin/codex-image-bridge.js serve`, then test again.', 'alorbach-ai-gateway' );
		}
		if ( '' !== trim( $reason ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: underlying execution failure */
				__( 'Underlying error: %s', 'alorbach-ai-gateway' ),
				$reason
			);
		}
		return $message;
	}
}
