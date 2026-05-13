<?php
/**
 * Browser-mediated local Codex bridge jobs.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Local_Codex_Bridge
 */
class Local_Codex_Bridge {

	const MODEL_TEXT_PREFIX = 'codex-local:';
	const MODEL_IMAGE       = 'codex-local:image';
	const JOB_TTL           = 900;

	/**
	 * Whether user-owned local Codex is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( 'alorbach_local_codex_enabled', false );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'alorbach/v1',
			'/local-codex/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'config_handler' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'alorbach/v1',
			'/local-codex/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_job_handler' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'alorbach/v1',
			'/local-codex/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete_job_handler' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);

		register_rest_route(
			'alorbach/v1',
			'/local-codex/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/fail',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'fail_job_handler' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Frontend bridge configuration.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function config_handler() {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'local_codex_disabled', __( 'Local Codex is not enabled for this site.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response(
			array(
				'enabled'        => true,
				'origin'         => self::site_origin(),
				'bridge_url'     => (string) get_option( 'alorbach_local_codex_bridge_url', 'http://127.0.0.1:8765' ),
				'text_prefix'    => self::MODEL_TEXT_PREFIX,
				'image_model'    => self::MODEL_IMAGE,
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
			return new \WP_Error( 'local_codex_disabled', __( 'Local Codex is not enabled for this site.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		$user_id = get_current_user_id();
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$type    = sanitize_key( (string) ( $params['type'] ?? '' ) );
		$payload = isset( $params['payload'] ) && is_array( $params['payload'] ) ? $params['payload'] : array();

		if ( ! in_array( $type, array( 'chat', 'image' ), true ) ) {
			return new \WP_Error( 'invalid_local_codex_type', __( 'Local Codex jobs must be chat or image jobs.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$validation = 'chat' === $type ? self::validate_chat_payload( $payload ) : self::validate_image_payload( $payload );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$capability = 'chat' === $type ? 'chat' : 'image';
		$model      = 'chat' === $type ? (string) $payload['model'] : self::MODEL_IMAGE;
		if ( ! Integration_Service::user_can_access_capability( $user_id, $capability, $model ) ) {
			return new \WP_Error( 'plan_restriction', __( 'Your plan does not allow this local Codex request.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		$rate_error = self::check_rate_limit( $user_id, 'chat' === $type ? 'chat' : 'images' );
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

		$response = 'chat' === $job['type'] ? self::normalize_chat_result( $result, $job ) : self::normalize_image_result( $result, $job );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		Ledger::insert_transaction(
			(int) $job['user_id'],
			'chat' === $job['type'] ? 'chat_deduction' : 'image_deduction',
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
			'codex-local:auto' => __( 'Local Codex (auto)', 'alorbach-ai-gateway' ),
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
			self::MODEL_IMAGE => __( 'Local Codex Image', 'alorbach-ai-gateway' ),
		);
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
		if ( 0 !== strpos( $model, self::MODEL_TEXT_PREFIX ) || self::MODEL_IMAGE === $model ) {
			return new \WP_Error( 'invalid_local_codex_model', __( 'Local Codex chat requires a codex-local text model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$messages = isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? $payload['messages'] : array();
		if ( empty( $messages ) ) {
			return new \WP_Error( 'invalid_messages', __( 'messages must be a non-empty array.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
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
		if ( self::MODEL_IMAGE !== $model ) {
			return new \WP_Error( 'invalid_local_codex_model', __( 'Local Codex images require the codex-local:image model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $prompt ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
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
			return new \WP_Error( 'invalid_local_codex_result', __( 'Local Codex image result was not valid.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$response['model'] = (string) $job['model'];
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
		$option = 'chat' === $type ? 'alorbach_local_codex_chat_fee_uc' : 'alorbach_local_codex_image_fee_uc';
		return max( 0, (int) get_option( $option, 0 ) );
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
			'chat'   => array( 'alorbach_rate_limit_chat', 100 ),
			'images' => array( 'alorbach_rate_limit_images', 30 ),
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
