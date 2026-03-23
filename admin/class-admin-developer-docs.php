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
			<p class="description"><?php esc_html_e( 'Stable downstream integration APIs, demo endpoints, hooks, and example usage for the Alorbach AI Subscription Gateway.', 'alorbach-ai-gateway' ); ?></p>

			<?php self::section_shortcodes(); ?>
			<?php self::section_rest_api(); ?>
			<?php self::section_payloads(); ?>
			<?php self::section_hooks(); ?>
			<?php self::section_php(); ?>
			<?php self::section_downstream_example(); ?>
			<?php self::section_custom_providers(); ?>
		</div>
		<?php
	}

	/**
	 * Shortcodes section.
	 */
	private static function section_shortcodes() {
		?>
		<h2><?php esc_html_e( 'Shortcodes', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'Demo shortcodes are intended for gateway-owned sample pages. The account widget shortcode is the stable downstream embeddable surface.', 'alorbach-ai-gateway' ); ?></p>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Shortcode', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>[alorbach_account_widget]</code></td><td><?php esc_html_e( 'Embeddable account widget with balance, usage, billing links, and recent history', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>[alorbach_balance]</code></td><td><?php esc_html_e( 'Display current user credit balance', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>[alorbach_usage_month]</code></td><td><?php esc_html_e( 'Display current user usage this month', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>[alorbach_demo_chat]</code></td><td><?php esc_html_e( 'Gateway demo chat UI', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>[alorbach_demo_image]</code></td><td><?php esc_html_e( 'Gateway demo image UI', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>[alorbach_demo_transcribe]</code></td><td><?php esc_html_e( 'Gateway demo audio transcription UI', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>[alorbach_demo_video]</code></td><td><?php esc_html_e( 'Gateway demo video UI', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Account Widget Attributes', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Default', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>history_items</code></td><td><code>5</code></td><td><?php esc_html_e( 'Number of recent history items to render', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>show_history</code></td><td><code>true</code></td><td><?php esc_html_e( 'Set to `false` to hide the recent activity list', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'If the visitor is not logged in, the account widget returns an empty string. If billing/account URLs are unset, the corresponding CTA is omitted.', 'alorbach-ai-gateway' ); ?></p>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>[alorbach_account_widget history_items="5"]
[alorbach_account_widget history_items="3" show_history="false"]
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
		<p><?php esc_html_e( 'Stable downstream contract: `/integration/config`, `/integration/plans`, `/integration/account`, `/integration/account/history`. The first two are public-readable; account endpoints require a logged-in user.', 'alorbach-ai-gateway' ); ?></p>

		<h3><?php esc_html_e( 'Downstream Contract', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Method', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Endpoint', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td>GET</td><td><code>/integration/config</code></td><td><?php esc_html_e( 'Backend defaults, supported capabilities, canonical billing/account URLs, and logged-in plan filtering. Public-readable.', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>GET</td><td><code>/integration/plans</code></td><td><?php esc_html_e( 'Canonical plan catalog including capabilities and allowed-model lists. Returns active plans by default. Public-readable.', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>GET</td><td><code>/integration/account</code></td><td><?php esc_html_e( 'Current user account summary: balance, usage, billing URLs, renewal summary. Logged-in users only.', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>GET</td><td><code>/integration/account/history</code></td><td><?php esc_html_e( 'Current user recent credit history in a frontend-safe shape. Logged-in users only.', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Parameters', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Parameters', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>/integration/config</code></td><td><code>none</code></td><td><?php esc_html_e( 'Read-only public config', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>/integration/plans</code></td><td><code>include_inactive</code> (boolean)</td><td><?php esc_html_e( 'Inactive plans are hidden by default. Downstream frontends should usually leave this unset.', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>/integration/account</code></td><td><code>none</code></td><td><?php esc_html_e( 'Returns current logged-in user only', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>/integration/account/history</code></td><td><code>page</code>, <code>per_page</code></td><td><?php esc_html_e( 'Paginated current-user history. `per_page` defaults to 10.', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Authentication', 'alorbach-ai-gateway' ); ?></h3>
		<p><?php esc_html_e( 'Public endpoints do not require authentication. Logged-in account endpoints use standard WordPress REST authentication. In browser-based integrations, send the logged-in cookies plus an `X-WP-Nonce` header created with `wp_create_nonce( \'wp_rest\' )`.', 'alorbach-ai-gateway' ); ?></p>
		<p><?php esc_html_e( 'The nonce must come from your own frontend bootstrap or localized script data. Do not assume the demo UI nonce object exists in downstream products.', 'alorbach-ai-gateway' ); ?></p>

		<h3><?php esc_html_e( 'Legacy / Demo-Facing Endpoints', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Method', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Endpoint', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td>GET</td><td><code>/me/balance</code></td><td><?php esc_html_e( 'Current user balance', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>GET</td><td><code>/me/usage</code></td><td><?php esc_html_e( 'Current user usage (month|week)', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>GET</td><td><code>/me/models</code></td><td><?php esc_html_e( 'Gateway demo model settings. Prefer `/integration/config` for downstream products.', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>GET</td><td><code>/me/estimate</code></td><td><?php esc_html_e( 'Pre-flight estimate for image/video/audio', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>POST</td><td><code>/chat</code></td><td><?php esc_html_e( 'Chat completion with credit deduction', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>POST</td><td><code>/images</code></td><td><?php esc_html_e( 'Image generation with credit deduction', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>POST</td><td><code>/transcribe</code></td><td><?php esc_html_e( 'Audio transcription with credit deduction', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td>POST</td><td><code>/video</code></td><td><?php esc_html_e( 'Video generation with credit deduction', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>

		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>// Public plan + config fetch
const config = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/integration/config' ) ); ?>').then(r => r.json());
const plans = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/integration/plans' ) ); ?>').then(r => r.json());

// Logged-in account fetch using your own localized nonce
const account = await fetch('<?php echo esc_url( rest_url( 'alorbach/v1/integration/account' ) ); ?>', {
  credentials: 'same-origin',
  headers: { 'X-WP-Nonce': window.myGatewayData.restNonce }
}).then(r => r.json());</code></pre>
		<?php
	}

	/**
	 * Payload shapes section.
	 */
	private static function section_payloads() {
		?>
		<h2><?php esc_html_e( 'Response Shapes', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'These structures are the stable downstream contract. Prefer these payloads and PHP helpers over raw options or demo endpoint payloads.', 'alorbach-ai-gateway' ); ?></p>

		<h3><code>/integration/config</code></h3>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>{
  "defaults": {
    "chat_model": "gpt-4.1-mini",
    "image_model": "dall-e-3",
    "image_size": "1024x1024",
    "image_quality": "medium",
    "audio_model": "whisper-1",
    "video_model": "sora-2"
  },
  "capabilities": {
    "chat_models": ["..."],
    "image_models": ["..."],
    "image_sizes": ["..."],
    "image_qualities": ["low", "medium", "high"],
    "audio_models": ["..."],
    "video_models": ["..."],
    "video_sizes": ["..."],
    "video_durations": ["4", "8", "12"]
  },
  "plan_capabilities": {
    "chat": true,
    "image": false,
    "audio": false,
    "video": false
  },
  "active_plan": {
    "slug": "basic",
    "public_name": "Basic",
    "is_free": true,
    "is_active": true,
    "billing_interval": "month",
    "price_usd": 0,
    "included_credits_uc": 0,
    "capabilities": {
      "chat": true,
      "image": false,
      "audio": false,
      "video": false
    },
    "allowed_models": {
      "chat": [],
      "image": [],
      "audio": [],
      "video": []
    }
  },
  "billing_urls": {
    "subscribe": "",
    "top_up": "",
    "manage_account": "",
    "account_overview": ""
  }
}</code></pre>
		<p><?php esc_html_e( 'Billing URLs are returned exactly as configured by the admin. Empty strings mean the downstream UI should hide that CTA.', 'alorbach-ai-gateway' ); ?></p>
		<p><?php esc_html_e( 'When a user is logged in, the payload is filtered to their resolved plan. If a Basic user has a positive credit balance, the full configured catalog is exposed so those credits can be spent across the paid capabilities.', 'alorbach-ai-gateway' ); ?></p>

		<h3><code>/integration/plans</code></h3>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>[
  {
    "slug": "basic",
    "public_name": "Basic",
    "billing_interval": "month",
    "price_usd": 0,
    "included_credits_uc": 0,
    "included_credits_display": "0 Credits",
    "display_order": 0,
    "is_active": true,
    "is_free": true,
    "capabilities": {
      "chat": true,
      "image": false,
      "audio": false,
      "video": false
    },
    "allowed_models": {
      "chat": [],
      "image": [],
      "audio": [],
      "video": []
    }
  }
]</code></pre>
		<p><?php esc_html_e( 'Plans are normalized from stored data. Legacy fields such as `name` and `credits_per_month` are internal storage compatibility details, not the public contract.', 'alorbach-ai-gateway' ); ?></p>
		<p><?php esc_html_e( 'The protected `basic` plan is always available as the fallback when no manual override or active paid subscription resolves.', 'alorbach-ai-gateway' ); ?></p>

		<h3><code>/integration/account</code></h3>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>{
  "user_id": 123,
  "balance_uc": 450000,
  "balance": {
    "uc": 450000,
    "credits": 450,
    "display": "450 Credits",
    "usd": 0.45
  },
  "usage_month": {
    "uc": 1250000,
    "credits": 1250,
    "display": "1,250 Credits",
    "usd": 1.25
  },
  "billing_urls": {
    "subscribe": "",
    "top_up": "",
    "manage_account": "",
    "account_overview": ""
  },
  "active_plan": {
    "slug": "basic",
    "public_name": "Basic",
    "is_free": true,
    "is_active": true,
    "billing_interval": "month",
    "price_usd": 0,
    "included_credits_uc": 0,
    "capabilities": {
      "chat": true,
      "image": false,
      "audio": false,
      "video": false
    },
    "allowed_models": {
      "chat": [],
      "image": [],
      "audio": [],
      "video": []
    },
    "source": "basic"
  },
  "renewal": {
    "status": "active",
    "next_payment": "2026-04-01 10:00:00",
    "status_label": "Subscription active. Next renewal: April 1, 2026"
  }
}</code></pre>
		<p><?php esc_html_e( 'If WooCommerce Subscriptions is unavailable or no subscription metadata is found, `renewal` may be `null`.', 'alorbach-ai-gateway' ); ?></p>
		<p><?php esc_html_e( 'Plan resolution order is manual override, active mapped subscription, then Basic fallback. `active_plan.source` is `manual`, `subscription`, or `basic`.', 'alorbach-ai-gateway' ); ?></p>

		<h3><code>/integration/account/history</code></h3>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>{
  "items": [
    {
      "transaction_id": 99,
      "created_at": "2026-03-20 14:30:00",
      "transaction_type": "chat_deduction",
      "transaction_label": "Chat usage",
      "model": "gpt-4.1-mini",
      "amount": {
        "uc": -5000,
        "credits": -5,
        "display": "-5 Credits",
        "usd": -0.005
      }
    }
  ],
  "total": 42,
  "page": 1,
  "per_page": 10
}</code></pre>
		<?php
	}

	/**
	 * Hooks section.
	 */
	private static function section_hooks() {
		?>
		<h2><?php esc_html_e( 'Hooks & Filters', 'alorbach-ai-gateway' ); ?></h2>

		<h3><?php esc_html_e( 'Integration Filters', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Filter', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Args', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>alorbach_integration_plans</code></td><td>$plans, $args</td><td><?php esc_html_e( 'Filter normalized downstream plan catalog', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_integration_billing_urls</code></td><td>$urls</td><td><?php esc_html_e( 'Filter canonical billing/account URLs', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_integration_config</code></td><td>$config</td><td><?php esc_html_e( 'Filter normalized downstream config payload', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_integration_account_summary</code></td><td>$summary, $user_id</td><td><?php esc_html_e( 'Filter normalized account summary', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_integration_account_history</code></td><td>$history, $user_id, $args</td><td><?php esc_html_e( 'Filter normalized account history', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_account_widget_html</code></td><td>$html, $summary, $history, $atts</td><td><?php esc_html_e( 'Filter embeddable account widget HTML', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Filter payloads use the same shapes documented above. For example, `alorbach_integration_plans` receives the normalized array returned by `/integration/plans`, and `alorbach_integration_account_summary` receives the `/integration/account` shape.', 'alorbach-ai-gateway' ); ?></p>

		<h3><?php esc_html_e( 'Operational Hooks', 'alorbach-ai-gateway' ); ?></h3>
		<table class="widefat striped" style="max-width: 900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Hook', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Args', 'alorbach-ai-gateway' ); ?></th>
					<th><?php esc_html_e( 'Description', 'alorbach-ai-gateway' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>alorbach_credits_added</code></td><td>$user_id, $credits_uc, $source</td><td><?php esc_html_e( 'Credits granted to a user', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_after_deduction</code></td><td>$user_id, $type, $model, $cost_uc, $api_cost_uc</td><td><?php esc_html_e( 'Credits deducted after a successful generation request', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_subscription_payment_failed</code></td><td>$subscription, $last_order</td><td><?php esc_html_e( 'WooCommerce renewal payment failed', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_wc_renewal_retry_failed</code></td><td>$user_id, $credits_uc</td><td><?php esc_html_e( 'Scheduled renewal credit retry failed', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_subscription_renewal_completed</code></td><td>$user_id, $credits_uc, $source, $subscription, $last_order</td><td><?php esc_html_e( 'Renewal completed and credits were granted successfully', 'alorbach-ai-gateway' ); ?></td></tr>
				<tr><td><code>alorbach_generation_rejected_insufficient_balance</code></td><td>$user_id, $context, $details</td><td><?php esc_html_e( 'Generation request rejected before execution because the user lacks credits', 'alorbach-ai-gateway' ); ?></td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'For `alorbach_generation_rejected_insufficient_balance`, `$context` is typically `chat`, `image`, `audio`, or `video`. `$details` contains context-specific fields such as `model`, `required_uc`, `available_uc`, and request metadata like `size` or `duration` when relevant.', 'alorbach-ai-gateway' ); ?></p>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>add_filter( 'alorbach_integration_plans', function( $plans ) {
  return array_values( array_filter( $plans, fn( $plan ) => $plan['slug'] !== 'legacy' ) );
}, 10, 1 );

add_action( 'alorbach_subscription_renewal_completed', function( $user_id, $credits_uc, $source ) {
  // Sync downstream account state here.
}, 10, 3 );</code></pre>
		<?php
	}

	/**
	 * PHP usage section.
	 */
	private static function section_php() {
		?>
		<h2><?php esc_html_e( 'PHP Usage', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'Use these helpers for stable downstream integrations. Avoid reading raw options directly.', 'alorbach-ai-gateway' ); ?></p>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>// Existing helpers
$balance_uc = alorbach_get_user_balance( $user_id );
$usage_uc   = alorbach_get_user_usage_this_month( $user_id );
echo alorbach_format_credits( $balance_uc );

// Stable downstream helpers
$config  = alorbach_get_integration_config();
$plans   = alorbach_get_public_plans();
$urls    = alorbach_get_billing_urls();
$summary = alorbach_get_account_summary( $user_id );
$history = alorbach_get_account_history( $user_id, array( 'per_page' => 5 ) );
$plan    = alorbach_get_active_plan( $user_id );
$can_use_image = alorbach_user_can_access_capability( 'image', 'gpt-image-1', $user_id );</code></pre>
		<p><?php esc_html_e( 'Safe per-request overrides usually include generation choices like model, size, quality, duration, or max token settings when your frontend is calling the generation endpoints. Gateway-owned canonical data includes normalized plans, billing/account URLs, and global fallback defaults returned by `alorbach_get_integration_config()`.', 'alorbach-ai-gateway' ); ?></p>
		<p><?php esc_html_e( 'Avoid reading raw options like `alorbach_plans`, direct user meta, or demo default options directly in downstream products. Use helpers or `/integration/*` instead so storage migrations and plan-resolution rules remain internal to the gateway.', 'alorbach-ai-gateway' ); ?></p>
		<?php
	}

	/**
	 * Downstream example section.
	 */
	private static function section_downstream_example() {
		?>
		<h2><?php esc_html_e( 'Downstream Integration Example', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'A typical downstream product uses the public plan/config contract for pricing UI, uses logged-in account endpoints or PHP helpers for account state, and embeds the account widget where appropriate.', 'alorbach-ai-gateway' ); ?></p>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>// PHP-rendered pricing page.
$config = alorbach_get_integration_config();
$plans  = alorbach_get_public_plans();

foreach ( $plans as $plan ) {
	printf(
		'&lt;a href="%s"&gt;%s - $%s / %s&lt;/a&gt;',
		esc_url( $config['billing_urls']['subscribe'] ),
		esc_html( $plan['public_name'] ),
		esc_html( $plan['price_usd'] ),
		esc_html( $plan['billing_interval'] )
	);
}

// Account area.
if ( is_user_logged_in() ) {
	echo do_shortcode( '[alorbach_account_widget history_items="5"]' );
}</code></pre>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>// Browser-rendered frontend bootstrapped by your plugin/theme.
window.myGatewayData = {
  restNonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
};

const [config, plans, account] = await Promise.all([
  fetch('<?php echo esc_url( rest_url( 'alorbach/v1/integration/config' ) ); ?>').then(r => r.json()),
  fetch('<?php echo esc_url( rest_url( 'alorbach/v1/integration/plans' ) ); ?>').then(r => r.json()),
  fetch('<?php echo esc_url( rest_url( 'alorbach/v1/integration/account' ) ); ?>', {
    credentials: 'same-origin',
    headers: { 'X-WP-Nonce': window.myGatewayData.restNonce }
  }).then(r => r.json())
]);</code></pre>
		<p><?php esc_html_e( 'For WooCommerce-based subscriptions, treat account balance as reliable after the gateway has granted credits and the `alorbach_subscription_renewal_completed` action has fired. Downstream listeners should react to that event rather than inferring renewal state from order status alone.', 'alorbach-ai-gateway' ); ?></p>
		<?php
	}

	/**
	 * Custom providers section.
	 */
	private static function section_custom_providers() {
		?>
		<h2><?php esc_html_e( 'Custom AI Providers', 'alorbach-ai-gateway' ); ?></h2>
		<p><?php esc_html_e( 'Provider extensibility remains unchanged. Downstream integration APIs are separate from provider registration.', 'alorbach-ai-gateway' ); ?></p>
		<pre style="background:#f6f7f7;padding:1rem;border:1px solid #c3c4c7;border-radius:4px;overflow-x:auto;"><code>use Alorbach\AIGateway\Providers\Provider_Base;
use Alorbach\AIGateway\Providers\Provider_Registry;

class My_Custom_Provider extends Provider_Base {
    public function get_type() { return 'my_provider'; }
    public function supports_chat() { return true; }
}

add_action( 'alorbach_register_providers', function() {
    Provider_Registry::register( new My_Custom_Provider() );
} );</code></pre>
		<?php
	}
}
