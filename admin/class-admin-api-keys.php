<?php
/**
 * Admin: API Keys configuration (grid).
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

use Alorbach\AIGateway\API_Keys_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_API_Keys
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
		'github_models' => 'GitHub Models',
	);

	/**
	 * Render API Keys page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_api_keys_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_api_keys_nonce'] ) ), 'alorbach_api_keys' ) ) {
			$entries = array();
			$raw     = isset( $_POST['entries'] ) && is_array( $_POST['entries'] ) ? $_POST['entries'] : array();
			foreach ( $raw as $e ) {
				$type = isset( $e['type'] ) ? sanitize_text_field( $e['type'] ) : '';
				if ( ! in_array( $type, array( 'openai', 'azure', 'google', 'github_models' ), true ) ) {
					continue;
				}
				$entry = array(
					'id'      => isset( $e['id'] ) ? sanitize_text_field( $e['id'] ) : '',
					'type'    => $type,
					'api_key' => isset( $e['api_key'] ) ? sanitize_text_field( wp_unslash( $e['api_key'] ) ) : '',
					'enabled' => ! empty( $e['enabled'] ),
					'name'    => isset( $e['name'] ) ? sanitize_text_field( wp_unslash( $e['name'] ) ) : '',
				);
				if ( $type === 'azure' && isset( $e['endpoint'] ) ) {
					$entry['endpoint'] = esc_url_raw( wp_unslash( $e['endpoint'] ) );
				}
				if ( $type === 'github_models' ) {
					if ( isset( $e['org'] ) ) {
						$entry['org'] = sanitize_text_field( wp_unslash( $e['org'] ) );
					}
					$entry['free_pass_through'] = ! empty( $e['free_pass_through'] );
				}
				$entries[] = $entry;
			}
			API_Keys_Helper::save_entries( $entries );
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

				<p class="submit">
					<button type="button" class="button" id="alorbach-add-entry"><?php esc_html_e( 'Add API Key', 'alorbach-ai-gateway' ); ?></button>
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save API Keys', 'alorbach-ai-gateway' ); ?>" />
				</p>
			</form>

			<template id="alorbach-entry-row-tpl">
				<?php self::render_entry_row( '{{INDEX}}', array( 'id' => '', 'type' => 'openai', 'api_key' => '', 'enabled' => true, 'name' => '', 'endpoint' => '', 'org' => '', 'free_pass_through' => false ) ); ?>
			</template>

			<style>
			.alorbach-api-keys { max-width: 1400px; }
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
			.alorbach-api-keys tr[data-type="azure"] .entry-endpoint { visibility: visible; }
			.alorbach-api-keys tr[data-type="github_models"] .entry-org, .alorbach-api-keys tr[data-type="github_models"] .entry-free { visibility: visible; }
			.alorbach-api-keys .input-with-actions { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
			.alorbach-api-keys .input-with-actions input { flex: 1; min-width: 0; max-width: 100%; }
			.alorbach-api-keys .alorbach-api-keys-table input,
			.alorbach-api-keys .alorbach-api-keys-table select { max-width: 100%; box-sizing: border-box; }
			.alorbach-api-keys .alorbach-tooltip-wrap { display: inline-flex; align-items: center; gap: 4px; }
			.alorbach-api-keys .alorbach-tooltip-icon { font-size: 16px; width: 16px; height: 16px; color: #646970; cursor: help; }
			.alorbach-api-keys .entry-enabled, .alorbach-api-keys .entry-actions { white-space: nowrap; vertical-align: middle; }
			.alorbach-api-keys .entry-enabled input { margin: 0; }
			.alorbach-api-keys .submit { margin-top: 1rem; }
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
						var resultEl = row.querySelector('.alorbach-test-result');
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
				}
				if (delBtn) {
					delBtn.addEventListener('click', function() { row.remove(); });
				}
			}

			document.querySelectorAll('#alorbach-entries-tbody tr').forEach(initRow);

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
		$org      = $entry['org'] ?? '';
		$free     = ! empty( $entry['free_pass_through'] );
		$key_id   = 'key-' . ( is_numeric( $index ) ? $index : str_replace( '{{INDEX}}', 'tpl', $index ) );
		?>
		<tr data-type="<?php echo esc_attr( $type ); ?>">
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
			<td class="entry-key">
				<div class="input-with-actions">
					<input type="password" id="<?php echo esc_attr( $key_id ); ?>" name="entries[<?php echo esc_attr( $index ); ?>][api_key]" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" />
					<button type="button" class="button alorbach-toggle-pw" data-target="<?php echo esc_attr( $key_id ); ?>"><?php esc_html_e( 'Show', 'alorbach-ai-gateway' ); ?></button>
					<button type="button" class="button alorbach-test-key"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
				</div>
				<span class="alorbach-test-result"></span>
			</td>
			<td class="entry-endpoint">
				<input type="url" name="entries[<?php echo esc_attr( $index ); ?>][endpoint]" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="https://xxx.services.ai.azure.com" class="large-text" />
			</td>
			<td class="entry-org">
				<input type="text" name="entries[<?php echo esc_attr( $index ); ?>][org]" value="<?php echo esc_attr( $org ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'alorbach-ai-gateway' ); ?>" class="regular-text" />
			</td>
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
