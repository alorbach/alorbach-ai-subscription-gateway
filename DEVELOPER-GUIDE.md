# Developer Guide

External developer documentation for building against the Alorbach AI Subscription Gateway plugin.

This guide is for developers creating themes, plugins, or custom frontend pages that consume the gateway's WordPress-facing functionality.

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
- public integration endpoints for WordPress frontend integration and external extensions
- admin endpoints for operational tools and verification
- internal-only endpoints used by the plugin itself

---

## Plan Resolution

The gateway resolves a user's active plan in this order:

- manual admin override from `AI Gateway -> User Plans`
- active WooCommerce subscription product mapped to a plan
- protected `basic` fallback plan

The `basic` plan is always present in the effective catalog. In the plugin's default plan seed it enables chat only.

Important exception:

- if a user resolves to `basic` and has a positive credit balance, the gateway allows the full configured model catalog and paid capabilities so manually added credits can be spent

Use the account/config integration endpoints or helper routines instead of reproducing this logic in your integration.

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
- `client_request_id` optional string, at most 128 characters (`idempotency_key` is accepted as an alias)

Duplicate protection: without `client_request_id` a request is refused with `409 duplicate_request` when the same user already sent the same `messages` to the same `model`, at any time. With `client_request_id` the Gateway deduplicates by that id instead, so a deliberate new attempt with the same prompt needs a new id, and a replay of the same id is refused. A successful response echoes `client_request_id`. When `max_tokens` is omitted the Gateway uses 1024 (clamped to the model maximum); reasoning models usually need a larger explicit value.

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
- `image.supports_provider_progress`
- `image.supports_preview_images`
- `image.progress_mode`
- `image.provider_progress_models`
- `image.preview_models`

These flags are capability hints, not blanket guarantees for every configured image model. Use the per-model lists instead of assuming that all image providers support provider progress or preview frames.

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

Useful for WordPress plugins, themes, or custom frontends that need a stable user-facing account payload.

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

Useful for WordPress plugins, themes, or custom frontends that need balance/history without reading plugin internals.

### `POST /images`

Synchronous image generation endpoint.

Request fields:

- `prompt` required string
- `size` optional string
- `n` optional integer
- `quality` optional string (`low`, `medium`, `high`; GPT Image 2.5 also accepts `xhigh` and `max`)
- `background` optional string for GPT Image models (`auto`, `opaque`, `transparent`)
- `output_format` optional string (`png`, `jpeg`)
- `model` optional string

Transparent backgrounds require PNG (JPEG is coerced to PNG).

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
- `quality` optional string (`low`, `medium`, `high`; GPT Image 2.5 also accepts `xhigh` and `max`)
- `background` optional string for GPT Image models (`auto`, `opaque`, `transparent`)
- `output_format` optional string (`png`, `jpeg`)
- `model` optional string

Typical response fields:

- `job_id`
- `status`
- `progress_stage`
- `progress_percent`
- `progress_mode`
- `provider_progress`
- `supports_previews`
- `preview_images`
- `final_images`

Hugging Face Spaces note:

- current support is text-to-image only
- no chat support through this provider
- no reference-image support yet
- no guaranteed preview-frame support even when async job mode is available

### `GET /images/jobs/<job_id>`

Returns current job state for one image job.

Typical response fields:

- `job_id`
- `status`
- `progress_stage`
- `progress_percent`
- `progress_mode`
- `provider_progress`
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
- `locale` optional for Azure Speech Services, for example `en-US` or `de-DE`
- `language` optional fallback when `locale` is not provided

Typical use cases:

- uploaded audio transcription
- dictated notes
- speech-to-text processing in WordPress integrations

Use `model: "azure-speech"` to route transcription through Azure Speech Services inside the Gateway's Azure provider. `speech` is accepted as a request alias. Azure Speech responses are normalized to `text`, `words` as `{ word, start, end }` in seconds, and `segments` when phrase timing is available.

### `POST /video`

Video generation endpoint.

Request fields:

- `prompt` required string
- `model` optional string
- `size` optional string
- `duration_seconds` optional integer

Video generation is handled as a polling-style flow rather than preview-frame streaming.

---

## Public Integration Endpoints

These endpoints do not require login.

### `GET /integration/config`

