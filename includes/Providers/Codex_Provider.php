<?php
/**
 * OpenAI Codex provider – authenticates via ChatGPT consumer OAuth managed by Codex_OAuth.
 *
 * Uses the ChatGPT backend Responses API endpoint
 * (https://chatgpt.com/backend-api/codex/responses) with an OAuth access token
 * obtained from a ChatGPT Plus/Pro subscription.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

use Alorbach\AIGateway\Codex_OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Codex_Provider
 */
class Codex_Provider extends Provider_Base {

	const BASE_URL = 'https://chatgpt.com/backend-api';

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'codex';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_chat() {
		return true;
	}

	/**
	 * Build a chat request for the ChatGPT Codex Responses API.
	 *
	 * Converts the incoming chat-completions-style body to the Responses API format
	 * expected by https://chatgpt.com/backend-api/codex/responses.
	 *
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		$token = Codex_OAuth::get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$account_id = Codex_OAuth::get_account_id();
		if ( empty( $account_id ) ) {
			return new \WP_Error( 'no_account_id', __( 'Codex account ID not available. Please re-authorize.', 'alorbach-ai-gateway' ) );
		}

		$model    = $body['model'] ?? '';
		$messages = $body['messages'] ?? array();

		// Separate system messages (→ instructions) from the rest (→ input).
		$instructions = '';
		$input        = array();
		foreach ( $messages as $msg ) {
			if ( isset( $msg['role'] ) && $msg['role'] === 'system' ) {
				$instructions .= ( $instructions ? "\n" : '' ) . ( is_string( $msg['content'] ) ? $msg['content'] : '' );
			} else {
				$input[] = $msg;
			}
		}

		$request_body = array(
			'model'        => $model,
			'store'        => false,
			'stream'       => true,
			'input'        => $input,
			'instructions' => $instructions !== '' ? $instructions : 'You are a helpful assistant.',
			'text'         => array( 'verbosity' => 'medium' ),
		);
		if ( isset( $body['temperature'] ) ) {
			$request_body['temperature'] = $body['temperature'];
		}

		return array(
			'url'     => self::BASE_URL . '/codex/responses',
			'headers' => array(
				'Content-Type'       => 'application/json',
				'Authorization'      => 'Bearer ' . $token,
				'chatgpt-account-id' => $account_id,
				'originator'         => 'pi',
				'OpenAI-Beta'        => 'responses=experimental',
			),
			'body'    => wp_json_encode( $request_body ),
		);
	}

	/**
	 * Verify the OAuth connection by checking token availability and account ID.
	 *
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		if ( ! Codex_OAuth::is_connected() ) {
			return array( 'success' => false, 'message' => __( 'Codex OAuth not connected. Please authorize.', 'alorbach-ai-gateway' ) );
		}

		$token = Codex_OAuth::get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return array( 'success' => false, 'message' => $token->get_error_message() );
		}

		$account_id = Codex_OAuth::get_account_id();
		if ( empty( $account_id ) ) {
			return array( 'success' => false, 'message' => __( 'Account ID missing – please re-authorize.', 'alorbach-ai-gateway' ) );
		}

		return array( 'success' => true, 'message' => sprintf( __( 'Connected (account: %s)', 'alorbach-ai-gateway' ), $account_id ) );
	}

	/**
	 * Fetch available Codex models dynamically from the ChatGPT backend API.
	 *
	 * Tries GET /backend-api/codex/models first (Codex-specific endpoint).
	 * Returns an error when the API is unreachable or returns no usable models.
	 *
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		if ( ! Codex_OAuth::is_connected() ) {
			return new \WP_Error( 'not_connected', __( 'Codex OAuth not connected.', 'alorbach-ai-gateway' ) );
		}

		$token = Codex_OAuth::get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$account_id = Codex_OAuth::get_account_id();

		// Try the Codex-specific models endpoint first.
		$response = wp_remote_get(
			self::BASE_URL . '/codex/models?client_version=' . rawurlencode( defined( 'ALORBACH_VERSION' ) ? ALORBACH_VERSION : '1.0.7' ),
			array(
				'headers' => array(
					'Authorization'      => 'Bearer ' . $token,
					'chatgpt-account-id' => $account_id,
					'originator'         => 'pi',
					'OpenAI-Beta'        => 'responses=experimental',
				),
				'timeout' => 15,
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$http_code = wp_remote_retrieve_response_code( $response );
			if ( $http_code < 400 ) {
				$body       = json_decode( wp_remote_retrieve_body( $response ), true );
				$raw_models = array();
				if ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
					$raw_models = $body['models'];
				} elseif ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
					$raw_models = $body['data'];
				} elseif ( is_array( $body ) && ! empty( $body ) ) {
					$raw_models = $body;
				}

				$items = array();
				foreach ( $raw_models as $m ) {
					$slug = isset( $m['slug'] ) ? (string) $m['slug'] : ( isset( $m['id'] ) ? (string) $m['id'] : '' );
					if ( ! $slug ) {
						continue;
					}
					$items[] = array(
						'id'           => $slug,
						'provider'     => 'codex',
						'type'         => 'text',
						'capabilities' => array( 'reasoning' ),
					);
				}

				if ( ! empty( $items ) ) {
					return $items;
				}
			}
		}

		// Codex-specific endpoint unavailable: expose the failure instead of a stale catalog.
		return new \WP_Error( 'models_unavailable', __( 'Codex models could not be loaded from ChatGPT. No fallback catalog is available.', 'alorbach-ai-gateway' ) );
	}
}
