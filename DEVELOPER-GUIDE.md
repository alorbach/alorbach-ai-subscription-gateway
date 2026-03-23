# Developer Guide

External developer documentation for building against the Alorbach AI Subscription Gateway plugin.

This guide is for developers creating themes, plugins, or custom frontend pages that consume the gateway's WordPress-facing functionality.

For internal plugin-development setup, local `wp-env`, and repository workflows, use the root project docs instead.

---

## Scope

Use this guide when you want to:

- build custom pages on top of the gateway REST API
- integrate balance, usage, and plan data into another plugin
- create a custom image generator UI using the gateway backend
- understand which model categories and demo capabilities are exposed to frontend code

---

## Base URL and Auth

REST routes are registered under:

```text
/wp-json/alorbach/v1
```

WordPress authentication rules apply:

- public endpoints can be called without login
- user endpoints require a logged-in WordPress user
- admin endpoints require a user with `manage_options`

For browser-based calls from WordPress pages, send the standard REST nonce:

```http
X-WP-Nonce: <wp_rest nonce>
```

---

## API Groups

The plugin currently exposes these route groups:

- user endpoints for logged-in frontend/application use
- public downstream endpoints for plugin-to-plugin integration
- admin endpoints for operational tools and verification
- internal-only endpoints used by the plugin itself

---

## Plan Resolution

The gateway resolves a user's active plan in this order:

- manual admin override from `AI Gateway -> User Plans`
- active WooCommerce subscription product mapped to a plan
- protected `basic` fallback plan

The `basic` plan is always present in the effective catalog. By default it enables chat only.

Important exception:

- if a user resolves to `basic` and has a positive credit balance, the gateway allows the full configured model catalog and paid capabilities so manually added credits can be spent

Use the downstream account/config endpoints or PHP helpers instead of reproducing this logic in another plugin.

---

## User Endpoints

These endpoints require a logged-in WordPress user.

### `POST /chat`

Chat completion with credit deduction.

Common request fields:

- `messages` required array
- `model` optional string
- `max_tokens` optional integer
- `multi_step` optional boolean
- `max_steps` optional integer
- `continue_message` optional string

Typical response:

- chat-completions style `choices`
- `usage`
- cost fields when applicable

### `GET /me/balance`

Returns the current user's balance.

Typical response fields:

- `balance_uc`
- `balance_credits`
- `balance_usd`

### `GET /me/usage`

Returns the current user's usage summary.

Query params:

- `period`: `month` or `week`

Typical response fields:

- usage totals in UC, credits, and USD-oriented display values

### `GET /me/models`

Frontend capability and default-model discovery endpoint.

This payload is plan-aware for logged-in users. If a user is on the free Basic plan, disabled capabilities return `enabled: false` and restricted model lists are filtered before they reach the UI.

Exception: if a Basic user has a positive credit balance, the gateway exposes the full configured model catalog so those credits can be spent across the paid capabilities.

Model categories exposed:

- `text`
- `image`
- `audio`
- `video`

Each category also includes an `enabled` flag for the current user.

Image capability metadata can include:

- `image.supports_progress`
- `image.supports_preview_images`
- `image.progress_mode`
- `image.preview_models`

Use this endpoint when building frontend pages so the UI does not hardcode model assumptions.

### `GET /me/estimate`

Pre-flight cost estimation endpoint.

Query params:

- `type` required: `image`, `video`, or `audio`
- `size` optional
- `quality` optional
- `n` optional
- `model` optional
- `duration_seconds` optional

Typical response fields:

- `cost_uc`
- `cost_credits`
- `cost_usd`

### `GET /integration/account`

Canonical account summary for the current logged-in user.

Useful for downstream plugins that want a stable user-facing account payload.

The payload includes the resolved `active_plan` summary.

`active_plan.source` indicates whether the plan came from a manual admin override, a WooCommerce subscription, or the Basic fallback.

Expected values:

- `manual`
- `subscription`
- `basic`

### `GET /integration/account/history`

Canonical account history for the current logged-in user.

Query params:

- `page` optional
- `per_page` optional

Useful for downstream plugins that want to show balance or transaction history without reading plugin internals.

### `POST /images`

Synchronous image generation endpoint.

Request fields:

- `prompt` required string
- `size` optional string
- `n` optional integer
- `quality` optional string
- `model` optional string

Use this when:

- you want a simple blocking request
- you do not need streamed preview images
- you are maintaining backward compatibility with older callers

### `POST /images/jobs`

Creates an async image generation job.

Request fields:

- `prompt` required string
- `size` optional string
- `n` optional integer
- `quality` optional string
- `model` optional string

Typical response fields:

- `job_id`
- `status`
- `progress_stage`
- `progress_percent`
- `progress_mode`
- `supports_previews`
- `preview_images`
- `final_images`

