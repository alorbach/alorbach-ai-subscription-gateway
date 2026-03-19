# Alorbach AI Subscription Gateway

A precise credit-based AI API billing layer for WordPress. Bridges fixed-price subscriptions with variable AI API costs using a Unified Credit (UC) system, BPE tokenization, and an immutable SQL ledger.

**Version:** 1.0.0 · **License:** GPL-2.0-or-later · **Requires:** PHP 7.4+, WordPress 5.8+

---

## Features

- **Unified Credit (UC) system** — 1 UC = 0.000001 USD; users see Credits (1 Credit = 1000 UC)
- **BPE tokenization** via [tiktoken](https://github.com/yethee/tiktoken-php) for pre-flight cost estimation
- **Post-flight reconciliation** with actual token counts from the API response
- **Immutable SQL ledger** — every credit and deduction is written as an append-only row
- **Multi-provider support** — OpenAI, Azure OpenAI, Google Gemini, GitHub Models, Codex (OAuth)
- **AI capabilities** — Chat (multi-step), Image generation, Audio transcription (Whisper), Video generation (Sora)
- **WooCommerce Subscriptions** — auto-credit on renewal, failed payment handling with retry scheduler
- **Stripe webhook** support
- **Demo shortcodes** — ready-to-deploy chat, image, audio, and video UI components
- **Admin panel** — API keys, cost matrix, model importer, plans, user balance, usage, developer docs

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 7.4 |
| WordPress | >= 5.8 |
| Composer | Any |
| WooCommerce Subscriptions | Optional (for subscription billing) |

---

## Installation

```bash
# 1. Clone or copy the plugin into your WordPress plugins directory
cd wp-content/plugins/
git clone https://github.com/alorbach/alorbach-ai-subscription-gateway.git

# 2. Install PHP dependencies
cd alorbach-ai-subscription-gateway
composer install --no-dev --optimize-autoloader

# 3. Activate in WordPress admin (Plugins -> Activate)
```

Then go to **AI Gateway -> API Keys** to add your provider credentials.

---

## Admin Pages

All pages are under the **AI Gateway** top-level menu.

| Page | Purpose |
|---|---|
| **API Keys** | Manage credentials for OpenAI, Azure, Google, GitHub Models, Codex |
| **Settings** | Rate limits, monthly quotas, cost multipliers |
| **Models** | Import and manage AI models with per-model pricing |
| **Demo Defaults** | Configure default models for demo pages; create sample pages |
| **Plans** | Create credit packages and subscription plans |
| **User Balance** | Manually credit or adjust individual users |
| **Stripe Webhook** | Webhook integration log and status |
| **Developer** | REST API docs, shortcode reference, hooks, and live test tools |

---

## Shortcodes

```
[alorbach_balance]           Display the current user's credit balance
[alorbach_usage_month]       Display the current user's usage this month

[alorbach_demo_chat]         Interactive AI chat UI
[alorbach_demo_image]        Image generator UI (prompt, size, quality, quantity)
[alorbach_demo_transcribe]   Audio transcription UI (drag-and-drop)
[alorbach_demo_video]        Video generator UI (prompt, duration, resolution)
```

Demo pages can also be created automatically via **AI Gateway -> Demo Defaults -> Create sample pages**.

---

## REST API

Base URL: `/wp-json/alorbach/v1`

### User Endpoints _(requires login)_

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/chat` | Chat completion with credit deduction |
| `GET` | `/me/balance` | Current user's balance (UC) |
| `GET` | `/me/usage` | Usage for `period` (`month` or `week`) |
| `GET` | `/me/models` | List available AI models |
| `GET` | `/me/estimate` | Pre-flight cost estimate for image/video/audio |
| `POST` | `/images` | Generate images |
| `POST` | `/transcribe` | Transcribe audio (base64) |
| `POST` | `/video` | Generate video |
| `POST` | `/stripe-webhook` | Stripe payment events _(public)_ |

### Admin Endpoints _(requires `manage_options`)_

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/admin/verify-api-key` | Validate a provider API key |
| `POST` | `/admin/verify-text` | Test text model configuration |
| `POST` | `/admin/verify-image` | Test image generation |
| `POST` | `/admin/verify-audio` | Test audio transcription |
| `POST` | `/admin/verify-video` | Test video generation |
| `GET` | `/admin/fetch-importable-models` | Fetch importable models from providers |
| `POST` | `/admin/import-models` | Import models |
| `POST` | `/admin/reset-models` | Reset models to defaults |
| `POST` | `/admin/refresh-azure-prices` | Refresh Azure retail pricing |
| `POST` | `/admin/save-google-whitelist` | Configure Google API whitelist |

---

## Hooks & Filters

### Actions

```php
// Fires after every credit deduction
do_action( 'alorbach_after_deduction', $user_id, $type, $model, $uc_cost, $api_cost );

// Fires after credits are added (e.g. WooCommerce renewal)
do_action( 'alorbach_credits_added', $user_id, $credits_uc, $source );

// Register custom AI providers
add_action( 'alorbach_register_providers', function() {
    // register your provider here
} );

// WooCommerce subscription events
do_action( 'alorbach_subscription_payment_failed', $subscription, $last_order );
do_action( 'alorbach_wc_renewal_retry_failed', $user_id, $credits_uc );
```

### Filters

```php
// Modify the chat request body before sending to the provider
add_filter( 'alorbach_chat_request_body', function( $body, $user_id ) {
    $body['temperature'] = 0.7;
    return $body;
}, 10, 2 );

// Change how many UC equal 1 displayed Credit (default: 1000)
add_filter( 'alorbach_uc_to_credit_ratio', fn() => 500 );

// Customize the Credits label
add_filter( 'alorbach_credits_label', fn() => 'Tokens' );

// Customize balance/usage display HTML
add_filter( 'alorbach_balance_display', fn( $html, $uc ) => $html, 10, 2 );
add_filter( 'alorbach_usage_display',   fn( $html, $uc ) => $html, 10, 2 );
```

---

## PHP Template Functions

```php
// Get user balance in UC (omit $user_id for current user)
$uc = alorbach_get_user_balance( $user_id );

// Get this month's usage in UC
$used = alorbach_get_user_usage_this_month( $user_id );

// Format UC as a human-readable Credits string — e.g. "1.50 Credits"
echo alorbach_format_credits( $uc );
```

---

## Credit Ledger

All transactions are written to `wp_alorbach_ledger` as immutable rows.

**Transaction types:** `subscription_credit` · `chat_deduction` · `image_deduction` · `audio_deduction` · `video_deduction` · `admin_credit` · `balance_forward`

```php
use Alorbach\AIGateway\Ledger;

$balance_uc = Ledger::get_balance( $user_id );
$usage_uc   = Ledger::get_usage_this_month( $user_id );
$rows       = Ledger::get_transactions( [ 'user_id' => $user_id, 'limit' => 20, 'offset' => 0 ] );
```

---

## Releases

Releases are published automatically when a version tag is pushed:

```bash
git tag v1.0.1
git push origin v1.0.1
```

The CI workflow builds a clean plugin ZIP and publishes a GitHub Release with auto-generated changelog notes.

---

## License

GPL-2.0-or-later — Copyright (c) Andre Lorbach
