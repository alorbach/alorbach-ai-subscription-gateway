<?php
/**
 * Verify core REST contract payload shapes from inside a loaded WordPress context.
 *
 * Run with wp-env / WP-CLI, for example:
 * wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/bin/verify-rest-contracts.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside a loaded WordPress context.\n" );
	exit( 1 );
}

$admin = get_user_by( 'login', 'admin' );
if ( ! $admin ) {
	fwrite( STDERR, "Could not resolve the admin user.\n" );
	exit( 1 );
}

wp_set_current_user( (int) $admin->ID );

/**
 * Perform a REST request and decode the response data.
 *
 * @param string $route  Route path.
 * @param string $method HTTP method.
 * @return array
 */
function alorbach_verify_request( $route, $method = 'GET' ) {
	$request  = new WP_REST_Request( $method, $route );
	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		$error = $response->as_error();
		throw new RuntimeException( sprintf( '%s failed: %s', $route, $error->get_error_message() ) );
	}

	return $response->get_data();
}

/**
 * Require object keys to exist.
 *
 * @param array  $payload Payload array.
 * @param array  $keys    Required keys.
 * @param string $label   Human label.
 * @return void
 */
function alorbach_require_keys( array $payload, array $keys, $label ) {
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $payload ) ) {
			throw new RuntimeException( sprintf( '%s is missing key "%s".', $label, $key ) );
		}
	}
}

try {
	$config  = alorbach_verify_request( '/alorbach/v1/integration/config' );
	$plans   = alorbach_verify_request( '/alorbach/v1/integration/plans' );
	$account = alorbach_verify_request( '/alorbach/v1/integration/account' );
	$models  = alorbach_verify_request( '/alorbach/v1/me/models' );

	alorbach_require_keys( $config, array( 'defaults', 'capabilities', 'plan_capabilities', 'billing_urls' ), '/integration/config' );
	alorbach_require_keys( $plans, array( 'plans' ), '/integration/plans' );
	alorbach_require_keys( $account, array( 'user_id', 'balance', 'usage_month', 'billing_urls', 'active_plan' ), '/integration/account' );
	alorbach_require_keys( $models, array( 'text', 'image', 'audio', 'video' ), '/me/models' );
	alorbach_require_keys( $models['image'], array( 'supports_progress', 'supports_provider_progress', 'supports_preview_images', 'progress_mode', 'job_endpoint' ), '/me/models.image' );

	echo "REST contract verification passed.\n";
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
