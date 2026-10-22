<?php
/**
 * Verify the published server chat (sync_chat) capability contract of this
 * site without calling a provider, changing balances, or spending credits.
 *
 * Run inside a loaded WordPress environment.  In the Gateway's own wp-env
 * (plugin mapped from "./wordpress-plugin"):
 * wp eval-file wp-content/plugins/wordpress-plugin/bin/verify-server-chat-capabilities.php
 * In a host site that installs the plugin as alorbach-ai-subscription-gateway
 * (for example the Kinder Buchwerk Studio environment) use:
 * wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/bin/verify-server-chat-capabilities.php
 *
 * The script reads GET /integration/config as an anonymous caller and as an
 * administrator (env WP_ADMIN_USER if set and found, else the login "admin",
 * else the first user with the administrator role), checks every sync_chat
 * contract for shape, fail-closed eligibility and absent secrets, and
 * exercises the pure contract builder with a fixture.  It does not change the
 * Server Chat Models configuration and calls no provider; core reads of the
 * integration config may still run existing migrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside a loaded WordPress context.\n" );
	exit( 1 );
}

use Alorbach\AIGateway\Server_Chat_Models;

/** @param bool $condition @param string $message */
function alorbach_server_chat_require( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @param array $contract @param string $label */
function alorbach_server_chat_check_contract( array $contract, $label ) {
	$required = array( 'gateway_model_key', 'label', 'provider', 'transport', 'chat_contract_version', 'eligible', 'disabled_reason', 'admin_enabled', 'server_dispatch_evidenced', 'dispatch_entry_exclusive', 'pricing_evidenced', 'requires_browser_pairing', 'supported_languages', 'operation_slugs', 'structured_output', 'provider_cancel', 'provider_resume', 'text_cost_estimate' );
	foreach ( $required as $key ) {
		alorbach_server_chat_require( array_key_exists( $key, $contract ), sprintf( '%s is missing "%s".', $label, $key ) );
	}
	alorbach_server_chat_require( Server_Chat_Models::TRANSPORT === $contract['transport'], "$label must have transport sync_chat." );
	alorbach_server_chat_require( Server_Chat_Models::CONTRACT_VERSION === $contract['chat_contract_version'], "$label must carry the current contract version." );
	alorbach_server_chat_require( Server_Chat_Models::is_server_model_key( (string) $contract['gateway_model_key'] ), "$label must not be a relay or local model." );
	alorbach_server_chat_require( false === $contract['requires_browser_pairing'], "$label must not require browser pairing." );
	alorbach_server_chat_require( false === $contract['provider_cancel'] && false === $contract['provider_resume'] && false === $contract['text_cost_estimate'] && false === $contract['structured_output'], "$label must not claim unproven capabilities." );
	alorbach_server_chat_require( is_array( $contract['supported_languages'] ) && is_array( $contract['operation_slugs'] ), "$label language and operation lists must be arrays." );
	foreach ( $contract['supported_languages'] as $language ) {
		alorbach_server_chat_require( is_string( $language ) && 1 === preg_match( '/^[a-z]{2,3}-[A-Z]{2}$/', $language ), "$label publishes an invalid language." );
	}
	foreach ( $contract['operation_slugs'] as $slug ) {
		alorbach_server_chat_require( is_string( $slug ) && 1 === preg_match( '/^[a-z][a-z0-9_]{2,79}$/', $slug ), "$label publishes an invalid operation slug." );
	}
	if ( true === $contract['eligible'] ) {
		alorbach_server_chat_require( true === $contract['admin_enabled'] && true === $contract['server_dispatch_evidenced'] && true === $contract['dispatch_entry_exclusive'] && true === $contract['pricing_evidenced'] && ! empty( $contract['supported_languages'] ) && ! empty( $contract['operation_slugs'] ) && null === $contract['disabled_reason'], "$label is eligible without complete evidence." );
	} else {
		alorbach_server_chat_require( is_string( $contract['disabled_reason'] ) && '' !== $contract['disabled_reason'], "$label is ineligible without a reason." );
	}
	alorbach_server_chat_require( array_keys( $contract ) === Server_Chat_Models::CONTRACT_FIELDS, "$label must carry exactly the documented fields." );
	$scanned = $contract;
	unset( $scanned['label'] ); // The human label may legitimately contain words such as "endpoint".
	$serialized = strtolower( (string) wp_json_encode( $scanned ) );
	foreach ( array( 'api_key', 'apikey', 'secret', 'password', 'authorization', 'access_token', 'endpoint' ) as $forbidden ) {
		alorbach_server_chat_require( false === strpos( $serialized, $forbidden ), "$label leaks \"$forbidden\"." );
	}
}

/** @param array $config @param string $label @return int Number of sync_chat contracts. */
function alorbach_server_chat_check_config( array $config, $label, array &$sync_contracts ) {
	alorbach_server_chat_require( isset( $config['capabilities'] ) && is_array( $config['capabilities'] ), "$label has no capabilities." );
	$capabilities = $config['capabilities'];
	alorbach_server_chat_require( array_key_exists( 'server_chat_models', $capabilities ) && is_array( $capabilities['server_chat_models'] ), "$label must publish capabilities.server_chat_models." );
	alorbach_server_chat_require( Server_Chat_Models::CONTRACT_VERSION === ( $capabilities['server_chat_contract_version'] ?? null ), "$label must publish capabilities.server_chat_contract_version." );
	alorbach_server_chat_require( isset( $capabilities['models'] ) && is_array( $capabilities['models'] ), "$label must publish capabilities.models." );
	$chat_models = isset( $capabilities['chat_models'] ) && is_array( $capabilities['chat_models'] ) ? $capabilities['chat_models'] : array();
	$eligible    = array();
	$count       = 0;
	$sync_contracts = array();
	foreach ( $capabilities['models'] as $index => $contract ) {
		if ( ! is_array( $contract ) || Server_Chat_Models::TRANSPORT !== ( $contract['transport'] ?? '' ) ) {
			continue;
		}
		$count++;
		$sync_contracts[] = $contract;
		alorbach_server_chat_check_contract( $contract, "$label models[$index]" );
		$aliases = \Alorbach\AIGateway\Cost_Matrix::gateway_model_key_aliases( (string) $contract['gateway_model_key'] );
		alorbach_server_chat_require( array() !== array_intersect( $aliases, $chat_models ), "$label models[$index] is published although its key is not in capabilities.chat_models." );
		if ( true === $contract['eligible'] ) {
			$eligible[] = $contract['gateway_model_key'];
		}
	}
	alorbach_server_chat_require( $eligible === $capabilities['server_chat_models'], "$label capabilities.server_chat_models must list exactly the eligible sync_chat contracts." );
	return $count;
}

try {
	// 1. Pure builder with a fixture: no option is written, no provider is reached.
	$fixture_path = dirname( __DIR__ ) . '/tests/fixtures/server-chat-contract.json';
	$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true );
	alorbach_server_chat_require( is_array( $fixture ), 'Fixture tests/fixtures/server-chat-contract.json is unreadable.' );
	$azure_key   = '62f21a64-9a37-42f7-bde4-f7627bed9b3b::gpt-5.6-luna';
	$openai_key  = 'gpt-4.1-mini';
	$unpriced    = '62f21a64-9a37-42f7-bde4-f7627bed9b3b::gpt-6-luna';
	$text_models = array( $azure_key => 'gpt-5.6-luna (Azure / Prod)', $openai_key => 'gpt-4.1-mini', $unpriced => 'gpt-6-luna (Azure / Prod)', 'model-relay:codex:auto' => 'Codex CLI (auto)' );
	$settings    = array(
		$azure_key  => array( 'enabled' => true, 'supported_languages' => array( 'de-DE', 'en-US' ), 'operation_slugs' => array( 'story_create', 'book_title_create', 'page_scene_create' ) ),
		$openai_key => array( 'enabled' => true, 'supported_languages' => array( 'en-US' ), 'operation_slugs' => array( 'story_create' ) ),
		$unpriced   => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ),
	);
	$evidence = static function ( $key ) use ( $azure_key, $openai_key, $unpriced ) {
		$map = array(
			$azure_key  => array( 'dispatch' => true, 'exclusive' => true, 'pricing' => true, 'provider' => 'azure' ),
			$openai_key => array( 'dispatch' => false, 'exclusive' => false, 'pricing' => true, 'provider' => 'openai' ),
			$unpriced   => array( 'dispatch' => true, 'exclusive' => true, 'pricing' => false, 'provider' => 'azure' ),
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : array( 'dispatch' => false, 'exclusive' => false, 'pricing' => false, 'provider' => '' );
	};
	$built = Server_Chat_Models::build_contracts( $text_models, $settings, $evidence );
	alorbach_server_chat_require( $fixture['capabilities']['models'] === $built, 'Pure builder output differs from the fixture.' );
	alorbach_server_chat_require( array() === Server_Chat_Models::build_contracts( $text_models, array(), $evidence ), 'Without configuration no sync_chat contract may be published.' );
	alorbach_server_chat_require( array() === Server_Chat_Models::sanitize_settings( array( 'model-relay:codex:auto' => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ) ) ), 'Relay keys must never be stored as server chat models.' );

	// 2. Live payload, anonymous.
	$anonymous = rest_do_request( new WP_REST_Request( 'GET', '/alorbach/v1/integration/config' ) );
	alorbach_server_chat_require( ! $anonymous->is_error() && 200 === $anonymous->get_status(), 'Anonymous GET /integration/config must return 200.' );
	$anonymous_contracts = array();
	$anonymous_data      = (array) $anonymous->get_data();
	$anonymous_count     = alorbach_server_chat_check_config( $anonymous_data, 'Anonymous config', $anonymous_contracts );

	// 3. Live payload, administrator (plan-filtered path).
	$admin      = false;
	$admin_name = getenv( 'WP_ADMIN_USER' );
	if ( is_string( $admin_name ) && '' !== $admin_name ) {
		$admin = get_user_by( 'login', $admin_name );
	}
	if ( ! ( $admin instanceof WP_User ) ) {
		$admin = get_user_by( 'login', 'admin' );
	}
	if ( ! ( $admin instanceof WP_User ) ) {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$admin  = ! empty( $admins ) ? $admins[0] : false;
	}
	alorbach_server_chat_require( $admin instanceof WP_User, 'Could not resolve an administrator: set WP_ADMIN_USER, create the user "admin", or create any user with the administrator role.' );
	wp_set_current_user( (int) $admin->ID );
	$authenticated = rest_do_request( new WP_REST_Request( 'GET', '/alorbach/v1/integration/config' ) );
	alorbach_server_chat_require( ! $authenticated->is_error() && 200 === $authenticated->get_status(), 'Authenticated GET /integration/config must return 200.' );
	$admin_contracts = array();
	$admin_data      = (array) $authenticated->get_data();
	$admin_count     = alorbach_server_chat_check_config( $admin_data, 'Admin config', $admin_contracts );

	// Cross-check: a contract the anonymous caller sees for a model in the admin chat catalog must match.
	$admin_chat_models = isset( $admin_data['capabilities']['chat_models'] ) && is_array( $admin_data['capabilities']['chat_models'] ) ? $admin_data['capabilities']['chat_models'] : array();
	foreach ( $anonymous_contracts as $anonymous_contract ) {
		$aliases = \Alorbach\AIGateway\Cost_Matrix::gateway_model_key_aliases( (string) $anonymous_contract['gateway_model_key'] );
		if ( array() === array_intersect( $aliases, $admin_chat_models ) ) {
			continue;
		}
		$match = null;
		foreach ( $admin_contracts as $admin_contract ) {
			if ( $admin_contract['gateway_model_key'] === $anonymous_contract['gateway_model_key'] ) {
				$match = $admin_contract;
				break;
			}
		}
		alorbach_server_chat_require( null !== $match, sprintf( 'Admin config lacks the sync_chat contract %s that is in capabilities.chat_models.', $anonymous_contract['gateway_model_key'] ) );
		alorbach_server_chat_require( $match['eligible'] === $anonymous_contract['eligible'], sprintf( 'Admin and anonymous eligibility differ for %s.', $anonymous_contract['gateway_model_key'] ) );
	}

	// 4. Stored configuration never contains a relay key and matches the published set.
	$stored = Server_Chat_Models::get_settings();
	foreach ( array_keys( $stored ) as $key ) {
		alorbach_server_chat_require( Server_Chat_Models::is_server_model_key( (string) $key ), "Stored server chat configuration contains relay key $key." );
	}

	fwrite( STDOUT, sprintf( "Server chat capability contract verified: %d sync_chat contract(s) anonymous, %d for admin, %d configured, contract version %s.\n", $anonymous_count, $admin_count, count( $stored ), Server_Chat_Models::CONTRACT_VERSION ) );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Server chat capability verification failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