Canonical integration configuration payload.

Use this for:

- frontend/backend defaults
- capability discovery
- billing or account URLs exposed by the gateway

When the caller is logged in, the payload is filtered to the user's active plan and includes `active_plan` plus `plan_capabilities`.

If the logged-in user is on `basic` with a positive balance, the payload exposes the full configured catalog so the frontend can spend those credits without being artificially restricted.

Direct, server-dispatched image models are published in both `capabilities.image_models` and `capabilities.models`. A direct model contract is eligible only while its exact configured entry is enabled, dispatch credentials are present, and every exposed size/quality pair has a positive Gateway price. It has this shape:

```json
{
  "gateway_model_key": "<entry-id>::gpt-image-2",
  "transport": "direct_image",
  "eligible": true,
  "direct_dispatch_evidenced": true,
  "image_capabilities_evidenced": true,
  "image_capabilities": {
    "size_mode": "preset",
    "supported_sizes": ["1024x1024"],
    "supported_qualities": ["low", "medium", "high"],
    "supported_output_formats": ["image/png", "image/jpeg"],
    "candidate_count_max": 1
  }
}
```

Azure `gpt-image-2.5-sunburst` and `gpt-image-2.5-flare` use the same Direct transport with additional qualities (`xhigh`, `max`), `supported_backgrounds` (`auto`, `opaque`, `transparent`), and a published image-edit contract (`contract_version` 1, `reference_images_max` 16, `candidate_count_max` 1). The Azure Images edits path accepts up to 16 reference images; quality such as `high` is independent of that limit. Prefer `capabilities.image_model_capabilities[gateway_model_key]` — both `entry-id::gpt-image-2.5-flare` and the UUID alias `entry-id:gpt-image-2.5-flare` resolve to the same contract.

Server-dispatched chat models are published in `capabilities.models` with `transport: "sync_chat"` (contract version `capabilities.server_chat_contract_version`, currently `1.0.0`), and their eligible keys additionally in `capabilities.server_chat_models`. They exist for downstream products that call `POST /chat` from their own server without a browser. Only models that an administrator has saved under AI Gateway > Settings > Server Chat Models are published at all. A model that was saved with the switch off appears with `eligible: false` and `disabled_reason: "disabled_by_admin"`; a model that was never saved does not appear. Prefixed AI Model Relay and local keys are never stored or published; a non-prefixed model name that sits under an AI Model Relay or local Codex provider entry is skipped entirely. A contract has this shape:

```json
{
  "gateway_model_key": "<entry-id>::gpt-5.6-luna",
  "label": "gpt-5.6-luna (Azure / Prod)",
  "provider": "azure",
  "transport": "sync_chat",
  "chat_contract_version": "1.0.0",
  "eligible": true,
  "disabled_reason": null,
  "admin_enabled": true,
  "server_dispatch_evidenced": true,
  "dispatch_entry_exclusive": true,
  "pricing_evidenced": true,
  "requires_browser_pairing": false,
  "supported_languages": ["de-DE", "en-US"],
  "operation_slugs": ["story_create", "book_title_create"],
  "structured_output": false,
  "provider_cancel": false,
  "provider_resume": false,
  "text_cost_estimate": false
}
```

`eligible` is `true` only while every gate passes. `disabled_reason` names the first failing gate, evaluated in this order:

1. `disabled_by_admin` - the administrator switch is off.
2. `dispatch_credentials_missing` - the provider type has no chat dispatcher, or the configured provider entry is disabled or its credentials are incomplete (Azure also needs an endpoint; Codex needs a connected OAuth session).
3. `dispatch_entry_ambiguous` - more than one enabled entry exists for the provider type (keyed or not), or the entry named by a compound key is not the only enabled one. `POST /chat` reads `free_pass_through` from the first enabled entry of the type while dispatch walks the enabled entries that have an API key, so the Gateway cannot promise which credentials and pricing serve the request.
4. `pricing_missing` - no priced model row and no free pass-through (see below).
5. `languages_missing` - `supported_languages` is empty.
6. `operations_missing` - `operation_slugs` is empty.

