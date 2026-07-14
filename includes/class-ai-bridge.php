<?php
/**
 * Browser-mediated AI Model Relay jobs.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Bridge
 */
class AI_Bridge {

	const MODEL_TEXT_PREFIX = 'codex-local:';
	const MODEL_IMAGE       = 'codex-local:image';
	const MODEL_AUDIO       = 'codex-local:audio';
	const MODEL_AUDIO_PREFIX = 'codex-local:audio:';
	const RELAY_MODEL_PREFIX = 'model-relay:';
	const LOCAL_ASR_MODEL    = 'local-asr';
	const LOCAL_ASR_PREFIX   = 'local-asr:';
	const JOB_TTL           = 900;

	/**
	 * Read a canonical setting, migrating the legacy Local Codex option on demand.
	 *
	 * @param string $name Setting suffix.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( $name, $default = null ) {
		$name      = sanitize_key( (string) $name );
		$new_key   = 'alorbach_ai_bridge_' . $name;
		$legacy_key = 'alorbach_local_codex_' . $name;
		$missing   = new \stdClass();
		$value     = get_option( $new_key, $missing );
		if ( $missing !== $value ) {
			return $value;
		}
		$legacy = get_option( $legacy_key, $missing );
		if ( $missing !== $legacy ) {
			update_option( $new_key, $legacy, false );
			return $legacy;
		}
		return $default;
	}

	/**
	 * Write canonical and legacy settings during the compatibility window.
	 *
	 * @param string $name Setting suffix.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function update_setting( $name, $value ) {
		$name = sanitize_key( (string) $name );
		update_option( 'alorbach_ai_bridge_' . $name, $value );
		update_option( 'alorbach_local_codex_' . $name, $value );
	}

	/**
	 * Whether user-owned AI Model Relay is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) self::get_setting( 'enabled', true );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		$permission = function () {
			return is_user_logged_in();
		};
		foreach ( array( '/ai-bridge', '/local-codex' ) as $base ) {
			register_rest_route( 'alorbach/v1', $base . '/config', array(
				'methods' => 'GET', 'callback' => array( __CLASS__, 'config_handler' ), 'permission_callback' => $permission,
			) );
			register_rest_route( 'alorbach/v1', $base . '/jobs', array(
				'methods' => 'POST', 'callback' => array( __CLASS__, 'create_job_handler' ), 'permission_callback' => $permission,
			) );
			register_rest_route( 'alorbach/v1', $base . '/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/complete', array(
				'methods' => 'POST', 'callback' => array( __CLASS__, 'complete_job_handler' ), 'permission_callback' => $permission,
			) );
			register_rest_route( 'alorbach/v1', $base . '/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/fail', array(
				'methods' => 'POST', 'callback' => array( __CLASS__, 'fail_job_handler' ), 'permission_callback' => $permission,
			) );
		}
	}

	/**
	 * Frontend bridge configuration.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function config_handler() {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'local_codex_disabled', __( 'AI Model Relay is not enabled for this site.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		$plan = Integration_Service::get_user_active_plan( get_current_user_id() );
		return rest_ensure_response(
			array(
				'enabled'        => true,
				'origin'         => self::site_origin(),
				'product_name'   => 'AI Model Relay',
				'bridge_url'     => (string) self::get_setting( 'bridge_url', 'http://127.0.0.1:8765' ),
				'text_prefix'    => self::MODEL_TEXT_PREFIX,
				'image_model'    => self::MODEL_IMAGE,
				'audio_model'    => self::MODEL_AUDIO,
				'audio_models'   => array_keys( self::get_audio_models() ),
				'relay_prefix'   => self::RELAY_MODEL_PREFIX,
				'canonical_routes' => array(
					'config'   => '/ai-bridge/config',
					'jobs'     => '/ai-bridge/jobs',
					'complete' => '/ai-bridge/jobs/{job_id}/complete',
					'fail'     => '/ai-bridge/jobs/{job_id}/fail',
				),
				'legacy_routes'  => array(
					'config'   => '/local-codex/config',
					'jobs'     => '/local-codex/jobs',
					'complete' => '/local-codex/jobs/{job_id}/complete',
					'fail'     => '/local-codex/jobs/{job_id}/fail',
				),
				'model_policy'   => array(
					'capabilities'    => isset( $plan['capabilities'] ) ? $plan['capabilities'] : array(),
					'allowed_models'  => isset( $plan['allowed_models'] ) ? $plan['allowed_models'] : array(),
					'empty_means_all' => true,
					'relay_wildcard'  => 'model-relay:*',
				),
				'job_ttl_seconds' => self::JOB_TTL,
			)
		);
	}

	/**
	 * Create a signed local execution job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_job_handler( $request ) {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'local_codex_disabled', __( 'AI Model Relay is not enabled for this site.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		$user_id = get_current_user_id();
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$type    = sanitize_key( (string) ( $params['type'] ?? '' ) );
		$payload = isset( $params['payload'] ) && is_array( $params['payload'] ) ? $params['payload'] : array();

		if ( ! in_array( $type, array( 'chat', 'image', 'video', 'transcribe' ), true ) ) {
			return new \WP_Error( 'invalid_local_codex_type', __( 'AI Model Relay jobs must be chat, image, video, or transcribe jobs.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		if ( 'chat' === $type ) {
			$validation = self::validate_chat_payload( $payload );
		} elseif ( 'image' === $type ) {
			$validation = self::validate_image_payload( $payload );
		} elseif ( 'video' === $type ) {
			$validation = self::validate_video_payload( $payload );
		} else {
			$validation = self::validate_transcribe_payload( $payload );
		}
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$capability = self::capability_for_type( $type );
		$model      = self::model_for_type( $type, $payload );
		if ( ! Integration_Service::user_can_access_capability( $user_id, $capability, $model ) ) {
			return new \WP_Error( 'plan_restriction', __( 'Your plan does not allow this AI Model Relay request.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		$rate_error = self::check_rate_limit( $user_id, self::rate_limit_endpoint_for_type( $type ) );
		if ( $rate_error ) {
			return $rate_error;
		}

		$quota_error = self::check_monthly_quota( $user_id );
		if ( $quota_error ) {
			return $quota_error;
		}

		$fee_uc = self::get_service_fee_uc( $type );
		if ( $fee_uc > 0 && Ledger::get_balance( $user_id ) < $fee_uc ) {
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$request_hash = self::request_hash( $user_id, $type, $payload );
		if ( Ledger::signature_exists( $request_hash ) || get_transient( 'alorbach_local_codex_hash_' . $request_hash ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$job_id = wp_generate_uuid4();
		$token  = wp_generate_password( 32, false, false );
		$job    = array(
			'job_id'       => $job_id,
			'user_id'      => $user_id,
			'type'         => $type,
			'model'        => $model,
			'payload'      => $payload,
			'request_hash' => $request_hash,
			'token_hash'   => wp_hash_password( $token ),
			'fee_uc'       => $fee_uc,
			'created_at'   => time(),
			'status'       => 'created',
		);

		set_transient( self::job_key( $job_id ), $job, self::JOB_TTL );
		set_transient( 'alorbach_local_codex_hash_' . $request_hash, $job_id, self::JOB_TTL );

		return rest_ensure_response(
			array(
				'job_id'       => $job_id,
				'job_token'    => $token,
				'request_hash' => $request_hash,
				'request_id'   => $job_id,
				'type'         => $type,
				'payload'      => $payload,
				'expires_in'   => self::JOB_TTL,
				'fee_uc'       => $fee_uc,
			)
		);
	}

	/**
	 * Complete a local execution job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function complete_job_handler( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$job    = self::load_authorized_job( $request, $params );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$result = isset( $params['result'] ) && is_array( $params['result'] ) ? $params['result'] : array();
		if ( empty( $result ) ) {
			return new \WP_Error( 'invalid_local_codex_result', __( 'Local Codex result is missing.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		if ( 'chat' === $job['type'] ) {
			$response = self::normalize_chat_result( $result, $job );
		} elseif ( 'image' === $job['type'] ) {
			$response = self::normalize_image_result( $result, $job );
		} elseif ( 'video' === $job['type'] ) {
			$response = self::normalize_video_result( $result, $job );
		} else {
			$response = self::normalize_transcribe_result( $result, $job );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		Ledger::insert_transaction(
			(int) $job['user_id'],
			self::ledger_type_for_type( (string) $job['type'] ),
			(string) $job['model'],
			- (int) $job['fee_uc'],
			isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : null,
			isset( $usage['prompt_tokens_details']['cached_tokens'] ) ? (int) $usage['prompt_tokens_details']['cached_tokens'] : null,
			isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : null,
			(string) $job['request_hash'],
			0
		);

		$response['cost_uc']      = (int) $job['fee_uc'];
		$response['cost_credits'] = User_Display::uc_to_credits( (int) $job['fee_uc'] );
		$response['cost_usd']     = User_Display::uc_to_usd( (int) $job['fee_uc'] );
		$response['ai_bridge']    = true;
		$response['local_codex']  = true;

		delete_transient( self::job_key( (string) $job['job_id'] ) );

		return rest_ensure_response( $response );
	}

	/**
	 * Mark a local job as failed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function fail_job_handler( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$job    = self::load_authorized_job( $request, $params );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$job['status'] = 'failed';
		$job['error']  = sanitize_text_field( (string) ( $params['message'] ?? __( 'Local Codex bridge failed.', 'alorbach-ai-gateway' ) ) );
		set_transient( self::job_key( (string) $job['job_id'] ), $job, self::JOB_TTL );
		delete_transient( 'alorbach_local_codex_hash_' . (string) $job['request_hash'] );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Local text models exposed by this site.
	 *
	 * @return array<string,string>
	 */
	public static function get_text_models() {
		if ( ! self::is_enabled() ) {
			return array();
		}
		return array(
			'codex-local:auto'              => __( 'Codex CLI (legacy auto)', 'alorbach-ai-gateway' ),
			'model-relay:codex:auto'        => __( 'Codex CLI (auto)', 'alorbach-ai-gateway' ),
			'model-relay:grok-cli:auto'     => __( 'Grok CLI (auto)', 'alorbach-ai-gateway' ),
			'model-relay:cursor-cli:auto'   => __( 'Cursor Agent (auto)', 'alorbach-ai-gateway' ),
		);
	}

