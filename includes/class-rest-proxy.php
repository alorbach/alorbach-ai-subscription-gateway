<?php
/**
 * REST API proxy for chat completions.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class REST_Proxy
 */
class REST_Proxy {

	/**
	 * Transient-based per-user rate limiter. Limits are read from plugin settings.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $endpoint Short endpoint key: chat, images, transcribe, video.
	 * @return \WP_Error|null WP_Error (HTTP 429) when limit exceeded, null when allowed.
	 */
	private static function check_rate_limit( $user_id, $endpoint ) {
		$bypass = apply_filters( 'alorbach_bypass_rate_limit', false, (string) $endpoint, (int) $user_id );
		if ( $bypass ) {
			return null;
		}

		$window = max( 10, (int) get_option( 'alorbach_rate_limit_window', 60 ) );
		$option_map = array(
			'chat'       => array( 'alorbach_rate_limit_chat', 100 ),
			'images'     => array( 'alorbach_rate_limit_images', 30 ),
			'transcribe' => array( 'alorbach_rate_limit_transcribe', 30 ),
			'video'      => array( 'alorbach_rate_limit_video', 10 ),
		);
		list( $option_key, $default ) = isset( $option_map[ $endpoint ] ) ? $option_map[ $endpoint ] : array( '', 60 );
		$limit     = $option_key ? max( 1, (int) get_option( $option_key, $default ) ) : $default;
		$cache_key = 'alorbach_rl_' . $user_id . '_' . $endpoint;

		$rate_error = new \WP_Error(
			'rate_limit_exceeded',
			/* translators: %d: rate limit window in seconds */
			sprintf( __( 'Too many requests. Please wait %d seconds before trying again.', 'alorbach-ai-gateway' ), $window ),
			array( 'status' => 429 )
		);

		if ( wp_using_ext_object_cache() ) {
			// Atomic path: wp_cache_add is guaranteed-atomic on Redis/Memcached.
			if ( ! wp_cache_add( $cache_key, 1, '', $window ) ) {
				$count = wp_cache_incr( $cache_key, 1 );
				if ( false === $count || $count <= 0 ) {
					// Key expired between add and incr — treat as fresh window.
					wp_cache_set( $cache_key, 1, '', $window );
					$count = 1;
				}
				if ( $count > $limit ) {
					return $rate_error;
				}
			}
		} else {
			// Non-persistent cache: each process has its own memory store, so
			// transients are the cross-request mechanism available here. A narrow
			// race window exists on concurrent requests; acceptable for this tier.
			$count = (int) get_transient( $cache_key );
			if ( $count >= $limit ) {
				return $rate_error;
			}
			set_transient( $cache_key, $count + 1, $window );
		}

		return null;
	}

	/**
	 * Check per-user monthly UC quota. Returns WP_Error if the user has exceeded
	 * their monthly allowance (0 = unlimited).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return \WP_Error|null WP_Error (HTTP 429) when quota exceeded, null when allowed.
	 */
	private static function check_monthly_quota( $user_id ) {
		$quota = (int) get_option( 'alorbach_monthly_quota_uc', 0 );
		if ( $quota <= 0 ) {
			return null; // 0 = unlimited.
		}
		$used = Ledger::get_usage_this_month( $user_id );
		if ( $used >= $quota ) {
			return new \WP_Error(
				'monthly_quota_exceeded',
				__( 'Monthly usage quota exceeded. Please upgrade your plan or wait until next month.', 'alorbach-ai-gateway' ),
				array( 'status' => 429 )
			);
		}
		return null;
	}

	/**
	 * Build an insufficient credits error and fire a downstream hook.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $context Request context such as chat or image.
	 * @param array  $details Context details for observers.
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private static function insufficient_credits_error( $user_id, $context, $details = array(), $message = '' ) {
		$message = $message ?: __( 'Insufficient credits.', 'alorbach-ai-gateway' );
		do_action( 'alorbach_generation_rejected_insufficient_balance', (int) $user_id, (string) $context, (array) $details );

		return new \WP_Error(
			'insufficient_credits',
			$message,
			array( 'status' => 402 )
		);
	}

	/**
	 * Build a plan restriction error for blocked capabilities or models.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $capability Capability key.
	 * @param string $model Requested model.
	 * @return \WP_Error
	 */
	private static function plan_restriction_error( $user_id, $capability, $model = '' ) {
		$plan = Integration_Service::get_user_active_plan( $user_id );

		return new \WP_Error(
			'plan_restriction',
			sprintf(
				/* translators: 1: capability, 2: plan name */
				__( 'The requested %1$s feature is not included in your %2$s plan.', 'alorbach-ai-gateway' ),
				(string) $capability,
				(string) ( $plan['public_name'] ?? $plan['slug'] ?? __( 'current', 'alorbach-ai-gateway' ) )
			),
			array(
				'status'     => 403,
				'capability' => (string) $capability,
				'model'      => (string) $model,
				'plan'       => Integration_Service::get_plan_summary( $plan ),
			)
		);
	}