### `GET /images/jobs/<job_id>`

Returns current job state for one image job.

Typical response fields:

- `job_id`
- `status`
- `progress_stage`
- `progress_percent`
- `progress_mode`
- `supports_previews`
- `preview_images`
- `final_images`
- `cost_uc`
- `cost_credits`
- `cost_usd`
- `error`

### `GET /images/jobs/<job_id>/stream`

Streams provider-backed image job updates.

Use this for preview-capable image models. The current sample page also polls `/images/jobs/<job_id>` as a fallback for environments where chunked delivery is buffered.

### `POST /transcribe`

Audio transcription endpoint.

Request fields:

- `audio_base64` required
- `audio_format` optional
- `duration_seconds` optional
- `model` optional
- `prompt` optional

Typical use cases:

- uploaded audio transcription
- dictated notes
- speech-to-text processing in downstream plugins

### `POST /video`

Video generation endpoint.

Request fields:

- `prompt` required string
- `model` optional string
- `size` optional string
- `duration_seconds` optional integer

Video generation is handled as a polling-style flow rather than preview-frame streaming.

---

## Public Downstream Endpoints

These endpoints do not require login.

### `GET /integration/config`

Canonical downstream configuration payload.

Use this for:

- frontend/backend defaults
- capability discovery
- billing or account URLs exposed by the gateway

When the caller is logged in, the payload is filtered to the user's active plan and includes `active_plan` plus `plan_capabilities`.

If the logged-in user is on `basic` with a positive balance, the payload exposes the full configured catalog so the frontend can spend those credits without being artificially restricted.

### `GET /integration/plans`

Canonical downstream plan catalog.

Query params:

- `include_inactive` optional boolean

Use this when another plugin wants to render gateway-owned plan data instead of duplicating it.

Each plan row includes:

- pricing and included credits
- `is_free`
- `capabilities`
- `allowed_models`

The plugin always maintains a protected `basic` plan as the fallback for users without an active paid subscription.

The `basic` plan cannot disappear from the effective catalog, even if older stored plan data is incomplete.

---

## Admin Endpoints

These endpoints require `manage_options`.

### Verification Endpoints

Used by the plugin admin and developer tooling.

#### `POST /admin/verify-api-key`

Request fields:

- `provider` required
- `entry_id` optional

#### `POST /admin/verify-text`

Request fields:

- `provider` required
- `model` required
- `entry_id` optional

#### `POST /admin/verify-image`

Request fields:

- `size` optional
- `model` optional

#### `POST /admin/verify-audio`

Request fields:

- `model` optional

#### `POST /admin/verify-video`

Request fields:

- `model` optional

### Model Import and Provider Admin Endpoints

#### `GET /admin/fetch-importable-models`

Fetches models available for import from configured providers.

#### `POST /admin/import-models`

Imports selected models into the plugin model registry.

#### `POST /admin/reset-models`

Resets imported models to defaults.

#### `POST /admin/refresh-azure-prices`

Refreshes Azure retail pricing data.

#### `POST /admin/save-google-whitelist`

Saves the Google API whitelist configuration.

### Image Queue Admin Endpoints

#### `GET /admin/image-jobs`

Returns recent image jobs for queue monitoring.

Typical response includes:

- aggregate stats
- recent job rows

#### `GET /admin/image-jobs/<job_id>`

Returns detailed image job data for one queue item.

Typical response fields can include:

- `original_prompt`
- `prompt`
- `status`
- `progress_percent`
- `progress_mode`
- `preview_images`
- `final_images`
- `runtime_seconds`
- `cost_credits_label`
- `user_label`
- `error`

---

## Internal-Only Endpoints

These are not part of the supported external integration surface.

### `POST /internal/images/jobs/process`

Used by the plugin to process image jobs. Do not depend on this route from external plugins or themes.

---

## Model Categories

Use `/me/models` to discover the configured defaults and supported options for:

- `text`
- `image`
- `audio`
- `video`

### Text Models

Used for chat-style generation via `/chat`.

- typical use cases: chat assistants, text generation, structured output, multi-step flows
- main providers: OpenAI, Azure OpenAI, Google Gemini, GitHub Models, Codex

### Image Models

Used for image generation via `/images` or the async image-job endpoints.

- typical use cases: image generation, preview frames, downloadable final artwork
- main providers: OpenAI, Azure OpenAI, Google
- supports both synchronous and async job flows

### Audio Models

Used for speech-to-text transcription via `/transcribe`.

- typical use cases: transcription, dictated notes, uploaded audio processing
- main providers: OpenAI, Azure OpenAI

### Video Models

Used for video generation via `/video`.

- typical use cases: prompt-to-video generation
- main providers: OpenAI, Azure OpenAI

### Codex Models

