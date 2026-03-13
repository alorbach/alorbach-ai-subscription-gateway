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
		// Handle CSV download (early exit).
		if ( current_user_can( 'manage_options' )
			&& isset( $_GET['action'] ) && $_GET['action'] === 'download_transactions_csv'
			&& isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'alorbach_download_transactions_csv' )
		) {
			$filter_user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : null;
			self::download_transactions_csv( $filter_user_id );
			exit;
		}

		$filter_user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : null;

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
							<td>
								<?php
								$user_transactions_url = add_query_arg( array( 'page' => 'alorbach-user-balance', 'user_id' => $user->ID ), admin_url( 'admin.php' ) );
								?>
								<a href="<?php echo esc_url( $user_transactions_url ); ?>"><?php echo esc_html( $user->user_login ); ?></a> (<?php echo esc_html( $user->user_email ); ?>)
							</td>
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

			<h2><?php esc_html_e( 'All Transactions', 'alorbach-ai-gateway' ); ?></h2>
			<?php
			$per_page = 50;
			$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
			$args     = array( 'per_page' => $per_page, 'page' => $page );
			if ( $filter_user_id > 0 ) {
				$args['user_id'] = $filter_user_id;
			}
			$result   = \Alorbach\AIGateway\Ledger::get_transactions( $args );
			$rows     = $result['rows'];
			$total    = $result['total'];
			$pages    = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
			$base_url = add_query_arg( 'page', 'alorbach-user-balance', admin_url( 'admin.php' ) );
			$csv_url  = add_query_arg( array(
				'action'   => 'download_transactions_csv',
				'_wpnonce' => wp_create_nonce( 'alorbach_download_transactions_csv' ),
			), $base_url );
			if ( $filter_user_id > 0 ) {
				$csv_url = add_query_arg( 'user_id', $filter_user_id, $csv_url );
			}
			$filtered_user = $filter_user_id > 0 ? get_user_by( 'id', $filter_user_id ) : null;
			?>
			<p>
				<label for="alorbach_filter_user"><?php esc_html_e( 'Filter:', 'alorbach-ai-gateway' ); ?></label>
				<select id="alorbach_filter_user" onchange="if(this.value){window.location.href=this.value;}">
					<option value="<?php echo esc_url( $base_url ); ?>" <?php selected( ! $filter_user_id ); ?>><?php esc_html_e( 'All users', 'alorbach-ai-gateway' ); ?></option>
					<?php foreach ( $users as $u ) :
						$u_url = add_query_arg( 'user_id', $u->ID, $base_url );
						?>
						<option value="<?php echo esc_url( $u_url ); ?>" <?php selected( $filter_user_id, $u->ID ); ?>><?php echo esc_html( $u->user_login . ' (' . $u->user_email . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<a href="<?php echo esc_url( $csv_url ); ?>" class="button"><?php esc_html_e( 'Download CSV', 'alorbach-ai-gateway' ); ?></a>
			</p>
			<?php if ( $filtered_user ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Showing transactions for %s only.', 'alorbach-ai-gateway' ), $filtered_user->user_login ) ); ?> <a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'View all', 'alorbach-ai-gateway' ); ?></a></p>
			<?php endif; ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php echo esc_html( $filter_user_id > 0 ? __( 'No transactions for this user.', 'alorbach-ai-gateway' ) : __( 'No transactions yet.', 'alorbach-ai-gateway' ) ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'User', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Type', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'AI Budget (UC)', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'User Charge (UC)', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Credits', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Date', 'alorbach-ai-gateway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$user = $row['user_id'] ? get_user_by( 'id', $row['user_id'] ) : null;
							$user_login = $user ? $user->user_login : '';
							$user_email = $user ? $user->user_email : '';
							$credits = \Alorbach\AIGateway\User_Display::uc_to_credits( $row['uc_amount'] );
							$api_cost_uc = isset( $row['api_cost_uc'] ) ? $row['api_cost_uc'] : null;
							?>
							<tr>
								<td><?php echo esc_html( $row['transaction_id'] ); ?></td>
								<td><?php echo esc_html( $user_login ? $user_login . ' (' . $user_email . ')' : $row['user_id'] ); ?></td>
								<td><?php echo esc_html( $row['transaction_type'] ); ?></td>
								<td><?php echo esc_html( $row['model_used'] ?? '—' ); ?></td>
								<td><?php echo $api_cost_uc !== null && $api_cost_uc !== '' ? esc_html( number_format_i18n( (int) $api_cost_uc ) ) : '—'; ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['uc_amount'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $credits, 2 ) ); ?></td>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
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
								$pagination_base = add_query_arg( 'page', 'alorbach-user-balance', admin_url( 'admin.php' ) );
								if ( $filter_user_id > 0 ) {
									$pagination_base = add_query_arg( 'user_id', $filter_user_id, $pagination_base );
								}
								if ( $page > 1 ) {
									$prev_url = add_query_arg( 'paged', $page - 1, $pagination_base );
									echo '<a class="prev-page button" href="' . esc_url( $prev_url ) . '">' . esc_html__( '&laquo;', 'alorbach-ai-gateway' ) . '</a> ';
								}
								echo '<span class="paging-input">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'alorbach-ai-gateway' ), $page, $pages ) ) . '</span>';
								if ( $page < $pages ) {
									$next_url = add_query_arg( 'paged', $page + 1, $pagination_base );
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
	 * Output transactions as CSV.
	 *
	 * @param int|null $user_id Optional. Filter by user ID. Null = all users.
	 */
	private static function download_transactions_csv( $user_id = null ) {
		$args = array( 'per_page' => 999999, 'page' => 1 );
		if ( $user_id > 0 ) {
			$args['user_id'] = $user_id;
		}
		$result = \Alorbach\AIGateway\Ledger::get_transactions( $args );
		$rows   = $result['rows'];

		$filename = 'alorbach-transactions-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( $out === false ) {
			return;
		}

		fputcsv( $out, array( 'transaction_id', 'user_id', 'user_login', 'user_email', 'transaction_type', 'model_used', 'api_cost_uc', 'user_charge_uc', 'credits', 'created_at' ) );

		foreach ( $rows as $row ) {
			$user      = $row['user_id'] ? get_user_by( 'id', $row['user_id'] ) : null;
			$user_login = $user ? $user->user_login : '';
			$user_email = $user ? $user->user_email : '';
			$credits   = \Alorbach\AIGateway\User_Display::uc_to_credits( $row['uc_amount'] );
			$api_cost_uc = isset( $row['api_cost_uc'] ) && $row['api_cost_uc'] !== null && $row['api_cost_uc'] !== '' ? $row['api_cost_uc'] : '';
			fputcsv( $out, array(
				$row['transaction_id'],
				$row['user_id'],
				$user_login,
				$user_email,
				$row['transaction_type'],
				$row['model_used'] ?? '',
				$api_cost_uc,
				$row['uc_amount'],
				$credits,
				$row['created_at'],
			) );
		}
		fclose( $out );
	}
}