	/**
	 * Local image models exposed by this site.
	 *
	 * @return array<string,string>
	 */
	public static function get_image_models() {
		if ( ! self::is_enabled() ) {
			return array();
		}
		return array(
			self::MODEL_IMAGE                  => __( 'Codex Image (legacy)', 'alorbach-ai-gateway' ),
			'model-relay:codex:image'          => __( 'Codex Image', 'alorbach-ai-gateway' ),
			'model-relay:grok-cli:image'       => __( 'Grok Imagine', 'alorbach-ai-gateway' ),
		);
	}

	/**
	 * Relay video models exposed by this site.
	 *
	 * @return array<string,string>
	 */
	public static function get_video_models() {
		if ( ! self::is_enabled() ) {
			return array();
		}
		return array(
			'model-relay:grok-cli:video' => __( 'Grok Imagine Video (experimental)', 'alorbach-ai-gateway' ),
		);
	}

	/**
	 * Local audio models exposed by this site.
	 *
	 * @return array<string,string>
	 */
	public static function get_audio_models() {
		if ( ! self::is_enabled() ) {
			return array();
		}
		return array(
			self::MODEL_AUDIO => __( 'Local ASR (legacy auto)', 'alorbach-ai-gateway' ),
			self::MODEL_AUDIO_PREFIX . 'whisper-large-v3' => __( 'Local Whisper Large v3', 'alorbach-ai-gateway' ),
			self::MODEL_AUDIO_PREFIX . 'whisper-medium' => __( 'Local Whisper Medium', 'alorbach-ai-gateway' ),
			self::MODEL_AUDIO_PREFIX . 'whisper-small' => __( 'Local Whisper Small', 'alorbach-ai-gateway' ),
			self::LOCAL_ASR_MODEL => __( 'Local ASR (auto)', 'alorbach-ai-gateway' ),
			self::LOCAL_ASR_PREFIX . 'whisper-large-v3' => __( 'Local Whisper Large v3', 'alorbach-ai-gateway' ),
			'model-relay:local-asr:auto' => __( 'Local ASR via Model Relay (auto)', 'alorbach-ai-gateway' ),
		);
	}

