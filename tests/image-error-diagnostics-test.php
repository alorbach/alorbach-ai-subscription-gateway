<?php
/** Offline regressions for safe provider image-error diagnostics. */

use Alorbach\AIGateway\Image_Error_Diagnostics;

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-image-error-diagnostics.php';

function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

function diagnostic_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$private_message = 'ResponsibleAI policy violation while processing private prompt: sketch of a house; sk-credential-value';
$policy = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'ResponsibleAIPolicyViolation', 'message' => $private_message ) ),
	array( 'x-request-id' => 'req_ABC-123:xyz.456' ),
	'{"prompt":"private prompt"}'
);
diagnostic_check( 'responsible_ai_policy_violation' === $policy['provider_error_code'], 'Azure ResponsibleAIPolicyViolation maps to a bounded canonical code' );
diagnostic_check( 'content_policy_violation' === $policy['provider_error_reason'], 'policy code maps to the canonical reason enum' );
diagnostic_check( 'req_ABC-123:xyz.456' === $policy['provider_request_id'], 'known request ID header accepts the documented opaque ID format' );
diagnostic_check( hash( 'sha256', $private_message ) === $policy['provider_error_message_sha256'], 'message fingerprint remains available for correlation' );
diagnostic_check( false !== strpos( $policy['provider_error_message'], '[request text redacted]' ) && false !== strpos( $policy['provider_error_message'], '[credential redacted]' ), 'actual provider wording is retained with exact prompt and key redacted' );
diagnostic_check( false === strpos( wp_json_encode( $policy ), 'private prompt' ) && false === strpos( wp_json_encode( $policy ), 'sk-credential-value' ), 'provider message contains neither prompt echo nor credential' );

$unsupported = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'unsupported_parameter', 'param' => 'output_format', 'message' => 'Unsupported parameter value' ) )
);
diagnostic_check( 'unsupported_parameter' === $unsupported['provider_error_reason'] && 'output_format' === $unsupported['provider_error_param'], 'structured unsupported-parameter code determines the reason and retains only the allowlisted field path' );

$quoted_echo = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'BadRequest', 'message' => 'This is not a content policy violation. The quoted prompt mentions unsupported_parameter and invalid image size.' ) )
);
diagnostic_check( 'provider_rejected' === $quoted_echo['provider_error_reason'], 'negated and quoted prompt text cannot override an ambiguous structured BadRequest' );

$freeform_words = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'message' => 'rate limit, model unavailable, unsupported parameter, content policy violation: these are unrelated words in a private prompt echo' ) )
);
diagnostic_check( 'unknown' === $freeform_words['provider_error_reason'], 'freeform and unrelated message words never invent a provider cause' );

$bad_size = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'BadRequest', 'param' => 'size', 'message' => 'Invalid image size' ) )
);
diagnostic_check( 'bad_request' === $bad_size['provider_error_code'] && 'invalid_size' === $bad_size['provider_error_reason'], 'Azure BadRequest with an invalid size identifies the size reason' );

$deployment = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'DeploymentNotFound', 'message' => 'The deployment was not found' ) )
);
diagnostic_check( 'deployment_not_found' === $deployment['provider_error_code'] && 'model_unavailable' === $deployment['provider_error_reason'], 'Azure DeploymentNotFound maps to model unavailable' );

$google = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid request', 'details' => array( array( 'fieldViolations' => array( array( 'field' => 'size' ) ) ) ) ) )
);
diagnostic_check( 'invalid_parameter' === $google['provider_error_code'] && 'invalid_size' === $google['provider_error_reason'], 'structured Google field path is normalized without copying arbitrary field text' );

$rate_limit = Image_Error_Diagnostics::from_payload( array( 'error' => array( 'code' => 'rate_limit_exceeded' ) ) );
diagnostic_check( 'rate_limit' === $rate_limit['provider_error_reason'], 'known rate-limit codes use the rate-limit reason' );

