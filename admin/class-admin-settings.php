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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}
		if ( Admin_Helper::verify_post_nonce( 'alorbach_settings_nonce', 'alorbach_settings' ) ) {
			$selling_enabled   = isset( $_POST['alorbach_selling_enabled'] );
			$selling_multiplier = isset( $_POST['alorbach_selling_multiplier'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['alorbach_selling_multiplier'] ) ) : 2.0;
			$selling_multiplier = max( 1.0, $selling_multiplier );
			$debug_enabled     = isset( $_POST['alorbach_debug_enabled'] );
			$google_import_default = isset( $_POST['alorbach_google_import_default'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_google_import_default'] ) ) : 'all';
			$google_import_default = in_array( $google_import_default, array( 'all', 'none' ), true ) ? $google_import_default : 'all';
			$google_model_whitelist = isset( $_POST['alorbach_google_model_whitelist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['alorbach_google_model_whitelist'] ) ) : '';

			$rate_limit_window    = isset( $_POST['alorbach_rate_limit_window'] ) ? max( 10, min( 3600, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_window'] ) ) ) ) : 60;
			$rate_limit_chat      = isset( $_POST['alorbach_rate_limit_chat'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_chat'] ) ) ) ) : 100;
			$rate_limit_images    = isset( $_POST['alorbach_rate_limit_images'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_images'] ) ) ) ) : 30;
			$rate_limit_transcribe = isset( $_POST['alorbach_rate_limit_transcribe'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_transcribe'] ) ) ) ) : 30;
			$rate_limit_video     = isset( $_POST['alorbach_rate_limit_video'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_video'] ) ) ) ) : 10;

			update_option( 'alorbach_selling_enabled', $selling_enabled );
			update_option( 'alorbach_selling_multiplier', $selling_multiplier );
			update_option( 'alorbach_debug_enabled', $debug_enabled );
			update_option( 'alorbach_google_import_default', $google_import_default );
			update_option( 'alorbach_google_model_whitelist', $google_model_whitelist );
			update_option( 'alorbach_rate_limit_window', $rate_limit_window );
			update_option( 'alorbach_rate_limit_chat', $rate_limit_chat );
			update_option( 'alorbach_rate_limit_images', $rate_limit_images );
			update_option( 'alorbach_rate_limit_transcribe', $rate_limit_transcribe );
			update_option( 'alorbach_rate_limit_video', $rate_limit_video );
			$monthly_quota_uc = isset( $_POST['alorbach_monthly_quota_uc'] ) ? max( 0, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_monthly_quota_uc'] ) ) ) : 0;
			update_option( 'alorbach_monthly_quota_uc', $monthly_quota_uc );
			Admin_Helper::render_notice( __( 'Settings saved.', 'alorbach-ai-gateway' ) );
		}

		$selling_enabled        = (bool) get_option( 'alorbach_selling_enabled', false );
		$selling_multiplier     = (float) get_option( 'alorbach_selling_multiplier', 2.0 );
		$debug_enabled          = (bool) get_option( 'alorbach_debug_enabled', false );
		$google_import_default  = get_option( 'alorbach_google_import_default', 'all' );
		$google_model_whitelist = get_option( 'alorbach_google_model_whitelist', \Alorbach\AIGateway\Model_Importer::GOOGLE_MODEL_WHITELIST_DEFAULT );
		$rate_limit_window      = (int) get_option( 'alorbach_rate_limit_window', 60 );
		$rate_limit_chat        = (int) get_option( 'alorbach_rate_limit_chat', 100 );
		$rate_limit_images      = (int) get_option( 'alorbach_rate_limit_images', 30 );
		$rate_limit_transcribe  = (int) get_option( 'alorbach_rate_limit_transcribe', 30 );
		$rate_limit_video       = (int) get_option( 'alorbach_rate_limit_video', 10 );
		$monthly_quota_uc       = (int) get_option( 'alorbach_monthly_quota_uc', 0 );
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
							<p class="description"><?php esc_html_e( 'When enabled, import API responses include a _debug object and the browser console logs payload and response details. Video errors also include debug info (API response code, headers, body preview) in the console.', 'alorbach-ai-gateway' ); ?></p>
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

				<h2><?php esc_html_e( 'Rate Limiting', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Limit how many requests each logged-in user can make per time window. Set to a high number to effectively disable limiting for a specific endpoint.', 'alorbach-ai-gateway' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="alorbach_rate_limit_window"><?php esc_html_e( 'Window (seconds)', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<input type="number" name="alorbach_rate_limit_window" id="alorbach_rate_limit_window" value="<?php echo esc_attr( $rate_limit_window ); ?>" min="10" max="3600" step="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Rolling time window in seconds (10–3600). Default: 60.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_rate_limit_chat"><?php esc_html_e( 'Chat requests / window', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<input type="number" name="alorbach_rate_limit_chat" id="alorbach_rate_limit_chat" value="<?php echo esc_attr( $rate_limit_chat ); ?>" min="1" max="9999" step="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Max chat completions per user per window. Default: 100.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
					<th scope="row"><label for="alorbach_rate_limit_images"><?php esc_html_e( 'Image requests / window', 'alorbach-ai-gateway' ); ?></label></th>
					<td>
						<input type="number" name="alorbach_rate_limit_images" id="alorbach_rate_limit_images" value="<?php echo esc_attr( $rate_limit_images ); ?>" min="1" max="9999" step="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Max image generation requests per user per window. Default: 30.', 'alorbach-ai-gateway' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alorbach_rate_limit_transcribe"><?php esc_html_e( 'Transcribe requests / window', 'alorbach-ai-gateway' ); ?></label></th>
					<td>
						<input type="number" name="alorbach_rate_limit_transcribe" id="alorbach_rate_limit_transcribe" value="<?php echo esc_attr( $rate_limit_transcribe ); ?>" min="1" max="9999" step="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Max audio transcription requests per user per window. Default: 30.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_rate_limit_video"><?php esc_html_e( 'Video requests / window', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<input type="number" name="alorbach_rate_limit_video" id="alorbach_rate_limit_video" value="<?php echo esc_attr( $rate_limit_video ); ?>" min="1" max="9999" step="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Max video generation requests per user per window. Default: 10.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_monthly_quota_uc"><?php esc_html_e( 'Monthly quota per user (UC)', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<input type="number" name="alorbach_monthly_quota_uc" id="alorbach_monthly_quota_uc" value="<?php echo esc_attr( $monthly_quota_uc ); ?>" min="0" step="1000" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Maximum UC each user may spend per calendar month across all endpoints. 0 = unlimited. 1 Credit = 1,000 UC.', 'alorbach-ai-gateway' ); ?></p>
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
