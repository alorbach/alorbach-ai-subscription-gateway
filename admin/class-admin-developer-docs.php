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
			<?php self::section_hooks(); ?>		<?php self::section_custom_providers(); ?>			<?php self::section_php(); ?>
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
					<td><?php esc_html_e( 'Chat completion (messages, model, max_tokens). Supports optional multi-step mode: multi_step, max_steps, continue_message.', 'alorbach-ai-gateway' ); ?></td>
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

// JavaScript: Standard single-step chat completion
const res = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/chat' ) ); ?>', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': alorbachDemo.nonce },
  body: JSON.stringify({
    messages: [{ role: 'user', content: 'Hello' }],
    model: 'gpt-4.1-mini',
    max_tokens: 256
  })
});
const data = await res.json();
console.log( data.choices[0].message.content );
console.log( 'Cost: ' + data.cost_credits + ' credits' );</code></pre>

		<h3><?php esc_html_e( 'Multi-Step Chat (Large Responses)', 'alorbach-ai-gateway' ); ?></h3>
		<p><?php esc_html_e( 'AI models truncate output when a response exceeds max_tokens, returning finish_reason: "length". Enable multi_step mode to have the gateway automatically continue and concatenate all chunks server-side, returning a single combined response.', 'alorbach-ai-gateway' ); ?></p>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Parameter', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Type', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Default', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>multi_step</code></td>
					<td><?php esc_html_e( 'bool', 'alorbach-ai-gateway' ); ?></td>
					<td><code>false</code></td>
					<td><?php esc_html_e( 'Enable automatic continuation. When true, the gateway loops until a non-length finish_reason is received or max_steps is reached.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>max_steps</code></td>
					<td><?php esc_html_e( 'int', 'alorbach-ai-gateway' ); ?></td>
					<td><code>5</code></td>
					<td><?php esc_html_e( 'Maximum continuation steps. Clamped to 1–20 server-side regardless of the value sent.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>continue_message</code></td>
					<td><?php esc_html_e( 'string', 'alorbach-ai-gateway' ); ?></td>
					<td><?php esc_html_e( '"Continue exactly where you left off without any preamble or repetition."', 'alorbach-ai-gateway' ); ?></td>
					<td><?php esc_html_e( 'Custom prompt injected as a user message after each truncated chunk to instruct the model how to continue.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'The combined response includes all standard chat fields plus:', 'alorbach-ai-gateway' ); ?></p>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Response Field', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Type', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>steps_count</code></td>
					<td><?php esc_html_e( 'int', 'alorbach-ai-gateway' ); ?></td>
					<td><?php esc_html_e( 'Number of API calls made (1 when no continuation was needed).', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>steps[]</code></td>
					<td><?php esc_html_e( 'array', 'alorbach-ai-gateway' ); ?></td>
					<td><?php esc_html_e( 'Per-step breakdown: { step, finish_reason, completion_tokens, cost_uc }. Useful for debugging and cost attribution.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>usage</code></td>
					<td><?php esc_html_e( 'object', 'alorbach-ai-gateway' ); ?></td>
					<td><?php esc_html_e( 'Token counts are summed across all steps (prompt_tokens, completion_tokens, total_tokens).', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>cost_uc / cost_credits / cost_usd</code></td>
					<td><?php esc_html_e( 'int / string / string', 'alorbach-ai-gateway' ); ?></td>
					<td><?php esc_html_e( 'Total cost across all steps. The ledger records one entry per step for a full audit trail.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>// JavaScript: Multi-step chat — generate a long document automatically
const res = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/chat' ) ); ?>', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': alorbachDemo.nonce },
  body: JSON.stringify({
    messages: [{ role: 'user', content: 'Write a comprehensive 2000-word article about renewable energy.' }],
    model: 'gpt-4.1-mini',
    max_tokens: 1024,
    multi_step: true,
    max_steps: 10
    // continue_message: 'Keep going.' // optional custom prompt
  })
});
const data = await res.json();

// The full concatenated article is in choices[0].message.content
console.log( data.choices[0].message.content );

// Developer metadata
console.log( 'Completed in ' + data.steps_count + ' step(s)' );
console.log( 'Total tokens:', data.usage.total_tokens );
console.log( 'Total cost:', data.cost_credits + ' credits' );

// Per-step breakdown
data.steps.forEach( s => {
  console.log( `Step ${s.step}: finish_reason=${s.finish_reason}, tokens=${s.completion_tokens}, cost=${s.cost_uc} UC` );
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
					<td>$user_cost, $api_cost_uc, $model</td>
					<td><?php esc_html_e( 'Modify user charge after markup applied. $user_cost is the marked-up UC amount; $api_cost_uc is the raw API cost.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_chat_request_body</code></td>
					<td>$body, $user_id, $model</td>
					<td><?php esc_html_e( 'Modify the request body sent to the AI provider for chat (inject temperature, tools, response_format, etc.)', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_count_tokens</code></td>
					<td>$count, $text, $model</td>
					<td><?php esc_html_e( 'Override token count for a given text and model. Return an integer to short-circuit the tokenizer.', 'alorbach-ai-gateway' ); ?></td>
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
				<tr>
					<td><code>alorbach_video_poll_max</code></td>
					<td>$max (int)</td>
					<td><?php esc_html_e( 'Maximum polling iterations when waiting for video generation. Default: 60.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_video_poll_interval</code></td>
					<td>$interval (int)</td>
					<td><?php esc_html_e( 'Seconds between video generation poll requests. Default: 5.', 'alorbach-ai-gateway' ); ?></td>
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
					<td><code>alorbach_register_providers</code></td>
					<td>—</td>
					<td><?php esc_html_e( 'Fires after built-in providers are registered. Call Provider_Registry::register() here to add custom providers.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_credits_added</code></td>
					<td>$user_id, $credits_uc, $source</td>
					<td><?php esc_html_e( 'Fired when credits are added. $source: stripe | woocommerce | woocommerce_retry.', 'alorbach-ai-gateway' ); ?></td>
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
					<td><code>alorbach_after_deduction</code></td>
					<td>$user_id, $type, $model, $cost_uc, $api_cost_uc</td>
					<td><?php esc_html_e( 'Fired after credits are deducted for any request (type: chat, image, audio, video). $cost_uc is the user charge; $api_cost_uc is the raw API cost.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_subscription_payment_failed</code></td>
					<td>$subscription, $last_order</td>
					<td><?php esc_html_e( 'Fired when WooCommerce subscription payment fails', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_wc_renewal_retry_failed</code></td>
					<td>$user_id, $credits_uc</td>
					<td><?php esc_html_e( 'Fired when the WooCommerce renewal retry (5 min after failure) also fails to credit the user.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
				<tr>
					<td><code>alorbach_video_poll_timeout</code></td>
					<td>$video_id, $provider</td>
					<td><?php esc_html_e( 'Fired when video generation polling times out without a completed or failed status.', 'alorbach-ai-gateway' ); ?></td>
				</tr>
			</tbody>
		</table>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>// Example: Custom per-model markup
add_filter( 'alorbach_user_cost', function( $user_cost, $api_cost_uc, $model ) {
  // Free for certain models, 2× for everything else
  if ( str_starts_with( $model, 'gpt-4.1-mini' ) ) return $api_cost_uc;
  return (int) ( $api_cost_uc * 2 );
}, 10, 3 );

// Example: Add a system prompt to every chat request
add_filter( 'alorbach_chat_request_body', function( $body, $user_id, $model ) {
  array_unshift( $body['messages'], [ 'role' => 'system', 'content' => 'Be concise.' ] );
  return $body;
}, 10, 3 );

// Example: Register a custom AI provider
add_action( 'alorbach_register_providers', function() {
  \Alorbach\AIGateway\Providers\Provider_Registry::register( new My_Custom_Provider() );
} );

// Example: Override balance display
add_filter( 'alorbach_balance_display', function( $html, $user_id, $balance ) {
  return '<span class="my-balance">' . esc_html( $balance / 1000 ) . ' credits</span>';
}, 10, 3 );</code></pre>
		<?php
	}

	/**
	 * Custom providers section.
	 */
	private static function section_custom_providers() {
		?>
		<h2><?php esc_html_e( 'Custom AI Providers', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'Add any AI backend by implementing Provider_Interface (or extending Provider_Base) and registering it on the alorbach_register_providers action.', 'alorbach-ai-gateway' ); ?></p>
		<pre style="background: #f6f7f7; padding: 1rem; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto;"><code>use Alorbach\AIGateway\Providers\Provider_Base;
use Alorbach\AIGateway\Providers\Provider_Registry;

class My_Custom_Provider extends Provider_Base {

    public function get_type()      { return 'my_provider'; }
    public function supports_chat() { return true; }

    public function build_chat_request( $body, $credentials ) {
        $api_key = $credentials['api_key'] ?? '';
        if ( empty( $api_key ) ) {
            return new \WP_Error( 'no_key', 'Missing API key.' );
        }
        $body = self::normalize_chat_body( $body, $body['model'] ?? '' );
        return [
            'url'     => 'https://api.my-provider.com/v1/chat/completions',
            'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ];
    }

    public function verify_key( $credentials )  { return true; }
    public function fetch_models( $credentials ) { return [ 'my-model-v1' ]; }
}

add_action( 'alorbach_register_providers', function() {
    Provider_Registry::register( new My_Custom_Provider() );
} );</code></pre>
		<p>
			<?php
			printf(
				/* translators: %s: method list */
				esc_html__( 'Provider_Base provides no-op stubs for %s so you only implement what your backend supports.', 'alorbach-ai-gateway' ),
				'<code>supports_images()</code>, <code>supports_audio()</code>, <code>supports_video()</code>, <code>build_images_request()</code>, <code>build_transcribe_request()</code>, <code>build_video_request()</code>'
			);
			?>
		</p>
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
