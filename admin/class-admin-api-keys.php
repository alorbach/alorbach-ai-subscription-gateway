<?php
/**
 * Admin: API Keys configuration (grid).
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

use Alorbach\AIGateway\API_Keys_Helper;
use Alorbach\AIGateway\Codex_OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin: API Keys configuration (grid).
 *
	 * Manages the multi-entry API key list for all supported providers
	 * (OpenAI, Azure OpenAI, Google Gemini, Hugging Face, Hugging Face Spaces,
	 * GitHub Models, Codex OAuth).
 * Each entry can be enabled/disabled independently and supports an
 * optional display name for identification.
 *
 * @package Alorbach\AIGateway\Admin
 * @since   1.0.0
 */
class Admin_API_Keys {

	/**
	 * Provider type options.
	 *
	 * @var array
	 */
	private static $type_options = array(
		'openai'        => 'OpenAI',
		'azure'         => 'Azure OpenAI / Foundry',
		'google'        => 'Google (Gemini)',
		'huggingface'   => 'Hugging Face',
		'huggingface_spaces' => 'Hugging Face Spaces',
		'github_models' => 'GitHub Models',
		'codex'         => 'OpenAI Codex (OAuth)',
	);

	/**
	 * Render API Keys page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}
		// Show OAuth callback notices set by the admin_init handler.
		$oauth_notice = get_transient( 'alorbach_codex_oauth_notice' );
		if ( $oauth_notice ) {
			delete_transient( 'alorbach_codex_oauth_notice' );
			$notice_class = ( $oauth_notice['type'] === 'success' ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $oauth_notice['message'] ) . '</p></div>';
		}

		if ( Admin_Helper::verify_post_nonce( 'alorbach_api_keys_nonce', 'alorbach_api_keys' ) ) {
			$entries = array();
			$raw     = isset( $_POST['entries'] ) && is_array( $_POST['entries'] ) ? $_POST['entries'] : array();
			foreach ( $raw as $e ) {
				$type = isset( $e['type'] ) ? sanitize_text_field( $e['type'] ) : '';
				if ( ! in_array( $type, array( 'openai', 'azure', 'google', 'huggingface', 'huggingface_spaces', 'github_models', 'codex' ), true ) ) {
					continue;
				}
				$entry = array(
					'id'      => isset( $e['id'] ) ? sanitize_text_field( $e['id'] ) : '',
					'type'    => $type,
					'api_key' => isset( $e['api_key'] ) ? sanitize_text_field( wp_unslash( $e['api_key'] ) ) : '',
					'enabled' => ! empty( $e['enabled'] ),
					'name'    => isset( $e['name'] ) ? sanitize_text_field( wp_unslash( $e['name'] ) ) : '',
				);
				if ( in_array( $type, array( 'azure', 'huggingface', 'huggingface_spaces' ), true ) && isset( $e['endpoint'] ) ) {
					$entry['endpoint'] = esc_url_raw( wp_unslash( $e['endpoint'] ) );
				}
				if ( $type === 'huggingface_spaces' ) {
					if ( isset( $e['space_id'] ) ) {
						$entry['space_id'] = sanitize_text_field( wp_unslash( $e['space_id'] ) );
					}
					if ( isset( $e['request_mode'] ) ) {
						$entry['request_mode'] = sanitize_key( wp_unslash( $e['request_mode'] ) );
					}
					if ( isset( $e['schema_preset'] ) ) {
						$entry['schema_preset'] = sanitize_text_field( wp_unslash( $e['schema_preset'] ) );
					}
				}
				if ( $type === 'github_models' ) {
					if ( isset( $e['org'] ) ) {
						$entry['org'] = sanitize_text_field( wp_unslash( $e['org'] ) );
					}
					$entry['free_pass_through'] = ! empty( $e['free_pass_through'] );
				}
				if ( $type === 'codex' ) {
					// Auth is via OAuth; no API key stored in the entry.
					$entry['api_key']           = '';
					$entry['free_pass_through'] = ! empty( $e['free_pass_through'] );
				}
				$entries[] = $entry;
			}
			API_Keys_Helper::save_entries( $entries );
			// Handle inline Codex disconnect.
			if ( isset( $_POST['codex_disconnect'] ) && '1' === $_POST['codex_disconnect'] ) {
				Codex_OAuth::revoke();
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API keys saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$entries  = API_Keys_Helper::get_entries();
		$rest_url = rest_url( 'alorbach/v1/admin/verify-api-key' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap alorbach-api-keys">
			<h1><?php esc_html_e( 'API Keys', 'alorbach-ai-gateway' ); ?></h1>
			<p class="alorbach-api-keys-intro"><?php esc_html_e( 'Add and configure API keys for each provider. Enable or disable each entry. Save before testing.', 'alorbach-ai-gateway' ); ?></p>

			<form method="post" id="alorbach-api-keys-form">
				<?php wp_nonce_field( 'alorbach_api_keys', 'alorbach_api_keys_nonce' ); ?>
				<input type="hidden" name="codex_disconnect" value="0" id="alorbach-codex-disconnect-flag" />

				<div class="alorbach-api-keys-table-wrap">
					<table class="widefat striped alorbach-api-keys-table">
						<thead>
							<tr>
								<th class="col-type"><?php esc_html_e( 'API Type', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-name"><?php esc_html_e( 'Name', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-key"><?php esc_html_e( 'API Key', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-endpoint"><?php esc_html_e( 'Endpoint', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-org"><?php esc_html_e( 'Organization', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-free"><?php esc_html_e( 'Free pass-through', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-enabled"><?php esc_html_e( 'Enabled', 'alorbach-ai-gateway' ); ?></th>
								<th class="col-actions"><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody id="alorbach-entries-tbody">
							<?php
							foreach ( $entries as $i => $entry ) {
								self::render_entry_row( $i, $entry );
							}
							?>
						</tbody>
					</table>
				</div>

				<p class="submit">
					<button type="button" class="button" id="alorbach-add-entry"><?php esc_html_e( 'Add API Key', 'alorbach-ai-gateway' ); ?></button>
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save API Keys', 'alorbach-ai-gateway' ); ?>" />
				</p>
			</form>

			<?php /* Hidden form for Codex OAuth exchange — MUST be outside the main form (nested forms are invalid HTML). */ ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="alorbach-codex-exchange-form" style="display:none;">
				<?php wp_nonce_field( 'alorbach_codex_exchange' ); ?>
				<input type="hidden" name="action" value="alorbach_codex_exchange" />
				<input type="hidden" name="codex_redirect_url" id="alorbach-codex-redirect-url-hidden" value="" />
			</form>

