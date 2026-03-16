<?php
/**
 * Provider interface for AI API backends.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Provider_Interface
 */
interface Provider_Interface {

	/**
	 * Provider type identifier.
	 *
	 * @return string openai, azure, google, github_models.
	 */
	public function get_type();

	/**
	 * Whether this provider supports chat completions.
	 *
	 * @return bool
	 */
	public function supports_chat();

	/**
	 * Whether this provider supports image generation.
	 *
	 * @return bool
	 */
	public function supports_images();

	/**
	 * Whether this provider supports audio transcription.
	 *
	 * @return bool
	 */
	public function supports_audio();

	/**
	 * Whether this provider supports video generation.
	 *
	 * @return bool
	 */
	public function supports_video();

	/**
	 * Build chat completion request.
	 *
	 * @param array $body        Request body (model, messages, max_tokens, etc.).
	 * @param array $credentials Credentials from API_Keys_Helper.
	 * @return array{url: string, headers: array, body: string}|WP_Error
	 */
	public function build_chat_request( $body, $credentials );

	/**
	 * Build images generation request. Return null if not supported.
	 *
	 * @param string $prompt       Prompt.
	 * @param string $size         Size (e.g. 1024x1024).
	 * @param int    $n            Number of images.
	 * @param string $model        Model ID.
	 * @param string $quality      Quality (low, medium, high).
	 * @param string $output_format Output format (png, jpeg).
	 * @param array  $credentials Credentials.
	 * @return array{url: string, headers: array, body: string}|WP_Error|null
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials );

	/**
	 * Build transcribe request. Return null if not supported.
	 *
	 * @param string $file_path   Path to audio file.
	 * @param string $model       Model (e.g. whisper-1).
	 * @param string $prompt      Optional prompt.
	 * @param array  $credentials Credentials.
	 * @return array{url: string, headers: array, body: string}|WP_Error|null
	 */
	public function build_transcribe_request( $file_path, $model, $prompt, $credentials );

	/**
	 * Build video generation request. Return null if not supported.
	 *
	 * @param string $prompt           Prompt.
	 * @param string $model            Model (e.g. sora-2).
	 * @param string $size             Size (e.g. 1280x720).
	 * @param int    $duration_seconds Duration in seconds (4, 8, or 12).
	 * @param array  $credentials      Credentials.
	 * @return array{url: string, headers: array, body: string}|WP_Error|null
	 */
	public function build_video_request( $prompt, $model, $size, $duration_seconds, $credentials );

	/**
	 * Verify API key/credentials.
	 *
	 * @param array $credentials Credentials from API_Keys_Helper.
	 * @return array{success: bool, message?: string}
	 */
	public function verify_key( $credentials );

	/**
	 * Fetch models for importer (detailed format).
	 *
	 * @param array $credentials Credentials from API_Keys_Helper.
	 * @return array|WP_Error List of items: { id, provider, type, capabilities }.
	 */
	public function fetch_models( $credentials );
}
