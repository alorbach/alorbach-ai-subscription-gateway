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
			$selling_enabled   = isset( $_POST['alorbach_selling_enabled'] );
			$selling_multiplier = isset( $_POST['alorbach_selling_multiplier'] ) ? (float) $_POST['alorbach_selling_multiplier'] : 2.0;
			$selling_multiplier = max( 1.0, $selling_multiplier );
			$debug_enabled     = isset( $_POST['alorbach_debug_enabled'] );
			$google_import_default = isset( $_POST['alorbach_google_import_default'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_google_import_default'] ) ) : 'all';
			$google_import_default = in_array( $google_import_default, array( 'all', 'none' ), true ) ? $google_import_default : 'all';
			$google_model_whitelist = isset( $_POST['alorbach_google_model_whitelist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['alorbach_google_model_whitelist'] ) ) : '';

			update_option( 'alorbach_selling_enabled', $selling_enabled );
			update_option( 'alorbach_selling_multiplier', $selling_multiplier );
			update_option( 'alorbach_debug_enabled', $debug_enabled );
			update_option( 'alorbach_google_import_default', $google_import_default );
			update_option( 'alorbach_google_model_whitelist', $google_model_whitelist );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$selling_enabled   = (bool) get_option( 'alorbach_selling_enabled', false );
		$selling_multiplier = (float) get_option( 'alorbach_selling_multiplier', 2.0 );
		$debug_enabled     = (bool) get_option( 'alorbach_debug_enabled', false );
		$google_import_default = get_option( 'alorbach_google_import_default', 'all' );
		$google_model_whitelist = get_option( 'alorbach_google_model_whitelist', \Alorbach\AIGateway\Model_Importer::GOOGLE_MODEL_WHITELIST_DEFAULT );
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

				<h2><?php esc_html_e( 'Debug', 'alorbach-ai-gateway' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug mode', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<label for="alorbach_debug_enabled">
								<input type="checkbox" name="alorbach_debug_enabled" id="alorbach_debug_enabled" value="1" <?php checked( $debug_enabled ); ?> />
								<?php esc_html_e( 'Enable debug output for model import', 'alorbach-ai-gateway' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, import API responses include a _debug object and the browser console logs payload and response details.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Model Import (Google)', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Google lists all catalog models; your account may not have access to all. Check AI Studio Rate Limit to see which models you can use.', 'alorbach-ai-gateway' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Google import default', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="alorbach_google_import_default" value="all" <?php checked( $google_import_default, 'all' ); ?> /> <?php esc_html_e( 'All models checked', 'alorbach-ai-gateway' ); ?></label><br />
								<label><input type="radio" name="alorbach_google_import_default" value="none" <?php checked( $google_import_default, 'none' ); ?> /> <?php esc_html_e( 'None checked (user selects from AI Studio Rate Limit)', 'alorbach-ai-gateway' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_google_model_whitelist"><?php esc_html_e( 'Google model whitelist', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<textarea name="alorbach_google_model_whitelist" id="alorbach_google_model_whitelist" rows="4" class="large-text code"><?php echo esc_textarea( $google_model_whitelist ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Comma-separated model IDs (e.g. gemini-2.5-flash, gemini-2.5-flash-lite). When set, only these models are shown in the import modal.', 'alorbach-ai-gateway' ); ?></p>
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
