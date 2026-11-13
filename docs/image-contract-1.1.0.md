# Image contract 1.1.0

Version `1.1.0` of the published `direct_image` contract (`image_contract_version`) and the matching request behavior of `POST /images`. The published contract fields are additive: a 1.0 consumer that ignores unknown fields keeps working, and `POST /images` without `async`/`mask_image` behaves as before apart from two behavior changes (not purely additive): the prompt is now validated and used unchanged (section 2, including the replay note) and entry-bound keys dispatch on exactly their entry (credential routing, section 1). Chat vision uses `chat_contract_version` `1.1.0` of the `sync_chat` contract.

Audience: server-side consumers that generate images without an open browser (for example a book studio) and need explicit, machine-readable evidence of what the Gateway can do and how an attempt ends.

## 1. Published evidence (`GET /integration/config`)

Each Direct Azure GPT Image model appears in `capabilities.models` with `transport: "direct_image"` (key form `<entry-id>::<model>`; the single-colon alias resolves to the same contract). New in 1.1.0:

| Field | Meaning |
| --- | --- |
| `image_contract_version` | `"1.1.0"` |
| `eligible` / `disabled_reason` | `true` only when dispatch, entry exclusivity and prices are all proven. First failing gate: `dispatch_credentials_missing`, `dispatch_entry_ambiguous`, `pricing_missing`; otherwise `null`. |
| `server_dispatch_evidenced` | The named entry is enabled, has an API key and endpoint, and the Azure provider is registered with image support. (`direct_dispatch_evidenced` is the 1.0 flag without the provider check and is kept.) |
| `dispatch_entry_exclusive` | Billing and dispatch run on the one entry named by the key: `API_Client::resolve_image_dispatch()` resolves the key, with and without reference images, to that same entry and its own provider type. |
| `operation_kinds` | Subset of `text_to_image`, `image_edit` (needs `reference_images_max > 0`), `image_inpaint` (needs `mask_supported`). Empty when not eligible. |
| `size_rules` | See below; `null` when not eligible. |
| `qualities` | Accepted `quality` values. |
| `candidate_count_max` | Maximum `n`. |
| `reference_images_max` | Maximum reference images (0 = none). |
| `mask_supported` | `true` only when the contract is eligible, references are supported, the provider path declares mask support (`supports_image_mask()`), and the filter `alorbach_direct_image_mask_supported( true, $gateway_model_key )` does not withdraw it. |
| `async_supported` | `true` when eligible: `POST /images` accepts `async=1`. |
| `request_limits` | `{ max_prompt_chars: 32000, max_mask_bytes: 20971520, max_body_bytes }`. `max_body_bytes` is PHP `post_max_size` (null if unlimited); a web server or proxy limit in front of PHP can be lower. |

`size_rules`:

```json
{
  "mode": "arbitrary",
  "max_edge": 3840,
  "min_edge": 16,
  "min_pixels": 655360,
  "max_pixels": 8294400,
  "multiple_of": 16,
  "aspect_min": 0.333333,
  "aspect_max": 3.0,
  "presets": []
}
```

`arbitrary` (GPT Image 2.5 Sunburst/Flare): any `WIDTHxHEIGHT` with both edges multiples of `multiple_of`, at most `max_edge`, with `min_pixels <= width*height <= max_pixels` and `aspect_min <= width/height <= aspect_max`. `preset` (GPT Image 2): only the listed `presets` are valid (`"auto"` is not a pixel size); the bounds are derived from the presets. `min_edge` is the smallest edge any valid size can have: for `arbitrary` the larger of `multiple_of` and the minimum width/height; for `preset` the smallest edge across the presets (not their greatest common divisor, which `multiple_of` carries). The authoritative check remains `validate_direct_image_request()`; the rules describe it.

Operation matrix of the shipped profiles: GPT Image 2.5 `[text_to_image, image_edit, image_inpaint]` (16 references, mask via Azure `images/edits`); GPT Image 2 `[text_to_image]`.

### Entry-bound dispatch (credential routing fix)

