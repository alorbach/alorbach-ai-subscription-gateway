<?php
/**
 * Admin: Developer documentation.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Developer_Docs
 */
class Admin_Developer_Docs {

	/**
	 * Render Developer docs page.
	 */
	public static function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Developer Documentation', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Code samples and API reference for integrating with the Alorbach AI Subscription Gateway.', 'alorbach-ai-gateway' ); ?></p>

			<?php self::section_shortcodes(); ?>
			<?php self::section_rest_api(); ?>
			<?php self::section_hooks(); ?>
			<?php self::section_php(); ?>
		</div>
		<?php
	}

	/**
	 * Shortcodes section.
	 */
	private static function section_shortcodes() {
		?>
		<h2><?php esc_html_e( 'Shortcodes', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'Add demo pages to any post or page. Users must be logged in.', 'alorbach-ai-gateway' ); ?></p>
		<table class="widefat striped" style="max-width: 800px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Shortcode', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>[alorbach_demo_chat]</code></td>
					<td><?php esc_html_e( 'AI Chat demo (text completions)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>[alorbach_demo_image]</code></td>
					<td><?php esc_html_e( 'Image generation demo', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>[alorbach_demo_transcribe]</code></td>
					<td><?php esc_html_e( 'Audio transcription demo (Whisper)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>[alorbach_demo_video]</code></td>
					<td><?php esc_html_e( 'Video generation demo (Sora)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>[alorbach_balance]</code></td>
					<td><?php esc_html_e( 'Display current user credit balance', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>[alorbach_usage_month]</code></td>
					<td><?php esc_html_e( 'Display current user usage this month', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>// Example: Add chat demo to a page
[alorbach_demo_chat]

// Example: Show balance and usage
[alorbach_balance]
[alorbach_usage_month]</code></pre>
		<?php
	}

	/**
	 * REST API section.
	 */
	private static function section_rest_api() {
		?>
		<h2><?php esc_html_e( 'REST API Endpoints', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'Base URL:', 'alorbach-ai-gateway' ); ?> <code><?php echo esc_html( rest_url( 'alorbach/v1' ) ); ?></code></p>
		<p><?php esc_html_e( 'All endpoints require authentication (logged-in user). Send X-WP-Nonce header for nonce-based auth.', 'alorbach-ai-gateway' ); ?></p>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Method', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Endpoint', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>GET</td>
					<td><code>/me/balance</code></td>
					<td><?php esc_html_e( 'Current user balance (UC, credits, USD)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>GET</td>
					<td><code>/me/usage</code></td>
					<td><?php esc_html_e( 'Current user usage (period: month|week)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>GET</td>
					<td><code>/me/models</code></td>
					<td><?php esc_html_e( 'Configured models for demos (text, image, audio, video)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>GET</td>
					<td><code>/me/estimate</code></td>
					<td><?php esc_html_e( 'Cost estimate (type: image|video|audio, size, quality, n, etc.)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>POST</td>
					<td><code>/chat</code></td>
					<td><?php esc_html_e( 'Chat completion (messages, model, max_tokens)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>POST</td>
					<td><code>/images</code></td>
					<td><?php esc_html_e( 'Image generation (prompt, size, n, quality)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>POST</td>
					<td><code>/transcribe</code></td>
					<td><?php esc_html_e( 'Audio transcription (audio_base64, duration_seconds, model, prompt)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td>POST</td>
					<td><code>/video</code></td>
					<td><?php esc_html_e( 'Video generation (prompt, model)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>// JavaScript: Fetch balance
const response = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/me/balance' ) ); ?>', {
  headers: { 'X-WP-Nonce': alorbachDemo.nonce }
});
const { balance_uc, balance_credits } = await response.json();

// JavaScript: Chat completion
const res = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/chat' ) ); ?>', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': alorbachDemo.nonce
  },
  body: JSON.stringify({
    messages: [{ role: 'user', content: 'Hello' }],
    model: 'gpt-4.1-mini',
    max_tokens: 256
  })
});</code></pre>
		<?php
	}

	/**
	 * Hooks section.
	 */
	private static function section_hooks() {
		?>
		<h2><?php esc_html_e( 'Hooks & Filters', 'alorbach-ai-gateway' ); ?></h2>
		<h3><?php esc_html_e( 'Filters', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Filter', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Args', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>alorbach_cost_matrix</code></td>
					<td>$saved</td>
					<td><?php esc_html_e( 'Modify cost matrix (text model costs)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_image_costs</code></td>
					<td>$costs</td>
					<td><?php esc_html_e( 'Modify DALL-E image costs by size', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_image_model_costs</code></td>
					<td>$model_costs</td>
					<td><?php esc_html_e( 'Modify GPT Image model costs (quality × size)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_video_costs</code></td>
					<td>$costs</td>
					<td><?php esc_html_e( 'Modify video model costs', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_audio_costs</code></td>
					<td>$costs</td>
					<td><?php esc_html_e( 'Modify audio model costs (per second)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_user_cost</code></td>
					<td>$user_cost, $api_cost_uc, $context</td>
					<td><?php esc_html_e( 'Modify user charge after markup applied', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_uc_to_credit_ratio</code></td>
					<td>—</td>
					<td><?php esc_html_e( 'Override UC-to-credit ratio (default 1000)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_credits_label</code></td>
					<td>$label, $uc_amount</td>
					<td><?php esc_html_e( 'Override "Credits" label in display', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_balance_display</code></td>
					<td>$html, $user_id, $balance</td>
					<td><?php esc_html_e( 'Override balance shortcode output', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_usage_display</code></td>
					<td>$html, $user_id, $usage, $period</td>
					<td><?php esc_html_e( 'Override usage shortcode output', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_plans</code></td>
					<td>$saved</td>
					<td><?php esc_html_e( 'Modify subscription plans', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_create_sample_pages_on_activation</code></td>
					<td>—</td>
					<td><?php esc_html_e( 'Return true to create sample pages on plugin activation', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Action', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Args', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>alorbach_credits_added</code></td>
					<td>$user_id, $credits, $source</td>
					<td><?php esc_html_e( 'Fired when credits are added (stripe, woocommerce)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_stripe_webhook</code></td>
					<td>$event_type, $event</td>
					<td><?php esc_html_e( 'Fired on Stripe webhook (before processing)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_stripe_payment_failed</code></td>
					<td>$event</td>
					<td><?php esc_html_e( 'Fired when Stripe payment fails', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_stripe_subscription_deleted</code></td>
					<td>$event</td>
					<td><?php esc_html_e( 'Fired when Stripe subscription is deleted', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_subscription_payment_failed</code></td>
					<td>$subscription, $last_order</td>
					<td><?php esc_html_e( 'Fired when WooCommerce subscription payment fails', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>// Example: Modify user cost
add_filter( 'alorbach_user_cost', function( $user_cost, $api_cost_uc, $context ) {
  return (int) ( $api_cost_uc * 1.5 ); // Custom 50% markup
}, 10, 3 );

// Example: Override balance display
add_filter( 'alorbach_balance_display', function( $html, $user_id, $balance ) {
  return '<span class="my-balance">' . esc_html( $balance / 1000 ) . ' credits</span>';
}, 10, 3 );</code></pre>
		<?php
	}

	/**
	 * PHP usage section.
	 */
	private static function section_php() {
		?>
		<h2><?php esc_html_e( 'PHP Usage', 'alorbach-ai-gateway' ); ?></h2>
		<h3><?php esc_html_e( 'Template tags', 'alorbach-ai-gateway' ); ?></h3>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>// Get user balance in UC
$balance = alorbach_get_user_balance( $user_id ); // null = current user

// Get usage this month in UC
$usage = alorbach_get_user_usage_this_month( $user_id );

// Format UC as credits string (e.g. "1,234.56")
$credits_str = alorbach_format_credits( $uc_amount );</code></pre>
		<h3><?php esc_html_e( 'Classes', 'alorbach-ai-gateway' ); ?></h3>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>use Alorbach\AIGateway\Ledger;
use Alorbach\AIGateway\User_Display;
use Alorbach\AIGateway\Cost_Matrix;

// Get balance
$balance = Ledger::get_balance( $user_id );

// Format for display
$credits = User_Display::uc_to_credits( $balance );
$usd     = User_Display::format_uc_as_usd( $balance );

// Get cost for a model (API cost in UC)
$chat_cost = Cost_Matrix::calculate_chat_cost( $model, $prompt_tokens, $completion_tokens, $cached_tokens );
$image_cost = Cost_Matrix::get_image_cost( $size, $model, $quality );
$audio_cost = Cost_Matrix::get_audio_cost( $seconds, $model );
$video_cost = Cost_Matrix::get_video_cost( $model );

// Apply markup to get user charge
$user_cost = Cost_Matrix::apply_user_cost( $api_cost );

// Insert transaction (admin credit)
Ledger::insert_transaction( $user_id, 'admin_credit', null, $uc_amount );</code></pre>
		<?php
	}
}
