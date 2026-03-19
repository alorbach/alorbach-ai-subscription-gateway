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
						$type_label = Admin_Helper::get_transaction_type_label( $row['transaction_type'] );
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
				<?php
				$base_url = add_query_arg( 'page', 'alorbach-my-credits', admin_url( 'admin.php' ) );
				Admin_Helper::render_pagination( $page, $pages, $total, $base_url );
				?>
			<?php endif; ?>
		</div>
		<?php
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
