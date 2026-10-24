# Image request parameter handling

This document describes the Gateway's implemented image-request serialization. Provider capabilities govern which normalized options become wire fields; a caller's internal option does not automatically belong in every provider's API body.

## Implemented provider behavior

| Route | `size` handling | `aspect_ratio` handling |
| --- | --- | --- |
| OpenAI Images (`OpenAI_Provider`) | The image request builder sends the supplied size value in `size`. The option filter leaves it unchanged. | The generic field is unsupported. The filter removes an existing ratio field from the body and does not add the normalized ratio or an accepted `provider_options` spelling such as `aspect_ratio` or `ASPECT_RATIO`. |
| Azure OpenAI-compatible Images (deployment and Foundry routes) | The builder sends the supplied size value in `size`; the option filter leaves it unchanged. | The generic field is unsupported and is removed from the final request body, including when supplied through `provider_options`. |
| Azure native Black Forest Labs (FLUX) route | The builder parses a dimension string such as `2208x3312` into `width` and `height`. | The native route supports `aspect_ratio`; the provider-aware option filter preserves it. |
| Other or custom providers | Provider-specific builder behavior applies. | `Provider_Base` keeps the existing pass-through default for compatibility; a provider can override `supports_image_request_option()` to declare an option unsupported. Do not infer the behavior of an unlisted provider from OpenAI or Azure. |

For the unsupported routes, the filter compares normalized option names so accepted case/underscore spelling variants cannot reintroduce `aspect_ratio` through `provider_options`. The same filter is used by direct and streaming image request paths.

## Example: OpenAI-compatible Azure request

For the offline serialization fixture using Azure Foundry model `gpt-image-2.5-sunburst`, the final JSON body is:

```json
{"prompt":"fixture prompt","n":1,"size":"2208x3312","model":"gpt-image-2.5-sunburst","quality":"max","output_format":"png"}
```

The requested `aspect_ratio` remains available in the Gateway's normalized options/provenance, but is omitted from this provider's wire body. The example demonstrates serialization only: `2208x3312`, `max`, and PNG are not claims that every model, deployment, or API version accepts those values. Supported size, quality, and format values depend on the selected provider route and model/deployment capabilities.

## Internal options and provider payloads

The Gateway may retain `aspect_ratio` in the internal image options and request provenance even when a provider route does not accept the generic `aspect_ratio` field. Filtering is applied when building the provider request; it does not rewrite the caller's internal options. For native Azure FLUX, the provider capability allows the ratio field and the builder also derives `width`/`height` from the requested dimensions.

Use the provider's advertised capabilities and current API documentation when choosing values. This reference documents the Gateway's serialization behavior; it does not define universal model limits.

Offline regression: `php tests/image-request-options-test.php`.