A model key that names an entry dispatches on exactly that entry. Previously a plain-name lookup could select OpenAI while the Azure entry's credentials were attached (the Azure key would have been sent to the OpenAI endpoint), and a Foundry endpoint with reference images was rerouted to OpenAI. `API_Client::images()` and `stream_images()` now take the provider type from the entry; a missing or disabled entry refuses the request (`image_dispatch_entry_unavailable`, HTTP 422, recorded as a conclusive not-sent rejection with zero cost). Plain model names (no entry) keep the legacy provider resolution, including the Foundry reroute to OpenAI when reference images are attached. Whenever the resolved provider differs from the provider type of the entry the model name was imported from, that entry's credentials are never used: the request goes out on the resolved provider's own credentials only (for example, a Foundry Azure endpoint with reference images rerouted to OpenAI uses the OpenAI key, never the Azure key). The same holds for `stream_images()`, which resolves through `resolve_image_dispatch()` like `images()`: a compound key whose entry is missing or disabled answers `image_dispatch_entry_unavailable` before any key is read. Entry-bound keys are never rerouted.

## 2. Prompt preservation

`prompt` is no longer passed through `sanitize_text_field`. The text is validated and then used unchanged (provider body, request hash, queue storage): valid UTF-8, no control characters except tab, LF and CR, not empty after trimming, at most 32000 characters. Errors (HTTP 422, no attempt is claimed): `invalid_prompt` (not a string, empty, control characters, invalid UTF-8), `prompt_too_long` (more than 32000 characters). A JSON number is cast to a string first (`12345` becomes `"12345"`); arrays, objects, booleans and null stay invalid. The `client_request_id` request hash binds the exact text, so `"a\nb"` and `"a b"` are different requests. `POST /images/jobs` (relay path) is unchanged.

Upgrade note (breaking for replays): before 1.1.0 the prompt went through `sanitize_text_field`, so the stored request hash of an attempt claimed before the upgrade was computed over the sanitized text. A replay of the same `client_request_id` after the upgrade hashes the exact text; when the two differ (prompts with newlines, tags or other characters the sanitizer changed) the replay answers `409 image_attempt_already_recorded` instead of the stored result. There is no legacy-hash fallback: read such attempts with `GET /images/attempts/<id>` and use a new id for a new request. Prompts that were silently altered before (control characters, more than 32000 characters, non-strings) are now refused with 422.

## 3. `POST /images`

Authenticated, JSON or form body.

| Field | Notes |
| --- | --- |
| `prompt` | required, see section 2 |
| `model` | `gateway_model_key` of an eligible contract |
| `size`, `quality`, `output_format`, `background`, `aspect_ratio`, `n`, `provider_options` | unchanged; validated against the contract |
| `reference_images` | array of `{ "b64_json": "...", "mime_type": "image/png" }`; the **first** image is the edit target |
| `mask_image` | base64 PNG (raw, `data:` URL, or `{ "b64_json": "..." }`) |
| `client_request_id` | max 128 chars `[A-Za-z0-9._:-]`; makes the request an idempotent attempt |
| `client_request_hash` | optional 64-hex binding supplied by the caller |
| `async` | boolean; requires `client_request_id` |

### Mask (inpainting)

`mask_image` must be a PNG with an alpha channel (colour type 4 or 6, or palette with `tRNS`) whose pixel dimensions equal the first reference image. **Transparent pixels mark the area to edit** (OpenAI/Azure semantics); opaque pixels are kept. The Gateway reads PNG/JPEG/WebP headers only and does not decode pixels, so a fully opaque mask is not rejected. It is part of the request hash (`mask_image` is hashed by its base64 bytes after normalization). Provider wire format: `multipart/form-data` on `images/edits` with the parts `prompt`, `n`, `size`, `quality`, `output_format`, (`model` on Foundry), one `image[]` (Azure) / `image` (OpenAI) part per reference image, and a final `mask` part (`filename="mask.png"`, `Content-Type: image/png`).

| HTTP 422 code | Cause |
| --- | --- |
| `invalid_mask_image` | not base64, not a PNG, or empty |
| `mask_alpha_required` | PNG without an alpha channel |
| `mask_dimension_mismatch` | size differs from the first reference image (`data` carries `mask_width`, `mask_height`, `image_width`, `image_height`) |
| `mask_requires_input_image` | `mask_image` without `reference_images` |
| `mask_too_large` | decoded mask above `request_limits.max_mask_bytes` |
| `invalid_reference_image` | first reference image is not PNG/JPEG/WebP |
| `direct_image_mask_unsupported` | the request does not resolve to an eligible direct-image contract with `mask_supported: true`: contract flag `false`, plain model names (no `<entry>::` key) and models without a direct-image contract are refused (HTTP 422; with a `client_request_id` the response is `image_request_rejected` carrying the evidence) |
| `mask_unsupported` | provider path without mask support (conclusive not-sent) |

