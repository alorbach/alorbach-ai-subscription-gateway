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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}
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

		if ( Admin_Helper::verify_post_nonce( 'alorbach_balance_nonce', 'alorbach_balance' ) ) {
			$user_id     = isset( $_POST['user_id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) : 0;
			$amount      = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0;
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
					Admin_Helper::render_notice( __( 'Balance updated.', 'alorbach-ai-gateway' ) );
				}
			}
		}

		$users        = get_users( array( 'number' => -1, 'orderby' => 'login' ) );
		$all_balances = \Alorbach\AIGateway\Ledger::get_all_balances();
		$all_usage    = \Alorbach\AIGateway\Ledger::get_all_usage_this_month();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'User Balance', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description"><?php esc_html_e( '1 Credit = 1,000 UC. 1 USD = 1,000,000 UC.', 'alorbach-ai-gateway' ); ?></p>
			<style>
			.alorbach-user-balance-table-wrap { max-width: 100%; overflow-x: auto; margin-bottom: 20px; }
			.alorbach-user-balance-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
			.alorbach-user-balance-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
			@media (max-width: 782px) {
				.alorbach-user-balance-table-wrap { overflow-x: visible; }
				.alorbach-user-balance-form { flex-direction: column; align-items: stretch; }
				.alorbach-user-balance-form input[type="number"],
				.alorbach-user-balance-form select,
				.alorbach-user-balance-form .button,
				.alorbach-user-balance-filters select,
				.alorbach-user-balance-filters .button { width: 100% !important; max-width: 100%; }
				.alorbach-user-balance-filters { align-items: stretch; }
				.alorbach-user-balance-table-wrap table,
				.alorbach-user-balance-table-wrap tbody,
				.alorbach-user-balance-table-wrap tr,
				.alorbach-user-balance-table-wrap td { display: block; width: 100%; box-sizing: border-box; }
				.alorbach-user-balance-table-wrap table.widefat { border: 0; background: transparent; box-shadow: none; }
				.alorbach-user-balance-table-wrap table.widefat thead { display: none; }
				.alorbach-user-balance-table-wrap table.widefat tbody { display: grid; gap: 12px; }
				.alorbach-user-balance-table-wrap table.widefat tr {
					margin: 0;
					padding: 14px;
					border: 1px solid #dcdcde;
					border-radius: 8px;
					background: #fff;
				}
				.alorbach-user-balance-table-wrap table.widefat td {
					padding: 8px 0;
					border: 0;
				}
				.alorbach-user-balance-table-wrap table.widefat td::before {
					content: attr(data-label);
					display: block;
					margin-bottom: 4px;
					font-size: 12px;
					font-weight: 600;
					color: #50575e;
					text-transform: uppercase;
					letter-spacing: .04em;
				}
			}
			</style>
			<div class="alorbach-user-balance-table-wrap">
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
						$balance = isset( $all_balances[ $user->ID ] ) ? $all_balances[ $user->ID ] : 0;
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'User', 'alorbach-ai-gateway' ); ?>">
								<?php
								$user_transactions_url = add_query_arg( array( 'page' => 'alorbach-user-balance', 'user_id' => $user->ID ), admin_url( 'admin.php' ) );
								?>
								<a href="<?php echo esc_url( $user_transactions_url ); ?>"><?php echo esc_html( $user->user_login ); ?></a> (<?php echo esc_html( $user->user_email ); ?>)
							</td>
							<td data-label="<?php esc_attr_e( 'Balance (UC)', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( number_format_i18n( $balance ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Balance (Credits)', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( self::format_credits_str( $balance ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Balance ($)', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( \Alorbach\AIGateway\User_Display::format_uc_as_usd( $balance ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Actions', 'alorbach-ai-gateway' ); ?>">
								<form method="post" class="alorbach-user-balance-form">
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

			<h2><?php esc_html_e( 'Monthly Usage', 'alorbach-ai-gateway' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Month: %s', 'alorbach-ai-gateway' ), gmdate( 'Y-m' ) ) ); ?></p>
			<div class="alorbach-user-balance-table-wrap">
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
						$usage = isset( $all_usage[ $user->ID ] ) ? $all_usage[ $user->ID ] : 0;
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'User', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( $user->user_login ); ?> (<?php echo esc_html( $user->user_email ); ?>)</td>
							<td data-label="<?php esc_attr_e( 'Usage (UC)', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( number_format_i18n( $usage ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Usage (Credits)', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( self::format_credits_str( $usage ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

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
			$csv_url  = add_query_arg(
				array(
					'action'   => 'download_transactions_csv',
					'_wpnonce' => wp_create_nonce( 'alorbach_download_transactions_csv' ),
				),
				$base_url
			);
			if ( $filter_user_id > 0 ) {
				$csv_url = add_query_arg( 'user_id', $filter_user_id, $csv_url );
			}
			$filtered_user = $filter_user_id > 0 ? get_user_by( 'id', $filter_user_id ) : null;
			?>
			<p class="alorbach-user-balance-filters">
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
				<div class="alorbach-user-balance-table-wrap">
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
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$row_user    = self::resolve_row_user( $row );
							$user_login  = $row_user['login'];
							$user_email  = $row_user['email'];
							$api_cost_uc = self::get_row_api_cost_uc( $row );
							?>
							<tr>
								<td data-label="<?php esc_attr_e( 'ID', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( $row['transaction_id'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'User', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( $user_login ? $user_login . ' (' . $user_email . ')' : $row['user_id'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Type', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( Admin_Helper::get_transaction_type_label( $row['transaction_type'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Model', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( $row['model_used'] ?? '-' ); ?></td>
								<td data-label="<?php esc_attr_e( 'AI Budget (UC)', 'alorbach-ai-gateway' ); ?>"><?php echo $api_cost_uc !== null ? esc_html( number_format_i18n( $api_cost_uc ) ) : '-'; ?></td>
								<td data-label="<?php esc_attr_e( 'User Charge (UC)', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( number_format_i18n( $row['uc_amount'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Credits', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( self::format_credits_str( $row['uc_amount'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Date', 'alorbach-ai-gateway' ); ?>"><?php echo esc_html( $row['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php
				$pagination_url = $filter_user_id > 0 ? add_query_arg( 'user_id', $filter_user_id, $base_url ) : $base_url;
				Admin_Helper::render_pagination( $page, $pages, $total, $pagination_url );
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resolve user login/email from a transaction row.
	 *
	 * @param array $row Transaction row from Ledger::get_transactions().
	 * @return array { login: string, email: string }
	 */
	private static function resolve_row_user( array $row ) : array {
		$user  = $row['user_id'] ? get_user_by( 'id', $row['user_id'] ) : null;
		return array(
			'login' => $user ? $user->user_login : '',
			'email' => $user ? $user->user_email : '',
		);
	}

	/**
	 * Convert a UC amount to a localised credits string (2 decimal places).
	 *
	 * @param int $uc Unit-Credits amount.
	 * @return string e.g. "1,234.56"
	 */
	private static function format_credits_str( int $uc ) : string {
		return number_format_i18n( \Alorbach\AIGateway\User_Display::uc_to_credits( $uc ), 2 );
	}

	/**
	 * Extract the api_cost_uc value from a transaction row.
	 *
	 * Returns null when the field is absent or empty so callers can render '-' / '' consistently.
	 *
	 * @param array $row Transaction row from Ledger::get_transactions().
	 * @return int|null
	 */
	private static function get_row_api_cost_uc( array $row ) : ?int {
		if ( ! isset( $row['api_cost_uc'] ) || $row['api_cost_uc'] === null || $row['api_cost_uc'] === '' ) {
			return null;
		}
		return (int) $row['api_cost_uc'];
	}

	/**
	 * Output transactions as CSV.
	 *
	 * @param int|null $user_id Optional. Filter by user ID. Null = all users.
	 */
	private static function download_transactions_csv( $user_id = null ) {
		$batch_size = 500;
		$page       = 1;

		$filename = 'alorbach-transactions-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		$out = fopen( 'php://output', 'w' );
		if ( $out === false ) {
			return;
		}

		fputcsv( $out, array( 'transaction_id', 'user_id', 'user_login', 'user_email', 'transaction_type', 'model_used', 'api_cost_uc', 'user_charge_uc', 'credits', 'created_at' ) );

		do {
			$args = array( 'per_page' => $batch_size, 'page' => $page );
			if ( $user_id > 0 ) {
				$args['user_id'] = $user_id;
			}
			$result = \Alorbach\AIGateway\Ledger::get_transactions( $args );
			$rows   = $result['rows'];

			foreach ( $rows as $row ) {
				$row_user    = self::resolve_row_user( $row );
				$user_login  = $row_user['login'];
				$user_email  = $row_user['email'];
				$api_cost_uc = self::get_row_api_cost_uc( $row );
				fputcsv(
					$out,
					array(
						$row['transaction_id'],
						$row['user_id'],
						$user_login,
						$user_email,
						$row['transaction_type'],
						$row['model_used'] ?? '',
						$api_cost_uc !== null ? $api_cost_uc : '',
						$row['uc_amount'],
						\Alorbach\AIGateway\User_Display::uc_to_credits( $row['uc_amount'] ),
						$row['created_at'],
					)
				);
			}
			$page++;
		} while ( count( $rows ) >= $batch_size );

		fclose( $out );
	}
}
