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
	const LOCAL_UPSCALE_PREFIX = 'model-relay:local-upscale:';
	const LOCAL_UPSCALE_MODELS = array( 'model-relay:local-upscale:swinir-classical-x2', 'model-relay:local-upscale:realesrgan-x2plus' );
	const RELAY_IMAGE_MODELS = array(
		'model-relay:codex:image'            => 'Codex Image',
		'model-relay:grok-cli:image'         => 'Grok Imagine',
		'model-relay:xai:imagine-image'      => 'xAI Imagine',
		'model-relay:antigravity-cli:image'  => 'Antigravity Image',
	);
	const IMAGE_CAPABILITY_CONTRACT_VERSION = 1;
	const MINIMUM_RELAY_VERSION = '1.0.10';
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
			register_rest_route( 'alorbach/v1', $base . '/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/receipt', array(
				'methods' => 'GET', 'callback' => array( __CLASS__, 'receipt_handler' ), 'permission_callback' => $permission,
			) );
			register_rest_route( 'alorbach/v1', $base . '/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/fail', array(
				'methods' => 'POST', 'callback' => array( __CLASS__, 'fail_job_handler' ), 'permission_callback' => $permission,
			) );
			register_rest_route( 'alorbach/v1', $base . '/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/cancel', array(
				'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_job_handler' ), 'permission_callback' => $permission,
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
		$integration_config = Integration_Service::get_integration_config( get_current_user_id() );
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
				'local_upscale'  => array( 'enabled' => true, 'models' => self::LOCAL_UPSCALE_MODELS, 'binary_transfer' => true, 'unmetered' => true ),
				'relay_prefix'   => self::RELAY_MODEL_PREFIX,
				'image_capability_contract_version' => self::IMAGE_CAPABILITY_CONTRACT_VERSION,
				'minimum_relay_version' => self::MINIMUM_RELAY_VERSION,
				'image_model_capabilities' => $integration_config['capabilities']['image_model_capabilities'] ?? array(),
				'canonical_routes' => array(
					'config'   => '/ai-bridge/config',
					'jobs'     => '/ai-bridge/jobs',
					'complete' => '/ai-bridge/jobs/{job_id}/complete',
					'receipt'  => '/ai-bridge/jobs/{job_id}/receipt',
					'fail'     => '/ai-bridge/jobs/{job_id}/fail',
					'cancel'   => '/ai-bridge/jobs/{job_id}/cancel',
				),
				'legacy_routes'  => array(
					'config'   => '/local-codex/config',
					'jobs'     => '/local-codex/jobs',
					'complete' => '/local-codex/jobs/{job_id}/complete',
					'receipt'  => '/local-codex/jobs/{job_id}/receipt',
					'fail'     => '/local-codex/jobs/{job_id}/fail',
					'cancel'   => '/local-codex/jobs/{job_id}/cancel',
				),
				'model_policy'   => array(
					'capabilities'    => isset( $plan['capabilities'] ) ? $plan['capabilities'] : array(),
					'allowed_models'  => isset( $plan['allowed_models'] ) ? $plan['allowed_models'] : array(),
					'empty_means_all' => true,
					'relay_wildcard'  => 'model-relay:*',
				),
				'job_ttl_seconds' => self::JOB_TTL,
				'supports_cancel' => true,
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

		if ( ! in_array( $type, array( 'chat', 'image', 'video', 'transcribe', 'upscale' ), true ) ) {
			return new \WP_Error( 'invalid_local_codex_type', __( 'AI Model Relay jobs must be chat, image, video, transcribe, or local upscale jobs.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		if ( 'chat' === $type ) {
			$validation = self::validate_chat_payload( $payload );
		} elseif ( 'image' === $type ) {
			$validation = self::validate_image_payload( $payload );
		} elseif ( 'video' === $type ) {
			$validation = self::validate_video_payload( $payload );
		} elseif ( 'upscale' === $type ) {
			$validation = self::validate_upscale_payload( $payload );
		} else {
			$validation = self::validate_transcribe_payload( $payload );
		}
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$model      = self::model_for_type( $type, $payload );
		$capability = self::capability_for_type( $type );
		if ( 'upscale' !== $type && ! Integration_Service::user_can_access_capability( $user_id, $capability, $model ) ) {
			return new \WP_Error( 'plan_restriction', __( 'Your plan does not allow this AI Model Relay request.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		if ( 'upscale' !== $type ) {
			$rate_error = self::check_rate_limit( $user_id, self::rate_limit_endpoint_for_type( $type ) );
			if ( $rate_error ) return $rate_error;
			$quota_error = self::check_monthly_quota( $user_id );
			if ( $quota_error ) return $quota_error;
		}

		$fee_uc = 'upscale' === $type ? 0 : self::get_service_fee_uc( $type );
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
		$job    = self::load_authorized_job( $request, $params, array( 'created', 'completed_pending_receipt' ), true );
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
		} elseif ( 'upscale' === $job['type'] ) {
			$response = self::normalize_upscale_result( $result, $job );
		} else {
			$response = self::normalize_transcribe_result( $result, $job );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 'chat' === $job['type'] ) {
			/* The downstream Studio receives the browser-relayed response. Bind the
			 * server-owned receipt to the canonical assistant choices so that a
			 * browser cannot replace a genuine completed review with another valid
			 * JSON report after the Gateway job has completed. */
			$response['result_digest'] = self::chat_result_digest( $response );
		}
		$completion_digest = self::completion_response_digest( $response );
		$already_charged = 'upscale' !== $job['type'] && Ledger::signature_exists( (string) $job['request_hash'] );
		if ( $already_charged && 'completed_pending_receipt' !== (string) ( $job['status'] ?? '' ) ) {
			return new \WP_Error( 'local_codex_completion_state_lost', __( 'The completed Local Codex result is already charged but its retry checkpoint is unavailable. Do not submit a different result.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}
		if ( 'completed_pending_receipt' === (string) ( $job['status'] ?? '' ) ) {
			if ( ! hash_equals( (string) ( $job['completion_digest'] ?? '' ), $completion_digest ) ) {
				return new \WP_Error( 'local_codex_completion_mismatch', __( 'The retry result does not match the already submitted Local Codex result.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
			}
		} else {
			/* Record the normalized result in the signed job before billing. If
			 * receipt storage fails after billing, the exact result remains available
			 * for an authenticated, idempotent completion retry. Keep chat output for
			 * digest verification; binary jobs need only their digest here. */
			$job['status'] = 'completed_pending_receipt';
			$job['completion_digest'] = $completion_digest;
			if ( 'chat' === $job['type'] ) {
				$job['completion_response'] = $response;
			}
			$stored_job = set_transient( self::job_key( (string) $job['job_id'] ), $job, self::JOB_TTL );
			$verified_job = get_transient( self::job_key( (string) $job['job_id'] ) );
			if ( false === $stored_job || ! is_array( $verified_job ) || ! hash_equals( (string) ( $verified_job['completion_digest'] ?? '' ), $completion_digest ) ) {
				return new \WP_Error( 'local_codex_completion_checkpoint_failed', __( 'AI Model Relay could not checkpoint the completion safely. Retry the same completion.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
			}
		}
		if ( $already_charged && 'chat' === $job['type'] && isset( $job['completion_response'] ) && is_array( $job['completion_response'] ) ) {
			$response = $job['completion_response'];
		}

		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		if ( 'upscale' !== $job['type'] && ! $already_charged ) {
			$ledger_result = Ledger::insert_transaction(
				(int) $job['user_id'], self::ledger_type_for_type( (string) $job['type'] ), (string) $job['model'], - (int) $job['fee_uc'], isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : null, isset( $usage['prompt_tokens_details']['cached_tokens'] ) ? (int) $usage['prompt_tokens_details']['cached_tokens'] : null, isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : null, (string) $job['request_hash'], 0
			);
			if ( false === $ledger_result && ! Ledger::signature_exists( (string) $job['request_hash'] ) ) {
				return new \WP_Error( 'local_codex_billing_persist_failed', __( 'AI Model Relay could not record the completion charge. Retry the same completion.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
			}
		}

		$response['cost_uc']      = (int) $job['fee_uc'];
		$response['cost_credits'] = User_Display::uc_to_credits( (int) $job['fee_uc'] );
		$response['cost_usd']     = User_Display::uc_to_usd( (int) $job['fee_uc'] );
		$response['ai_bridge']    = true;
		$response['local_codex']  = true;
		if ( 'upscale' === $job['type'] ) $response['local_unmetered'] = true;

		$receipt_error = self::persist_completion_receipt( $job, $response );
		if ( is_wp_error( $receipt_error ) ) {
			/* Keep the signed job transient intact so the browser can retry the
			 * completion after a storage outage. Never acknowledge a charged result
			 * unless the downstream receipt can be read back durably. */
			return $receipt_error;
		}
		delete_transient( self::job_key( (string) $job['job_id'] ) );

		return rest_ensure_response( $response );
	}

	/**
	 * Read a completed, redacted relay receipt for the current user.
	 *
	 * The receipt deliberately omits provider envelopes and binary result data.
	 * A downstream product may compare its independently uploaded private bytes
	 * to this manifest, but cannot use this endpoint to obtain image bytes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receipt_handler( $request ) {
		$job_id  = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$receipt = get_transient( self::receipt_key( $job_id ) );
		if ( ! is_array( $receipt ) ) {
			$receipt = get_option( self::receipt_option_key( $job_id ), null );
		}
		if ( ! is_array( $receipt ) ) {
			return new \WP_Error( 'local_codex_receipt_not_found', __( 'AI Model Relay completion receipt was not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}
		if ( (int) ( $receipt['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'local_codex_wrong_user', __( 'AI Model Relay job belongs to another user.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}

		unset( $receipt['user_id'] );
		return rest_ensure_response( $receipt );
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

	/** Cancel a signed job before the browser Relay starts provider execution. */
	public static function cancel_job_handler( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$job = self::load_authorized_job( $request, $params, array( 'created', 'cancelled' ) );
		if ( is_wp_error( $job ) ) return $job;
		if ( 'cancelled' === (string) ( $job['status'] ?? '' ) ) return rest_ensure_response( array( 'success' => true, 'status' => 'cancelled', 'job_id' => (string) $job['job_id'] ) );
		$job['status'] = 'cancelled';
		$job['cancelled_at'] = time();
		set_transient( self::job_key( (string) $job['job_id'] ), $job, self::JOB_TTL );
		delete_transient( 'alorbach_local_codex_hash_' . (string) $job['request_hash'] );
		return rest_ensure_response( array( 'success' => true, 'status' => 'cancelled', 'job_id' => (string) $job['job_id'] ) );
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
		return array_merge(
			array( self::MODEL_IMAGE => __( 'Codex Image (legacy)', 'alorbach-ai-gateway' ) ),
			array_map( static fn( $label ) => __( $label, 'alorbach-ai-gateway' ), self::RELAY_IMAGE_MODELS )
		);
	}

	/**
	 * Publish the versioned contract for image models executed by the paired
	 * browser relay. This is capability evidence owned by the Gateway/Relay
	 * integration; downstream products must not infer it from a model ID.
	 *
	 * The browser still has to establish an origin-scoped pairing before it can
	 * execute a signed job. The contract intentionally says nothing about
	 * cancellation, streaming progress, or previews because the relay does not
	 * implement those provider controls.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_integration_model_capabilities() {
		if ( ! self::is_enabled() ) {
			return array();
		}

		return array(
			self::relay_image_contract( 'model-relay:codex:image', 'Codex Image', 'codex-cli', array( 'text_to_image', 'image_edit' ), array( 'guidance' => true, 'native' => false ), array(), array( 'low', 'medium', 'high' ), array( '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2560x1440', '1440x2560', '3840x2160', '2160x3840' ), 4, false ),
			self::relay_image_contract( 'model-relay:grok-cli:image', 'Grok Imagine', 'grok-cli', array( 'text_to_image', 'image_edit' ), array( 'guidance' => true, 'native' => false, 'ratio_native' => true ), array( '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '2:1', '1:2', '19.5:9', '9:19.5', '20:9', '9:20', '21:9', '5:2' ), array(), array( '2k', '1k' ), 4, false ),
			self::relay_image_contract( 'model-relay:xai:imagine-image', 'xAI Imagine', 'xai-api', array( 'text_to_image', 'image_edit' ), array( 'guidance' => false, 'native' => true ), array( '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '2:1', '1:2', '19.5:9', '9:19.5', '20:9', '9:20', '21:9', '5:2' ), array( 'medium', 'low' ), array( '2k', '1k' ), 3, true, 3 ),
			self::relay_image_contract( 'model-relay:antigravity-cli:image', 'Antigravity Image', 'antigravity-cli', array( 'text_to_image' ), array( 'guidance' => true, 'native' => false ), array( '1:1', '2:3', '3:2', '4:3', '3:4', '16:9', '9:16', '21:9' ), array(), array( '1K', '2K', '4K' ), 4, false ),
		);
	}

	/** Build a safe, provider-neutral Relay image contract for downstream products. */
	private static function relay_image_contract( $model, $label, $backend, $operation_kinds, $resolution_modes, $ratios, $qualities, $resolution_options, $reference_max, $cloud_upload, $candidate_count_max = 1, $quality_delivery = '' ) {
		$options = array();
		$resolution_native = ! empty( $resolution_modes['resolution_native'] ?? $resolution_modes['native'] );
		$resolution_delivery = $resolution_native ? 'native_scale' : 'guidance';
		foreach ( array_values( $resolution_options ) as $index => $value ) $options[] = array( 'value' => $value, 'rank' => $index + 1, 'delivery' => $resolution_delivery );
		$quality_delivery = $quality_delivery ?: ( ! empty( $qualities ) && $resolution_native ? 'native' : 'guidance' );
		$provider_size_key = 'codex-cli' === $backend ? 'size' : 'resolution';
		$provider_options = array(
			$provider_size_key => array( 'type' => 'enum', 'delivery' => $resolution_delivery, 'values' => array_values( $resolution_options ) ),
		);
		if ( 'antigravity-cli' === $backend ) {
			$provider_options['image_size'] = array( 'type' => 'enum', 'delivery' => 'guidance', 'values' => array_values( $resolution_options ) );
			unset( $provider_options['resolution'] );
		}
		return array(
			'gateway_model_key' => $model,
			'label' => __( $label, 'alorbach-ai-gateway' ),
			'provider' => 'model-relay',
			'backend' => $backend,
			'transport' => 'async_image',
			'eligible' => true,
			'direct_dispatch_evidenced' => false,
			'image_capabilities_evidenced' => true,
			'requires_browser_pairing' => true,
			'supported_languages' => array( 'de-DE', 'en-US' ),
			'operation_kinds' => $operation_kinds,
			'cloud_upload' => $cloud_upload,
			'image_capabilities' => array(
				'contract_version' => self::IMAGE_CAPABILITY_CONTRACT_VERSION,
				'async_jobs' => true,
				'provider_progress' => false,
				'preview_images' => false,
				'reference_images' => $reference_max > 0,
				'reference_images_max' => $reference_max,
				'provider_cancel' => false,
				'candidate_count_max' => max( 1, absint( $candidate_count_max ) ),
				'supported_sizes' => $resolution_options,
				'resolution_mode' => ! empty( $resolution_modes['native'] ) ? 'native_scale' : 'guidance',
				'resolution_options' => $options,
				'supported_qualities' => $qualities,
				'quality_delivery' => $quality_delivery,
				'supported_aspect_ratios' => $ratios,
				'aspect_ratio_delivery' => in_array( $backend, array( 'grok-cli', 'xai-api' ), true ) ? 'native' : ( empty( $ratios ) ? '' : 'guidance' ),
				'supported_output_formats' => array( 'image/png', 'image/jpeg', 'image/webp' ),
			'provider_options' => $provider_options,
			),
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
		if ( 'local_upscale' === $capability ) {
			return in_array( $model, self::LOCAL_UPSCALE_MODELS, true );
		}
		if ( self::matches_relay_model( $model ) ) {
			$known_capability = self::known_relay_model_capability( $model );
			return '' === $known_capability || $capability === $known_capability;
		}
		if ( 'audio' === $capability ) {
			return self::is_allowed_audio_model( $model );
		}
		if ( 'image' === $capability ) {
			return self::MODEL_IMAGE === $model;
		}
		if ( 'video' === $capability ) {
			return false;
		}
		if ( 'chat' === $capability ) {
			if ( 0 === strpos( $model, self::MODEL_TEXT_PREFIX ) && self::MODEL_IMAGE !== $model && 0 !== strpos( $model, self::MODEL_AUDIO_PREFIX ) ) {
				return self::is_safe_model_slug( substr( $model, strlen( self::MODEL_TEXT_PREFIX ) ) );
			}
			return false;
		}
		return false;
	}

	/**
	 * Resolve a capability from the stable relay model suffixes we publish.
	 * Unknown, safely formed IDs remain deferred to the paired relay so future
	 * backends can be introduced without a Gateway release.
	 *
	 * @param string $model Relay model ID.
	 * @return string Empty when the capability is not encoded in the ID.
	 */
	private static function known_relay_model_capability( $model ) {
		if ( 0 === strpos( $model, self::LOCAL_UPSCALE_PREFIX ) ) {
			return 'local_upscale';
		}
		if ( 0 === strpos( $model, 'model-relay:local-asr:' ) ) {
			return 'audio';
		}
		if ( ':image' === substr( $model, -6 ) ) {
			return 'image';
		}
		if ( ':video' === substr( $model, -6 ) ) {
			return 'video';
		}
		if ( ':auto' === substr( $model, -5 ) ) {
			return 'chat';
		}
		return '';
	}

	private static function is_safe_model_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $slug );
	}

	private static function matches_relay_model( $model ) {
		return 1 === preg_match( '/^model-relay:[a-z0-9][a-z0-9-]{0,63}:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', (string) $model );
	}

	/**
	 * Capability name for one local job type.
	 *
	 * @param string $type Job type.
	 * @return string
	 */
	private static function capability_for_type( $type ) {
		if ( 'upscale' === $type ) {
			return 'local_upscale';
		}
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
		if ( 'upscale' === $type ) {
			return 'local_upscale';
		}
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
		if ( 'upscale' === $type ) {
			return 'image_deduction';
		}
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
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay chat requires a safely formed relay model ID.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
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
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay images require a safely formed relay model ID.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $prompt ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( strlen( $prompt ) > 32768 ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt exceeds the AI Model Relay limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		$model_contract = self::image_model_contract( $model );
		if ( null !== $model_contract && ! self::image_options_are_supported( $payload, $model_contract ) ) {
			return new \WP_Error( 'invalid_local_codex_image_options', __( 'The requested image size, quality, format, or candidate count is not supported by the AI Model Relay model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $payload['reference_images'] ) ) {
			$reference_max = null !== $model_contract ? (int) ( $model_contract['image_capabilities']['reference_images_max'] ?? 0 ) : 4;
			if ( ! is_array( $payload['reference_images'] ) || $reference_max < 1 || count( $payload['reference_images'] ) > $reference_max ) {
				return new \WP_Error( 'invalid_reference_image', __( 'The selected AI Model Relay model does not support this number of reference images.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
			foreach ( $payload['reference_images'] as $reference ) {
				if ( ! self::is_image_reference( $reference ) ) {
					return new \WP_Error( 'invalid_reference_image', __( 'Image references must be PNG, JPEG, or WebP data URLs or base64 image objects.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
				}
			}
		}
		return true;
	}

	/** Validate metadata only; source and result PNG bytes use the paired Relay binary route. */
	private static function validate_upscale_payload( $payload ) {
		$model = isset( $payload['model'] ) ? (string) $payload['model'] : '';
		if ( ! self::is_supported_model_for_capability( $model, 'local_upscale' ) ) return new \WP_Error( 'invalid_local_upscale_model', __( 'Choose a supported local CUDA upscale model.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		if ( 2 !== (int) ( $payload['scale'] ?? 0 ) || 'png' !== strtolower( (string) ( $payload['output_format'] ?? '' ) ) ) return new \WP_Error( 'invalid_local_upscale_payload', __( 'Local upscaling requires exactly ×2 PNG output.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		if ( ! is_numeric( $payload['source_asset_id'] ?? null ) || ! wp_is_uuid( (string) ( $payload['source_asset_uuid'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) ( $payload['source_checksum'] ?? '' ) ) ) ) return new \WP_Error( 'invalid_local_upscale_source', __( 'Local upscaling requires an immutable source asset manifest.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		$source = isset( $payload['source_dimensions'] ) && is_array( $payload['source_dimensions'] ) ? $payload['source_dimensions'] : array();
		$target = isset( $payload['target_print'] ) && is_array( $payload['target_print'] ) ? $payload['target_print'] : array();
		$crop = isset( $payload['crop'] ) && is_array( $payload['crop'] ) ? $payload['crop'] : array();
		$crop_pixels = isset( $payload['crop_pixels'] ) && is_array( $payload['crop_pixels'] ) ? $payload['crop_pixels'] : array();
		$output = isset( $payload['output_print'] ) && is_array( $payload['output_print'] ) ? $payload['output_print'] : array();
		$source_width = (int) ( $source['width'] ?? 0 ); $source_height = (int) ( $source['height'] ?? 0 );
		$target_width = (int) ( $target['width'] ?? 0 ); $target_height = (int) ( $target['height'] ?? 0 ); $dpi = (int) ( $target['dpi'] ?? 0 );
		if ( $source_width < 1 || $source_height < 1 || $target_width < 1 || $target_height < 1 || $target_width > 12000 || $target_height > 12000 || $dpi < 72 || $dpi > 1200 ) return new \WP_Error( 'invalid_local_upscale_target', __( 'Local upscaling requires valid profile-derived target dimensions and DPI.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		$x = isset( $crop['x'] ) ? (float) $crop['x'] : -1; $y = isset( $crop['y'] ) ? (float) $crop['y'] : -1; $width = isset( $crop['width'] ) ? (float) $crop['width'] : 0; $height = isset( $crop['height'] ) ? (float) $crop['height'] : 0;
		$fit_policy = sanitize_key( (string) ( $payload['fit_policy'] ?? 'crop_to_safe_box' ) );
		$no_crop = in_array( $fit_policy, array( 'contain_no_crop', 'edge_fade_no_crop' ), true );
		$crop_is_valid = $x >= 0 && $y >= 0 && $width > 0 && $height > 0 && $x + $width <= 1.000001 && $y + $height <= 1.000001;
		if ( $no_crop ) {
			if ( ! $crop_is_valid || abs( $x ) > 0.000001 || abs( $y ) > 0.000001 || abs( $width - 1.0 ) > 0.000001 || abs( $height - 1.0 ) > 0.000001 || rest_sanitize_boolean( $crop['approved_by_user'] ?? false ) ) return new \WP_Error( 'invalid_local_upscale_crop', __( 'No-crop local upscaling requires the complete source frame without crop approval.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		} elseif ( ! rest_sanitize_boolean( $crop['approved_by_user'] ?? false ) || ! $crop_is_valid || abs( ( $source_width * $width ) / ( $source_height * $height ) - ( $target_width / $target_height ) ) > 0.002 ) return new \WP_Error( 'invalid_local_upscale_crop', __( 'Approve an in-bounds crop matching the profile artwork ratio before local upscaling.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		$left = (int) ( $crop_pixels['left'] ?? -1 ); $top = (int) ( $crop_pixels['top'] ?? -1 ); $right = (int) ( $crop_pixels['right'] ?? -1 ); $bottom = (int) ( $crop_pixels['bottom'] ?? -1 );
		$crop_width = (int) ( $crop_pixels['width'] ?? 0 ); $crop_height = (int) ( $crop_pixels['height'] ?? 0 );
		$output_width = (int) ( $output['width'] ?? 0 ); $output_height = (int) ( $output['height'] ?? 0 );
		if ( 'retain_native_x2' !== (string) ( $payload['output_policy'] ?? '' ) || $left !== (int) round( $x * $source_width ) || $top !== (int) round( $y * $source_height ) || $right !== (int) round( ( $x + $width ) * $source_width ) || $bottom !== (int) round( ( $y + $height ) * $source_height ) || $left < 0 || $top < 0 || $right <= $left || $bottom <= $top || $right > $source_width || $bottom > $source_height || $crop_width !== $right - $left || $crop_height !== $bottom - $top || $output_width !== $crop_width * 2 || $output_height !== $crop_height * 2 || $output_width < $target_width || $output_height < $target_height ) return new \WP_Error( 'invalid_local_upscale_output_contract', __( 'Local upscaling requires an approved native ×2 output derived from the reviewed crop.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		return true;
	}

	/** @return array<string,mixed>|null */
	private static function image_model_contract( $model ) {
		foreach ( self::get_integration_model_capabilities() as $contract ) {
			if ( is_array( $contract ) && $model === ( $contract['gateway_model_key'] ?? null ) ) {
				return $contract;
			}
		}
		return null;
	}

	/** Validate a non-billable estimate for a Relay image without exposing secrets. */
	public static function validate_image_estimate_request( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$model = sanitize_text_field( (string) ( $payload['model'] ?? '' ) );
		$contract = self::image_model_contract( $model );
		if ( null === $contract ) return null;
		if ( isset( $payload['provider_options'] ) && is_string( $payload['provider_options'] ) ) {
			$decoded = json_decode( (string) $payload['provider_options'], true );
			$payload['provider_options'] = is_array( $decoded ) ? $decoded : array();
		}
		$payload['size'] = (string) ( $payload['size'] ?? $payload['provider_size'] ?? '' );
		$payload['output_format'] = self::normalize_image_estimate_format( $payload['output_format'] ?? 'image/png' );
		$payload['candidate_count'] = max( 1, (int) ( $payload['candidate_count'] ?? $payload['n'] ?? 1 ) );
		if ( ! self::image_options_are_supported( $payload, $contract ) ) return new \WP_Error( 'relay_image_options_unsupported', __( 'The selected Relay image options are not supported by this provider.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		return array( 'model' => $model, 'size' => $payload['size'], 'quality' => sanitize_key( (string) ( $payload['quality'] ?? '' ) ), 'output_format' => $payload['output_format'], 'candidate_count' => $payload['candidate_count'], 'provider_options' => is_array( $payload['provider_options'] ?? null ) ? $payload['provider_options'] : array() );
	}

	private static function normalize_image_estimate_format( $format ) {
		$format = strtolower( sanitize_key( (string) $format ) );
		if ( 'png' === $format ) return 'image/png';
		if ( in_array( $format, array( 'jpg', 'jpeg' ), true ) ) return 'image/jpeg';
		if ( 'webp' === $format ) return 'image/webp';
		return sanitize_text_field( (string) $format );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $contract */
	private static function image_options_are_supported( $payload, $contract ) {
		$capabilities = isset( $contract['image_capabilities'] ) && is_array( $contract['image_capabilities'] ) ? $contract['image_capabilities'] : array();
		$provider_options = $payload['provider_options'] ?? array();
		$size         = (string) ( $payload['size'] ?? $payload['provider_size'] ?? '' );
		$provider_schema = is_array( $capabilities['provider_options'] ?? null ) ? $capabilities['provider_options'] : array();
		$declared_provider_values = array();
		foreach ( array( 'size', 'resolution', 'image_size' ) as $provider_size_key ) {
			if ( ! array_key_exists( $provider_size_key, $payload ) || '' === (string) $payload[ $provider_size_key ] || 'auto' === strtolower( (string) $payload[ $provider_size_key ] ) ) continue;
			$value = (string) $payload[ $provider_size_key ];
			if ( ! isset( $provider_schema[ $provider_size_key ] ) ) {
				$native_schema_key = array_key_exists( 'resolution', $provider_schema ) ? 'resolution' : ( array_key_exists( 'image_size', $provider_schema ) ? 'image_size' : '' );
				if ( 'size' !== $provider_size_key || '' === $native_schema_key || '1024x1024' !== $value ) return false;
				continue;
			}
			$allowed_values = array_map( 'strval', (array) ( $provider_schema[ $provider_size_key ]['values'] ?? array() ) );
			if ( $allowed_values && ! in_array( $value, $allowed_values, true ) ) return false;
			$declared_provider_values[ $provider_size_key ] = $value;
		}
		if ( is_array( $provider_options ) ) {
			foreach ( array( 'size', 'resolution', 'image_size' ) as $provider_size_key ) {
				if ( isset( $declared_provider_values[ $provider_size_key ], $provider_options[ $provider_size_key ] ) && (string) $declared_provider_values[ $provider_size_key ] !== (string) $provider_options[ $provider_size_key ] ) return false;
				if ( '' !== (string) ( $provider_options[ $provider_size_key ] ?? '' ) && ( '' === $size || '1024x1024' === $size ) ) {
					$size = (string) $provider_options[ $provider_size_key ];
					break;
				}
			}
		}
		foreach ( array( 'resolution', 'image_size' ) as $provider_size_key ) {
			if ( '' !== (string) ( $payload[ $provider_size_key ] ?? '' ) && ( '' === $size || '1024x1024' === $size ) ) {
				$size = (string) $payload[ $provider_size_key ];
				break;
			}
		}
		if ( '' === $size ) {
			$supported_sizes = array_values( (array) ( $capabilities['supported_sizes'] ?? array() ) );
			$size = (string) ( $supported_sizes[0] ?? '1024x1024' );
		}
		$quality      = (string) ( $payload['quality'] ?? '' );
		$quality_explicit = array_key_exists( 'quality_explicit', $payload ) ? (bool) $payload['quality_explicit'] : array_key_exists( 'quality', $payload );
		$format       = (string) ( $payload['output_format'] ?? 'image/png' );
		$count        = isset( $payload['candidate_count'] ) ? (int) $payload['candidate_count'] : 1;
		if ( ! is_array( $provider_options ) || ! self::provider_options_are_supported( $provider_options, $capabilities['provider_options'] ?? array() ) ) return false;
		$quality_supported = empty( $capabilities['supported_qualities'] ) ? ( ! $quality_explicit || '' === $quality || 'auto' === strtolower( $quality ) ) : ( 'auto' === strtolower( $quality ) || in_array( $quality, (array) $capabilities['supported_qualities'], true ) );
		$size_supported = empty( $capabilities['supported_sizes'] ) || in_array( $size, (array) $capabilities['supported_sizes'], true );
		$ratio = (string) ( $payload['aspect_ratio'] ?? '' );
		$ratio_supported = '' === $ratio || in_array( $ratio, (array) ( $capabilities['supported_aspect_ratios'] ?? array() ), true );
		$cloud_ok = empty( $contract['cloud_upload'] ) || true === ( $payload['cloud_upload_confirmed'] ?? false ) || ( is_array( $payload['cloud_consent'] ?? null ) && true === ( $payload['cloud_consent']['confirmed'] ?? false ) );
		return $size_supported
			&& $quality_supported
			&& in_array( $format, (array) ( $capabilities['supported_output_formats'] ?? array() ), true )
			&& $ratio_supported
			&& $count >= 1
			&& $count <= (int) ( $capabilities['candidate_count_max'] ?? 0 )
			&& $cloud_ok;
	}

	/** Validate only the provider option keys and scalar enum values advertised by the contract. */
	private static function provider_options_are_supported( $options, $schema ) {
		if ( ! is_array( $options ) || ! is_array( $schema ) ) return empty( $options );
		foreach ( $options as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) || ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) return false;
			$values = array_map( 'strval', (array) ( $schema[ $key ]['values'] ?? array() ) );
			if ( $values && ! in_array( (string) $value, $values, true ) ) return false;
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
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay video requires a safely formed relay model ID.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( '' === $prompt ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		if ( strlen( $prompt ) > 32768 ) {
			return new \WP_Error( 'invalid_prompt', __( 'Prompt exceeds the AI Model Relay limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}
		if ( ! empty( $payload['input_reference'] ) && ! self::is_image_reference( $payload['input_reference'] ) ) {
			return new \WP_Error( 'invalid_reference_image', __( 'Video reference must be a PNG, JPEG, or WebP data URL or base64 image object.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private static function is_image_reference( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value ) <= 16777216 && self::is_image_data_url( $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		$mime    = strtolower( trim( (string) ( $value['mime_type'] ?? '' ) ) );
		$encoded = preg_replace( '/\s+/', '', (string) ( $value['b64_json'] ?? '' ) );
		return in_array( $mime, array( 'image/png', 'image/jpeg', 'image/jpg', 'image/webp' ), true )
			&& '' !== $encoded
			&& strlen( $encoded ) <= 16777216
			&& false !== base64_decode( $encoded, true );
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
			return new \WP_Error( 'invalid_local_codex_model', __( 'AI Model Relay transcription requires a safely formed relay or Local ASR model ID.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
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
	private static function load_authorized_job( $request, $params, $allowed_statuses = array( 'created' ), $allow_completed_replay = false ) {
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
		if ( Ledger::signature_exists( (string) $job['request_hash'] ) && ! $allow_completed_replay ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( (string) ( $job['status'] ?? '' ), $allowed_statuses, true ) ) {
			return new \WP_Error( 'local_codex_job_not_active', __( 'This AI Model Relay job is no longer active.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
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
		$requires_mime = null !== self::image_model_contract( (string) ( $job['model'] ?? '' ) );
		foreach ( $response['data'] as $image ) {
			$encoded = is_array( $image ) && isset( $image['b64_json'] ) ? preg_replace( '/\s+/', '', (string) $image['b64_json'] ) : '';
			$mime    = is_array( $image ) ? strtolower( trim( (string) ( $image['mime_type'] ?? '' ) ) ) : '';
			if ( '' === $encoded || false === base64_decode( $encoded, true ) || ( $requires_mime && ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) ) {
				return new \WP_Error( 'invalid_local_codex_result', __( 'AI Model Relay image result did not contain valid base64 image data.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
		}
		$response['model'] = (string) $job['model'];
		return $response;
	}

	/** Normalize a binary-transfer manifest without retaining or forwarding PNG bytes. */
	private static function normalize_upscale_result( $result, $job ) {
		$response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : $result;
		$output = isset( $response['output'] ) && is_array( $response['output'] ) ? $response['output'] : array();
		$provenance = isset( $response['provenance'] ) && is_array( $response['provenance'] ) ? $response['provenance'] : array();
		$output_print = isset( $job['payload']['output_print'] ) && is_array( $job['payload']['output_print'] ) ? $job['payload']['output_print'] : array();
		$checksum = strtolower( trim( (string) ( $output['checksum'] ?? '' ) ) );
		if ( 'image/png' !== strtolower( trim( (string) ( $output['mime_type'] ?? '' ) ) ) || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) || 'retain_native_x2' !== (string) ( $job['payload']['output_policy'] ?? '' ) || (int) ( $output['width'] ?? 0 ) !== (int) ( $output_print['width'] ?? 0 ) || (int) ( $output['height'] ?? 0 ) !== (int) ( $output_print['height'] ?? 0 ) || (int) ( $output['byte_size'] ?? 0 ) < 1 || (int) ( $output['byte_size'] ?? 0 ) > 67108864 ) return new \WP_Error( 'invalid_local_upscale_result', __( 'AI Model Relay local upscale output did not match the signed native ×2 PNG manifest.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		$required = array( 'model_id', 'model_version', 'weight_checksum', 'cuda_device', 'precision', 'downsampler', 'processing_started_at', 'processing_finished_at' );
		foreach ( $required as $field ) if ( '' === trim( (string) ( $provenance[ $field ] ?? '' ) ) ) return new \WP_Error( 'invalid_local_upscale_provenance', __( 'AI Model Relay local upscale provenance is incomplete.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		if ( (string) $job['model'] !== (string) $provenance['model_id'] || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $provenance['weight_checksum'] ) ) || 'none' !== strtolower( (string) $provenance['downsampler'] ) || false === strtotime( (string) $provenance['processing_started_at'] ) || false === strtotime( (string) $provenance['processing_finished_at'] ) ) return new \WP_Error( 'invalid_local_upscale_provenance', __( 'AI Model Relay local upscale provenance did not match the signed native ×2 request.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		return array( 'output' => array( 'mime_type' => 'image/png', 'checksum' => $checksum, 'width' => (int) $output['width'], 'height' => (int) $output['height'], 'byte_size' => (int) $output['byte_size'] ), 'provenance' => $provenance, 'model' => (string) $job['model'], 'local_unmetered' => true );
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
	 * Transient key for a redacted completed-job receipt.
	 *
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	private static function receipt_key( $job_id ) {
		return 'alorbach_local_codex_receipt_' . sanitize_key( (string) $job_id );
	}

	/** Durable option key for a redacted completed-job receipt. */
	private static function receipt_option_key( $job_id ) {
		return 'alorbach_local_codex_receipt_' . sanitize_key( (string) $job_id );
	}

	/** Persist a receipt before acknowledging and deleting its signed job. */
	private static function persist_completion_receipt( $job, $response ) {
		$receipt = self::completion_receipt( $job, $response );
		$key = self::receipt_option_key( (string) $job['job_id'] );
		$updated = update_option( $key, $receipt, false );
		$stored = get_option( $key, null );
		if ( ! is_array( $stored ) || wp_json_encode( $stored ) !== wp_json_encode( $receipt ) ) {
			return new \WP_Error( 'local_codex_receipt_persist_failed', __( 'AI Model Relay could not durably save the completion receipt. Retry the completion.', 'alorbach-ai-gateway' ), array( 'status' => 503 ) );
		}
		/* The option is authoritative; the transient is only a fast expiring
		 * cache and may fail without losing the completion receipt. */
		set_transient( self::receipt_key( (string) $job['job_id'] ), $receipt, self::JOB_TTL );
		return true;
	}

	/**
	 * Build the minimal receipt a downstream product may trust for result
	 * integrity. It never retains raw result bytes or a relay authentication
	 * token; image records hold only the independently checkable byte manifest.
	 *
	 * @param array $job Signed relay job.
	 * @param array $response Normalized completion response.
	 * @return array<string,mixed>
	 */
	private static function completion_receipt( $job, $response ) {
		$receipt = array(
			'job_id'       => (string) $job['job_id'],
			'user_id'      => (int) $job['user_id'],
			'request_hash' => (string) $job['request_hash'],
			'type'         => (string) $job['type'],
			'model'        => (string) $job['model'],
			'status'       => 'completed',
			'completed_at' => gmdate( 'c' ),
			'cost_uc'      => (int) $job['fee_uc'],
			'result_digest' => 'chat' === (string) $job['type'] ? (string) ( $response['result_digest'] ?? '' ) : '',
			'result_manifest' => array(),
		);

		if ( 'upscale' === (string) $job['type'] ) {
			$output = isset( $response['output'] ) && is_array( $response['output'] ) ? $response['output'] : array();
			$receipt['result_manifest'][] = array( 'mime_type' => (string) ( $output['mime_type'] ?? '' ), 'byte_size' => (int) ( $output['byte_size'] ?? 0 ), 'sha256' => (string) ( $output['checksum'] ?? '' ), 'width' => (int) ( $output['width'] ?? 0 ), 'height' => (int) ( $output['height'] ?? 0 ) );
			return $receipt;
		}

		if ( 'image' !== (string) $job['type'] ) {
			return $receipt;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		foreach ( $data as $index => $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$encoded = preg_replace( '/\s+/', '', (string) ( $image['b64_json'] ?? '' ) );
			$bytes   = false !== $encoded ? base64_decode( $encoded, true ) : false;
			if ( false === $bytes ) {
				continue;
			}
			$receipt['result_manifest'][] = array(
				'index'      => (int) $index,
				'mime_type'  => strtolower( trim( (string) ( $image['mime_type'] ?? '' ) ) ),
				'byte_size'  => strlen( $bytes ),
				'sha256'     => hash( 'sha256', $bytes ),
			);
		}

		return $receipt;
	}

	/** Hash only the normalized chat choices, excluding mutable billing metadata. */
	private static function chat_result_digest( $response ) {
		$choices = isset( $response['choices'] ) && is_array( $response['choices'] ) ? $response['choices'] : array();
		return hash( 'sha256', (string) wp_json_encode( $choices ) );
	}

	/** Hash the normalized completion before mutable billing metadata is added. */
	private static function completion_response_digest( $response ) {
		return hash( 'sha256', (string) wp_json_encode( $response ) );
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
