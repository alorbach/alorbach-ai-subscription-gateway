<?php
/**
 * Offline contract test for Server_Chat_Models.
 *
 * Runs without WordPress and without any provider call: a handful of WordPress
 * functions and the Gateway collaborators (API_Keys_Helper, API_Client,
 * Codex_OAuth, Provider_Registry) are stubbed from a fixture array, and the
 * expected payload is compared against tests/fixtures/server-chat-contract.json.
 *
 * Run: php tests/server-chat-models-test.php
 */

declare( strict_types=1 );

namespace Alorbach\AIGateway\Providers {
	/** Provider stub: only chat support is modelled. */
	class Stub_Provider {
		private $chat;
		public function __construct( bool $chat ) { $this->chat = $chat; }
		public function supports_chat(): bool { return $this->chat; }
	}

	class Provider_Registry {
		public static function get( $type ) {
			$map = $GLOBALS['alorbach_fx']['providers'] ?? array();
			return isset( $map[ $type ] ) ? new Stub_Provider( (bool) $map[ $type ] ) : null;
		}
	}
}

namespace Alorbach\AIGateway {
	/** Fixture-driven stand-in for the real key helper (no options, no secrets). */
	class API_Keys_Helper {
		private static function entries(): array { return $GLOBALS['alorbach_fx']['entries'] ?? array(); }

		/** Mirrors API_Keys_Helper::get_entries(): list of entries, each carrying its id. */
		public static function get_entries() {
			$result = array();
			foreach ( self::entries() as $id => $entry ) {
				$result[] = array_merge( array( 'id' => (string) $id ), $entry );
			}
			return $result;
		}

		public static function get_entry_by_id( $id ) {
			if ( empty( $id ) ) {
				return null;
			}
			foreach ( self::get_entries() as $entry ) {
				if ( $entry['id'] === (string) $id ) {
					return $entry;
				}
			}
			return null;
		}

		/** First ENABLED entry of the type, regardless of api_key. */
		public static function get_entry_by_type( $type ) {
			foreach ( self::get_entries() as $entry ) {
				if ( ( $entry['type'] ?? '' ) === $type && ! empty( $entry['enabled'] ) ) {
					return $entry;
				}
			}
			return null;
		}

		/** Enabled entries with an api_key (except codex-like types). */
		public static function get_all_entries_for_type( $type ) {
			$result = array();
			foreach ( self::get_entries() as $entry ) {
				if ( ( $entry['type'] ?? '' ) === $type && ! empty( $entry['enabled'] ) ) {
					if ( ! in_array( $type, array( 'ai_bridge', 'codex', 'codex_local', 'huggingface_spaces' ), true ) && empty( $entry['api_key'] ) ) {
						continue;
					}
					$result[] = $entry;
				}
			}
			return $result;
		}

		private static function entry_to_credentials( array $entry ) {
			if ( ! in_array( (string) ( $entry['type'] ?? '' ), array( 'ai_bridge', 'codex', 'codex_local', 'huggingface_spaces' ), true ) && empty( $entry['api_key'] ) ) {
				return null;
			}
			return array(
				'api_key'           => (string) ( $entry['api_key'] ?? '' ),
				'endpoint'          => (string) ( $entry['endpoint'] ?? '' ),
				'free_pass_through' => ! empty( $entry['free_pass_through'] ),
			);
		}

		public static function get_credentials_for_entry( $id ) {
			$entry = self::get_entry_by_id( $id );
			return is_array( $entry ) ? self::entry_to_credentials( $entry ) : null;
		}

		public static function get_credentials_for_provider( $type ) {
			$entry = self::get_entry_by_type( $type );
			return $entry ? self::entry_to_credentials( $entry ) : null;
		}
	}

	class API_Client {
		public static function get_provider_for_model( $key ) {
			$parsed = Cost_Matrix::parse_model_key( (string) $key );
			if ( '' !== $parsed['entry_id'] ) {
				$entry = API_Keys_Helper::get_entry_by_id( $parsed['entry_id'] );
				return is_array( $entry ) ? (string) $entry['type'] : '';
			}
			$map = $GLOBALS['alorbach_fx']['model_provider'] ?? array();
			return (string) ( $map[ $parsed['model'] ] ?? '' );
		}
	}

