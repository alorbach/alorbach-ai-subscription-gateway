<?php
/**
 * Offline tests for image contract 1.1.0 (no WordPress, no database, no provider call).
 *
 * Covers: prompt preservation, mask validation, OpenAI/Azure mask multipart
 * golden bodies, entry-bound dispatch (credential routing), direct_image
 * capability publication, vision part pass-through (OpenAI, Azure, Gemini
 * mapping), the chat token estimate for image parts, and the async status
 * projection. Database-backed behavior (async lifecycle, idempotent replay, no
 * double charge) is covered by tests/image-async-integration-test.php.
 *
 * Run: php tests/image-contract-test.php
 */

namespace Alorbach\AIGateway {
	/** Entry store stub driven by $GLOBALS['fx_entries'] (id => entry). */
	class API_Keys_Helper {
		public static function get_entries() {
			$out = array();
			foreach ( $GLOBALS['fx_entries'] ?? array() as $id => $entry ) {
				$out[] = array_merge( array( 'id' => (string) $id ), $entry );
			}
			return $out;
		}
		public static function get_entry_by_id( $id ) {
			foreach ( self::get_entries() as $entry ) {
				if ( $entry['id'] === (string) $id ) {
					return $entry;
				}
			}
			return null;
		}
		public static function has_provider( $type ) {
			foreach ( self::get_entries() as $entry ) {
				if ( ( $entry['type'] ?? '' ) === $type && ! empty( $entry['enabled'] ) && ! empty( $entry['api_key'] ) ) {
					return true;
				}
			}
			return false;
		}
		public static function get_credentials_for_entry( $id ) {
			$entry = self::get_entry_by_id( $id );
			return is_array( $entry ) ? array( 'api_key' => (string) ( $entry['api_key'] ?? '' ), 'endpoint' => (string) ( $entry['endpoint'] ?? '' ), 'free_pass_through' => false ) : null;
		}
		public static function get_credentials_for_provider( $type ) {
			foreach ( self::get_entries() as $entry ) {
				if ( ( $entry['type'] ?? '' ) === $type && ! empty( $entry['enabled'] ) ) {
					return self::get_credentials_for_entry( $entry['id'] );
				}
			}
			return null;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['fx_options'] = array();
	$GLOBALS['fx_filters'] = array();
	$GLOBALS['fx_http']    = array();

	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $text, $domain = '' ) { return $text; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function get_option( $name, $default = false ) { return $GLOBALS['fx_options'][ $name ] ?? $default; }
	function apply_filters( $hook, $value ) {
		$callback = $GLOBALS['fx_filters'][ $hook ] ?? null;
		return $callback ? $callback( $value ) : $value;
	}
	function wp_generate_password( $length = 12, $special = true, $extra = false ) { return 'BOUNDARY'; }
	function wp_remote_post( $url, $args ) {
		$GLOBALS['fx_http'][] = array( 'url' => $url, 'args' => $args );
		return array( 'code' => 200, 'body' => '{"data":[{"b64_json":"aW1hZ2U="}]}', 'headers' => array( 'content-type' => 'application/json' ) );
	}
	function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ strtolower( $name ) ] ?? ''; }
	function wp_remote_retrieve_headers( $response ) { return $response['headers']; }

	require_once dirname( __DIR__ ) . '/includes/class-cost-matrix.php';
	require_once dirname( __DIR__ ) . '/includes/Providers/Provider_Interface.php';
	require_once dirname( __DIR__ ) . '/includes/Providers/Provider_Base.php';
	require_once dirname( __DIR__ ) . '/includes/Providers/Azure_Provider.php';
	require_once dirname( __DIR__ ) . '/includes/Providers/OpenAI_Provider.php';
	require_once dirname( __DIR__ ) . '/includes/Providers/Google_Provider.php';
	require_once dirname( __DIR__ ) . '/includes/Providers/Provider_Registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-image-error-diagnostics.php';
	require_once dirname( __DIR__ ) . '/includes/class-image-attempts.php';
	require_once dirname( __DIR__ ) . '/includes/class-image-validation.php';
	require_once dirname( __DIR__ ) . '/includes/class-image-attempt-worker.php';
	require_once dirname( __DIR__ ) . '/includes/class-api-client.php';
	require_once dirname( __DIR__ ) . '/includes/class-integration-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-tokenizer.php';
	require_once dirname( __DIR__ ) . '/includes/class-rest-proxy.php';

	use Alorbach\AIGateway\API_Client;
	use Alorbach\AIGateway\Image_Attempt_Worker;
	use Alorbach\AIGateway\Image_Attempts;
	use Alorbach\AIGateway\Image_Validation;
	use Alorbach\AIGateway\Integration_Service;
	use Alorbach\AIGateway\Providers\Azure_Provider;
	use Alorbach\AIGateway\Providers\Google_Provider;
	use Alorbach\AIGateway\Providers\OpenAI_Provider;
	use Alorbach\AIGateway\Providers\Provider_Registry;
	use Alorbach\AIGateway\REST_Proxy;
	use Alorbach\AIGateway\Tokenizer;

	$failures = 0;
	function t( $condition, $message ) {
		global $failures;
		if ( $condition ) {
			fwrite( STDOUT, "PASS $message\n" );
			return;
		}
		$failures++;
		fwrite( STDOUT, "FAIL $message\n" );
	}
	function err_code( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : null; }

