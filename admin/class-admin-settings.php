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
	 * Migration flag option.
	 */
	private const MIGRATION_FLAG = 'alorbach_general_defaults_migrated';

	/**
	 * Tab slugs.
	 *
	 * @return array<string,string>
	 */
	private static function get_tabs() {
		return array(
			'general-defaults' => __( 'General Defaults', 'alorbach-ai-gateway' ),
			'rate-limits'      => __( 'Rate Limits & Quotas', 'alorbach-ai-gateway' ),
			'billing'          => __( 'Billing & Account URLs', 'alorbach-ai-gateway' ),
			'providers'        => __( 'Providers / Import', 'alorbach-ai-gateway' ),
			'advanced'         => __( 'Advanced', 'alorbach-ai-gateway' ),
		);
	}

	/**
	 * Migrate legacy demo-owned defaults to general settings.
	 */
	public static function maybe_migrate_general_defaults() {
		if ( get_option( self::MIGRATION_FLAG, false ) ) {
			return;
		}

		$migrations = array(
			'alorbach_default_chat_model' => 'alorbach_demo_default_chat_model',
			'alorbach_default_max_tokens' => 'alorbach_demo_default_max_tokens',
			'alorbach_max_tokens_options' => 'alorbach_demo_max_tokens_options',
			'alorbach_default_image_size' => 'alorbach_demo_default_image_model',
			'alorbach_default_audio_model' => 'alorbach_demo_default_audio_model',
			'alorbach_default_video_model' => 'alorbach_demo_default_video_model',
		);

		foreach ( $migrations as $new_key => $legacy_key ) {
			$current_value = get_option( $new_key, null );
			if ( null !== $current_value ) {
				continue;
			}
			$legacy_value = get_option( $legacy_key, null );
			if ( null !== $legacy_value ) {
				update_option( $new_key, $legacy_value );
			}
		}

		update_option( self::MIGRATION_FLAG, true );
	}

	/**
	 * Get general max_tokens options.
	 *
	 * @return array
	 */
	public static function get_max_tokens_options() {
		self::maybe_migrate_general_defaults();

		$opts = get_option( 'alorbach_max_tokens_options', '' );
		if ( is_string( $opts ) && $opts !== '' ) {
			$parsed = array_filter( array_map( 'absint', explode( ',', $opts ) ) );
			if ( ! empty( $parsed ) ) {
				return array_map( 'strval', array_values( array_unique( array_filter( $parsed ) ) ) );
			}
		}
		return array( '256', '512', '1024', '2048', '4096', '8192', '16384' );
	}

	/**
	 * Get the canonical default max_tokens value.
	 *
	 * @return string
	 */
	public static function get_default_max_tokens() {
		self::maybe_migrate_general_defaults();

		$options = self::get_max_tokens_options();
		$value   = get_option( 'alorbach_default_max_tokens', '1024' );
		return in_array( $value, $options, true ) ? $value : ( $options[0] ?? '1024' );
	}

	/**
	 * Get the canonical default chat model.
	 *
	 * @param array $text_models Available models.
	 * @return string
	 */
	public static function get_default_chat_model( $text_models ) {
		self::maybe_migrate_general_defaults();
		return (string) get_option( 'alorbach_default_chat_model', $text_models[0] ?? 'gpt-4.1-mini' );
	}

	/**
	 * Get the canonical default image size.
	 *
	 * @param array $image_sizes Available sizes.
	 * @return string
	 */
	public static function get_default_image_size( $image_sizes ) {
		self::maybe_migrate_general_defaults();
		return (string) get_option( 'alorbach_default_image_size', $image_sizes[0] ?? '1024x1024' );
	}

	/**
	 * Get the canonical default audio model.
	 *
	 * @param array $audio_models Available models.
	 * @return string
	 */
	public static function get_default_audio_model( $audio_models ) {
		self::maybe_migrate_general_defaults();
		return (string) get_option( 'alorbach_default_audio_model', $audio_models[0] ?? 'whisper-1' );
	}

	/**
	 * Get the canonical default video model.
	 *
	 * @param array $video_models Available models.
	 * @return string
	 */
	public static function get_default_video_model( $video_models ) {
		self::maybe_migrate_general_defaults();
		return (string) get_option( 'alorbach_default_video_model', $video_models[0] ?? 'sora-2' );
	}

	/**
	 * Render tab navigation.
	 *
	 * @param string $active_tab Current tab slug.
	 */
	private static function render_tab_nav( $active_tab ) {
		$tabs = self::get_tabs();
		?>
		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<?php $tab_url = add_query_arg( array( 'page' => 'alorbach-settings', 'tab' => $tab_slug ), admin_url( 'admin.php' ) ); ?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	/**
	 * Render Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}

		self::maybe_migrate_general_defaults();

		$tabs       = self::get_tabs();
		$active_tab = isset( $_REQUEST['tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['tab'] ) ) : 'general-defaults';
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general-defaults';
		}

		$demo_admin            = Admin_Demo_Defaults::class;
		$text_models           = $demo_admin::get_text_models();
		$image_models          = $demo_admin::get_image_models();
		$image_sizes           = $demo_admin::get_image_sizes();
		$audio_models          = $demo_admin::get_audio_models();
		$video_models          = $demo_admin::get_video_models();
		$max_tokens_options    = self::get_max_tokens_options();
		$default_chat_model    = self::get_default_chat_model( $text_models );
		$default_max_tokens    = self::get_default_max_tokens();
		$default_image_model   = (string) get_option( 'alorbach_image_default_model', $image_models[0] ?? 'dall-e-3' );
		$default_image_quality = (string) get_option( 'alorbach_image_default_quality', 'medium' );
		$default_image_format  = (string) get_option( 'alorbach_image_default_output_format', 'png' );
		$default_image_size    = self::get_default_image_size( $image_sizes );
		$default_audio_model   = self::get_default_audio_model( $audio_models );
		$default_video_model   = self::get_default_video_model( $video_models );

		if ( Admin_Helper::verify_post_nonce( 'alorbach_settings_nonce', 'alorbach_settings' ) ) {
			$active_tab                 = isset( $_POST['alorbach_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['alorbach_settings_tab'] ) ) : $active_tab;
			$selling_enabled            = isset( $_POST['alorbach_selling_enabled'] );
			$selling_multiplier         = isset( $_POST['alorbach_selling_multiplier'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['alorbach_selling_multiplier'] ) ) : 2.0;
			$selling_multiplier         = max( 1.0, $selling_multiplier );
			$debug_enabled              = isset( $_POST['alorbach_debug_enabled'] );
			$google_import_default      = isset( $_POST['alorbach_google_import_default'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_google_import_default'] ) ) : 'all';
			$google_import_default      = in_array( $google_import_default, array( 'all', 'none' ), true ) ? $google_import_default : 'all';
			$google_model_whitelist     = isset( $_POST['alorbach_google_model_whitelist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['alorbach_google_model_whitelist'] ) ) : '';
			$rate_limit_window          = isset( $_POST['alorbach_rate_limit_window'] ) ? max( 10, min( 3600, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_window'] ) ) ) ) : 60;
			$rate_limit_chat            = isset( $_POST['alorbach_rate_limit_chat'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_chat'] ) ) ) ) : 100;
			$rate_limit_images          = isset( $_POST['alorbach_rate_limit_images'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_images'] ) ) ) ) : 30;
			$rate_limit_transcribe      = isset( $_POST['alorbach_rate_limit_transcribe'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_transcribe'] ) ) ) ) : 30;
			$rate_limit_video           = isset( $_POST['alorbach_rate_limit_video'] ) ? max( 1, min( 9999, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_rate_limit_video'] ) ) ) ) : 10;
			$billing_url_subscribe      = isset( $_POST['alorbach_billing_url_subscribe'] ) ? esc_url_raw( wp_unslash( $_POST['alorbach_billing_url_subscribe'] ) ) : '';
			$billing_url_top_up         = isset( $_POST['alorbach_billing_url_top_up'] ) ? esc_url_raw( wp_unslash( $_POST['alorbach_billing_url_top_up'] ) ) : '';
			$billing_url_manage_account = isset( $_POST['alorbach_billing_url_manage_account'] ) ? esc_url_raw( wp_unslash( $_POST['alorbach_billing_url_manage_account'] ) ) : '';
			$billing_url_account        = isset( $_POST['alorbach_billing_url_account_overview'] ) ? esc_url_raw( wp_unslash( $_POST['alorbach_billing_url_account_overview'] ) ) : '';
			$my_credits_menu_label      = isset( $_POST['alorbach_my_credits_menu_label'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_my_credits_menu_label'] ) ) : 'AI Credits';
			$monthly_quota_uc           = isset( $_POST['alorbach_monthly_quota_uc'] ) ? max( 0, (int) sanitize_text_field( wp_unslash( $_POST['alorbach_monthly_quota_uc'] ) ) ) : 0;
			$default_chat_model         = isset( $_POST['alorbach_default_chat_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_default_chat_model'] ) ) : $default_chat_model;
			$default_max_tokens         = isset( $_POST['alorbach_default_max_tokens'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_default_max_tokens'] ) ) : $default_max_tokens;
			$max_tokens_options_raw     = isset( $_POST['alorbach_max_tokens_options'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_max_tokens_options'] ) ) : '';
			$default_image_model        = isset( $_POST['alorbach_image_default_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_model'] ) ) : $default_image_model;
			$default_image_quality      = isset( $_POST['alorbach_image_default_quality'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_quality'] ) ) : $default_image_quality;
			$default_image_format       = isset( $_POST['alorbach_image_default_output_format'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_output_format'] ) ) : $default_image_format;
			$default_image_size         = isset( $_POST['alorbach_default_image_size'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_default_image_size'] ) ) : $default_image_size;
			$default_audio_model        = isset( $_POST['alorbach_default_audio_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_default_audio_model'] ) ) : $default_audio_model;
			$default_video_model        = isset( $_POST['alorbach_default_video_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_default_video_model'] ) ) : $default_video_model;

			update_option( 'alorbach_default_chat_model', $default_chat_model );
			update_option( 'alorbach_default_max_tokens', $default_max_tokens );
			update_option( 'alorbach_max_tokens_options', $max_tokens_options_raw );
			update_option( 'alorbach_image_default_model', $default_image_model );
			update_option( 'alorbach_image_default_quality', $default_image_quality );
			update_option( 'alorbach_image_default_output_format', $default_image_format );
			update_option( 'alorbach_default_image_size', $default_image_size );
			update_option( 'alorbach_default_audio_model', $default_audio_model );
			update_option( 'alorbach_default_video_model', $default_video_model );
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
			update_option( 'alorbach_billing_url_subscribe', $billing_url_subscribe );
			update_option( 'alorbach_billing_url_top_up', $billing_url_top_up );
			update_option( 'alorbach_billing_url_manage_account', $billing_url_manage_account );
			update_option( 'alorbach_billing_url_account_overview', $billing_url_account );
			update_option( 'alorbach_my_credits_menu_label', $my_credits_menu_label ?: 'AI Credits' );
			update_option( 'alorbach_monthly_quota_uc', $monthly_quota_uc );
			Admin_Helper::render_notice( __( 'Settings saved.', 'alorbach-ai-gateway' ) );

			$max_tokens_options = self::get_max_tokens_options();
			$default_max_tokens = self::get_default_max_tokens();
		}

		$selling_enabled            = (bool) get_option( 'alorbach_selling_enabled', false );
		$selling_multiplier         = (float) get_option( 'alorbach_selling_multiplier', 2.0 );
		$debug_enabled              = (bool) get_option( 'alorbach_debug_enabled', false );
		$google_import_default      = get_option( 'alorbach_google_import_default', 'all' );
		$google_model_whitelist     = get_option( 'alorbach_google_model_whitelist', \Alorbach\AIGateway\Model_Importer::GOOGLE_MODEL_WHITELIST_DEFAULT );
		$rate_limit_window          = (int) get_option( 'alorbach_rate_limit_window', 60 );
		$rate_limit_chat            = (int) get_option( 'alorbach_rate_limit_chat', 100 );
		$rate_limit_images          = (int) get_option( 'alorbach_rate_limit_images', 30 );
		$rate_limit_transcribe      = (int) get_option( 'alorbach_rate_limit_transcribe', 30 );
		$rate_limit_video           = (int) get_option( 'alorbach_rate_limit_video', 10 );
		$monthly_quota_uc           = (int) get_option( 'alorbach_monthly_quota_uc', 0 );
		$billing_url_subscribe      = (string) get_option( 'alorbach_billing_url_subscribe', '' );
		$billing_url_top_up         = (string) get_option( 'alorbach_billing_url_top_up', '' );
		$billing_url_manage_account = (string) get_option( 'alorbach_billing_url_manage_account', '' );
		$billing_url_account        = (string) get_option( 'alorbach_billing_url_account_overview', '' );
		$my_credits_menu_label      = (string) get_option( 'alorbach_my_credits_menu_label', 'AI Credits' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'alorbach-ai-gateway' ); ?></h1>

			<style>
				.alorbach-settings-panel { max-width: 960px; background: #fff; border: 1px solid #dcdcde; border-top: 0; padding: 24px 24px 8px; }
				.alorbach-settings-panel .form-table { max-width: 780px; }
				.alorbach-settings-intro { margin: 12px 0 18px; color: #50575e; }
				.alorbach-settings-tab-copy { margin-top: 0; color: #50575e; }
			</style>

			<?php self::render_tab_nav( $active_tab ); ?>

			<form method="post">
				<?php wp_nonce_field( 'alorbach_settings', 'alorbach_settings_nonce' ); ?>
				<input type="hidden" name="alorbach_settings_tab" value="<?php echo esc_attr( $active_tab ); ?>" />

				<div class="alorbach-settings-panel">
					<p class="alorbach-settings-intro">
						<?php esc_html_e( 'Gateway-wide defaults now live here. Demo pages inherit these values unless users are allowed to change them on the Demo Defaults page.', 'alorbach-ai-gateway' ); ?>
					</p>

					<?php if ( 'general-defaults' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'General Defaults', 'alorbach-ai-gateway' ); ?></h2>
						<p class="alorbach-settings-tab-copy"><?php esc_html_e( 'These defaults are used by the gateway runtime, integration config, and demo pages when no per-request override is supplied.', 'alorbach-ai-gateway' ); ?></p>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="alorbach_default_chat_model"><?php esc_html_e( 'Default chat model', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_default_chat_model" id="alorbach_default_chat_model">
										<?php foreach ( $text_models as $model ) : ?>
											<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $default_chat_model, $model ); ?>><?php echo esc_html( $model ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_default_max_tokens"><?php esc_html_e( 'Default max tokens', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_default_max_tokens" id="alorbach_default_max_tokens">
										<?php foreach ( $max_tokens_options as $value ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $default_max_tokens, $value ); ?>><?php echo esc_html( $value ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_max_tokens_options"><?php esc_html_e( 'Max tokens options', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<input type="text" name="alorbach_max_tokens_options" id="alorbach_max_tokens_options" value="<?php echo esc_attr( get_option( 'alorbach_max_tokens_options', '' ) ); ?>" class="regular-text" placeholder="256,512,1024,2048,4096,8192" />
									<p class="description"><?php esc_html_e( 'Comma-separated values shown to users and used to validate the default max tokens value.', 'alorbach-ai-gateway' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_image_default_model"><?php esc_html_e( 'Default image model', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_image_default_model" id="alorbach_image_default_model">
										<?php foreach ( $image_models as $model ) : ?>
											<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $default_image_model, $model ); ?>><?php echo esc_html( $model ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_default_image_size"><?php esc_html_e( 'Default image size', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_default_image_size" id="alorbach_default_image_size">
										<?php foreach ( $image_sizes as $size ) : ?>
											<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $default_image_size, $size ); ?>><?php echo esc_html( $size ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_image_default_quality"><?php esc_html_e( 'Default image quality', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_image_default_quality" id="alorbach_image_default_quality">
										<option value="low" <?php selected( $default_image_quality, 'low' ); ?>><?php esc_html_e( 'Low', 'alorbach-ai-gateway' ); ?></option>
										<option value="medium" <?php selected( $default_image_quality, 'medium' ); ?>><?php esc_html_e( 'Medium', 'alorbach-ai-gateway' ); ?></option>
										<option value="high" <?php selected( $default_image_quality, 'high' ); ?>><?php esc_html_e( 'High', 'alorbach-ai-gateway' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_image_default_output_format"><?php esc_html_e( 'Default image output format', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_image_default_output_format" id="alorbach_image_default_output_format">
										<option value="png" <?php selected( $default_image_format, 'png' ); ?>><?php esc_html_e( 'PNG', 'alorbach-ai-gateway' ); ?></option>
										<option value="jpeg" <?php selected( $default_image_format, 'jpeg' ); ?>><?php esc_html_e( 'JPEG', 'alorbach-ai-gateway' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Used when image generation requests do not supply an explicit output format.', 'alorbach-ai-gateway' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_default_audio_model"><?php esc_html_e( 'Default audio model', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_default_audio_model" id="alorbach_default_audio_model">
										<?php foreach ( $audio_models as $model ) : ?>
											<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $default_audio_model, $model ); ?>><?php echo esc_html( $model ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_default_video_model"><?php esc_html_e( 'Default video model', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<select name="alorbach_default_video_model" id="alorbach_default_video_model">
										<?php foreach ( $video_models as $model ) : ?>
											<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $default_video_model, $model ); ?>><?php echo esc_html( $model ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
					<?php elseif ( 'rate-limits' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Rate Limits & Quotas', 'alorbach-ai-gateway' ); ?></h2>
						<p class="alorbach-settings-tab-copy"><?php esc_html_e( 'Limit how many requests each logged-in user can make and optionally cap monthly usage across the gateway.', 'alorbach-ai-gateway' ); ?></p>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="alorbach_rate_limit_window"><?php esc_html_e( 'Window (seconds)', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<input type="number" name="alorbach_rate_limit_window" id="alorbach_rate_limit_window" value="<?php echo esc_attr( $rate_limit_window ); ?>" min="10" max="3600" step="1" class="small-text" />
									<p class="description"><?php esc_html_e( 'Rolling time window in seconds (10-3600). Default: 60.', 'alorbach-ai-gateway' ); ?></p>
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
					<?php elseif ( 'billing' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Billing & Account URLs', 'alorbach-ai-gateway' ); ?></h2>
						<p class="alorbach-settings-tab-copy"><?php esc_html_e( 'Canonical frontend destinations exposed to downstream plugins. Leave a field empty to hide that CTA in downstream integrations.', 'alorbach-ai-gateway' ); ?></p>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="alorbach_billing_url_subscribe"><?php esc_html_e( 'Subscribe URL', 'alorbach-ai-gateway' ); ?></label></th>
								<td><input type="url" name="alorbach_billing_url_subscribe" id="alorbach_billing_url_subscribe" value="<?php echo esc_attr( $billing_url_subscribe ); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_billing_url_top_up"><?php esc_html_e( 'Top-up URL', 'alorbach-ai-gateway' ); ?></label></th>
								<td><input type="url" name="alorbach_billing_url_top_up" id="alorbach_billing_url_top_up" value="<?php echo esc_attr( $billing_url_top_up ); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_billing_url_manage_account"><?php esc_html_e( 'Manage account URL', 'alorbach-ai-gateway' ); ?></label></th>
								<td><input type="url" name="alorbach_billing_url_manage_account" id="alorbach_billing_url_manage_account" value="<?php echo esc_attr( $billing_url_manage_account ); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_billing_url_account_overview"><?php esc_html_e( 'Account overview URL', 'alorbach-ai-gateway' ); ?></label></th>
								<td><input type="url" name="alorbach_billing_url_account_overview" id="alorbach_billing_url_account_overview" value="<?php echo esc_attr( $billing_url_account ); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="alorbach_my_credits_menu_label"><?php esc_html_e( 'My credits menu label', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<input type="text" name="alorbach_my_credits_menu_label" id="alorbach_my_credits_menu_label" value="<?php echo esc_attr( $my_credits_menu_label ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'User-facing WordPress admin menu label. Default: AI Credits.', 'alorbach-ai-gateway' ); ?></p>
								</td>
							</tr>
						</table>
					<?php elseif ( 'providers' === $active_tab ) : ?>
						<h2><?php esc_html_e( 'Providers / Import', 'alorbach-ai-gateway' ); ?></h2>
						<p class="alorbach-settings-tab-copy"><?php esc_html_e( 'Provider-specific import defaults that affect how model catalogs are presented in the admin.', 'alorbach-ai-gateway' ); ?></p>
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
					<?php else : ?>
						<h2><?php esc_html_e( 'Advanced', 'alorbach-ai-gateway' ); ?></h2>
						<p class="alorbach-settings-tab-copy"><?php esc_html_e( 'Operational switches that affect markup behavior and diagnostics.', 'alorbach-ai-gateway' ); ?></p>
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
									<p class="description"><?php esc_html_e( '2.0 = 100% profit (user pays 2x API cost).', 'alorbach-ai-gateway' ); ?></p>
								</td>
							</tr>
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
					<?php endif; ?>

					<p class="submit">
						<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'alorbach-ai-gateway' ); ?>" />
					</p>
				</div>
			</form>
		</div>
		<?php
	}
}