Provider extension point (backward compatible): `Provider_Base::supports_image_mask( $model )` returns `false` by default. A provider that returns `true` must accept a tenth argument `$request_options` on `build_images_request()` carrying `array( 'mask' => array( 'binary' => <PNG bytes>, 'mime_type' => 'image/png' ) )`. Providers that do not declare mask support are still called with exactly the nine arguments of `Provider_Interface`; the interface signature is unchanged.

### Synchronous (default)

Pre-dispatch refusals raised while building the provider request (`image_dispatch_entry_unavailable`, `mask_unsupported`, `invalid_mask_image`, `mask_requires_input_image`, `no_api_key`, `no_provider` ...) are conclusive not-sent rejections and keep their classified code and the HTTP status of the error (422 for the request-shape refusals, 502 when the error carries none) in the response and in the async job error; only errors after the provider handoff are reported as `image_provider_error` (502).

Unchanged: `200` with the provider response (`data[].b64_json`), `provider_size`, `quality`, `output_format`, `background`, `client_request_id` and cost fields (`cost_uc`, `cost_credits`, `cost_usd`). Errors use the evidence-bearing `image_attempt` data of the recovery contract.

### Asynchronous (`async=1`)

1. The request is validated and claimed exactly like a synchronous keyed request (storage check, rate limit, monthly quota, plan, balance). Nothing is sent to the provider.
2. The prepared request is stored in the queue table `alorbach_image_attempt_jobs` (created on first use) and `202` is returned.
3. A worker runs `dispatch_image_request()` in its own request: a loopback POST to `/alorbach/v1/internal/images/attempts/process` (same transport as `/images/jobs`, hosts filter `alorbach_image_job_internal_hosts`), with a WP-Cron event `alorbach_process_image_attempt` (20 s later) as fallback. Only the atomic `queued -> running` update starts a worker, so concurrent triggers never dispatch twice. The balance is checked again before the provider handoff.
4. The worker calls the provider, saves the response, then writes the ledger row and the billing evidence in one transaction (same as the synchronous path). Bytes are released only after `billing_status` is `recorded` (or a verified attestation).
5. The queue payload (prompt, references, mask) is deleted when the job ends.

The queue row is reserved with status `preparing` inside the claim transaction (before COMMIT; the queue table is created before the claim), so the attempt is never visible without it. If the reservation fails the claim is rolled back and the id is not burned. A concurrent replay of the same `client_request_id` in that window therefore reads `queued` (`execution_mode: async`), not "outcome unknown". A reservation that is not promoted within 120 s (the request died while being prepared) is closed on the next status read as a zero-cost, not-sent rejection (`queue_timeout`).

Size: before the id is claimed, the encoded request context (everything the queue stores: prompt, references, mask, options, model, ids; plus a 4 KiB allowance for the fields computed after the claim) is compared with the smaller of 192 MiB and the database `max_allowed_packet` minus 1 MiB. A larger request answers `413 image_request_too_large` and burns no id. If the queue insert still fails afterwards (storage error), the attempt is recorded as a zero-cost not-sent rejection (`image_queue_unavailable`, 503) and, because a `client_request_id` is one immutable attempt, that id cannot be reused; use a new id.

Timeouts: a job that stays `queued` for more than 24 hours (filter `alorbach_image_attempt_queue_timeout_seconds`) is closed on the next status read as not sent / not charged with the error `queue_timeout` (retry-safe, new id) and its payload is deleted. The worker lease is at least `n` x the per-call provider timeout (default 300 s, filter `alorbach_image_request_timeout`) plus 300 s, stored on the job when it starts, so a Hugging Face request with several candidates is not reaped as `worker_lost` while it is still running.

`202` body (evidence fields as in the recovery contract plus the lifecycle fields):

```json
{
  "attempt_id": "book-17-page-3-v1",
  "client_request_id": "book-17-page-3-v1",
  "status": "queued",
  "execution_mode": "async",
  "result_available": false,
  "error": null,
  "queued_at": "2026-11-13T10:00:00Z",
  "started_at": null,
  "finished_at": null,
  "poll_after_ms": 2000,
  "replayed": false,
  "estimated_cost_uc": 55000,
  "estimated_cost_credits": 55,
  "estimated_cost_usd": "0.05500000",
  "evidence_id": "…",
  "request_hash": "…64 hex…",
  "dispatch_status": "not_sent",
  "outcome": "unknown",
  "billing_status": "unresolved",
  "cost_usd": null,
  "provider_cost_usd": null,
  "retry_safe": false
}
```