			<template id="alorbach-entry-row-tpl">
				<?php self::render_entry_row( '{{INDEX}}', array( 'id' => '', 'type' => 'openai', 'api_key' => '', 'enabled' => true, 'name' => '', 'endpoint' => '', 'org' => '', 'space_id' => '', 'request_mode' => 'custom_http', 'schema_preset' => '', 'free_pass_through' => false ) ); ?>
			</template>

			<style>
			.alorbach-api-keys { max-width: 1400px; }
			.alorbach-api-keys .alorbach-api-keys-table-wrap { max-width: 100%; overflow-x: auto; }
			.alorbach-api-keys .alorbach-api-keys-table { width: 100%; max-width: 1400px; margin-top: 1rem; table-layout: fixed; }
			.alorbach-api-keys .col-type { width: 12%; }
			.alorbach-api-keys .col-name { width: 10%; }
			.alorbach-api-keys .col-key { width: 22%; }
			.alorbach-api-keys .col-endpoint { width: 18%; }
			.alorbach-api-keys .col-org { width: 10%; }
			.alorbach-api-keys .col-free { width: 8%; }
			.alorbach-api-keys .col-enabled { width: 6%; }
			.alorbach-api-keys .col-actions { width: 8%; }
			.alorbach-api-keys .entry-endpoint, .alorbach-api-keys .entry-org, .alorbach-api-keys .entry-free { visibility: hidden; }
			.alorbach-api-keys tr[data-type="azure"] .entry-endpoint,
			.alorbach-api-keys tr[data-type="huggingface"] .entry-endpoint,
			.alorbach-api-keys tr[data-type="huggingface_spaces"] .entry-endpoint { visibility: visible; }
			.alorbach-api-keys tr[data-type="github_models"] .entry-org, .alorbach-api-keys tr[data-type="github_models"] .entry-free { visibility: visible; }
			.alorbach-api-keys tr[data-type="codex"] .entry-endpoint,
			.alorbach-api-keys tr[data-type="codex"] .entry-org { display: none; }
			.alorbach-api-keys .spaces-extra-fields { display: none; margin-top: 6px; }
			.alorbach-api-keys tr[data-type="huggingface_spaces"] .spaces-extra-fields { display: grid; gap: 6px; }
			.alorbach-api-keys .spaces-extra-fields select,
			.alorbach-api-keys .spaces-extra-fields input { width: 100%; }
			.alorbach-api-keys tr[data-type="codex"] .input-with-actions { display: none; }
			.alorbach-api-keys .codex-inline-ui { display: none; }
			.alorbach-api-keys tr[data-type="codex"] .codex-inline-ui { display: block; }
			.alorbach-api-keys .codex-inline-ui { font-size: 12px; max-width: 540px; }
			.alorbach-api-keys .codex-inline-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
			.alorbach-api-keys .codex-inline-actions .codex-status { font-weight: 600; }
			.alorbach-api-keys .codex-inline-actions .codex-action-buttons { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
			.alorbach-api-keys tr[data-type="codex"] .entry-key { padding-right: 12px; }
			.alorbach-api-keys .input-with-actions { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
			.alorbach-api-keys .input-with-actions input { flex: 1; min-width: 0; max-width: 100%; }
			.alorbach-api-keys .alorbach-api-keys-table input,
			.alorbach-api-keys .alorbach-api-keys-table select { max-width: 100%; box-sizing: border-box; }
			.alorbach-api-keys .alorbach-tooltip-wrap { display: inline-flex; align-items: center; gap: 4px; }
			.alorbach-api-keys .alorbach-tooltip-icon { font-size: 16px; width: 16px; height: 16px; color: #646970; cursor: help; }
			.alorbach-api-keys .entry-enabled, .alorbach-api-keys .entry-actions { white-space: nowrap; vertical-align: middle; }
			.alorbach-api-keys .entry-enabled input { margin: 0; }
			.alorbach-api-keys .submit { margin-top: 1rem; }
			.alorbach-api-keys .setup-guides { margin-top: 2rem; }
			.alorbach-api-keys .setup-guides-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
			.alorbach-api-keys .setup-guide-card { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
			.alorbach-api-keys .setup-guide-card h3 { margin: 0 0 8px; }
			.alorbach-api-keys .setup-guide-card p { margin: 0 0 10px; }
			.alorbach-api-keys .setup-guide-card ol { margin: 0 0 10px 18px; }
			.alorbach-api-keys .setup-guide-card li { margin-bottom: 6px; }
			.alorbach-api-keys .setup-guide-card .description { color: #50575e; }
			@media (max-width: 782px) {
				.alorbach-api-keys .alorbach-api-keys-table { table-layout: auto; min-width: 0; }
				.alorbach-api-keys .alorbach-api-keys-table thead { display: none; }
				.alorbach-api-keys .alorbach-api-keys-table,
				.alorbach-api-keys .alorbach-api-keys-table tbody,
				.alorbach-api-keys .alorbach-api-keys-table tr,
				.alorbach-api-keys .alorbach-api-keys-table td { display: block; width: 100%; box-sizing: border-box; }
				.alorbach-api-keys .alorbach-api-keys-table tr { margin: 0 0 16px; padding: 12px; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
				.alorbach-api-keys .alorbach-api-keys-table td { padding: 6px 0; border: 0; }
				.alorbach-api-keys .alorbach-api-keys-table td.entry-actions { padding-top: 10px; }
				.alorbach-api-keys .alorbach-api-keys-table td.entry-actions .button { width: 100%; justify-content: center; }
				.alorbach-api-keys .input-with-actions { flex-direction: column; align-items: stretch; }
				.alorbach-api-keys .input-with-actions .button { width: 100%; }
				.alorbach-api-keys .entry-enabled,
				.alorbach-api-keys .entry-free { display: flex !important; align-items: center; }
			}
			</style>
		<script>
		(function() {
			var restUrl = <?php echo wp_json_encode( $rest_url ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var okText = <?php echo wp_json_encode( __( 'OK', 'alorbach-ai-gateway' ) ); ?>;
			var errText = <?php echo wp_json_encode( __( 'Error', 'alorbach-ai-gateway' ) ); ?>;
			var showText = <?php echo wp_json_encode( __( 'Show', 'alorbach-ai-gateway' ) ); ?>;
			var hideText = <?php echo wp_json_encode( __( 'Hide', 'alorbach-ai-gateway' ) ); ?>;
			var typeOptions = <?php echo wp_json_encode( self::$type_options ); ?>;

			function initRow(row) {
				var typeSel = row.querySelector('select[name*="[type]"]');
				var toggleBtn = row.querySelector('.alorbach-toggle-pw');
				var testBtn = row.querySelector('.alorbach-test-key');
				var delBtn = row.querySelector('.alorbach-delete-entry');
				if (typeSel) {
					typeSel.addEventListener('change', function() {
						row.setAttribute('data-type', this.value);
					});
					row.setAttribute('data-type', typeSel.value);
				}
				if (toggleBtn) {
					toggleBtn.addEventListener('click', function() {
						var id = this.getAttribute('data-target');
						var input = row.querySelector('#' + id);
						if (!input) return;
						if (input.type === 'password') {
							input.type = 'text';
							this.textContent = hideText;
						} else {
							input.type = 'password';
							this.textContent = showText;
						}
					});
				}
				if (testBtn) {
					testBtn.addEventListener('click', function() {
						var provider = typeSel ? typeSel.value : 'openai';
						var entryId = row.getAttribute('data-entry-id') || '';
						var resultEl = row.querySelector('.alorbach-test-result');
						resultEl.textContent = '...';
						resultEl.style.color = '';
						var body = { provider: provider };
						if (entryId) body.entry_id = entryId;
						fetch(restUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
							body: JSON.stringify(body)
						}).then(function(r) { return r.json(); }).then(function(data) {
							resultEl.textContent = data.success ? okText : (data.message || errText);
							resultEl.style.color = data.success ? 'green' : 'red';
						}).catch(function(err) {
							resultEl.textContent = err.message || errText;
							resultEl.style.color = 'red';
						});
					});
				}
				if (delBtn) {
					delBtn.addEventListener('click', function() { row.remove(); });
				}
			}

			document.querySelectorAll('#alorbach-entries-tbody tr').forEach(initRow);

			var codexConnectBtn = document.getElementById('alorbach-codex-connect-btn');
			if (codexConnectBtn) {
				codexConnectBtn.addEventListener('click', function() {
					var textarea = document.getElementById('alorbach-codex-redirect-url');
					var hidden   = document.getElementById('alorbach-codex-redirect-url-hidden');
					var form     = document.getElementById('alorbach-codex-exchange-form');
					if (!textarea || !hidden || !form) return;
					var val = textarea.value.trim();
					if (!val) { alert('Please paste the callback URL first.'); return; }
					hidden.value = val;
					form.submit();
				});
			}

			document.getElementById('alorbach-add-entry').addEventListener('click', function() {
				var tpl = document.getElementById('alorbach-entry-row-tpl');
				var tbody = document.getElementById('alorbach-entries-tbody');
				if (!tpl || !tbody) return;
				var idx = tbody.querySelectorAll('tr').length;
				var clone = tpl.content.cloneNode(true);
				var row = clone.querySelector('tr');
				if (!row) return;
				row.querySelectorAll('[name]').forEach(function(el) {
					el.name = el.name.replace(/\{\{INDEX\}\}/g, idx);
				});
				row.querySelectorAll('[data-target]').forEach(function(el) {
					var t = el.getAttribute('data-target');
					if (t) el.setAttribute('data-target', t.replace(/\{\{INDEX\}\}/g, 'new-' + idx));
				});
				row.querySelectorAll('[id]').forEach(function(el) {
					if (el.id && el.id.indexOf('{{INDEX}}') !== -1) el.id = el.id.replace(/\{\{INDEX\}\}/g, 'new-' + idx);
				});
				var pwInput = row.querySelector('input[type="password"]');
				var toggleBtn = row.querySelector('.alorbach-toggle-pw');
				if (pwInput) pwInput.id = 'key-new-' + idx;
				if (toggleBtn) toggleBtn.setAttribute('data-target', 'key-new-' + idx);
				tbody.appendChild(clone);
				initRow(tbody.lastElementChild);
			});
		})();
		</script>

		<?php self::render_provider_setup_sections(); ?>

		</div>
		<?php
	}

	/**
	 * Render provider setup instructions below the API keys table.
	 */
	private static function render_provider_setup_sections() {
		?>
		<hr style="margin:2rem 0;" />
		<div class="setup-guides">
			<h2><?php esc_html_e( 'Provider Setup Guides', 'alorbach-ai-gateway' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Use these step-by-step instructions to create the right credentials for each provider, then return here to save the row and click Test.', 'alorbach-ai-gateway' ); ?></p>
			<div class="setup-guides-grid">
				<?php self::render_setup_guide_card(
					__( 'OpenAI', 'alorbach-ai-gateway' ),
					__( 'Use this for Chat Completions, GPT Image, Whisper-style audio, and Sora-capable OpenAI models.', 'alorbach-ai-gateway' ),
					array(
						__( 'Sign in to the OpenAI dashboard and open API keys.', 'alorbach-ai-gateway' ),
						__( 'Create a new secret key for the project or account you want to bill.', 'alorbach-ai-gateway' ),
						__( 'In the table above add a row with type "OpenAI".', 'alorbach-ai-gateway' ),
						__( 'Paste the secret key into API Key. Leave Endpoint empty for OpenAI.', 'alorbach-ai-gateway' ),
						__( 'Save API Keys, then click Test.', 'alorbach-ai-gateway' ),
					)
				); ?>
				<?php self::render_setup_guide_card(
					__( 'Azure OpenAI / Foundry', 'alorbach-ai-gateway' ),
					__( 'Use this when your models are deployed in Azure OpenAI or Azure AI Foundry and require both a key and a base endpoint.', 'alorbach-ai-gateway' ),
					array(
						__( 'In the Azure portal open your Azure OpenAI or Azure AI Foundry resource.', 'alorbach-ai-gateway' ),
						__( 'Copy one of the API keys from Keys and Endpoint.', 'alorbach-ai-gateway' ),
						__( 'Copy the resource endpoint URL shown by Azure.', 'alorbach-ai-gateway' ),
						__( 'In the table above add a row with type "Azure OpenAI / Foundry".', 'alorbach-ai-gateway' ),
						__( 'Paste the API key into API Key and the Azure resource URL into Endpoint.', 'alorbach-ai-gateway' ),
						__( 'Save API Keys, then click Test.', 'alorbach-ai-gateway' ),
					)
				); ?>
				<?php self::render_setup_guide_card(
					__( 'Google (Gemini)', 'alorbach-ai-gateway' ),
					__( 'Use this for Gemini, Imagen, and other Google AI Studio models that this plugin can import and call.', 'alorbach-ai-gateway' ),
					array(
						__( 'Open Google AI Studio and create or select a project.', 'alorbach-ai-gateway' ),
						__( 'Create an API key for Generative Language / Gemini access.', 'alorbach-ai-gateway' ),
						__( 'In the table above add a row with type "Google (Gemini)".', 'alorbach-ai-gateway' ),
						__( 'Paste the API key into API Key. Leave Endpoint empty.', 'alorbach-ai-gateway' ),
						__( 'Save API Keys, then click Test.', 'alorbach-ai-gateway' ),
						__( 'After that, review imported Google models carefully because your account may not have quota for every catalog model.', 'alorbach-ai-gateway' ),
					)
				); ?>
				<?php self::render_setup_guide_card(
					__( 'Hugging Face', 'alorbach-ai-gateway' ),
					__( 'Use this for the existing Hugging Face router and hf-inference provider path.', 'alorbach-ai-gateway' ),
					array(
						__( 'Sign in to Hugging Face and open Access Tokens in Settings.', 'alorbach-ai-gateway' ),
						__( 'Create a token with the permissions required for the models you want to use.', 'alorbach-ai-gateway' ),
						__( 'In the table above add a row with type "Hugging Face".', 'alorbach-ai-gateway' ),
						__( 'Paste the token into API Key.', 'alorbach-ai-gateway' ),
						__( 'Leave Endpoint empty to use the default router, or enter a custom Hugging Face router base URL if needed.', 'alorbach-ai-gateway' ),
						__( 'Save API Keys, then click Test.', 'alorbach-ai-gateway' ),
					)
				); ?>
				<?php self::render_setup_guide_card(
					__( 'Hugging Face Spaces', 'alorbach-ai-gateway' ),
					__( 'Use this for manual single-Space image integrations. This is separate from the normal Hugging Face router provider and is currently a partial feature rather than a curated catalog.', 'alorbach-ai-gateway' ),
					array(
						__( 'Identify the Space you want to connect, for example owner/space-name.', 'alorbach-ai-gateway' ),
						__( 'If the Space is private or protected, create a Hugging Face access token with the required permissions. Public Spaces can leave API Key empty.', 'alorbach-ai-gateway' ),
						__( 'Add a row with type "Hugging Face Spaces".', 'alorbach-ai-gateway' ),
						__( 'Enter the Space ID in the Space ID field.', 'alorbach-ai-gateway' ),
						__( 'Choose the request mode. Use Custom HTTP for stable custom endpoints, or Gradio API for Spaces that publish /gradio_api documentation.', 'alorbach-ai-gateway' ),
						__( 'For Gradio API Spaces, enter the base app URL such as https://owner-space.hf.space, not the MCP URL.', 'alorbach-ai-gateway' ),
						__( 'For Custom HTTP Spaces, enter Endpoint only when you want to override the detected hf.space URL or target a custom path such as /generate.', 'alorbach-ai-gateway' ),
						__( 'Schema preset is an advanced manual field for selecting a named Gradio endpoint. It is not a curated preset catalog.', 'alorbach-ai-gateway' ),
						__( 'The supported path today is one manual entry mapped to one imported image model for that Space.', 'alorbach-ai-gateway' ),
						__( 'Save API Keys, then click Test.', 'alorbach-ai-gateway' ),
					)
				); ?>
				<?php self::render_setup_guide_card(
					__( 'GitHub Models', 'alorbach-ai-gateway' ),
					__( 'Use this for GitHub Models access through your GitHub account or organization.', 'alorbach-ai-gateway' ),
					array(
						__( 'Sign in to GitHub and create a personal access token or other credential that can access GitHub Models for your account or organization.', 'alorbach-ai-gateway' ),
						__( 'If you use an organization-backed setup, note the organization name you want to associate with requests.', 'alorbach-ai-gateway' ),
						__( 'Add a row with type "GitHub Models".', 'alorbach-ai-gateway' ),
						__( 'Paste the token into API Key and optionally fill Organization.', 'alorbach-ai-gateway' ),
						__( 'Enable Free pass-through only if you intentionally want to skip credit charging for requests covered by your own GitHub allowance.', 'alorbach-ai-gateway' ),
						__( 'Save API Keys, then click Test.', 'alorbach-ai-gateway' ),
					)
				); ?>
				<?php self::render_setup_guide_card(
					__( 'Codex OAuth — ChatGPT Subscription Setup', 'alorbach-ai-gateway' ),
					__( 'Codex models are powered by ChatGPT and require an active ChatGPT Plus or Pro subscription. No OAuth app registration is needed.', 'alorbach-ai-gateway' ),
					array(
						__( 'Add a row with type "OpenAI Codex (OAuth)" in the table above.', 'alorbach-ai-gateway' ),
						__( 'Click "Get Authorization URL" in the Codex row.', 'alorbach-ai-gateway' ),
						__( 'Open the displayed URL in your local browser and sign in with your ChatGPT Plus or Pro account.', 'alorbach-ai-gateway' ),
						__( 'After sign-in, your browser will show a localhost connection error. This is expected.', 'alorbach-ai-gateway' ),
						__( 'Copy the full callback URL from the browser address bar and paste it into the Codex row.', 'alorbach-ai-gateway' ),
						__( 'Click "Submit & Connect", then save if needed.', 'alorbach-ai-gateway' ),
					)
				); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one provider setup guide card.
	 *
	 * @param string $title       Card title.
	 * @param string $description Short description.
	 * @param array  $steps       Setup steps.
	 * @return void
	 */
	private static function render_setup_guide_card( $title, $description, $steps ) {
		?>
		<div class="setup-guide-card">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p class="description"><?php echo esc_html( $description ); ?></p>
			<ol>
				<?php foreach ( (array) $steps as $step ) : ?>
					<li><?php echo esc_html( $step ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render a single entry row.
	 *
	 * @param int|string $index Row index.
	 * @param array      $entry Entry data.
	 */
	private static function render_entry_row( $index, $entry ) {
		$id       = $entry['id'] ?? '';
		$type     = $entry['type'] ?? 'openai';
		$api_key  = $entry['api_key'] ?? '';
		$enabled  = ! empty( $entry['enabled'] );
		$name     = $entry['name'] ?? '';
		$endpoint = $entry['endpoint'] ?? '';
		$space_id = $entry['space_id'] ?? '';
		$request_mode = $entry['request_mode'] ?? 'custom_http';
		$schema_preset = $entry['schema_preset'] ?? '';
		$endpoint_placeholder = ( $type === 'huggingface' )
			? 'https://router.huggingface.co/v1'
			: ( $type === 'huggingface_spaces' ? 'https://owner-space.hf.space/generate' : 'https://xxx.services.ai.azure.com' );
		$org      = $entry['org'] ?? '';
		$free     = ! empty( $entry['free_pass_through'] );
		$key_id   = 'key-' . ( is_numeric( $index ) ? $index : str_replace( '{{INDEX}}', 'tpl', $index ) );
		?>
		<tr data-type="<?php echo esc_attr( $type ); ?>" data-entry-id="<?php echo esc_attr( $id ); ?>">
			<td class="entry-type">
				<input type="hidden" name="entries[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $id ); ?>" />
				<select name="entries[<?php echo esc_attr( $index ); ?>][type]" class="alorbach-type-select">
					<?php foreach ( self::$type_options as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $type, $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="entry-name">
				<input type="text" name="entries[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'alorbach-ai-gateway' ); ?>" class="regular-text" />
			</td>
			<td class="entry-key"<?php if ( $type === 'codex' ) echo ' colspan="3"'; ?>>
				<div class="input-with-actions">
					<input type="password" id="<?php echo esc_attr( $key_id ); ?>" name="entries[<?php echo esc_attr( $index ); ?>][api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" />
					<button type="button" class="button alorbach-toggle-pw" data-target="<?php echo esc_attr( $key_id ); ?>"><?php esc_html_e( 'Show', 'alorbach-ai-gateway' ); ?></button>
					<button type="button" class="button alorbach-test-key"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
				</div>
				<?php if ( $type === 'codex' ) : ?>
				<?php
				$codex_connected     = Codex_OAuth::is_connected();
				$pending_auth_url    = get_transient( 'alorbach_codex_pending_auth_url' );
				$authorize_url       = wp_nonce_url( admin_url( 'admin-post.php?action=alorbach_codex_authorize' ), 'alorbach_codex_authorize' );
				?>
				<div class="codex-inline-ui" style="margin-top:0;">
					<?php if ( $pending_auth_url && ! $codex_connected ) : ?>
					<?php /* Step 2 UI: show URL to open + paste form */ ?>
					<div style="margin-bottom:6px;">
						<span style="color:#d63638;font-weight:600;">&#9679; <?php esc_html_e( 'Not connected', 'alorbach-ai-gateway' ); ?></span>
					</div>
					<p style="margin:4px 0;font-size:12px;"><strong><?php esc_html_e( 'Step 1:', 'alorbach-ai-gateway' ); ?></strong>
						<?php esc_html_e( 'Open this URL in your local browser and sign in with your ChatGPT Plus/Pro account:', 'alorbach-ai-gateway' ); ?>
					</p>
					<textarea readonly rows="3" style="width:100%;font-size:11px;word-break:break-all;margin-bottom:4px;" onclick="this.select();"><?php echo esc_textarea( $pending_auth_url ); ?></textarea>
					<p style="margin:4px 0;font-size:12px;"><strong><?php esc_html_e( 'Step 2:', 'alorbach-ai-gateway' ); ?></strong>
						<?php esc_html_e( 'After signing in, your browser will show a connection error (this is expected). Copy the full URL from the address bar and paste it below:', 'alorbach-ai-gateway' ); ?>
					</p>
					<div style="margin-top:4px;">
						<textarea id="alorbach-codex-redirect-url" rows="2" placeholder="http://localhost:1455/auth/callback?code=...&amp;state=..." style="width:100%;font-size:11px;"></textarea>
						<button type="button" class="button button-primary button-small" id="alorbach-codex-connect-btn" style="margin-top:4px;"><?php esc_html_e( 'Submit &amp; Connect', 'alorbach-ai-gateway' ); ?></button>
					</div>
					<?php else : ?>
					<div class="codex-inline-actions">
						<?php if ( $codex_connected ) : ?>
							<span class="codex-status" style="color:#00a32a;">&#10003; <?php esc_html_e( 'Connected', 'alorbach-ai-gateway' ); ?></span>
							<span class="codex-action-buttons">
								<button type="button" class="button button-small" onclick="document.getElementById('alorbach-codex-disconnect-flag').value='1';document.getElementById('alorbach-api-keys-form').submit();"><?php esc_html_e( 'Disconnect', 'alorbach-ai-gateway' ); ?></button>
								<a href="<?php echo esc_url( $authorize_url ); ?>" class="button button-small"><?php esc_html_e( 'Re-authorize', 'alorbach-ai-gateway' ); ?></a>
							</span>
						<?php else : ?>
							<span class="codex-status" style="color:#d63638;">&#9679; <?php esc_html_e( 'Not connected', 'alorbach-ai-gateway' ); ?></span>
							<span class="codex-action-buttons">
								<a href="<?php echo esc_url( $authorize_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Get Authorization URL', 'alorbach-ai-gateway' ); ?></a>
							</span>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<span class="alorbach-test-result"></span>
			</td>
			<?php if ( $type !== 'codex' ) : ?>
			<td class="entry-endpoint">
				<input type="url" name="entries[<?php echo esc_attr( $index ); ?>][endpoint]" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="<?php echo esc_attr( $endpoint_placeholder ); ?>" class="large-text" />
				<div class="spaces-extra-fields">
					<input type="text" name="entries[<?php echo esc_attr( $index ); ?>][space_id]" value="<?php echo esc_attr( $space_id ); ?>" placeholder="owner/space-name" class="regular-text" />
					<select name="entries[<?php echo esc_attr( $index ); ?>][request_mode]">
						<option value="custom_http" <?php selected( $request_mode, 'custom_http' ); ?>><?php esc_html_e( 'Custom HTTP', 'alorbach-ai-gateway' ); ?></option>
						<option value="gradio_api" <?php selected( $request_mode, 'gradio_api' ); ?>><?php esc_html_e( 'Gradio API', 'alorbach-ai-gateway' ); ?></option>
					</select>
					<input type="text" name="entries[<?php echo esc_attr( $index ); ?>][schema_preset]" value="<?php echo esc_attr( $schema_preset ); ?>" placeholder="Optional manual endpoint name" class="regular-text" />
				</div>
			</td>
			<td class="entry-org">
				<input type="text" name="entries[<?php echo esc_attr( $index ); ?>][org]" value="<?php echo esc_attr( $org ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'alorbach-ai-gateway' ); ?>" class="regular-text" />
			</td>
			<?php endif; ?>
			<td class="entry-free">
				<span class="alorbach-tooltip-wrap" title="<?php echo esc_attr__( 'GitHub Pro: higher free limits. Use Free pass-through to skip charging credits.', 'alorbach-ai-gateway' ); ?>">
					<input type="checkbox" name="entries[<?php echo esc_attr( $index ); ?>][free_pass_through]" value="1" <?php checked( $free ); ?> />
					<span class="dashicons dashicons-editor-help alorbach-tooltip-icon" aria-hidden="true"></span>
				</span>
			</td>
			<td class="entry-enabled">
				<input type="checkbox" name="entries[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
			</td>
			<td class="entry-actions">
				<button type="button" class="button button-link-delete alorbach-delete-entry"><?php esc_html_e( 'Delete', 'alorbach-ai-gateway' ); ?></button>
			</td>
		</tr>
		<?php
	}
}