$bare = Image_Error_Diagnostics::from_payload(
	array( 'arbitrary_body' => 'private raw body', 'unknown_field' => 'freeform value' ),
	array( 'x-request-id' => 'eyJhbGciOiJub25lIn0.eyJwcm9tcHQiOiJzZWNyZXQifQ.signature' )
);
diagnostic_check( 'unknown' === $bare['provider_error_reason'] && null === $bare['provider_error_code'], 'bare status/body evidence does not invent a verified cause' );
diagnostic_check( null === $bare['provider_error_param'] && null === $bare['provider_request_id'] && null === $bare['provider_error_message_sha256'], 'arbitrary body keys and token-shaped request IDs produce no diagnostic values' );
diagnostic_check( false === strpos( wp_json_encode( $bare ), 'private raw body' ) && false === strpos( wp_json_encode( $bare ), 'freeform value' ), 'arbitrary provider body values are not returned' );

diagnostic_check( null === Image_Error_Diagnostics::normalize_param( 'headers.Authorization' ), 'arbitrary provider field paths are rejected' );
diagnostic_check( null === Image_Error_Diagnostics::normalize_request_id( 'Bearer sk-demo-marker' ), 'bearer and key-shaped request IDs are rejected' );
diagnostic_check( null === Image_Error_Diagnostics::normalize_request_id( 'req:sk-proj-' . str_repeat( 'X', 48 ) ), 'credential-shaped marker embedded after a request prefix is rejected' );
diagnostic_check( null === Image_Error_Diagnostics::normalize_request_id( 'reqxsk-proj-demo-marker' ), 'credential markers hidden within a valid ID segment are rejected' );
diagnostic_check( null === Image_Error_Diagnostics::normalize_request_id( 'req:' . str_repeat( 'A', 48 ) ), 'long token-shaped segments are rejected anywhere in the ID' );
$embedded_jwt = 'request-id:' . str_repeat( 'A', 8 ) . '.' . str_repeat( 'B', 8 ) . '.' . str_repeat( 'C', 8 );
diagnostic_check( null === Image_Error_Diagnostics::normalize_request_id( $embedded_jwt ), 'JWT-shaped segments are rejected when embedded in an otherwise valid ID' );
diagnostic_check( '12345678-1234-4234-8234-123456789abc' === Image_Error_Diagnostics::normalize_request_id( '12345678-1234-4234-8234-123456789abc' ), 'UUID request IDs remain accepted' );
diagnostic_check( '0123456789abcdef0123456789abcdef' === Image_Error_Diagnostics::normalize_request_id( '0123456789abcdef0123456789abcdef' ), 'known request header context allows opaque 32-character hexadecimal IDs' );

$azure_message = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'BadRequest', 'message' => 'Invalid image size. See https://user:password@docs.example.test/images?sig=DEMO_QUERY#section for supported sizes.' ) )
);
diagnostic_check( 'provider_rejected' === $azure_message['provider_error_reason'], 'an unknown structured detail does not promote message wording into a reason' );
diagnostic_check( false !== strpos( $azure_message['provider_error_message'], 'Invalid image size.' ) && false !== strpos( $azure_message['provider_error_message'], '[URL redacted]' ), 'useful Azure message survives while the entire documentation URL is removed' );
diagnostic_check( false === strpos( $azure_message['provider_error_message'], 'password' ) && false === strpos( $azure_message['provider_error_message'], 'DEMO_QUERY' ), 'URL userinfo and query are not exposed' );

$secrets = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'UnknownProviderCode-123', 'message' => 'Request failed for person@example.test; api-key=DEMO_API_KEY_123; Authorization: Bearer DEMO_BEARER_TOKEN_123; sk-proj-DEMO_OPENAI_KEY_1234567890; eyJhbGciOiJub25lIn0.eyJwcm9tcHQiOiJkZW1vIn0.DEMO_SIGNATURE_123' ) )
);
diagnostic_check( null === $secrets['provider_error_code'] && 'unknown' === $secrets['provider_error_reason'], 'unknown provider codes remain unknown' );
foreach ( array( 'person@example.test', 'DEMO_API_KEY_123', 'DEMO_BEARER_TOKEN_123', 'DEMO_OPENAI_KEY_1234567890', 'eyJhbGciOiJub25lIn0' ) as $secret ) {
	diagnostic_check( false === strpos( $secrets['provider_error_message'], $secret ), 'credential, email, or JWT marker is redacted: ' . $secret );
}
diagnostic_check( false !== strpos( $secrets['provider_error_message'], '[email redacted]' ) && false !== strpos( $secrets['provider_error_message'], '[credential redacted]' ), 'redaction leaves useful sentence structure' );

