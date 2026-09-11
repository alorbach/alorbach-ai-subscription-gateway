<?php
/**
 * Verify the configured Direct Azure GPT Image 2 contract without generating
 * an image, calling an upstream provider, changing account balances, or
 * consuming paid AI credits.
 *
 * Run inside a loaded WordPress environment:
 * wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/bin/verify-direct-azure-capabilities.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside a loaded WordPress context.\n" );
	exit( 1 );
}

use Alorbach\AIGateway\Cost_Matrix;
use Alorbach\AIGateway\Integration_Service;
use Alorbach\AIGateway\REST_Proxy;

/** @param bool $condition @param string $message */
function alorbach_direct_azure_require( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

try {
	$admin = get_user_by( 'login', 'admin' );
	alorbach_direct_azure_require( $admin instanceof WP_User, 'Could not resolve the local WordPress admin user.' );
	wp_set_current_user( (int) $admin->ID );

	$model_key = '2497af91-687f-46ea-9cf9-bd9b2b8f4dd6::gpt-image-2';
	$expected = array(
		'size_mode'                => 'preset',
		'supported_sizes'          => array( '1024x1024', '1024x1536', '1536x1024', '2048x2048', '2048x1152', '3840x2160', '2160x3840' ),
		'supported_qualities'      => array( 'low', 'medium', 'high' ),
		'supported_output_formats' => array( 'image/png', 'image/jpeg' ),
		'supported_aspect_ratios'  => array( '1:1', '2:3', '3:2', '16:9', '9:16' ),
		'candidate_count_max'      => 1,
	);

	$request = new WP_REST_Request( 'GET', '/alorbach/v1/integration/config' );
	$response = rest_do_request( $request );
	alorbach_direct_azure_require( ! $response->is_error(), '/integration/config must return a response.' );
	$config = $response->get_data();
	alorbach_direct_azure_require( in_array( $model_key, (array) ( $config['capabilities']['image_models'] ?? array() ), true ), 'The configured Azure compound model must remain in capabilities.image_models.' );
	$contracts = array_values( array_filter( (array) ( $config['capabilities']['models'] ?? array() ), static fn( $candidate ) => is_array( $candidate ) && $model_key === (string) ( $candidate['gateway_model_key'] ?? '' ) ) );
	alorbach_direct_azure_require( 1 === count( $contracts ), 'The configured Azure compound model must expose exactly one Direct capability contract.' );
	$contract = $contracts[0];
	alorbach_direct_azure_require( 'direct_image' === ( $contract['transport'] ?? '' ) && true === ( $contract['eligible'] ?? null ) && true === ( $contract['direct_dispatch_evidenced'] ?? null ) && true === ( $contract['image_capabilities_evidenced'] ?? null ), 'The configured Direct Azure model must be evidenced and eligible.' );
	alorbach_direct_azure_require( $expected === ( $contract['image_capabilities'] ?? null ), 'The Direct Azure image capability profile must exactly match the supported contract.' );

	$valid = Integration_Service::validate_direct_image_request( (int) $admin->ID, $model_key, '1024x1536', 'medium', 'image/jpeg', 1 );
	alorbach_direct_azure_require( is_array( $valid ) && 'jpeg' === ( $valid['output_format'] ?? '' ), 'The Direct contract must normalize image/jpeg and accept a documented size/quality pair.' );
	$invalid_size = Integration_Service::validate_direct_image_request( (int) $admin->ID, $model_key, 'auto', 'medium', 'png', 1 );
	$invalid_count = Integration_Service::validate_direct_image_request( (int) $admin->ID, $model_key, '1024x1024', 'medium', 'png', 2 );
	alorbach_direct_azure_require( is_wp_error( $invalid_size ) && 'direct_image_options_unsupported' === $invalid_size->get_error_code(), 'Direct Azure must reject the legacy auto size.' );
	alorbach_direct_azure_require( is_wp_error( $invalid_count ) && 'direct_image_options_unsupported' === $invalid_count->get_error_code(), 'Direct Azure must reject candidate counts above one.' );

	alorbach_direct_azure_require( 'xhigh' === Cost_Matrix::normalize_image_quality( 'xhigh', 'gpt-image-2.5-sunburst' ), 'GPT Image 2.5 must keep xhigh.' );
	alorbach_direct_azure_require( 'xhigh' === Cost_Matrix::normalize_image_quality( 'extra_high', 'gpt-image-2.5-flare-2026-09-08' ), 'GPT Image 2.5 must map extra_high to xhigh.' );
	alorbach_direct_azure_require( 'max' === Cost_Matrix::normalize_image_quality( 'max', 'gpt-image-2.5-flare' ), 'GPT Image 2.5 must keep max.' );
	alorbach_direct_azure_require( 'xhigh' !== Cost_Matrix::normalize_image_quality( 'xhigh', 'gpt-image-1.5' ), 'Older GPT Image models must not accept xhigh.' );
	alorbach_direct_azure_require( 'transparent' === Cost_Matrix::normalize_image_background( 'transparent', 'gpt-image-2.5-sunburst' ), 'GPT Image 2.5 must accept transparent backgrounds.' );
	alorbach_direct_azure_require( 'png' === Cost_Matrix::coerce_output_format_for_background( 'jpeg', 'transparent' ), 'Transparent JPEG output must coerce to PNG.' );

	$two_five_contracts = array_values( array_filter( (array) ( $config['capabilities']['models'] ?? array() ), static function ( $candidate ) {
		if ( ! is_array( $candidate ) ) {
			return false;
		}
		$key = (string) ( $candidate['gateway_model_key'] ?? '' );
		$plain = strpos( $key, '::' ) !== false ? explode( '::', $key, 2 )[1] : $key;
		return Cost_Matrix::is_gpt_image_2_5_model( $plain );
	} ) );
	foreach ( $two_five_contracts as $two_five ) {
		$caps = is_array( $two_five['image_capabilities'] ?? null ) ? $two_five['image_capabilities'] : array();
		alorbach_direct_azure_require( in_array( 'xhigh', (array) ( $caps['supported_qualities'] ?? array() ), true ) && in_array( 'max', (array) ( $caps['supported_qualities'] ?? array() ), true ), 'Direct GPT Image 2.5 contracts must advertise xhigh and max.' );
		alorbach_direct_azure_require( in_array( 'transparent', (array) ( $caps['supported_backgrounds'] ?? array() ), true ), 'Direct GPT Image 2.5 contracts must advertise transparent backgrounds.' );
		$accepted = Integration_Service::validate_direct_image_request( (int) $admin->ID, (string) $two_five['gateway_model_key'], '1024x1024', 'xhigh', 'png', 1, 'transparent' );
		alorbach_direct_azure_require( is_array( $accepted ) && 'xhigh' === ( $accepted['quality'] ?? '' ) && 'transparent' === ( $accepted['background'] ?? '' ), 'Direct GPT Image 2.5 must accept xhigh with a transparent background.' );
	}

	$estimate_request = new WP_REST_Request( 'GET', '/alorbach/v1/me/estimate' );
	$estimate_request->set_query_params( array( 'type' => 'image', 'model' => $model_key, 'size' => '1024x1536', 'quality' => 'medium', 'output_format' => 'jpeg', 'n' => 1 ) );
	$estimate = REST_Proxy::me_estimate( $estimate_request );
	alorbach_direct_azure_require( $estimate instanceof WP_REST_Response && (int) ( $estimate->get_data()['cost_uc'] ?? 0 ) > 0, 'The non-billable estimate must accept documented Direct Azure options and return a positive cost.' );

	echo wp_json_encode( array( 'verified' => true, 'model' => $model_key, 'transport' => 'direct_image', 'cost_uc' => (int) $estimate->get_data()['cost_uc'], 'paid_provider_calls' => 0 ) ) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Direct Azure capability verification failed: ' . $error->getMessage() . PHP_EOL );
	exit( 1 );
}
