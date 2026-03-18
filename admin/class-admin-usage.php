<?php
/**
 * Admin: Usage overview (current month).
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Usage
 */
class Admin_Usage {

	/**
	 * Render Usage page.
	 */
	public static function render() {
		$users = get_users( array( 'number' => -1, 'orderby' => 'login' ) );
		$month = gmdate( 'Y-m' );
		$all_usage = \Alorbach\AIGateway\Ledger::get_all_usage_this_month();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Usage (Current Month)', 'alorbach-ai-gateway' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Month: %s', 'alorbach-ai-gateway' ), $month ) ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Usage (UC)', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Usage (Credits)', 'alorbach-ai-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $users as $user ) :
					$usage   = isset( $all_usage[ $user->ID ] ) ? $all_usage[ $user->ID ] : 0;
						$credits = \Alorbach\AIGateway\User_Display::uc_to_credits( $usage );
						?>
						<tr>
							<td><?php echo esc_html( $user->user_login ); ?> (<?php echo esc_html( $user->user_email ); ?>)</td>
							<td><?php echo esc_html( number_format_i18n( $usage ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $credits, 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
