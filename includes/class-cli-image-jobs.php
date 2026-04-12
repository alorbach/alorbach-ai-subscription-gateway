<?php
/**
 * WP-CLI commands for image job queue maintenance.
 *
 * @package Alorbach\AIGateway\CLI
 */

namespace Alorbach\AIGateway\CLI;

use Alorbach\AIGateway\Image_Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Image_Jobs_Command
 */
class Image_Jobs_Command {

	/**
	 * Clear image jobs by status or queue scope.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Clear jobs that match a single status, for example failed or completed.
	 *
	 * [--stalled]
	 * : Clear stalled jobs.
	 *
	 * [--all]
	 * : Clear all indexed jobs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp alorbach image-jobs clear --status=failed
	 *     wp alorbach image-jobs clear --stalled
	 *     wp alorbach image-jobs clear --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function clear( $args, $assoc_args ) {
		$status = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : '';
		$is_stalled = ! empty( $assoc_args['stalled'] );
		$is_all = ! empty( $assoc_args['all'] );

		if ( $is_all ) {
			$result = Image_Jobs::delete_all_jobs(
				array(
					'delete_preview_assets' => true,
					'delete_reference_assets' => true,
					'delete_final_assets' => true,
				)
			);
			$this->print_summary( 'all', $result );
			return;
		}

		if ( $is_stalled ) {
			$result = Image_Jobs::delete_stalled_jobs(
				array(
					'delete_preview_assets' => true,
					'delete_reference_assets' => true,
					'delete_final_assets' => true,
				)
			);
			$this->print_summary( 'stalled', $result );
			return;
		}

		if ( '' === $status ) {
			\WP_CLI::error( 'Pass --status=<status>, --stalled, or --all.' );
		}

		$delete_final_assets = in_array( $status, array( 'failed', 'stalled' ), true );
		$result = Image_Jobs::delete_jobs_by_status(
			array( $status ),
			array(
				'delete_preview_assets' => true,
				'delete_reference_assets' => true,
				'delete_final_assets' => $delete_final_assets,
			)
		);

		$this->print_summary( $status, $result );
	}

	/**
	 * Prune stale queue index entries.
	 *
	 * ## EXAMPLES
	 *
	 *     wp alorbach image-jobs prune-index
	 *
	 * @return void
	 */
	public function prune_index() {
		$pruned = Image_Jobs::prune_job_index();
		\WP_CLI::success( sprintf( 'Pruned %d stale queue index entr%s.', $pruned, 1 === $pruned ? 'y' : 'ies' ) );
	}

	/**
	 * Print a concise summary after cleanup.
	 *
	 * @param string $label  Action label.
	 * @param array  $result Cleanup result.
	 * @return void
	 */
	private function print_summary( $label, $result ) {
		$job_count = isset( $result['job_count'] ) ? (int) $result['job_count'] : 0;
		$attachment_count = isset( $result['attachment_count'] ) ? (int) $result['attachment_count'] : 0;
		$message = sprintf( 'Cleared %d %s job%s.', $job_count, $label, 1 === $job_count ? '' : 's' );
		if ( $attachment_count > 0 ) {
			$message .= ' ' . sprintf( 'Removed %d attachment%s.', $attachment_count, 1 === $attachment_count ? '' : 's' );
		}

		\WP_CLI::success( $message );
	}
}
