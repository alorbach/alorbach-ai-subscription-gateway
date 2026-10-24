<?php
/** Offline tests for provider-aware image request option serialization. */

use Alorbach\AIGateway\API_Client;
use Alorbach\AIGateway\Providers\Azure_Provider;
use Alorbach\AIGateway\Providers\OpenAI_Provider;

define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function __( $text, $domain = '' ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/Providers/Provider_Interface.php';
require_once dirname( __DIR__ ) . '/includes/Providers/Provider_Base.php';
require_once dirname( __DIR__ ) . '/includes/Providers/Azure_Provider.php';
require_once dirname( __DIR__ ) . '/includes/Providers/OpenAI_Provider.php';
require_once dirname( __DIR__ ) . '/includes/class-api-client.php';

function option_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$apply_options = new ReflectionMethod( API_Client::class, 'apply_image_request_options' );
$apply_options->setAccessible( true );
$options = array(
	'aspect_ratio' => '2:3',
	'provider_options' => array( 'aspect_ratio' => '1:1' ),
);
$credentials = array(
	'endpoint' => 'https://newai-delta-eu.services.ai.azure.com',
	'api_key' => 'fixture-key',
);
$azure = new Azure_Provider();
$azure_model = 'gpt-image-2.5-sunburst';
$azure_request = $azure->build_images_request( 'fixture prompt', '2208x3312', 1, $azure_model, 'max', 'png', $credentials );
$azure_wire = $apply_options->invoke( null, $azure_request, $options, $azure, $azure_model );
option_check( '{"prompt":"fixture prompt","n":1,"size":"2208x3312","model":"gpt-image-2.5-sunburst","quality":"max","output_format":"png"}' === $azure_wire['body'], 'Azure OpenAI-compatible request keeps exact size and max quality and omits aspect_ratio from the final wire body' );
option_check( '2:3' === $options['aspect_ratio'], 'wire filtering does not mutate the internal aspect-ratio option used for provenance' );

$openai = new OpenAI_Provider();
$openai_model = 'gpt-image-1';
$openai_request = $openai->build_images_request( 'fixture prompt', '2208x3312', 1, $openai_model, 'max', 'png', array( 'api_key' => 'fixture-key' ) );
$openai_wire = $apply_options->invoke( null, $openai_request, array( 'aspect_ratio' => '2:3', 'provider_options' => array( 'ASPECT_RATIO' => '1:1' ) ), $openai, $openai_model );
option_check( '{"model":"gpt-image-1","prompt":"fixture prompt","size":"2208x3312","n":1,"quality":"max","output_format":"png"}' === $openai_wire['body'], 'OpenAI Images drops both normalized and provider_options aspect-ratio spellings' );

$flux_model = 'FLUX.2-pro';
$flux_request = $azure->build_images_request( 'fixture prompt', '2208x3312', 1, $flux_model, 'max', 'png', $credentials );
$flux_wire = $apply_options->invoke( null, $flux_request, array( 'aspect_ratio' => '16:9' ), $azure, $flux_model );
$flux_body = json_decode( $flux_wire['body'], true );
option_check( false !== strpos( $flux_wire['url'], 'blackforestlabs/v1/flux-2-pro' ) && '16:9' === ( $flux_body['aspect_ratio'] ?? null ), 'Azure native FLUX route retains supported aspect_ratio' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-api-client.php' );
$stream_start = strpos( $source, 'public static function stream_images(' );
$stream_end = false === $stream_start ? false : strpos( $source, 'private static function ', $stream_start );
$stream_source = false === $stream_end ? '' : substr( $source, $stream_start, $stream_end - $stream_start );
option_check( false !== strpos( $stream_source, '$options, $prov, $model' ), 'streaming requests use the same provider-aware wire option filter' );

echo "PASS: provider-aware image request options offline regressions\n";
