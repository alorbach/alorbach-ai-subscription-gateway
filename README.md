# Alorbach AI Subscription Gateway

A precise credit-based AI API billing layer for WordPress. Bridges fixed-price subscriptions with variable AI API costs using a Unified Credit (UC) system, BPE tokenization, and an immutable SQL ledger.

**Version:** 1.0.0 | **License:** GPL-2.0-or-later | **Requires:** PHP 7.4+, WordPress 5.8+

---

## Features

- **Unified Credit (UC) system** - 1 UC = 0.000001 USD; users see Credits (1 Credit = 1000 UC)
- **BPE tokenization** via [tiktoken](https://github.com/yethee/tiktoken-php) for pre-flight cost estimation
- **Post-flight reconciliation** with actual token counts from the API response
- **Immutable SQL ledger** - every credit and deduction is written as an append-only row
- **Multi-provider support** - OpenAI, Azure OpenAI, Google Gemini, GitHub Models, Codex (OAuth)
- **AI capabilities** - Chat (multi-step), Image generation, Audio transcription (Whisper), Video generation (Sora)
- **WooCommerce Subscriptions** - auto-credit on renewal, failed payment handling with retry scheduler
- **Stripe webhook** support
- **Async image jobs** - provider-backed progress, streamed preview frames, and queue visibility
- **Demo shortcodes** - ready-to-deploy chat, image, audio, and video UI components
- **Admin panel** - API keys, cost matrix, model importer, plans, user balance, usage, developer docs

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

For detailed downstream integration guidance, see [DEVELOPER-GUIDE.md](./DEVELOPER-GUIDE.md).

---

## Admin Pages

All pages are under the **AI Gateway** top-level menu.

| Page | Purpose |
|---|---|
| **API Keys** | Manage credentials for OpenAI, Azure, Google, GitHub Models, Codex |
| **Settings** | Rate limits, monthly quotas, cost multipliers, canonical billing/account URLs |
| **Models** | Import and manage AI models with per-model pricing |
| **Demo Defaults** | Configure default models for demo pages; create sample pages |
| **Image Queue** | Monitor recent image jobs, prompts, progress, previews, and final outputs |
| **Plans** | Manage configurable paid plans plus the protected free Basic fallback plan |
| **User Plans** | Review each user's resolved plan and assign or clear manual plan overrides |
| **User Balance** | Manually credit or adjust individual users |
| **Stripe Webhook** | Webhook integration log and status |
| **Developer** | REST API docs, shortcode reference, hooks, and live test tools |

---

## Shortcodes

```
[alorbach_balance]           Display the current user's credit balance
[alorbach_usage_month]       Display the current user's usage this month
[alorbach_account_widget]    Embeddable downstream account widget

[alorbach_demo_chat]         Interactive AI chat UI
[alorbach_demo_image]        Image generator UI (prompt, size, quality, quantity, live previews)
[alorbach_demo_transcribe]   Audio transcription UI (drag-and-drop)
[alorbach_demo_video]        Video generator UI (prompt, duration, resolution)
```

Demo pages can also be created automatically via **AI Gateway -> Demo Defaults -> Create sample pages**.

The logged-in user menu label for the credits page defaults to `AI Credits` and can be changed in **AI Gateway -> Settings**.

---

## Model Types

The gateway currently exposes model configuration in these functional categories.

### Text Models

Used for chat-style generation via `/chat`.

- Typical use cases: chat assistants, text generation, structured output, multi-step flows
- Main providers: OpenAI, Azure OpenAI, Google Gemini, GitHub Models, Codex
- Demo surface: `[alorbach_demo_chat]`

### Image Models

Used for image generation via `/images` or the async image-job endpoints.

- Typical use cases: image generation, preview frames, downloadable final artwork
- Main providers: OpenAI, Azure OpenAI, Google
- Demo surface: `[alorbach_demo_image]`
- Supports both:
  - synchronous generation with `/images`
  - async generation with `/images/jobs`, `/images/jobs/<job_id>`, and `/images/jobs/<job_id>/stream`
- Preview-capable models expose progress metadata through `/me/models`

### Audio Models

Used for speech-to-text transcription via `/transcribe`.

- Typical use cases: transcription, dictated notes, uploaded audio processing
- Main providers: OpenAI, Azure OpenAI
- Demo surface: `[alorbach_demo_transcribe]`

### Video Models

Used for video generation via `/video`.

- Typical use cases: prompt-to-video generation
- Main providers: OpenAI, Azure OpenAI
- Demo surface: `[alorbach_demo_video]`
- Video generation is polled until completion rather than streamed frame-by-frame

### Codex Models

Codex models are a specialized text-model path for coding and agent-style responses.

- Typical use cases: coding assistants, code transformation, developer workflows
- Provider path: Codex OAuth and Azure Codex-compatible deployments
- Request behavior: uses a Responses/SSE-style backend flow instead of a plain chat-completions path when required
- Notes:
  - Codex is still a text model category from an integration point of view
  - it is documented separately because provider authentication and request handling differ from standard text models

### Capability Discovery

Use `/me/models` when building frontend pages so the UI can discover the currently configured defaults and plan-filtered options for:

- `text`
- `image`
- `audio`
- `video`

For image pages specifically, `/me/models` also includes progress and preview capability metadata so the frontend can decide whether to use the async preview flow.

Basic users with a positive balance can spend those credits on the full configured model catalog.

---

## REST API

Base URL: `/wp-json/alorbach/v1`

### User Endpoints _(requires login)_

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/chat` | Chat completion with credit deduction |
| `GET` | `/me/balance` | Current user's balance (UC) |
| `GET` | `/me/usage` | Usage for `period` (`month` or `week`) |
| `GET` | `/me/models` | Demo-facing model settings. Prefer `/integration/config` for downstream products |
| `GET` | `/me/estimate` | Pre-flight cost estimate for image/video/audio |
| `GET` | `/integration/account` | Canonical downstream account summary |
| `GET` | `/integration/account/history` | Canonical downstream account history |
| `POST` | `/images` | Generate images |
| `POST` | `/images/jobs` | Create an async image generation job |
| `GET` | `/images/jobs/<job_id>` | Read image job status, progress, previews, and final images |
| `GET` | `/images/jobs/<job_id>/stream` | Stream provider-backed image job updates |
| `POST` | `/transcribe` | Transcribe audio (base64) |
| `POST` | `/video` | Generate video |
| `POST` | `/stripe-webhook` | Stripe payment events _(public)_ |

### Public Downstream Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/integration/config` | Canonical downstream config: backend defaults, capabilities, billing URLs, and logged-in active-plan filtering |
| `GET` | `/integration/plans` | Canonical downstream plan catalog with capabilities and model allowlists |

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
| `GET` | `/admin/image-jobs` | List recent image jobs for queue monitoring |
| `GET` | `/admin/image-jobs/<job_id>` | Read one image job detail payload |

### Image Job Notes

- `/images` remains the compatibility path for synchronous callers.
- The image sample page now prefers `/images/jobs` and uses `/images/jobs/<job_id>/stream` plus status polling for preview-capable models.
- `/me/models` includes image preview/progress capability metadata used by the sample UI.
- Credits are checked before job creation and deducted only after successful completion.
- Preview images can be inspected in both the sample page and the Image Queue admin screen.

---

## Hooks & Filters

### Actions

```php
// Fires after every credit deduction
do_action( 'alorbach_after_deduction', $user_id, $type, $model, $uc_cost, $api_cost );

// Fires after credits are added (e.g. WooCommerce renewal)
do_action( 'alorbach_credits_added', $user_id, $credits_uc, $source ); // source: stripe|woocommerce|woocommerce_retry

// Register custom AI providers
add_action( 'alorbach_register_providers', function() {
    // register your provider here
} );

// WooCommerce subscription events
do_action( 'alorbach_subscription_payment_failed', $subscription, $last_order );
do_action( 'alorbach_wc_renewal_retry_failed', $user_id, $credits_uc );
do_action( 'alorbach_subscription_renewal_completed', $user_id, $credits_uc, $source, $subscription, $last_order );
do_action( 'alorbach_generation_rejected_insufficient_balance', $user_id, $context, $details );

// Stripe events (after signature verification)
do_action( 'alorbach_stripe_webhook', $event_type, $event );
do_action( 'alorbach_stripe_payment_failed', $event );
do_action( 'alorbach_stripe_subscription_deleted', $event );

// Video generation
do_action( 'alorbach_video_poll_timeout', $video_id, $provider );
```

### Filters

```php
// Modify the chat request body before sending to the provider
add_filter( 'alorbach_chat_request_body', function( $body, $user_id, $model ) {
    $body['temperature'] = 0.7;
    return $body;
}, 10, 3 );

// Change how many UC equal 1 displayed Credit (default: 1000)
add_filter( 'alorbach_uc_to_credit_ratio', fn() => 500 );

// Customize the Credits label
add_filter( 'alorbach_credits_label', fn() => 'Tokens' );

// Customize balance/usage display HTML
add_filter( 'alorbach_balance_display', fn( $html, $user_id, $balance ) => $html, 10, 3 );
add_filter( 'alorbach_usage_display',   fn( $html, $user_id, $usage, $period ) => $html, 10, 4 );

// Override token counting
add_filter( 'alorbach_count_tokens', fn( $count, $text, $model ) => null, 10, 3 );

// Custom user cost (e.g. free for a specific model)
add_filter( 'alorbach_user_cost', function( $user_cost, $api_cost_uc, $model ) {
    return $user_cost;
}, 10, 3 );

// Video polling tuning
add_filter( 'alorbach_video_poll_max',      fn() => 120 ); // double polling attempts
add_filter( 'alorbach_video_poll_interval', fn() => 10  ); // poll every 10s

// Downstream integration payloads
add_filter( 'alorbach_integration_plans', fn( $plans, $args ) => $plans, 10, 2 );
add_filter( 'alorbach_integration_config', fn( $config ) => $config, 10, 1 );
add_filter( 'alorbach_integration_account_summary', fn( $summary, $user_id ) => $summary, 10, 2 );
add_filter( 'alorbach_integration_account_history', fn( $history, $user_id, $args ) => $history, 10, 3 );
```

---

## PHP Template Functions

```php
// Get user balance in UC (omit $user_id for current user)
$uc = alorbach_get_user_balance( $user_id );

// Get this month's usage in UC
$used = alorbach_get_user_usage_this_month( $user_id );

// Get the resolved active plan and capability access
$plan = alorbach_get_active_plan( $user_id );
$can_generate_images = alorbach_user_can_access_capability( 'image', 'gpt-image-1.5', $user_id );

// Format UC as a human-readable Credits string - e.g. "1.50 Credits"
echo alorbach_format_credits( $uc );
```

---

## Credit Ledger

All transactions are written to `wp_alorbach_ledger` as immutable rows.

**Transaction types:** `subscription_credit` | `chat_deduction` | `image_deduction` | `audio_deduction` | `video_deduction` | `admin_credit` | `balance_forward`

```php
use Alorbach\AIGateway\Ledger;

$balance_uc = Ledger::get_balance( $user_id );
$usage_uc   = Ledger::get_usage_this_month( $user_id );
$rows       = Ledger::get_transactions( [ 'user_id' => $user_id, 'per_page' => 20, 'page' => 1 ] );
```

---

## Custom AI Providers

Any AI backend can be added without modifying the plugin. Extend `Provider_Base`,
implement the two required methods, and register on the `alorbach_register_providers` hook:

```php
use Alorbach\AIGateway\Providers\Provider_Base;
use Alorbach\AIGateway\Providers\Provider_Registry;

class My_Custom_Provider extends Provider_Base {
    public function get_type()      { return 'my_provider'; }
    public function supports_chat() { return true; }

    public function build_chat_request( $body, $credentials ) {
        $body = self::normalize_chat_body( $body, $body['model'] ?? '' );
        return [
            'url'     => 'https://api.my-provider.com/v1/chat/completions',
            'headers' => [ 'Authorization' => 'Bearer ' . $credentials['api_key'], 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ];
    }

    public function verify_key( $credentials )  { return true; }
    public function fetch_models( $credentials ) { return [ 'my-model-v1' ]; }
}

add_action( 'alorbach_register_providers', function() {
    Provider_Registry::register( new My_Custom_Provider() );
} );
```

See `Provider_Base` for optional method stubs (images, audio, video) you only override when needed.

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

GPL-2.0-or-later - Copyright (c) Andre Lorbach