`attempt_id` equals `client_request_id` (the route key). The numeric database id is not exposed.

Idempotent replay (async requests only; a synchronous request with the id of a queued, running or failed attempt answers `409 image_attempt_already_recorded`, and only a finished async attempt hands back its stored result): the same `client_request_id` with the same normalized request returns `200` with the stored attempt and `"replayed": true` (any status, including `succeeded`; no bytes). It never dispatches and never charges again. The same id with a different request is `409 image_attempt_already_recorded`. A synchronous request with the id of a finished async attempt returns the stored result (`200`, bytes) without a provider call. A `client_request_id` is one immutable attempt: after a failure use a new id.

| Error (async specific) | HTTP | Meaning |
| --- | --- | --- |
| `image_attempt_claim_failed` | 503 | the claim (or the queue reservation made inside the claim transaction) could not be stored; nothing was recorded or sent and the id is not burned, so the same id may be retried |
| `image_attempt_not_found` | 404 | `GET /images/attempts/<id>` for an unknown id or another owner's id (`/result` answers `404 image_attempt_result_not_found`) |
| `image_request_too_large` | 413 | the encoded request exceeds the queue limit (smaller of 192 MiB and `max_allowed_packet` minus 1 MiB, measured over the same payload the queue stores plus a 4 KiB allowance); nothing is claimed |
| `async_requires_client_request_id` | 422 | `async=1` without a key |
| `image_queue_unavailable` | 503 | queue could not be stored; the attempt is recorded as a zero-cost not-sent rejection |
| `image_request_rejected` | 4xx/503 | pre-dispatch rejection (validation, plan, rate limit, quota, balance) with evidence |

### `GET /images/attempts/<client_request_id>`

Owner-scoped, never creates tables. Returns the evidence projection of `docs/image-attempt-recovery.md` plus:

| Field | Values |
| --- | --- |
| `attempt_id` | the client request id |
| `status` | `queued`, `running`, `succeeded`, `failed`, `unknown` (legacy ledger-only evidence) |
| `execution_mode` | `async`, `sync`, `legacy` |
| `result_available` | `true` when `status` is `succeeded` (billing settled) |
| `error` | `null` or `{ "code", "message" }` |
| `queued_at`, `started_at`, `finished_at` | ISO 8601 UTC or null |
| `poll_after_ms` | 2000 while queued/running, else null |

Status mapping: `queued` = job stored, not leased; `running` = leased by a worker (or a fresh synchronous request in flight); `succeeded` = outcome succeeded and billing recorded; `failed` = everything else that ended. Cost lives in the evidence fields (`cost_usd`, `provider_cost_usd`, `billing_status`). `error.code` values: `worker_failed_before_send` (the worker raised an exception before the provider handoff; not sent, not charged, retry-safe with a new id), `image_dispatch_entry_unavailable` (the entry named by the key vanished or was disabled before the handoff; not sent, retry-safe), `payload_invalid` (the stored queue payload or the attempt row could not be read; the job is closed, check the evidence fields), `attempt_state_conflict` (the attempt was no longer in the not-sent/unknown state when the worker started; nothing was sent), `queue_timeout` (never started: queued for more than 24 hours or abandoned while being prepared; not sent, retry-safe with a new id), `insufficient_credits` (balance no longer covers the image; not sent, retry-safe), `image_request_rejected`, `image_provider_error` (outcome unknown, not retry-safe), `image_billing_pending` (image produced, billing not settled; bytes withheld; do not resend, reconcile), `worker_lost` (lease expired; outcome unknown if the provider was already called, a conclusive zero-cost rejection if not), `worker_exception`, `outcome_unknown`, `attestation_conflict`. Polling is also a safety net (a status read may therefore dispatch a stalled queued job again; such a job was never sent to the provider): a queued job whose last dispatch is older than 5 s is dispatched again with a rotated token, and an expired lease (default 35 minutes, filter `alorbach_image_attempt_lease_seconds`) is closed as `worker_lost`. A request that may have reached the provider is never sent again.