	/**
	 * Enforce plan capability and model access for a user.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $capability Capability key.
	 * @param string $model Requested model.
	 * @return \WP_Error|null
	 */
	private static function enforce_user_plan_access( $user_id, $capability, $model = '' ) {
		if ( Integration_Service::user_can_access_capability( $user_id, $capability, $model ) ) {
			return null;
		}

		return self::plan_restriction_error( $user_id, $capability, $model );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route( 'alorbach/v1', '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'chat_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'messages'   => array(
					'required'          => true,
					'type'             => 'array',
					'sanitize_callback' => function ( $val ) {
						return is_array( $val ) ? $val : array();
					},
				),
				'model'      => array(
					'default'           => 'gpt-4.1-mini',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'max_tokens'       => array(
					'default'           => 1024,
					'sanitize_callback' => 'absint',
				),
				'multi_step'       => array(
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'max_steps'        => array(
					'default'           => 5,
					'sanitize_callback' => 'absint',
				),
				'continue_message' => array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/me/balance', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_balance' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		) );

		register_rest_route( 'alorbach/v1', '/me/usage', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_usage' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'period' => array(
					'default' => 'month',
					'enum'    => array( 'month', 'week' ),
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/me/models', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_models' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		) );

		register_rest_route( 'alorbach/v1', '/integration/config', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'integration_config' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'alorbach/v1', '/integration/plans', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'integration_plans' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'include_inactive' => array(
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/integration/account', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'integration_account' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		) );

		register_rest_route( 'alorbach/v1', '/integration/account/history', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'integration_account_history' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'page'     => array(
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'default'           => 10,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/me/estimate', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_estimate' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'type'             => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'image', 'video', 'audio' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'size'             => array( 'default' => '1024x1024', 'sanitize_callback' => 'sanitize_text_field' ),
				'quality'           => array( 'default' => 'medium', 'sanitize_callback' => 'sanitize_text_field' ),
				'n'                 => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
				'model'             => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'duration_seconds'  => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( 'alorbach/v1', '/stripe-webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'stripe_webhook' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'alorbach/v1', '/images', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'images_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'prompt'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'size'    => array( 'default' => '1024x1024', 'sanitize_callback' => 'sanitize_text_field' ),
				'n'       => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
				'quality' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'model'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( 'alorbach/v1', '/images/jobs', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'images_job_create_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'prompt'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'size'    => array( 'default' => '1024x1024', 'sanitize_callback' => 'sanitize_text_field' ),
				'n'       => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
				'quality' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'model'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( 'alorbach/v1', '/images/jobs/(?P<job_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'images_job_status_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		) );

		register_rest_route( 'alorbach/v1', '/images/jobs/(?P<job_id>[a-zA-Z0-9\-]+)/stream', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'images_job_stream_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		) );

		register_rest_route( 'alorbach/v1', '/internal/images/jobs/process', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'images_job_process_handler' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'job_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'token'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( 'alorbach/v1', '/transcribe', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transcribe_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'audio_base64'     => array( 'required' => true ),
				'audio_format'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'duration_seconds' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'model'            => array( 'default' => 'whisper-1', 'sanitize_callback' => 'sanitize_text_field' ),
				'prompt'           => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( 'alorbach/v1', '/video', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'video_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'prompt'            => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'model'             => array( 'default' => 'sora-2', 'sanitize_callback' => 'sanitize_text_field' ),
				'size'              => array( 'default' => '1280x720', 'sanitize_callback' => 'sanitize_text_field' ),
				'duration_seconds'  => array( 'default' => 8, 'sanitize_callback' => 'absint' ),
			),
		) );

		$admin_permission = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route( 'alorbach/v1', '/admin/verify-api-key', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_api_key' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'provider'  => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'openai', 'azure', 'google', 'github_models' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'entry_id'  => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/verify-text', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_text' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'provider'  => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'model'     => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'entry_id'  => array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/verify-image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_image' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'size'   => array(
					'default'           => '1024x1024',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'model'  => array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/verify-audio', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_audio' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'model' => array(
					'default'           => 'whisper-1',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/verify-video', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_video' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'model' => array(
					'default'           => 'sora-2',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/fetch-importable-models', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_fetch_importable_models' ),
			'permission_callback' => $admin_permission,
		) );

		register_rest_route( 'alorbach/v1', '/admin/import-models', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_import_models' ),
			'permission_callback' => $admin_permission,
		) );

		register_rest_route( 'alorbach/v1', '/admin/reset-models', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_reset_models' ),
			'permission_callback' => $admin_permission,
		) );

		register_rest_route( 'alorbach/v1', '/admin/refresh-azure-prices', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_refresh_azure_prices' ),
			'permission_callback' => $admin_permission,
		) );

		register_rest_route( 'alorbach/v1', '/admin/save-google-whitelist', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_save_google_whitelist' ),
			'permission_callback' => $admin_permission,
		) );

		register_rest_route( 'alorbach/v1', '/admin/image-jobs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_image_jobs_list' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'limit' => array(
					'default'           => 50,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/image-jobs/(?P<job_id>[a-zA-Z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_image_job_detail' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'include_images' => array(
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/image-jobs/actions', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_image_jobs_action' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'action' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );
	}

	/**
	 * Chat completion handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function chat_handler( $request ) {
		$user_id  = get_current_user_id();

		$rate_error = self::check_rate_limit( $user_id, 'chat' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$quota_error = self::check_monthly_quota( $user_id );
		if ( $quota_error ) {
			return $quota_error;
		}

		$messages         = $request->get_param( 'messages' );
		$config           = Integration_Service::get_integration_config( $user_id );
		$model            = $request->get_param( 'model' ) ?: ( $config['defaults']['chat_model'] ?? 'gpt-4.1-mini' );
		$max_tokens       = $request->get_param( 'max_tokens' );
		$multi_step       = (bool) $request->get_param( 'multi_step' );
		$max_steps        = min( 20, max( 1, (int) $request->get_param( 'max_steps' ) ) );
		$continue_message = (string) $request->get_param( 'continue_message' );

		$plan_error = self::enforce_user_plan_access( $user_id, 'chat', $model );
		if ( $plan_error ) {
			return $plan_error;
		}

		// Validate messages structure.
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new \WP_Error( 'invalid_messages', __( 'messages must be a non-empty array.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$valid_roles = array( 'system', 'user', 'assistant' );
		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) || ! isset( $msg['role'] ) || ! array_key_exists( 'content', $msg ) ) {
				return new \WP_Error( 'invalid_messages', __( 'Each message must have a role and content.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $msg['role'], $valid_roles, true ) ) {
				return new \WP_Error( 'invalid_messages', __( 'Invalid message role.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
			if ( ! is_string( $msg['content'] ) && ! is_array( $msg['content'] ) ) {
				return new \WP_Error( 'invalid_messages', __( 'Message content must be a string or array.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
			}
		}

		// Clamp max_tokens to the model's maximum supported output length.
		$model_max = Cost_Matrix::get_max_tokens( $model );
		if ( (int) $max_tokens > $model_max ) {
			$max_tokens = $model_max;
		}

		$request_signature = hash( 'sha256', wp_json_encode( array( $user_id, $messages, $model ) ) );
		$multi_step_lock   = 'alorbach_chat_inflight_' . $request_signature;
		if ( Ledger::signature_exists( $request_signature ) || ( $multi_step && get_transient( $multi_step_lock ) ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$provider = API_Client::get_provider_for_model( $model );
		$creds    = API_Keys_Helper::get_credentials_for_provider( $provider );
		$free_pass_through = ! empty( $creds['free_pass_through'] );

		if ( ! $free_pass_through ) {
			$input_tokens   = Tokenizer::count_messages_tokens( $messages, $model );
			$output_estimate = $max_tokens;
			$input_cost     = (int) ( ( $input_tokens * Cost_Matrix::get_input_cost_per_token( $model ) ) / 1000000 );
			$output_cost    = (int) ( ( $output_estimate * Cost_Matrix::get_output_cost_per_token( $model ) ) / 1000000 );
			$auth_hold      = Cost_Matrix::apply_user_cost( $input_cost + $output_cost, $model );

			$balance = Ledger::get_balance( $user_id );
			if ( $balance < $auth_hold ) {
				return self::insufficient_credits_error(
					$user_id,
					'chat',
					array(
						'model'        => $model,
						'required_uc'  => $auth_hold,
						'available_uc' => $balance,
					)
				);
			}
		}

		$body = array(
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => $max_tokens,
		);

		/**
		 * Filter the request body sent to the AI provider.
		 *
		 * Allows third-party plugins to inject additional parameters (e.g. temperature,
		 * tools, response_format) before the request is dispatched.
		 *
		 * @param array  $body    Request body.
		 * @param int    $user_id WordPress user ID.
		 * @param string $model   Model slug.
		 */
		$body = apply_filters( 'alorbach_chat_request_body', $body, $user_id, $model );

		if ( $multi_step ) {
			return self::execute_multi_step_chat(
				$user_id,
				$provider,
				$body,
				$messages,
				$model,
				$max_steps,
				$continue_message,
				$free_pass_through,
				$request_signature,
				$multi_step_lock
			);
		}

		$response = API_Client::chat( $provider, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $free_pass_through ) {
			$response['cost_uc']      = 0;
			$response['cost_credits'] = User_Display::uc_to_credits( 0 );
			$response['cost_usd']     = User_Display::uc_to_usd( 0 );
			return rest_ensure_response( $response );
		}

		$prompt_tokens    = isset( $response['usage']['prompt_tokens'] ) ? (int) $response['usage']['prompt_tokens'] : Tokenizer::count_messages_tokens( $messages, $model );
		$completion_tokens = isset( $response['usage']['completion_tokens'] ) ? (int) $response['usage']['completion_tokens'] : 0;
		$cached_tokens   = isset( $response['usage']['prompt_tokens_details']['cached_tokens'] ) ? (int) $response['usage']['prompt_tokens_details']['cached_tokens'] : 0;

		$api_cost = Cost_Matrix::calculate_chat_cost( $model, $prompt_tokens, $completion_tokens, $cached_tokens );
		$uc_cost  = Cost_Matrix::apply_user_cost( $api_cost, $model );

		Ledger::insert_transaction(
			$user_id,
			'chat_deduction',
			$model,
			- $uc_cost,
			$prompt_tokens,
			$cached_tokens,
			$completion_tokens,
			$request_signature,
			$api_cost
		);
		do_action( 'alorbach_after_deduction', $user_id, 'chat', $model, $uc_cost, $api_cost );

		$response['cost_uc']      = $uc_cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $uc_cost );
		$response['cost_usd']     = User_Display::uc_to_usd( $uc_cost );
		return rest_ensure_response( $response );
	}

	/**
	 * Get current user balance.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function me_balance( $request ) {
		$user_id = get_current_user_id();
		$balance = Ledger::get_balance( $user_id );
		return rest_ensure_response( array(
			'balance_uc'      => $balance,
			'balance_credits' => User_Display::uc_to_credits( $balance ),
			'balance_usd'     => User_Display::uc_to_usd( $balance ),
		) );
	}

	/**
	 * Get current user usage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function me_usage( $request ) {
		$user_id = get_current_user_id();
		$period  = $request->get_param( 'period' );
		$usage   = $period === 'week' ? Ledger::get_usage( $user_id, gmdate( 'Y-m-d', strtotime( '-7 days' ) ), gmdate( 'Y-m-d' ) ) : Ledger::get_usage_this_month( $user_id );
		return rest_ensure_response( array(
			'usage_uc' => $usage,
			'usage_credits' => User_Display::uc_to_credits( $usage ),
			'period' => $period,
		) );
	}

	/**
	 * Get configured models for demo pages (defaults, allow_select, options).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function me_models( $request ) {
		$user_id = get_current_user_id();
		$config         = Integration_Service::get_integration_config( $user_id );
		$admin          = \Alorbach\AIGateway\Admin\Admin_Demo_Defaults::class;
		$settings_admin = \Alorbach\AIGateway\Admin\Admin_Settings::class;
		$max_tokens_options = $admin::get_max_tokens_options();
		$default_max_tokens = $settings_admin::get_default_max_tokens();
		$image_models = isset( $config['capabilities']['image_models'] ) && is_array( $config['capabilities']['image_models'] ) ? $config['capabilities']['image_models'] : array();
		$streamable_image_models = array_values( array_filter( $image_models, function ( $model ) {
			return API_Client::supports_partial_image_streaming( $model );
		} ) );
		$default_image_model = isset( $config['defaults']['image_model'] ) ? $config['defaults']['image_model'] : '';
		$default_supports_stream = API_Client::supports_partial_image_streaming( $default_image_model );

		return rest_ensure_response( array(
			'text'  => array(
				'enabled'      => ! empty( $config['plan_capabilities']['chat'] ),
				'default'      => $config['defaults']['chat_model'],
				'allow_select' => (bool) get_option( 'alorbach_demo_allow_chat_model_select', false ),
				'options'      => $config['capabilities']['chat_models'],
				'max_tokens'   => array(
					'default' => $default_max_tokens,
					'options' => $max_tokens_options,
				),
			),
 			'image' => array(
				'enabled' => ! empty( $config['plan_capabilities']['image'] ),
 				'size'    => array(
 					'default'      => $config['defaults']['image_size'],
 					'allow_select' => (bool) get_option( 'alorbach_demo_allow_image_size_select', false ),
 					'options'      => $config['capabilities']['image_sizes'],
				),
				'model'   => array(
					'default'      => $config['defaults']['image_model'],
					'allow_select' => (bool) get_option( 'alorbach_demo_allow_image_model_select', false ),
					'options'      => $config['capabilities']['image_models'],
				),
				'quality' => array(
 					'default'      => $config['defaults']['image_quality'],
 					'allow_select' => (bool) get_option( 'alorbach_demo_allow_image_quality_select', false ),
 					'options'      => $config['capabilities']['image_qualities'],
 				),
				'supports_progress'       => true,
				'supports_preview_images' => ! empty( $streamable_image_models ),
				'progress_mode'           => $default_supports_stream ? 'provider' : 'estimated',
				'job_endpoint'            => rest_url( 'alorbach/v1/images/jobs' ),
				'preview_models'          => $streamable_image_models,
 			),
			'audio' => array(
				'enabled'      => ! empty( $config['plan_capabilities']['audio'] ),
				'default'      => $config['defaults']['audio_model'],
				'allow_select' => (bool) get_option( 'alorbach_demo_allow_audio_model_select', false ),
				'options'      => $config['capabilities']['audio_models'],
			),
			'video' => array(
				'enabled'      => ! empty( $config['plan_capabilities']['video'] ),
				'default'      => $config['defaults']['video_model'],
				'allow_select' => (bool) get_option( 'alorbach_demo_allow_video_model_select', false ),
				'options'      => $config['capabilities']['video_models'],
				'size'         => array(
					'default'      => '1280x720',
					'allow_select'  => true,
					'options'       => $config['capabilities']['video_sizes'],
				),
				'duration'      => array(
					'default'      => '8',
					'allow_select'  => true,
					'options'       => $config['capabilities']['video_durations'],
				),
			),
		) );
	}

	/**
	 * Get downstream integration config.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function integration_config( $request ) {
		return rest_ensure_response( Integration_Service::get_integration_config( get_current_user_id() ) );
	}

	/**
	 * Get downstream public plans.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function integration_plans( $request ) {
		$include_inactive = (bool) $request->get_param( 'include_inactive' );
		if ( $include_inactive && ! current_user_can( 'manage_options' ) ) {
			$include_inactive = false;
		}

		return rest_ensure_response(
			array(
				'plans' => Integration_Service::get_public_plans(
					array(
						'include_inactive' => $include_inactive,
					)
				),
			)
		);
	}

	/**
	 * Get downstream account summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function integration_account( $request ) {
		return rest_ensure_response( Integration_Service::get_account_summary( get_current_user_id() ) );
	}

	/**
	 * Get downstream account history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function integration_account_history( $request ) {
		return rest_ensure_response(
			Integration_Service::get_account_history(
				get_current_user_id(),
				array(
					'page'     => max( 1, (int) $request->get_param( 'page' ) ),
					'per_page' => max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) ),
				)
			)
		);
	}

	/**
	 * Get estimated cost for image, video, or audio based on selected settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function me_estimate( $request ) {
		$type = $request->get_param( 'type' );
		$cost_uc = 0;

		if ( $type === 'image' ) {
			$size    = $request->get_param( 'size' ) ?: '1024x1024';
			$quality = $request->get_param( 'quality' ) ?: 'medium';
			$n       = max( 1, min( 10, (int) $request->get_param( 'n' ) ) );
			$model   = $request->get_param( 'model' ) ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
			$api_cost = Cost_Matrix::get_image_cost( $size, $model, $quality ) * $n;
			$cost_uc  = Cost_Matrix::apply_user_cost( $api_cost, $model );
		} elseif ( $type === 'video' ) {
			$model    = $request->get_param( 'model' ) ?: \Alorbach\AIGateway\Admin\Admin_Settings::get_default_video_model( array( 'sora-2' ) );
			$duration = max( 4, min( 12, (int) $request->get_param( 'duration_seconds' ) ) );
			if ( ! in_array( $duration, array( 4, 8, 12 ), true ) ) {
				$duration = 8;
			}
			$api_cost = Cost_Matrix::get_video_cost( $model, $duration );
			$cost_uc  = Cost_Matrix::apply_user_cost( $api_cost, $model );
		} elseif ( $type === 'audio' ) {
			$duration = max( 1, (int) $request->get_param( 'duration_seconds' ) );
			$model    = $request->get_param( 'model' ) ?: \Alorbach\AIGateway\Admin\Admin_Settings::get_default_audio_model( array( 'whisper-1' ) );
			$api_cost  = Cost_Matrix::get_audio_cost( $duration, $model );
			$cost_uc   = Cost_Matrix::apply_user_cost( $api_cost, $model );
		}

		return rest_ensure_response( array(
			'cost_uc'      => $cost_uc,
			'cost_credits' => User_Display::uc_to_credits( $cost_uc ),
			'cost_usd'     => User_Display::uc_to_usd( $cost_uc ),
		) );
	}

	/**
	 * Stripe webhook handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function stripe_webhook( $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( 'Stripe-Signature' );
		$secret  = get_option( 'alorbach_stripe_webhook_secret', '' );

		if ( empty( $secret ) || empty( $sig ) ) {
			return new \WP_Error( 'webhook_config', __( 'Stripe webhook not configured.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		// Parse Stripe-Signature: t=timestamp,v1=signature
		$parts = array();
		foreach ( explode( ',', $sig ) as $part ) {
			$kv = explode( '=', $part, 2 );
			if ( count( $kv ) === 2 ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}
		$timestamp = isset( $parts['t'] ) ? $parts['t'] : '';
		$signature = isset( $parts['v1'] ) ? $parts['v1'] : '';
		if ( ! $timestamp || ! $signature ) {
			return new \WP_Error( 'invalid_signature', __( 'Invalid Stripe signature format.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		// Replay protection: reject if older than 2 minutes.
		if ( abs( time() - (int) $timestamp ) > 120 ) {
			return new \WP_Error( 'replay_attack', __( 'Stripe webhook timestamp too old.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}
		$signed_content = $timestamp . '.' . $payload;
		$expected_sig    = hash_hmac( 'sha256', $signed_content, $secret );
		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return new \WP_Error( 'invalid_signature', __( 'Stripe signature verification failed.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! $event || ! isset( $event['type'] ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid payload.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		// Idempotency: claim the event up front so concurrent deliveries cannot
		// both credit the same invoice.
		$event_id = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';
		$sig_key  = 'stripe:' . $event_id;
		if ( $event_id ) {
			$claimed = Ledger::insert_transaction( 0, 'stripe_idempotency', null, 0, null, null, null, $sig_key );
			if ( ! $claimed ) {
				return rest_ensure_response( array( 'received' => true ) );
			}
		}

		do_action( 'alorbach_stripe_webhook', $event['type'], $event );

		if ( $event['type'] === 'invoice.paid' && isset( $event['data']['object'] ) ) {
			$obj = $event['data']['object'];
			$user_id = isset( $obj['metadata']['user_id'] ) ? (int) $obj['metadata']['user_id'] : 0;
			$plan_slug = isset( $obj['metadata']['plan_slug'] ) ? $obj['metadata']['plan_slug'] : '';
			$plan_credits = isset( $obj['metadata']['plan_credits'] ) ? (int) $obj['metadata']['plan_credits'] : 0;

			if ( $user_id && ( $plan_credits > 0 || $plan_slug ) ) {
				$credits = $plan_credits;
				if ( empty( $credits ) && $plan_slug ) {
					$plans = get_option( 'alorbach_plans', array() );
					$credits = isset( $plans[ $plan_slug ]['credits_per_month'] ) ? (int) $plans[ $plan_slug ]['credits_per_month'] : 0;
				}
				if ( $credits > 0 ) {
					$inserted = Ledger::insert_transaction( $user_id, 'subscription_credit', null, $credits );
					if ( ! $inserted ) {
						if ( $event_id ) {
							Ledger::delete_by_signature( $sig_key );
						}
						return new \WP_Error( 'db_error', __( 'Failed to record transaction. Please retry.', 'alorbach-ai-gateway' ), array( 'status' => 500 ) );
					}
					do_action( 'alorbach_credits_added', $user_id, $credits, 'stripe' );
				}
			}
		} elseif ( $event['type'] === 'invoice.payment_failed' ) {
			do_action( 'alorbach_stripe_payment_failed', $event );
		} elseif ( $event['type'] === 'customer.subscription.deleted' ) {
			do_action( 'alorbach_stripe_subscription_deleted', $event );
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * DALL-E images handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function images_handler( $request ) {
		$user_id = get_current_user_id();

		$rate_error = self::check_rate_limit( $user_id, 'images' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$quota_error = self::check_monthly_quota( $user_id );
		if ( $quota_error ) {
			return $quota_error;
		}

		$prompt  = $request->get_param( 'prompt' );
		$size    = $request->get_param( 'size' );
		$n       = (int) $request->get_param( 'n' );
		$n       = min( 10, max( 1, $n ) );
		$quality = $request->get_param( 'quality' );
		$reference_images = $request->get_param( 'reference_images' );
		$reference_images = is_array( $reference_images ) ? $reference_images : array();
		$model   = $request->get_param( 'model' ) ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = ( $quality && in_array( $quality, array( 'low', 'medium', 'high' ), true ) )
			? $quality
			: get_option( 'alorbach_image_default_quality', 'medium' );

		$plan_error = self::enforce_user_plan_access( $user_id, 'image', $model );
		if ( $plan_error ) {
			return $plan_error;
		}

		// Idempotency: reject duplicate image requests within a 5-minute window.
		$time_bucket       = (int) ( time() / 300 );
		$request_signature = hash( 'sha256', wp_json_encode( array( $user_id, 'image', $prompt, $size, $model, $quality, $n, md5( wp_json_encode( $reference_images ) ), $time_bucket ) ) );
		if ( Ledger::signature_exists( $request_signature ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$api_cost = Cost_Matrix::get_image_cost( $size, $model, $quality ) * $n;
		$cost     = Cost_Matrix::apply_user_cost( $api_cost, $model );
		$balance  = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			return self::insufficient_credits_error(
				$user_id,
				'image',
				array(
					'model'        => $model,
					'size'         => $size,
					'quality'      => $quality,
					'required_uc'  => $cost,
					'available_uc' => $balance,
				)
			);
		}

		$response = API_Client::images( $prompt, $size, $n, $model, $quality, null, $reference_images );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'image_deduction', $model, -$cost, null, null, null, $request_signature, $api_cost );
		do_action( 'alorbach_after_deduction', $user_id, 'image', $model, $cost, $api_cost );
		$response['cost_uc']      = $cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $cost );
		$response['cost_usd']     = User_Display::uc_to_usd( $cost );
		return rest_ensure_response( $response );
	}

	/**
	 * Create an async image job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function images_job_create_handler( $request ) {
		$user_id = get_current_user_id();
		$json    = $request->get_json_params();
		$raw_prompt = '';

		if ( is_array( $json ) && isset( $json['prompt'] ) ) {
			$raw_prompt = is_scalar( $json['prompt'] ) ? (string) $json['prompt'] : '';
		} else {
			$raw_param = $request->get_body_params();
			if ( is_array( $raw_param ) && isset( $raw_param['prompt'] ) && is_scalar( $raw_param['prompt'] ) ) {
				$raw_prompt = (string) $raw_param['prompt'];
			}
		}

		$rate_error = self::check_rate_limit( $user_id, 'images' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$quota_error = self::check_monthly_quota( $user_id );
		if ( $quota_error ) {
			return $quota_error;
		}

		$model = $request->get_param( 'model' ) ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$plan_error = self::enforce_user_plan_access( $user_id, 'image', $model );
		if ( $plan_error ) {
			return $plan_error;
		}

		$result = Image_Jobs::create_job(
			$user_id,
			array(
				'prompt'          => $request->get_param( 'prompt' ),
				'original_prompt' => $raw_prompt,
				'size'            => $request->get_param( 'size' ),
				'n'               => $request->get_param( 'n' ),
				'quality'         => $request->get_param( 'quality' ),
				'model'           => $model,
				'reference_images' => $request->get_param( 'reference_images' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get image job status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function images_job_status_handler( $request ) {
		$job_id = (string) $request->get_param( 'job_id' );
		$job    = Image_Jobs::get_job_for_user( $job_id, get_current_user_id() );

		if ( ! $job ) {
			return new \WP_Error( 'job_not_found', __( 'Image job not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}

		if ( $job['status'] === 'queued' && ! empty( $job['dispatched_at'] ) && ( time() - (int) $job['dispatched_at'] ) >= 4 ) {
			Image_Jobs::dispatch_job( $job );
			$job = Image_Jobs::get_job_for_user( $job_id, get_current_user_id() );
			if ( ! $job ) {
				return new \WP_Error( 'job_not_found', __( 'Image job not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
			}
		}

		return rest_ensure_response( Image_Jobs::public_job_payload( $job ) );
	}

	/**
	 * Stream provider-backed image job updates to the browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void|\WP_REST_Response|\WP_Error
	 */
	public static function images_job_stream_handler( $request ) {
		$job_id = (string) $request->get_param( 'job_id' );
		$job    = Image_Jobs::get_job_for_user( $job_id, get_current_user_id() );

		if ( ! $job ) {
			return new \WP_Error( 'job_not_found', __( 'Image job not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}

		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'implicit_flush', '1' );
		nocache_headers();
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";
		if ( function_exists( 'flush' ) ) {
			flush();
		}

		self::emit_sse_image_job( 'job', Image_Jobs::public_job_payload( $job ) );

		if ( $job['status'] === 'completed' || $job['status'] === 'failed' ) {
			self::emit_sse_image_job( 'done', Image_Jobs::public_job_payload( $job ) );
			exit;
		}

		$result = Image_Jobs::process_job(
			$job_id,
			(string) $job['dispatch_token'],
			function ( $payload ) {
				self::emit_sse_image_job( 'job', $payload );
			}
		);

		if ( is_wp_error( $result ) ) {
			$latest = Image_Jobs::get_job_for_user( $job_id, get_current_user_id() );
			$payload = $latest ? Image_Jobs::public_job_payload( $latest ) : array(
				'job_id' => $job_id,
				'status' => 'failed',
				'error'  => $result->get_error_message(),
			);
			self::emit_sse_image_job( 'error', $payload );
			exit;
		}

		self::emit_sse_image_job( 'done', $result );
		exit;
	}

	/**
	 * Internal loopback endpoint that processes an image job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function images_job_process_handler( $request ) {
		$result = Image_Jobs::process_job(
			(string) $request->get_param( 'job_id' ),
			(string) $request->get_param( 'token' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Emit one SSE image job event.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event payload.
	 * @return void
	 */
	private static function emit_sse_image_job( $event, $payload ) {
		echo 'event: ' . sanitize_key( $event ) . "\n";
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
		echo ':' . str_repeat( ' ', 1024 ) . "\n\n";
		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * List recent image jobs for the admin queue page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_image_jobs_list( $request ) {
		$jobs = Image_Jobs::list_jobs_for_admin( (int) $request->get_param( 'limit' ) );
		$payload = array_map( array( Image_Jobs::class, 'admin_job_summary_payload' ), $jobs );
		$stats = Image_Jobs::get_queue_stats();
		$stats['recent_total'] = count( $payload );
		$response = array(
			'stats' => $stats,
			'jobs'  => $payload,
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[alorbach-image-queue] list_response_bytes %s',
					wp_json_encode(
						array(
							'jobs'  => count( $payload ),
							'bytes' => strlen( wp_json_encode( $response ) ),
						)
					)
				)
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get a single image job detail payload for admin monitoring.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function admin_image_job_detail( $request ) {
		$include_images = rest_sanitize_boolean( $request->get_param( 'include_images' ) );
		$job = Image_Jobs::get_job_for_admin(
			(string) $request->get_param( 'job_id' ),
			$include_images
		);
		if ( ! $job ) {
			return new \WP_Error( 'job_not_found', __( 'Image job not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[alorbach-image-queue] detail_response_bytes %s',
					wp_json_encode(
						array(
							'job_id'         => (string) $request->get_param( 'job_id' ),
							'include_images' => $include_images,
							'bytes'          => strlen( wp_json_encode( $job ) ),
						)
					)
				)
			);
		}

		return rest_ensure_response( Image_Jobs::admin_job_payload( $job ) );
	}

	/**
	 * Execute one admin queue maintenance action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_image_jobs_action( $request ) {
		$payload = \Alorbach\AIGateway\Admin\Admin_Image_Queue::execute_action(
			(string) $request->get_param( 'action' )
		);

		$stats = Image_Jobs::get_queue_stats();
		$stats['recent_total'] = min( 50, (int) ( $stats['total'] ?? 0 ) );
		$payload['stats'] = $stats;

		return rest_ensure_response( $payload );
	}

	/**
	 * Whisper transcribe handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function transcribe_handler( $request ) {
		$user_id   = get_current_user_id();

		$rate_error = self::check_rate_limit( $user_id, 'transcribe' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$quota_error = self::check_monthly_quota( $user_id );
		if ( $quota_error ) {
			return $quota_error;
		}

		$audio_b64 = $request->get_param( 'audio_base64' );
		$duration  = (int) $request->get_param( 'duration_seconds' );
		$model     = $request->get_param( 'model' ) ?: 'whisper-1';
		$prompt    = $request->get_param( 'prompt' ) ?: '';
		$format    = $request->get_param( 'audio_format' ) ?: null;

		$plan_error = self::enforce_user_plan_access( $user_id, 'audio', $model );
		if ( $plan_error ) {
			return $plan_error;
		}

		// Limit encoded size to ~48 MB decoded to prevent DoS via memory exhaustion.
		if ( ! is_string( $audio_b64 ) || strlen( $audio_b64 ) > 67108864 ) {
			return new \WP_Error( 'audio_too_large', __( 'Audio file exceeds the 48 MB limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}

		$decoded = base64_decode( $audio_b64, true );
		if ( false === $decoded ) {
			return new \WP_Error( 'invalid_audio', __( 'Invalid base64 audio.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		// Idempotency: reject duplicate transcribe requests within a 5-minute window.
		// Include prompt so retries with different instructions are allowed.
		$time_bucket       = (int) ( time() / 300 );
		$request_signature = hash( 'sha256', wp_json_encode( array( $user_id, 'transcribe', hash( 'sha256', $audio_b64 ), $model, $prompt, $time_bucket ) ) );
		if ( Ledger::signature_exists( $request_signature ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = \wp_tempnam( 'alorbach-whisper-' );
		if ( ! $tmp ) {
			return new \WP_Error( 'upload_error', __( 'Could not create temporary file.', 'alorbach-ai-gateway' ), array( 'status' => 500 ) );
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $tmp, $decoded, FS_CHMOD_FILE ) ) {
			return new \WP_Error( 'upload_error', __( 'Could not save audio.', 'alorbach-ai-gateway' ), array( 'status' => 500 ) );
		}

		if ( ! $format ) {
			$format = \Alorbach\AIGateway\API_Client::detect_audio_format( $decoded );
		}

		if ( $duration <= 0 && class_exists( 'getID3' ) ) {
			$getid3 = new \getID3();
			$info   = $getid3->analyze( $tmp );
			$duration = isset( $info['playtime_seconds'] ) ? (int) ceil( $info['playtime_seconds'] ) : 0;
		}
		if ( $duration <= 0 ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'invalid_duration', __( 'duration_seconds required when getID3 is not available.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$api_cost = Cost_Matrix::get_audio_cost( $duration, $model );
		$cost     = Cost_Matrix::apply_user_cost( $api_cost, $model );
		$balance  = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			wp_delete_file( $tmp );
			return self::insufficient_credits_error(
				$user_id,
				'audio',
				array(
					'model'        => $model,
					'duration'     => $duration,
					'required_uc'  => $cost,
					'available_uc' => $balance,
				)
			);
		}

		$response = API_Client::transcribe( $tmp, $model, $prompt, $format );
		wp_delete_file( $tmp );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'audio_deduction', $model, -$cost, null, null, null, $request_signature, $api_cost );
		do_action( 'alorbach_after_deduction', $user_id, 'audio', $model, $cost, $api_cost );
		$response['cost_uc']           = $cost;
		$response['cost_credits']       = User_Display::uc_to_credits( $cost );
		$response['cost_usd']           = User_Display::uc_to_usd( $cost );
		$response['duration_seconds']   = $duration;
		return rest_ensure_response( $response );
	}

	/**
	 * Video generation handler (Sora).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function video_handler( $request ) {
		$user_id = get_current_user_id();

		$rate_error = self::check_rate_limit( $user_id, 'video' );
		if ( $rate_error ) {
			return $rate_error;
		}

		$quota_error = self::check_monthly_quota( $user_id );
		if ( $quota_error ) {
			return $quota_error;
		}

		$prompt  = $request->get_param( 'prompt' );
		$model   = $request->get_param( 'model' ) ?: 'sora-2';
		$size    = $request->get_param( 'size' ) ?: '1280x720';
		$duration = max( 4, min( 12, (int) $request->get_param( 'duration_seconds' ) ) );
		if ( ! in_array( (string) $duration, array( '4', '8', '12' ), true ) ) {
			$duration = 8;
		}

		$plan_error = self::enforce_user_plan_access( $user_id, 'video', $model );
		if ( $plan_error ) {
			return $plan_error;
		}

		// Idempotency: reject duplicate video requests within a 5-minute window.
		$time_bucket       = (int) ( time() / 300 );
		$request_signature = hash( 'sha256', wp_json_encode( array( $user_id, 'video', $prompt, $model, $size, $duration, $time_bucket ) ) );
		if ( Ledger::signature_exists( $request_signature ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$api_cost = Cost_Matrix::get_video_cost( $model, $duration );
		$cost     = Cost_Matrix::apply_user_cost( $api_cost, $model );
		$balance  = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			return self::insufficient_credits_error(
				$user_id,
				'video',
				array(
					'model'        => $model,
					'size'         => $size,
					'duration'     => $duration,
					'required_uc'  => $cost,
					'available_uc' => $balance,
				)
			);
		}

		$response = API_Client::video( $prompt, $model, $size, $duration );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'video_deduction', $model, -$cost, null, null, null, $request_signature, $api_cost );
		do_action( 'alorbach_after_deduction', $user_id, 'video', $model, $cost, $api_cost );
		$response['cost_uc']      = $cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $cost );
		$response['cost_usd']     = User_Display::uc_to_usd( $cost );
		return rest_ensure_response( $response );
	}

	/**
	 * Admin: Verify API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_api_key( $request ) {
		$provider = self::get_json_or_query_param( $request, 'provider' );
		$entry_id = self::get_json_or_query_param( $request, 'entry_id' );
		if ( empty( $provider ) || ! in_array( $provider, array( 'openai', 'azure', 'google', 'github_models', 'codex' ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Invalid or missing provider.', 'alorbach-ai-gateway' ) ) );
		}
		$result = API_Validator::verify_key( $provider, $entry_id );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify text model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_text( $request ) {
		$provider = self::get_json_or_query_param( $request, 'provider' );
		$model    = self::get_json_or_query_param( $request, 'model' );
		$entry_id = self::get_json_or_query_param( $request, 'entry_id' );
		$result = API_Validator::verify_text_model( $provider, $model, $entry_id );
		if ( (bool) get_option( 'alorbach_debug_enabled', false ) && current_user_can( 'manage_options' ) && is_array( $result ) ) {
			$result['_debug'] = array(
				'provider' => $provider,
				'model'    => $model,
				'result_empty' => empty( $result['result'] ),
			);
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify image model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_image( $request ) {
		$size  = self::get_json_or_query_param( $request, 'size', '1024x1024' );
		$model = self::get_json_or_query_param( $request, 'model' );
		$result = API_Validator::verify_image_model( $size, $model );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify audio model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_audio( $request ) {
		try {
			$model  = self::get_json_or_query_param( $request, 'model', 'whisper-1' );
			$result = API_Validator::verify_audio_model( $model );
			return rest_ensure_response( $result );
		} catch ( \Throwable $e ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => $e->getMessage(),
			) );
		}
	}

	/**
	 * Admin: Verify video model (quick create, no polling).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_video( $request ) {
		$model  = self::get_json_or_query_param( $request, 'model', 'sora-2' );
		$result = API_Validator::verify_video_model( $model );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Fetch importable models (with capabilities). Does not import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_fetch_importable_models( $request ) {
		$result = Model_Importer::fetch_importable_models();
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Import models from providers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_import_models( $request ) {
		$selected = self::parse_import_selected( $request );
		$result   = Model_Importer::import_from_providers( false, $selected );
		if ( (bool) get_option( 'alorbach_debug_enabled', false ) && current_user_can( 'manage_options' ) ) {
			$entries = API_Keys_Helper::get_entries();
			$result['_debug'] = array(
				'body_empty'         => empty( $request->get_body() ),
				'selected_null'      => $selected === null,
				'entry_ids_received' => $selected && isset( $selected['entries'] ) ? array_keys( $selected['entries'] ) : array(),
				'entry_ids_backend'  => array_map( function ( $e ) { return ( $e['type'] ?? '' ) . ':' . ( $e['id'] ?? '' ); }, $entries ),
				'models_per_entry'   => $selected && isset( $selected['entries'] ) ? array_map( function ( $e ) { return array( 'text' => count( $e['text'] ?? array() ), 'image' => count( $e['image'] ?? array() ), 'video' => count( $e['video'] ?? array() ) ); }, $selected['entries'] ) : array(),
			);
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Parse selected models from request body (avoids REST sanitization of nested entries).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|null Selected: { entries: { entry_id => { text, image, video, audio } } } or null.
	 */
	private static function parse_import_selected( $request ) {
		$body = $request->get_body();
		if ( ! empty( $body ) ) {
			$json = json_decode( $body, true );
			if ( is_array( $json ) && isset( $json['selected'] ) && is_array( $json['selected'] ) ) {
				return self::normalize_selected( $json['selected'] );
			}
		}
		$json = $request->get_json_params();
		if ( is_array( $json ) && isset( $json['selected'] ) && is_array( $json['selected'] ) ) {
			return self::normalize_selected( $json['selected'] );
		}
		return null;
	}

	/**
	 * Normalize selected structure: ensure entries is object with string keys.
	 *
	 * @param array $selected Raw from JSON.
	 * @return array Normalized selected.
	 */
	private static function normalize_selected( $selected ) {
		if ( ! isset( $selected['entries'] ) || ! is_array( $selected['entries'] ) ) {
			return $selected;
		}
		$entries = array();
		foreach ( $selected['entries'] as $k => $v ) {
			$key = is_string( $k ) ? $k : (string) $k;
			if ( $key === '' ) {
				continue;
			}
			$entries[ $key ] = is_array( $v ) ? array(
				'text'  => isset( $v['text'] ) && is_array( $v['text'] ) ? $v['text'] : array(),
				'image' => isset( $v['image'] ) && is_array( $v['image'] ) ? $v['image'] : array(),
				'video' => isset( $v['video'] ) && is_array( $v['video'] ) ? $v['video'] : array(),
				'audio' => isset( $v['audio'] ) && is_array( $v['audio'] ) ? $v['audio'] : array(),
			) : array( 'text' => array(), 'image' => array(), 'video' => array(), 'audio' => array() );
		}
		$selected['entries'] = $entries;
		return $selected;
	}

	/**
	 * Admin: Reset models and re-import from APIs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_reset_models( $request ) {
		$selected = self::parse_import_selected( $request );
		$result   = Model_Importer::reset_and_import( $selected );
		if ( (bool) get_option( 'alorbach_debug_enabled', false ) && current_user_can( 'manage_options' ) ) {
			$entries = API_Keys_Helper::get_entries();
			$result['_debug'] = array(
				'body_empty'         => empty( $request->get_body() ),
				'selected_null'      => $selected === null,
				'entry_ids_received' => $selected && isset( $selected['entries'] ) ? array_keys( $selected['entries'] ) : array(),
				'entry_ids_backend'  => array_map( function ( $e ) { return ( $e['type'] ?? '' ) . ':' . ( $e['id'] ?? '' ); }, $entries ),
				'models_per_entry'   => $selected && isset( $selected['entries'] ) ? array_map( function ( $e ) { return array( 'text' => count( $e['text'] ?? array() ), 'image' => count( $e['image'] ?? array() ), 'video' => count( $e['video'] ?? array() ) ); }, $selected['entries'] ) : array(),
			);
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Clear Azure Retail Prices cache so next import fetches fresh data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_refresh_azure_prices( $request ) {
		Azure_Retail_Prices::clear_cache();
		return rest_ensure_response( array( 'success' => true, 'message' => __( 'Azure prices cache cleared. Next import will fetch fresh data.', 'alorbach-ai-gateway' ) ) );
	}

	/**
	 * Admin: Save Google model whitelist from selected models in import modal.
	 *
	 * @param \WP_REST_Request $request Request. Body: { "model_ids": ["gemini-2.5-flash", ...] }.
	 * @return \WP_REST_Response
	 */
	public static function admin_save_google_whitelist( $request ) {
		$body = $request->get_body();
		$json = ! empty( $body ) ? json_decode( $body, true ) : $request->get_json_params();
		$model_ids = isset( $json['model_ids'] ) && is_array( $json['model_ids'] ) ? $json['model_ids'] : array();
		$sanitized = array();
		foreach ( $model_ids as $id ) {
			$id = sanitize_text_field( is_string( $id ) ? $id : (string) $id );
			if ( $id !== '' && $id !== 'default' ) {
				$sanitized[] = $id;
			}
		}
		$whitelist = implode( ', ', array_unique( $sanitized ) );
		update_option( 'alorbach_google_model_whitelist', $whitelist );
		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Google model whitelist saved. Future imports will only show these models.', 'alorbach-ai-gateway' ),
		) );
	}

	/**
	 * Execute a multi-step chat request, automatically continuing when the AI
	 * truncates its response (finish_reason === "length") until the full output
	 * is received or the maximum number of steps is reached.
	 *
	 * @param int    $user_id          WordPress user ID.
	 * @param string $provider         AI provider slug.
	 * @param array  $body             Initial request body (model, messages, max_tokens).
	 * @param array  $messages         Initial conversation messages.
	 * @param string $model            Model identifier.
	 * @param int    $max_steps        Maximum continuation steps (1–20).
	 * @param string $continue_message Continuation prompt injected between steps.
	 * @param bool   $free_pass_through Skip billing when true.
	 * @param string $base_signature   Idempotency base; each step appends "_step_N".
	 * @param string $lock_key         Transient key guarding in-flight retries.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function execute_multi_step_chat(
		$user_id,
		$provider,
		$body,
		$messages,
		$model,
		$max_steps,
		$continue_message,
		$free_pass_through,
		$base_signature,
		$lock_key
	) {
		// Long-running jobs may exceed the default PHP execution time limit.
		// Use a configurable hard cap to prevent unbounded blocking requests (DoS).
		$timeout  = max( 30, (int) get_option( 'alorbach_multi_step_timeout', 120 ) );
		$deadline = time() + $timeout;
		set_time_limit( $timeout + 5 ); // Allow PHP a small grace period beyond our deadline.

		if ( empty( $continue_message ) ) {
			$continue_message = 'Continue exactly where you left off without any preamble or repetition.';
		}

		$content_chunks          = array();
		$total_prompt_tokens     = 0;
		$total_completion_tokens = 0;
		$total_cached_tokens     = 0;
		$total_api_cost          = 0;
		$total_uc_cost           = 0;
		$steps                   = array();
		$last_response           = null;
		$current_messages        = $messages;
		set_transient( $lock_key, 1, $timeout + 30 );

		for ( $step = 1; $step <= $max_steps; $step++ ) {
			// Abort gracefully if we are approaching the wall-clock deadline.
			if ( time() >= $deadline ) {
				break;
			}

			// Per-step balance check with a cost estimate — stop spending if credits
			// are insufficient for this step before making the (costly) API call.
			if ( ! $free_pass_through ) {
				$balance            = Ledger::get_balance( $user_id );
				$step_input_tokens  = Tokenizer::count_messages_tokens( $current_messages, $model );
				$step_output_est    = $body['max_tokens'];
				$step_cost_estimate = Cost_Matrix::apply_user_cost(
					Cost_Matrix::calculate_chat_cost( $model, $step_input_tokens, $step_output_est, 0 ),
					$model
				);
				if ( $balance < $step_cost_estimate ) {
					delete_transient( $lock_key );
					return self::insufficient_credits_error(
						$user_id,
						'chat',
						array(
							'model'        => $model,
							'multi_step'   => true,
							'required_uc'  => $step_cost_estimate,
							'available_uc' => $balance,
							'step'         => $step,
						),
						__( 'Insufficient credits to continue.', 'alorbach-ai-gateway' )
					);
				}
			}

			$body['messages'] = $current_messages;
			$response         = API_Client::chat( $provider, $body );
			if ( is_wp_error( $response ) ) {
				delete_transient( $lock_key );
				return $response;
			}

			$last_response = $response;
			$finish_reason = isset( $response['choices'][0]['finish_reason'] ) ? $response['choices'][0]['finish_reason'] : 'stop';
			$step_content  = isset( $response['choices'][0]['message']['content'] ) ? (string) $response['choices'][0]['message']['content'] : '';

			$content_chunks[] = $step_content;

			$step_prompt_tokens     = 0;
			$step_completion_tokens = 0;
			$step_uc_cost           = 0;

			if ( ! $free_pass_through ) {
				$step_prompt_tokens     = isset( $response['usage']['prompt_tokens'] ) ? (int) $response['usage']['prompt_tokens'] : 0;
				$step_completion_tokens = isset( $response['usage']['completion_tokens'] ) ? (int) $response['usage']['completion_tokens'] : 0;
				$step_cached_tokens     = isset( $response['usage']['prompt_tokens_details']['cached_tokens'] ) ? (int) $response['usage']['prompt_tokens_details']['cached_tokens'] : 0;
				$step_api_cost          = Cost_Matrix::calculate_chat_cost( $model, $step_prompt_tokens, $step_completion_tokens, $step_cached_tokens );
				$step_uc_cost           = Cost_Matrix::apply_user_cost( $step_api_cost, $model );

				Ledger::insert_transaction(
					$user_id,
					'chat_deduction',
					$model,
					- $step_uc_cost,
					$step_prompt_tokens,
					$step_cached_tokens,
					$step_completion_tokens,
					$base_signature . '_step_' . $step,
					$step_api_cost
				);

				$total_prompt_tokens     += $step_prompt_tokens;
				$total_completion_tokens += $step_completion_tokens;
				$total_cached_tokens     += $step_cached_tokens;
				$total_api_cost          += $step_api_cost;
				$total_uc_cost           += $step_uc_cost;
			}

			$steps[] = array(
				'step'              => $step,
				'finish_reason'     => $finish_reason,
				'completion_tokens' => $step_completion_tokens,
				'cost_uc'           => $step_uc_cost,
			);

			if ( $finish_reason !== 'length' ) {
				break;
			}

			// Truncated: append partial assistant reply and inject continuation prompt.
			$current_messages[] = array( 'role' => 'assistant', 'content' => $step_content );
			$current_messages[] = array( 'role' => 'user',      'content' => $continue_message );
		}

		// Merge all chunks into the final choices content.
		if ( ! $last_response ) {
			delete_transient( $lock_key );
			return new \WP_Error( 'chat_timeout', __( 'Multi-step chat timed out before a response was completed.', 'alorbach-ai-gateway' ), array( 'status' => 504 ) );
		}

		$combined_content = implode( '', $content_chunks );
		if ( isset( $last_response['choices'][0]['message']['content'] ) ) {
			$last_response['choices'][0]['message']['content'] = $combined_content;
		}

		if ( $free_pass_through ) {
			$last_response['cost_uc']      = 0;
			$last_response['cost_credits'] = User_Display::uc_to_credits( 0 );
			$last_response['cost_usd']     = User_Display::uc_to_usd( 0 );
		} else {
			if ( isset( $last_response['usage'] ) ) {
				$last_response['usage']['prompt_tokens']     = $total_prompt_tokens;
				$last_response['usage']['completion_tokens'] = $total_completion_tokens;
				$last_response['usage']['total_tokens']      = $total_prompt_tokens + $total_completion_tokens;
			}
			$last_response['cost_uc']      = $total_uc_cost;
			$last_response['cost_credits'] = User_Display::uc_to_credits( $total_uc_cost );
			$last_response['cost_usd']     = User_Display::uc_to_usd( $total_uc_cost );
		}

		$last_response['steps_count'] = count( $steps );
		$last_response['steps']       = $steps;
		Ledger::insert_transaction( $user_id, 'chat_deduction', $model, 0, null, null, null, $base_signature );
		delete_transient( $lock_key );

		return rest_ensure_response( $last_response );
	}

	/**
	 * Read a parameter from the JSON body; fall back to query/route param.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param string           $key     Parameter name.
	 * @param string           $default Default when the parameter is absent.
	 * @return string Sanitized value.
	 */
	private static function get_json_or_query_param( $request, $key, $default = '' ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) && isset( $params[ $key ] ) ) {
			return sanitize_text_field( $params[ $key ] );
		}
		$val = $request->get_param( $key );
		return ( $val !== null && $val !== '' ) ? sanitize_text_field( $val ) : $default;
	}
}
