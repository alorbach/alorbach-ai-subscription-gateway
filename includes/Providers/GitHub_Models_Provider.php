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
		return self::make_verified_get(
			self::BASE_URL . '/catalog/models',
			array(
				'Authorization'        => 'Bearer ' . $api_key,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2026-03-10',
			),
			__( 'Invalid GitHub token.', 'alorbach-ai-gateway' )
		);
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
			// Extract max output tokens from catalog response — GitHub Models catalog
			// may return this under several field names depending on API version.
			$limits     = is_array( $m['model_limits'] ?? null ) ? $m['model_limits'] : array();
			$max_tokens = (int) (
				$limits['max_output_tokens']   ??
				$limits['output_token_limit']  ??
				$m['max_output_tokens']        ??
				$m['output_token_limit']       ??
				$m['context_window']           ??
				$m['context_length']           ??
				0
			);
			$items[] = array(
				'id'           => $id,
				'provider'     => 'github_models',
				'type'         => 'text',
				'capabilities' => $caps,
				'max_tokens'   => $max_tokens,
			);
		}
		return $items;
	}
}
