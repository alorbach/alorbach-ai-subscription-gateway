# Direct image attempt recovery

Keyed direct image requests send `client_request_id` and, for BuchWerk dispatch, the immutable `client_request_hash`. Gateway claims the owner/request pair before provider handoff and records the canonical provider-request hash. A repeated key never dispatches again. After any provider handoff, transport and HTTP errors remain `unknown` unless signed evidence establishes the result; status codes do not prove that the provider did not accept or meter a request.

Read owner evidence with `GET /wp-json/alorbach/v1/images/attempts/{client_request_id}`. The endpoint is authenticated and read-only. It does not create tables. A protected result can be read with `/result` only when the owner, `evidence_id`, and canonical Gateway `request_hash` match. Successful output is saved before billing, and the saved output omits cost fields until the Gateway ledger charge commits. The result endpoint releases bytes only after `billing_status=recorded` with a known user cost, or after a verified signed attestation settles that cost; pending or conflicted rows return no bytes.

## Signed historical evidence

An owner can request a reconciliation through `POST /wp-json/alorbach/v1/images/attempts/{client_request_id}/reconcile` only after confirming the attempt. The request includes `confirmed: true`, the currently observed `expected_revision` (use `0` only when lookup returns the stable not-found result), an idempotency key, the signed envelope, and its base64 signature. A conflict or stale revision requires a fresh read.

The canonical envelope schema is `gateway-image-attestation.v1`. Its ordered fields are `schema`, `issuer`, `attestation_id`, `owner_id`, `client_request_id`, `client_request_hash`, `provider_case_id`, `issued_at`, `outcome`, `cost_usd`, `provider_cost_usd`, `source_uri`, and `artifact_sha256`. Sign the exact UTF-8 bytes of `wp_json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` after arranging those fields in that order. The signature algorithm is RSA with SHA-256. The source URI is recorded as provenance and is never fetched.

Issuer keys are configured on the server with the `alorbach_image_attestation_public_keys` filter. Only RSA public keys in this server-side map are trusted. Every key must list its explicit authority set; there is no default key or default authority:

```php
add_filter(
    'alorbach_image_attestation_public_keys',
    static function ( $keys ) {
        $keys['billing-review-board-v1'] = array(
            'public_key' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
            'authorities' => array( 'outcome', 'user_cost', 'provider_cost' ),
        );
        return $keys;
    }
);
```

`outcome` authority is required for every envelope. `user_cost` authorizes only the actual Gateway debit (`cost_usd`), and `provider_cost` authorizes the upstream provider charge (`provider_cost_usd`). A provider invoice alone does not prove the Gateway user debit. Leave an amount `null` when the issuer cannot establish it; a rejected outcome does not imply zero cost. A confirmed retry is safe only for a signed rejection with known user and provider costs, and BuchWerk must issue a new job UUID with fresh authorization. Same-key replay never retries.

Reconciliation is append-only. Identical idempotent replay returns the saved projection. Reuse of an issuer attestation ID with changed claims is rejected. A changed outcome, incompatible known cost, different request binding, or contradictory claim for the same issuer/provider case is retained as a conflict; conflict blocks result import and retry.

The offline Gateway regression command is `php tests/image-attempt-recovery-test.php`. It uses generated test RSA material and does not contact an image provider or touch billing.
## Transactional storage preflight

Keyed image dispatch verifies the existing attempts and ledger tables use InnoDB
before handing a request to the provider and checks again before billing. The
check reads table status; it never converts an engine or migrates live billing.
Unsupported/unavailable ledger storage before handoff creates durable
`gateway_preflight_v1` evidence: `not_sent`, `rejected`, `not_charged`, with both
actual user and provider costs explicitly zero. If attempt storage itself cannot
honor the claim transaction, dispatch fails closed without fabricated evidence.
A post-handoff storage failure remains unresolved; it never becomes zero-cost
preflight evidence. An operator must diagnose/repair unsupported storage through
an approved database maintenance procedure before new paid work can start.
