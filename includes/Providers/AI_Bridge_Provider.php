<?php
/**
 * User-owned AI Model Relay provider metadata.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

use Alorbach\AIGateway\AI_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Bridge_Provider
 */
class AI_Bridge_Provider extends Provider_Base {

	public function get_type() {
		return 'ai_bridge';
	}

	public function supports_chat() {
		return true;
	}

	public function supports_images() {
		return true;
	}

	public function supports_audio() {
		return true;
	}

	public function supports_video() {
		return true;
	}

	public function build_chat_request( $body, $credentials ) {
		return new \WP_Error( 'browser_local_required', __( 'AI Model Relay requests must be executed through the user browser and tray app.', 'alorbach-ai-gateway' ) );
	}

	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		return new \WP_Error( 'browser_local_required', __( 'AI Model Relay image requests must be executed through the user browser and tray app.', 'alorbach-ai-gateway' ) );
	}

	public function build_transcribe_request( $file_path, $model, $prompt, $credentials, $format = null, $options = array() ) {
		return new \WP_Error( 'browser_local_required', __( 'AI Model Relay transcription requests must be executed through the user browser and tray app.', 'alorbach-ai-gateway' ) );
	}

	public function build_video_request( $prompt, $model, $size, $duration_seconds, $credentials, $input_reference = array() ) {
		return new \WP_Error( 'browser_local_required', __( 'AI Model Relay video requests must be executed through the user browser and tray app.', 'alorbach-ai-gateway' ) );
	}

	public function verify_key( $credentials ) {
		return array(
			'success' => AI_Bridge::is_enabled(),
			'message' => AI_Bridge::is_enabled()
				? __( 'AI Model Relay is enabled for browser-mediated users.', 'alorbach-ai-gateway' )
				: __( 'AI Model Relay is disabled.', 'alorbach-ai-gateway' ),
		);
	}

	public function fetch_models( $credentials ) {
		// The paired browser owns the relay connection and live catalog. The
		// admin importer hydrates this entry from /v1/relay/models instead of
		// exposing a server-side or hardcoded fallback catalog.
		return array();
	}
}
