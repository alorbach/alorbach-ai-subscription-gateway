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
		$window = max( 10, (int) get_option( 'alorbach_rate_limit_window', 60 ) );
		$option_map = array(
			'chat'       => array( 'alorbach_rate_limit_chat', 100 ),
			'images'     => array( 'alorbach_rate_limit_images', 30 ),
			'transcribe' => array( 'alorbach_rate_limit_images', 30 ),
			'video'      => array( 'alorbach_rate_limit_video', 10 ),
		);
		list( $option_key, $default ) = isset( $option_map[ $endpoint ] ) ? $option_map[ $endpoint ] : array( '', 60 );
		$limit = $option_key ? max( 1, (int) get_option( $option_key, $default ) ) : $default;

		$key   = 'alorbach_rl_' . $user_id . '_' . $endpoint;
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				/* translators: %d: rate limit window in seconds */
				sprintf( __( 'Too many requests. Please wait %d seconds before trying again.', 'alorbach-ai-gateway' ), $window ),
				array( 'status' => 429 )
			);
		}
		if ( $count === 0 ) {
			set_transient( $key, 1, $window );
		} else {
			set_transient( $key, $count + 1, $window );
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
				'max_tokens' => array(
					'default'           => 1024,
					'sanitize_callback' => 'absint',
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

		register_rest_route( 'alorbach/v1', '/transcribe', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'transcribe_handler' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'audio_base64'     => array( 'required' => true ),
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
				'provider' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'openai', 'azure', 'google', 'github_models' ),
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

		$messages = $request->get_param( 'messages' );
		$model    = $request->get_param( 'model' );
		$max_tokens = $request->get_param( 'max_tokens' );

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

		$request_signature = hash( 'sha256', wp_json_encode( array( $user_id, $messages, $model, microtime( true ) ) ) );
		if ( Ledger::signature_exists( $request_signature ) ) {
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
			$auth_hold      = Cost_Matrix::apply_user_cost( $input_cost + $output_cost );

			$balance = Ledger::get_balance( $user_id );
			if ( $balance < $auth_hold ) {
				return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
			}
		}

		$body = array(
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => $max_tokens,
		);

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
		$uc_cost  = Cost_Matrix::apply_user_cost( $api_cost );

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
		$admin = \Alorbach\AIGateway\Admin\Admin_Demo_Defaults::class;
		$text_options  = $admin::get_text_models();
		$image_options = $admin::get_image_sizes();
		$audio_options = $admin::get_audio_models();
		$video_options = $admin::get_video_models();

		$default_chat  = get_option( 'alorbach_demo_default_chat_model', $text_options[0] ?? 'gpt-4.1-mini' );
		$default_image = get_option( 'alorbach_demo_default_image_model', $image_options[0] ?? '1024x1024' );
		$default_audio = get_option( 'alorbach_demo_default_audio_model', $audio_options[0] ?? 'whisper-1' );
		$default_video = get_option( 'alorbach_demo_default_video_model', $video_options[0] ?? 'sora-2' );

		$allow_chat         = (bool) get_option( 'alorbach_demo_allow_chat_model_select', false );
		$allow_image_size   = (bool) get_option( 'alorbach_demo_allow_image_size_select', false );
		$allow_image_model  = (bool) get_option( 'alorbach_demo_allow_image_model_select', false );
		$allow_image_quality = (bool) get_option( 'alorbach_demo_allow_image_quality_select', false );
		$allow_audio        = (bool) get_option( 'alorbach_demo_allow_audio_model_select', false );
		$allow_video        = (bool) get_option( 'alorbach_demo_allow_video_model_select', false );

		$default_quality = get_option( 'alorbach_image_default_quality', 'medium' );
		$quality_options = array( 'low', 'medium', 'high' );
		$image_model_options = $admin::get_image_models();
		$default_image_model = get_option( 'alorbach_image_default_model', $image_model_options[0] ?? 'dall-e-3' );

		$max_tokens_options = $admin::get_max_tokens_options();
		$default_max_tokens  = get_option( 'alorbach_demo_default_max_tokens', '1024' );
		$default_max_tokens = in_array( $default_max_tokens, $max_tokens_options, true ) ? $default_max_tokens : ( $max_tokens_options[0] ?? '1024' );

		return rest_ensure_response( array(
			'text'  => array(
				'default'      => in_array( $default_chat, $text_options, true ) ? $default_chat : ( $text_options[0] ?? 'gpt-4.1-mini' ),
				'allow_select' => $allow_chat,
				'options'      => $text_options,
				'max_tokens'   => array(
					'default' => $default_max_tokens,
					'options' => $max_tokens_options,
				),
			),
			'image' => array(
				'size'    => array(
					'default'      => in_array( $default_image, $image_options, true ) ? $default_image : ( $image_options[0] ?? '1024x1024' ),
					'allow_select' => $allow_image_size,
					'options'      => $image_options,
				),
				'model'   => array(
					'default'      => in_array( $default_image_model, $image_model_options, true ) ? $default_image_model : ( $image_model_options[0] ?? 'dall-e-3' ),
					'allow_select' => $allow_image_model,
					'options'      => $image_model_options,
				),
				'quality' => array(
					'default'      => in_array( $default_quality, $quality_options, true ) ? $default_quality : 'medium',
					'allow_select' => $allow_image_quality,
					'options'      => $quality_options,
				),
			),
			'audio' => array(
				'default'      => in_array( $default_audio, $audio_options, true ) ? $default_audio : ( $audio_options[0] ?? 'whisper-1' ),
				'allow_select' => $allow_audio,
				'options'      => $audio_options,
			),
			'video' => array(
				'default'      => in_array( $default_video, $video_options, true ) ? $default_video : ( $video_options[0] ?? 'sora-2' ),
				'allow_select' => $allow_video,
				'options'      => $video_options,
				'size'         => array(
					'default'      => '1280x720',
					'allow_select'  => true,
					'options'       => array( '1280x720', '720x1280', '1920x1080', '1080x1920', '1024x1792', '1792x1024' ),
				),
				'duration'      => array(
					'default'      => '8',
					'allow_select'  => true,
					'options'       => array( '4', '8', '12' ),
				),
			),
		) );
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
			$cost_uc  = Cost_Matrix::apply_user_cost( $api_cost );
		} elseif ( $type === 'video' ) {
			$model    = $request->get_param( 'model' ) ?: get_option( 'alorbach_demo_default_video_model', 'sora-2' );
			$duration = max( 4, min( 12, (int) $request->get_param( 'duration_seconds' ) ) );
			if ( ! in_array( $duration, array( 4, 8, 12 ), true ) ) {
				$duration = 8;
			}
			$api_cost = Cost_Matrix::get_video_cost( $model, $duration );
			$cost_uc  = Cost_Matrix::apply_user_cost( $api_cost );
		} elseif ( $type === 'audio' ) {
			$duration = max( 1, (int) $request->get_param( 'duration_seconds' ) );
			$model    = $request->get_param( 'model' ) ?: get_option( 'alorbach_demo_default_audio_model', 'whisper-1' );
			$api_cost  = Cost_Matrix::get_audio_cost( $duration, $model );
			$cost_uc   = Cost_Matrix::apply_user_cost( $api_cost );
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

		// Idempotency: avoid processing same event twice.
		$event_id = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';
		$sig_key  = 'stripe:' . $event_id;
		if ( $event_id && Ledger::signature_exists( $sig_key ) ) {
			return rest_ensure_response( array( 'received' => true ) );
		}
		if ( $event_id ) {
			Ledger::insert_transaction( 0, 'stripe_idempotency', null, 0, null, null, null, $sig_key );
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
						// Signal failure so Stripe automatically retries the webhook.
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
		$model   = $request->get_param( 'model' ) ?: get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = ( $quality && in_array( $quality, array( 'low', 'medium', 'high' ), true ) )
			? $quality
			: get_option( 'alorbach_image_default_quality', 'medium' );

		$api_cost = Cost_Matrix::get_image_cost( $size, $model, $quality ) * $n;
		$cost     = Cost_Matrix::apply_user_cost( $api_cost );
		$balance  = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$response = API_Client::images( $prompt, $size, $n, $model, $quality );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'image_deduction', $model, -$cost, null, null, null, null, $api_cost );
		$response['cost_uc']      = $cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $cost );
		$response['cost_usd']     = User_Display::uc_to_usd( $cost );
		return rest_ensure_response( $response );
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

		// Limit encoded size to ~48 MB decoded to prevent DoS via memory exhaustion.
		if ( ! is_string( $audio_b64 ) || strlen( $audio_b64 ) > 67108864 ) {
			return new \WP_Error( 'audio_too_large', __( 'Audio file exceeds the 48 MB limit.', 'alorbach-ai-gateway' ), array( 'status' => 413 ) );
		}

		$decoded = base64_decode( $audio_b64, true );
		if ( false === $decoded ) {
			return new \WP_Error( 'invalid_audio', __( 'Invalid base64 audio.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = \wp_tempnam( 'alorbach-whisper-' );
		if ( ! $tmp || false === file_put_contents( $tmp, $decoded ) ) {
			return new \WP_Error( 'upload_error', __( 'Could not save audio.', 'alorbach-ai-gateway' ), array( 'status' => 500 ) );
		}

		if ( $duration <= 0 && class_exists( 'getID3' ) ) {
			$getid3 = new \getID3();
			$info   = $getid3->analyze( $tmp );
			$duration = isset( $info['playtime_seconds'] ) ? (int) ceil( $info['playtime_seconds'] ) : 0;
		}
		if ( $duration <= 0 ) {
			@unlink( $tmp );
			return new \WP_Error( 'invalid_duration', __( 'duration_seconds required when getID3 is not available.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$api_cost = Cost_Matrix::get_audio_cost( $duration, $model );
		$cost     = Cost_Matrix::apply_user_cost( $api_cost );
		$balance  = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			@unlink( $tmp );
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$response = API_Client::transcribe( $tmp, $model, $prompt );
		@unlink( $tmp );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'audio_deduction', $model, -$cost, null, null, null, null, $api_cost );
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

		$api_cost = Cost_Matrix::get_video_cost( $model, $duration );
		$cost     = Cost_Matrix::apply_user_cost( $api_cost );
		$balance  = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$response = API_Client::video( $prompt, $model, $size, $duration );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'video_deduction', $model, -$cost, null, null, null, null, $api_cost );
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
		$provider = $request->get_param( 'provider' );
		if ( empty( $provider ) ) {
			$json = $request->get_json_params();
			$provider = isset( $json['provider'] ) ? sanitize_text_field( $json['provider'] ) : '';
		}
		if ( empty( $provider ) || ! in_array( $provider, array( 'openai', 'azure', 'google', 'github_models' ), true ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Invalid or missing provider.', 'alorbach-ai-gateway' ) ) );
		}
		$result = API_Validator::verify_key( $provider );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify text model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_text( $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			$provider = isset( $params['provider'] ) ? sanitize_text_field( $params['provider'] ) : '';
			$model    = isset( $params['model'] ) ? sanitize_text_field( $params['model'] ) : '';
			$entry_id = isset( $params['entry_id'] ) ? sanitize_text_field( $params['entry_id'] ) : '';
		} else {
			$provider = $request->get_param( 'provider' ) ?: '';
			$model    = $request->get_param( 'model' ) ?: '';
			$entry_id = $request->get_param( 'entry_id' ) ?: '';
		}
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
		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			$size  = isset( $params['size'] ) ? sanitize_text_field( $params['size'] ) : '1024x1024';
			$model = isset( $params['model'] ) ? sanitize_text_field( $params['model'] ) : '';
		} else {
			$size  = $request->get_param( 'size' ) ?: '1024x1024';
			$model = $request->get_param( 'model' ) ?: '';
		}
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
			$params = $request->get_json_params();
			$model  = ( is_array( $params ) && isset( $params['model'] ) ) ? sanitize_text_field( $params['model'] ) : ( $request->get_param( 'model' ) ?: 'whisper-1' );
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
		$params = $request->get_json_params();
		$model  = ( is_array( $params ) && isset( $params['model'] ) ) ? sanitize_text_field( $params['model'] ) : ( $request->get_param( 'model' ) ?: 'sora-2' );
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
}
