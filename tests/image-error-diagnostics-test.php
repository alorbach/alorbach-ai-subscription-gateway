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
	array( 'x-request-id' => 'req_ABC-123:xyz.456' )
);
diagnostic_check( 'responsible_ai_policy_violation' === $policy['provider_error_code'], 'Azure ResponsibleAIPolicyViolation maps to a bounded canonical code' );
diagnostic_check( 'content_policy_violation' === $policy['provider_error_reason'], 'policy code maps to the canonical reason enum' );
diagnostic_check( 'req_ABC-123:xyz.456' === $policy['provider_request_id'], 'known request ID header accepts the documented opaque ID format' );
diagnostic_check( hash( 'sha256', $private_message ) === $policy['provider_error_message_sha256'], 'only a message fingerprint is returned' );
diagnostic_check( false === strpos( wp_json_encode( $policy ), 'private prompt' ) && false === strpos( wp_json_encode( $policy ), 'sk-credential-value' ), 'provider message, prompt, and credential are not returned' );

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

echo "PASS: safe image provider diagnostics offline regressions\n";
