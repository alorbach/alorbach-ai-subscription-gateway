# Advanced User Guide

This guide helps plugin operators and integrators understand how to use the gateway in production-like setups and what is currently implemented versus planned for Hugging Face Spaces.

Last updated: 2026-04-15

## Table of Contents

1. [Who this guide is for](#who-this-guide-is-for)
2. [Plugin flow in one screen](#plugin-flow-in-one-screen)
3. [Provider setup and API keys](#provider-setup-and-api-keys)
4. [Hugging Face Spaces: advanced setup](#hugging-face-spaces-advanced-setup)
5. [Demo workflow and API behavior](#demo-workflow-and-api-behavior)
6. [Async calls and image job lifecycle](#async-calls-and-image-job-lifecycle)
7. [Roadmap vs implementation state](#roadmap-vs-implementation-state)
8. [Error and verification behavior](#error-and-verification-behavior)
9. [Security and redaction rules for this guide and screenshots](#security-and-redaction-rules-for-this-guide-and-screenshots)

## Who this guide is for

Use this document if you:

1. Need to onboard clients/users with the WordPress admin UI.
2. Configure multiple providers or non-default request modes.
3. Troubleshoot verification, failures, and image jobs.
4. Need a transparent status of Hugging Face Spaces implementation before rollout planning.

## Plugin flow in one screen

At runtime, the plugin evaluates:

1. Active request context (API call from frontend/theme/plugin)  
2. Provider selection by model mapping and capability checks  
3. Request execution path (sync vs async/image job path)  
4. Response formatting and cost/matrix counters  

The relevant admin flows are:

- API keys and provider fields (`wordpress-plugin/admin/class-admin-api-keys.php`)
- Default model settings (`wordpress-plugin/admin/class-admin-demo-defaults.php`)
- Cost matrix and limits (`wordpress-plugin/admin/class-admin-cost-matrix.php`)
- Job processing pipeline (`wordpress-plugin/includes/class-image-jobs.php`)

## Provider setup and API keys

### Screenshot workflow: base provider setup

1. Open WordPress admin as a role with plugin settings access.
2. Go to **AI Gateway → API Keys**.
3. Add/edit a provider row:
   1. Provider identifier and label
   2. Encrypted key value
   3. Optional endpoint/headers overrides
4. Save and verify status indicator.

![Provider setup and API key status](images/advanced-user/provider-setup.png)

### What to check

1. Key fields are present and masked in UI.
2. Provider-specific extra fields (for example Hugging Face Spaces fields) appear only where relevant.
3. Save produces a successful admin notice.
4. No secrets are visible in screenshots or shared links.

## Hugging Face Spaces advanced setup

Hugging Face Spaces support uses a dedicated provider entry and specific request mode fields.

### Supported mode and schema controls

1. **Space ID**: exact format expected by the plugin.  
2. **Request mode**:
   1. `gradio_api` for native Gradio flow
   2. `custom_http` for custom endpoint behavior
3. **Schema preset**:
   1. Controls how request payload is shaped
   2. Used with manual model/schema override when needed
4. **API key**: where required by provider transport.

### Screenshot workflow: HF Spaces configuration

1. Open **API Keys** and select Hugging Face Spaces provider row.
2. Confirm `Space ID`, mode, and preset.
3. Enable/verify test endpoint if using custom transport.
4. Save and test verification endpoint.

![Hugging Face Spaces config](images/advanced-user/space-configuration.png)

### Screenshot workflow: transport and request shape comparison

1. Configure one provider row with `gradio_api`.
2. Submit a test request from Demo panel.
3. Capture event stream behavior and status.

![Hugging Face Spaces transport behavior](images/advanced-user/spaces-transport.png)

## Demo workflow and API behavior

### Screenshot workflow: text/image demo request

1. Open the built-in demo page.
2. Choose provider/model context.
3. Submit a prompt or image request.
4. Read response + request timing + returned payload.

![Demo text request (before send)](images/advanced-user/demo-chat-before-send.png)
![Demo text request (final response)](images/advanced-user/demo-chat-final.png)

### Screenshot workflow: expected error state

Use this when a provider or request is not yet healthy.

1. Trigger with invalid schema or unavailable endpoint.
2. Confirm visible status, structured error, and remediation hint.

![Demo error/state capture placeholder from prior environment state](images/advanced-user/demo-error.png)

### Recommended operator checks

1. In each provider migration or transport change, run a smoke demo after save.
2. Confirm request and error messages map to the same model/preset combination used by your production pages.
3. Keep a copy of successful payload templates per environment.

## Async calls and image job lifecycle

Image requests may use async jobs in provider and capability-aware flows.

### Screenshot workflow: image job lifecycle

1. Submit image-capable request.
2. Open job monitor (or plugin-provided job list/overview).
3. Verify state transitions:
   1. Pending
   2. Processing
   3. Completed / Failed

![Image job lifecycle (submitted)](images/advanced-user/demo-image-job-started.png)
![Image job lifecycle (processing)](images/advanced-user/demo-image-wait-01.png)
![Image job lifecycle (completed)](images/advanced-user/demo-image-final.png)

### Interpretation

1. Provider progress output can be returned as status updates depending on provider capabilities.
2. Not all providers expose equal granularity; behavior is capability-dependent.
3. A failed job is surfaced with error state and message details in admin UI and logs.

## Roadmap vs implementation state

This section is aligned with current code behavior and the roadmap file.

| Area | Planned goal | Current implementation status |
| --- | --- | --- |
| Provider registration | Add dedicated Hugging Face Spaces provider integration | Implemented |
| API key/admin fields | Space ID, request mode, schema preset in provider form | Implemented |
| Verification | Probe or metadata checks during provider test | Implemented |
| Request shape handling | Manual model/schema and transport-aware payload generation | Partially implemented |
| Text-to-image transport | Gradio polling and custom HTTP behavior | Partially implemented |
| Zero-friction model/catalog UX | Curated list + import path | Planned |
| Retry and cancellation UX | Hardened retry and cancel controls | Partially/Not fully implemented |
| Advanced progress UX | Rich progress/error timeline and richer state details | Partially implemented |
| Image preview support for Spaces | Preview rendering in all job states | Not yet implemented |
| Public onboarding with workflow screenshots | Updated, role-based advanced docs + screenshots | In progress |

If you need the original planning language, request the maintainer-only roadmap notes from the project maintainers.

## Error and verification behavior

### Common statuses

1. `verification_failed` for invalid endpoint or token configuration.
2. `request_failed` for provider network, payload, or schema mismatch.
3. `provider_unavailable` for temporary service instability.
4. `job_timeout` for long-running image generations that exceed runtime window.

### What users should do

1. Recheck provider config and selected preset.
2. Verify service URL and transport path.
3. Retry once after transient network failures.
4. Open job logs when async calls fail repeatedly and rotate keys if suspicious traffic is detected.

## Security and redaction rules for this guide and screenshots

1. Never include secrets in screenshots:
   1. API keys
   2. Access tokens
   3. webhook secrets
2. Keep hostnames generic if they can be internal-only.
3. If a screenshot contains masked input, blur or crop before commit.
4. For shared docs, replace user IDs and tenant identifiers if shown.
5. Keep screenshot metadata minimal; if possible, re-export to remove camera/source metadata.

## Screenshot capture convention used in this guide

1. File naming: `{topic-kebab}.png`
2. Root folder:
   - `images/advanced-user/`
3. Standard size target:
   - 1200×800 desktop, cropped to action-relevant panel only
4. Caption format:
   - `#1 Provider setup and key status`
   - `#2 HF Spaces configuration`
5. Verification checklist for each screenshot:
   - Context (admin page and route)
   - Action performed
   - Expected pre/post state
   - Error path observed (if relevant)
   - Sensitive values masked

## Contributor references from this guide

1. For coding workflow and PR process, see [Developer Guide](DEVELOPER-GUIDE.md).
2. For API consumer-side integration, see [plugin API docs](../README.md).