	class Codex_OAuth {
		public static function is_connected() { return ! empty( $GLOBALS['alorbach_fx']['codex'] ); }
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	// --- Minimal WordPress stubs -------------------------------------------------
	$GLOBALS['alorbach_test_options'] = array();
	$GLOBALS['alorbach_fx']           = array();
	function get_option( $name, $default = false ) { return $GLOBALS['alorbach_test_options'][ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['alorbach_test_options'][ $name ] = $value; return true; }
	function apply_filters( $hook, $value ) {
		$callback = $GLOBALS['alorbach_test_filters'][ $hook ] ?? null;
		return $callback ? $callback( $value ) : $value;
	}
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function __( $text, $domain = null ) { return $text; }

	require_once dirname( __DIR__ ) . '/includes/class-cost-matrix.php';
	require_once dirname( __DIR__ ) . '/includes/class-server-chat-models.php';

	$failures = 0;
	function check( $condition, $message ) {
		global $failures;
		if ( $condition ) {
			fwrite( STDOUT, "PASS $message\n" );
			return;
		}
		$failures++;
		fwrite( STDOUT, "FAIL $message\n" );
	}

	/** Install a fixture (entries, providers, model_provider, codex) and a cost matrix. */
	function fx( array $fixture, array $matrix_rows ) {
		$GLOBALS['alorbach_fx']                                   = $fixture;
		$GLOBALS['alorbach_test_options']['alorbach_cost_matrix'] = array( 'models' => $matrix_rows );
	}

	$SCM = 'Alorbach\\AIGateway\\Server_Chat_Models';

	$azure_key   = '62f21a64-9a37-42f7-bde4-f7627bed9b3b::gpt-5.6-luna';
	$openai_key  = 'gpt-4.1-mini';
	$unpriced    = '62f21a64-9a37-42f7-bde4-f7627bed9b3b::gpt-6-luna';
	$relay_key   = 'ai-model-relay::model-relay:codex:auto';
	$text_models = array(
		$azure_key                     => 'gpt-5.6-luna (Azure / Prod)',
		$openai_key                    => 'gpt-4.1-mini',
		$unpriced                      => 'gpt-6-luna (Azure / Prod)',
		$relay_key                     => 'Codex CLI (auto)',
		'model-relay:grok-cli:auto'    => 'Grok CLI (auto)',
		'codex-local:auto'             => 'Codex CLI (legacy auto)',
	);

	$evidence = static function ( string $key ) use ( $azure_key, $openai_key, $unpriced ): array {
		$map = array(
			$azure_key  => array( 'dispatch' => true, 'exclusive' => true, 'pricing' => true, 'provider' => 'azure' ),
			$openai_key => array( 'dispatch' => false, 'exclusive' => false, 'pricing' => true, 'provider' => 'openai' ),
			$unpriced   => array( 'dispatch' => true, 'exclusive' => true, 'pricing' => false, 'provider' => 'azure' ),
		);
		return $map[ $key ] ?? array( 'dispatch' => true, 'exclusive' => true, 'pricing' => true, 'provider' => 'ai_bridge' );
	};

	// 1. Fail closed: no configuration publishes nothing.
	check( array() === $SCM::build_contracts( $text_models, array(), $evidence ), 'no configuration publishes no sync_chat contract' );
	check( array() === $SCM::eligible_keys( array() ), 'no contracts means no eligible keys' );

	// 2. Sanitization drops relay keys, bad languages and bad slugs; keeps order and uniqueness.
	$sanitized = $SCM::sanitize_settings( array(
		$azure_key                  => array( 'enabled' => '1', 'supported_languages' => 'de-DE, en-US, de-DE, german, EN-us', 'operation_slugs' => "story_create\nbook_title_create, story_create\nBad-Slug\nab\n" . str_repeat( 'x', 81 ) ),
		$relay_key                  => array( 'enabled' => '1', 'supported_languages' => 'de-DE', 'operation_slugs' => 'story_create' ),
		'model-relay:grok-cli:auto' => array( 'enabled' => '1', 'supported_languages' => 'de-DE', 'operation_slugs' => 'story_create' ),
		'codex-local:auto'          => array( 'enabled' => '1', 'supported_languages' => 'de-DE', 'operation_slugs' => 'story_create' ),
		'local-asr'                 => array( 'enabled' => '1', 'supported_languages' => 'de-DE', 'operation_slugs' => 'story_create' ),
		'local-asr:whisper'         => array( 'enabled' => '1', 'supported_languages' => 'de-DE', 'operation_slugs' => 'story_create' ),
		'../evil key'               => array( 'enabled' => '1' ),
		$openai_key                 => 'not-a-row',
	) );
	check( array( $azure_key ) === array_keys( $sanitized ), 'relay, local (incl. local-asr), malformed and non-array rows are dropped from settings' );
	check( array( 'de-DE', 'en-US' ) === $sanitized[ $azure_key ]['supported_languages'], 'languages are BCP-47 content locales, case-normalized, unique and ordered' );
	check( array( 'story_create', 'book_title_create' ) === $sanitized[ $azure_key ]['operation_slugs'], 'operation slugs are validated snake_case, unique and ordered' );
	check( true === $sanitized[ $azure_key ]['enabled'], 'enabled flag is boolean' );
	check( false === $SCM::sanitize_settings( array( $azure_key => array( 'enabled' => '0' ) ) )[ $azure_key ]['enabled'], 'string zero disables' );

	// 2b. Language case normalization.
	check( array( 'en-US' ) === $SCM::sanitize_languages( 'en-us' ), 'en-us is normalized to en-US' );
	check( array( 'en-US' ) === $SCM::sanitize_languages( 'EN-us, en-US, EN-US' ), 'EN-us is normalized to en-US and de-duplicated' );
	check( array( 'fil-PH' ) === $SCM::sanitize_languages( 'FIL-ph' ), 'three-letter language subtag is accepted and normalized' );
	check( array() === $SCM::sanitize_languages( 'en, english, en_US, e-US, en-USA, 12-34' ), 'malformed locales are dropped' );

	// 3. Contract shape and evidence gates.
	$settings = array(
		$azure_key  => array( 'enabled' => true, 'supported_languages' => array( 'de-DE', 'en-US' ), 'operation_slugs' => array( 'story_create', 'book_title_create', 'page_scene_create' ) ),
		$openai_key => array( 'enabled' => true, 'supported_languages' => array( 'en-US' ), 'operation_slugs' => array( 'story_create' ) ),
		$unpriced   => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ),
		'missing::model' => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ),
	);
	$contracts = $SCM::build_contracts( $text_models, $settings, $evidence );
	$by_key    = array();
	foreach ( $contracts as $contract ) {
		$by_key[ $contract['gateway_model_key'] ] = $contract;
	}
	check( array( $azure_key, $openai_key, $unpriced ) === array_keys( $by_key ), 'only configured models present in the text catalog are published' );
	check( true === $by_key[ $azure_key ]['eligible'] && null === $by_key[ $azure_key ]['disabled_reason'], 'enabled + dispatch + exclusive + pricing + languages + slugs is eligible' );
	check( true === $by_key[ $azure_key ]['dispatch_entry_exclusive'], 'eligible contract publishes dispatch_entry_exclusive true' );
	check( false === $by_key[ $openai_key ]['eligible'] && $SCM::REASON_DISPATCH_UNPROVEN === $by_key[ $openai_key ]['disabled_reason'], 'missing credentials block eligibility' );
	check( false === $by_key[ $unpriced ]['eligible'] && $SCM::REASON_PRICING_UNPROVEN === $by_key[ $unpriced ]['disabled_reason'], 'missing explicit price blocks eligibility' );
	check( array( $azure_key ) === $SCM::eligible_keys( $contracts ), 'eligible_keys lists exactly the eligible contract' );