A seventh value, `filter_invalid`, is set when a contract leaves the `alorbach_server_chat_model_contracts` filter ineligible without a reason, for example because the filter returned it as eligible without the full evidence above or switched off a built-eligible contract. Models that belong to an AI Model Relay or local Codex provider entry are never published at all (no contract, not listed in the admin tab).

`server_dispatch_evidenced` reports the dispatch gate and `dispatch_entry_exclusive` reports the entry gate (the provider type has exactly one enabled entry, counting keyless entries too, and for a compound key it is the entry named by the key). Dispatch credentials and `free_pass_through` are both read from that sole entry. Pricing is evidenced by an explicit cost-matrix row with positive input and output prices for exactly this model and entry, or by `free_pass_through` enabled on the sole dispatch entry; catalog defaults never count. Pricing evidence in detail: for a compound key a cost-matrix row for that model and that entry, for a plain key the first cost-matrix row for that model name, in both cases with positive input and output prices; or `free_pass_through` on the sole enabled entry of the type. The administrator confirms that a saved model is a chat model (the text catalog is built from all cost-matrix rows, so the Gateway cannot tell chat models from others). `eligible: true` does not guarantee success: `POST /chat` still applies login, plan access, balance, rate limit, monthly quota and duplicate checks. Plan access on `POST /chat` is checked by model name, so a plan that allows one entry's model also admits the same model name under another entry; a server-side consumer must therefore send only a `gateway_model_key` it received as an eligible contract. `supported_languages` are BCP-47 content locales in the form `xx-XX` or `xxx-XX` (for example `de-DE`, `fil-PH`); input is case-normalized (`en-us` becomes `en-US`) and other values are dropped. `operation_slugs` are lowercase `snake_case` names owned by the downstream product; the Gateway publishes them without interpreting them. Send `gateway_model_key` verbatim as `model` to `POST /chat`. No credential, endpoint or price internals are ever included.

The payload gains two fields in `capabilities`: `server_chat_models` (the `gateway_model_key` values of all eligible contracts) and `server_chat_contract_version` (currently `1.0.0`). Existing fields keep their meaning; `capabilities.models` additionally contains the `sync_chat` entries, so a client that reads that list must check `transport`. If a `sync_chat` key would equal the key of a relay or direct image contract, the `sync_chat` contract is not published. Anonymous callers receive the unfiltered set of configured contracts; for a logged-in caller a contract is present only when its key is in the plan-filtered `capabilities.chat_models`, and `server_chat_models` is recomputed from the filtered list.

Sites can adjust the published list with the filter `alorbach_server_chat_model_contracts( array $contracts, array $text_models )`, which receives the built contracts and the configured text catalog (`compound key => label`). The returned value is validated and fails closed: The filter may only remove contracts or change documented fields of contracts the Gateway built for this call: contracts with a `gateway_model_key` that was not built (added keys), contracts whose `provider` is a relay provider, and duplicate keys are discarded, and each kept contract is rebuilt from the documented fields only, in documented order (unknown fields such as `api_key` or `endpoint` are discarded; missing fields fall back to the built value). When a contract stays eligible, `disabled_reason` is forced to `null`. Further: a non-array publishes nothing, and entries that are not arrays, lack a string `gateway_model_key`, do not have `transport: "sync_chat"`, or carry a relay or local key are dropped; the list is re-indexed. A contract stays eligible only when `eligible` is strictly `true`, `admin_enabled`, `server_dispatch_evidenced`, `dispatch_entry_exclusive` and `pricing_evidenced` are strictly `true`, and `supported_languages` and `operation_slugs` are non-empty arrays; otherwise it is forced to `eligible: false` with `disabled_reason: "filter_invalid"` (an existing reason is kept). `requires_browser_pairing`, `structured_output`, `provider_cancel`, `provider_resume` and `text_cost_estimate` are forced to `false`.

For Direct Azure GPT Image entries, use only the advertised discrete sizes; `auto` is not a pixel preset. `POST /images` and `POST /images/jobs` validate `size`, `quality`, `output_format`, `background` (when advertised), `aspect_ratio` (when supplied), `n`, and reference-image count against this contract before provider dispatch. Validation errors distinguish unsupported quality, size, aspect ratio, output format, missing reference-image support, and a reference-image count above `reference_images_max`. It accepts an optional `client_request_id` (maximum 128 characters), uses it for Gateway-side duplicate detection, and echoes it in a successful response so downstream job records can retain the correlation without exposing credentials.