Codex models are a specialized text-model path for coding and agent-style responses.

- provider path: Codex OAuth and Azure Codex-compatible deployments
- request behavior can use a Responses/SSE-style backend flow rather than a plain chat-completions path

Do not treat Codex as an image, audio, or video category. From an integration perspective it belongs under text-generation workflows.

---

## Building a Custom Image Generator

### Recommended flow

For new UIs, prefer the async job flow instead of only calling `/images`.

1. Call `POST /images/jobs`
2. Read the returned `job_id`, `status`, and `progress_mode`
3. If the selected model supports provider-backed previews, connect to `GET /images/jobs/<job_id>/stream`
4. Also poll `GET /images/jobs/<job_id>` as a resilience fallback for buffered environments
5. Render preview frames as they arrive
6. Render final images when the job reaches `completed`

### Compatibility flow

If you want a simple blocking implementation or need compatibility with older callers:

1. Call `POST /images`
2. Wait for the final response
3. Render final images only

---

## Image Job Behavior

### Progress modes

- `estimated`: provider does not expose partial preview images, so the UI should show stage-based progress only
- `provider`: provider-backed preview frames and progress updates are available

### Progress mapping used by the sample page

- `queued` -> `10%`
- `in_progress` with no preview images -> `35%`
- first preview image -> `55%`
- second preview image -> `75%`
- third preview image -> `90%`
- `completed` -> `100%`

These percentages are UI milestones, not true backend percentages.

### Payload notes

Image job payloads can include:

- `job_id`
- `status`
- `progress_stage`
- `progress_percent`
- `progress_mode`
- `supports_previews`
- `preview_images`
- `final_images`
- `cost_credits`
- `cost_usd`
- `error`

Your frontend should tolerate missing optional fields and unsupported preview behavior.

---

## Preview and Gallery UX

The sample page currently implements this UX:

- preview frames appear in a `Live previews` rail while generation is in progress
- preview and final images open in a gallery lightbox
- users can move left and right through the current gallery with arrow keys
- users can download the currently visible image, including intermediate previews

If you build your own page, keep preview frames visually distinct from final images.

---

## Credits and Failure Behavior

- credits are checked before image job creation
- image jobs do not deduct credits at job creation time
- credits are deducted only after successful final image completion
- failed jobs do not deduct credits

This matters if your downstream UI wants to explain when balance changes will occur.

It also matters for Basic users with manually granted credits: a positive balance unlocks the full configured capability/model catalog even when the resolved plan is still `basic`.

---

## Queue and Admin Monitoring

The plugin includes `AI Gateway -> Image Queue` for operational inspection of recent jobs.

The queue page shows:

- queued, in-progress, completed, and failed counts
- recent jobs with user, model, status, and progress
- detail view with original prompt, sanitized prompt, previews, final images, runtime, and cost

This queue UI is intended for monitoring and debugging, not as a stable public API for third-party dashboards.

---

## Shortcodes

Available shortcodes include:

- `[alorbach_balance]`
- `[alorbach_usage_month]`
- `[alorbach_account_widget]`
- `[alorbach_demo_chat]`
- `[alorbach_demo_image]`
- `[alorbach_demo_transcribe]`
- `[alorbach_demo_video]`

Use these when you want a supported UI quickly instead of building a custom page.

The user-facing wp-admin credits page label defaults to `AI Credits` and can be changed in `AI Gateway -> Settings`.

The credits page shows the current user's:

- resolved active plan
- plan source
- enabled benefits and included credits
- current balance and usage/history

---

## PHP Helpers

Use the plugin helpers instead of reading `alorbach_plans`, user meta, or demo defaults directly.

Common helpers:

- `alorbach_get_integration_config( $user_id = null )`
- `alorbach_get_public_plans( $args = array() )`
- `alorbach_get_billing_urls()`
- `alorbach_get_account_summary( $user_id = null )`
- `alorbach_get_account_history( $user_id = null, $args = array() )`
- `alorbach_get_active_plan( $user_id = null )`
- `alorbach_user_can_access_capability( $capability, $model = '', $user_id = null )`

Typical usage:

```php
$plan    = alorbach_get_active_plan( $user_id );
$summary = alorbach_get_account_summary( $user_id );
$config  = alorbach_get_integration_config( $user_id );

if ( alorbach_user_can_access_capability( 'image', 'gpt-image-1', $user_id ) ) {
    // Render or enable image-generation UI.
}
```

---

## Documentation Map

Use the docs in these places for these purposes:

- `docs/` in the repository root: internal maintainer documentation
- `wordpress-plugin/README.md`: public plugin overview and high-level endpoint summary
- `wordpress-plugin/DEVELOPER-GUIDE.md`: external integration and REST API reference
- `AI Gateway -> Developer` in wp-admin: interactive developer/admin reference and test tooling
