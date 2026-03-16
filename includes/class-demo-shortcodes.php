<?php
/**
 * Demo page shortcodes.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Demo_Shortcodes
 */
class Demo_Shortcodes {

	/**
	 * Render chat demo shortcode.
	 *
	 * @return string
	 */
	public static function render_chat() {
		self::enqueue_assets();
		if ( ! is_user_logged_in() ) {
			return self::login_required();
		}
		$balance = Ledger::get_balance( get_current_user_id() );
		$credits = User_Display::format_credits( $balance );
		ob_start();
		?>
		<div class="alorbach-demo alorbach-demo-chat">
			<div class="alorbach-demo-spinner" aria-hidden="true"></div>
			<div class="alorbach-demo-header">
				<h2 class="alorbach-demo-title"><?php esc_html_e( 'AI Chat Demo', 'alorbach-ai-gateway' ); ?></h2>
				<span class="alorbach-demo-balance"><?php echo esc_html( $credits ); ?></span>
			</div>
			<div class="alorbach-demo-error" style="display:none;"></div>
			<div class="alorbach-demo-form-card">
				<div class="alorbach-demo-settings">
					<label class="alorbach-demo-model-wrap" style="display:none;">
						<?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?>
						<select class="alorbach-demo-model-select"></select>
					</label>
					<label>
						<?php esc_html_e( 'Max tokens', 'alorbach-ai-gateway' ); ?>
						<select class="alorbach-demo-max-tokens"></select>
					</label>
				</div>
			</div>
			<div class="alorbach-demo-messages"></div>
			<div class="alorbach-demo-input-row">
				<textarea class="alorbach-demo-input" placeholder="<?php esc_attr_e( 'Type your message...', 'alorbach-ai-gateway' ); ?>" rows="3"></textarea>
				<button type="button" class="button button-primary alorbach-demo-send"><?php esc_html_e( 'Send', 'alorbach-ai-gateway' ); ?></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render image demo shortcode.
	 *
	 * @return string
	 */
	public static function render_image() {
		self::enqueue_assets();
		if ( ! is_user_logged_in() ) {
			return self::login_required();
		}
		$balance = Ledger::get_balance( get_current_user_id() );
		$credits = User_Display::format_credits( $balance );
		ob_start();
		?>
		<div class="alorbach-demo alorbach-demo-image">
			<div class="alorbach-demo-spinner" aria-hidden="true"></div>
			<div class="alorbach-demo-header">
				<h2 class="alorbach-demo-title"><?php esc_html_e( 'Image Generator', 'alorbach-ai-gateway' ); ?></h2>
				<span class="alorbach-demo-balance"><?php echo esc_html( $credits ); ?></span>
			</div>
			<div class="alorbach-demo-error" style="display:none;"></div>
			<div class="alorbach-demo-form-card">
				<div class="alorbach-demo-prompt-row">
					<label for="alorbach-image-prompt"><?php esc_html_e( 'Prompt', 'alorbach-ai-gateway' ); ?></label>
					<textarea id="alorbach-image-prompt" class="alorbach-demo-prompt" rows="3" placeholder="<?php esc_attr_e( 'Describe the image you want to create...', 'alorbach-ai-gateway' ); ?>"></textarea>
				</div>
				<div class="alorbach-demo-image-options">
					<div class="alorbach-demo-settings">
						<label class="alorbach-demo-model-wrap" style="display:none;">
							<?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?>
							<select class="alorbach-demo-model-select"></select>
						</label>
						<label class="alorbach-demo-size-wrap">
							<?php esc_html_e( 'Size', 'alorbach-ai-gateway' ); ?>
							<select class="alorbach-demo-size-select"></select>
						</label>
						<label class="alorbach-demo-quality-wrap" style="display:none;">
							<?php esc_html_e( 'Quality', 'alorbach-ai-gateway' ); ?>
							<select class="alorbach-demo-quality-select"></select>
						</label>
						<label class="alorbach-demo-n-wrap">
							<?php esc_html_e( 'Number', 'alorbach-ai-gateway' ); ?>
							<input type="number" class="alorbach-demo-n" value="1" min="1" max="10">
						</label>
					</div>
					<p class="alorbach-demo-cost-wrap" style="display:none;">
						<span class="alorbach-demo-cost" aria-live="polite"></span>
					</p>
				</div>
				<div class="alorbach-demo-action-row">
					<button type="button" class="button button-primary alorbach-demo-generate"><?php esc_html_e( 'Generate', 'alorbach-ai-gateway' ); ?></button>
				</div>
			</div>
			<div class="alorbach-demo-images"></div>
			<div class="alorbach-demo-usage" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render transcribe demo shortcode.
	 *
	 * @return string
	 */
	public static function render_transcribe() {
		self::enqueue_assets();
		if ( ! is_user_logged_in() ) {
			return self::login_required();
		}
		$balance = Ledger::get_balance( get_current_user_id() );
		$credits = User_Display::format_credits( $balance );
		ob_start();
		?>
		<div class="alorbach-demo alorbach-demo-transcribe">
			<div class="alorbach-demo-spinner" aria-hidden="true"></div>
			<div class="alorbach-demo-header">
				<h2 class="alorbach-demo-title"><?php esc_html_e( 'Audio Transcription', 'alorbach-ai-gateway' ); ?></h2>
				<span class="alorbach-demo-balance"><?php echo esc_html( $credits ); ?></span>
			</div>
			<div class="alorbach-demo-error" style="display:none;"></div>
			<div class="alorbach-demo-dropzone">
				<input type="file" class="alorbach-demo-file-input" accept="audio/*">
				<p><?php esc_html_e( 'Drag and drop an audio file here, or click to select.', 'alorbach-ai-gateway' ); ?></p>
				<p class="alorbach-demo-file-info" style="margin-top:0.5rem;font-size:0.9em;color:#646970;"></p>
			</div>
			<div class="alorbach-demo-settings" style="margin-top:1rem;">
				<label>
					<?php esc_html_e( 'Instructions (optional):', 'alorbach-ai-gateway' ); ?>
					<textarea class="alorbach-demo-instructions" placeholder="<?php esc_attr_e( 'e.g. Technical meeting, use proper terminology', 'alorbach-ai-gateway' ); ?>" rows="2" style="width:100%;max-width:400px;"></textarea>
				</label>
				<label class="alorbach-demo-model-wrap" style="display:none;">
					<?php esc_html_e( 'Model:', 'alorbach-ai-gateway' ); ?>
					<select class="alorbach-demo-model-select"></select>
				</label>
			</div>
			<p class="alorbach-demo-cost-wrap" style="display:none;">
				<span class="alorbach-demo-cost" aria-live="polite"></span>
			</p>
			<button type="button" class="button button-primary alorbach-demo-transcribe-btn" style="margin-top:1rem;"><?php esc_html_e( 'Transcribe', 'alorbach-ai-gateway' ); ?></button>
			<div class="alorbach-demo-result"></div>
			<div class="alorbach-demo-usage" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render video demo shortcode.
	 *
	 * @return string
	 */
	public static function render_video() {
		self::enqueue_assets();
		if ( ! is_user_logged_in() ) {
			return self::login_required();
		}
		$balance = Ledger::get_balance( get_current_user_id() );
		$credits = User_Display::format_credits( $balance );
		ob_start();
		?>
		<div class="alorbach-demo alorbach-demo-video">
			<div class="alorbach-demo-spinner" aria-hidden="true"></div>
			<div class="alorbach-demo-header">
				<h2 class="alorbach-demo-title"><?php esc_html_e( 'Video Generator', 'alorbach-ai-gateway' ); ?></h2>
				<span class="alorbach-demo-balance"><?php echo esc_html( $credits ); ?></span>
			</div>
			<div class="alorbach-demo-error" style="display:none;"></div>
			<div class="alorbach-demo-form-card">
				<div class="alorbach-demo-prompt-row">
					<label for="alorbach-video-prompt"><?php esc_html_e( 'Prompt', 'alorbach-ai-gateway' ); ?></label>
					<textarea id="alorbach-video-prompt" class="alorbach-demo-prompt" rows="3" placeholder="<?php esc_attr_e( 'Describe the video you want to create...', 'alorbach-ai-gateway' ); ?>"></textarea>
				</div>
				<div class="alorbach-demo-video-options">
					<div class="alorbach-demo-settings">
						<label class="alorbach-demo-duration-wrap">
							<?php esc_html_e( 'Duration', 'alorbach-ai-gateway' ); ?>
							<select class="alorbach-demo-duration-select"></select>
						</label>
						<label class="alorbach-demo-size-wrap">
							<?php esc_html_e( 'Resolution', 'alorbach-ai-gateway' ); ?>
							<select class="alorbach-demo-size-select"></select>
						</label>
						<label class="alorbach-demo-model-wrap" style="display:none;">
							<?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?>
							<select class="alorbach-demo-model-select"></select>
						</label>
					</div>
					<p class="alorbach-demo-cost-wrap" style="display:none;">
						<span class="alorbach-demo-cost" aria-live="polite"></span>
					</p>
				</div>
				<div class="alorbach-demo-action-row">
					<button type="button" class="button button-primary alorbach-demo-generate"><?php esc_html_e( 'Generate', 'alorbach-ai-gateway' ); ?></button>
				</div>
			</div>
			<div class="alorbach-demo-videos"></div>
			<div class="alorbach-demo-usage" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Login required message.
	 *
	 * @return string
	 */
	private static function login_required() {
		return '<div class="alorbach-demo-login-required">' . esc_html__( 'Please log in to use this demo.', 'alorbach-ai-gateway' ) . '</div>';
	}

	/**
	 * Enqueue CSS and JS for demo pages.
	 */
	public static function enqueue_assets() {
		$url = ALORBACH_PLUGIN_URL;
		$ver = ALORBACH_VERSION;
		wp_enqueue_style( 'alorbach-demo-pages', $url . 'assets/css/demo-pages.css', array(), $ver );
		wp_enqueue_script( 'alorbach-demo-pages', $url . 'assets/js/demo-pages.js', array(), $ver, true );
		wp_localize_script( 'alorbach-demo-pages', 'alorbachDemo', array(
			'restUrl'     => rest_url( 'alorbach/v1' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'creditsLabel' => __( 'Credits', 'alorbach-ai-gateway' ),
			'costLabel'    => __( 'Cost: ', 'alorbach-ai-gateway' ),
		) );
	}
}
