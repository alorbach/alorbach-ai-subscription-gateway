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
	 * Asset reference transient prefix.
	 *
	 * @var string
	 */
	const JOB_ASSET_TRANSIENT_PREFIX = 'alorbach_image_job_assets_';

	/**
	 * Prompt transient prefix.
	 *
	 * @var string
	 */
	const JOB_PROMPT_TRANSIENT_PREFIX = 'alorbach_image_job_prompts_';

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
	 * Default age in seconds after which an in-progress job is treated as stalled.
	 *
	 * @var int
	 */
	const JOB_STALLED_SECONDS = 180;

	/**
	 * Cron hook used to purge expired intermediate assets.
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'alorbach_cleanup_image_job_assets';

	/**
	 * Attachment meta key for the owning job id.
	 *
	 * @var string
	 */
	const ATTACHMENT_META_JOB_ID = '_alorbach_image_job_id';

	/**
	 * Attachment meta key for the stored asset role.
	 *
	 * @var string
	 */
	const ATTACHMENT_META_ROLE = '_alorbach_image_job_asset_role';

	/**
	 * Attachment meta key for the asset owner.
	 *
	 * @var string
	 */
	const ATTACHMENT_META_USER_ID = '_alorbach_image_job_user_id';

	/**
	 * Attachment meta key for the stored source hash.
	 *
	 * @var string
	 */
	const ATTACHMENT_META_SOURCE_HASH = '_alorbach_image_job_source_hash';

	/**
	 * Attachment meta key for the asset creation timestamp.
	 *
	 * @var string
	 */
	const ATTACHMENT_META_CREATED_AT = '_alorbach_image_job_created_at';

	/**
	 * Preview/reference retention window in seconds.
	 *
	 * @var int
	 */
	const PREVIEW_RETENTION_SECONDS = DAY_IN_SECONDS;

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
		$reference_images = self::normalize_reference_images(
			isset( $args['reference_images'] ) && is_array( $args['reference_images'] ) ? $args['reference_images'] : array()
		);

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

		$job_id               = wp_generate_uuid4();
		$token                = wp_generate_password( 20, false, false );
		$image_capabilities   = empty( $reference_images ) ? API_Client::get_image_job_capabilities( $model ) : array(
			'async_jobs'        => false,
			'provider_progress' => false,
			'preview_images'    => false,
		);
		$supports_previews    = ! empty( $image_capabilities['preview_images'] );
		$provider_progress    = ! empty( $image_capabilities['provider_progress'] );
		$progress_mode        = $provider_progress ? 'provider' : 'estimated';
		$job     = array(
			'job_id'             => $job_id,
			'user_id'            => $user_id,
			'status'             => 'queued',
			'progress_stage'     => 'queued',
			'progress_percent'   => 10,
			'progress_mode'      => $progress_mode,
			'supports_previews'  => $supports_previews,
			'provider_progress'  => $provider_progress,
			'size'               => $size,
			'n'                  => $n,
			'quality'            => $quality,
			'model'              => $model,
			'preview_count'      => 0,
			'final_count'        => 0,
			'reference_count'    => count( $reference_images ),
			'cost_uc'            => $cost,
			'cost_credits'       => User_Display::uc_to_credits( $cost ),
			'cost_usd'           => User_Display::uc_to_usd( $cost ),
			'api_cost_uc'        => $api_cost,
			'request_signature'  => hash( 'sha256', wp_json_encode( array( $user_id, 'image_job', $prompt, $size, $model, $quality, $n, md5( wp_json_encode( $reference_images ) ), time() ) ) ),
			'deduction_applied'  => false,
			'error'              => '',
			'dispatch_token'     => $token,
			'dispatched_at'      => 0,
			'created_at'         => time(),
			'updated_at'         => time(),
		);

		self::save_full_job(
			$job,
			array(
				'preview_images'   => array(),
				'final_images'     => array(),
				'reference_images' => $reference_images,
			),
			array(
				'prompt'          => $prompt,
				'original_prompt' => $original_prompt,
			)
		);
		self::dispatch_job( $job );

		return self::public_job_payload( self::hydrate_public_job( $job ), false );
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
		self::save_job( self::strip_heavy_job_fields( $job ) );

		$body = wp_json_encode(
			array(
				'job_id' => $job['job_id'],
				'token'  => $job['dispatch_token'],
			)
		);

		foreach ( self::get_internal_process_urls() as $url ) {
			if ( self::send_fire_and_forget_request( $url, $body ) ) {
				self::log_job_diagnostic(
					'dispatch_job_socket_success',
					array(
						'job_id' => (string) $job['job_id'],
						'url'    => (string) $url,
					)
				);
				return;
			}

			self::log_job_diagnostic(
				'dispatch_job_socket_failed',
				array(
					'job_id' => (string) $job['job_id'],
					'url'    => (string) $url,
				)
			);
		}

		foreach ( self::get_internal_process_urls() as $url ) {
			$response = wp_remote_post(
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

			if ( ! is_wp_error( $response ) ) {
				self::log_job_diagnostic(
					'dispatch_job_http_success',
					array(
						'job_id' => (string) $job['job_id'],
						'url'    => (string) $url,
					)
				);
				return;
			}

			self::log_job_diagnostic(
				'dispatch_job_http_failed',
				array(
					'job_id'  => (string) $job['job_id'],
					'url'     => (string) $url,
					'message' => $response->get_error_message(),
				)
			);
		}
	}

	/**
	 * Build candidate internal loopback URLs for processing jobs.
	 *
	 * Web requests can usually use 127.0.0.1, but CLI runs inside wp-env hit a
	 * separate container where the web server is instead reachable as
	 * "wordpress". We try a small ordered candidate set and let the transport
	 * pick the first reachable target.
	 *
	 * @return string[]
	 */
	private static function get_internal_process_urls() {
		$scheme = is_ssl() ? 'https' : 'http';
		$path   = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path   = is_string( $path ) ? rtrim( $path, '/' ) : '';
		$route  = $path . '/index.php?rest_route=/alorbach/v1/internal/images/jobs/process';
		$hosts  = array( '127.0.0.1' );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			array_unshift( $hosts, 'wordpress' );
		}

		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$site_host = is_string( $site_host ) ? trim( $site_host ) : '';
		if ( '' !== $site_host ) {
			$hosts[] = $site_host;
		}

		$hosts = apply_filters( 'alorbach_image_job_internal_hosts', $hosts );
		if ( ! is_array( $hosts ) ) {
			$hosts = array( '127.0.0.1' );
		}

		$hosts = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $host ) => is_string( $host ) ? trim( $host ) : '',
						$hosts
					)
				)
			)
		);

		return array_map(
			static fn( $host ) => $scheme . '://' . $host . $route,
			$hosts
		);
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
		$job = self::get_full_job( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'job_not_found', __( 'Image job not found.', 'alorbach-ai-gateway' ), array( 'status' => 404 ) );
		}
		if ( empty( $job['dispatch_token'] ) || ! hash_equals( (string) $job['dispatch_token'], (string) $token ) ) {
			return new \WP_Error( 'invalid_job_token', __( 'Invalid image job token.', 'alorbach-ai-gateway' ), array( 'status' => 403 ) );
		}
		if ( in_array( $job['status'], array( 'completed', 'failed', 'in_progress' ), true ) ) {
			return self::public_job_payload( $job );
		}

		$image_capabilities       = empty( $job['reference_images'] ) ? API_Client::get_image_job_capabilities( (string) $job['model'] ) : array(
			'async_jobs'        => false,
			'provider_progress' => false,
			'preview_images'    => false,
		);
		$job['status']            = 'in_progress';
		$job['progress_stage']    = 'drafting';
		$job['progress_percent']  = 35;
		$job['supports_previews'] = ! empty( $image_capabilities['preview_images'] );
		$job['provider_progress'] = ! empty( $image_capabilities['provider_progress'] );
		$job['progress_mode']     = ! empty( $job['provider_progress'] ) ? 'provider' : 'estimated';
		$job['updated_at']        = time();
		self::save_full_job( $job );
		if ( is_callable( $on_update ) ) {
			call_user_func( $on_update, self::public_job_payload( $job ) );
		}

		$provider_reference_images = self::build_provider_reference_images(
			isset( $job['reference_images'] ) && is_array( $job['reference_images'] ) ? $job['reference_images'] : array()
		);
		if ( is_wp_error( $provider_reference_images ) ) {
			$job['status']           = 'failed';
			$job['progress_stage']   = 'failed';
			$job['progress_percent'] = 0;
			$job['error']            = $provider_reference_images->get_error_message();
			$job['updated_at']       = time();
			self::save_full_job( $job );
			if ( is_callable( $on_update ) ) {
				call_user_func( $on_update, self::public_job_payload( $job ) );
			}
			return $provider_reference_images;
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
						$job['preview_images']   = self::append_asset_items(
							$job['preview_images'],
							$event['images'],
							(string) $job['job_id'],
							(int) $job['user_id'],
							'preview'
						);
						$preview_count           = count( $job['preview_images'] );
						$job['status']           = 'in_progress';
						$job['progress_stage']   = $preview_count >= 3 ? 'finalizing' : ( $preview_count === 2 ? 'refining' : 'drafting' );
						$job['progress_percent'] = $preview_count >= 3 ? 90 : ( $preview_count === 2 ? 75 : 55 );
						$job['updated_at']       = time();
						self::save_full_job( $job );
						if ( is_callable( $on_update ) ) {
							call_user_func( $on_update, self::public_job_payload( $job ) );
						}
						return;
					}

					if ( $event['type'] === 'final_image' ) {
						$job['final_images']     = self::append_asset_items(
							$job['final_images'],
							$event['images'],
							(string) $job['job_id'],
							(int) $job['user_id'],
							'final'
						);
						$job['progress_stage']   = 'finalizing';
						$job['progress_percent'] = 95;
						$job['updated_at']       = time();
						self::save_full_job( $job );
						if ( is_callable( $on_update ) ) {
							call_user_func( $on_update, self::public_job_payload( $job ) );
						}
					}
				},
				$provider_reference_images
			);
		} else {
			$response = API_Client::images(
				$job['prompt'],
				$job['size'],
				$job['n'],
				$job['model'],
				$job['quality'],
				null,
				$provider_reference_images
			);
		}

		if ( is_wp_error( $response ) ) {
			$job['status']           = 'failed';
			$job['progress_stage']   = 'failed';
			$job['progress_percent'] = 0;
			$job['error']            = $response->get_error_message();
			$job['updated_at']       = time();
			self::save_full_job( $job );
			if ( is_callable( $on_update ) ) {
				call_user_func( $on_update, self::public_job_payload( $job ) );
			}
			return $response;
		}

		$job['status']         = 'completed';
		$job['progress_stage'] = 'completed';
		$job['progress_percent'] = 100;
		$job['final_images']   = self::append_asset_items(
			array(),
			isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array(),
			(string) $job['job_id'],
			(int) $job['user_id'],
			'final'
		);
		$job['preview_images'] = isset( $response['preview_images'] ) && is_array( $response['preview_images'] )
			? self::append_asset_items(
				$job['preview_images'],
				$response['preview_images'],
				(string) $job['job_id'],
				(int) $job['user_id'],
				'preview'
			)
			: $job['preview_images'];
		$job['updated_at']     = time();

		if ( empty( $job['final_images'] ) ) {
			$job['status']           = 'failed';
			$job['progress_stage']   = 'failed';
			$job['progress_percent'] = 0;
			$job['error']            = __( 'Image generation returned no images.', 'alorbach-ai-gateway' );
			self::save_full_job( $job );
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

		self::save_full_job( $job );
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
		$job = self::get_full_job( $job_id );
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
	public static function get_job_for_admin( $job_id, $include_images = false ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return null;
		}

		return self::hydrate_admin_job( $job, $include_images );
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
			$valid_index[ $job_id ] = (int) ( $job['updated_at'] ?? $updated_at );
			if ( count( $jobs ) < $limit ) {
				$jobs[] = $job;
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
	 * Get queue statistics across the indexed job set.
	 *
	 * @return array
	 */
	public static function get_queue_stats() {
		$stats = array(
			'total'            => 0,
			'recent_total'     => 0,
			'queued'           => 0,
			'in_progress'      => 0,
			'completed'        => 0,
			'failed'           => 0,
			'stalled'          => 0,
			'expired'          => 0,
			'stale_or_expired' => 0,
		);

		foreach ( self::get_all_job_ids() as $job_id ) {
			$stats['total']++;
			$stats['recent_total']++;

			$job = self::load_job_record( $job_id, false );
			if ( ! is_array( $job ) ) {
				$stats['expired']++;
				$stats['stale_or_expired']++;
				continue;
			}

			$status = self::get_job_status( $job );
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ]++;
			}

			$is_stalled = self::is_job_stalled_for_cleanup( $job );
			$is_expired = self::is_job_expired_for_cleanup( $job_id, $job );
			if ( $is_stalled ) {
				$stats['stalled']++;
			}
			if ( $is_expired ) {
				$stats['expired']++;
			}
			if ( $is_stalled || $is_expired ) {
				$stats['stale_or_expired']++;
			}
		}

		return $stats;
	}

	/**
	 * Get all job ids currently present in the job index.
	 *
	 * @return array
	 */
	public static function get_all_job_ids() {
		$index = self::get_job_index();
		arsort( $index );

		return array_map( 'strval', array_keys( $index ) );
	}

	/**
	 * Get job ids matching one or more statuses.
	 *
	 * @param array $statuses Status values to match.
	 * @return array
	 */
	public static function get_job_ids_by_status( $statuses ) {
		$statuses = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						is_array( $statuses ) ? $statuses : array()
					)
				)
			)
		);
		if ( empty( $statuses ) ) {
			return array();
		}

		$matched = array();
		foreach ( self::get_all_job_ids() as $job_id ) {
			$job = self::load_job_record( $job_id, false );
			if ( self::job_matches_requested_statuses( $job_id, $job, $statuses ) ) {
				$matched[] = $job_id;
			}
		}

		return $matched;
	}

	/**
	 * Delete one job and any owned cleanup assets.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $options Cleanup options.
	 * @return array
	 */
	public static function delete_job( $job_id, $options = array() ) {
		$job_id = (string) $job_id;
		$options = wp_parse_args(
			$options,
			array(
				'delete_preview_assets' => true,
				'delete_reference_assets' => true,
				'delete_final_assets'   => false,
				'force_final_assets'    => false,
				'prune_index'           => true,
			)
		);

		$job = self::load_job_record( $job_id, false );
		$attachment_result = self::delete_job_attachments( $job_id, $options, $job );
		delete_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
		delete_transient( self::JOB_ASSET_TRANSIENT_PREFIX . $job_id );
		delete_transient( self::JOB_PROMPT_TRANSIENT_PREFIX . $job_id );
		$index_removed = self::remove_job_from_index( $job_id );

		$result = array(
			'job_id'             => $job_id,
			'job_found'          => is_array( $job ),
			'job_status'         => is_array( $job ) ? self::get_job_status( $job ) : 'missing',
			'attachments_removed' => $attachment_result['attachments_removed'],
			'attachments_skipped' => $attachment_result['attachments_skipped'],
			'attachment_count'   => count( $attachment_result['attachments_removed'] ),
			'index_removed'      => $index_removed,
			'deleted'            => true,
		);

		self::log_job_diagnostic(
			'cleanup_delete_job',
			array(
				'job_id'            => $job_id,
				'job_found'         => is_array( $job ),
				'job_status'        => $result['job_status'],
				'attachments_removed' => $result['attachments_removed'],
				'attachments_skipped' => $result['attachments_skipped'],
				'index_removed'     => $index_removed,
				'operator_user_id'  => get_current_user_id(),
				'options'           => array(
					'delete_preview_assets' => (bool) $options['delete_preview_assets'],
					'delete_reference_assets' => (bool) $options['delete_reference_assets'],
					'delete_final_assets'   => (bool) $options['delete_final_assets'],
					'force_final_assets'    => (bool) $options['force_final_assets'],
				),
			)
		);

		return $result;
	}

	/**
	 * Delete jobs matching the provided statuses.
	 *
	 * @param array $statuses Status values.
	 * @param array $options Cleanup options.
	 * @return array
	 */
	public static function delete_jobs_by_status( $statuses, $options = array() ) {
		$job_ids = self::get_job_ids_by_status( $statuses );
		$jobs = array();
		$attachments_removed = array();

		foreach ( $job_ids as $job_id ) {
			$result = self::delete_job( $job_id, $options );
			$jobs[] = $result;
			$attachments_removed = array_merge( $attachments_removed, $result['attachments_removed'] );
		}

		return array(
			'job_ids'             => $job_ids,
			'job_count'           => count( $job_ids ),
			'jobs'                => $jobs,
			'attachments_removed' => array_values( array_unique( array_map( 'absint', $attachments_removed ) ) ),
			'attachment_count'    => count( array_unique( array_map( 'absint', $attachments_removed ) ) ),
		);
	}

	/**
	 * Delete expired jobs.
	 *
	 * @param array $options Cleanup options.
	 * @return array
	 */
	public static function delete_expired_jobs( $options = array() ) {
		return self::delete_jobs_by_status( array( 'expired' ), $options );
	}

	/**
	 * Delete stalled jobs.
	 *
	 * @param array $options Cleanup options.
	 * @return array
	 */
	public static function delete_stalled_jobs( $options = array() ) {
		return self::delete_jobs_by_status( array( 'stalled' ), $options );
	}

	/**
	 * Delete jobs in a completed state.
	 *
	 * @param array $options Cleanup options.
	 * @return array
	 */
	public static function delete_completed_jobs( $options = array() ) {
		return self::delete_jobs_by_status( array( 'completed' ), $options );
	}

	/**
	 * Delete all indexed jobs.
	 *
	 * @param array $options Cleanup options.
	 * @return array
	 */
	public static function delete_all_jobs( $options = array() ) {
		return self::delete_jobs_by_status( self::get_all_job_statuses_for_cleanup(), $options );
	}

	/**
	 * Prune missing jobs from the index.
	 *
	 * @return int
	 */
	public static function prune_job_index() {
		$index = self::get_job_index();
		$pruned = 0;
		$valid_index = array();

		foreach ( $index as $job_id => $updated_at ) {
			$job = self::load_job_record( (string) $job_id, false );
			if ( ! is_array( $job ) && ! self::job_exists_in_attachments( (string) $job_id ) ) {
				$pruned++;
				continue;
			}

			$valid_index[ (string) $job_id ] = (int) $updated_at;
		}

		self::save_job_index( $valid_index );

		return $pruned;
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
		$job_status   = self::get_job_status( $job );
		$age_seconds  = self::get_job_age_seconds( $job );

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
			'provider_progress'   => ! empty( $job['provider_progress'] ),
			'model'               => (string) ( $job['model'] ?? '' ),
			'size'                => (string) ( $job['size'] ?? '' ),
			'quality'             => (string) ( $job['quality'] ?? '' ),
			'n'                   => (int) ( $job['n'] ?? 1 ),
			'prompt'              => (string) ( $job['prompt'] ?? '' ),
			'original_prompt'     => (string) ( $job['original_prompt'] ?? ( $job['prompt'] ?? '' ) ),
			'preview_images'      => isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? $job['preview_images'] : array(),
			'final_images'        => isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? $job['final_images'] : array(),
			'reference_images'    => isset( $job['reference_images'] ) && is_array( $job['reference_images'] ) ? $job['reference_images'] : array(),
			'preview_count'       => (int) ( $job['preview_count'] ?? 0 ),
			'final_count'         => (int) ( $job['final_count'] ?? 0 ),
			'reference_count'     => (int) ( $job['reference_count'] ?? 0 ),
			'images_included'     => ! empty( $job['images_included'] ),
			'images_error'        => (string) ( $job['images_error'] ?? '' ),
			'cost_uc'             => (int) ( $job['cost_uc'] ?? 0 ),
			'cost_credits'        => $cost_credits,
			'cost_credits_label'  => number_format_i18n( $cost_credits, 2 ) . ' Credits ($' . number_format_i18n( $cost_usd, 2 ) . ')',
			'created_at'          => $created_at,
			'created_at_label'    => $created_at ? wp_date( 'Y-m-d H:i:s', $created_at ) : '',
			'updated_at'          => $updated_at,
			'updated_at_label'    => $updated_at ? wp_date( 'Y-m-d H:i:s', $updated_at ) : '',
			'dispatched_at'       => isset( $job['dispatched_at'] ) ? (int) $job['dispatched_at'] : 0,
			'runtime_seconds'     => $runtime,
			'age_seconds'         => $age_seconds,
			'is_stalled'          => self::is_job_stalled_for_cleanup( $job ),
			'is_expired'          => self::is_job_expired_for_cleanup( (string) ( $job['job_id'] ?? '' ), $job ),
			'job_state'           => $job_status,
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
		$job_status   = self::get_job_status( $job );
		$age_seconds  = self::get_job_age_seconds( $job );

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
			'provider_progress'  => ! empty( $job['provider_progress'] ),
			'model'              => (string) ( $job['model'] ?? '' ),
			'size'               => (string) ( $job['size'] ?? '' ),
			'quality'            => (string) ( $job['quality'] ?? '' ),
			'n'                  => (int) ( $job['n'] ?? 1 ),
			'preview_count'      => (int) ( $job['preview_count'] ?? 0 ),
			'final_count'        => (int) ( $job['final_count'] ?? 0 ),
			'cost_uc'            => (int) ( $job['cost_uc'] ?? 0 ),
			'cost_credits'       => $cost_credits,
			'cost_credits_label' => number_format_i18n( $cost_credits, 2 ) . ' Credits ($' . number_format_i18n( $cost_usd, 2 ) . ')',
			'created_at'         => $created_at,
			'created_at_label'   => $created_at ? wp_date( 'Y-m-d H:i:s', $created_at ) : '',
			'updated_at'         => $updated_at,
			'updated_at_label'   => $updated_at ? wp_date( 'Y-m-d H:i:s', $updated_at ) : '',
			'dispatched_at'      => isset( $job['dispatched_at'] ) ? (int) $job['dispatched_at'] : 0,
			'runtime_seconds'    => $runtime,
			'age_seconds'        => $age_seconds,
			'is_stalled'         => self::is_job_stalled_for_cleanup( $job ),
			'is_expired'         => self::is_job_expired_for_cleanup( (string) ( $job['job_id'] ?? '' ), $job ),
			'job_state'          => $job_status,
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
		$job = self::hydrate_public_job( $job );

		$payload = array(
			'job_id'             => $job['job_id'],
			'status'             => $job['status'],
			'progress_stage'     => $job['progress_stage'],
			'progress_percent'   => (int) $job['progress_percent'],
			'progress_mode'      => $job['progress_mode'],
			'supports_previews'  => ! empty( $job['supports_previews'] ),
			'provider_progress'  => ! empty( $job['provider_progress'] ),
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
		return self::load_job_record( $job_id, true );
	}

	/**
	 * Load a job snapshot without auto-mutating stalled jobs unless requested.
	 *
	 * @param string $job_id        Job ID.
	 * @param bool   $allow_mutation Whether stalled jobs should be auto-failed.
	 * @return array|null
	 */
	private static function load_job_record( $job_id, $allow_mutation = true ) {
		self::invalidate_transient_option_caches( self::JOB_TRANSIENT_PREFIX . $job_id );
		$job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $job ) ) {
			return null;
		}

		if ( self::job_has_legacy_heavy_fields( $job ) ) {
			$job = self::migrate_legacy_job_storage( $job );
		}

		if ( $allow_mutation && self::is_job_stalled( $job ) ) {
			$job['status']           = 'failed';
			$job['progress_stage']   = 'failed';
			$job['progress_percent'] = 0;
			$job['error']            = __( 'Image generation timed out before a final image was received. Please retry the request.', 'alorbach-ai-gateway' );
			$job['updated_at']       = time();
			self::save_job( $job );
		}

		return $job;
	}

	/**
	 * Whether a job has been left in progress long enough to treat it as stalled.
	 *
	 * @param array $job Raw job data.
	 * @return bool
	 */
	private static function is_job_stalled( $job ) {
		if ( ! is_array( $job ) || ( $job['status'] ?? '' ) !== 'in_progress' ) {
			return false;
		}

		if ( (int) ( $job['final_count'] ?? 0 ) > 0 ) {
			return false;
		}

		$updated_at = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
		if ( $updated_at <= 0 ) {
			return false;
		}

		$timeout = max(
			30,
			(int) apply_filters( 'alorbach_image_job_stalled_seconds', self::JOB_STALLED_SECONDS )
		);

		return ( time() - $updated_at ) >= $timeout;
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
	 * Save a full job by splitting compact state, image assets, and prompts.
	 *
	 * @param array      $job     Job state.
	 * @param array|null $assets  Optional explicit assets override.
	 * @param array|null $prompts Optional explicit prompt override.
	 * @return void
	 */
	private static function save_full_job( $job, $assets = null, $prompts = null ) {
		$job_id = (string) ( $job['job_id'] ?? '' );
		if ( '' === $job_id ) {
			return;
		}

		$assets  = is_array( $assets ) ? $assets : self::extract_asset_payload( $job );
		$assets  = self::prepare_assets_for_storage( $job_id, (int) ( $job['user_id'] ?? 0 ), $assets );
		$prompts = is_array( $prompts ) ? $prompts : self::extract_prompt_payload( $job );

		$job['preview_count']   = count( isset( $assets['preview_images'] ) && is_array( $assets['preview_images'] ) ? $assets['preview_images'] : array() );
		$job['final_count']     = count( isset( $assets['final_images'] ) && is_array( $assets['final_images'] ) ? $assets['final_images'] : array() );
		$job['reference_count'] = count( isset( $assets['reference_images'] ) && is_array( $assets['reference_images'] ) ? $assets['reference_images'] : array() );

		self::save_job( self::strip_heavy_job_fields( $job ) );
		self::save_job_assets( $job_id, $assets );
		self::save_job_prompts( $job_id, $prompts );
		self::log_job_diagnostic(
			'saved_compact_job',
			array(
				'job_id'        => $job_id,
				'compact_bytes' => strlen( wp_json_encode( self::strip_heavy_job_fields( $job ) ) ),
				'asset_bytes'   => strlen( wp_json_encode( $assets ) ),
				'prompt_bytes'  => strlen( wp_json_encode( $prompts ) ),
			)
		);
	}

	/**
	 * Get a full job with prompts and image assets.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	private static function get_full_job( $job_id ) {
		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return null;
		}

		return array_merge(
			$job,
			self::get_stored_job_assets( $job_id ),
			self::get_job_prompts( $job_id )
		);
	}

	/**
	 * Build an admin-focused job payload, optionally loading heavy image data.
	 *
	 * @param array $job            Compact job.
	 * @param bool  $include_images Whether to include heavy images.
	 * @return array
	 */
	private static function hydrate_admin_job( $job, $include_images ) {
		$job = array_merge( $job, self::get_job_prompts( (string) $job['job_id'] ) );
		$job['preview_images']   = array();
		$job['final_images']     = array();
		$job['reference_images'] = array();
		$job['images_included']  = false;
		$job['images_error']     = '';

		if ( $include_images ) {
			$job = array_merge( $job, self::get_job_assets_for_payload( (string) $job['job_id'] ) );
			$job['images_included'] = true;
		}

		return $job;
	}

	/**
	 * Ensure public job payloads still contain live image arrays.
	 *
	 * @param array $job Compact or full job.
	 * @return array
	 */
	private static function hydrate_public_job( $job ) {
		if ( isset( $job['preview_images'], $job['final_images'] ) ) {
			$job['preview_images']   = self::prepare_asset_refs_for_payload( (string) ( $job['job_id'] ?? '' ), isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? $job['preview_images'] : array() );
			$job['final_images']     = self::prepare_asset_refs_for_payload( (string) ( $job['job_id'] ?? '' ), isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? $job['final_images'] : array() );
			$job['reference_images'] = self::prepare_asset_refs_for_payload( (string) ( $job['job_id'] ?? '' ), isset( $job['reference_images'] ) && is_array( $job['reference_images'] ) ? $job['reference_images'] : array() );
			return $job;
		}

		return array_merge( $job, self::get_job_assets_for_payload( (string) $job['job_id'] ) );
	}

	/**
	 * Determine whether a job still uses legacy all-in-one storage.
	 *
	 * @param array $job Job data.
	 * @return bool
	 */
	private static function job_has_legacy_heavy_fields( $job ) {
		return isset( $job['preview_images'] ) || isset( $job['final_images'] ) || isset( $job['reference_images'] ) || isset( $job['prompt'] ) || isset( $job['original_prompt'] );
	}

	/**
	 * Migrate a legacy raw job into compact + separated storage.
	 *
	 * @param array $job Legacy job data.
	 * @return array
	 */
	private static function migrate_legacy_job_storage( $job ) {
		self::save_full_job( $job );
		self::log_job_diagnostic(
			'migrated_legacy_job_storage',
			array(
				'job_id' => (string) ( $job['job_id'] ?? '' ),
			)
		);

		return self::strip_heavy_job_fields( $job );
	}

	/**
	 * Remove heavy fields from a job before saving compact metadata.
	 *
	 * @param array $job Job data.
	 * @return array
	 */
	private static function strip_heavy_job_fields( $job ) {
		unset( $job['preview_images'], $job['final_images'], $job['reference_images'], $job['prompt'], $job['original_prompt'], $job['images_included'], $job['images_error'] );
		return $job;
	}

	/**
	 * Extract image assets from a full job.
	 *
	 * @param array $job Job data.
	 * @return array
	 */
	private static function extract_asset_payload( $job ) {
		return array(
			'preview_images'   => isset( $job['preview_images'] ) && is_array( $job['preview_images'] ) ? array_values( $job['preview_images'] ) : array(),
			'final_images'     => isset( $job['final_images'] ) && is_array( $job['final_images'] ) ? array_values( $job['final_images'] ) : array(),
			'reference_images' => isset( $job['reference_images'] ) && is_array( $job['reference_images'] ) ? array_values( $job['reference_images'] ) : array(),
		);
	}

	/**
	 * Extract prompt payload from a full job.
	 *
	 * @param array $job Job data.
	 * @return array
	 */
	private static function extract_prompt_payload( $job ) {
		return array(
			'prompt'          => (string) ( $job['prompt'] ?? '' ),
			'original_prompt' => (string) ( $job['original_prompt'] ?? ( $job['prompt'] ?? '' ) ),
		);
	}

	/**
	 * Save image assets for one job.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $assets Asset payload.
	 * @return void
	 */
	private static function save_job_assets( $job_id, $assets ) {
		set_transient(
			self::JOB_ASSET_TRANSIENT_PREFIX . $job_id,
			array(
				'preview_images'   => isset( $assets['preview_images'] ) && is_array( $assets['preview_images'] ) ? array_values( $assets['preview_images'] ) : array(),
				'final_images'     => isset( $assets['final_images'] ) && is_array( $assets['final_images'] ) ? array_values( $assets['final_images'] ) : array(),
				'reference_images' => isset( $assets['reference_images'] ) && is_array( $assets['reference_images'] ) ? array_values( $assets['reference_images'] ) : array(),
			),
			self::JOB_TTL
		);
	}

	/**
	 * Load image assets for one job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	private static function get_stored_job_assets( $job_id ) {
		self::invalidate_transient_option_caches( self::JOB_ASSET_TRANSIENT_PREFIX . $job_id );
		$assets = get_transient( self::JOB_ASSET_TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $assets ) ) {
			return array(
				'preview_images'   => array(),
				'final_images'     => array(),
				'reference_images' => array(),
			);
		}

		if ( self::assets_need_migration( $assets ) ) {
			$job         = self::get_job( $job_id );
			$user_id     = (int) ( $job['user_id'] ?? 0 );
			$assets      = self::prepare_assets_for_storage( $job_id, $user_id, $assets );
			self::save_job_assets( $job_id, $assets );
			self::log_job_diagnostic(
				'migrated_legacy_job_assets',
				array(
					'job_id' => $job_id,
				)
			);
		}

		$assets = self::prune_missing_asset_refs( $job_id, $assets );

		return array(
			'preview_images'   => isset( $assets['preview_images'] ) && is_array( $assets['preview_images'] ) ? array_values( $assets['preview_images'] ) : array(),
			'final_images'     => isset( $assets['final_images'] ) && is_array( $assets['final_images'] ) ? array_values( $assets['final_images'] ) : array(),
			'reference_images' => isset( $assets['reference_images'] ) && is_array( $assets['reference_images'] ) ? array_values( $assets['reference_images'] ) : array(),
		);
	}

	/**
	 * Load payload-ready image assets for one job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	private static function get_job_assets_for_payload( $job_id ) {
		$assets = self::get_stored_job_assets( $job_id );

		return array(
			'preview_images'   => self::prepare_asset_refs_for_payload( $job_id, isset( $assets['preview_images'] ) && is_array( $assets['preview_images'] ) ? $assets['preview_images'] : array() ),
			'final_images'     => self::prepare_asset_refs_for_payload( $job_id, isset( $assets['final_images'] ) && is_array( $assets['final_images'] ) ? $assets['final_images'] : array() ),
			'reference_images' => self::prepare_asset_refs_for_payload( $job_id, isset( $assets['reference_images'] ) && is_array( $assets['reference_images'] ) ? $assets['reference_images'] : array() ),
		);
	}

	/**
	 * Save prompt fields for one job.
	 *
	 * @param string $job_id  Job ID.
	 * @param array  $prompts Prompt payload.
	 * @return void
	 */
	private static function save_job_prompts( $job_id, $prompts ) {
		set_transient(
			self::JOB_PROMPT_TRANSIENT_PREFIX . $job_id,
			array(
				'prompt'          => (string) ( $prompts['prompt'] ?? '' ),
				'original_prompt' => (string) ( $prompts['original_prompt'] ?? ( $prompts['prompt'] ?? '' ) ),
			),
			self::JOB_TTL
		);
	}

	/**
	 * Load prompt fields for one job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	private static function get_job_prompts( $job_id ) {
		self::invalidate_transient_option_caches( self::JOB_PROMPT_TRANSIENT_PREFIX . $job_id );
		$prompts = get_transient( self::JOB_PROMPT_TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $prompts ) ) {
			return array(
				'prompt'          => '',
				'original_prompt' => '',
			);
		}

		return array(
			'prompt'          => (string) ( $prompts['prompt'] ?? '' ),
			'original_prompt' => (string) ( $prompts['original_prompt'] ?? ( $prompts['prompt'] ?? '' ) ),
		);
	}

	/**
	 * Normalize reference images before saving them in transient storage.
	 *
	 * @param array $reference_images Raw reference images.
	 * @return array
	 */
	private static function normalize_reference_images( $reference_images ) {
		$normalized = array();

		foreach ( $reference_images as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized_item = array();
			if ( isset( $item['url'] ) && is_string( $item['url'] ) && '' !== $item['url'] ) {
				$normalized_item['url'] = esc_url_raw( $item['url'] );
			}
			if ( isset( $item['b64_json'] ) && is_string( $item['b64_json'] ) && '' !== $item['b64_json'] ) {
				$normalized_item['b64_json'] = preg_replace( '/\s+/', '', $item['b64_json'] );
			}
			if ( isset( $item['mime_type'] ) && is_string( $item['mime_type'] ) && '' !== $item['mime_type'] ) {
				$normalized_item['mime_type'] = sanitize_text_field( $item['mime_type'] );
			}
			if ( ! empty( $normalized_item ) ) {
				$normalized[] = $normalized_item;
			}
		}

		return $normalized;
	}

	/**
	 * Determine whether an asset payload still contains legacy raw image items.
	 *
	 * @param array $assets Stored asset payload.
	 * @return bool
	 */
	private static function assets_need_migration( $assets ) {
		foreach ( array( 'preview_images', 'final_images', 'reference_images' ) as $key ) {
			$items = isset( $assets[ $key ] ) && is_array( $assets[ $key ] ) ? $assets[ $key ] : array();
			foreach ( $items as $item ) {
				if ( ! self::is_asset_ref( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Convert raw image items or legacy payloads into compact stored attachment refs.
	 *
	 * @param string $job_id   Job ID.
	 * @param int    $user_id  Owner user ID.
	 * @param array  $assets   Asset payload.
	 * @return array
	 */
	private static function prepare_assets_for_storage( $job_id, $user_id, $assets ) {
		$prepared = array(
			'preview_images'   => array(),
			'final_images'     => array(),
			'reference_images' => array(),
		);

		foreach ( array( 'preview_images' => 'preview', 'final_images' => 'final', 'reference_images' => 'reference' ) as $key => $role ) {
			$prepared[ $key ] = self::prepare_asset_collection_for_storage(
				$job_id,
				$user_id,
				isset( $assets[ $key ] ) && is_array( $assets[ $key ] ) ? $assets[ $key ] : array(),
				$role
			);
		}

		return $prepared;
	}

	/**
	 * Convert a set of items into stored attachment refs for one role.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @param array  $items Items or refs.
	 * @param string $role Asset role.
	 * @return array
	 */
	private static function prepare_asset_collection_for_storage( $job_id, $user_id, $items, $role ) {
		$prepared = array();
		$seen     = array();

		foreach ( $items as $item ) {
			$ref = self::normalize_asset_ref_or_create_attachment( $job_id, $user_id, $item, $role );
			if ( empty( $ref ) || empty( $ref['attachment_id'] ) ) {
				continue;
			}

			$dedupe_key = ! empty( $ref['source_hash'] ) ? (string) $ref['source_hash'] : 'attachment:' . (int) $ref['attachment_id'];
			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}

			$seen[ $dedupe_key ] = true;
			$prepared[]          = $ref;
		}

		return array_values( $prepared );
	}

	/**
	 * Append provider image items to an existing set of stored refs.
	 *
	 * @param array  $existing Existing refs.
	 * @param array  $incoming Incoming raw image items.
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @param string $role Asset role.
	 * @return array
	 */
	private static function append_asset_items( $existing, $incoming, $job_id, $user_id, $role ) {
		return self::prepare_asset_collection_for_storage(
			$job_id,
			$user_id,
			array_merge(
				is_array( $existing ) ? $existing : array(),
				is_array( $incoming ) ? $incoming : array()
			),
			$role
		);
	}

	/**
	 * Determine whether one item is already a stored asset ref.
	 *
	 * @param mixed $item Asset item.
	 * @return bool
	 */
	private static function is_asset_ref( $item ) {
		return is_array( $item ) && ! empty( $item['attachment_id'] ) && is_numeric( $item['attachment_id'] );
	}

	/**
	 * Normalize a stored ref or create a new attachment for a raw image item.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @param mixed  $item Asset item or ref.
	 * @param string $role Asset role.
	 * @return array
	 */
	private static function normalize_asset_ref_or_create_attachment( $job_id, $user_id, $item, $role ) {
		if ( ! is_array( $item ) ) {
			return array();
		}

		if ( self::is_asset_ref( $item ) ) {
			return self::sanitize_asset_ref( $job_id, $item, $role );
		}

		$attachment_id = self::create_attachment_from_image_item( $job_id, $user_id, $item, $role );
		if ( $attachment_id <= 0 ) {
			return array();
		}

		return self::build_stored_asset_ref( $job_id, $attachment_id, $role, self::image_item_source_hash( $item ) );
	}

	/**
	 * Sanitize one stored asset ref and ensure role metadata is present.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $item Stored ref.
	 * @param string $role Asset role.
	 * @return array
	 */
	private static function sanitize_asset_ref( $job_id, $item, $role ) {
		$attachment_id = (int) ( $item['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array();
		}

		$stored_role = (string) get_post_meta( $attachment_id, self::ATTACHMENT_META_ROLE, true );
		if ( '' === $stored_role ) {
			update_post_meta( $attachment_id, self::ATTACHMENT_META_ROLE, $role );
			$stored_role = $role;
		}

		$created_at = (int) get_post_meta( $attachment_id, self::ATTACHMENT_META_CREATED_AT, true );
		if ( $created_at <= 0 ) {
			$created_at = time();
			update_post_meta( $attachment_id, self::ATTACHMENT_META_CREATED_AT, $created_at );
		}

		$source_hash = (string) get_post_meta( $attachment_id, self::ATTACHMENT_META_SOURCE_HASH, true );
		if ( '' === $source_hash && ! empty( $item['source_hash'] ) ) {
			$source_hash = sanitize_text_field( (string) $item['source_hash'] );
			update_post_meta( $attachment_id, self::ATTACHMENT_META_SOURCE_HASH, $source_hash );
		}

		if ( '' === (string) get_post_meta( $attachment_id, self::ATTACHMENT_META_JOB_ID, true ) ) {
			update_post_meta( $attachment_id, self::ATTACHMENT_META_JOB_ID, $job_id );
		}

		return self::build_stored_asset_ref( $job_id, $attachment_id, $stored_role, $source_hash, $created_at );
	}

	/**
	 * Build one stored asset ref from attachment metadata.
	 *
	 * @param string   $job_id Job ID.
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $role Asset role.
	 * @param string   $source_hash Optional source hash.
	 * @param int|null $created_at Optional creation timestamp.
	 * @return array
	 */
	private static function build_stored_asset_ref( $job_id, $attachment_id, $role, $source_hash = '', $created_at = null ) {
		$mime_type = get_post_mime_type( $attachment_id );
		$created   = null === $created_at ? (int) get_post_meta( $attachment_id, self::ATTACHMENT_META_CREATED_AT, true ) : (int) $created_at;
		if ( $created <= 0 ) {
			$created = time();
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'mime_type'     => $mime_type ? (string) $mime_type : 'image/png',
			'role'          => sanitize_text_field( $role ),
			'created_at'    => $created,
			'source_hash'   => (string) $source_hash,
		);
	}

	/**
	 * Convert stored refs into payload-safe items with signed same-origin URLs.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $items Stored refs.
	 * @return array
	 */
	private static function prepare_asset_refs_for_payload( $job_id, $items ) {
		$payload = array();

		foreach ( $items as $item ) {
			$ref = self::sanitize_asset_ref( $job_id, $item, isset( $item['role'] ) ? (string) $item['role'] : 'preview' );
			if ( empty( $ref['attachment_id'] ) ) {
				continue;
			}

			$payload[] = array(
				'attachment_id' => (int) $ref['attachment_id'],
				'url'           => self::get_asset_url( $job_id, (int) $ref['attachment_id'] ),
				'mime_type'     => (string) ( $ref['mime_type'] ?? 'image/png' ),
				'role'          => (string) ( $ref['role'] ?? 'preview' ),
				'created_at'    => (int) ( $ref['created_at'] ?? 0 ),
			);
		}

		return $payload;
	}

	/**
	 * Remove missing or expired asset refs and persist the compact result.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $assets Stored asset payload.
	 * @return array
	 */
	private static function prune_missing_asset_refs( $job_id, $assets ) {
		$changed = false;

		foreach ( array( 'preview_images' => 'preview', 'final_images' => 'final', 'reference_images' => 'reference' ) as $key => $role ) {
			$items   = isset( $assets[ $key ] ) && is_array( $assets[ $key ] ) ? $assets[ $key ] : array();
			$cleaned = array();

			foreach ( $items as $item ) {
				$ref = self::sanitize_asset_ref( $job_id, $item, $role );
				if ( empty( $ref['attachment_id'] ) ) {
					$changed = true;
					continue;
				}
				if ( self::is_attachment_expired( $ref ) ) {
					self::delete_attachment_if_exists( (int) $ref['attachment_id'] );
					$changed = true;
					continue;
				}
				$cleaned[] = $ref;
			}

			$assets[ $key ] = array_values( $cleaned );
		}

		if ( $changed ) {
			self::save_job_assets( $job_id, $assets );
			$job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
			if ( is_array( $job ) ) {
				$job['preview_count']   = count( $assets['preview_images'] );
				$job['final_count']     = count( $assets['final_images'] );
				$job['reference_count'] = count( $assets['reference_images'] );
				$job['updated_at']      = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : time();
				self::save_job( $job );
			}
		}

		return $assets;
	}

	/**
	 * Build provider-compatible reference images from stored refs.
	 *
	 * @param array $reference_refs Stored reference refs.
	 * @return array|\WP_Error
	 */
	private static function build_provider_reference_images( $reference_refs ) {
		$provider_items = array();

		foreach ( $reference_refs as $item ) {
			$attachment_id = (int) ( $item['attachment_id'] ?? 0 );
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return new \WP_Error( 'missing_reference_image', __( 'A reference image file is missing.', 'alorbach-ai-gateway' ) );
			}

			$binary = file_get_contents( $file );
			if ( false === $binary || '' === $binary ) {
				return new \WP_Error( 'invalid_reference_image', __( 'A reference image could not be read.', 'alorbach-ai-gateway' ) );
			}

			$provider_items[] = array(
				'b64_json'  => base64_encode( $binary ),
				'mime_type' => get_post_mime_type( $attachment_id ) ?: 'image/png',
			);
		}

		return $provider_items;
	}

	/**
	 * Create one intermediate attachment from a raw provider image item.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @param array  $item Raw image item.
	 * @param string $role Asset role.
	 * @return int
	 */
	private static function create_attachment_from_image_item( $job_id, $user_id, $item, $role ) {
		$binary    = self::image_item_binary( $item );
		$mime_type = self::image_item_mime_type( $item );
		if ( '' === $binary ) {
			return 0;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$extension = self::mime_type_to_extension( $mime_type );
		$filename  = sprintf( 'alorbach-%s-%s-%s.%s', sanitize_key( $role ), sanitize_key( $job_id ), wp_generate_password( 8, false, false ), $extension );
		$upload    = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			self::log_job_diagnostic(
				'asset_upload_failed',
				array(
					'job_id' => $job_id,
					'role'   => $role,
					'error'  => (string) $upload['error'],
				)
			);
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime_type,
				'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_status'    => 'private',
				'post_author'    => max( 0, (int) $user_id ),
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) || $attachment_id <= 0 ) {
			return 0;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, self::ATTACHMENT_META_JOB_ID, $job_id );
		update_post_meta( $attachment_id, self::ATTACHMENT_META_ROLE, $role );
		update_post_meta( $attachment_id, self::ATTACHMENT_META_USER_ID, max( 0, (int) $user_id ) );
		update_post_meta( $attachment_id, self::ATTACHMENT_META_CREATED_AT, time() );
		update_post_meta( $attachment_id, self::ATTACHMENT_META_SOURCE_HASH, self::image_item_source_hash( $item ) );

		return (int) $attachment_id;
	}

	/**
	 * Get raw binary for a provider image item.
	 *
	 * @param array $item Image item.
	 * @return string
	 */
	private static function image_item_binary( $item ) {
		if ( ! is_array( $item ) ) {
			return '';
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! empty( $item['b64_json'] ) && is_string( $item['b64_json'] ) ) {
			$binary = base64_decode( preg_replace( '/\s+/', '', $item['b64_json'] ), true );
			return false === $binary ? '' : $binary;
		}

		if ( ! empty( $item['url'] ) && is_string( $item['url'] ) ) {
			$temp_file = download_url( esc_url_raw( $item['url'] ) );
			if ( is_wp_error( $temp_file ) ) {
				return '';
			}

			$binary = file_get_contents( $temp_file );
			@unlink( $temp_file );

			return false === $binary ? '' : $binary;
		}

		return '';
	}

	/**
	 * Determine mime type for a provider image item.
	 *
	 * @param array $item Image item.
	 * @return string
	 */
	private static function image_item_mime_type( $item ) {
		if ( is_array( $item ) && ! empty( $item['mime_type'] ) && is_string( $item['mime_type'] ) ) {
			return sanitize_text_field( $item['mime_type'] );
		}

		return 'image/png';
	}

	/**
	 * Create a stable dedupe hash for one raw image item.
	 *
	 * @param array $item Image item.
	 * @return string
	 */
	private static function image_item_source_hash( $item ) {
		if ( ! is_array( $item ) ) {
			return '';
		}

		if ( ! empty( $item['b64_json'] ) && is_string( $item['b64_json'] ) ) {
			return hash( 'sha256', preg_replace( '/\s+/', '', $item['b64_json'] ) );
		}

		if ( ! empty( $item['url'] ) && is_string( $item['url'] ) ) {
			return hash( 'sha256', esc_url_raw( $item['url'] ) );
		}

		return '';
	}

	/**
	 * Convert mime type into a safe file extension.
	 *
	 * @param string $mime_type Mime type.
	 * @return string
	 */
	private static function mime_type_to_extension( $mime_type ) {
		$map = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);

		return $map[ $mime_type ] ?? 'png';
	}

	/**
	 * Generate a signed same-origin URL for one intermediate attachment.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function get_asset_url( $job_id, $attachment_id ) {
		$base_url   = admin_url( 'admin-post.php' );
		$path       = wp_parse_url( $base_url, PHP_URL_PATH );
		$query      = wp_parse_url( $base_url, PHP_URL_QUERY );
		$relative   = is_string( $path ) && '' !== $path ? $path : '/wp-admin/admin-post.php';
		$relative  .= is_string( $query ) && '' !== $query ? '?' . $query : '';

		return add_query_arg(
			array(
				'action'        => 'alorbach_image_job_asset',
				'job_id'        => (string) $job_id,
				'attachment_id' => (int) $attachment_id,
				'_wpnonce'      => wp_create_nonce( 'alorbach_image_job_asset_' . $job_id . '_' . $attachment_id ),
			),
			$relative
		);
	}

	/**
	 * Serve one intermediate asset to an authorized admin or job owner.
	 *
	 * @return void
	 */
	public static function serve_asset_request() {
		if ( ! is_user_logged_in() ) {
			status_header( 403 );
			exit;
		}

		$job_id        = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
		$nonce         = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( '' === $job_id || $attachment_id <= 0 || ! wp_verify_nonce( $nonce, 'alorbach_image_job_asset_' . $job_id . '_' . $attachment_id ) ) {
			status_header( 403 );
			exit;
		}

		$user_id = get_current_user_id();
		$job     = current_user_can( 'manage_options' ) ? self::get_job_for_admin( $job_id, false ) : self::get_job_for_user( $job_id, $user_id );
		if ( ! $job ) {
			status_header( 404 );
			exit;
		}

		$assets = self::get_stored_job_assets( $job_id );
		$found  = false;
		foreach ( array( 'preview_images', 'final_images', 'reference_images' ) as $key ) {
			foreach ( isset( $assets[ $key ] ) && is_array( $assets[ $key ] ) ? $assets[ $key ] : array() as $item ) {
				if ( (int) ( $item['attachment_id'] ?? 0 ) === $attachment_id ) {
					$found = true;
					break 2;
				}
			}
		}

		if ( ! $found ) {
			status_header( 404 );
			exit;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}

		$mime_type = get_post_mime_type( $attachment_id ) ?: 'application/octet-stream';
		nocache_headers();
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: inline; filename="' . basename( $file ) . '"' );
		readfile( $file );
		exit;
	}

	/**
	 * Get the normalized job status or "missing" when no snapshot is available.
	 *
	 * @param array|null $job Job snapshot.
	 * @return string
	 */
	private static function get_job_status( $job ) {
		if ( ! is_array( $job ) ) {
			return 'missing';
		}

		$status = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
		return '' !== $status ? $status : 'queued';
	}

	/**
	 * Whether a queued/in-progress job has exceeded the manual stall threshold.
	 *
	 * @param array $job Job snapshot.
	 * @return bool
	 */
	private static function is_job_stalled_for_cleanup( $job ) {
		if ( ! is_array( $job ) ) {
			return false;
		}

		$status = self::get_job_status( $job );
		if ( ! in_array( $status, array( 'queued', 'in_progress' ), true ) ) {
			return false;
		}

		if ( (int) ( $job['final_count'] ?? 0 ) > 0 ) {
			return false;
		}

		$updated_at = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
		if ( $updated_at <= 0 ) {
			return false;
		}

		return ( time() - $updated_at ) >= self::get_stalled_timeout_seconds();
	}

	/**
	 * Whether a job record is expired according to transient retention rules.
	 *
	 * @param string     $job_id Job ID.
	 * @param array|null $job    Optional job snapshot.
	 * @return bool
	 */
	private static function is_job_expired_for_cleanup( $job_id, $job = null ) {
		if ( ! is_array( $job ) ) {
			return true;
		}

		$age = self::get_job_age_seconds( $job );
		if ( null === $age ) {
			return false;
		}

		return $age >= self::get_job_retention_seconds();
	}

	/**
	 * Get the age of a job in seconds.
	 *
	 * @param array $job Job snapshot.
	 * @return int|null
	 */
	private static function get_job_age_seconds( $job ) {
		if ( ! is_array( $job ) ) {
			return null;
		}

		$created_at = isset( $job['created_at'] ) ? (int) $job['created_at'] : 0;
		$updated_at = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
		$timestamp = max( $created_at, $updated_at );
		if ( $timestamp <= 0 ) {
			return null;
		}

		return max( 0, time() - $timestamp );
	}

	/**
	 * Get the manual stall threshold in seconds.
	 *
	 * @return int
	 */
	private static function get_stalled_timeout_seconds() {
		return max(
			30,
			(int) apply_filters( 'alorbach_image_job_stalled_seconds', self::JOB_STALLED_SECONDS )
		);
	}

	/**
	 * Get the retention window used for expired job cleanup.
	 *
	 * @return int
	 */
	private static function get_job_retention_seconds() {
		return max( 1, (int) apply_filters( 'alorbach_image_job_ttl', self::JOB_TTL ) );
	}

	/**
	 * Return the configured cleanup statuses used for "clear all".
	 *
	 * @return array
	 */
	private static function get_all_job_statuses_for_cleanup() {
		return array( 'queued', 'in_progress', 'completed', 'failed', 'stalled', 'expired' );
	}

	/**
	 * Check whether a job snapshot matches any requested statuses.
	 *
	 * @param string     $job_id   Job ID.
	 * @param array|null $job      Job snapshot.
	 * @param array      $statuses Requested statuses.
	 * @return bool
	 */
	private static function job_matches_requested_statuses( $job_id, $job, $statuses ) {
		foreach ( $statuses as $status ) {
			if ( 'expired' === $status && self::is_job_expired_for_cleanup( $job_id, $job ) ) {
				return true;
			}
			if ( 'stalled' === $status && self::is_job_stalled_for_cleanup( $job ) ) {
				return true;
			}
			if ( is_array( $job ) && self::get_job_status( $job ) === $status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove a job from the indexed job list.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	private static function remove_job_from_index( $job_id ) {
		$index = self::get_job_index();
		if ( ! isset( $index[ $job_id ] ) ) {
			return false;
		}

		unset( $index[ $job_id ] );
		self::save_job_index( $index );
		return true;
	}

	/**
	 * Check whether any attachments remain linked to a job.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	private static function job_exists_in_attachments( $job_id ) {
		return ! empty( self::get_job_attachment_ids( $job_id ) );
	}

	/**
	 * Get owned attachment ids for one job, optionally constrained to roles.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $roles   Optional roles.
	 * @return array
	 */
	private static function get_job_attachment_ids( $job_id, $roles = array() ) {
		$meta_query = array(
			array(
				'key'     => self::ATTACHMENT_META_JOB_ID,
				'value'   => (string) $job_id,
				'compare' => '=',
			),
		);

		if ( ! empty( $roles ) ) {
			$meta_query[] = array(
				'key'     => self::ATTACHMENT_META_ROLE,
				'value'   => array_values( array_map( 'sanitize_key', (array) $roles ) ),
				'compare' => 'IN',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Delete attachments for a job according to the supplied cleanup options.
	 *
	 * @param string     $job_id Job ID.
	 * @param array      $options Cleanup options.
	 * @param array|null  $job    Optional job snapshot.
	 * @return array
	 */
	private static function delete_job_attachments( $job_id, $options, $job = null ) {
		$allowed_roles = array();
		if ( ! empty( $options['delete_preview_assets'] ) ) {
			$allowed_roles[] = 'preview';
		}
		if ( ! empty( $options['delete_reference_assets'] ) ) {
			$allowed_roles[] = 'reference';
		}
		if ( ! empty( $options['delete_final_assets'] ) ) {
			$allowed_roles[] = 'final';
		}

		$attachments_removed = array();
		$attachments_skipped = array();
		$job_status = self::get_job_status( $job );
		$job_is_cleanup_safe = in_array( $job_status, array( 'failed', 'stalled', 'expired' ), true );
		$finals_allowed = ! empty( $options['delete_final_assets'] ) && ( ! self::has_promoted_final_attachment( $job_id, $job ) || ! empty( $options['force_final_assets'] ) );

		foreach ( self::get_job_attachment_ids( $job_id ) as $attachment_id ) {
			$role = sanitize_key( (string) get_post_meta( $attachment_id, self::ATTACHMENT_META_ROLE, true ) );
			if ( '' === $role ) {
				$attachments_skipped[] = $attachment_id;
				continue;
			}

			if ( 'final' === $role ) {
				if ( ! $job_is_cleanup_safe || ! $finals_allowed ) {
					$attachments_skipped[] = $attachment_id;
					continue;
				}
			} elseif ( ! in_array( $role, $allowed_roles, true ) ) {
				$attachments_skipped[] = $attachment_id;
				continue;
			}

			if ( self::delete_attachment_if_exists( (int) $attachment_id ) ) {
				$attachments_removed[] = (int) $attachment_id;
			} else {
				$attachments_skipped[] = (int) $attachment_id;
			}
		}

		return array(
			'attachments_removed' => array_values( array_unique( array_map( 'absint', $attachments_removed ) ) ),
			'attachments_skipped' => array_values( array_unique( array_map( 'absint', $attachments_skipped ) ) ),
		);
	}

	/**
	 * Determine whether a final attachment is already promoted downstream.
	 *
	 * @param string     $job_id Job ID.
	 * @param array|null $job    Optional job snapshot.
	 * @return bool
	 */
	private static function has_promoted_final_attachment( $job_id, $job = null ) {
		return (bool) apply_filters( 'alorbach_image_job_final_attachment_promoted', false, (string) $job_id, $job );
	}

	/**
	 * Delete one attachment if it still exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function delete_attachment_if_exists( $attachment_id ) {
		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			$deleted = wp_delete_attachment( $attachment_id, true );
			return false !== $deleted && null !== $deleted;
		}

		return false;
	}

	/**
	 * Ensure periodic cleanup is scheduled.
	 *
	 * @return void
	 */
	public static function ensure_cleanup_scheduled() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Delete expired preview/reference attachments.
	 *
	 * @return void
	 */
	public static function cleanup_expired_assets() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::ATTACHMENT_META_ROLE,
						'value'   => array( 'preview', 'reference' ),
						'compare' => 'IN',
					),
					array(
						'key'     => self::ATTACHMENT_META_CREATED_AT,
						'value'   => time() - self::PREVIEW_RETENTION_SECONDS,
						'type'    => 'NUMERIC',
						'compare' => '<=',
					),
				),
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			self::delete_attachment_if_exists( (int) $attachment_id );
		}
	}

	/**
	 * Decide whether a stored attachment ref has expired.
	 *
	 * @param array $ref Stored ref.
	 * @return bool
	 */
	private static function is_attachment_expired( $ref ) {
		$role = (string) ( $ref['role'] ?? '' );
		if ( ! in_array( $role, array( 'preview', 'reference' ), true ) ) {
			return false;
		}

		$created_at = (int) ( $ref['created_at'] ?? 0 );
		return $created_at > 0 && $created_at <= ( time() - self::PREVIEW_RETENTION_SECONDS );
	}

	/**
	 * Clear DB-backed transient option caches before polling from long-running CLI processes.
	 *
	 * Without an external object cache, another PHP request can update a transient
	 * while the current process still serves the old option value from its local
	 * object cache. Sample-gallery seeding polls in one long CLI request, so we
	 * force-refresh the targeted transient options before reading them.
	 *
	 * @param string $transient_key Logical transient key without `_transient_` prefix.
	 * @return void
	 */
	private static function invalidate_transient_option_caches( $transient_key ) {
		if ( wp_using_ext_object_cache() || '' === $transient_key ) {
			return;
		}

		wp_cache_delete( '_transient_' . $transient_key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $transient_key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Emit lightweight diagnostics for queue reliability work.
	 *
	 * @param string $event   Event name.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function log_job_diagnostic( $event, $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log(
			sprintf(
				'[alorbach-image-jobs] %s %s',
				(string) $event,
				wp_json_encode( $context )
			)
		);
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
