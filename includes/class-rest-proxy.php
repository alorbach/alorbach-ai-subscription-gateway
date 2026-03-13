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
				'prompt' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'model'  => array( 'default' => 'sora-2', 'sanitize_callback' => 'sanitize_text_field' ),
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
					'enum'              => array( 'openai', 'azure', 'google' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/verify-text', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_text' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'provider' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'model'    => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/verify-image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_verify_image' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'size' => array(
					'default'           => '1024x1024',
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

		register_rest_route( 'alorbach/v1', '/admin/fetch-importable-models', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_fetch_importable_models' ),
			'permission_callback' => $admin_permission,
		) );

		register_rest_route( 'alorbach/v1', '/admin/import-models', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_import_models' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'selected' => array(
					'required' => false,
					'type'     => 'object',
					'description' => 'Selected model IDs: { text: [], image: [], audio: [] }',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/reset-models', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_reset_models' ),
			'permission_callback' => $admin_permission,
			'args'                => array(
				'selected' => array(
					'required' => false,
					'type'     => 'object',
					'description' => 'Selected model IDs: { text: [], image: [], audio: [] }',
				),
			),
		) );

		register_rest_route( 'alorbach/v1', '/admin/refresh-azure-prices', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_refresh_azure_prices' ),
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
		$messages = $request->get_param( 'messages' );
		$model    = $request->get_param( 'model' );
		$max_tokens = $request->get_param( 'max_tokens' );

		$input_tokens = Tokenizer::count_messages_tokens( $messages, $model );
		$output_estimate = $max_tokens;
		$input_cost  = (int) ( ( $input_tokens * Cost_Matrix::get_input_cost_per_token( $model ) ) / 1000000 );
		$output_cost = (int) ( ( $output_estimate * Cost_Matrix::get_output_cost_per_token( $model ) ) / 1000000 );
		$auth_hold   = $input_cost + $output_cost;

		$balance = Ledger::get_balance( $user_id );
		if ( $balance < $auth_hold ) {
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$request_signature = hash( 'sha256', wp_json_encode( array( $user_id, $messages, $model, time() ) ) );
		if ( Ledger::signature_exists( $request_signature ) ) {
			return new \WP_Error( 'duplicate_request', __( 'Duplicate request.', 'alorbach-ai-gateway' ), array( 'status' => 409 ) );
		}

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => $max_tokens,
		);

		$provider = API_Client::get_provider_for_model( $model );
		$response = API_Client::chat( $provider, $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$prompt_tokens    = isset( $response['usage']['prompt_tokens'] ) ? (int) $response['usage']['prompt_tokens'] : $input_tokens;
		$completion_tokens = isset( $response['usage']['completion_tokens'] ) ? (int) $response['usage']['completion_tokens'] : 0;
		$cached_tokens   = isset( $response['usage']['prompt_tokens_details']['cached_tokens'] ) ? (int) $response['usage']['prompt_tokens_details']['cached_tokens'] : 0;

		$uc_cost = Cost_Matrix::calculate_chat_cost( $model, $prompt_tokens, $completion_tokens, $cached_tokens );

		Ledger::insert_transaction(
			$user_id,
			'chat_deduction',
			$model,
			- $uc_cost,
			$prompt_tokens,
			$cached_tokens,
			$completion_tokens,
			$request_signature
		);

		$response['cost_uc']      = $uc_cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $uc_cost );
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
			'balance_uc' => $balance,
			'balance_credits' => User_Display::uc_to_credits( $balance ),
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
		$allow_image        = (bool) get_option( 'alorbach_demo_allow_image_model_select', false );
		$allow_image_quality = (bool) get_option( 'alorbach_demo_allow_image_quality_select', false );
		$allow_audio        = (bool) get_option( 'alorbach_demo_allow_audio_model_select', false );
		$allow_video        = (bool) get_option( 'alorbach_demo_allow_video_model_select', false );

		$default_quality = get_option( 'alorbach_image_default_quality', 'medium' );
		$quality_options = array( 'low', 'medium', 'high' );

		return rest_ensure_response( array(
			'text'  => array(
				'default'      => in_array( $default_chat, $text_options, true ) ? $default_chat : ( $text_options[0] ?? 'gpt-4.1-mini' ),
				'allow_select' => $allow_chat,
				'options'      => $text_options,
			),
			'image' => array(
				'default'      => in_array( $default_image, $image_options, true ) ? $default_image : ( $image_options[0] ?? '1024x1024' ),
				'allow_select' => $allow_image,
				'options'      => $image_options,
				'quality'      => array(
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
			),
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
		// Replay protection: reject if older than 5 minutes.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
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
					Ledger::insert_transaction( $user_id, 'subscription_credit', null, $credits );
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
		$prompt  = $request->get_param( 'prompt' );
		$size    = $request->get_param( 'size' );
		$n       = (int) $request->get_param( 'n' );
		$n       = min( 10, max( 1, $n ) );
		$quality = $request->get_param( 'quality' );
		$model   = get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality = ( $quality && in_array( $quality, array( 'low', 'medium', 'high' ), true ) )
			? $quality
			: get_option( 'alorbach_image_default_quality', 'medium' );

		$cost = Cost_Matrix::get_image_cost( $size, $model, $quality ) * $n;
		$balance = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$response = API_Client::images( $prompt, $size, $n, $model, $quality );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'image_deduction', $model, -$cost );
		$response['cost_uc']      = $cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $cost );
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
		$audio_b64 = $request->get_param( 'audio_base64' );
		$duration  = (int) $request->get_param( 'duration_seconds' );
		$model     = $request->get_param( 'model' ) ?: 'whisper-1';
		$prompt    = $request->get_param( 'prompt' ) ?: '';

		$decoded = base64_decode( $audio_b64, true );
		if ( false === $decoded ) {
			return new \WP_Error( 'invalid_audio', __( 'Invalid base64 audio.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$tmp = wp_tempnam( 'alorbach-whisper-' );
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

		$cost = Cost_Matrix::get_audio_cost( $duration, $model );
		$balance = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			@unlink( $tmp );
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$response = API_Client::transcribe( $tmp, $model, $prompt );
		@unlink( $tmp );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'audio_deduction', $model, -$cost );
		$response['cost_uc']           = $cost;
		$response['cost_credits']       = User_Display::uc_to_credits( $cost );
		$response['duration_seconds']  = $duration;
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
		$prompt  = $request->get_param( 'prompt' );
		$model   = $request->get_param( 'model' ) ?: 'sora-2';

		$cost = Cost_Matrix::get_video_cost( $model );
		$balance = Ledger::get_balance( $user_id );
		if ( $balance < $cost ) {
			return new \WP_Error( 'insufficient_credits', __( 'Insufficient credits.', 'alorbach-ai-gateway' ), array( 'status' => 402 ) );
		}

		$response = API_Client::video( $prompt, $model );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		Ledger::insert_transaction( $user_id, 'video_deduction', $model, -$cost );
		$response['cost_uc']      = $cost;
		$response['cost_credits'] = User_Display::uc_to_credits( $cost );
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
		$method   = 'verify_' . $provider . '_key';
		if ( ! method_exists( API_Validator::class, $method ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Unknown provider.', 'alorbach-ai-gateway' ) ) );
		}
		$result = API_Validator::$method();
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify text model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_text( $request ) {
		$provider = $request->get_param( 'provider' );
		$model    = $request->get_param( 'model' );
		$result   = API_Validator::verify_text_model( $provider, $model );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify image model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_image( $request ) {
		$size   = $request->get_param( 'size' );
		$result = API_Validator::verify_image_model( $size );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Verify audio model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_verify_audio( $request ) {
		$model  = $request->get_param( 'model' ) ?: 'whisper-1';
		$result = API_Validator::verify_audio_model( $model );
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
		$result['capability_labels'] = Model_Importer::$capability_labels;
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Import models from providers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_import_models( $request ) {
		$selected = $request->get_param( 'selected' );
		$selected = is_array( $selected ) ? $selected : null;
		$result = Model_Importer::import_from_providers( false, $selected );
		return rest_ensure_response( $result );
	}

	/**
	 * Admin: Reset models and re-import from APIs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_reset_models( $request ) {
		$selected = $request->get_param( 'selected' );
		$selected = is_array( $selected ) ? $selected : null;
		$result = Model_Importer::reset_and_import( $selected );
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
}