### `GET /integration/plans`

Canonical integration plan catalog.

Query params:

- `include_inactive` optional boolean

Use this when another WordPress integration wants to render gateway-owned plan data instead of duplicating it.

The response shape is an object with a top-level `plans` array:

```json
{
  "plans": [
    {
      "slug": "basic",
      "public_name": "Basic",
      "is_free": true
    }
  ]
}
```

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

Validates provider access for server-side providers. User-owned AI Model Relay runs through browser-mediated local jobs instead of this endpoint.

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

#### `GET|POST /admin/fetch-importable-models`

Fetches models available for import from configured providers.

#### `POST /admin/import-models`

Imports selected models into the plugin model registry.

#### `POST /admin/reset-models`

Resets imported models to defaults.

#### `POST /admin/refresh-azure-prices`

Fetches the current Azure Retail Prices data and updates existing text-model price rows that belong to configured Azure accounts. It also refreshes the selected GPT-Image-2 token-rate tier used to bill completed image generations from provider-reported token usage. Text rows without a matching Azure Retail meter are left unchanged.

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

#### `POST /admin/image-jobs/actions`

Runs queue maintenance actions used by the Image Queue admin page.

Request fields:

- `action` required

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
- server-side consumers (no browser) must select only models published as eligible `sync_chat` contracts in `GET /integration/config` (see Public Integration Endpoints)

### Image Models

Used for image generation via `/images` or the async image-job endpoints.

- typical use cases: image generation, preview frames, downloadable final artwork
- main providers: OpenAI, AI Model Relay, Azure OpenAI, Google, Hugging Face, Hugging Face Spaces
- supports both synchronous and async job flows
- imported Hugging Face and Hugging Face Spaces image models may appear in `/me/models` when configured

### Audio Models

Used for speech-to-text transcription via `/transcribe`.

- typical use cases: transcription, dictated notes, uploaded audio processing
- main providers: OpenAI, Azure OpenAI, Azure Speech Services
- `azure-speech` uses Azure Speech Services for transcription with word-level timestamps

### Video Models

Used for video generation via `/video`.

- typical use cases: prompt-to-video generation
- main providers: OpenAI, Azure OpenAI

### Codex Models

Codex models are a specialized text-model path for coding and agent-style responses.

- provider path: Codex OAuth and Azure Codex-compatible deployments
- request behavior can use a Responses/SSE-style backend flow rather than a plain chat-completions path

Do not treat Codex as an image, audio, or video category. From an integration perspective it belongs under text-generation workflows.

### AI Model Relay

AI Model Relay lets a logged-in user run supported Gateway requests through their own Codex CLI, Cursor Agent, Grok CLI, or Local ASR installation.

- canonical provider key: `ai_bridge`; compatibility provider key: `codex_local`
- authentication: provider login in the user's local account plus a per-origin browser pairing token
- text IDs: `model-relay:codex:*`, `model-relay:cursor-cli:*`, and `model-relay:grok-cli:*`
- media IDs: `model-relay:codex:image`, `model-relay:grok-cli:image`, and experimental `model-relay:grok-cli:video`
- audio IDs: `model-relay:local-asr:*` and `local-asr:*`
- compatibility IDs: `codex-local:auto`, `codex-local:image`, and `codex-local:audio:*`
- cost behavior: upstream provider cost is recorded as `0`; optional capability fees can be configured in UC

The integration does not use a stored WordPress API key. WordPress signs jobs and remains authoritative for capabilities and model allowlists. Dynamic IDs are accepted only for the supported backend namespace and matching capability. Restricted legacy plans do not receive relay access unless an exact relay ID or capability-scoped `model-relay:*` wildcard is configured.

The integration config contains both `ai_bridge` and its `local_codex` alias. Successful results contain both `ai_bridge: true` and `local_codex: true`.

Codex image output is natively PNG, and Grok Imagine output is natively JPEG. Either model accepts a requested PNG or JPEG; WordPress converts the completed image when that request is not the native format. WebP is rejected. The signed payload sent to the Relay always uses the native format, and Grok does not accept a resolution control.

### AI Model Relay REST Flow

