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
