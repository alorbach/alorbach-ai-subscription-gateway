<?php
/**
 * Codex OAuth manager – ChatGPT consumer PKCE flow (ChatGPT Plus/Pro subscription).
 *
 * Implements the same OAuth flow used by the "pi" CLI tool (github.com/badlogic/pi-mono)
 * to authenticate with ChatGPT Codex (chatgpt.com/backend-api).
 *
 * No user-registered OAuth app is needed.  This uses the consumer app credentials
 * baked into the ChatGPT Codex CLI client (app_EMoamEEZ73f0CkXaXp7hrann).
 *
 * WordPress / remote flow:
 *  1. Admin clicks "Get Authorization URL" → calls get_authorization_url().
 *  2. The generated URL is displayed; admin opens it in their LOCAL browser.
 *  3. After signing in, the browser is redirected to the localhost:1455 callback
 *     (which shows "connection refused" since nothing is listening on that port).
 *  4. Admin copies the full URL from the browser address bar and pastes it.
 *  5. exchange_code_from_input() extracts code + state, validates PKCE state,
 *     exchanges for tokens, and stores them.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Codex_OAuth
 */
class Codex_OAuth {

	// Consumer app credentials baked into the ChatGPT Codex CLI / pi tool.
	const CLIENT_ID    = 'app_EMoamEEZ73f0CkXaXp7hrann';
	const AUTH_URL     = 'https://auth.openai.com/oauth/authorize';
	const TOKEN_URL    = 'https://auth.openai.com/oauth/token';
	const REDIRECT_URI = 'http://localhost:1455/auth/callback';
	const SCOPE        = 'openid profile email offline_access';

	// JWT nested claim key containing chatgpt_account_id.
	const JWT_CLAIM_PATH = 'https://api.openai.com/auth';

	const TOKEN_OPTION       = 'alorbach_codex_oauth_token';
	const PKCE_TRANSIENT_PFX = 'alorbach_cxpkce_';
	/**
	 * Seconds before access-token expiry at which to proactively refresh.
	 */
	const REFRESH_BUFFER = 300;

	// -------------------------------------------------------------------------
	// PKCE helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a PKCE code verifier: 32 random bytes, base64url-encoded.
	 *
	 * @return string
	 */
	private static function generate_code_verifier() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Compute PKCE S256 code challenge: BASE64URL(SHA256(verifier)).
	 *
	 * @param string $verifier Code verifier string.
	 * @return string
	 */
	private static function generate_code_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Generate a cryptographically random state value: 16 bytes, hex-encoded.
	 *
	 * @return string
	 */
	private static function generate_state() {
		return bin2hex( random_bytes( 16 ) );
	}

	// -------------------------------------------------------------------------
	// Authorization URL
	// -------------------------------------------------------------------------

