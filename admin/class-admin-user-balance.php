<?php
/**
 * Admin: User balance management.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_User_Balance
 */
class Admin_User_Balance {

	/**
	 * Render User Balance page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_balance_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_balance_nonce'] ) ), 'alorbach_balance' ) ) {
			$user_id     = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
			$amount      = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
			$amount_unit = isset( $_POST['amount_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['amount_unit'] ) ) : 'uc';
			$action      = isset( $_POST['amount_action'] ) ? sanitize_text_field( wp_unslash( $_POST['amount_action'] ) ) : 'add';

			$allowed_units = array( 'uc', 'credits', 'usd' );
			if ( ! in_array( $amount_unit, $allowed_units, true ) ) {
				$amount_unit = 'uc';
			}

			if ( $user_id && $amount > 0 ) {
				switch ( $amount_unit ) {
					case 'credits':
						$uc = \Alorbach\AIGateway\User_Display::credits_to_uc( $amount );
						break;
					case 'usd':
						$uc = \Alorbach\AIGateway\User_Display::usd_to_uc( $amount );
						break;
					default:
						$uc = (int) $amount;
				}
				if ( $uc > 0 ) {
					$uc = $action === 'subtract' ? -$uc : $uc;
					\Alorbach\AIGateway\Ledger::insert_transaction( $user_id, 'admin_credit', null, $uc );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Balance updated.', 'alorbach-ai-gateway' ) . '</p></div>';
				}
			}
		}

		$users = get_users( array( 'number' => 100, 'orderby' => 'login' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'User Balance', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description"><?php esc_html_e( '1 Credit = 1,000 UC. 1 USD = 1,000,000 UC.', 'alorbach-ai-gateway' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Balance (UC)', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Balance (Credits)', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Balance ($)', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $users as $user ) :
						$balance = \Alorbach\AIGateway\Ledger::get_balance( $user->ID );
						$credits = \Alorbach\AIGateway\User_Display::uc_to_credits( $balance );
						?>
						<tr>
							<td><?php echo esc_html( $user->user_login ); ?> (<?php echo esc_html( $user->user_email ); ?>)</td>
							<td><?php echo esc_html( number_format_i18n( $balance ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $credits, 2 ) ); ?></td>
							<td><?php echo esc_html( \Alorbach\AIGateway\User_Display::format_uc_as_usd( $balance ) ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'alorbach_balance', 'alorbach_balance_nonce' ); ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
									<input type="number" name="amount" value="0" min="0" step="any" size="8" style="width:80px;" placeholder="0" />
									<select name="amount_unit" style="width:90px;">
										<option value="uc"><?php esc_html_e( 'UC', 'alorbach-ai-gateway' ); ?></option>
										<option value="credits"><?php esc_html_e( 'Credits', 'alorbach-ai-gateway' ); ?></option>
										<option value="usd"><?php esc_html_e( 'USD ($)', 'alorbach-ai-gateway' ); ?></option>
									</select>
									<select name="amount_action">
										<option value="add"><?php esc_html_e( 'Add', 'alorbach-ai-gateway' ); ?></option>
										<option value="subtract"><?php esc_html_e( 'Subtract', 'alorbach-ai-gateway' ); ?></option>
									</select>
									<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Apply', 'alorbach-ai-gateway' ); ?>" />
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
