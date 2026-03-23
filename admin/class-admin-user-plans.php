<?php
/**
 * Admin: User plan assignment.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_User_Plans
 */
class Admin_User_Plans {

	/**
	 * Render User Plans page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}

		if ( Admin_Helper::verify_post_nonce( 'alorbach_user_plans_nonce', 'alorbach_user_plans' ) ) {
			$user_id  = isset( $_POST['user_id'] ) ? (int) wp_unslash( $_POST['user_id'] ) : 0;
			$plan_slug = isset( $_POST['manual_plan_slug'] ) ? sanitize_key( wp_unslash( $_POST['manual_plan_slug'] ) ) : '';

			if ( $user_id > 0 ) {
				\Alorbach\AIGateway\Integration_Service::set_user_manual_plan_slug( $user_id, $plan_slug );
				Admin_Helper::render_notice( __( 'User plan assignment updated.', 'alorbach-ai-gateway' ) );
			}
		}

		$users = get_users(
			array(
				'number'  => -1,
				'orderby' => 'login',
				'fields'  => array( 'ID', 'user_login', 'user_email' ),
			)
		);
		$plans = \Alorbach\AIGateway\Integration_Service::get_public_plans(
			array(
				'include_inactive' => true,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'User Plans', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Assign a manual plan override to a user or clear it to fall back to WooCommerce subscriptions and then Basic.', 'alorbach-ai-gateway' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Resolved plan', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Source', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Manual override', 'alorbach-ai-gateway' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $users as $user ) : ?>
						<?php
						$resolution   = \Alorbach\AIGateway\Integration_Service::get_user_plan_resolution( (int) $user->ID );
						$resolved     = $resolution['plan'];
						$manual_slug  = \Alorbach\AIGateway\Integration_Service::get_user_manual_plan_slug( (int) $user->ID );
						?>
						<tr>
							<td><?php echo esc_html( $user->user_login ); ?> (<?php echo esc_html( $user->user_email ); ?>)</td>
							<td><?php echo esc_html( $resolved['public_name'] ?? $resolved['slug'] ?? '' ); ?> <code><?php echo esc_html( $resolved['slug'] ?? '' ); ?></code></td>
							<td><?php echo esc_html( self::get_source_label( (string) $resolution['source'] ) ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'alorbach_user_plans', 'alorbach_user_plans_nonce' ); ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
									<select name="manual_plan_slug">
										<option value=""><?php esc_html_e( 'No override', 'alorbach-ai-gateway' ); ?></option>
										<?php foreach ( $plans as $plan ) : ?>
											<option value="<?php echo esc_attr( $plan['slug'] ); ?>" <?php selected( $manual_slug, $plan['slug'] ); ?>>
												<?php echo esc_html( $plan['public_name'] ?: $plan['slug'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
							</td>
							<td>
									<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Save', 'alorbach-ai-gateway' ); ?>" />
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $users ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No users found.', 'alorbach-ai-gateway' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Human label for plan source.
	 *
	 * @param string $source Resolution source.
	 * @return string
	 */
	private static function get_source_label( $source ) {
		switch ( $source ) {
			case 'manual':
				return __( 'Manual override', 'alorbach-ai-gateway' );
			case 'subscription':
				return __( 'WooCommerce subscription', 'alorbach-ai-gateway' );
			default:
				return __( 'Basic fallback', 'alorbach-ai-gateway' );
		}
	}
}