$prompt = 'A highly private scene of a silver fox crossing the quiet forest at sunrise';
$prompt_echo = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'BadRequest', 'message' => 'Provider rejected prompt: "' . $prompt . '" because this private description is not accepted.' ) ),
	array(),
	array( 'prompt' => $prompt )
);
diagnostic_check( false === strpos( $prompt_echo['provider_error_message'], 'silver fox' ) && false === strpos( $prompt_echo['provider_error_message'], 'quiet forest at sunrise' ), 'full prompt and overlapping exact prompt fragments are removed even inside quotes' );
diagnostic_check( false !== strpos( $prompt_echo['provider_error_message'], 'Provider rejected prompt:' ) && false !== strpos( $prompt_echo['provider_error_message'], 'because this private description is not accepted.' ), 'prompt redaction preserves provider explanation around the echo' );

$short_name = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'unsupported_parameter', 'message' => 'Unsupported parameter near Li Bo; choose a supported image quality.' ) ),
	array(),
	array( 'prompt' => array( 'Li', 'Bo', 'walks beside the river' ) )
);
diagnostic_check( 'unsupported_parameter' === $short_name['provider_error_reason'], 'prompt redaction leaves the structured provider reason intact' );
diagnostic_check( false === strpos( $short_name['provider_error_message'], 'Li Bo' ) && false !== strpos( $short_name['provider_error_message'], 'Unsupported parameter near' ), 'two-word private names are removed while useful provider text remains' );

$nested_prompt = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'code' => 'BadRequest', 'message' => 'Invalid request: the prompt named Li Bo in the final scene; use a supported image size.' ) ),
	array(),
	array( 'prompt' => array( 'segments' => array( 'A private character named Li Bo waits by the water' ) ) )
);
diagnostic_check( false === strpos( $nested_prompt['provider_error_message'], 'Li Bo' ) && false !== strpos( $nested_prompt['provider_error_message'], 'use a supported image size.' ), 'nested array-valued prompts remain available for echo redaction without losing the provider explanation' );

$long_name = Image_Error_Diagnostics::from_payload(
	array( 'error' => array( 'message' => 'Request rejected for BlueHeronAlias; change the output format.' ) ),
	array(),
	array( 'prompt' => 'Paint BlueHeronAlias on the shoreline' )
);
diagnostic_check( false === strpos( $long_name['provider_error_message'], 'BlueHeronAlias' ) && false !== strpos( $long_name['provider_error_message'], 'change the output format.' ), 'single long prompt tokens are removed without discarding other diagnostics' );

$plain = Image_Error_Diagnostics::from_payload(
	null,
	array(),
	array( 'prompt' => 'Draw a red balloon over the harbor' ),
	"Azure upstream error: prompt=Draw a red balloon over the harbor; api_key=DEMO_PLAIN_BODY_KEY_123\n"
);
diagnostic_check( false !== strpos( $plain['provider_error_message'], 'Azure upstream error:' ) && false === strpos( $plain['provider_error_message'], 'red balloon' ) && false === strpos( $plain['provider_error_message'], 'DEMO_PLAIN_BODY_KEY_123' ), 'non-JSON plain errors retain safe text without body secrets or prompt echoes' );

$html = Image_Error_Diagnostics::safe_provider_message( "<b>Invalid input</b>\x01 was rejected &amp; needs correction." );
diagnostic_check( 'Invalid input was rejected & needs correction.' === $html, 'HTML entities and control characters are normalized safely' );
$long = Image_Error_Diagnostics::safe_provider_message( str_repeat( 'Ä', 1300 ) );
diagnostic_check( strlen( $long ) <= 2048 && 1 === preg_match( '//u', $long ), 'safe message output is valid UTF-8 and capped at 2048 bytes' );
diagnostic_check( null === Image_Error_Diagnostics::safe_provider_message( 'provider said: private prompt echo', 'not-json-context' ), 'unparseable request context fails closed rather than risking a prompt leak' );

echo "PASS: safe image provider diagnostics offline regressions\n";
