<?php
/**
 * Local Codex CLI image provider.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

use Alorbach\AIGateway\Codex_Image_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Codex_Images_Provider
 */
class Codex_Images_Provider extends Provider_Base {

	/**
	 * Normalize quality for the local Codex bridge.
	 *
	 * Codex currently treats quality as a hint only. Keep low disabled because
	 * it degrades output noticeably, and default to high for this provider.
	 *
	 * @param string $quality Requested quality.
	 * @return string
	 */
	private function normalize_quality( $quality ) {
		$quality = strtolower( trim( (string) $quality ) );
		if ( 'medium' === $quality || 'high' === $quality ) {
			return $quality;
		}

		return 'high';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'codex_images';
	}

	/**
	 * This provider is image-only.
	 *
	 * @return bool
	 */
	public function supports_chat() {
		return false;
	}

	/**
	 * This provider does not support chat requests.
	 *
	 * @param array $body        Request body.
	 * @param array $credentials Credentials.
	 * @return \WP_Error
	 */
	public function build_chat_request( $body, $credentials ) {
		return new \WP_Error( 'unsupported_chat', __( 'Codex Images (Local Codex CLI) does not support chat requests.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * This provider supports images via the local Codex CLI bridge.
	 *
	 * @return bool
	 */
	public function supports_images() {
		return true;
	}

	/**
	 * This provider is image-only.
	 *
	 * @return bool
	 */
	public function supports_audio() {
		return false;
	}

	/**
	 * This provider is image-only.
	 *
	 * @return bool
	 */
	public function supports_video() {
		return false;
	}

	/**
	 * Keep the existing synchronous image flow for this path.
	 *
	 * @param string $model Model ID.
	 * @return array{async_jobs: bool, provider_progress: bool, preview_images: bool}
	 */
	public function get_image_job_capabilities( $model = '' ) {
		return array(
			'async_jobs'        => false,
			'provider_progress' => false,
			'preview_images'    => false,
		);
	}

	/**
	 * Build a local bridge image request.
	 *
	 * @param string $prompt           Prompt.
	 * @param string $size             Size.
	 * @param int    $n                Number of images.
	 * @param string $model            Model ID.
	 * @param string $quality          Quality.
	 * @param string $output_format    Output format.
	 * @param array  $credentials      Credentials.
	 * @param array  $reference_images Optional reference images.
	 * @return array|\WP_Error
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		$reference_images = is_array( $reference_images ) ? array_values( array_filter( $reference_images, 'is_array' ) ) : array();
		if ( ! empty( $reference_images ) ) {
			return new \WP_Error( 'codex_image_bridge_reference_unsupported', __( 'Reference-image edits are not supported by the local Codex CLI bridge yet.', 'alorbach-ai-gateway' ) );
		}

		return array(
			'transport' => 'local_codex_cli',
			'payload'   => array(
				'prompt'           => (string) $prompt,
				'size'             => (string) $size,
				'n'                => (int) $n,
				'model'            => (string) $model,
				'quality'          => $this->normalize_quality( $quality ),
				'output_format'    => (string) $output_format,
				'reference_images' => $reference_images,
			),
		);
	}

	/**
	 * Verify that the local Codex CLI bridge is available.
	 *
	 * @param array $credentials Credentials.
	 * @return array{success: bool, message?: string}
	 */
	public function verify_key( $credentials ) {
		return Codex_Image_Bridge::verify_runtime();
	}

	/**
	 * Expose a fixed local model entry for import and routing.
	 *
	 * @param array $credentials Credentials.
	 * @return array
	 */
	public function fetch_models( $credentials ) {
		return array(
			array(
				'id'           => 'codex-image-local',
				'provider'     => 'codex_images',
				'type'         => 'image',
				'capabilities' => array( 'text_to_image' ),
			),
		);
	}
}
