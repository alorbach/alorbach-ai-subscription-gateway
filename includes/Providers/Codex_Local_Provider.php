<?php
/**
 * User-owned local Codex provider metadata.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

use Alorbach\AIGateway\Local_Codex_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Codex_Local_Provider
 */
class Codex_Local_Provider extends Provider_Base {

	public function get_type() {
		return 'codex_local';
	}

	public function supports_chat() {
		return true;
	}

	public function supports_images() {
		return true;
	}

	public function supports_audio() {
		return false;
	}

	public function supports_video() {
		return false;
	}

	public function build_chat_request( $body, $credentials ) {
		return new \WP_Error( 'browser_local_required', __( 'Local Codex requests must be executed through the user browser and tray bridge.', 'alorbach-ai-gateway' ) );
	}

	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		return new \WP_Error( 'browser_local_required', __( 'Local Codex image requests must be executed through the user browser and tray bridge.', 'alorbach-ai-gateway' ) );
	}

	public function verify_key( $credentials ) {
		return array(
			'success' => Local_Codex_Bridge::is_enabled(),
			'message' => Local_Codex_Bridge::is_enabled()
				? __( 'Local Codex tray bridge is enabled for browser-mediated users.', 'alorbach-ai-gateway' )
				: __( 'Local Codex tray bridge is disabled.', 'alorbach-ai-gateway' ),
		);
	}

	public function fetch_models( $credentials ) {
		return array(
			array(
				'id'           => 'codex-local:auto',
				'provider'     => 'codex_local',
				'type'         => 'text',
				'capabilities' => array( 'reasoning' ),
			),
			array(
				'id'           => Local_Codex_Bridge::MODEL_IMAGE,
				'provider'     => 'codex_local',
				'type'         => 'image',
				'capabilities' => array( 'text_to_image' ),
			),
		);
	}
}