	/**
	 * Generate the ChatGPT OAuth authorization URL and store PKCE verifier in a transient.
	 *
	 * @return string Authorization URL.
	 */
	public static function get_authorization_url() {
		$verifier  = self::generate_code_verifier();
		$challenge = self::generate_code_challenge( $verifier );
		$state     = self::generate_state();

		// Store PKCE verifier keyed by state (5-minute TTL).
		set_transient(
			self::PKCE_TRANSIENT_PFX . $state,
			array( 'verifier' => $verifier ),
			5 * MINUTE_IN_SECONDS
		);

		return self::AUTH_URL . '?' . http_build_query(
			array(
				'response_type'             => 'code',
				'client_id'                 => self::CLIENT_ID,
				'redirect_uri'              => self::REDIRECT_URI,
				'scope'                     => self::SCOPE,
				'code_challenge'            => $challenge,
				'code_challenge_method'     => 'S256',
				'state'                     => $state,
				'id_token_add_organizations' => 'true',
				'codex_cli_simplified_flow' => 'true',
				'originator'                => 'pi',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Token exchange (pasted redirect URL or bare code)
	// -------------------------------------------------------------------------

	/**
	 * Parse a pasted redirect URL (or bare code) and exchange for tokens.
	 *
	 * Accepts any of:
	 *  - Full redirect URL: http://localhost:1455/auth/callback?code=xxx&state=yyy
	 *  - Query string:      code=xxx&state=yyy
	 *  - code#state format
	 *  - Bare authorization code (state is required; will fail without it)
	 *
	 * @param string $pasted_input Whatever the admin pasted into the form.
	 * @return true|\WP_Error
	 */
	public static function exchange_code_from_input( $pasted_input ) {
		$pasted_input = trim( $pasted_input );
		if ( empty( $pasted_input ) ) {
			return new \WP_Error( 'empty_input', __( 'No authorization input provided.', 'alorbach-ai-gateway' ) );
		}

		$code  = null;
		$state = null;

		// Try parsing as a URL (handles full redirect URL and bare query strings with a scheme).
		$parsed = wp_parse_url( $pasted_input );
		if ( isset( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $qs );
			$code  = isset( $qs['code'] ) ? sanitize_text_field( $qs['code'] ) : null;
			$state = isset( $qs['state'] ) ? sanitize_text_field( $qs['state'] ) : null;
		}

		// Try code#state shorthand.
		if ( ! $code && strpos( $pasted_input, '#' ) !== false ) {
			list( $code, $state ) = array_pad( explode( '#', $pasted_input, 2 ), 2, null );
			$code  = $code  ? sanitize_text_field( $code )  : null;
			$state = $state ? sanitize_text_field( $state ) : null;
		}

		// Try bare query string (no scheme, e.g. "code=xxx&state=yyy").
		if ( ! $code && strpos( $pasted_input, 'code=' ) !== false ) {
			parse_str( $pasted_input, $qs );
			$code  = isset( $qs['code'] ) ? sanitize_text_field( $qs['code'] ) : null;
			$state = isset( $qs['state'] ) ? sanitize_text_field( $qs['state'] ) : null;
		}

		// Treat the entire input as a bare code (state must already be set or will fail).
		if ( ! $code ) {
			$code = sanitize_text_field( $pasted_input );
		}

		if ( empty( $code ) ) {
			return new \WP_Error( 'missing_code', __( 'Could not extract authorization code from input.', 'alorbach-ai-gateway' ) );
		}

		if ( empty( $state ) ) {
			return new \WP_Error(
				'missing_state',
				__( 'No state parameter found. Please paste the full redirect URL from your browser address bar (e.g. http://localhost:1455/auth/callback?code=…&state=…).', 'alorbach-ai-gateway' )
			);
		}

		// Retrieve and validate the stored PKCE verifier.
		$pkce = get_transient( self::PKCE_TRANSIENT_PFX . $state );
		if ( ! is_array( $pkce ) || empty( $pkce['verifier'] ) ) {
			return new \WP_Error(
				'invalid_state',
				__( 'Invalid or expired PKCE state. Please click "Get Authorization URL" again to start a fresh flow.', 'alorbach-ai-gateway' )
			);
		}
		delete_transient( self::PKCE_TRANSIENT_PFX . $state );

		return self::do_token_exchange( $code, $pkce['verifier'] );
	}

	/**
	 * POST authorization code + PKCE verifier to the token endpoint.
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE code verifier.
	 * @return true|\WP_Error
	 */
	private static function do_token_exchange( $code, $verifier ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => self::CLIENT_ID,
					'code'          => $code,
					'code_verifier' => $verifier,
					'redirect_uri'  => self::REDIRECT_URI,
				),
			)
		);

		return self::process_token_response( $response );
	}

	// -------------------------------------------------------------------------
	// Token refresh
	// -------------------------------------------------------------------------

	/**
	 * Use the stored refresh token to obtain a new access token.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh_access_token() {
		$token = self::get_stored_token();
		if ( ! $token || empty( $token['refresh'] ) ) {
			return new \WP_Error(
				'no_refresh_token',
				__( 'No refresh token stored. Please reconnect.', 'alorbach-ai-gateway' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $token['refresh'],
					'client_id'     => self::CLIENT_ID,
				),
			)
		);

		return self::process_token_response( $response );
	}

	// -------------------------------------------------------------------------
	// Access-token retrieval (with auto-refresh)
	// -------------------------------------------------------------------------

	/**
	 * Return a valid access token, automatically refreshing when within
	 * REFRESH_BUFFER seconds of expiry.
	 *
	 * @return string|\WP_Error Access token string or error.
	 */
	public static function get_valid_access_token() {
		$token = self::get_stored_token();

		if ( ! $token || empty( $token['access'] ) ) {
			return new \WP_Error(
				'not_connected',
				__( 'Codex OAuth not connected. Please authorize under AI Gateway → API Keys.', 'alorbach-ai-gateway' )
			);
		}

		// Proactively refresh when close to or past expiry.
		// $token['expires'] is stored in milliseconds (consistent with pi-ai).
		if ( isset( $token['expires'] ) && ( time() + self::REFRESH_BUFFER ) * 1000 >= (int) $token['expires'] ) {
			$result = self::refresh_access_token();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$token = self::get_stored_token();
			if ( ! $token || empty( $token['access'] ) ) {
				return new \WP_Error(
					'refresh_failed',
					__( 'Failed to refresh Codex access token.', 'alorbach-ai-gateway' )
				);
			}
		}

		return $token['access'];
	}

