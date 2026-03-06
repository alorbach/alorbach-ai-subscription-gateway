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
			echo '<div class="notice notice-success"><p>' . esc_html__( 'API keys saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'API Keys', 'alorbach-ai-gateway' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'alorbach_api_keys', 'alorbach_api_keys_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="openai"><?php esc_html_e( 'OpenAI API Key', 'alorbach-ai-gateway' ); ?></label></th>
						<td><input type="password" id="openai" name="openai" value="<?php echo esc_attr( isset( $keys['openai'] ) ? $keys['openai'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="azure_endpoint"><?php esc_html_e( 'Azure OpenAI Endpoint', 'alorbach-ai-gateway' ); ?></label></th>
						<td><input type="url" id="azure_endpoint" name="azure_endpoint" value="<?php echo esc_attr( isset( $keys['azure_endpoint'] ) ? $keys['azure_endpoint'] : '' ); ?>" class="regular-text" placeholder="https://xxx.openai.azure.com/" /></td>
					</tr>
					<tr>
						<th><label for="azure"><?php esc_html_e( 'Azure OpenAI API Key', 'alorbach-ai-gateway' ); ?></label></th>
						<td><input type="password" id="azure" name="azure" value="<?php echo esc_attr( isset( $keys['azure'] ) ? $keys['azure'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="google"><?php esc_html_e( 'Google (Gemini) API Key', 'alorbach-ai-gateway' ); ?></label></th>
						<td><input type="password" id="google" name="google" value="<?php echo esc_attr( isset( $keys['google'] ) ? $keys['google'] : '' ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'alorbach-ai-gateway' ); ?>" /></p>
			</form>
		</div>
		<?php
	}
}