### `GET /images/attempts/<client_request_id>/result`

`200` with the saved provider response (`data[].b64_json`, `provider_size`, `quality`, `output_format`, `background`, `client_request_id`, `cost_uc`, `cost_credits`, `cost_usd`) plus `attempt_id` and `status: "succeeded"` for async attempts. For async attempts `evidence_id` and `request_hash` query parameters are optional (a supplied value must match the stored one); for synchronous attempts both remain required (`400` otherwise). `409 image_attempt_result_pending` (data `attempt_status`) while queued/running; `404 image_attempt_result_not_found` when there is no releasable result (failed, unbilled, unknown key, other owner).

## 4. Vision (`POST /chat`, `sync_chat` contract 1.1.0)

New contract fields: `image_parts_proven`, `max_images`, `max_image_bytes`, `image_part_formats`. An administrator switches vision on per model in Settings > Server Chat Models ("Vision" column); the flag is published only for an otherwise eligible contract whose provider path is proven: OpenAI and Azure OpenAI pass `image_url` parts through unchanged, Google Gemini maps them to `inline_data`. A filter cannot grant it. Defaults: 8 images, 8 MiB decoded per image (filter `alorbach_chat_vision_limits`, hard caps 64 and 32 MiB).

Request: `messages[].content` as an array of `{ "type": "text", "text": "…" }` and `{ "type": "image_url", "image_url": { "url": "data:image/png;base64,…" } }`. Accepted URLs: `data:image/<type>;base64,…` and `https://…` (Gemini refuses remote URLs with `unsupported_image_url`; unknown part types with `unsupported_content_part`). HTTP 422 `too_many_images`, `image_part_too_large`, `invalid_image_part` otherwise. The pre-flight hold counts a flat 1105 tokens per image; billing uses the provider-reported usage. Queue diagnostics store a size marker instead of image bytes.

Gate: image parts need a published `sync_chat` contract of the requested model with `image_parts_proven: true` (the key must equal the contract's key or its single-colon alias; plain names that match no proven contract are refused). Otherwise `POST /chat` answers `422 vision_not_supported`. A part counts as an image part when its type is `image_url`, `input_image` or `image`, or when it carries a `data:image/` URL in `image_url.url`, `image_url` or `url`; all of them are checked against the proven-model gate and the size limits. A request that contains image parts may only contain `text` and `image_url` parts (other image-like types such as `input_image` are refused); any other part (or a non-object part) next to an image answers `422 unsupported_content_part`. Requests without any image part are not inspected and pass through exactly as before (any array content).

REST body limit: the whole JSON body (prompt, base64 references, mask, chat images) must fit PHP `post_max_size`, the web server and any proxy limit. Base64 adds about 33 %. Size the client payloads from `request_limits.max_body_bytes`.

## 5. Not part of 1.1.0

Server-side upscaling (the relay upscaler stays), reference-image support for GPT Image 2, decoding mask pixels, remote image URLs for Gemini, cancelling a provider request.

## 6. Verification (no paid calls)

- `php tests/image-contract-test.php` - validation, golden multipart bodies (OpenAI, Azure), entry-bound routing, capability publication, vision pass-through and Gemini mapping, status projection.
- `php tests/server-chat-models-test.php` - `sync_chat` 1.1.0 contract and vision gates (fixture `tests/fixtures/server-chat-contract.json`).
- `wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/tests/image-async-integration-test.php` - async lifecycle on the real tables with a fake HTTP provider and a throwaway user: 202, status, result gating, idempotent replay, one provider call and one charge, mask multipart, provider failure, insufficient funds, worker loss, re-dispatch, ownership, unchanged sync behavior.

## 7. Live probes that automated tests cannot prove

1. Inpainting on the real Azure `gpt-image-2.5` deployment: does `images/edits` accept and honor `mask` (transparent area edited, rest preserved) on the Foundry route and on the deployment route? If not, set the filter `alorbach_direct_image_mask_supported` to `false`.
2. Real image size limits per edge/pixel count at the largest sizes, and the effective `post_max_size`/proxy body limit for 16 references plus a mask.
3. A vision-capable chat model: a data-URL image through `POST /chat` (OpenAI/Azure) and the Gemini mapping, with real usage-based billing.
4. The loopback worker transport on the production host (otherwise WP-Cron runs the job; check `dispatch_count` in the queue table or time-to-running).