1. Read `GET /ai-bridge/config` (legacy alias: `GET /local-codex/config`).
2. Detect and pair the tray app through `/v1/status` and `/v1/pair`, then fetch `/v1/relay/models` and capability/backend readiness metadata.
3. Create a WordPress job with `POST /ai-bridge/jobs`.
4. For `model-relay:*` and `local-asr:*`, send the signed job to `/v1/relay/jobs/chat`, `/v1/relay/jobs/images`, `/v1/relay/jobs/videos`, or `/v1/relay/jobs/transcribe`.
5. For `codex-local:*`, retain the legacy `/v1/chat`, `/v1/images`, and `/v1/transcribe` tray routes so older installations remain usable.
6. If an image job is still `created` but its browser handoff token was lost, renew it with `POST /ai-bridge/jobs/<job_id>/handoff` and the original `request_hash`. The route is owner-bound, preserves the original payload, caps renewals at eight, and stores only the renewed token hash. It returns `local_codex_job_completed` when a durable completion receipt already exists so clients can recover through the receipt route without dispatching another image job.
7. Complete with `POST /ai-bridge/jobs/<job_id>/complete`, or fail with `POST /ai-bridge/jobs/<job_id>/fail`. Every `/local-codex/*` WordPress route remains an equivalent compatibility alias.

Chat results use OpenAI-compatible `choices` and usage. Image results contain `b64_json`; transcription requires word timing; video results contain validated `b64_video` and MIME data and should be rendered through a browser Blob URL.

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
- `provider_progress`
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

This matters if your front-end integration needs to explain when balance changes will occur.

It also matters for Basic users with manually granted credits: a positive balance unlocks the full configured capability/model catalog even when the resolved plan is still `basic`.

---

## Queue and Admin Monitoring

The plugin includes `AI Gateway -> Image Queue` for operational inspection of recent jobs.

The queue page shows:

- queued, in-progress, completed, and failed counts
- recent jobs with user, model, status, and progress
- detail view with original prompt, sanitized prompt, previews, final images, runtime, and cost

This queue UI is intended for monitoring and debugging, not as a stable public API for third-party dashboards.

### Queue Cleanup

Operator cleanup actions are available to admins on the Image Queue page and through the optional WP-CLI surface.

- `Clear Failed` removes failed jobs, their queue transients, their index entries, and their owned preview/reference assets. Final assets are deleted only when the cleanup rules allow it and the attachment is not marked as promoted downstream.
- `Clear Stalled` removes queued or in-progress jobs that have exceeded the stall threshold, together with their owned queue assets.
- `Clear Completed` removes completed jobs and their queue metadata. It keeps final assets by default.
- `Clear Expired` removes jobs whose transient state has expired or gone missing, and prunes their queue record.
- `Clear All` removes every indexed job using the same ownership checks.
- `Prune Index` removes stale job IDs from the queue index without deleting media.

What cleanup does not delete:

- unrelated WordPress attachments
- any attachment that is not explicitly marked as owned by the target job
- final attachments that are already promoted into a downstream workflow unless the cleanup path is forced and the code path explicitly allows that deletion

Recommended uses:

- provider outages and retries after API failures
- billing exhaustion or provider-side rejection loops
- sample-gallery or demo-page debugging when stale queue state obscures the current job list

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

Gateway-wide model defaults are managed in `AI Gateway -> Settings -> General Defaults`. `AI Gateway -> Demo Defaults` only controls demo-page selection behavior and sample page creation.

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

- `README.md`: public plugin overview and high-level endpoint summary
- `DEVELOPER-GUIDE.md`: external integration and REST API reference
- `AI Gateway -> Developer` in wp-admin: interactive developer/admin reference and test tooling

## Scope note

This guide is for developers integrating this plugin from themes, custom pages, and third-party WordPress plugins.

For questions about maintaining or changing plugin source code in this repository, use the maintainer onboarding path in your project environment (not this guide).

## Integration validation checklist

Before shipping a custom frontend integration, check:

- endpoint contracts in /wp-json/alorbach/v1 and response fields you depend on
- authentication and REST nonce behavior for logged-in users
- error handling (401, 403, 422, and provider-level failures)
- image job progress behavior for the models you expose
- secrets policy: never render API keys or provider credentials in the browser or logs
