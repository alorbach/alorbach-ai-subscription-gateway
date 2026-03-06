<?php
/**
 * Admin: Stripe webhook configuration.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Stripe_Webhook
 */
class Admin_Stripe_Webhook {

	/**
	 * Render Stripe Webhook page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_stripe_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_stripe_nonce'] ) ), 'alorbach_stripe' ) ) {
			$secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
			update_option( 'alorbach_stripe_webhook_secret', $secret );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Stripe webhook secret saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$webhook_url = rest_url( 'alorbach/v1/stripe-webhook' );
		$secret      = get_option( 'alorbach_stripe_webhook_secret', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stripe Webhook', 'alorbach-ai-gateway' ); ?></h1>
			<p><?php esc_html_e( 'Add this URL to your Stripe Dashboard under Developers > Webhooks:', 'alorbach-ai-gateway' ); ?></p>
			<p><code><?php echo esc_url( $webhook_url ); ?></code></p>
			<form method="post">
				<?php wp_nonce_field( 'alorbach_stripe', 'alorbach_stripe_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="webhook_secret"><?php esc_html_e( 'Webhook Signing Secret', 'alorbach-ai-gateway' ); ?></label></th>
						<td><input type="password" id="webhook_secret" name="webhook_secret" value="<?php echo esc_attr( $secret ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'alorbach-ai-gateway' ); ?>" /></p>
			</form>
		</div>
		<?php
	}
}