	$expected_keys = array( 'gateway_model_key', 'label', 'provider', 'transport', 'chat_contract_version', 'eligible', 'disabled_reason', 'admin_enabled', 'server_dispatch_evidenced', 'dispatch_entry_exclusive', 'pricing_evidenced', 'requires_browser_pairing', 'supported_languages', 'operation_slugs', 'structured_output', 'provider_cancel', 'provider_resume', 'text_cost_estimate' );
	check( $expected_keys === array_keys( $by_key[ $azure_key ] ), 'contract exposes exactly the documented fields in order' );
	check( 'sync_chat' === $by_key[ $azure_key ]['transport'] && '1.0.0' === $by_key[ $azure_key ]['chat_contract_version'], 'transport and version are fixed' );
	check( false === $by_key[ $azure_key ]['requires_browser_pairing'] && false === $by_key[ $azure_key ]['provider_cancel'] && false === $by_key[ $azure_key ]['provider_resume'] && false === $by_key[ $azure_key ]['text_cost_estimate'] && false === $by_key[ $azure_key ]['structured_output'], 'unproven capabilities stay false' );

	// 3b. Exclusivity gate: a missing "exclusive" defaults to false and yields the ambiguity reason after dispatch.
	$ambiguous = $SCM::build_contracts( $text_models, array( $azure_key => $settings[ $azure_key ] ), static fn( string $key ): array => array( 'dispatch' => true, 'pricing' => true, 'provider' => 'azure' ) );
	check( false === $ambiguous[0]['eligible'] && $SCM::REASON_DISPATCH_AMBIGUOUS === $ambiguous[0]['disabled_reason'] && 'dispatch_entry_ambiguous' === $ambiguous[0]['disabled_reason'], 'missing exclusive evidence is ambiguous (fail closed)' );
	check( true === $ambiguous[0]['server_dispatch_evidenced'] && false === $ambiguous[0]['dispatch_entry_exclusive'], 'ambiguous contract keeps dispatch evidence but is not exclusive' );
	$both_missing = $SCM::build_contracts( $text_models, array( $azure_key => $settings[ $azure_key ] ), static fn( string $key ): array => array( 'dispatch' => false, 'exclusive' => false, 'pricing' => false, 'provider' => 'azure' ) );
	check( $SCM::REASON_DISPATCH_UNPROVEN === $both_missing[0]['disabled_reason'], 'dispatch gate is evaluated before the exclusivity gate' );
	$ambiguous_unpriced = $SCM::build_contracts( $text_models, array( $azure_key => $settings[ $azure_key ] ), static fn( string $key ): array => array( 'dispatch' => true, 'exclusive' => false, 'pricing' => false, 'provider' => 'azure' ) );
	check( $SCM::REASON_DISPATCH_AMBIGUOUS === $ambiguous_unpriced[0]['disabled_reason'], 'exclusivity gate is evaluated before the pricing gate' );

	// 4. Secrets never leak, whatever the resolver returns.
	$leaky = static fn( string $key ): array => array( 'dispatch' => true, 'exclusive' => true, 'pricing' => true, 'provider' => 'azure', 'api_key' => 'sk-secret', 'endpoint' => 'https://x' );
	$serialized = json_encode( $SCM::build_contracts( $text_models, $settings, $leaky ) );
	check( false === stripos( $serialized, 'sk-secret' ) && false === stripos( $serialized, 'api_key' ) && false === stripos( $serialized, 'endpoint' ), 'resolver extras never reach the published contract' );