	/**
	 * Return the stored chatgpt_account_id or empty string.
	 *
	 * @return string
	 */
	public static function get_account_id() {
		$token = self::get_stored_token();
		return ( $token && ! empty( $token['account_id'] ) ) ? $token['account_id'] : '';
	}

	// -------------------------------------------------------------------------
	// Status helpers
	// -------------------------------------------------------------------------

	/**
	 * True when an access or refresh token is stored.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$token = self::get_stored_token();
		return ! empty( $token['access'] ) || ! empty( $token['refresh'] );
	}

	/**
	 * Return the raw stored token array or null.
	 *
	 * @return array|null
	 */
	public static function get_stored_token() {
		$data = get_option( self::TOKEN_OPTION, null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Remove all stored OAuth tokens (disconnect).
	 */
	public static function revoke() {
		delete_option( self::TOKEN_OPTION );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode a JWT payload without signature verification (for claim extraction only).
	 *
	 * @param string $jwt JWT string.
	 * @return array|null Decoded payload or null on failure.
	 */
	private static function decode_jwt( $jwt ) {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		$payload = strtr( $parts[1], '-_', '+/' );
		$padded  = str_pad( $payload, strlen( $payload ) + ( 4 - strlen( $payload ) % 4 ) % 4, '=' );
		$decoded = base64_decode( $padded );
		if ( false === $decoded ) {
			return null;
		}
		return json_decode( $decoded, true );
	}

	/**
	 * Extract chatgpt_account_id from an access-token JWT.
	 *
	 * @param string $access_token Raw JWT string.
	 * @return string|null Account ID or null if not found.
	 */
	private static function extract_account_id( $access_token ) {
		$payload = self::decode_jwt( $access_token );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		$auth = isset( $payload[ self::JWT_CLAIM_PATH ] ) ? $payload[ self::JWT_CLAIM_PATH ] : null;
		if ( is_array( $auth ) && ! empty( $auth['chatgpt_account_id'] ) ) {
			return $auth['chatgpt_account_id'];
		}
		return null;
	}

	/**
	 * Parse a token-endpoint response and persist the resulting tokens.
	 * Preserves the existing refresh token if the server does not issue a new one.
	 *
	 * @param array|\WP_Error $response Result of wp_remote_post().
	 * @return true|\WP_Error
	 */
	private static function process_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$body      = json_decode( $raw_body, true );

		if ( $http_code >= 400 || ! isset( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] )
				? $body['error_description']
				: ( isset( $body['error'] ) ? $body['error'] : __( 'OAuth token exchange failed.', 'alorbach-ai-gateway' ) );
			error_log( 'Codex OAuth token exchange HTTP ' . $http_code . ': ' . $raw_body );
			return new \WP_Error( 'token_error', $msg );
		}

		$access_token = sanitize_text_field( $body['access_token'] );

		// Try to extract chatgpt_account_id from access_token (may be a JWT).
		// Fall back to id_token (OIDC — always a JWT and commonly carries custom claims).
		$account_id = self::extract_account_id( $access_token );
		if ( ! $account_id && ! empty( $body['id_token'] ) ) {
			$account_id = self::extract_account_id( $body['id_token'] );
		}

		// Log decoded JWT payloads to help diagnose claim structure during development.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$at_payload = self::decode_jwt( $access_token );
			error_log( 'Codex OAuth access_token payload: ' . wp_json_encode( $at_payload ) );
			if ( ! empty( $body['id_token'] ) ) {
				$id_payload = self::decode_jwt( $body['id_token'] );
				error_log( 'Codex OAuth id_token payload: ' . wp_json_encode( $id_payload ) );
			}
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		$token_data = array(
			'access'     => $access_token,
			// Store expiry in milliseconds, consistent with pi-ai.
			'expires'    => ( time() + $expires_in ) * 1000,
			'account_id' => $account_id ? sanitize_text_field( $account_id ) : '',
		);

		if ( ! empty( $body['refresh_token'] ) ) {
			$token_data['refresh'] = sanitize_text_field( $body['refresh_token'] );
		} else {
			// Preserve existing refresh token across access-token renewals.
			$existing = self::get_stored_token();
			if ( $existing && ! empty( $existing['refresh'] ) ) {
				$token_data['refresh'] = $existing['refresh'];
			}
		}

		update_option( self::TOKEN_OPTION, $token_data, false );
		return true;
	}
}
