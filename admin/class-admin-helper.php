<?php
/**
 * Admin helper utilities shared across admin pages.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Helper
 *
 * Shared utilities: nonce verification, admin notices, pagination, and
 * transaction-type labels.
 */
class Admin_Helper {

	/**
	 * Verify a POST nonce.
	 *
	 * Replaces the repeated pattern:
	 *   isset( $_POST[$field] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[$field] ) ), $action )
	 *
	 * @param string $field  POST field name that holds the nonce value.
	 * @param string $action Nonce action string.
	 * @return bool
	 */
	public static function verify_post_nonce( $field, $action ) {
		return isset( $_POST[ $field ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), $action );
	}

	/**
	 * Output a WordPress-style admin notice.
	 *
	 * @param string $message     Already-translated message text (will be escaped on output).
	 * @param string $type        Notice type: 'success' | 'error' | 'warning' | 'info'.
	 * @param bool   $dismissible Whether to add the is-dismissible class.
	 */
	public static function render_notice( $message, $type = 'success', $dismissible = true ) {
		$class = 'notice notice-' . esc_attr( $type );
		if ( $dismissible ) {
			$class .= ' is-dismissible';
		}
		echo '<div class="' . $class . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Output WordPress tablenav pagination (prev / page-info / next).
	 *
	 * Only renders when $pages > 1.
	 *
	 * @param int    $page     Current 1-based page number.
	 * @param int    $pages    Total number of pages.
	 * @param int    $total    Total number of items (for the "N items" label).
	 * @param string $base_url Base URL already including all active filter params.
	 *                         'paged' will be appended by this method.
	 */
	public static function render_pagination( $page, $pages, $total, $base_url ) {
		if ( $pages <= 1 ) {
			return;
		}
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'alorbach-ai-gateway' ), number_format_i18n( $total ) ) ); ?></span>
				<span class="pagination-links">
					<?php
					if ( $page > 1 ) {
						$prev_url = add_query_arg( 'paged', $page - 1, $base_url );
						echo '<a class="prev-page button" href="' . esc_url( $prev_url ) . '">' . esc_html__( '&laquo;', 'alorbach-ai-gateway' ) . '</a> ';
					}
					echo '<span class="paging-input">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'alorbach-ai-gateway' ), $page, $pages ) ) . '</span>';
					if ( $page < $pages ) {
						$next_url = add_query_arg( 'paged', $page + 1, $base_url );
						echo ' <a class="next-page button" href="' . esc_url( $next_url ) . '">' . esc_html__( '&raquo;', 'alorbach-ai-gateway' ) . '</a>';
					}
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Get a human-readable label for a ledger transaction type.
	 *
	 * @param string $type Raw transaction type (e.g. 'chat_deduction').
	 * @return string Translated label, or the raw type if no mapping exists.
	 */
	public static function get_transaction_type_label( $type ) {
		$labels = array(
			'chat_deduction'      => __( 'Chat', 'alorbach-ai-gateway' ),
			'image_deduction'     => __( 'Image', 'alorbach-ai-gateway' ),
			'audio_deduction'     => __( 'Audio', 'alorbach-ai-gateway' ),
			'video_deduction'     => __( 'Video', 'alorbach-ai-gateway' ),
			'admin_credit'        => __( 'Admin credit', 'alorbach-ai-gateway' ),
			'subscription_credit' => __( 'Subscription', 'alorbach-ai-gateway' ),
			'balance_forward'     => __( 'Balance forward', 'alorbach-ai-gateway' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}
}