	/**
	 * Whether a local audio model id can be signed for the browser bridge.
	 *
	 * Specific known models are listed for admin/catalog fallbacks. Future bridge
	 * ASR models are accepted when they use the local audio prefix and a safe slug.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private static function is_allowed_audio_model( $model ) {
		$model          = (string) $model;
		$allowed_models = self::get_audio_models();
		if ( isset( $allowed_models[ $model ] ) ) {
			return true;
		}
		foreach ( array( self::MODEL_AUDIO_PREFIX, self::LOCAL_ASR_PREFIX, 'model-relay:local-asr:' ) as $prefix ) {
			if ( 0 === strpos( $model, $prefix ) ) {
				return self::is_safe_model_slug( substr( $model, strlen( $prefix ) ) );
			}
		}
		return self::LOCAL_ASR_MODEL === $model;
	}

	/**
	 * Whether a dynamic model can be signed for a capability.
	 *
	 * @param string $model Model ID.
	 * @param string $capability Capability.
	 * @return bool
	 */
	public static function is_supported_model_for_capability( $model, $capability ) {
		$model = (string) $model;
		if ( 'audio' === $capability ) {
			return self::is_allowed_audio_model( $model );
		}
		if ( 'image' === $capability ) {
			return self::MODEL_IMAGE === $model || self::matches_relay_model( $model, array( 'codex' => array( 'image' ), 'grok-cli' => array( 'image' ) ) );
		}
		if ( 'video' === $capability ) {
			return self::matches_relay_model( $model, array( 'grok-cli' => array( 'video' ) ) );
		}
		if ( 'chat' === $capability ) {
			if ( 0 === strpos( $model, self::MODEL_TEXT_PREFIX ) && self::MODEL_IMAGE !== $model && 0 !== strpos( $model, self::MODEL_AUDIO_PREFIX ) ) {
				return self::is_safe_model_slug( substr( $model, strlen( self::MODEL_TEXT_PREFIX ) ) );
			}
			return self::matches_relay_model( $model, array( 'codex' => true, 'grok-cli' => true, 'cursor-cli' => true ) );
		}
		return false;
	}

