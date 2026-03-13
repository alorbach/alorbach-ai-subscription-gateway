<?php
/**
 * Admin: General settings.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Settings
 */
class Admin_Settings {

	/**
	 * Render Settings page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_settings_nonce'] ) ), 'alorbach_settings' ) ) {
			$selling_enabled  = isset( $_POST['alorbach_selling_enabled'] );
			$selling_multiplier = isset( $_POST['alorbach_selling_multiplier'] ) ? (float) $_POST['alorbach_selling_multiplier'] : 2.0;
			$selling_multiplier = max( 1.0, $selling_multiplier );

			update_option( 'alorbach_selling_enabled', $selling_enabled );
			update_option( 'alorbach_selling_multiplier', $selling_multiplier );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$selling_enabled  = (bool) get_option( 'alorbach_selling_enabled', false );
		$selling_multiplier = (float) get_option( 'alorbach_selling_multiplier', 2.0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'alorbach-ai-gateway' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( 'alorbach_settings', 'alorbach_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Selling / Markup', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When disabled, users pay exactly API cost (pass-through). When enabled, users pay a markup on API usage.', 'alorbach-ai-gateway' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable selling', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<label for="alorbach_selling_enabled">
								<input type="checkbox" name="alorbach_selling_enabled" id="alorbach_selling_enabled" value="1" <?php checked( $selling_enabled ); ?> />
								<?php esc_html_e( 'Charge markup on API usage', 'alorbach-ai-gateway' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_selling_multiplier"><?php esc_html_e( 'Markup multiplier', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<input type="number" name="alorbach_selling_multiplier" id="alorbach_selling_multiplier" value="<?php echo esc_attr( $selling_multiplier ); ?>" min="1" max="100" step="0.1" class="small-text" />
							<p class="description"><?php esc_html_e( '2.0 = 100% profit (user pays 2× API cost).', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'alorbach-ai-gateway' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}
}
