<?php
/**
 * Admin: API Keys configuration.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_API_Keys
 */
class Admin_API_Keys {

	/**
	 * Render API Keys page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_api_keys_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_api_keys_nonce'] ) ), 'alorbach_api_keys' ) ) {
			$keys = array(
				'openai'         => isset( $_POST['openai'] ) ? sanitize_text_field( wp_unslash( $_POST['openai'] ) ) : '',
				'azure'          => isset( $_POST['azure'] ) ? sanitize_text_field( wp_unslash( $_POST['azure'] ) ) : '',
				'azure_endpoint' => isset( $_POST['azure_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['azure_endpoint'] ) ) : '',
				'google'         => isset( $_POST['google'] ) ? sanitize_text_field( wp_unslash( $_POST['google'] ) ) : '',
			);
			update_option( 'alorbach_api_keys', $keys );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API keys saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$keys     = get_option( 'alorbach_api_keys', array() );
		$keys     = is_array( $keys ) ? $keys : array();
		$rest_url = rest_url( 'alorbach/v1/admin/verify-api-key' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap alorbach-api-keys">
			<h1><?php esc_html_e( 'API Keys', 'alorbach-ai-gateway' ); ?></h1>
			<p class="alorbach-api-keys-intro"><?php esc_html_e( 'Configure API keys for each provider. Save before testing. GPT models use OpenAI when configured, otherwise Azure.', 'alorbach-ai-gateway' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'alorbach_api_keys', 'alorbach_api_keys_nonce' ); ?>

				<div class="alorbach-provider-cards">
					<div class="alorbach-provider-card">
						<h2 class="alorbach-provider-title"><?php esc_html_e( 'OpenAI', 'alorbach-ai-gateway' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Chat, image (DALL-E), and audio (Whisper) models.', 'alorbach-ai-gateway' ); ?></p>
						<table class="form-table">
							<tr>
								<th><label for="openai"><?php esc_html_e( 'API Key', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<div class="alorbach-input-with-actions">
										<input type="password" id="openai" name="openai" value="<?php echo esc_attr( $keys['openai'] ?? '' ); ?>" class="regular-text alorbach-password-input" autocomplete="off" />
										<button type="button" class="button alorbach-toggle-pw" data-target="openai" aria-label="<?php esc_attr_e( 'Show', 'alorbach-ai-gateway' ); ?>"><?php esc_html_e( 'Show', 'alorbach-ai-gateway' ); ?></button>
										<button type="button" class="button alorbach-test-key" data-provider="openai"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
									</div>
									<span class="alorbach-test-result" data-provider="openai"></span>
								</td>
							</tr>
						</table>
					</div>

					<div class="alorbach-provider-card">
						<h2 class="alorbach-provider-title"><?php esc_html_e( 'Azure OpenAI / Foundry', 'alorbach-ai-gateway' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Chat, image (GPT-image, DALL-E), and audio (Whisper, gpt-4o-transcribe) models. Supports both traditional Azure OpenAI and Foundry endpoints.', 'alorbach-ai-gateway' ); ?></p>
						<table class="form-table">
							<tr>
								<th><label for="azure_endpoint"><?php esc_html_e( 'Endpoint', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<input type="url" id="azure_endpoint" name="azure_endpoint" value="<?php echo esc_attr( $keys['azure_endpoint'] ?? '' ); ?>" class="large-text" placeholder="https://your-resource.services.ai.azure.com" />
									<p class="description"><?php esc_html_e( 'Full URL ending in .azure.com. Foundry: https://xxx.services.ai.azure.com', 'alorbach-ai-gateway' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="azure"><?php esc_html_e( 'API Key', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<div class="alorbach-input-with-actions">
										<input type="password" id="azure" name="azure" value="<?php echo esc_attr( $keys['azure'] ?? '' ); ?>" class="regular-text alorbach-password-input" autocomplete="off" />
										<button type="button" class="button alorbach-toggle-pw" data-target="azure" aria-label="<?php esc_attr_e( 'Show', 'alorbach-ai-gateway' ); ?>"><?php esc_html_e( 'Show', 'alorbach-ai-gateway' ); ?></button>
										<button type="button" class="button alorbach-test-key" data-provider="azure"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
									</div>
									<span class="alorbach-test-result" data-provider="azure"></span>
								</td>
							</tr>
						</table>
					</div>

					<div class="alorbach-provider-card">
						<h2 class="alorbach-provider-title"><?php esc_html_e( 'Google (Gemini)', 'alorbach-ai-gateway' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Gemini chat models.', 'alorbach-ai-gateway' ); ?></p>
						<table class="form-table">
							<tr>
								<th><label for="google"><?php esc_html_e( 'API Key', 'alorbach-ai-gateway' ); ?></label></th>
								<td>
									<div class="alorbach-input-with-actions">
										<input type="password" id="google" name="google" value="<?php echo esc_attr( $keys['google'] ?? '' ); ?>" class="regular-text alorbach-password-input" autocomplete="off" />
										<button type="button" class="button alorbach-toggle-pw" data-target="google" aria-label="<?php esc_attr_e( 'Show', 'alorbach-ai-gateway' ); ?>"><?php esc_html_e( 'Show', 'alorbach-ai-gateway' ); ?></button>
										<button type="button" class="button alorbach-test-key" data-provider="google"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
									</div>
									<span class="alorbach-test-result" data-provider="google"></span>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save API Keys', 'alorbach-ai-gateway' ); ?>" /></p>
			</form>

			<style>
			.alorbach-api-keys .alorbach-provider-cards { display: grid; gap: 1.5rem; max-width: 720px; margin-top: 1rem; }
			.alorbach-api-keys .alorbach-provider-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 1.25rem 1.5rem; }
			.alorbach-api-keys .alorbach-provider-title { margin: 0 0 0.25rem; font-size: 1.1em; }
			.alorbach-api-keys .alorbach-provider-card .description { margin: 0 0 1rem; color: #646970; }
			.alorbach-api-keys .alorbach-input-with-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
			.alorbach-api-keys .alorbach-input-with-actions input { flex: 1; min-width: 200px; }
			.alorbach-api-keys .alorbach-test-result { display: inline-block; margin-left: 0.5rem; font-size: 13px; }
			.alorbach-api-keys .submit { margin-top: 1.5rem; }
			</style>
		<script>
		(function() {
			var restUrl = <?php echo wp_json_encode( $rest_url ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var okText = <?php echo wp_json_encode( __( 'OK', 'alorbach-ai-gateway' ) ); ?>;
			var errText = <?php echo wp_json_encode( __( 'Error', 'alorbach-ai-gateway' ) ); ?>;
			var showText = <?php echo wp_json_encode( __( 'Show', 'alorbach-ai-gateway' ) ); ?>;
			var hideText = <?php echo wp_json_encode( __( 'Hide', 'alorbach-ai-gateway' ) ); ?>;

			document.querySelectorAll('.alorbach-toggle-pw').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var id = this.getAttribute('data-target');
					var input = document.getElementById(id);
					if (!input) return;
					if (input.type === 'password') {
						input.type = 'text';
						btn.textContent = hideText;
						btn.setAttribute('aria-label', hideText);
					} else {
						input.type = 'password';
						btn.textContent = showText;
						btn.setAttribute('aria-label', showText);
					}
				});
			});

			document.querySelectorAll('.alorbach-test-key').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var provider = this.getAttribute('data-provider');
					var resultEl = document.querySelector('.alorbach-test-result[data-provider="' + provider + '"]');
					resultEl.textContent = '...';
					resultEl.style.color = '';
					fetch(restUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify({ provider: provider })
					}).then(function(r) { return r.json(); }).then(function(data) {
						resultEl.textContent = data.success ? okText : (data.message || errText);
						resultEl.style.color = data.success ? 'green' : 'red';
					}).catch(function(err) {
						resultEl.textContent = err.message || errText;
						resultEl.style.color = 'red';
					});
				});
			});
		})();
		</script>
		</div>
		<?php
	}
}