	private static function is_safe_model_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $slug );
	}

	private static function matches_relay_model( $model, $backends ) {
		if ( 1 !== preg_match( '/^model-relay:([a-z0-9-]+):([A-Za-z0-9][A-Za-z0-9._-]{0,127})$/', (string) $model, $matches ) ) {
			return false;
		}
		if ( ! array_key_exists( $matches[1], $backends ) ) {
			return false;
		}
		return true === $backends[ $matches[1] ] || in_array( $matches[2], $backends[ $matches[1] ], true );
	}

	/**
	 * Capability name for one local job type.
	 *
	 * @param string $type Job type.
	 * @return string
	 */
	private static function capability_for_type( $type ) {
		if ( 'image' === $type ) {
			return 'image';
		}
		if ( 'transcribe' === $type ) {
			return 'audio';
		}
		if ( 'video' === $type ) {
			return 'video';
		}
		return 'chat';
	}

	/**
	 * Rate limit endpoint for one local job type.
	 *
	 * @param string $type Job type.
	 * @return string
	 */
	private static function rate_limit_endpoint_for_type( $type ) {
		if ( 'image' === $type ) {
			return 'images';
		}
		if ( 'transcribe' === $type ) {
			return 'transcribe';
		}
		if ( 'video' === $type ) {
			return 'video';
		}
		return 'chat';
	}

	/**
	 * Ledger transaction type for one local job type.
	 *
	 * @param string $type Job type.
	 * @return string
	 */
	private static function ledger_type_for_type( $type ) {
		if ( 'image' === $type ) {
			return 'image_deduction';
		}
		if ( 'transcribe' === $type ) {
			return 'audio_deduction';
		}
		if ( 'video' === $type ) {
			return 'video_deduction';
		}
		return 'chat_deduction';
	}

	/**
	 * Resolved model for one local job type.
	 *
	 * @param string $type Job type.
	 * @param array  $payload Payload.
	 * @return string
	 */
	private static function model_for_type( $type, $payload ) {
		if ( 'transcribe' === $type ) {
			return (string) ( $payload['model'] ?? self::MODEL_AUDIO );
		}
		return (string) ( $payload['model'] ?? ( 'image' === $type ? self::MODEL_IMAGE : '' ) );
	}

	/**
	 * Get the site origin.
	 *
	 * @return string
	 */
	private static function site_origin() {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return home_url();
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Validate chat payload.
	 *
	 * @param array $payload Payload.
	 * @return true|\WP_Error
	 */
	private static function validate_chat_payload( $payload ) {
		$model = isset( $payload['model'] ) ? (string) $payload['model'] : '';
		if ( ! self::is_supported_model_for_capability( $model, 'chat' ) ) {
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay chat requires a supported Codex, Grok CLI, or Cursor Agent model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$messages = isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? $payload['messages'] : array();
		if ( empty( $messages ) ) {
			return new \WP_Error( 'invalid_messages', __( 'messages must be a non-empty array.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( count( $messages ) > 100 || strlen( wp_json_encode( $messages ) ) > 1048576 ) {
			return new \WP_Error( 'invalid_messages', __( 'Chat payload exceeds the AI Model Relay bounds.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['role'] ) || ! array_key_exists( 'content', $message ) ) {
				return new \WP_Error( 'invalid_messages', __( 'Each message must have a role and content.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Validate image payload.
	 *
	 * @param array $payload Payload.
	 * @return true|\WP_Error
	 */
	private static function validate_image_payload( $payload ) {
		$prompt = isset( $payload['prompt'] ) ? trim( (string) $payload['prompt'] ) : '';
		$model  = isset( $payload['model'] ) ? (string) $payload['model'] : self::MODEL_IMAGE;
		if ( ! self::is_supported_model_for_capability( $model, 'image' ) ) {
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay images require a supported Codex or Grok Imagine model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $prompt ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( strlen( $prompt ) > 32768 ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt exceeds the AI Model Relay limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		if ( ! empty( $payload['reference_images'] ) ) {
			if ( ! is_array( $payload['reference_images'] ) || count( $payload['reference_images'] ) > 4 ) {
				return new \WP_Error( 'invalid_reference_image', __( 'AI Model Relay images accept up to four reference images.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
			foreach ( $payload['reference_images'] as $reference ) {
				if ( ! self::is_image_data_url( $reference ) || strlen( $reference ) > 16777216 ) {
					return new \WP_Error( 'invalid_reference_image', __( 'Image references must be PNG, JPEG, or WebP data URLs.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
				}
			}
		}
		return true;
	}

	/**
	 * Validate video payload.
	 *
	 * @param array $payload Payload.
	 * @return true|\WP_Error
	 */
	private static function validate_video_payload( $payload ) {
		$model  = isset( $payload['model'] ) ? (string) $payload['model'] : '';
		$prompt = isset( $payload['prompt'] ) ? trim( (string) $payload['prompt'] ) : '';
		if ( ! self::is_supported_model_for_capability( $model, 'video' ) ) {
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay video requires the experimental Grok Imagine video model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $prompt ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( strlen( $prompt ) > 32768 ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt exceeds the AI Model Relay limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		if ( ! empty( $payload['input_reference'] ) && ( ! self::is_image_data_url( $payload['input_reference'] ) || strlen( $payload['input_reference'] ) > 16777216 ) ) {
			return new \WP_Error( 'invalid_reference_image', __( 'Video reference must be a PNG, JPEG, or WebP data URL.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private static function is_image_data_url( $value ) {
		return is_string( $value ) && 1 === preg_match( '#^data:image/(?:png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=\r\n]+$#i', $value );
	}

	/**
	 * Validate transcribe payload.
	 *
	 * @param array $payload Payload.
	 * @return true|\WP_Error
	 */
	private static function validate_transcribe_payload( $payload ) {
		$model = isset( $payload['model'] ) ? (string) $payload['model'] : self::MODEL_AUDIO;
		if ( ! self::is_allowed_audio_model( $model ) ) {
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay transcription requires a supported Local ASR model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$audio_base64 = isset( $payload['audio_base64'] ) ? (string) $payload['audio_base64'] : '';
		if ( '' === $audio_base64 ) {
			return new \WP_Error( 'invalid_audio', __( 'audio_base64 is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( strlen( $audio_base64 ) > 67108864 ) {
			return new \WP_Error( 'audio_too_large', __( 'Audio file exceeds the 48 MB limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		if ( false === base64_decode( $audio_base64, true ) ) {
			return new \WP_Error( 'invalid_audio', __( 'Invalid base64 audio.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( empty( $payload['duration_seconds'] ) || (int) $payload['duration_seconds'] <= 0 ) {
			return new \WP_Error( 'invalid_duration', __( 'duration_seconds is required for Local Codex transcription.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Load a job and validate user, token, and hash.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param array            $params JSON params.
	 * @return array|\WP_Error
	 */
	private static function load_authorized_job( $request, $params ) {
		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$job    = get_transient( self::job_key( $job_id ) );
		if ( ! is_array( $job ) ) {
			return new \WP_Error( 'local_codex_job_expired', __( 'Local Codex job expired or does not exist.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}
		if ( (int) $job['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'local_codex_wrong_user', __( 'Local Codex job belongs to another user.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}
		$token = isset( $params['job_token'] ) ? (string) $params['job_token'] : '';
		if ( '' === $token || ! wp_check_password( $token, (string) $job['token_hash'] ) ) {
			return new \WP_Error( 'local_codex_bad_token', __( 'Local Codex job token is invalid.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}
		if ( (string) ( $params['request_hash'] ?? '' ) !== (string) $job['request_hash'] ) {
			return new \WP_Error( 'local_codex_hash_mismatch', __( 'Local Codex request hash changed.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}
		if ( Ledger::signature_exists( (string) $job['request_hash'] ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}
		return $job;
	}

	/**
	 * Normalize chat bridge result.
	 *
	 * @param array $result Bridge result.
	 * @param array $job Job.
	 * @return array|\WP_Error
	 */
	private static function normalize_chat_result( $result, $job ) {
		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : $result;
		if ( empty( $response['choices'] ) || ! is_array( $response['choices'] ) ) {
			return new \WP_Error( 'invalid_local_codex_result', __( 'Local Codex chat result was not valid.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$response['model'] = (string) $job['model'];
		return $response;
	}

	/**
	 * Normalize image bridge result.
	 *
	 * @param array $result Bridge result.
	 * @param array $job Job.
	 * @return array|\WP_Error
	 */
	private static function normalize_image_result( $result, $job ) {
		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : $result;
		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return new \WP_Error( 'invalid_local_codex_result', __( 'AI Model Relay image result was not valid.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		foreach ( $response['data'] as $image ) {
			$encoded = is_array( $image ) && isset( $image['b64_json'] ) ? preg_replace( '/\s+/', '', (string) $image['b64_json'] ) : '';
			if ( '' === $encoded || false === base64_decode( $encoded, true ) ) {
				return new \WP_Error( 'invalid_local_codex_result', __( 'AI Model Relay image result did not contain valid base64 image data.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
		}
		$response['model'] = (string) $job['model'];
		return $response;
	}

	/**
	 * Normalize an experimental relay video result.
	 *
	 * @param array $result Bridge result.
	 * @param array $job Job.
	 * @return array|\WP_Error
	 */
	private static function normalize_video_result( $result, $job ) {
		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : $result;
		$encoded  = isset( $response['b64_video'] ) ? preg_replace( '/\s+/', '', (string) $response['b64_video'] ) : '';
		$mime     = isset( $response['mime_type'] ) ? strtolower( (string) $response['mime_type'] ) : 'video/mp4';
		if ( '' === $encoded || false === base64_decode( $encoded, true ) || ! in_array( $mime, array( 'video/mp4', 'video/webm', 'video/quicktime' ), true ) ) {
			return new \WP_Error( 'invalid_local_codex_result', __( 'AI Model Relay video result was not valid.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$response['b64_video'] = $encoded;
		$response['mime_type'] = $mime;
		$response['model']     = (string) $job['model'];
		$response['data']      = array( array( 'b64_video' => $encoded, 'mime_type' => $mime ) );
		return $response;
	}

	/**
	 * Normalize transcribe bridge result.
	 *
	 * @param array $result Bridge result.
	 * @param array $job Job.
	 * @return array|\WP_Error
	 */
	private static function normalize_transcribe_result( $result, $job ) {
		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : $result;
		$words = isset( $response['words'] ) && is_array( $response['words'] ) ? $response['words'] : array();
		if ( empty( $words ) ) {
			return new \WP_Error( 'invalid_local_codex_result', __( 'Local Codex transcription result did not include word timing.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		foreach ( $words as $word ) {
			if ( ! is_array( $word ) || ! isset( $word['word'], $word['start'], $word['end'] ) ) {
				return new \WP_Error( 'invalid_local_codex_result', __( 'Local Codex transcription word timing was not valid.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
		}
		$response['model'] = (string) $job['model'];
		$response['ai_bridge'] = true;
		$response['local_codex'] = true;
		return $response;
	}

	/**
	 * Build request hash.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type Type.
	 * @param array  $payload Payload.
	 * @return string
	 */
	private static function request_hash( $user_id, $type, $payload ) {
		return hash( 'sha256', wp_json_encode( array( (int) $user_id, (string) $type, $payload ) ) );
	}

	/**
	 * Transient key for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private static function job_key( $job_id ) {
		return 'alorbach_local_codex_job_' . sanitize_key( (string) $job_id );
	}

	/**
	 * Optional site fee in UC.
	 *
	 * @param string $type Type.
	 * @return int
	 */
	private static function get_service_fee_uc( $type ) {
		if ( 'image' === $type ) {
			$name = 'image_fee_uc';
		} elseif ( 'transcribe' === $type ) {
			$name = 'audio_fee_uc';
		} elseif ( 'video' === $type ) {
			$name = 'video_fee_uc';
		} else {
			$name = 'chat_fee_uc';
		}
		return max( 0, (int) self::get_setting( $name, 0 ) );
	}

	/**
	 * Local rate limiter equivalent.
	 *
	 * @param int    $user_id User ID.
	 * @param string $endpoint Endpoint.
	 * @return \WP_Error|null
	 */
	private static function check_rate_limit( $user_id, $endpoint ) {
		$window     = max( 10, (int) get_option( 'alorbach_rate_limit_window', 60 ) );
		$option_map = array(
			'chat'       => array( 'alorbach_rate_limit_chat', 100 ),
			'images'     => array( 'alorbach_rate_limit_images', 30 ),
			'transcribe' => array( 'alorbach_rate_limit_transcribe', 30 ),
			'video'      => array( 'alorbach_rate_limit_video', 10 ),
		);
		list( $option_key, $default ) = isset( $option_map[ $endpoint ] ) ? $option_map[ $endpoint ] : array( '', 60 );
		$limit     = $option_key ? max( 1, (int) get_option( $option_key, $default ) ) : $default;
		$cache_key = 'alorbach_rl_' . (int) $user_id . '_' . $endpoint;
		$count     = (int) get_transient( $cache_key );
		if ( $count >= $limit ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: %d: rate limit window in seconds */
					__( 'Too many requests. Please wait %d seconds before trying again.', 'alorbach-ai-gateway' ),
					$window
				),
				array( 'status' => 429 )
			);
		}
		set_transient( $cache_key, $count + 1, $window );
		return null;
	}

	/**
	 * Check monthly quota.
	 *
	 * @param int $user_id User ID.
	 * @return \WP_Error|null
	 */
	private static function check_monthly_quota( $user_id ) {
		$quota = (int) get_option( 'alorbach_monthly_quota_uc', 0 );
		if ( $quota <= 0 ) {
			return null;
		}
		if ( Ledger::get_usage_this_month( $user_id ) >= $quota ) {
			return new \WP_Error( 'monthly_quota_exceeded', __( 'Monthly usage quota exceeded. Please upgrade your plan or wait until next month.', 'alorbach-ai-gateway' ), array( 'status' => 429 ) );
		}
		return null;
	}
}
