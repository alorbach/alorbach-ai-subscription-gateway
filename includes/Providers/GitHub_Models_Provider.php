<?php
/**
 * GitHub Models API provider (chat-only).
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GitHub_Models_Provider
 */
class GitHub_Models_Provider extends Provider_Base {

	/**
	 * Base URL for GitHub Models API.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://models.github.ai';

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'github_models';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_chat() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'GitHub Models token not configured.', 'alorbach-ai-gateway' ) );
		}
		$model = $body['model'] ?? '';
		$body  = self::normalize_chat_body( $body, $model );
		$org   = isset( $credentials['org'] ) ? trim( $credentials['org'] ) : '';
		$url   = $org !== ''
			? self::BASE_URL . '/orgs/' . rawurlencode( $org ) . '/inference/chat/completions'
			: self::BASE_URL . '/inference/chat/completions';
		return array(
			'url'     => $url,
			'headers' => array(
				'Content-Type'        => 'application/json',
				'Authorization'       => 'Bearer ' . $api_key,
				'Accept'              => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2026-03-10',
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'message' => __( 'GitHub Models token not configured.', 'alorbach-ai-gateway' ) );
		}
		$response = wp_remote_get(
			self::BASE_URL . '/catalog/models',
			array(
				'headers' => array(
					'Authorization'       => 'Bearer ' . $api_key,
					'Accept'              => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2026-03-10',
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['message'] ) ? $body['message'] : __( 'Invalid GitHub token.', 'alorbach-ai-gateway' );
			return array( 'success' => false, 'message' => $msg );
		}
		return array( 'success' => true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			self::BASE_URL . '/catalog/models',
			array(
				'headers' => array(
					'Authorization'       => 'Bearer ' . $api_key,
					'Accept'              => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2026-03-10',
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}
		$items = array();
		foreach ( $body as $m ) {
			$id = $m['id'] ?? '';
			if ( empty( $id ) ) {
				$publisher = $m['publisher'] ?? '';
				$name     = $m['name'] ?? '';
				if ( $publisher && $name ) {
					$id = $publisher . '/' . $name;
				}
			}
			if ( empty( $id ) ) {
				continue;
			}
			$input_mods  = $m['supported_input_modalities'] ?? array();
			$output_mods = $m['supported_output_modalities'] ?? array();
			$caps        = array( 'text_to_text' );
			if ( in_array( 'image', $input_mods, true ) ) {
				$caps[] = 'image_to_text';
			}
			$items[] = array(
				'id'           => $id,
				'provider'     => 'github_models',
				'type'         => 'text',
				'capabilities' => $caps,
			);
		}
		return $items;
	}
}
