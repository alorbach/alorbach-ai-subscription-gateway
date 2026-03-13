<?php
/**
 * Admin: User-facing "Mein Guthaben" page and profile section.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_User_Credits
 */
class Admin_User_Credits {

	/**
	 * Register "Mein Guthaben" menu for logged-in users.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'My Credits', 'alorbach-ai-gateway' ),
			__( 'My Credits', 'alorbach-ai-gateway' ),
			'read',
			'alorbach-my-credits',
			array( __CLASS__, 'render_my_credits_page' ),
			'dashicons-admin-generic',
			70
		);
	}

	/**
	 * Render "Mein Guthaben" page.
	 */
	public static function render_my_credits_page() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$balance = \Alorbach\AIGateway\Ledger::get_balance( $user_id );
		$usage   = \Alorbach\AIGateway\Ledger::get_usage_this_month( $user_id );

		$per_page = 20;
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$result   = \Alorbach\AIGateway\Ledger::get_transactions( array(
			'per_page' => $per_page,
			'page'     => $page,
			'user_id'  => $user_id,
		) );
		$rows     = $result['rows'];
		$total    = $result['total'];
		$pages    = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'My AI Credits', 'alorbach-ai-gateway' ); ?></h1>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Balance', 'alorbach-ai-gateway' ); ?></th>
					<td><?php echo esc_html( \Alorbach\AIGateway\User_Display::format_credits( $balance ) ); ?> <span class="description">(<?php echo esc_html( __( 'worth', 'alorbach-ai-gateway' ) . ' ' . \Alorbach\AIGateway\User_Display::format_uc_as_usd( $balance ) ); ?>)</span></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Usage this month', 'alorbach-ai-gateway' ); ?></th>
					<td><?php echo esc_html( \Alorbach\AIGateway\User_Display::format_credits( $usage ) ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Transaction history', 'alorbach-ai-gateway' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No transactions yet.', 'alorbach-ai-gateway' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Type', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'alorbach-ai-gateway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$type_label = self::get_transaction_type_label( $row['transaction_type'] );
							$amount    = (int) $row['uc_amount'];
							$credits   = \Alorbach\AIGateway\User_Display::uc_to_credits( $amount );
							$usd       = \Alorbach\AIGateway\User_Display::format_uc_as_usd( abs( $amount ) );
							$amount_str = $amount >= 0
								? '+' . number_format_i18n( $credits, 2 ) . ' ' . _x( 'Credits', 'unit', 'alorbach-ai-gateway' )
								: number_format_i18n( $credits, 2 ) . ' ' . _x( 'Credits', 'unit', 'alorbach-ai-gateway' );
							$amount_str .= ' (' . $usd . ')';
							?>
							<tr>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
								<td><?php echo esc_html( $type_label ); ?></td>
								<td><?php echo esc_html( $row['model_used'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $amount_str ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'alorbach-ai-gateway' ), number_format_i18n( $total ) ) ); ?></span>
							<span class="pagination-links">
								<?php
								$base_url = add_query_arg( 'page', 'alorbach-my-credits', admin_url( 'admin.php' ) );
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
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get human-readable label for transaction type.
	 *
	 * @param string $type Transaction type.
	 * @return string
	 */
	private static function get_transaction_type_label( $type ) {
		$labels = array(
			'chat_deduction'       => __( 'Chat', 'alorbach-ai-gateway' ),
			'image_deduction'      => __( 'Image', 'alorbach-ai-gateway' ),
			'audio_deduction'      => __( 'Audio', 'alorbach-ai-gateway' ),
			'video_deduction'      => __( 'Video', 'alorbach-ai-gateway' ),
			'admin_credit'         => __( 'Admin credit', 'alorbach-ai-gateway' ),
			'subscription_credit'  => __( 'Subscription', 'alorbach-ai-gateway' ),
			'balance_forward'      => __( 'Balance forward', 'alorbach-ai-gateway' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Render profile section (AI Credits).
	 *
	 * @param \WP_User $user User object.
	 */
	public static function render_profile_section( $user ) {
		if ( ! $user || ! $user->ID ) {
			return;
		}
		$balance = \Alorbach\AIGateway\Ledger::get_balance( $user->ID );
		$usage   = \Alorbach\AIGateway\Ledger::get_usage_this_month( $user->ID );
		?>
		<h2><?php esc_html_e( 'AI Credits', 'alorbach-ai-gateway' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Balance', 'alorbach-ai-gateway' ); ?></th>
				<td><?php echo esc_html( \Alorbach\AIGateway\User_Display::format_credits( $balance ) ); ?> <span class="description">(<?php echo esc_html( __( 'worth', 'alorbach-ai-gateway' ) . ' ' . \Alorbach\AIGateway\User_Display::format_uc_as_usd( $balance ) ); ?>)</span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Usage this month', 'alorbach-ai-gateway' ); ?></th>
				<td><?php echo esc_html( \Alorbach\AIGateway\User_Display::format_credits( $usage ) ); ?></td>
			</tr>
		</table>
		<?php
	}
}
