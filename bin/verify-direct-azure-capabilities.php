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

	$alias_parsed = Cost_Matrix::parse_model_key( '9bd4a2e7-261b-4640-858c-b963608cf924:gpt-image-2.5-flare' );
	alorbach_direct_azure_require( '9bd4a2e7-261b-4640-858c-b963608cf924' === ( $alias_parsed['entry_id'] ?? '' ) && 'gpt-image-2.5-flare' === ( $alias_parsed['model'] ?? '' ), 'UUID single-colon Gateway aliases must parse to the same entry and model as the canonical compound key.' );
	alorbach_direct_azure_require(
		array( '9bd4a2e7-261b-4640-858c-b963608cf924::gpt-image-2.5-flare', '9bd4a2e7-261b-4640-858c-b963608cf924:gpt-image-2.5-flare' ) === Cost_Matrix::gateway_model_key_aliases( '9bd4a2e7-261b-4640-858c-b963608cf924:gpt-image-2.5-flare' ),
		'Gateway aliases must include both the canonical compound key and the UUID single-colon form.'
	);
	alorbach_direct_azure_require( 'model-relay:codex:image' === Cost_Matrix::parse_model_key( 'model-relay:codex:image' )['model'], 'Relay model IDs must not be treated as UUID compound keys.' );

	$two_five_profile = Integration_Service::DIRECT_AZURE_GPT_IMAGE_2_5_CAPABILITIES;
	alorbach_direct_azure_require( 1 === (int) ( $two_five_profile['contract_version'] ?? 0 ), 'GPT Image 2.5 must publish contract_version 1.' );
	alorbach_direct_azure_require( 16 === (int) ( $two_five_profile['reference_images_max'] ?? -1 ), 'GPT Image 2.5 must publish reference_images_max 16.' );
	alorbach_direct_azure_require( true === ( $two_five_profile['reference_images'] ?? null ), 'GPT Image 2.5 must declare reference-image support.' );
	alorbach_direct_azure_require( 1 === (int) ( $two_five_profile['candidate_count_max'] ?? 0 ), 'GPT Image 2.5 must advertise a candidate_count_max of 1.' );
	alorbach_direct_azure_require( ! empty( $two_five_profile['supported_sizes'] ) && ! empty( $two_five_profile['supported_qualities'] ) && ! empty( $two_five_profile['supported_aspect_ratios'] ) && ! empty( $two_five_profile['supported_output_formats'] ), 'GPT Image 2.5 must publish non-empty size, quality, aspect-ratio, and output-format arrays.' );
	alorbach_direct_azure_require( ! empty( $two_five_profile['provider_options']['size']['values'] ) && ! empty( $two_five_profile['provider_options']['quality']['values'] ), 'GPT Image 2.5 must publish provider_options with non-empty size and quality values.' );
	alorbach_direct_azure_require( ! array_key_exists( 'reference_images_max', Integration_Service::DIRECT_AZURE_GPT_IMAGE_2_CAPABILITIES ), 'GPT Image 2 must not inherit a false 2.5 reference-image declaration.' );

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
	$gpt_image_2_present = in_array( $model_key, (array) ( $config['capabilities']['image_models'] ?? array() ), true );
	$estimate_cost_uc = 0;
	if ( $gpt_image_2_present ) {
		$contracts = array_values( array_filter( (array) ( $config['capabilities']['models'] ?? array() ), static fn( $candidate ) => is_array( $candidate ) && $model_key === (string) ( $candidate['gateway_model_key'] ?? '' ) ) );
		alorbach_direct_azure_require( 1 === count( $contracts ), 'The configured Azure compound model must expose exactly one Direct capability contract.' );
		$contract = $contracts[0];
		alorbach_direct_azure_require( 'direct_image' === ( $contract['transport'] ?? '' ) && true === ( $contract['eligible'] ?? null ) && true === ( $contract['direct_dispatch_evidenced'] ?? null ) && true === ( $contract['image_capabilities_evidenced'] ?? null ), 'The configured Direct Azure model must be evidenced and eligible.' );
		alorbach_direct_azure_require( $expected === ( $contract['image_capabilities'] ?? null ), 'The Direct Azure image capability profile must exactly match the supported contract.' );

		$valid = Integration_Service::validate_direct_image_request( (int) $admin->ID, $model_key, '1024x1536', 'medium', 'image/jpeg', 1 );
		alorbach_direct_azure_require( is_array( $valid ) && 'jpeg' === ( $valid['output_format'] ?? '' ), 'The Direct contract must normalize image/jpeg and accept a documented size/quality pair.' );
		$invalid_size = Integration_Service::validate_direct_image_request( (int) $admin->ID, $model_key, 'auto', 'medium', 'png', 1 );
		$invalid_count = Integration_Service::validate_direct_image_request( (int) $admin->ID, $model_key, '1024x1024', 'medium', 'png', 2 );
		alorbach_direct_azure_require( is_wp_error( $invalid_size ) && 'direct_image_size_unsupported' === $invalid_size->get_error_code(), 'Direct Azure must reject the legacy auto size.' );
		alorbach_direct_azure_require( is_wp_error( $invalid_count ) && 'direct_image_candidate_count_unsupported' === $invalid_count->get_error_code(), 'Direct Azure must reject candidate counts above one.' );

		$estimate_request = new WP_REST_Request( 'GET', '/alorbach/v1/me/estimate' );
		$estimate_request->set_query_params( array( 'type' => 'image', 'model' => $model_key, 'size' => '1024x1536', 'quality' => 'medium', 'output_format' => 'jpeg', 'n' => 1 ) );
		$estimate = REST_Proxy::me_estimate( $estimate_request );
		alorbach_direct_azure_require( $estimate instanceof WP_REST_Response && (int) ( $estimate->get_data()['cost_uc'] ?? 0 ) > 0, 'The non-billable estimate must accept documented Direct Azure options and return a positive cost.' );
		$estimate_cost_uc = (int) $estimate->get_data()['cost_uc'];
	}

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
		return Cost_Matrix::is_gpt_image_2_5_model( Cost_Matrix::parse_model_key( (string) ( $candidate['gateway_model_key'] ?? '' ) )['model'] )
			&& ! empty( $candidate['eligible'] )
			&& is_array( $candidate['image_capabilities'] ?? null )
			&& ! empty( $candidate['image_capabilities'] );
	} ) );
	foreach ( $two_five_contracts as $two_five ) {
		$caps = is_array( $two_five['image_capabilities'] ?? null ) ? $two_five['image_capabilities'] : array();
		$canonical = (string) ( $two_five['gateway_model_key'] ?? '' );
		$alias = (string) ( $two_five['legacy_id'] ?? '' );
		alorbach_direct_azure_require( in_array( 'xhigh', (array) ( $caps['supported_qualities'] ?? array() ), true ) && in_array( 'max', (array) ( $caps['supported_qualities'] ?? array() ), true ), 'Direct GPT Image 2.5 contracts must advertise xhigh and max.' );
		alorbach_direct_azure_require( in_array( 'transparent', (array) ( $caps['supported_backgrounds'] ?? array() ), true ), 'Direct GPT Image 2.5 contracts must advertise transparent backgrounds.' );
		alorbach_direct_azure_require( 1 === (int) ( $caps['contract_version'] ?? 0 ) && 16 === (int) ( $caps['reference_images_max'] ?? 0 ) && 1 === (int) ( $caps['candidate_count_max'] ?? 0 ), 'Direct GPT Image 2.5 contracts must publish contract_version 1, reference_images_max 16, and candidate_count_max 1.' );
		alorbach_direct_azure_require( ! empty( $caps['supported_sizes'] ) && ! empty( $caps['supported_aspect_ratios'] ) && ! empty( $caps['supported_output_formats'] ), 'Direct GPT Image 2.5 contracts must publish non-empty size, aspect-ratio, and output-format arrays.' );
		alorbach_direct_azure_require( ! empty( $caps['provider_options']['size']['values'] ) && ! empty( $caps['provider_options']['quality']['values'] ), 'Direct GPT Image 2.5 contracts must publish provider_options with non-empty values.' );
		$mapped_canonical = is_array( $config['capabilities']['image_model_capabilities'][ $canonical ] ?? null ) ? $config['capabilities']['image_model_capabilities'][ $canonical ] : array();
		$mapped_alias = '' !== $alias && is_array( $config['capabilities']['image_model_capabilities'][ $alias ] ?? null ) ? $config['capabilities']['image_model_capabilities'][ $alias ] : array();
		alorbach_direct_azure_require( $caps === $mapped_canonical && $caps === $mapped_alias, 'Exact GPT Image 2.5 model IDs and Gateway aliases must resolve to the same capability contract.' );
		$accepted = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'xhigh', 'png', 1, 'transparent' );
		alorbach_direct_azure_require( is_array( $accepted ) && 'xhigh' === ( $accepted['quality'] ?? '' ) && 'transparent' === ( $accepted['background'] ?? '' ), 'Direct GPT Image 2.5 must accept xhigh with a transparent background.' );
		$standalone = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'high', 'png', 1, 'auto', 0 );
		$one_persona = Integration_Service::validate_direct_image_request( (int) $admin->ID, $alias, '1024x1024', 'high', 'png', 1, 'auto', 1 );
		$one_environment = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'high', 'png', 1, 'auto', 1 );
		$two_refs = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'high', 'png', 1, 'auto', 2 );
		alorbach_direct_azure_require( is_array( $standalone ) && is_array( $one_persona ) && is_array( $one_environment ) && is_array( $two_refs ), 'GPT Image 2.5 must accept standalone generation and persona/environment reference counts independently of quality high.' );
		$too_many = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'high', 'png', 1, 'auto', 17 );
		$bad_quality = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'ultra', 'png', 1, 'auto', 2 );
		$bad_size = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '512x512', 'high', 'png', 1 );
		$bad_aspect = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'high', 'png', 1, 'auto', 0, '21:9' );
		$bad_format = Integration_Service::validate_direct_image_request( (int) $admin->ID, $canonical, '1024x1024', 'high', 'webp', 1 );
		alorbach_direct_azure_require( is_wp_error( $too_many ) && 'direct_image_reference_limit_exceeded' === $too_many->get_error_code(), 'GPT Image 2.5 must reject more than 16 reference images.' );
		alorbach_direct_azure_require( is_wp_error( $bad_quality ) && 'direct_image_quality_unsupported' === $bad_quality->get_error_code(), 'Unsupported quality must be reported independently from reference-image support.' );
		alorbach_direct_azure_require( is_wp_error( $bad_size ) && 'direct_image_size_unsupported' === $bad_size->get_error_code(), 'Unsupported size must be a distinct validation error.' );
		alorbach_direct_azure_require( is_wp_error( $bad_aspect ) && 'direct_image_aspect_ratio_unsupported' === $bad_aspect->get_error_code(), 'Unsupported aspect ratio must be a distinct validation error.' );
		alorbach_direct_azure_require( is_wp_error( $bad_format ) && 'direct_image_output_format_unsupported' === $bad_format->get_error_code(), 'Unsupported output format must be a distinct validation error.' );
	}

	echo wp_json_encode( array( 'verified' => true, 'model' => $model_key, 'transport' => 'direct_image', 'cost_uc' => $estimate_cost_uc, 'paid_provider_calls' => 0, 'gpt_image_2_5_contracts' => count( $two_five_contracts ) ) ) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Direct Azure capability verification failed: ' . $error->getMessage() . PHP_EOL );
	exit( 1 );
}
