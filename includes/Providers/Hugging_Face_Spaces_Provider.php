<?php
/**
 * Hugging Face Spaces provider.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

use Alorbach\AIGateway\API_Keys_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Hugging_Face_Spaces_Provider
 */
class Hugging_Face_Spaces_Provider extends Provider_Base {

	/**
	 * Hugging Face Hub API base URL.
	 *
	 * @var string
	 */
	const HUB_API_BASE_URL = 'https://huggingface.co/api/spaces/';

	/**
	 * Supported request modes.
	 *
	 * @var string[]
	 */
	private static $supported_request_modes = array( 'custom_http', 'gradio_api' );

	/**
	 * Per-request cache for Gradio info payloads.
	 *
	 * @var array
	 */
	private static $gradio_info_cache = array();

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'huggingface_spaces';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_chat() {
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_images() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_image_job_capabilities( $model = '' ) {
		$request_mode = self::get_request_mode_for_model( $model );
		if ( 'gradio_api' === $request_mode ) {
			return array(
				'async_jobs'        => true,
				'provider_progress' => true,
				'preview_images'    => false,
			);
		}

		return array(
			'async_jobs'        => false,
			'provider_progress' => false,
			'preview_images'    => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		return new \WP_Error( 'unsupported_provider', __( 'Hugging Face Spaces does not support chat completions in this plugin.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		if ( ! empty( $reference_images ) ) {
			return new \WP_Error( 'reference_images_unsupported', __( 'Reference-image generation is not supported for Hugging Face Spaces yet.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$space_id = self::normalize_space_id( $credentials['space_id'] ?? '' );
		if ( '' === $space_id ) {
			return new \WP_Error( 'missing_space_id', __( 'Hugging Face Space ID not configured.', 'alorbach-ai-gateway' ) );
		}

		$request_mode = self::normalize_request_mode( $credentials['request_mode'] ?? '' );
		if ( '' === $request_mode ) {
			return new \WP_Error( 'unsupported_request_mode', __( 'Hugging Face Space request mode is invalid.', 'alorbach-ai-gateway' ) );
		}

		$endpoint = self::resolve_space_endpoint( $credentials );
		if ( '' === $endpoint ) {
			return new \WP_Error( 'missing_endpoint', __( 'Hugging Face Space endpoint could not be determined.', 'alorbach-ai-gateway' ) );
		}

		self::log_debug(
			'build_images_request',
			array(
				'space_id'     => $space_id,
				'request_mode' => $request_mode,
				'endpoint'     => $endpoint,
				'model'        => (string) $model,
			)
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json, image/*',
		);

		$api_key = isset( $credentials['api_key'] ) ? trim( (string) $credentials['api_key'] ) : '';
		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		if ( 'gradio_api' === $request_mode ) {
			return self::build_gradio_images_request( $prompt, $size, $quality, $endpoint, $credentials, $headers );
		}

		list( $width, $height ) = self::parse_image_size( $size );

		$body = array(
			'prompt'        => (string) $prompt,
			'size'          => (string) $size,
			'width'         => $width,
			'height'        => $height,
			'n'             => max( 1, (int) $n ),
			'quality'       => (string) $quality,
			'output_format' => (string) $output_format,
			'model'         => (string) $model,
			'space_id'      => $space_id,
		);

		$schema_preset = isset( $credentials['schema_preset'] ) ? trim( (string) $credentials['schema_preset'] ) : '';
		if ( '' !== $schema_preset ) {
			$body['schema_preset'] = $schema_preset;
		}

		return array(
			'url'     => $endpoint,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		$space_id = self::normalize_space_id( $credentials['space_id'] ?? '' );
		if ( '' === $space_id ) {
			return array( 'success' => false, 'message' => __( 'Hugging Face Space ID not configured.', 'alorbach-ai-gateway' ) );
		}

		$request_mode = self::normalize_request_mode( $credentials['request_mode'] ?? '' );
		if ( '' === $request_mode ) {
			return array( 'success' => false, 'message' => __( 'Hugging Face Space request mode is invalid.', 'alorbach-ai-gateway' ) );
		}

		$api_key = isset( $credentials['api_key'] ) ? trim( (string) $credentials['api_key'] ) : '';
		$headers = array();
		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$metadata = self::fetch_space_metadata( $space_id, $headers );
		if ( is_wp_error( $metadata ) ) {
			self::log_error(
				'verify_key_metadata_failed',
				array(
					'space_id' => $space_id,
					'message'  => $metadata->get_error_message(),
				)
			);
			return array( 'success' => false, 'message' => $metadata->get_error_message() );
		}

		$endpoint = self::resolve_space_endpoint( $credentials, $metadata );
		if ( '' === $endpoint ) {
			return array( 'success' => false, 'message' => __( 'Could not determine the Hugging Face Space endpoint.', 'alorbach-ai-gateway' ) );
		}

		if ( 'gradio_api' === $request_mode ) {
			$gradio_base = self::normalize_gradio_base_url( $endpoint );
			$gradio_info = self::fetch_gradio_api_info( $gradio_base, $headers );
			if ( is_wp_error( $gradio_info ) ) {
				self::log_error(
					'verify_key_gradio_info_failed',
					array(
						'space_id'     => $space_id,
						'request_mode' => $request_mode,
						'endpoint'     => $endpoint,
						'message'      => $gradio_info->get_error_message(),
					)
				);
				return array( 'success' => false, 'message' => $gradio_info->get_error_message() );
			}

			$api_name = self::resolve_gradio_generate_api_name( $credentials['schema_preset'] ?? '', $gradio_info );
			if ( '' === $api_name ) {
				return array( 'success' => false, 'message' => __( 'No public Gradio generate endpoint was found for this Space.', 'alorbach-ai-gateway' ) );
			}

			return array( 'success' => true );
		}

		$probe = self::verify_custom_http_endpoint( $space_id, $endpoint, $credentials, $headers );
		if ( ! empty( $probe['success'] ) ) {
			return array( 'success' => true );
		}

		$message = isset( $probe['message'] ) ? (string) $probe['message'] : __( 'Hugging Face Space endpoint is not reachable.', 'alorbach-ai-gateway' );
		self::log_error(
			'verify_key_custom_http_failed',
			array(
				'space_id'     => $space_id,
				'request_mode' => $request_mode,
				'endpoint'     => $endpoint,
				'message'      => $message,
			)
		);

		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		$space_id = self::normalize_space_id( $credentials['space_id'] ?? '' );
		if ( '' === $space_id ) {
			return array();
		}

		$schema_preset = isset( $credentials['schema_preset'] ) ? trim( (string) $credentials['schema_preset'] ) : '';
		$model_id      = self::build_model_id( $space_id, $schema_preset );

		return array(
			array(
				'id'           => $model_id,
				'provider'     => 'huggingface_spaces',
				'type'         => 'image',
				'capabilities' => array( 'text_to_image' ),
			),
		);
	}

	/**
	 * Build a namespaced model identifier.
	 *
	 * @param string $space_id      Space identifier.
	 * @param string $schema_preset Optional schema preset.
	 * @return string
	 */
	public static function build_model_id( $space_id, $schema_preset = '' ) {
		$model_id = 'hf-space:' . self::normalize_space_id( $space_id );
		$schema_preset = trim( (string) $schema_preset );
		if ( '' !== $schema_preset ) {
			$model_id .= ':' . sanitize_title( $schema_preset );
		}
		return $model_id;
	}

	/**
	 * Build a Gradio API image request.
	 *
	 * @param string $prompt      Prompt text.
	 * @param string $size        Requested size.
	 * @param string $quality     Requested quality.
	 * @param string $endpoint    Resolved endpoint.
	 * @param array  $credentials Provider credentials.
	 * @param array  $headers     Auth headers.
	 * @return array|\WP_Error
	 */
	private static function build_gradio_images_request( $prompt, $size, $quality, $endpoint, $credentials, $headers ) {
		list( $width, $height ) = self::parse_image_size( $size );
		$gradio_base = self::normalize_gradio_base_url( $endpoint );
		$gradio_info = self::fetch_gradio_api_info( $gradio_base, $headers );
		if ( is_wp_error( $gradio_info ) ) {
			self::log_error(
				'build_gradio_images_request_info_failed',
				array(
					'endpoint' => $endpoint,
					'message'  => $gradio_info->get_error_message(),
				)
			);
			return $gradio_info;
		}

		$api_name = self::resolve_gradio_generate_api_name( $credentials['schema_preset'] ?? '', $gradio_info );
		if ( '' === $api_name ) {
			return new \WP_Error( 'missing_gradio_endpoint', __( 'No public Gradio generate endpoint was found for this Space.', 'alorbach-ai-gateway' ) );
		}

		$payload = self::build_gradio_generate_payload( $gradio_info, $api_name, $prompt, $width, $height, $quality );
		if ( is_wp_error( $payload ) ) {
			self::log_error(
				'build_gradio_images_request_payload_failed',
				array(
					'endpoint'      => $endpoint,
					'api_name'      => $api_name,
					'message'       => $payload->get_error_message(),
					'space_id'      => self::normalize_space_id( $credentials['space_id'] ?? '' ),
					'schema_preset' => isset( $credentials['schema_preset'] ) ? (string) $credentials['schema_preset'] : '',
				)
			);
			return $payload;
		}

		$api_name = ltrim( $api_name, '/' );

		return array(
			'transport'    => 'gradio_api',
			'url'          => trailingslashit( $gradio_base ) . 'gradio_api/call/' . $api_name,
			'poll_url'     => trailingslashit( $gradio_base ) . 'gradio_api/call/' . $api_name . '/%s',
			'headers'      => array_merge( $headers, array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) ),
			'poll_headers' => $headers,
			'body'         => wp_json_encode( array( 'data' => $payload ) ),
		);
	}

	/**
	 * Extract the configured Space ID from a namespaced model identifier.
	 *
	 * @param string $model Model identifier.
	 * @return string
	 */
	public static function extract_space_id_from_model( $model ) {
		$model = trim( (string) $model );
		if ( strpos( $model, 'hf-space:' ) !== 0 ) {
			return '';
		}

		$payload = substr( $model, strlen( 'hf-space:' ) );
		if ( false === strpos( $payload, ':' ) ) {
			return self::normalize_space_id( rawurldecode( $payload ) );
		}

		list( $space_id ) = explode( ':', $payload, 2 );
		return self::normalize_space_id( rawurldecode( $space_id ) );
	}

	/**
	 * Normalize a Space ID.
	 *
	 * @param string $space_id Raw Space ID.
	 * @return string
	 */
	private static function normalize_space_id( $space_id ) {
		$space_id = trim( (string) $space_id );
		$space_id = preg_replace( '#^https?://[^/]+/#i', '', $space_id );
		$space_id = preg_replace( '#^spaces/#i', '', $space_id );
		$space_id = trim( (string) $space_id, '/' );
		return $space_id;
	}

	/**
	 * Normalize a request mode string.
	 *
	 * @param string $request_mode Raw request mode.
	 * @return string
	 */
	private static function normalize_request_mode( $request_mode ) {
		$request_mode = sanitize_key( (string) $request_mode );
		return in_array( $request_mode, self::$supported_request_modes, true ) ? $request_mode : '';
	}

	/**
	 * Normalize a Gradio base URL from various saved endpoint forms.
	 *
	 * @param string $endpoint Endpoint string.
	 * @return string
	 */
	private static function normalize_gradio_base_url( $endpoint ) {
		$endpoint = untrailingslashit( trim( (string) $endpoint ) );

		$gradio_pos = strpos( $endpoint, '/gradio_api/' );
		if ( false !== $gradio_pos ) {
			$endpoint = substr( $endpoint, 0, $gradio_pos );
		}

		foreach ( array( '/generate', '/predict', '/config' ) as $suffix ) {
			if ( substr( $endpoint, -strlen( $suffix ) ) === $suffix ) {
				$endpoint = substr( $endpoint, 0, -strlen( $suffix ) );
				break;
			}
		}

		return untrailingslashit( $endpoint );
	}

	/**
	 * Fetch Gradio API metadata for a Space.
	 *
	 * @param string $gradio_base Gradio base URL.
	 * @param array  $headers     HTTP headers.
	 * @return array|\WP_Error
	 */
	private static function fetch_gradio_api_info( $gradio_base, $headers = array() ) {
		$cache_key = md5( $gradio_base . '|' . wp_json_encode( $headers ) );
		if ( isset( self::$gradio_info_cache[ $cache_key ] ) ) {
			return self::$gradio_info_cache[ $cache_key ];
		}

		$response = wp_remote_get(
			trailingslashit( $gradio_base ) . 'gradio_api/info',
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			self::log_error(
				'fetch_gradio_api_info_request_failed',
				array(
					'gradio_base' => $gradio_base,
					'message'     => $response->get_error_message(),
				)
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : __( 'Could not load Hugging Face Space Gradio API metadata.', 'alorbach-ai-gateway' );
			self::log_error(
				'fetch_gradio_api_info_failed',
				array(
					'gradio_base' => $gradio_base,
					'status'      => $code,
					'message'     => $message,
				)
			);
			return new \WP_Error( 'hf_space_gradio_info_error', $message, array( 'status' => $code ) );
		}

		self::$gradio_info_cache[ $cache_key ] = $body;
		return $body;
	}

	/**
	 * Resolve the Gradio endpoint name used for generation.
	 *
	 * @param string $schema_preset Optional schema preset.
	 * @param array  $gradio_info   Gradio info payload.
	 * @return string
	 */
	private static function resolve_gradio_generate_api_name( $schema_preset, $gradio_info ) {
		$named_endpoints = isset( $gradio_info['named_endpoints'] ) && is_array( $gradio_info['named_endpoints'] ) ? $gradio_info['named_endpoints'] : array();
		$candidates      = array();

		$schema_preset = trim( (string) $schema_preset );
		if ( '' !== $schema_preset ) {
			$candidates[] = '/' . trim( $schema_preset, '/' );
		}
		$candidates[] = '/generate';
		$candidates[] = '/predict';

		foreach ( $candidates as $candidate ) {
			if ( isset( $named_endpoints[ $candidate ] ) ) {
				return ltrim( $candidate, '/' );
			}
		}

		foreach ( $named_endpoints as $name => $definition ) {
			if ( ! is_array( $definition ) || empty( $definition['returns'] ) ) {
				continue;
			}
			$serialized = strtolower( wp_json_encode( $definition['returns'] ) );
			if ( strpos( $serialized, 'image' ) !== false || strpos( $serialized, 'filedata' ) !== false || strpos( $serialized, 'url' ) !== false ) {
				return ltrim( (string) $name, '/' );
			}
		}

		return '';
	}

	/**
	 * Build a Gradio payload array for a generate endpoint.
	 *
	 * @param array  $gradio_info Gradio info payload.
	 * @param string $api_name    Generate endpoint name.
	 * @param string $prompt      Prompt text.
	 * @param int    $width       Width.
	 * @param int    $height      Height.
	 * @param string $quality     Quality selection.
	 * @return array|\WP_Error
	 */
	private static function build_gradio_generate_payload( $gradio_info, $api_name, $prompt, $width, $height, $quality ) {
		$endpoint_key = '/' . ltrim( (string) $api_name, '/' );
		$definition   = isset( $gradio_info['named_endpoints'][ $endpoint_key ] ) && is_array( $gradio_info['named_endpoints'][ $endpoint_key ] ) ? $gradio_info['named_endpoints'][ $endpoint_key ] : array();
		$parameters   = isset( $definition['parameters'] ) && is_array( $definition['parameters'] ) ? $definition['parameters'] : array();
		if ( empty( $parameters ) ) {
			return new \WP_Error(
				'hf_space_schema_unsupported',
				__( 'This Hugging Face Space Gradio endpoint does not expose a supported parameter schema for image generation.', 'alorbach-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		$payload        = array();
		$mode_choice    = null;
		$matched_prompt = false;

		foreach ( $parameters as $parameter ) {
			$name = strtolower( (string) ( $parameter['parameter_name'] ?? '' ) );
			switch ( $name ) {
				case 'prompt':
				case 'text':
				case 'query':
				case 'input_text':
					$value = (string) $prompt;
					$matched_prompt = true;
					break;
				case 'input_images':
				case 'image_list':
					$value = array();
					break;
				case 'mode':
				case 'mode_choice':
					$value = self::resolve_gradio_mode_choice( $quality, $parameter );
					$mode_choice = (string) $value;
					break;
				case 'seed':
					$value = 0;
					break;
				case 'randomize_seed':
					$value = true;
					break;
				case 'width':
					$value = $width;
					break;
				case 'height':
					$value = $height;
					break;
				case 'num_inference_steps':
					$value = ( $mode_choice && false !== stripos( $mode_choice, 'base' ) ) ? 50 : self::default_gradio_parameter_value( $parameter );
					break;
				case 'guidance_scale':
					$value = self::default_gradio_parameter_value( $parameter );
					break;
				case 'prompt_upsampling':
					$value = ( 'high' === (string) $quality );
					break;
				default:
					$value = self::default_gradio_parameter_value( $parameter );
					break;
			}

			$payload[] = $value;
		}

		if ( ! $matched_prompt ) {
			return new \WP_Error(
				'hf_space_schema_unsupported',
				__( 'This Hugging Face Space Gradio endpoint does not expose a supported prompt field for text-to-image generation.', 'alorbach-ai-gateway' ),
				array( 'status' => 400 )
			);
		}

		return $payload;
	}

	/**
	 * Resolve a mode choice for Gradio generate endpoints.
	 *
	 * @param string $quality   Requested quality.
	 * @param array  $parameter Parameter definition.
	 * @return string
	 */
	private static function resolve_gradio_mode_choice( $quality, $parameter ) {
		$choices = isset( $parameter['type']['enum'] ) && is_array( $parameter['type']['enum'] ) ? $parameter['type']['enum'] : array();
		$default = isset( $parameter['parameter_default'] ) ? (string) $parameter['parameter_default'] : '';

		if ( empty( $choices ) ) {
			return $default;
		}

		if ( 'high' === (string) $quality ) {
			foreach ( $choices as $choice ) {
				if ( false !== stripos( (string) $choice, 'base' ) ) {
					return (string) $choice;
				}
			}
		}

		if ( '' !== $default ) {
			return $default;
		}

		return (string) reset( $choices );
	}

	/**
	 * Get a safe default value from a Gradio parameter definition.
	 *
	 * @param array $parameter Parameter definition.
	 * @return mixed
	 */
	private static function default_gradio_parameter_value( $parameter ) {
		if ( array_key_exists( 'parameter_default', $parameter ) ) {
			return $parameter['parameter_default'];
		}

		$type = isset( $parameter['type']['type'] ) ? (string) $parameter['type']['type'] : '';
		if ( 'boolean' === $type ) {
			return false;
		}
		if ( 'number' === $type || 'integer' === $type ) {
			return 0;
		}
		if ( 'array' === $type ) {
			return array();
		}
		if ( isset( $parameter['type']['enum'] ) && is_array( $parameter['type']['enum'] ) && ! empty( $parameter['type']['enum'] ) ) {
			return reset( $parameter['type']['enum'] );
		}

		return '';
	}

	/**
	 * Resolve the runtime endpoint for a Space.
	 *
	 * @param array $credentials Provider credentials.
	 * @param array $metadata    Optional metadata payload.
	 * @return string
	 */
	private static function resolve_space_endpoint( $credentials, $metadata = array() ) {
		$endpoint = isset( $credentials['endpoint'] ) ? trim( (string) $credentials['endpoint'] ) : '';
		if ( '' !== $endpoint ) {
			return untrailingslashit( $endpoint );
		}

		$candidates = array();
		if ( isset( $metadata['host'] ) ) {
			$candidates[] = $metadata['host'];
		}
		if ( isset( $metadata['subdomain'] ) ) {
			$candidates[] = $metadata['subdomain'];
		}
		if ( isset( $metadata['runtime']['host'] ) ) {
			$candidates[] = $metadata['runtime']['host'];
		}
		if ( isset( $metadata['runtime']['subdomain'] ) ) {
			$candidates[] = $metadata['runtime']['subdomain'];
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( strpos( $candidate, 'http://' ) === 0 || strpos( $candidate, 'https://' ) === 0 ) {
				return untrailingslashit( $candidate );
			}
			return 'https://' . untrailingslashit( $candidate );
		}

		$space_id = self::normalize_space_id( $credentials['space_id'] ?? '' );
		if ( '' === $space_id ) {
			return '';
		}

		return 'https://' . str_replace( '/', '-', strtolower( $space_id ) ) . '.hf.space';
	}

	/**
	 * Fetch metadata for a Hugging Face Space.
	 *
	 * @param string $space_id Space identifier.
	 * @param array  $headers  Optional request headers.
	 * @return array|\WP_Error
	 */
	private static function fetch_space_metadata( $space_id, $headers = array() ) {
		$paths = array(
			self::HUB_API_BASE_URL . rawurlencode( $space_id ),
			self::HUB_API_BASE_URL . str_replace( '%2F', '/', rawurlencode( $space_id ) ),
		);

		$last_error = null;
		foreach ( array_unique( $paths ) as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => $headers,
					'timeout' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$body = json_decode( $raw, true );
			if ( $code >= 400 ) {
				$message = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : __( 'Could not load Hugging Face Space metadata.', 'alorbach-ai-gateway' );
				$last_error = new \WP_Error( 'hf_space_metadata_error', $message, array( 'status' => $code ) );
				continue;
			}

			return is_array( $body ) ? $body : array();
		}

		return $last_error instanceof \WP_Error
			? $last_error
			: new \WP_Error( 'hf_space_metadata_error', __( 'Could not load Hugging Face Space metadata.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * Parse image size into width and height.
	 *
	 * @param string $size Size string.
	 * @return int[]
	 */
	private static function parse_image_size( $size ) {
		if ( preg_match( '/^(\d+)x(\d+)$/', (string) $size, $matches ) ) {
			return array( (int) $matches[1], (int) $matches[2] );
		}

		return array( 1024, 1024 );
	}

	/**
	 * Verify a custom HTTP endpoint using a POST probe rather than a GET reachability check.
	 *
	 * @param string $space_id    Space identifier.
	 * @param string $endpoint    Endpoint URL.
	 * @param array  $credentials Provider credentials.
	 * @param array  $headers     Auth headers.
	 * @return array
	 */
	private static function verify_custom_http_endpoint( $space_id, $endpoint, $credentials, $headers ) {
		$request = self::build_images_request(
			'Verification probe',
			'1024x1024',
			1,
			self::build_model_id( $space_id, $credentials['schema_preset'] ?? '' ),
			'medium',
			'png',
			$credentials
		);

		if ( is_wp_error( $request ) ) {
			return array(
				'success' => false,
				'message' => $request->get_error_message(),
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => $request['body'],
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( 200 <= $status && $status < 300 ) {
			return array( 'success' => true );
		}

		if ( in_array( $status, array( 400, 405, 409, 415, 422, 429 ), true ) ) {
			return array( 'success' => true );
		}

		$message = is_array( $decoded ) && ! empty( $decoded['message'] )
			? (string) $decoded['message']
			: ( is_array( $decoded ) && ! empty( $decoded['error'] ) ? (string) $decoded['error'] : __( 'Hugging Face Space endpoint rejected the verification probe.', 'alorbach-ai-gateway' ) );

		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * Resolve the configured request mode for a model when possible.
	 *
	 * @param string $model Model identifier.
	 * @return string
	 */
	private static function get_request_mode_for_model( $model ) {
		$model = trim( (string) $model );
		if ( '' === $model ) {
			return '';
		}

		$entries = API_Keys_Helper::get_all_entries_for_type( 'huggingface_spaces' );
		foreach ( $entries as $entry ) {
			if ( empty( $entry['enabled'] ) ) {
				continue;
			}

			$entry_model_id = self::build_model_id( $entry['space_id'] ?? '', $entry['schema_preset'] ?? '' );
			if ( $entry_model_id === $model ) {
				return self::normalize_request_mode( $entry['request_mode'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Write a provider log entry when gateway debug mode is enabled.
	 *
	 * @param string $event   Event label.
	 * @param array  $context Event context.
	 * @return void
	 */
	private static function log_debug( $event, $context = array() ) {
		if ( ! (bool) get_option( 'alorbach_debug_enabled', false ) ) {
			return;
		}

		error_log( '[alorbach-hf-spaces] ' . sanitize_key( $event ) . ' ' . wp_json_encode( $context ) );
	}

	/**
	 * Write an error-flavored provider log entry when gateway debug mode is enabled.
	 *
	 * @param string $event   Event label.
	 * @param array  $context Event context.
	 * @return void
	 */
	private static function log_error( $event, $context = array() ) {
		self::log_debug( $event, $context );
	}
}
