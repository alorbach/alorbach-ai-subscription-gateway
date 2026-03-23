<?php
/**
 * Image generation job manager.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Image_Jobs
 */
class Image_Jobs {

	/**
	 * Job transient prefix.
	 *
	 * @var string
	 */
	const JOB_TRANSIENT_PREFIX = 'alorbach_image_job_';

	/**
	 * Option name used to track recent job ids.
	 *
	 * @var string
	 */
	const JOB_INDEX_OPTION = 'alorbach_image_job_index';

	/**
	 * Default transient TTL in seconds.
	 *
	 * @var int
	 */
	const JOB_TTL = 3600;

	/**
	 * Maximum number of job ids to retain in the index.
	 *
	 * @var int
	 */
	const JOB_INDEX_LIMIT = 200;

	/**
	 * Create an async image generation job.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Job request args.
	 * @return array|WP_Error
	 */
	public static function create_job( $user_id, $args ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'invalid_user', __( 'Invalid user.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$original_prompt = isset( $args['original_prompt'] ) ? (string) wp_unslash( $args['original_prompt'] ) : '';
		$prompt          = isset( $args['prompt'] ) ? sanitize_text_field( $args['prompt'] ) : '';
		$size            = isset( $args['size'] ) ? sanitize_text_field( $args['size'] ) : '1024x1024';
		$model           = isset( $args['model'] ) && $args['model'] ? sanitize_text_field( $args['model'] ) : get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$quality         = isset( $args['quality'] ) && $args['quality'] ? sanitize_text_field( $args['quality'] ) : get_option( 'alorbach_image_default_quality', 'medium' );
		$n               = isset( $args['n'] ) ? max( 1, min( 10, (int) $args['n'] ) ) : 1;

		if ( '' === $original_prompt ) {
			$original_prompt = $prompt;
		}

		if ( '' === $prompt ) {
			return new \WP_Error( 'missing_prompt', __( 'Prompt is required.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		$api_cost = Cost_Matrix::get_image_cost( $size, $model, $quality ) * $n;
		$cost     = Cost_Matrix::apply_user_cost( $api_cost, $model );
		$balance  = Ledger::get_balance( $user_id );

		if ( $balance < $cost ) {
			do_action(
				'alorbach_generation_rejected_insufficient_balance',
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
			return new \WP_Error(
				'insufficient_credits',
				__( 'Insufficient credits.', 'alorbach-ai-gateway' ),
				array( 'status' => 402 )
			);
		}

		$job_id             = wp_generate_uuid4();
		$token              = wp_generate_password( 20, false, false );
		$supports_previews  = API_Client::supports_partial_image_streaming( $model );
		$progress_mode      = $supports_previews ? 'provider' : 'estimated';
		$job     = array(
			'job_id'             => $job_id,
			'user_id'            => $user_id,
			'status'             => 'queued',
			'progress_stage'     => 'queued',
			'progress_percent'   => 10,
			'progress_mode'      => $progress_mode,
			'supports_previews'  => $supports_previews,
			'preview_images'     => array(),
			'final_images'       => array(),
			'prompt'             => $prompt,
			'original_prompt'    => $original_prompt,
			'size'               => $size,
			'n'                  => $n,
			'quality'            => $quality,
			'model'              => $model,
			'cost_uc'            => $cost,
			'cost_credits'       => User_Display::uc_to_credits( $cost ),
			'cost_usd'           => User_Display::uc_to_usd( $cost ),
			'api_cost_uc'        => $api_cost,
			'request_signature'  => hash( 'sha256', wp_json_encode( array( $user_id, 'image_job', $prompt, $size, $model, $quality, $n, time() ) ) ),
			'deduction_applied'  => false,
			'error'              => '',
			'dispatch_token'     => $token,
			'dispatched_at'      => 0,
			'created_at'         => time(),
			'updated_at'         => time(),
		);

		self::save_job( $job );
		self::dispatch_job( $job );

		return self::public_job_payload( $job, false );
	}

	/**
	 * Dispatch background processing for a job.
	 *
	 * @param array $job Job state.
	 * @return void
	 */
	public static function dispatch_job( $job ) {
		if ( empty( $job['job_id'] ) || empty( $job['dispatch_token'] ) ) {
			return;
		}

		$job['dispatched_at'] = time();
		$job['updated_at']    = time();
		self::save_job( $job );

		$url = self::get_internal_process_url();
		$body = wp_json_encode(
			array(
				'job_id' => $job['job_id'],
				'token'  => $job['dispatch_token'],
			)
		);

		if ( self::send_fire_and_forget_request( $url, $body ) ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 1,
				'blocking' => false,
				'headers'  => array(
					'Content-Type' => 'application/json',
				),
				'body'     => $body,
			)
		);
	}

	/**
	 * Build an internal loopback URL for processing jobs.
	 *
	 * Uses the local web server port instead of the public site URL so
	 * containerized environments can reach the same WordPress instance.
	 *
	 * @return string
	 */
	private static function get_internal_process_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$path   = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path   = is_string( $path ) ? rtrim( $path, '/' ) : '';
		$url    = $scheme . '://127.0.0.1';

		return $url . $path . '/index.php?rest_route=/alorbach/v1/internal/images/jobs/process';
	}

	/**
	 * Send a fire-and-forget POST request over a raw socket.
	 *
	 * @param string $url  Target URL.
	 * @param string $body JSON request body.
	 * @return bool
	 */
	private static function send_fire_and_forget_request( $url, $body ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'http';
		$host   = (string) $parts['host'];
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( $scheme === 'https' ? 443 : 80 );
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query  = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$target = $path . ( $query !== '' ? '?' . $query : '' );
		$remote = ( $scheme === 'https' ? 'ssl://' : '' ) . $host;
		$site_parts = wp_parse_url( home_url( '/' ) );
		$host_header = isset( $site_parts['host'] ) ? (string) $site_parts['host'] : $host;
		$site_port   = isset( $site_parts['port'] ) ? (int) $site_parts['port'] : 0;

		$socket = @fsockopen( $remote, $port, $errno, $errstr, 1 );
		if ( ! $socket ) {
			return false;
		}

		if ( $site_port > 0 ) {
			$host_header .= ':' . $site_port;
		}

		$request  = "POST {$target} HTTP/1.1\r\n";
		$request .= "Host: {$host_header}\r\n";
		$request .= "Content-Type: application/json\r\n";
		$request .= 'Content-Length: ' . strlen( $body ) . "\r\n";
		$request .= "Connection: Close\r\n\r\n";
		$request .= $body;

		fwrite( $socket, $request );
		fclose( $socket );

		return true;
	}

	/**
	 * Process a queued image job.
	 *
	 * @param string        $job_id     Job ID.
	 * @param string        $token      Dispatch token.
	 * @param callable|null $on_update  Optional callback invoked after job state updates.
	 * @return array|WP_Error
	 */
	public static function process_job( $job_id, $token, $on_update = null ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'job_not_found', __( 'Image job not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}
		if ( empty( $job['dispatch_token'] ) || ! hash_equals( (string) $job['dispatch_token'], (string) $token ) ) {
			return new \WP_Error( 'invalid_job_token', __( 'Invalid image job token.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}
		if ( in_array( $job['status'], array( 'completed', 'failed', 'in_progress' ), true ) ) {
			return self::public_job_payload( $job );
		}

		$job['status']            = 'in_progress';
		$job['progress_stage']    = 'drafting';
		$job['progress_percent']  = 35;
		$job['supports_previews'] = API_Client::supports_partial_image_streaming( $job['model'] );
		$job['progress_mode']     = $job['supports_previews'] ? 'provider' : 'estimated';
		$job['updated_at']        = time();
		self::save_job( $job );
		if ( is_callable( $on_update ) ) {
			call_user_func( $on_update, self::public_job_payload( $job ) );
		}

		if ( ! empty( $job['supports_previews'] ) ) {
			$response = API_Client::stream_images(
				$job['prompt'],
				$job['size'],
				$job['n'],
				$job['model'],
				$job['quality'],
				null,
				function ( $event ) use ( &$job, $on_update ) {
					if ( empty( $event['type'] ) || empty( $event['images'] ) || ! is_array( $event['images'] ) ) {
						return;
					}

					if ( $event['type'] === 'preview_image' ) {
						$job['preview_images']   = self::merge_images( $job['preview_images'], $event['images'] );
						$preview_count           = count( $job['preview_images'] );
						$job['status']           = 'in_progress';
						$job['progress_stage']   = $preview_count >= 3 ? 'finalizing' : ( $preview_count === 2 ? 'refining' : 'drafting' );
						$job['progress_percent'] = $preview_count >= 3 ? 90 : ( $preview_count === 2 ? 75 : 55 );
						$job['updated_at']       = time();
						self::save_job( $job );
						if ( is_callable( $on_update ) ) {
							call_user_func( $on_update, self::public_job_payload( $job ) );
						}
						return;
					}

					if ( $event['type'] === 'final_image' ) {
						$job['final_images']     = self::merge_images( $job['final_images'], $event['images'] );
						$job['progress_stage']   = 'finalizing';
						$job['progress_percent'] = 95;
						$job['updated_at']       = time();
						self::save_job( $job );
						if ( is_callable( $on_update ) ) {
							call_user_func( $on_update, self::public_job_payload( $job ) );
						}
					}
				}
			);
		} else {
			$response = API_Client::images(
				$job['prompt'],
				$job['size'],
				$job['n'],
				$job['model'],
				$job['quality']
			);
		}

		if ( is_wp_error( $response ) ) {
			$job['status']           = 'failed';
			$job['progress_stage']   = 'failed';
			$job['progress_percent'] = 0;
			$job['error']            = $response->get_error_message();
			$job['updated_at']       = time();
			self::save_job( $job );
			if ( is_callable( $on_update ) ) {
				call_user_func( $on_update, self::public_job_payload( $job ) );
			}
			return $response;
		}

		$job['status']         = 'completed';
		$job['progress_stage'] = 'completed';
		$job['progress_percent'] = 100;
		$job['final_images']   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$job['preview_images'] = isset( $response['preview_images'] ) && is_array( $response['preview_images'] ) ? self::merge_images( $job['preview_images'], $response['preview_images'] ) : $job['preview_images'];
		$job['updated_at']     = time();

		if ( empty( $job['final_images'] ) ) {
			$job['status']           = 'failed';
			$job['progress_stage']   = 'failed';
			$job['progress_percent'] = 0;
			$job['error']            = __( 'Image generation returned no images.', 'alorbach-ai-gateway' );
			self::save_job( $job );
			if ( is_callable( $on_update ) ) {
				call_user_func( $on_update, self::public_job_payload( $job ) );
			}
			return new \WP_Error( 'empty_image_result', $job['error'], array( 'status' => 502 ) );
		}

		if ( ! empty( $job['final_images'] ) && empty( $job['deduction_applied'] ) ) {
			Ledger::insert_transaction(
				$job['user_id'],
				'image_deduction',
				$job['model'],
				- (int) $job['cost_uc'],
				null,
				null,
				null,
				$job['request_signature'],
				(int) $job['api_cost_uc']
			);
			do_action( 'alorbach_after_deduction', $job['user_id'], 'image', $job['model'], (int) $job['cost_uc'], (int) $job['api_cost_uc'] );
			$job['deduction_applied'] = true;
		}

		self::save_job( $job );
		if ( is_callable( $on_update ) ) {
			call_user_func( $on_update, self::public_job_payload( $job ) );
		}
		return self::public_job_payload( $job );
	}

	/**
	 * Get a job owned by a user.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @return array|null
	 */
	public static function get_job_for_user( $job_id, $user_id ) {
		$job = self::get_job( $job_id );
		if ( ! $job || (int) $job['user_id'] !== (int) $user_id ) {
			return null;
		}
		return $job;
	}

	/**
	 * Get a raw job by ID for admin tooling.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public static function get_job_for_admin( $job_id ) {
		return self::get_job( $job_id );
	}

	/**
	 * Get recent jobs for admin queue monitoring.
	 *
	 * @param int $limit Maximum number of jobs to return.
	 * @return array
	 */
	public static function list_jobs_for_admin( $limit = 50 ) {
		$limit = max( 1, min( 200, (int) $limit ) );
		$index = self::get_job_index();
		arsort( $index );

		$jobs          = array();
		$valid_index   = array();

		foreach ( $index as $job_id => $updated_at ) {
			$job = self::get_job( $job_id );
			if ( ! $job ) {
				continue;
			}
			$job['preview_count'] = isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? count( $job['preview_images'] ) : 0;
			$job['final_count']   = isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? count( $job['final_images'] ) : 0;
			unset( $job['preview_images'], $job['final_images'], $job['prompt'], $job['original_prompt'], $job['error'] );
			$jobs[]                = $job;
			$valid_index[ $job_id ] = (int) ( $job['updated_at'] ?? $updated_at );
			if ( count( $jobs ) >= $limit ) {
				break;
			}
		}

		self::save_job_index( $valid_index );

		usort(
			$jobs,
			function ( $a, $b ) {
				return (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 );
			}
		);

		return $jobs;
	}

	/**
	 * Get aggregate queue stats for admin views.
	 *
	 * @param array $jobs Raw jobs.
	 * @return array
	 */
	public static function summarize_jobs( $jobs ) {
		$stats = array(
			'total'       => 0,
			'queued'      => 0,
			'in_progress' => 0,
			'completed'   => 0,
			'failed'      => 0,
		);

		foreach ( $jobs as $job ) {
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			$stats['total']++;
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ]++;
			}
		}

		return $stats;
	}

	/**
	 * Build a detailed admin payload for a job.
	 *
	 * @param array $job Raw job data.
	 * @return array
	 */
	public static function admin_job_payload( $job ) {
		$user_id      = (int) ( $job['user_id'] ?? 0 );
		$user         = $user_id > 0 ? get_user_by( 'id', $user_id ) : null;
		$created_at   = isset( $job['created_at'] ) ? (int) $job['created_at'] : 0;
		$updated_at   = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
		$runtime      = ( $created_at > 0 && $updated_at >= $created_at ) ? ( $updated_at - $created_at ) : null;
		$cost_credits = isset( $job['cost_credits'] ) ? (float) $job['cost_credits'] : 0.0;
		$cost_usd     = isset( $job['cost_usd'] ) ? (float) $job['cost_usd'] : 0.0;

		return array(
			'job_id'              => (string) ( $job['job_id'] ?? '' ),
			'user_id'             => $user_id,
			'user_label'          => $user ? $user->user_login . ' (' . $user->user_email . ')' : '#' . $user_id,
			'status'              => (string) ( $job['status'] ?? 'queued' ),
			'status_label'        => ucfirst( str_replace( '_', ' ', (string) ( $job['status'] ?? 'queued' ) ) ),
			'progress_stage'      => (string) ( $job['progress_stage'] ?? 'queued' ),
			'progress_percent'    => (int) ( $job['progress_percent'] ?? 0 ),
			'progress_mode'       => (string) ( $job['progress_mode'] ?? 'estimated' ),
			'supports_previews'   => ! empty( $job['supports_previews'] ),
			'prompt'              => (string) ( $job['prompt'] ?? '' ),
			'original_prompt'     => (string) ( $job['original_prompt'] ?? ( $job['prompt'] ?? '' ) ),
			'model'               => (string) ( $job['model'] ?? '' ),
			'size'                => (string) ( $job['size'] ?? '' ),
			'quality'             => (string) ( $job['quality'] ?? '' ),
			'n'                   => (int) ( $job['n'] ?? 1 ),
			'preview_images'      => isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? $job['preview_images'] : array(),
			'final_images'        => isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? $job['final_images'] : array(),
			'preview_count'       => isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? count( $job['preview_images'] ) : 0,
			'final_count'         => isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? count( $job['final_images'] ) : 0,
			'cost_uc'             => (int) ( $job['cost_uc'] ?? 0 ),
			'cost_credits'        => $cost_credits,
			'cost_credits_label'  => number_format_i18n( $cost_credits, 2 ) . ' Credits ($' . number_format_i18n( $cost_usd, 2 ) . ')',
			'created_at'          => $created_at,
			'created_at_label'    => $created_at ? wp_date( 'Y-m-d H:i:s', $created_at ) : '',
			'updated_at'          => $updated_at,
			'updated_at_label'    => $updated_at ? wp_date( 'Y-m-d H:i:s', $updated_at ) : '',
			'dispatched_at'       => isset( $job['dispatched_at'] ) ? (int) $job['dispatched_at'] : 0,
			'runtime_seconds'     => $runtime,
			'error'               => (string) ( $job['error'] ?? '' ),
			'deduction_applied'   => ! empty( $job['deduction_applied'] ),
		);
	}

	/**
	 * Build a compact admin payload for queue list rows.
	 *
	 * @param array $job Raw job data.
	 * @return array
	 */
	public static function admin_job_summary_payload( $job ) {
		$user_id      = (int) ( $job['user_id'] ?? 0 );
		$user         = $user_id > 0 ? get_user_by( 'id', $user_id ) : null;
		$created_at   = isset( $job['created_at'] ) ? (int) $job['created_at'] : 0;
		$updated_at   = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
		$runtime      = ( $created_at > 0 && $updated_at >= $created_at ) ? ( $updated_at - $created_at ) : null;
		$cost_credits = isset( $job['cost_credits'] ) ? (float) $job['cost_credits'] : 0.0;
		$cost_usd     = isset( $job['cost_usd'] ) ? (float) $job['cost_usd'] : 0.0;

		return array(
			'job_id'             => (string) ( $job['job_id'] ?? '' ),
			'user_id'            => $user_id,
			'user_label'         => $user ? $user->user_login . ' (' . $user->user_email . ')' : '#' . $user_id,
			'status'             => (string) ( $job['status'] ?? 'queued' ),
			'status_label'       => ucfirst( str_replace( '_', ' ', (string) ( $job['status'] ?? 'queued' ) ) ),
			'progress_stage'     => (string) ( $job['progress_stage'] ?? 'queued' ),
			'progress_percent'   => (int) ( $job['progress_percent'] ?? 0 ),
			'progress_mode'      => (string) ( $job['progress_mode'] ?? 'estimated' ),
			'supports_previews'  => ! empty( $job['supports_previews'] ),
			'model'              => (string) ( $job['model'] ?? '' ),
			'size'               => (string) ( $job['size'] ?? '' ),
			'quality'            => (string) ( $job['quality'] ?? '' ),
			'n'                  => (int) ( $job['n'] ?? 1 ),
			'preview_count'      => isset( $job['preview_count'] ) ? (int) $job['preview_count'] : ( isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? count( $job['preview_images'] ) : 0 ),
			'final_count'        => isset( $job['final_count'] ) ? (int) $job['final_count'] : ( isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? count( $job['final_images'] ) : 0 ),
			'cost_uc'            => (int) ( $job['cost_uc'] ?? 0 ),
			'cost_credits'       => $cost_credits,
			'cost_credits_label' => number_format_i18n( $cost_credits, 2 ) . ' Credits ($' . number_format_i18n( $cost_usd, 2 ) . ')',
			'created_at'         => $created_at,
			'created_at_label'   => $created_at ? wp_date( 'Y-m-d H:i:s', $created_at ) : '',
			'updated_at'         => $updated_at,
			'updated_at_label'   => $updated_at ? wp_date( 'Y-m-d H:i:s', $updated_at ) : '',
			'dispatched_at'      => isset( $job['dispatched_at'] ) ? (int) $job['dispatched_at'] : 0,
			'runtime_seconds'    => $runtime,
			'deduction_applied'  => ! empty( $job['deduction_applied'] ),
		);
	}

	/**
	 * Get public job payload.
	 *
	 * @param array $job              Job data.
	 * @param bool  $include_internal Whether to include internal fields.
	 * @return array
	 */
	public static function public_job_payload( $job, $include_internal = false ) {
		$payload = array(
			'job_id'             => $job['job_id'],
			'status'             => $job['status'],
			'progress_stage'     => $job['progress_stage'],
			'progress_percent'   => (int) $job['progress_percent'],
			'progress_mode'      => $job['progress_mode'],
			'supports_previews'  => ! empty( $job['supports_previews'] ),
			'estimated_progress_mode' => $job['progress_mode'] === 'estimated',
			'preview_images'     => isset( $job['preview_images'] ) ? $job['preview_images'] : array(),
			'final_images'       => isset( $job['final_images'] ) ? $job['final_images'] : array(),
		);

		if ( ! empty( $job['deduction_applied'] ) || $job['status'] === 'completed' ) {
			$payload['cost_uc']      = (int) $job['cost_uc'];
			$payload['cost_credits'] = (float) $job['cost_credits'];
			$payload['cost_usd']     = (float) $job['cost_usd'];
		}
		if ( ! empty( $job['error'] ) ) {
			$payload['error'] = $job['error'];
		}
		if ( $include_internal ) {
			$payload['user_id'] = (int) $job['user_id'];
		}

		return $payload;
	}

	/**
	 * Get a raw job by ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	private static function get_job( $job_id ) {
		$job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Save a job.
	 *
	 * @param array $job Job data.
	 * @return void
	 */
	private static function save_job( $job ) {
		set_transient( self::JOB_TRANSIENT_PREFIX . $job['job_id'], $job, self::JOB_TTL );
		self::touch_job_index( (string) $job['job_id'], (int) ( $job['updated_at'] ?? time() ) );
	}

	/**
	 * Merge image items while preserving uniqueness.
	 *
	 * @param array $existing Existing image items.
	 * @param array $incoming Incoming image items.
	 * @return array
	 */
	private static function merge_images( $existing, $incoming ) {
		$seen = array();
		$merged = array();
		foreach ( array_merge( $existing, $incoming ) as $item ) {
			$hash = md5( wp_json_encode( $item ) );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$merged[] = $item;
		}
		return $merged;
	}

	/**
	 * Read the recent job index.
	 *
	 * @return array
	 */
	private static function get_job_index() {
		$index = get_option( self::JOB_INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Save the recent job index.
	 *
	 * @param array $index Index map keyed by job id.
	 * @return void
	 */
	private static function save_job_index( $index ) {
		update_option( self::JOB_INDEX_OPTION, $index, false );
	}

	/**
	 * Mark a job as recently updated.
	 *
	 * @param string $job_id      Job ID.
	 * @param int    $updated_at  Unix timestamp.
	 * @return void
	 */
	private static function touch_job_index( $job_id, $updated_at ) {
		$index            = self::get_job_index();
		$index[ $job_id ] = $updated_at > 0 ? $updated_at : time();
		arsort( $index );
		$index = array_slice( $index, 0, self::JOB_INDEX_LIMIT, true );
		self::save_job_index( $index );
	}
}