	/** Build a PNG with a correct IHDR (and optional tRNS) and a tiny IDAT; header fields are what the Gateway reads. */
	function make_png( $w, $h, $color_type, $with_trns = false ) {
		$chunk = static function ( $type, $data ) { return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) ); };
		$png   = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, $color_type, 0, 0, 0 ) );
		if ( $with_trns ) {
			$png .= $chunk( 'tRNS', "\x00\x80" );
		}
		return $png . $chunk( 'IDAT', gzcompress( "\x00\x00" ) ) . $chunk( 'IEND', '' );
	}
	function make_jpeg( $w, $h ) {
		return "\xFF\xD8\xFF\xC0" . pack( 'n', 17 ) . "\x08" . pack( 'nn', $h, $w ) . "\x03\x01\x11\x00\x02\x11\x01\x03\x11\x01" . "\xFF\xD9" . str_repeat( "\x00", 8 );
	}
	function ref( $binary, $mime = 'image/png' ) { return array( 'b64_json' => base64_encode( $binary ), 'mime_type' => $mime ); }
	function set_providers( array $providers ) {
		$property = new ReflectionProperty( Provider_Registry::class, 'providers' );
		$property->setAccessible( true );
		$property->setValue( null, $providers );
	}

	// ---------------------------------------------------------------- 1. Prompt preservation
	$prompt = "Scene 1:\n  A fox\r\n\tsleeps.\n\nÄÖÜ ß 日本語 😀";
	t( true === Image_Validation::validate_prompt( $prompt ), 'prompt: newlines, tabs, CRLF and Unicode are valid' );
	t( $prompt === REST_Proxy::passthrough_prompt( $prompt ), 'prompt: the REST sanitizer returns the text byte for byte (newlines survive)' );
	t( 'invalid_prompt' === err_code( Image_Validation::validate_prompt( '   ' ) ) && 'invalid_prompt' === err_code( Image_Validation::validate_prompt( array( 'x' ) ) ), 'prompt: empty or non-string is rejected' );
	t( 'invalid_prompt' === err_code( Image_Validation::validate_prompt( "a\x00b" ) ) && 'invalid_prompt' === err_code( Image_Validation::validate_prompt( "a\x1bb" ) ) && 'invalid_prompt' === err_code( Image_Validation::validate_prompt( "a\x7fb" ) ), 'prompt: control characters (NUL, ESC, DEL) are rejected' );
	t( 'invalid_prompt' === err_code( Image_Validation::validate_prompt( "bad \xC3\x28 utf8" ) ), 'prompt: invalid UTF-8 is rejected' );
	t( true === Image_Validation::validate_prompt( str_repeat( 'a', Image_Validation::MAX_PROMPT_CHARS ) ) && 'prompt_too_long' === err_code( Image_Validation::validate_prompt( str_repeat( 'a', Image_Validation::MAX_PROMPT_CHARS + 1 ) ) ), 'prompt: length limit is inclusive and enforced in characters' );
	$binding = array( 'model' => 'e::m', 'prompt' => "a\nb", 'size' => '1024x1024', 'n' => 1 );
	t( Image_Attempts::request_hash( 7, 'k1', $binding ) !== Image_Attempts::request_hash( 7, 'k1', array_merge( $binding, array( 'prompt' => 'a b' ) ) ), 'prompt: the request hash binds the exact text (newline differs from space)' );
	t( Image_Attempts::request_hash( 7, 'k1', $binding ) === Image_Attempts::request_hash( 7, 'k1', $binding ), 'prompt: the request hash is stable for an identical request' );
	$rest_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-proxy.php' );
	t( 1 === preg_match( "/'\\/images', array\\(.*?'prompt'\\s*=>\\s*array\\( 'required' => true, 'sanitize_callback' => array\\( __CLASS__, 'passthrough_prompt' \\)/s", $rest_source ), 'prompt: POST /images registers the pass-through sanitizer, not sanitize_text_field' );

	// ---------------------------------------------------------------- 2. Mask validation
	$base_image = make_png( 64, 48, 2 );
	$refs       = array( ref( $base_image ) );
	$mask_ok    = make_png( 64, 48, 6 );
	$valid      = Image_Validation::validate_mask( base64_encode( $mask_ok ), $refs );
	t( is_array( $valid ) && 64 === $valid['width'] && 48 === $valid['height'] && $mask_ok === $valid['binary'], 'mask: RGBA PNG of the same size is accepted' );
	t( is_array( Image_Validation::validate_mask( 'data:image/png;base64,' . base64_encode( $mask_ok ), $refs ) ), 'mask: a data URL is accepted' );
	t( is_array( Image_Validation::validate_mask( array( 'b64_json' => base64_encode( $mask_ok ) ), $refs ) ), 'mask: an object with b64_json is accepted' );
	t( is_array( Image_Validation::validate_mask( base64_encode( make_png( 64, 48, 4 ) ), $refs ) ), 'mask: grayscale+alpha PNG is accepted' );
	t( is_array( Image_Validation::validate_mask( base64_encode( make_png( 64, 48, 3, true ) ), $refs ) ), 'mask: palette PNG with tRNS is accepted' );
	t( 'mask_alpha_required' === err_code( Image_Validation::validate_mask( base64_encode( make_png( 64, 48, 2 ) ), $refs ) ), 'mask: RGB PNG without alpha is rejected (mask_alpha_required)' );
	t( 'mask_alpha_required' === err_code( Image_Validation::validate_mask( base64_encode( make_png( 64, 48, 3 ) ), $refs ) ), 'mask: palette PNG without tRNS is rejected' );
	t( 'mask_dimension_mismatch' === err_code( Image_Validation::validate_mask( base64_encode( make_png( 65, 48, 6 ) ), $refs ) ), 'mask: different width is rejected (mask_dimension_mismatch)' );
	t( 'mask_dimension_mismatch' === err_code( Image_Validation::validate_mask( base64_encode( make_png( 64, 49, 6 ) ), $refs ) ), 'mask: different height is rejected' );
	$mismatch = Image_Validation::validate_mask( base64_encode( make_png( 10, 20, 6 ) ), $refs );
	$mismatch_data = $mismatch->get_error_data();
	t( 10 === $mismatch_data['mask_width'] && 20 === $mismatch_data['mask_height'] && 64 === $mismatch_data['image_width'] && 48 === $mismatch_data['image_height'] && 422 === $mismatch_data['status'], 'mask: the dimension error reports both sizes with HTTP 422' );
	t( 'invalid_mask_image' === err_code( Image_Validation::validate_mask( base64_encode( make_jpeg( 64, 48 ) ), $refs ) ), 'mask: a JPEG mask is rejected (PNG only)' );
	t( 'invalid_mask_image' === err_code( Image_Validation::validate_mask( '!!!not base64!!!', $refs ) ) && 'invalid_mask_image' === err_code( Image_Validation::validate_mask( '', $refs ) ) && 'invalid_mask_image' === err_code( Image_Validation::validate_mask( base64_encode( 'plain text' ), $refs ) ), 'mask: invalid base64 or non-image data is rejected' );
	t( 'mask_requires_input_image' === err_code( Image_Validation::validate_mask( base64_encode( $mask_ok ), array() ) ), 'mask: without a reference image to edit it is rejected' );
	t( is_array( Image_Validation::validate_mask( base64_encode( make_png( 64, 48, 6 ) ), array( ref( make_jpeg( 64, 48 ), 'image/jpeg' ) ) ) ), 'mask: a JPEG input image of the same size works' );
	t( 'invalid_reference_image' === err_code( Image_Validation::validate_mask( base64_encode( $mask_ok ), array( ref( 'not an image' ) ) ) ), 'mask: an unreadable first reference image is rejected' );
	t( is_array( Image_Validation::validate_mask( base64_encode( $mask_ok ), array( ref( make_png( 64, 48, 2 ) ), ref( make_png( 10, 10, 2 ) ) ) ) ), 'mask: only the first reference image is the edit target (further references may differ in size)' );
	t( 'mask_too_large' === err_code( Image_Validation::validate_mask( str_repeat( 'A', 28000000 ), $refs ) ), 'mask: an oversized payload is rejected before decoding' );
	$inspect = Image_Validation::inspect_image( make_jpeg( 300, 200 ) );
	t( 'image/jpeg' === $inspect['mime'] && 300 === $inspect['width'] && 200 === $inspect['height'], 'inspect: JPEG dimensions are read from the SOF marker' );

	// ---------------------------------------------------------------- 3. Provider multipart golden bodies
	$azure_creds  = array( 'api_key' => 'KEY', 'endpoint' => 'https://r.services.ai.azure.com' );
	$mask_options = array( 'mask' => array( 'binary' => 'MASKBYTES', 'mime_type' => 'image/png' ) );
	$one_ref      = array( array( 'b64_json' => base64_encode( 'IMGBYTES' ), 'mime_type' => 'image/png' ) );
	$azure        = new Azure_Provider();
	$openai       = new OpenAI_Provider();

	$golden_azure = "--BOUNDARY\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\ngpt-image-2.5-x\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"prompt\"\r\n\r\nline1\nline2\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"n\"\r\n\r\n1\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"size\"\r\n\r\n1024x1024\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"quality\"\r\n\r\nhigh\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"output_format\"\r\n\r\npng\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"image[]\"; filename=\"reference-1.png\"\r\nContent-Type: image/png\r\n\r\nIMGBYTES\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"mask\"; filename=\"mask.png\"\r\nContent-Type: image/png\r\n\r\nMASKBYTES\r\n"
		. "--BOUNDARY--\r\n";
	$azure_request = $azure->build_images_request( "line1\nline2", '1024x1024', 1, 'gpt-image-2.5-x', 'high', 'png', $azure_creds, $one_ref, '', $mask_options );
	t( is_array( $azure_request ) && $golden_azure === $azure_request['body'], 'azure mask: images/edits multipart body matches the golden (prompt newline, image[], mask part last)' );
	t( 'https://r.services.ai.azure.com/openai/v1/images/edits' === $azure_request['url'] && 'multipart/form-data; boundary=BOUNDARY' === $azure_request['headers']['Content-Type'], 'azure mask: Foundry edits URL and multipart content type' );
	$azure_deploy = $azure->build_images_request( 'p', '1024x1024', 1, 'gpt-image-2.5-x', 'high', 'png', array( 'api_key' => 'KEY', 'endpoint' => 'https://r.openai.azure.com' ), $one_ref, '', $mask_options );
	t( false !== strpos( $azure_deploy['url'], '/openai/deployments/gpt-image-2.5-x/images/edits?api-version=' ) && false !== strpos( $azure_deploy['body'], 'name="mask"; filename="mask.png"' ), 'azure mask: deployment endpoint route carries the mask part too' );
	$azure_plain = $azure->build_images_request( 'p', '1024x1024', 1, 'gpt-image-2.5-x', 'high', 'png', $azure_creds, $one_ref );
	t( false === strpos( $azure_plain['body'], 'name="mask"' ), 'azure: an edit without a mask carries no mask part (unchanged)' );
	t( 'mask_requires_input_image' === err_code( $azure->build_images_request( 'p', '1024x1024', 1, 'gpt-image-2.5-x', 'high', 'png', $azure_creds, array(), '', $mask_options ) ), 'azure mask: without an input image the builder refuses' );
	t( 'mask_requires_input_image' === err_code( $azure->build_images_request( 'p', '1024x1024', 1, 'FLUX.2-pro', 'high', 'png', $azure_creds, $one_ref, '', $mask_options ) ), 'azure mask: non GPT-Image models refuse a mask' );

	$golden_openai = "--BOUNDARY\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\ngpt-image-1\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"prompt\"\r\n\r\nfix it\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"size\"\r\n\r\n1024x1024\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"n\"\r\n\r\n1\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"quality\"\r\n\r\nmedium\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"output_format\"\r\n\r\npng\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"image\"; filename=\"reference-1.png\"\r\nContent-Type: image/png\r\n\r\nIMGBYTES\r\n"
		. "--BOUNDARY\r\nContent-Disposition: form-data; name=\"mask\"; filename=\"mask.png\"\r\nContent-Type: image/png\r\n\r\nMASKBYTES\r\n"
		. "--BOUNDARY--\r\n";
	$openai_request = $openai->build_images_request( 'fix it', '1024x1024', 1, 'gpt-image-1', 'medium', 'png', array( 'api_key' => 'OKEY' ), $one_ref, '', $mask_options );
	t( $golden_openai === $openai_request['body'] && 'https://api.openai.com/v1/images/edits' === $openai_request['url'], 'openai mask: images/edits multipart body matches the golden' );
	t( true === $azure->supports_image_mask( 'gpt-image-2.5-x' ) && false === $azure->supports_image_mask( 'FLUX.2-pro' ) && true === $openai->supports_image_mask( 'gpt-image-1' ) && false === $openai->supports_image_mask( 'dall-e-3' ), 'mask capability: GPT Image models on OpenAI and Azure only' );
	t( false === ( new Google_Provider() )->supports_image_mask( 'imagen-4' ), 'mask capability: providers without mask support default to false' );

	// ---------------------------------------------------------------- 4. Entry-bound dispatch (credential routing) and mask through API_Client
	$entry_azure = '11111111-1111-4111-8111-111111111111';
	$GLOBALS['fx_entries'] = array(
		$entry_azure => array( 'type' => 'azure', 'enabled' => true, 'api_key' => 'AZURE-SECRET', 'endpoint' => 'https://r.services.ai.azure.com' ),
		'openai-1'   => array( 'type' => 'openai', 'enabled' => true, 'api_key' => 'OPENAI-SECRET', 'endpoint' => '' ),
		'azure-off'  => array( 'type' => 'azure', 'enabled' => false, 'api_key' => 'OFF-SECRET', 'endpoint' => 'https://off.example' ),
	);
	$GLOBALS['fx_options']['alorbach_cost_matrix'] = array( 'models' => array() );
	set_providers( array( 'azure' => $azure, 'openai' => $openai ) );
	$key = $entry_azure . '::gpt-image-2.5-x';

	$dispatch = API_Client::resolve_image_dispatch( $key, array() );
	t( array( 'provider' => 'azure', 'entry_id' => $entry_azure, 'model' => 'gpt-image-2.5-x', 'entry_bound' => true ) === $dispatch, 'dispatch: an entry-bound key resolves to its own entry and provider type' );
	t( $dispatch === API_Client::resolve_image_dispatch( $key, $one_ref ), 'dispatch: reference images never reroute an entry-bound key (Foundry to OpenAI reroute removed)' );
	t( 'image_dispatch_entry_unavailable' === err_code( API_Client::resolve_image_dispatch( 'azure-off::gpt-image-2.5-x' ) ) && 'image_dispatch_entry_unavailable' === err_code( API_Client::resolve_image_dispatch( 'nope::gpt-image-2.5-x' ) ), 'dispatch: a disabled or missing entry is a refusal, not a fallback' );
	$GLOBALS['fx_http'] = array();
	t( 'image_dispatch_entry_unavailable' === err_code( API_Client::stream_images( 'p', '1024x1024', 1, 'azure-off::gpt-image-2.5-x', 'high', 'png' ) ) && 'image_dispatch_entry_unavailable' === err_code( API_Client::stream_images( 'p', '1024x1024', 1, 'nope::gpt-image-2.5-x', 'high', 'png' ) ) && array() === $GLOBALS['fx_http'], 'dispatch: the stream path also refuses a disabled or missing entry (its key is never used)' );
	$legacy = API_Client::resolve_image_dispatch( 'gpt-image-1', array() );
	t( 'openai' === $legacy['provider'] && '' === $legacy['entry_id'] && false === $legacy['entry_bound'], 'dispatch: a plain model name keeps the legacy resolution' );

	$GLOBALS['fx_http'] = array();
	$response = API_Client::images( "keep\nnewline", '1024x1024', 1, $key, 'high', 'png', array(), array() );
	$sent     = $GLOBALS['fx_http'][0] ?? array();
	t( is_array( $response ) && 1 === count( $GLOBALS['fx_http'] ), 'dispatch: one provider request is made' );
	t( 0 === strpos( $sent['url'] ?? '', 'https://r.services.ai.azure.com/' ) && 'AZURE-SECRET' === ( $sent['args']['headers']['api-key'] ?? '' ), 'dispatch: the Azure entry key goes only to the Azure endpoint although an OpenAI provider is configured' );
	t( false === strpos( (string) json_encode( $sent ), 'OPENAI-SECRET' ) && false === strpos( (string) json_encode( $sent ), 'api.openai.com' ), 'dispatch: no OpenAI endpoint or key is involved for an Azure entry key' );
	t( false !== strpos( (string) ( $sent['args']['body'] ?? '' ), '"prompt":"keep\nnewline"' ), 'dispatch: the prompt newline reaches the provider JSON body' );

	// Plain model name + reference images + Foundry Azure entry + OpenAI key: the Foundry reroute goes to OpenAI,
	// and the Azure entry's credentials must never travel with it.
	$GLOBALS['fx_options']['alorbach_cost_matrix'] = array( 'models' => array( array( 'model' => 'gpt-image-foundry-plain', 'entry_id' => $entry_azure ) ) );
	$plain_dispatch = API_Client::resolve_image_dispatch( 'gpt-image-foundry-plain', $one_ref );
	t( 'openai' === $plain_dispatch['provider'] && false === $plain_dispatch['entry_bound'], 'plain name: a Foundry endpoint with reference images is rerouted to OpenAI (legacy resolution)' );
	$GLOBALS['fx_http'] = array();
	$plain_response = API_Client::images( 'edit', '1024x1024', 1, 'gpt-image-foundry-plain', 'high', 'png', array( ref( $base_image ) ), array() );
	$plain_sent     = $GLOBALS['fx_http'][0] ?? array();
	t( is_array( $plain_response ) && 1 === count( $GLOBALS['fx_http'] ) && 0 === strpos( $plain_sent['url'] ?? '', 'https://api.openai.com/' ), 'plain name: the rerouted request goes to the OpenAI endpoint' );
	t( false === strpos( (string) json_encode( $plain_sent ), 'AZURE-SECRET' ), 'plain name: the Azure entry key never reaches an OpenAI request (no credential cross-over)' );
	t( 'Bearer OPENAI-SECRET' === ( $plain_sent['args']['headers']['Authorization'] ?? '' ), 'plain name: the rerouted request carries the OpenAI provider credentials' );
	$GLOBALS['fx_http'] = array();
	API_Client::images( 'text only', '1024x1024', 1, 'gpt-image-foundry-plain', 'high', 'png', array(), array() );
	$plain_text_sent = $GLOBALS['fx_http'][0] ?? array();
	t( 0 === strpos( $plain_text_sent['url'] ?? '', 'https://r.services.ai.azure.com/' ) && 'AZURE-SECRET' === ( $plain_text_sent['args']['headers']['api-key'] ?? '' ), 'plain name: without references the request stays on the imported Azure entry and its own credentials' );
	$GLOBALS['fx_options']['alorbach_cost_matrix'] = array( 'models' => array() );

	$GLOBALS['fx_http'] = array();
	$mask_b64 = base64_encode( $mask_ok );
	$response = API_Client::images( 'edit', '1024x1024', 1, $key, 'high', 'png', array( ref( $base_image ) ), array( 'mask_image' => $mask_b64 ) );
	$sent     = $GLOBALS['fx_http'][0] ?? array();
	t( is_array( $response ) && false !== strpos( $sent['url'], '/openai/v1/images/edits' ) && false !== strpos( $sent['args']['body'], "name=\"mask\"; filename=\"mask.png\"\r\nContent-Type: image/png\r\n\r\n" . $mask_ok ), 'mask: API_Client forwards the decoded mask PNG as the multipart mask part on the Azure edits route' );
	t( 'AZURE-SECRET' === $sent['args']['headers']['api-key'], 'mask: the edit goes out on the entry-bound Azure credentials' );

	$GLOBALS['fx_http'] = array();
	$bare_provider = new class() extends \Alorbach\AIGateway\Providers\Provider_Base {
		public function get_type() { return 'barefake'; }
		public function supports_chat() { return false; }
		public function supports_images() { return true; }
		public function build_chat_request( $body, $credentials ) { return null; }
		public function verify_key( $credentials ) { return array( 'success' => true ); }
		public function fetch_models( $credentials ) { return array(); }
		public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array(), $background = '' ) {
			$GLOBALS['bare_args'] = func_get_args();
			return array( 'url' => 'https://bare.example/images', 'headers' => array(), 'body' => '{}' );
		}
	};
	$GLOBALS['fx_entries']['bare-1'] = array( 'type' => 'barefake', 'enabled' => true, 'api_key' => 'BARE', 'endpoint' => '' );
	set_providers( array( 'azure' => $azure, 'openai' => $openai, 'barefake' => $bare_provider ) );
	$blocked = API_Client::images( 'edit', '1024x1024', 1, 'bare-1::some-model', 'high', 'png', array( ref( $base_image ) ), array( 'mask_image' => $mask_b64 ) );
	t( 'mask_unsupported' === err_code( $blocked ) && array() === $GLOBALS['fx_http'], 'mask: a provider path without mask support refuses before any request is sent' );
	$GLOBALS['fx_http'] = array();
	API_Client::images( 'plain', '1024x1024', 1, 'bare-1::some-model', 'high', 'png', array(), array() );
	t( 9 === count( $GLOBALS['bare_args'] ) , 'compat: a nine-argument custom provider is still called with nine arguments' );
	t( 'invalid_mask_image' === err_code( API_Client::images( 'edit', '1024x1024', 1, $key, 'high', 'png', array( ref( $base_image ) ), array( 'mask_image' => '%%%' ) ) ), 'mask: an undecodable mask is refused before dispatch' );
	t( 'local_configuration' === Image_Attempts::classify_error( 'mask_unsupported' )['failure_class'], 'evidence: mask_unsupported is a conclusive not-sent rejection' );
	t( 'not_sent' === Image_Attempts::classify_error( 'image_dispatch_entry_unavailable' )['dispatch_status'] && 'not_sent' === Image_Attempts::classify_error( 'mask_requires_input_image' )['dispatch_status'], 'evidence: the new local refusals are conclusive not-sent rejections' );

	// ---------------------------------------------------------------- 5. direct_image capability publication
	$price_row = static function ( array $qualities, array $sizes ) {
		$row = array();
		foreach ( $qualities as $quality ) {
			foreach ( $sizes as $size ) {
				$row[ $quality ][ $size ] = 100000;
			}
		}
		return $row;
	};
	$GLOBALS['fx_options']['alorbach_image_model_costs'] = array(
		'gpt-image-2.5-sunburst' => $price_row( array( 'low', 'medium', 'high', 'xhigh', 'max' ), array( '1024x1024' ) ),
		'gpt-image-2'            => $price_row( array( 'low', 'medium', 'high' ), array( '1024x1024', '1024x1536', '1536x1024', '2048x2048', '2048x1152', '3840x2160', '2160x3840' ) ),
	);
	$publish = new ReflectionMethod( Integration_Service::class, 'get_direct_image_model_capabilities' );
	$publish->setAccessible( true );
	$k25   = $entry_azure . '::gpt-image-2.5-sunburst';
	$k2    = $entry_azure . '::gpt-image-2';
	$kmiss = $entry_azure . '::gpt-image-2.5-flare';
	$kdis  = 'azure-off::gpt-image-2.5-sunburst';
	$contracts = $publish->invoke( null, array( $k25 => 'Sunburst', $k2 => 'GPT Image 2', $kmiss => 'Flare (no prices)', $kdis => 'Disabled entry' ) );
	$by_key = array();
	foreach ( $contracts as $contract ) {
		$by_key[ $contract['gateway_model_key'] ] = $contract;
	}
	$c25 = $by_key[ $k25 ];
	t( '1.1.0' === $c25['image_contract_version'] && 'direct_image' === $c25['transport'] && true === $c25['eligible'] && null === $c25['disabled_reason'], 'contract: GPT Image 2.5 publishes image_contract_version 1.1.0 and is eligible' );
	t( true === $c25['server_dispatch_evidenced'] && true === $c25['dispatch_entry_exclusive'] && true === $c25['direct_dispatch_evidenced'] && true === $c25['async_supported'], 'contract: dispatch evidence flags (server_dispatch_evidenced, dispatch_entry_exclusive, async_supported)' );
	t( array( 'text_to_image', 'image_edit', 'image_inpaint' ) === $c25['operation_kinds'] && true === $c25['mask_supported'] && 16 === $c25['reference_images_max'], 'contract: 2.5 publishes text_to_image, image_edit and image_inpaint with 16 references' );
	t( array( 'low', 'medium', 'high', 'xhigh', 'max' ) === $c25['qualities'] && 1 === $c25['candidate_count_max'], 'contract: qualities and candidate_count_max' );
	$rules = $c25['size_rules'];
	t( 'arbitrary' === $rules['mode'] && 3840 === $rules['max_edge'] && 16 === $rules['min_edge'] && 655360 === $rules['min_pixels'] && 8294400 === $rules['max_pixels'] && 16 === $rules['multiple_of'] && array() === $rules['presets'], 'contract: arbitrary size_rules (max_edge, min_pixels, max_pixels, multiple_of)' );
	t( abs( $rules['aspect_min'] - 0.333333 ) < 0.000001 && abs( $rules['aspect_max'] - 3.0 ) < 0.000001, 'contract: aspect_min 1/3 and aspect_max 3 as width/height ratios' );
	t( null !== $c25['request_limits'] && Image_Validation::MAX_PROMPT_CHARS === $c25['request_limits']['max_prompt_chars'] && Image_Validation::MAX_MASK_BYTES === $c25['request_limits']['max_mask_bytes'] && array_key_exists( 'max_body_bytes', $c25['request_limits'] ), 'contract: request_limits publish prompt, mask and body limits' );
	$c2 = $by_key[ $k2 ];
	t( true === $c2['eligible'] && array( 'text_to_image' ) === $c2['operation_kinds'] && false === $c2['mask_supported'] && 0 === $c2['reference_images_max'], 'contract: GPT Image 2 stays text_to_image only without mask or references' );
	t( 'preset' === $c2['size_rules']['mode'] && array( '1024x1024', '1024x1536', '1536x1024', '2048x2048', '2048x1152', '3840x2160', '2160x3840' ) === $c2['size_rules']['presets'] && 3840 === $c2['size_rules']['max_edge'] && 1048576 === $c2['size_rules']['min_pixels'] && 8294400 === $c2['size_rules']['max_pixels'] && 16 === $c2['size_rules']['multiple_of'], 'contract: GPT Image 2 preset size_rules list the presets and derive the bounds' );
	t( 1024 === $c2['size_rules']['min_edge'] && 16 === $c2['size_rules']['multiple_of'], 'contract: preset min_edge is the smallest preset edge (not the GCD)' );
	t( abs( $c2['size_rules']['aspect_min'] - round( 9 / 16, 6 ) ) < 0.000001 && abs( $c2['size_rules']['aspect_max'] - round( 16 / 9, 6 ) ) < 0.000001, 'contract: preset aspect bounds come from the presets' );
	$cm = $by_key[ $kmiss ];
	t( false === $cm['eligible'] && 'pricing_missing' === $cm['disabled_reason'] && array() === $cm['operation_kinds'] && null === $cm['size_rules'] && false === $cm['mask_supported'] && array() === $cm['image_capabilities'], 'contract: missing prices fail closed (no operations, no size rules, no mask)' );
	$cd = $by_key[ $kdis ];
	t( false === $cd['eligible'] && 'dispatch_credentials_missing' === $cd['disabled_reason'] && false === $cd['server_dispatch_evidenced'], 'contract: a disabled entry is not dispatch evidence' );
	$GLOBALS['fx_filters']['alorbach_direct_image_mask_supported'] = static function ( $value ) { return false; };
	$no_mask = $publish->invoke( null, array( $k25 => 'Sunburst' ) );
	t( false === $no_mask[0]['mask_supported'] && array( 'text_to_image', 'image_edit' ) === $no_mask[0]['operation_kinds'], 'contract: the mask_supported filter can withdraw inpainting' );
	unset( $GLOBALS['fx_filters']['alorbach_direct_image_mask_supported'] );
	set_providers( array( 'openai' => $openai ) );
	$no_provider = $publish->invoke( null, array( $k25 => 'Sunburst' ) );
	t( false === $no_provider[0]['eligible'] && false === $no_provider[0]['server_dispatch_evidenced'], 'contract: no registered Azure provider means no server dispatch evidence' );
	set_providers( array( 'azure' => $azure, 'openai' => $openai ) );
	$secret_scan = strtolower( (string) json_encode( $contracts ) );
	t( false === strpos( $secret_scan, 'azure-secret' ) && false === strpos( $secret_scan, 'r.services.ai.azure.com' ), 'contract: no credentials or endpoints appear in the published contracts' );

	// ---------------------------------------------------------------- 6. Vision: pass-through and Gemini mapping
	$data_url = 'data:image/png;base64,' . base64_encode( $mask_ok );
	$vision_messages = array(
		array( 'role' => 'system', 'content' => 'You inspect pictures.' ),
		array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'What is this?' ), array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url, 'detail' => 'low' ) ) ) ),
	);
	$openai_chat = $openai->build_chat_request( array( 'model' => 'gpt-4.1-mini', 'messages' => $vision_messages, 'max_tokens' => 50 ), array( 'api_key' => 'k' ) );
	$openai_body = json_decode( $openai_chat['body'], true );
	t( $vision_messages === $openai_body['messages'], 'vision: OpenAI chat passes the messages with image_url parts through unchanged' );
	$azure_chat = $azure->build_chat_request( array( 'model' => 'gpt-4.1-mini', 'messages' => $vision_messages, 'max_tokens' => 50 ), $azure_creds );
	$azure_body = json_decode( $azure_chat['body'], true );
	t( $vision_messages === $azure_body['messages'] && false !== strpos( $azure_chat['url'], '/chat/completions' ), 'vision: Azure chat passes the messages with image_url parts through unchanged' );
	t( true === $openai->supports_chat_image_parts( 'gpt-4.1-mini' ) && true === $azure->supports_chat_image_parts( 'gpt-4.1-mini' ) && true === ( new Google_Provider() )->supports_chat_image_parts( 'gemini-2.5-flash' ), 'vision: OpenAI, Azure and Google declare image-part support' );

	$google      = new Google_Provider();
	$google_chat = $google->build_chat_request( array( 'model' => 'gemini-2.5-flash', 'messages' => $vision_messages, 'max_tokens' => 50 ), array( 'api_key' => 'g' ) );
	$google_body = json_decode( $google_chat['body'], true );
	t( array( array( 'text' => 'You inspect pictures.' ) ) === $google_body['systemInstruction']['parts'], 'gemini: a string system message becomes one text part' );
	$parts = $google_body['contents'][0]['parts'];
	t( 'user' === $google_body['contents'][0]['role'] && 2 === count( $parts ) && array( 'text' => 'What is this?' ) === $parts[0], 'gemini: the text part is kept as a text part' );
	t( array( 'inline_data' => array( 'mime_type' => 'image/png', 'data' => base64_encode( $mask_ok ) ) ) === $parts[1], 'gemini: an image_url data URL becomes inline_data with mime type and base64 (not a stringified array)' );
	t( false === strpos( $google_chat['body'], 'image_url' ) && false === strpos( $google_chat['body'], '"Array"' ), 'gemini: no OpenAI part structure leaks into the Gemini body' );
	$google_text = json_decode( $google->build_chat_request( array( 'model' => 'gemini-2.5-flash', 'messages' => array( array( 'role' => 'user', 'content' => 'hello' ) ), 'max_tokens' => 5 ), array( 'api_key' => 'g' ) )['body'], true );
	t( array( array( 'text' => 'hello' ) ) === $google_text['contents'][0]['parts'], 'gemini: plain string content is unchanged' );
	$google_remote = $google->build_chat_request( array( 'model' => 'gemini-2.5-flash', 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image_url', 'image_url' => array( 'url' => 'https://example.com/a.png' ) ) ) ) ) ), array( 'api_key' => 'g' ) );
	t( 'unsupported_image_url' === err_code( $google_remote ), 'gemini: a remote image URL cannot be inlined and is refused' );
	$google_array_system = json_decode( $google->build_chat_request( array( 'model' => 'gemini-2.5-flash', 'messages' => array( array( 'role' => 'system', 'content' => array( array( 'type' => 'text', 'text' => 'a' ), array( 'type' => 'text', 'text' => 'b' ) ) ), array( 'role' => 'assistant', 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ) ), array( 'role' => 'user', 'content' => 'q' ) ) ), array( 'api_key' => 'g' ) )['body'], true );
	t( array( array( 'text' => 'a' ), array( 'text' => 'b' ) ) === $google_array_system['systemInstruction']['parts'] && 'model' === $google_array_system['contents'][0]['role'] && array( array( 'text' => 'ok' ) ) === $google_array_system['contents'][0]['parts'], 'gemini: array-form text content maps part by part for system and assistant roles' );
	t( 'unsupported_content_part' === err_code( $google->build_chat_request( array( 'model' => 'gemini-2.5-flash', 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'input_audio' ) ) ) ) ), array( 'api_key' => 'g' ) ) ), 'gemini: an unknown part type is refused instead of being stringified' );

	$redacted = REST_Proxy::redact_image_parts( $vision_messages );
	t( false === strpos( (string) json_encode( $redacted ), 'base64,' ) && false !== strpos( (string) json_encode( $redacted ), 'inline image' ) && $vision_messages[1]['content'][1]['image_url']['url'] === $data_url, 'vision: queue diagnostics redact image bytes without touching the request' );

	$text_only  = Tokenizer::count_messages_tokens( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'What is this?' ) ) ) ), 'gpt-4.1-mini' );
	$with_image = Tokenizer::count_messages_tokens( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'What is this?' ), array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:image/png;base64,' . str_repeat( 'QUJD', 400000 ) ) ) ) ) ), 'gpt-4.1-mini' );
	t( $with_image - $text_only >= Tokenizer::IMAGE_PART_TOKENS && $with_image - $text_only < Tokenizer::IMAGE_PART_TOKENS + 10, 'vision: an image part costs the flat estimate, not its base64 length in tokens' );

	// ---------------------------------------------------------------- 7. Async status projection
	$row = static function ( array $override = array() ) {
		return array_merge( array( 'attempt_id' => 5, 'owner_id' => 3, 'outcome' => 'unknown', 'dispatch_status' => 'not_sent', 'billing_status' => 'unresolved', 'cost_usd' => null, 'conflict_status' => 0, 'failure_class' => null, 'source' => 'gateway_attempt_v1', 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'client_request_id' => 'job-1', 'evidence_id' => 'e', 'request_hash' => str_repeat( 'a', 64 ), 'revision' => 1 ), $override );
	};
	$job = static function ( array $override = array() ) {
		return array_merge( array( 'attempt_id' => 5, 'status' => 'queued', 'error_code' => null, 'created_at' => '2026-11-13 10:00:00', 'started_at' => null, 'finished_at' => null ), $override );
	};
	$describe = static function ( array $row_in, $job_in ) {
		return Image_Attempt_Worker::describe( Image_Attempts::public_evidence( $row_in ), $row_in, $job_in );
	};
	$queued = $describe( $row(), $job() );
	t( 'queued' === $queued['status'] && 'async' === $queued['execution_mode'] && false === $queued['result_available'] && null === $queued['error'] && 2000 === $queued['poll_after_ms'] && 'job-1' === $queued['attempt_id'] && '2026-11-13T10:00:00Z' === $queued['queued_at'], 'status: a queued job reports queued with poll hint and ISO timestamp' );
	$running = $describe( $row( array( 'dispatch_status' => 'possibly_sent' ) ), $job( array( 'status' => 'running', 'started_at' => '2026-11-13 10:00:05' ) ) );
	t( 'running' === $running['status'] && '2026-11-13T10:00:05Z' === $running['started_at'], 'status: a leased job reports running' );
	$done = $describe( $row( array( 'dispatch_status' => 'possibly_sent', 'outcome' => 'succeeded', 'billing_status' => 'recorded', 'cost_usd' => '0.10000000' ) ), $job( array( 'status' => 'done', 'finished_at' => '2026-11-13 10:01:00' ) ) );
	t( 'succeeded' === $done['status'] && true === $done['result_available'] && null === $done['error'], 'status: settled billing reports succeeded with the result available' );
	$unbilled = $describe( $row( array( 'dispatch_status' => 'possibly_sent', 'outcome' => 'succeeded', 'billing_status' => 'pending' ) ), $job( array( 'status' => 'done', 'error_code' => 'image_billing_pending' ) ) );
	t( 'failed' === $unbilled['status'] && false === $unbilled['result_available'] && 'image_billing_pending' === $unbilled['error']['code'] && '' !== $unbilled['error']['message'], 'status: produced but unbilled bytes are failed, withheld and carry image_billing_pending' );
	$rejected = $describe( $row( array( 'outcome' => 'rejected', 'billing_status' => 'not_charged', 'cost_usd' => '0.00000000', 'provider_cost_usd' => '0.00000000', 'source' => 'gateway_preflight_v1' ) ), $job( array( 'status' => 'done', 'error_code' => 'insufficient_credits' ) ) );
	t( 'failed' === $rejected['status'] && 'insufficient_credits' === $rejected['error']['code'], 'status: a pre-dispatch rejection reports failed with the worker error code' );
	$lost = $describe( $row( array( 'dispatch_status' => 'possibly_sent' ) ), $job( array( 'status' => 'done', 'error_code' => 'worker_lost' ) ) );
	t( 'failed' === $lost['status'] && 'worker_lost' === $lost['error']['code'], 'status: a lost worker after handoff is failed with an unknown outcome and never retried' );
	$sync_running = $describe( $row( array( 'dispatch_status' => 'possibly_sent' ) ), null );
	t( 'running' === $sync_running['status'] && 'sync' === $sync_running['execution_mode'], 'status: a fresh synchronous attempt in flight reports running' );
	$sync_stale = $describe( $row( array( 'dispatch_status' => 'possibly_sent', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) ), null );
	t( 'failed' === $sync_stale['status'] && 'outcome_unknown' === $sync_stale['error']['code'], 'status: a stale synchronous attempt with no outcome is failed/outcome_unknown' );
	$sync_ok = $describe( $row( array( 'dispatch_status' => 'possibly_sent', 'outcome' => 'succeeded', 'billing_status' => 'recorded', 'cost_usd' => '0.05000000' ) ), null );
	t( 'succeeded' === $sync_ok['status'] && 'sync' === $sync_ok['execution_mode'], 'status: settled synchronous attempts report succeeded too' );
	$legacy_status = Image_Attempt_Worker::describe( array( 'client_request_id' => 'old', 'outcome' => 'unknown', 'billing_status' => 'recorded', 'conflict' => false ), null, null );
	t( 'unknown' === $legacy_status['status'] && 'legacy' === $legacy_status['execution_mode'], 'status: ledger-only legacy evidence reports unknown' );
	$conflict = Image_Attempt_Worker::describe( array_merge( Image_Attempts::public_evidence( $row( array( 'conflict_status' => 1, 'outcome' => 'succeeded' ) ) ) ), $row( array( 'conflict_status' => 1, 'outcome' => 'succeeded' ) ), $job( array( 'status' => 'done' ) ) );
	t( 'failed' === $conflict['status'] && 'attestation_conflict' === $conflict['error']['code'], 'status: an attestation conflict blocks the result' );

	if ( $failures > 0 ) {
		fwrite( STDOUT, "\n$failures check(s) failed.\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "\nAll image contract 1.1.0 offline checks passed.\n" );
}