	// 5. Gates: disabled switch, languages, slugs; relay providers are skipped.
	$off = $SCM::build_contracts( $text_models, array( $azure_key => array( 'enabled' => false, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ) ), $evidence );
	check( false === $off[0]['eligible'] && $SCM::REASON_DISABLED_BY_ADMIN === $off[0]['disabled_reason'] && false === $off[0]['admin_enabled'], 'disabled switch wins over evidence' );
	$no_lang = $SCM::build_contracts( $text_models, array( $azure_key => array( 'enabled' => true, 'supported_languages' => array( 'xx' ), 'operation_slugs' => array( 'story_create' ) ) ), $evidence );
	check( $SCM::REASON_LANGUAGES_MISSING === $no_lang[0]['disabled_reason'] && array() === $no_lang[0]['supported_languages'], 'invalid languages leave the model ineligible' );
	$no_ops = $SCM::build_contracts( $text_models, array( $azure_key => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'Nope' ) ) ), $evidence );
	check( $SCM::REASON_OPERATIONS_MISSING === $no_ops[0]['disabled_reason'], 'invalid slugs leave the model ineligible' );
	$relay_provider = static fn( string $key ): array => array( 'dispatch' => true, 'exclusive' => true, 'pricing' => true, 'provider' => 'ai_bridge' );
	check( array() === $SCM::build_contracts( $text_models, array( $azure_key => $settings[ $azure_key ] ), $relay_provider ), 'a relay-backed provider is never published' );
	check( $SCM::is_relay_provider( 'ai_bridge' ) && $SCM::is_relay_provider( 'codex_local' ) && ! $SCM::is_relay_provider( 'azure' ) && ! $SCM::is_relay_provider( '' ), 'is_relay_provider recognizes relay entry types only' );

	// 6. Key classification.
	check( $SCM::is_server_model_key( $azure_key ) && $SCM::is_server_model_key( $openai_key ), 'provider keys are server keys' );
	check( ! $SCM::is_server_model_key( $relay_key ) && ! $SCM::is_server_model_key( 'model-relay:codex:auto' ) && ! $SCM::is_server_model_key( 'codex-local:auto' ) && ! $SCM::is_server_model_key( '' ), 'relay and local keys are never server keys' );
	check( ! $SCM::is_server_model_key( 'local-asr' ) && ! $SCM::is_server_model_key( 'local-asr:whisper' ) && ! $SCM::is_server_model_key( 'entry::local-asr' ), 'local ASR keys (bare and prefixed) are never server keys' );
	check( array( $azure_key, $openai_key, $unpriced ) === array_keys( $SCM::filter_server_models( $text_models ) ), 'filter_server_models removes relay and local entries' );

	// 7. Settings round trip through the (stubbed) option store.
	$SCM::update_settings( array( $azure_key => array( 'enabled' => '1', 'supported_languages' => 'de-DE', 'operation_slugs' => 'story_create' ) ) );
	check( array( $azure_key => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ) ) === $SCM::get_settings(), 'settings round trip' );
	$merged = array_merge( $SCM::get_settings(), array( $openai_key => array( 'enabled' => '1', 'supported_languages' => 'en-US', 'operation_slugs' => 'story_create' ) ) );
	$SCM::update_settings( $merged );
	check( array( $azure_key, $openai_key ) === array_keys( $SCM::get_settings() ), 'merging posted rows into stored settings keeps configuration of rows not rendered in the form' );

	// 8. resolve_evidence against fixture-driven collaborators.
	$entry_a   = 'aaaaaaaa-0000-4000-8000-000000000001';
	$entry_b   = 'bbbbbbbb-0000-4000-8000-000000000002';
	$priced    = array( array( 'model' => 'gpt-5.6-luna', 'entry_id' => $entry_a, 'input' => 100, 'output' => 400 ) );
	$azure_a   = array( 'type' => 'azure', 'enabled' => true, 'api_key' => 'secret-a', 'endpoint' => 'https://a.example' );
	$azure_b   = array( 'type' => 'azure', 'enabled' => true, 'api_key' => 'secret-b', 'endpoint' => 'https://b.example' );
	$key_a     = $entry_a . '::gpt-5.6-luna';
	$key_b     = $entry_b . '::gpt-5.6-luna';
	$providers = array( 'azure' => true, 'openai' => true, 'huggingface_spaces' => false, 'ai_bridge' => true );
	$one_row   = array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => $azure_a ) ), $priced );
	$ev = $SCM::resolve_evidence( $key_a );
	check( true === $ev['dispatch'] && true === $ev['exclusive'] && true === $ev['pricing'] && 'azure' === $ev['provider'], 'happy path: sole azure entry with key+endpoint and explicit price is dispatch, exclusive, priced' );
	check( false === stripos( (string) json_encode( $ev ), 'secret-a' ), 'resolve_evidence never returns credentials' );
	$ev_contracts = $SCM::build_contracts( array( $key_a => 'GPT' ), array( $key_a => $one_row ), array( $SCM, 'resolve_evidence' ) );
	check( true === $ev_contracts[0]['eligible'] && true === $ev_contracts[0]['dispatch_entry_exclusive'], 'resolve_evidence feeds build_contracts to an eligible contract' );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array( 'type' => 'azure', 'enabled' => true, 'api_key' => 'secret-a', 'endpoint' => '' ) ) ), $priced );
	check( false === $SCM::resolve_evidence( $key_a )['dispatch'], 'azure without endpoint does not evidence dispatch' );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array( 'type' => 'azure', 'enabled' => true, 'api_key' => '', 'endpoint' => 'https://a.example' ) ) ), $priced );
	check( false === $SCM::resolve_evidence( $key_a )['dispatch'], 'entry without api key does not evidence dispatch' );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array_merge( $azure_a, array( 'enabled' => false ) ) ) ), $priced );
	check( false === $SCM::resolve_evidence( $key_a )['dispatch'], 'disabled entry does not evidence dispatch' );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => $azure_a, $entry_b => $azure_b ) ), $priced );
	$ev = $SCM::resolve_evidence( $key_a );
	check( true === $ev['dispatch'] && false === $ev['exclusive'], 'two enabled azure entries: dispatch holds but is not exclusive' );
	$amb = $SCM::build_contracts( array( $key_a => 'GPT' ), array( $key_a => $one_row ), array( $SCM, 'resolve_evidence' ) );
	check( false === $amb[0]['eligible'] && 'dispatch_entry_ambiguous' === $amb[0]['disabled_reason'], 'two enabled entries yield dispatch_entry_ambiguous' );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_b => $azure_b ) ), $priced );
	$ev = $SCM::resolve_evidence( $key_a );
	check( false === $ev['exclusive'], 'compound key whose entry is not the sole enabled entry is not exclusive' );
	$ev = $SCM::resolve_evidence( $key_b );
	check( true === $ev['dispatch'] && true === $ev['exclusive'] && false === $ev['pricing'], 'sole entry without a price row for it is exclusive but unpriced' );

	fx( array( 'providers' => $providers, 'entries' => array( 'hf-1' => array( 'type' => 'huggingface_spaces', 'enabled' => true, 'api_key' => 'secret-hf', 'endpoint' => 'https://hf.example' ) ) ), array( array( 'model' => 'space', 'entry_id' => 'hf-1', 'input' => 1, 'output' => 1 ) ) );
	$ev = $SCM::resolve_evidence( 'hf-1::space' );
	check( false === $ev['dispatch'] && false === $ev['exclusive'] && 'huggingface_spaces' === $ev['provider'], 'provider without chat support (huggingface_spaces) never evidences dispatch' );

	fx( array( 'providers' => array(), 'entries' => array( $entry_a => $azure_a ) ), $priced );
	check( false === $SCM::resolve_evidence( $key_a )['dispatch'], 'unregistered provider type never evidences dispatch' );

	fx( array( 'providers' => $providers, 'entries' => array( 'br-1' => array( 'type' => 'ai_bridge', 'enabled' => true ) ) ), array( array( 'model' => 'gpt-x', 'entry_id' => 'br-1', 'input' => 1, 'output' => 1 ) ) );
	$ev = $SCM::resolve_evidence( 'br-1::gpt-x' );
	check( false === $ev['dispatch'] && false === $ev['exclusive'] && 'ai_bridge' === $ev['provider'], 'ai_bridge provider is never dispatch evidence' );
	check( array() === $SCM::build_contracts( array( 'br-1::gpt-x' => 'Relay GPT' ), array( 'br-1::gpt-x' => $one_row ), array( $SCM, 'resolve_evidence' ) ), 'non-prefixed model under an ai_bridge entry is not published at all' );

	// Keyless enabled entry listed first plus a keyed entry: get_entry_by_type and get_all_entries_for_type disagree => ambiguous.
	fx( array( 'providers' => $providers, 'entries' => array( $entry_b => array( 'type' => 'azure', 'enabled' => true, 'api_key' => '', 'endpoint' => 'https://b.example', 'free_pass_through' => true ), $entry_a => $azure_a ) ), array() );
	$ev = $SCM::resolve_evidence( $key_a );
	check( false === $ev['exclusive'] && false === $ev['pricing'], 'keyless enabled entry listed first plus keyed entry is ambiguous and free_pass_through is not trusted' );

	// Single keyless azure entry: no dispatch.
	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array( 'type' => 'azure', 'enabled' => true, 'api_key' => '', 'endpoint' => 'https://a.example' ) ) ), $priced );
	$ev = $SCM::resolve_evidence( $key_a );
	check( false === $ev['dispatch'], 'single keyless azure entry does not evidence dispatch' );

	// Plain (non-compound) key.
	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array( 'type' => 'openai', 'enabled' => true, 'api_key' => 'secret-o' ) ), 'model_provider' => array( 'gpt-4.1-mini' => 'openai' ) ), array( array( 'model' => 'gpt-4.1-mini', 'entry_id' => $entry_a, 'input' => 5, 'output' => 5 ) ) );
	$ev = $SCM::resolve_evidence( 'gpt-4.1-mini' );
	check( true === $ev['dispatch'] && true === $ev['exclusive'] && true === $ev['pricing'], 'plain key with a sole openai entry and a price row is proven' );

	// Codex authenticates through OAuth.
	fx( array( 'providers' => array( 'codex' => true ), 'entries' => array( 'cx-1' => array( 'type' => 'codex', 'enabled' => true ) ), 'codex' => false ), array() );
	check( false === $SCM::resolve_evidence( 'cx-1::gpt-5-codex' )['dispatch'], 'codex entry without OAuth connection does not evidence dispatch' );
	fx( array( 'providers' => array( 'codex' => true ), 'entries' => array( 'cx-1' => array( 'type' => 'codex', 'enabled' => true ) ), 'codex' => true ), array() );
	check( true === $SCM::resolve_evidence( 'cx-1::gpt-5-codex' )['dispatch'], 'connected codex entry evidences dispatch' );

	// 9. free_pass_through is valid pricing evidence, read from the sole entry only.
	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array_merge( $azure_a, array( 'free_pass_through' => true ) ) ) ), array() );
	$ev = $SCM::resolve_evidence( $key_a );
	check( true === $ev['pricing'] && true === $ev['exclusive'], 'free_pass_through on the sole entry proves pricing without a cost-matrix row' );
	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array_merge( $azure_a, array( 'free_pass_through' => true ) ), $entry_b => $azure_b ) ), array() );
	$ev = $SCM::resolve_evidence( $key_a );
	check( false === $ev['pricing'] && false === $ev['exclusive'], 'free_pass_through is ignored when the dispatch entry is ambiguous' );

	fx( array( 'providers' => $providers, 'entries' => array( $entry_a => array( 'type' => 'openai', 'enabled' => true, 'api_key' => 'secret-o', 'free_pass_through' => true ) ), 'model_provider' => array( 'gpt-4.1-mini' => 'openai' ) ), array() );
	$ev = $SCM::resolve_evidence( 'gpt-4.1-mini' );
	check( true === $ev['dispatch'] && true === $ev['exclusive'] && true === $ev['pricing'], 'free_pass_through on the sole entry proves pricing for a plain key without a price row' );

	// 10. has_explicit_pricing.
	fx( array(), array() );
	check( false === $SCM::has_explicit_pricing( $key_a ), 'no cost-matrix row: no explicit pricing' );
	fx( array(), array( array( 'model' => 'gpt-5.6-luna', 'entry_id' => $entry_a, 'input' => 100, 'output' => 0 ) ) );
	check( false === $SCM::has_explicit_pricing( $key_a ), 'row with output 0: no explicit pricing' );
	fx( array(), array( array( 'model' => 'gpt-5.6-luna', 'entry_id' => $entry_b, 'input' => 100, 'output' => 400 ) ) );
	check( false === $SCM::has_explicit_pricing( $key_a ), 'row for another entry does not price this entry' );
	check( true === $SCM::has_explicit_pricing( $key_b ), 'row for the named entry prices the compound key' );
	check( true === $SCM::has_explicit_pricing( 'gpt-5.6-luna' ), 'plain key matches a row for the model' );
	fx( array(), array( array( 'model' => 'gpt-5.6-luna', 'entry_id' => $entry_a, 'input' => 100, 'output' => 400 ) ) );
	check( true === $SCM::has_explicit_pricing( $key_a ), 'row with positive input and output prices the compound key' );

	// 11. Filter output validation (fail closed).
	$good = array(
		'gateway_model_key' => $azure_key, 'label' => 'gpt-5.6-luna (Azure / Prod)', 'provider' => 'azure', 'transport' => 'sync_chat', 'chat_contract_version' => '1.0.0',
		'eligible' => true, 'disabled_reason' => null,
		'admin_enabled' => true, 'server_dispatch_evidenced' => true, 'dispatch_entry_exclusive' => true, 'pricing_evidenced' => true,
		'requires_browser_pairing' => false, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ),
		'structured_output' => false, 'provider_cancel' => false, 'provider_resume' => false, 'text_cost_estimate' => false,
	);
	$built = array( $good );
	check( array() === $SCM::validate_contracts( 'nope', $built ), 'non-array filter output publishes nothing' );
	check( array() === $SCM::validate_contracts( null, $built ), 'null filter output publishes nothing' );
	check( array( $good ) === $SCM::validate_contracts( array( $good ), $built ), 'a fully evidenced eligible contract passes unchanged' );
	$string_eligible = $SCM::validate_contracts( array( array_merge( $good, array( 'eligible' => 'false' ) ) ), $built );
	check( false === $string_eligible[0]['eligible'] && 'filter_invalid' === $string_eligible[0]['disabled_reason'], "eligible:'false' (string) is forced ineligible with filter_invalid" );
	$no_evidence = array();
	foreach ( array( array( 'pricing_evidenced' => false ), array( 'dispatch_entry_exclusive' => 1 ), array( 'admin_enabled' => false ), array( 'server_dispatch_evidenced' => null ) ) as $override ) {
		$no_evidence = array_merge( $no_evidence, $SCM::validate_contracts( array( array_merge( $good, $override ) ), $built ) );
	}
	check( 4 === count( $no_evidence ) && array( false ) === array_values( array_unique( array_column( $no_evidence, 'eligible' ) ) ) && array( 'filter_invalid' ) === array_values( array_unique( array_column( $no_evidence, 'disabled_reason' ) ) ), 'eligible true without strict evidence is forced ineligible with filter_invalid' );
	$no_lists = array_merge( $SCM::validate_contracts( array( array_merge( $good, array( 'supported_languages' => array() ) ) ), $built ), $SCM::validate_contracts( array( array_merge( $good, array( 'operation_slugs' => 'story_create' ) ) ), $built ) );
	check( 2 === count( $no_lists ) && false === $no_lists[0]['eligible'] && false === $no_lists[1]['eligible'], 'empty or non-array languages/operation slugs are forced ineligible' );
	$kept_reason = $SCM::validate_contracts( array( array_merge( $good, array( 'eligible' => false, 'disabled_reason' => 'pricing_missing' ) ) ), $built );
	check( 'pricing_missing' === $kept_reason[0]['disabled_reason'], 'an existing disabled_reason is kept' );
	$flags = $SCM::validate_contracts( array( array_merge( $good, array( 'requires_browser_pairing' => true, 'structured_output' => true, 'provider_cancel' => true, 'provider_resume' => true, 'text_cost_estimate' => true ) ) ), $built );
	check( false === $flags[0]['requires_browser_pairing'] && false === $flags[0]['structured_output'] && false === $flags[0]['provider_cancel'] && false === $flags[0]['provider_resume'] && false === $flags[0]['text_cost_estimate'], 'capability flags a filter may not grant are forced to false' );
	$dirty = array(
		5   => $good,
		'x' => 'string',
		array( 'transport' => 'sync_chat' ),
		array( 'gateway_model_key' => 123, 'transport' => 'sync_chat' ),
		array( 'gateway_model_key' => $openai_key, 'transport' => 'direct_image' ),
		array( 'gateway_model_key' => 'model-relay:codex:auto', 'transport' => 'sync_chat' ),
		array( 'gateway_model_key' => 'local-asr', 'transport' => 'sync_chat' ),
	);
	check( array( $good ) === $SCM::validate_contracts( $dirty, $built ), 'invalid, non-sync_chat and relay entries are dropped and the list is reindexed' );
	check( array() === $SCM::validate_contracts( array(), $built ), 'a filter may legitimately hide every contract' );

	// 11b. Filter provenance, relay providers, unknown fields, stale reasons.
	$added = $SCM::validate_contracts( array( $good, array_merge( $good, array( 'gateway_model_key' => $openai_key ) ) ), $built );
	check( array( $good ) === $added, 'a filter-added contract whose key was not built is dropped' );
	check( array() === $SCM::validate_contracts( array( $good ), array() ), 'without built contracts nothing is published' );
	check( array() === $SCM::validate_contracts( array( array_merge( $good, array( 'provider' => 'ai_bridge' ) ) ), $built ), 'a contract whose provider is a relay is dropped' );
	check( array() === $SCM::validate_contracts( array( array_merge( $good, array( 'provider' => 'codex_local' ) ) ), $built ), 'a codex_local provider is dropped' );
	$extra = $SCM::validate_contracts( array( array_merge( $good, array( 'api_key' => 'sk-secret', 'endpoint' => 'https://x.example', 'zzz' => 1 ) ) ), $built );
	check( array( $good ) === $extra && ! array_key_exists( 'api_key', $extra[0] ) && ! array_key_exists( 'endpoint', $extra[0] ), 'unknown fields such as api_key and endpoint are discarded' );
	check( array_keys( $extra[0] ) === $SCM::CONTRACT_FIELDS, 'output carries exactly the documented fields in documented order' );
	$stale = $SCM::validate_contracts( array( array_merge( $good, array( 'disabled_reason' => 'pricing_missing' ) ) ), $built );
	check( true === $stale[0]['eligible'] && null === $stale[0]['disabled_reason'], 'an eligible proven contract with a stale disabled_reason gets null' );
	$partial = $good;
	unset( $partial['label'], $partial['provider'] );
	$filled = $SCM::validate_contracts( array( $partial ), $built );
	check( array( $good ) === $filled, 'missing documented fields fall back to the built values' );
	$GLOBALS['alorbach_test_filters']['alorbach_server_chat_model_contracts'] = static function ( $list ) use ( $good, $openai_key ) {
		return array_merge( $list, array( array_merge( $good, array( 'gateway_model_key' => $openai_key ) ) ) );
	};
	$SCM::update_settings( array( $azure_key => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ) ) );
	fx( array( 'providers' => array( 'azure' => true ), 'entries' => array( '62f21a64-9a37-42f7-bde4-f7627bed9b3b' => array( 'type' => 'azure', 'enabled' => true, 'api_key' => 'k', 'endpoint' => 'https://e' ) ) ), array( array( 'model' => 'gpt-5.6-luna', 'entry_id' => '62f21a64-9a37-42f7-bde4-f7627bed9b3b', 'input' => 1, 'output' => 2 ) ) );
	$live = $SCM::get_integration_model_capabilities( array( $azure_key => 'gpt-5.6-luna (Azure / Prod)', $openai_key => 'gpt-4.1-mini' ) );
	check( 1 === count( $live ) && $azure_key === $live[0]['gateway_model_key'], 'get_integration_model_capabilities drops a filter-added unconfigured key' );
	unset( $GLOBALS['alorbach_test_filters'] );
	$GLOBALS['alorbach_test_options'][ $SCM::OPTION ] = array();

	// 11c. Plan chat catalog visibility.
	check( true === $SCM::is_visible_for_chat_catalog( $azure_key, array( $azure_key ) ), 'compound key is visible when the chat catalog contains it' );
	check( true === $SCM::is_visible_for_chat_catalog( $azure_key, array( '62f21a64-9a37-42f7-bde4-f7627bed9b3b:gpt-5.6-luna' ) ), 'compound key is visible through its single-colon alias' );
	check( false === $SCM::is_visible_for_chat_catalog( $azure_key, array( $openai_key, 'other::gpt-5.6-luna' ) ), 'compound key is hidden when absent from the chat catalog' );
	check( false === $SCM::is_visible_for_chat_catalog( $azure_key, array() ), 'compound key is hidden with an empty chat catalog' );
	check( true === $SCM::is_visible_for_chat_catalog( $openai_key, array( 'x', $openai_key ) ), 'plain key matches exactly' );
	check( false === $SCM::is_visible_for_chat_catalog( $openai_key, array( 'gpt-4.1-mini-2' ) ), 'plain key does not match a different key' );

	// 12. Fixture comparison.
	$fixture_path = __DIR__ . '/fixtures/server-chat-contract.json';
	$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true );
	check( is_array( $fixture ), 'fixture is valid JSON' );
	check( is_array( $fixture ) && $fixture['capabilities']['models'] === $contracts, 'published contracts match tests/fixtures/server-chat-contract.json' );
	check( is_array( $fixture ) && $fixture['capabilities']['server_chat_models'] === $SCM::eligible_keys( $contracts ), 'fixture eligible keys match' );
	check( is_array( $fixture ) && $fixture['capabilities']['server_chat_contract_version'] === $SCM::CONTRACT_VERSION, 'fixture version matches' );

	// Admin save merge: posted rows first, stale stored rows after, string keys.
	$merged = \Alorbach\AIGateway\Server_Chat_Models::merge_for_save(
		array( 'stale::old-model' => array( 'enabled' => true ), 'e::gpt-x' => array( 'enabled' => true ) ),
		array( 'e::gpt-x' => array( 'enabled' => false ), '12345' => array( 'enabled' => true ) )
	);
	check( array( 'e::gpt-x', '12345', 'stale::old-model' ) === array_map( 'strval', array_keys( $merged ) ), 'merge_for_save puts posted rows before stale stored rows' );
	check( false === $merged['e::gpt-x']['enabled'], 'merge_for_save: posted row wins over stored row' );
	$many = array();
	for ( $i = 0; $i < \Alorbach\AIGateway\Server_Chat_Models::MAX_MODELS + 5; $i++ ) {
		$many[ 'stale::m' . $i ] = array( 'enabled' => false );
	}
	$capped = \Alorbach\AIGateway\Server_Chat_Models::sanitize_settings( \Alorbach\AIGateway\Server_Chat_Models::merge_for_save( $many, array( 'cur::model' => array( 'enabled' => true ) ) ) );
	check( \Alorbach\AIGateway\Server_Chat_Models::MAX_MODELS === count( $capped ) && isset( $capped['cur::model'] ), 'cap keeps the posted row and drops stale rows first' );
	// Numeric-only model keys are published as strings.
	$numeric = \Alorbach\AIGateway\Server_Chat_Models::build_contracts(
		array( '12345' => 'Numeric model' ),
		array( '12345' => array( 'enabled' => true, 'supported_languages' => array( 'de-DE' ), 'operation_slugs' => array( 'story_create' ) ) ),
		static function ( $key ) { return array( 'dispatch' => true, 'exclusive' => true, 'pricing' => true, 'provider' => 'openai' ); }
	);
	check( 1 === count( $numeric ) && '12345' === $numeric[0]['gateway_model_key'] && true === $numeric[0]['eligible'], 'numeric-only model key is published as a string and stays eligible' );
	$validated_numeric = \Alorbach\AIGateway\Server_Chat_Models::validate_contracts( $numeric, $numeric ); check( 1 === count( $validated_numeric ) && '12345' === $validated_numeric[0]['gateway_model_key'], 'numeric-only key survives contract validation' );

	if ( $failures > 0 ) {
		fwrite( STDOUT, "\n$failures check(s) failed.\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "\nAll server chat model checks passed.\n" );
}
