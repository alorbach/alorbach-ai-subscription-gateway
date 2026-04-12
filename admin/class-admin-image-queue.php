<?php
/**
 * Admin: image queue monitor.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

use Alorbach\AIGateway\Image_Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Image_Queue
 */
class Admin_Image_Queue {

	/**
	 * Queue admin page slug.
	 *
	 * @return string
	 */
	private static function page_slug() {
		return 'alorbach-image-queue';
	}

	/**
	 * Transient key for the current operator notice.
	 *
	 * @return string
	 */
	private static function notice_transient_key() {
		return 'alorbach_image_queue_notice_' . get_current_user_id();
	}

	/**
	 * Whether the current admin request targets the image queue page.
	 *
	 * @return bool
	 */
	private static function is_queue_page_request() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';

		return self::page_slug() === $page;
	}

	/**
	 * Process a posted queue action before any output is sent.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! is_admin() || ! self::is_queue_page_request() ) {
			return;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['alorbach_image_queue_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}

		if ( ! Admin_Helper::verify_post_nonce( 'alorbach_image_queue_nonce', 'alorbach_image_queue_actions' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'alorbach-ai-gateway' ) );
		}

		$payload = self::execute_action( sanitize_key( wp_unslash( $_POST['alorbach_image_queue_action'] ) ) );

		set_transient( self::notice_transient_key(), $payload['notice'], MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::page_slug() ) );
		exit;
	}

	/**
	 * Execute one queue maintenance action and return its result payload.
	 *
	 * @param string $action Action name.
	 * @return array
	 */
	public static function execute_action( $action ) {
		$action = sanitize_key( (string) $action );
		$notice = array(
			'type'    => 'info',
			'message' => __( 'No queue action was taken.', 'alorbach-ai-gateway' ),
		);
		$result = array(
			'job_count'        => 0,
			'attachment_count' => 0,
		);

		try {
			switch ( $action ) {
				case 'clear_failed':
					$result = Image_Jobs::delete_jobs_by_status(
						array( 'failed' ),
						array(
							'delete_preview_assets'   => true,
							'delete_reference_assets' => true,
							'delete_final_assets'     => true,
						)
					);
					break;
				case 'clear_stalled':
					$result = Image_Jobs::delete_stalled_jobs(
						array(
							'delete_preview_assets'   => true,
							'delete_reference_assets' => true,
							'delete_final_assets'     => true,
						)
					);
					break;
				case 'clear_completed':
					$result = Image_Jobs::delete_completed_jobs(
						array(
							'delete_preview_assets'   => true,
							'delete_reference_assets' => true,
							'delete_final_assets'     => false,
						)
					);
					break;
				case 'clear_expired':
					$result = Image_Jobs::delete_expired_jobs(
						array(
							'delete_preview_assets'   => true,
							'delete_reference_assets' => true,
							'delete_final_assets'     => false,
						)
					);
					break;
				case 'clear_all':
					$result = Image_Jobs::delete_all_jobs(
						array(
							'delete_preview_assets'   => true,
							'delete_reference_assets' => true,
							'delete_final_assets'     => true,
						)
					);
					break;
				case 'prune_index':
					$result = array(
						'job_count'        => 0,
						'attachment_count' => 0,
						'pruned_count'     => Image_Jobs::prune_job_index(),
					);
					break;
			}

			$notice = self::build_action_notice( $action, $result );
		} catch ( \Throwable $error ) {
			$notice = array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}

		return array(
			'action' => $action,
			'notice' => $notice,
			'result' => $result,
		);
	}

	/**
	 * Build an operator notice for one queue action result.
	 *
	 * @param string $action Action name.
	 * @param array  $result Action result.
	 * @return array
	 */
	private static function build_action_notice( $action, $result ) {
		$job_count        = isset( $result['job_count'] ) ? (int) $result['job_count'] : 0;
		$attachment_count = isset( $result['attachment_count'] ) ? (int) $result['attachment_count'] : 0;
		$pruned_count     = isset( $result['pruned_count'] ) ? (int) $result['pruned_count'] : 0;

		if ( in_array( $action, array( 'clear_failed', 'clear_stalled', 'clear_completed', 'clear_expired', 'clear_all' ), true ) ) {
			$message = sprintf(
				/* translators: %d: job count */
				_n( 'Cleared %d image job.', 'Cleared %d image jobs.', $job_count, 'alorbach-ai-gateway' ),
				$job_count
			);
			if ( $attachment_count > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: attachment count */
					_n( 'Removed %d attachment.', 'Removed %d attachments.', $attachment_count, 'alorbach-ai-gateway' ),
					$attachment_count
				);
			}
			if ( $pruned_count > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: index entry count */
					_n( 'Pruned %d stale index entry.', 'Pruned %d stale index entries.', $pruned_count, 'alorbach-ai-gateway' ),
					$pruned_count
				);
			}

			return array(
				'type'    => $job_count > 0 || $attachment_count > 0 || $pruned_count > 0 ? 'success' : 'info',
				'message' => $message,
			);
		}

		if ( 'prune_index' === $action ) {
			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: index entry count */
					_n( 'Pruned %d stale index entry.', 'Pruned %d stale index entries.', $pruned_count, 'alorbach-ai-gateway' ),
					$pruned_count
				),
			);
		}

		return array(
			'type'    => 'info',
			'message' => __( 'No queue action was taken.', 'alorbach-ai-gateway' ),
		);
	}

	/**
	 * Read and clear the latest operator notice.
	 *
	 * @return array|null
	 */
	private static function get_notice() {
		$key = self::notice_transient_key();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
		}

		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Render the operator actions panel.
	 *
	 * @param array $stats Queue stats.
	 * @return void
	 */
	private static function render_operator_panel( $stats ) {
		?>
		<div class="alorbach-image-queue__operator-panel">
			<div class="alorbach-image-queue__operator-copy">
				<h2><?php esc_html_e( 'Operator Actions', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Manual cleanup is admin-only and conservative by default. Final assets are only removed when the cleanup rules allow it.', 'alorbach-ai-gateway' ); ?></p>
			</div>
			<div class="alorbach-image-queue__operator-stats">
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Recent Jobs', 'alorbach-ai-gateway' ); ?></span><strong data-stat="total"><?php echo esc_html( (string) ( $stats['recent_total'] ?? 0 ) ); ?></strong></div>
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Queued', 'alorbach-ai-gateway' ); ?></span><strong data-stat="queued"><?php echo esc_html( (string) ( $stats['queued'] ?? 0 ) ); ?></strong></div>
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'In Progress', 'alorbach-ai-gateway' ); ?></span><strong data-stat="in_progress"><?php echo esc_html( (string) ( $stats['in_progress'] ?? 0 ) ); ?></strong></div>
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Completed', 'alorbach-ai-gateway' ); ?></span><strong data-stat="completed"><?php echo esc_html( (string) ( $stats['completed'] ?? 0 ) ); ?></strong></div>
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Failed', 'alorbach-ai-gateway' ); ?></span><strong data-stat="failed"><?php echo esc_html( (string) ( $stats['failed'] ?? 0 ) ); ?></strong></div>
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Stalled', 'alorbach-ai-gateway' ); ?></span><strong data-stat="stalled"><?php echo esc_html( (string) ( $stats['stalled'] ?? 0 ) ); ?></strong></div>
				<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Expired', 'alorbach-ai-gateway' ); ?></span><strong data-stat="expired"><?php echo esc_html( (string) ( $stats['expired'] ?? 0 ) ); ?></strong></div>
			</div>
			<form method="post" class="alorbach-image-queue__operator-actions" data-role="operator-form">
				<?php wp_nonce_field( 'alorbach_image_queue_actions', 'alorbach_image_queue_nonce' ); ?>
				<div class="alorbach-image-queue__action-set">
					<button type="submit" class="button button-secondary" name="alorbach_image_queue_action" value="clear_failed" data-confirm="<?php echo esc_attr( __( 'Clear failed jobs and their owned assets?', 'alorbach-ai-gateway' ) ); ?>"><?php esc_html_e( 'Clear Failed', 'alorbach-ai-gateway' ); ?></button>
					<button type="submit" class="button button-secondary" name="alorbach_image_queue_action" value="clear_stalled" data-confirm="<?php echo esc_attr( __( 'Clear stalled jobs and their owned assets?', 'alorbach-ai-gateway' ) ); ?>"><?php esc_html_e( 'Clear Stalled', 'alorbach-ai-gateway' ); ?></button>
					<button type="submit" class="button button-secondary" name="alorbach_image_queue_action" value="clear_completed" data-confirm="<?php echo esc_attr( __( 'Clear completed jobs and their queue metadata?', 'alorbach-ai-gateway' ) ); ?>"><?php esc_html_e( 'Clear Completed', 'alorbach-ai-gateway' ); ?></button>
					<button type="submit" class="button button-secondary" name="alorbach_image_queue_action" value="clear_expired" data-confirm="<?php echo esc_attr( __( 'Clear expired jobs and their owned assets?', 'alorbach-ai-gateway' ) ); ?>"><?php esc_html_e( 'Clear Expired', 'alorbach-ai-gateway' ); ?></button>
					<button type="submit" class="button button-link-delete" name="alorbach_image_queue_action" value="clear_all" data-confirm="<?php echo esc_attr( __( 'Clear every indexed job and its owned queue assets?', 'alorbach-ai-gateway' ) ); ?>"><?php esc_html_e( 'Clear All', 'alorbach-ai-gateway' ); ?></button>
					<button type="submit" class="button button-link" name="alorbach_image_queue_action" value="prune_index" data-confirm="<?php echo esc_attr( __( 'Prune stale queue index entries only?', 'alorbach-ai-gateway' ) ); ?>"><?php esc_html_e( 'Prune Index', 'alorbach-ai-gateway' ); ?></button>
					<button type="button" class="button" data-role="manual-refresh"><?php esc_html_e( 'Refresh Queue', 'alorbach-ai-gateway' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render image queue page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}

		$stats_notice = self::get_notice();
		$stats        = Image_Jobs::get_queue_stats();
		$stats['recent_total'] = min( 50, (int) ( $stats['total'] ?? 0 ) );

		$rest_url   = rest_url( 'alorbach/v1/admin/image-jobs' );
		$rest_path  = wp_parse_url( $rest_url, PHP_URL_PATH );
		$rest_query = wp_parse_url( $rest_url, PHP_URL_QUERY );
		$rest_base  = ( is_string( $rest_path ) && '' !== $rest_path )
			? $rest_path . ( is_string( $rest_query ) && '' !== $rest_query ? '?' . $rest_query : '' )
			: '/wp-json/alorbach/v1/admin/image-jobs';
		$nonce      = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Image Queue', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Monitor recent image jobs, active queue state, and per-job details. The list refreshes automatically.', 'alorbach-ai-gateway' ); ?></p>
			<div data-role="queue-notice">
				<?php if ( $stats_notice ) : ?>
					<?php Admin_Helper::render_notice( (string) ( $stats_notice['message'] ?? '' ), (string) ( $stats_notice['type'] ?? 'info' ) ); ?>
				<?php endif; ?>
			</div>

			<?php self::render_operator_panel( $stats ); ?>

			<div id="alorbach-image-queue-app" class="alorbach-image-queue">
				<div class="alorbach-image-queue__stats">
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Recent Jobs', 'alorbach-ai-gateway' ); ?></span><strong data-stat="total"><?php echo esc_html( (string) ( $stats['recent_total'] ?? 0 ) ); ?></strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Queued', 'alorbach-ai-gateway' ); ?></span><strong data-stat="queued"><?php echo esc_html( (string) ( $stats['queued'] ?? 0 ) ); ?></strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'In Progress', 'alorbach-ai-gateway' ); ?></span><strong data-stat="in_progress"><?php echo esc_html( (string) ( $stats['in_progress'] ?? 0 ) ); ?></strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Completed', 'alorbach-ai-gateway' ); ?></span><strong data-stat="completed"><?php echo esc_html( (string) ( $stats['completed'] ?? 0 ) ); ?></strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Failed', 'alorbach-ai-gateway' ); ?></span><strong data-stat="failed"><?php echo esc_html( (string) ( $stats['failed'] ?? 0 ) ); ?></strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Stalled', 'alorbach-ai-gateway' ); ?></span><strong data-stat="stalled"><?php echo esc_html( (string) ( $stats['stalled'] ?? 0 ) ); ?></strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Expired', 'alorbach-ai-gateway' ); ?></span><strong data-stat="expired"><?php echo esc_html( (string) ( $stats['expired'] ?? 0 ) ); ?></strong></div>
				</div>

				<div class="alorbach-image-queue__status">
					<span data-role="status"><?php esc_html_e( 'Loading queue...', 'alorbach-ai-gateway' ); ?></span>
					<button type="button" class="button" data-role="refresh"><?php esc_html_e( 'Refresh', 'alorbach-ai-gateway' ); ?></button>
				</div>

				<div class="alorbach-image-queue__layout">
					<div class="alorbach-image-queue__panel alorbach-image-queue__panel--list">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Job', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'User', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Status', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Progress', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Updated', 'alorbach-ai-gateway' ); ?></th>
								</tr>
							</thead>
							<tbody data-role="rows">
								<tr><td colspan="6"><?php esc_html_e( 'Loading...', 'alorbach-ai-gateway' ); ?></td></tr>
							</tbody>
						</table>
					</div>

					<div class="alorbach-image-queue__panel alorbach-image-queue__details" data-role="details">
						<h2><?php esc_html_e( 'Job Details', 'alorbach-ai-gateway' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Select a job to inspect compact metadata first. Image assets load only on demand.', 'alorbach-ai-gateway' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<style>
			.alorbach-image-queue__operator-panel { background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:16px; margin:16px 0 18px; box-shadow:0 1px 0 rgba(0,0,0,.04); }
			.alorbach-image-queue__operator-copy h2 { margin:0 0 4px; }
			.alorbach-image-queue__operator-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:14px 0; }
			.alorbach-image-queue__operator-actions { margin-top:12px; }
			.alorbach-image-queue__action-set { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
			.alorbach-image-queue__stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:16px 0; }
			.alorbach-image-queue__stat { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:12px; }
			.alorbach-image-queue__stat span { display:block; color:#50575e; margin-bottom:6px; }
			.alorbach-image-queue__stat strong { font-size:20px; }
			.alorbach-image-queue__status { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:12px; }
			.alorbach-image-queue__layout { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(320px,1fr); gap:16px; align-items:start; }
			.alorbach-image-queue__panel { min-width:0; background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:12px; }
			.alorbach-image-queue__panel--list { overflow-x:auto; }
			.alorbach-image-queue__row.is-selected { background:#eef4ff; }
			.alorbach-image-queue__row button { background:none; border:0; color:#2271b1; cursor:pointer; padding:0; text-align:left; }
			.alorbach-image-queue__job-button { display:inline-flex; width:100%; font-weight:600; overflow-wrap:anywhere; word-break:break-word; }
			.alorbach-image-queue__meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin:12px 0; }
			.alorbach-image-queue__meta div { background:#f6f7f7; border-radius:6px; padding:10px; }
			.alorbach-image-queue__meta span { display:block; color:#50575e; margin-bottom:4px; }
			.alorbach-image-queue__details-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
			.alorbach-image-queue__details-header h2 { margin:0; }
			.alorbach-image-queue__job-id { margin:4px 0 0; color:#50575e; word-break:break-all; }
			.alorbach-image-queue__tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 12px; padding:0; list-style:none; border-bottom:1px solid #dcdcde; }
			.alorbach-image-queue__tab { appearance:none; border:0; border-bottom:2px solid transparent; background:none; padding:10px 4px; margin:0; font-weight:600; color:#50575e; cursor:pointer; }
			.alorbach-image-queue__tab.is-active { color:#2271b1; border-bottom-color:#2271b1; }
			.alorbach-image-queue__tab-panel[hidden] { display:none !important; }
			.alorbach-image-queue__prompt-card { margin:0 0 12px; border:1px solid #dcdcde; border-radius:8px; background:#fff; overflow:hidden; }
			.alorbach-image-queue__prompt-card-head { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-bottom:1px solid #dcdcde; background:#f6f7f7; }
			.alorbach-image-queue__prompt-card-head h3 { margin:0; font-size:14px; }
			.alorbach-image-queue__prompt-card-actions { display:flex; gap:8px; align-items:center; }
			.alorbach-image-queue__copy { display:inline-flex; align-items:center; gap:6px; }
			.alorbach-image-queue__copy-feedback { color:#2271b1; font-size:12px; min-height:16px; }
			.alorbach-image-queue__prompt { white-space:pre-wrap; background:#f6f7f7; border-radius:6px; padding:10px; margin:12px; word-break:break-word; }
			.alorbach-image-queue__empty { margin:0; padding:12px; color:#50575e; background:#f6f7f7; border-radius:6px; }
			.alorbach-image-queue__section-title { margin:16px 0 8px; }
			.alorbach-image-queue__thumbs { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
			.alorbach-image-queue__thumbs img { width:88px; height:88px; object-fit:cover; border-radius:6px; border:1px solid #dcdcde; cursor:pointer; transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
			.alorbach-image-queue__thumbs img:hover, .alorbach-image-queue__thumbs img:focus { transform:translateY(-1px); box-shadow:0 10px 24px rgba(34,113,177,.18); border-color:#2271b1; outline:none; }
			.alorbach-image-queue__error { color:#b32d2e; }
			.alorbach-image-queue__lightbox { position:fixed; inset:0; z-index:100000; display:none; align-items:center; justify-content:center; padding:32px; background:rgba(15,23,42,.82); }
			.alorbach-image-queue__lightbox.is-open { display:flex; }
			.alorbach-image-queue__lightbox-backdrop { position:absolute; inset:0; }
			.alorbach-image-queue__lightbox img { position:relative; max-width:min(1200px,92vw); max-height:90vh; border-radius:10px; box-shadow:0 24px 80px rgba(0,0,0,.45); background:#fff; }
			@media (max-width: 960px) { .alorbach-image-queue__layout { grid-template-columns:1fr; } }
			@media (max-width: 782px) {
				.alorbach-image-queue__operator-panel { padding:12px; }
				.alorbach-image-queue__action-set { width:100%; }
				.alorbach-image-queue__action-set .button { width:100%; justify-content:center; }
				.alorbach-image-queue__panel { padding:10px; }
				.alorbach-image-queue__panel--list { overflow-x:visible; }
				.alorbach-image-queue__panel--list table,
				.alorbach-image-queue__panel--list thead,
				.alorbach-image-queue__panel--list tbody,
				.alorbach-image-queue__panel--list tr,
				.alorbach-image-queue__panel--list th,
				.alorbach-image-queue__panel--list td { display:block; width:100%; }
				.alorbach-image-queue__panel--list thead { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); clip-path:inset(50%); white-space:nowrap; }
				.alorbach-image-queue__panel--list tbody { display:grid; gap:10px; }
				.alorbach-image-queue__panel--list tr { border:1px solid #dcdcde; border-radius:10px; background:#fff; padding:10px; }
				.alorbach-image-queue__panel--list td { border:0 !important; padding:0; }
				.alorbach-image-queue__panel--list td + td { margin-top:8px; }
				.alorbach-image-queue__panel--list td::before { content:attr(data-label); display:block; margin-bottom:2px; color:#50575e; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
				.alorbach-image-queue__job-button { font-size:14px; }
				.alorbach-image-queue__details-header { align-items:flex-start; flex-direction:column; }
				.alorbach-image-queue__prompt-card-head { align-items:flex-start; flex-direction:column; }
				.alorbach-image-queue__prompt-card-actions { width:100%; flex-wrap:wrap; }
				.alorbach-image-queue__copy { justify-content:center; width:100%; }
			}
		</style>

		<script>
			jQuery(function ($) {
				var $app = $('#alorbach-image-queue-app');
				if (! $app.length) {
					return;
				}

				var restBase = <?php echo wp_json_encode( $rest_base ); ?>;
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var $rowsEl = $app.find('[data-role="rows"]');
				var $detailsEl = $app.find('[data-role="details"]');
				var $statusEl = $app.find('[data-role="status"]');
				var $refreshBtn = $app.find('[data-role="refresh"]');
				var $noticeEl = $('[data-role="queue-notice"]').first();
				var $operatorForm = $('[data-role="operator-form"]').first();
				var $actionButtons = $operatorForm.find('button[type="submit"]');
				var selectedJobId = null;
				var refreshTimer = null;
				var lightboxEl = null;
				var hasRenderedDetails = false;
				var activeDetailsTab = 'overview';

				function request(options) {
					return $.ajax($.extend(true, {
						dataType: 'json',
						xhrFields: { withCredentials: true },
						beforeSend: function (xhr) {
							xhr.setRequestHeader('X-WP-Nonce', nonce);
						}
					}, options)).then(function (data) {
						return data;
					}, function (xhr) {
						var data = xhr && xhr.responseJSON ? xhr.responseJSON : null;
						if (! data && xhr && xhr.responseText) {
							try {
								data = JSON.parse(xhr.responseText);
							} catch (error) {
								data = null;
							}
						}

						throw data || { message: (xhr && xhr.statusText) ? xhr.statusText : 'Unexpected error.' };
					});
				}

				function api(path) {
					return request({
						url: restBase + path,
						method: 'GET'
					});
				}

				function runQueueAction(action) {
					return request({
						url: restBase + '/actions',
						method: 'POST',
						data: { action: action }
					});
				}

				function getErrorMessage(error) {
					if (! error) return 'Unexpected error.';
					if (typeof error.message === 'string' && error.message) return error.message;
					if (typeof error.code === 'string' && error.code) return error.code;
					return 'Unexpected error.';
				}

				function formatDuration(seconds) {
					if (seconds === null || seconds === undefined || seconds < 0) return '-';
					if (seconds < 60) return seconds + 's';
					var mins = Math.floor(seconds / 60);
					var secs = seconds % 60;
					return mins + 'm ' + secs + 's';
				}

				function escapeHtml(value) {
					return String(value || '').replace(/[&<>\"']/g, function (char) {
						return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', '\'': '&#039;' })[char];
					});
				}

				function setStats(stats) {
					['total', 'queued', 'in_progress', 'completed', 'failed', 'stalled', 'expired'].forEach(function (key) {
						Array.prototype.forEach.call($app[0].querySelectorAll('[data-stat="' + key + '"]'), function (el) {
							var value = key === 'total' ? (stats.recent_total || stats.total || 0) : (stats[key] || 0);
							el.textContent = String(value);
						});
					});
				}

				function renderNotice(notice) {
					if (! $noticeEl.length) {
						return;
					}

					if (! notice || ! notice.message) {
						$noticeEl.empty();
						return;
					}

					var typeMap = {
						success: 'notice-success',
						error: 'notice-error',
						warning: 'notice-warning',
						info: 'notice-info'
					};
					var cssClass = typeMap[notice.type] || 'notice-info';
					$noticeEl.html('<div class="notice ' + cssClass + '"><p>' + escapeHtml(notice.message) + '</p></div>');
				}

				function setActionButtonsDisabled(disabled) {
					$actionButtons.prop('disabled', !! disabled);
				}

				function resetDetails() {
					hasRenderedDetails = false;
					activeDetailsTab = 'overview';
					$detailsEl.html('<h2>Job Details</h2><p class="description">Select a job to inspect compact metadata first. Image assets load only on demand.</p>');
				}

				function ensureLightbox() {
					if (lightboxEl) return lightboxEl;
					lightboxEl = document.createElement('div');
					lightboxEl.className = 'alorbach-image-queue__lightbox';
					lightboxEl.innerHTML = '<div class="alorbach-image-queue__lightbox-backdrop"></div><img src="" alt="">';
					lightboxEl.querySelector('.alorbach-image-queue__lightbox-backdrop').addEventListener('click', closeLightbox);
					document.body.appendChild(lightboxEl);
					return lightboxEl;
				}

				function openLightbox(src, alt) {
					var lightbox = ensureLightbox();
					var image = lightbox.querySelector('img');
					image.src = src;
					image.alt = alt || '';
					lightbox.classList.add('is-open');
					document.body.style.overflow = 'hidden';
				}

				function closeLightbox() {
					if (!lightboxEl) return;
					lightboxEl.classList.remove('is-open');
					document.body.style.overflow = '';
				}

				function bindThumbClicks(scope) {
					Array.prototype.forEach.call(scope.querySelectorAll('[data-fullsize-src]'), function (thumb) {
						thumb.addEventListener('click', function () {
							openLightbox(thumb.getAttribute('data-fullsize-src'), thumb.getAttribute('alt') || '');
						});
						thumb.addEventListener('keydown', function (event) {
							if (event.key === 'Enter' || event.key === ' ') {
								event.preventDefault();
								openLightbox(thumb.getAttribute('data-fullsize-src'), thumb.getAttribute('alt') || '');
							}
						});
					});
				}

				function setActiveTab(tabName) {
					activeDetailsTab = tabName || 'overview';
					Array.prototype.forEach.call($detailsEl[0].querySelectorAll('[data-tab]'), function (tabButton) {
						var isActive = tabButton.getAttribute('data-tab') === activeDetailsTab;
						tabButton.classList.toggle('is-active', isActive);
						tabButton.setAttribute('aria-selected', isActive ? 'true' : 'false');
						tabButton.setAttribute('tabindex', isActive ? '0' : '-1');
					});
					Array.prototype.forEach.call($detailsEl[0].querySelectorAll('[data-tab-panel]'), function (panel) {
						panel.hidden = panel.getAttribute('data-tab-panel') !== activeDetailsTab;
					});
				}

				function copyText(text, button) {
					var value = String(text || '');
					if (!value) return;
					var feedback = button && button.parentNode ? button.parentNode.querySelector('[data-copy-feedback]') : null;

					function updateFeedback(message, isError) {
						if (!feedback) return;
						feedback.textContent = message;
						feedback.style.color = isError ? '#b32d2e' : '#2271b1';
						window.setTimeout(function () {
							if (feedback.textContent === message) feedback.textContent = '';
						}, 1800);
					}

					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(value).then(function () {
							updateFeedback('Copied', false);
						}).catch(function () {
							updateFeedback('Copy failed', true);
						});
						return;
					}

					var helper = document.createElement('textarea');
					helper.value = value;
					helper.setAttribute('readonly', 'readonly');
					helper.style.position = 'absolute';
					helper.style.left = '-9999px';
					document.body.appendChild(helper);
					helper.select();
					try {
						document.execCommand('copy');
						updateFeedback('Copied', false);
					} catch (error) {
						updateFeedback('Copy failed', true);
					}
					document.body.removeChild(helper);
				}

				function bindDetailInteractions(job) {
					Array.prototype.forEach.call($detailsEl[0].querySelectorAll('[data-tab]'), function (tabButton) {
						tabButton.addEventListener('click', function () {
							var tabName = tabButton.getAttribute('data-tab');
							setActiveTab(tabName);
							if ('images' === tabName && ! job.images_included) {
								loadDetails(job.job_id, false, true);
							}
						});
					});

					Array.prototype.forEach.call($detailsEl[0].querySelectorAll('[data-copy-text]'), function (copyButton) {
						copyButton.addEventListener('click', function () {
							copyText(copyButton.getAttribute('data-copy-text') || '', copyButton);
						});
					});

					var loadImagesBtn = $detailsEl[0].querySelector('[data-role="load-images"]');
					if (loadImagesBtn) {
						loadImagesBtn.addEventListener('click', function () {
							loadDetails(job.job_id, false, true);
						});
					}

					setActiveTab(activeDetailsTab);
				}

				function renderRows(payload) {
					var jobs = payload.jobs || [];
					setStats(payload.stats || {});
					if (!jobs.length) {
						selectedJobId = null;
						$rowsEl.html('<tr><td colspan="6">No image jobs found.</td></tr>');
						resetDetails();
						return;
					}

					var hasSelectedJob = jobs.some(function (job) {
						return job.job_id === selectedJobId;
					});
					if (!selectedJobId || !hasSelectedJob) {
						selectedJobId = jobs[0].job_id;
					}

					$rowsEl.html(jobs.map(function (job) {
						var selected = selectedJobId === job.job_id ? ' is-selected' : '';
						var shortJobId = job.job_id.length > 14 ? job.job_id.slice(0, 14) + '…' : job.job_id;
						return '<tr class="alorbach-image-queue__row' + selected + '">' +
							'<td data-label="Job"><button type="button" class="alorbach-image-queue__job-button" data-job-id="' + escapeHtml(job.job_id) + '" title="' + escapeHtml(job.job_id) + '">' + escapeHtml(shortJobId) + '</button></td>' +
							'<td data-label="User">' + escapeHtml(job.user_label) + '</td>' +
							'<td data-label="Model">' + escapeHtml(job.model) + '</td>' +
							'<td data-label="Status">' + escapeHtml(job.status_label) + '</td>' +
							'<td data-label="Progress">' + job.progress_percent + '%</td>' +
							'<td data-label="Updated">' + escapeHtml(job.updated_at_label) + '</td>' +
							'</tr>';
					}).join(''));

					Array.prototype.forEach.call($rowsEl[0].querySelectorAll('[data-job-id]'), function (button) {
						button.addEventListener('click', function () {
							selectedJobId = button.getAttribute('data-job-id');
							loadDetails(selectedJobId, false, false);
						});
					});
				}

				function renderThumbs(items) {
					if (!items || !items.length) return '<p class="alorbach-image-queue__empty">None</p>';
					return '<div class="alorbach-image-queue__thumbs">' + items.slice(0, 4).map(function (item, index) {
						var src = item.url || (item.b64_json ? ('data:image/png;base64,' + item.b64_json) : '');
						if (!src) return '';
						return '<img src="' + escapeHtml(src) + '" data-fullsize-src="' + escapeHtml(src) + '" alt="Image ' + (index + 1) + '" tabindex="0">';
					}).join('') + '</div>';
				}

				function renderPromptCard(title, value, copyLabel) {
					var text = String(value || '');
					return '<section class="alorbach-image-queue__prompt-card">' +
						'<div class="alorbach-image-queue__prompt-card-head">' +
							'<h3>' + escapeHtml(title) + '</h3>' +
							'<div class="alorbach-image-queue__prompt-card-actions">' +
								'<button type="button" class="button button-secondary alorbach-image-queue__copy" data-copy-text="' + escapeHtml(text) + '">' + escapeHtml(copyLabel || 'Copy') + '</button>' +
								'<span class="alorbach-image-queue__copy-feedback" data-copy-feedback></span>' +
							'</div>' +
						'</div>' +
						'<div class="alorbach-image-queue__prompt">' + (text ? escapeHtml(text) : '<em>Empty</em>') + '</div>' +
					'</section>';
				}

				function renderDetails(job) {
					hasRenderedDetails = true;
					var loadImagesButton = job.images_included ? '' : '<p><button type="button" class="button button-secondary" data-role="load-images">Load Images</button></p>';
					var originalPrompt = job.original_prompt || job.prompt || '';
					var prompt = job.prompt || '';
					var errorsHtml = (job.error ? '<p class="alorbach-image-queue__error"><strong>Error:</strong> ' + escapeHtml(job.error) + '</p>' : '') +
						(job.images_error ? '<p class="alorbach-image-queue__error"><strong>Images:</strong> ' + escapeHtml(job.images_error) + '</p>' : '');
					var hasErrors = !!(job.error || job.images_error);
					$detailsEl.html(
						'<div class="alorbach-image-queue__details-header">' +
							'<div>' +
								'<h2>Job Details</h2>' +
								'<p class="alorbach-image-queue__job-id"><strong>Job ID:</strong> ' + escapeHtml(job.job_id || '') + '</p>' +
							'</div>' +
						'</div>' +
						'<div class="alorbach-image-queue__tabs" role="tablist" aria-label="Job detail tabs">' +
							'<button type="button" class="alorbach-image-queue__tab" data-tab="overview" role="tab" aria-selected="false">Overview</button>' +
							'<button type="button" class="alorbach-image-queue__tab" data-tab="prompts" role="tab" aria-selected="false">Prompts</button>' +
							'<button type="button" class="alorbach-image-queue__tab" data-tab="images" role="tab" aria-selected="false">Images</button>' +
							'<button type="button" class="alorbach-image-queue__tab" data-tab="errors" role="tab" aria-selected="false">Errors</button>' +
						'</div>' +
						'<section class="alorbach-image-queue__tab-panel" data-tab-panel="overview">' +
							'<div class="alorbach-image-queue__meta">' +
								'<div><span>Status</span><strong>' + escapeHtml(job.status_label) + '</strong></div>' +
								'<div><span>Progress</span><strong>' + job.progress_percent + '%</strong></div>' +
								'<div><span>User</span><strong>' + escapeHtml(job.user_label) + '</strong></div>' +
								'<div><span>Model</span><strong>' + escapeHtml(job.model) + '</strong></div>' +
								'<div><span>Size</span><strong>' + escapeHtml(job.size) + '</strong></div>' +
								'<div><span>Quality</span><strong>' + escapeHtml(job.quality) + '</strong></div>' +
								'<div><span>Images</span><strong>' + job.n + '</strong></div>' +
								'<div><span>References</span><strong>' + (job.reference_count || 0) + '</strong></div>' +
								'<div><span>Mode</span><strong>' + escapeHtml(job.progress_mode) + '</strong></div>' +
								'<div><span>Created</span><strong>' + escapeHtml(job.created_at_label) + '</strong></div>' +
								'<div><span>Updated</span><strong>' + escapeHtml(job.updated_at_label) + '</strong></div>' +
								'<div><span>Runtime</span><strong>' + formatDuration(job.runtime_seconds) + '</strong></div>' +
								'<div><span>Credits</span><strong>' + escapeHtml(job.cost_credits_label) + '</strong></div>' +
							'</div>' +
						'</section>' +
						'<section class="alorbach-image-queue__tab-panel" data-tab-panel="prompts" hidden>' +
							renderPromptCard('Original Prompt', originalPrompt, 'Copy Original') +
							renderPromptCard('Prompt', prompt, 'Copy Prompt') +
						'</section>' +
						'<section class="alorbach-image-queue__tab-panel" data-tab-panel="images" hidden>' +
							loadImagesButton +
							'<h3 class="alorbach-image-queue__section-title">Reference Images</h3>' + renderThumbs(job.reference_images) +
							'<h3 class="alorbach-image-queue__section-title">Preview Frames</h3>' + renderThumbs(job.preview_images) +
							'<h3 class="alorbach-image-queue__section-title">Final Images</h3>' + renderThumbs(job.final_images) +
						'</section>' +
						'<section class="alorbach-image-queue__tab-panel" data-tab-panel="errors" hidden>' +
							(hasErrors ? errorsHtml : '<p class="alorbach-image-queue__empty">No errors reported for this job.</p>') +
						'</section>'
					);
					bindThumbClicks($detailsEl[0]);
					bindDetailInteractions(job);
				}

				function loadDetails(jobId, rerenderRows, includeImages) {
					$statusEl.text('Loading job details...');
					var detailPath = '/' + encodeURIComponent(jobId) + (includeImages ? '?include_images=1' : '');
					api(detailPath).then(function (job) {
						renderDetails(job);
						$statusEl.text('Queue updated ' + new Date().toLocaleTimeString());
						if (rerenderRows) {
							loadList();
						}
					}).catch(function (error) {
						$statusEl.text('Failed to load job details: ' + getErrorMessage(error));
					});
				}

				function loadList() {
					$statusEl.text('Loading queue...');
					return api('').then(function (payload) {
						renderRows(payload);
						$statusEl.text('Queue updated ' + new Date().toLocaleTimeString());
						if (selectedJobId && !hasRenderedDetails) {
							return loadDetails(selectedJobId, false, false);
						}
					}).catch(function (error) {
						$rowsEl.html('<tr><td colspan="6">Failed to load queue.</td></tr>');
						$statusEl.text('Failed to load queue: ' + getErrorMessage(error));
					});
				}

				$refreshBtn.on('click', function () {
					loadList();
					if (selectedJobId) {
						loadDetails(selectedJobId, false, false);
					}
				});

				$app.find('[data-role="manual-refresh"]').on('click', function () {
						loadList();
						if (selectedJobId) {
							loadDetails(selectedJobId, false, false);
						}
				});

				$operatorForm.on('click', 'button[type="submit"]', function (event) {
					event.preventDefault();
					var $button = $(this);
					var action = $button.val();
					var confirmMessage = $button.data('confirm') || '';

					if (!action) {
						return;
					}

					if (confirmMessage && !window.confirm(confirmMessage)) {
						return;
					}

					setActionButtonsDisabled(true);
					$statusEl.text('Running queue action...');

					runQueueAction(action).then(function (payload) {
						renderNotice(payload.notice || null);
						if (payload.result && Array.isArray(payload.result.job_ids) && selectedJobId && payload.result.job_ids.indexOf(selectedJobId) !== -1) {
							selectedJobId = null;
							resetDetails();
						}
						setStats(payload.stats || {});
						return loadList().then(function () {
							if (selectedJobId) {
								return loadDetails(selectedJobId, false, false);
							}
						});
					}).catch(function (error) {
						renderNotice({ type: 'error', message: getErrorMessage(error) });
						$statusEl.text('Queue action failed: ' + getErrorMessage(error));
					}).always(function () {
						setActionButtonsDisabled(false);
					});
				});

				document.addEventListener('keydown', function (event) {
					if (event.key === 'Escape') {
						closeLightbox();
					}
				});

				loadList();
				refreshTimer = window.setInterval(loadList, 5000);
				window.addEventListener('beforeunload', function () {
					if (refreshTimer) window.clearInterval(refreshTimer);
					closeLightbox();
				});
			});
		</script>
		<?php
	}
}
