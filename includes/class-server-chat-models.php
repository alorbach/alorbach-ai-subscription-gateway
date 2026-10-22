<?php
/**
 * Server-dispatched chat model contracts for downstream integrations.
 *
 * A downstream product that runs AI work without an open browser (a cron job,
 * a server-side pipeline) needs explicit, per-model evidence that the Gateway
 * itself can dispatch a chat request to a provider: no browser pairing, no
 * local relay, complete credentials on the sole enabled entry of the provider
 * type, a price for the model, and an administrator who opted the model in for
 * specific content languages and operation slugs.  Pricing evidence is a
 * cost-matrix row with positive input and output prices (compound key: the row
 * of that model and that entry; plain key: the first row for that model name)
 * or free_pass_through on the sole enabled entry of the type.  The
 * administrator also confirms that the model is a chat model, because the text
 * catalog is built from all cost-matrix rows.  Nothing is inferred from a
 * model name; without administrator configuration no model is published as
 * eligible (fail closed).  "eligible" does not guarantee that a request
 * succeeds: POST /chat still applies login, plan access, balance, rate limit,
 * monthly quota and duplicate checks.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and stores the `sync_chat` capability contracts that are published in
 * `GET /integration/config` under `capabilities.models`.
 */
class Server_Chat_Models {

	/** Semantic version of the published per-model `sync_chat` contract. */
	const CONTRACT_VERSION = '1.0.0';

	/** Downstream transport name (matches the async_image / direct_image vocabulary). */
	const TRANSPORT = 'sync_chat';

	/** Option that stores the administrator configuration, keyed by compound model key. */
	const OPTION = 'alorbach_server_chat_models';

	/** Reasons a published contract is not eligible, in evaluation order. */
	const REASON_DISABLED_BY_ADMIN   = 'disabled_by_admin';
	const REASON_DISPATCH_UNPROVEN   = 'dispatch_credentials_missing';
	const REASON_DISPATCH_AMBIGUOUS  = 'dispatch_entry_ambiguous';
	const REASON_PRICING_UNPROVEN    = 'pricing_missing';
	const REASON_LANGUAGES_MISSING   = 'languages_missing';
	const REASON_OPERATIONS_MISSING  = 'operations_missing';

	/** Set when the `alorbach_server_chat_model_contracts` filter returned a contract without full evidence. */
	const REASON_FILTER_INVALID      = 'filter_invalid';

	/** Provider entry types that never dispatch from the server (browser or local relay). */
	const RELAY_PROVIDER_TYPES = array( 'ai_bridge', 'codex_local' );

	/** Model key prefixes that always need a paired browser or a local tray app (see AI_Bridge). */
	const RELAY_KEY_PREFIXES = array( 'model-relay:', 'codex-local:', 'local-asr:' );

	/** Bare local model ids (no prefix) that are never server dispatchable (AI_Bridge::LOCAL_ASR_MODEL). */
	const RELAY_KEY_EXACT = array( 'local-asr' );

	/** Documented contract fields in published order. */
	const CONTRACT_FIELDS = array(
		'gateway_model_key', 'label', 'provider', 'transport', 'chat_contract_version', 'eligible', 'disabled_reason',
		'admin_enabled', 'server_dispatch_evidenced', 'dispatch_entry_exclusive', 'pricing_evidenced', 'requires_browser_pairing',
		'supported_languages', 'operation_slugs', 'structured_output', 'provider_cancel', 'provider_resume', 'text_cost_estimate',
	);

	const MAX_LANGUAGES  = 20;
	const MAX_OPERATIONS = 200;
	const MAX_MODELS     = 1000;

	/**
	 * Stored administrator configuration, normalized.
	 *
	 * @return array<string,array{enabled:bool,supported_languages:string[],operation_slugs:string[]}>
	 */
	public static function get_settings() {
		return self::sanitize_settings( get_option( self::OPTION, array() ) );
	}

	/**
	 * Persist administrator configuration after normalization.
	 *
	 * @param mixed $raw Raw map (compound key => row) from the settings form.
	 * @return array<string,array{enabled:bool,supported_languages:string[],operation_slugs:string[]}> Stored value.
	 */
	/**
	 * Combine posted rows with stored rows for an admin save.  Posted rows come
	 * first (they are the models shown in the form), stored rows that were not
	 * posted (models that left the catalog) follow, so the MAX_MODELS budget
	 * drops stale rows before current ones.  Keys are kept as strings.
	 *
	 * @param mixed $stored Stored configuration.
	 * @param mixed $posted Posted configuration.
	 * @return array<string,mixed>
	 */
	public static function merge_for_save( $stored, $posted ) {
		$merged = array();
		foreach ( array( $posted, $stored ) as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( $source as $key => $row ) {
				$key = (string) $key;
				if ( ! array_key_exists( $key, $merged ) ) {
					$merged[ $key ] = $row;
				}
			}
		}
		return $merged;
	}

	public static function update_settings( $raw ) {
		$settings = self::sanitize_settings( $raw );
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * Normalize a configuration map.  Unknown keys are dropped, every row is
	 * reduced to the three documented fields, and relay keys are never stored.
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string,array{enabled:bool,supported_languages:string[],operation_slugs:string[]}>
	 */
	public static function sanitize_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$settings = array();
		foreach ( $raw as $key => $row ) {
			if ( count( $settings ) >= self::MAX_MODELS ) {
				break;
			}
			$key = self::sanitize_model_key( $key );
			if ( '' === $key || ! self::is_server_model_key( $key ) || ! is_array( $row ) ) {
				continue;
			}
			$settings[ $key ] = array(
				'enabled'             => ! empty( $row['enabled'] ) && 'false' !== $row['enabled'] && '0' !== $row['enabled'],
				'supported_languages' => self::sanitize_languages( $row['supported_languages'] ?? array() ),
				'operation_slugs'     => self::sanitize_operation_slugs( $row['operation_slugs'] ?? array() ),
			);
		}
		return $settings;
	}

	/**
	 * Build the contracts that are published for the current site.
	 *
	 * @param array<string,string> $text_models Configured text catalog (compound key => label).
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_integration_model_capabilities( $text_models ) {
		$contracts = self::build_contracts( $text_models, self::get_settings(), array( __CLASS__, 'resolve_evidence' ) );

		/**
		 * Filter the published server-dispatched chat contracts.
		 *
		 * The filter may only remove contracts or change documented fields of
		 * contracts the Gateway built.  Keys that were not built, relay providers
		 * and unknown fields are discarded.  The result is validated (fail
		 * closed): a non-array publishes nothing and a contract stays eligible
		 * only with every evidence flag strictly true.
		 *
		 * @param array<int,array<string,mixed>> $contracts   Contracts as built from configuration and evidence.
		 * @param array<string,string>           $text_models Configured text catalog.
		 */
		$filtered = apply_filters( 'alorbach_server_chat_model_contracts', $contracts, $text_models );

		return self::validate_contracts( $filtered, $contracts );
	}

	/**
	 * Validate filter output (fail closed).  A non-array result publishes
	 * nothing.  Only contracts whose gateway_model_key was built by the Gateway
	 * for this call ($built) are kept, once each; entries that are not arrays,
	 * are not sync_chat, carry a relay/local key or a relay provider are
	 * dropped.  Each kept contract is rebuilt with only the documented fields in
	 * documented order (values from the filtered contract, falling back to the
	 * built one); unknown fields are discarded.  A contract stays eligible only
	 * when `eligible` is strictly true, every evidence flag is strictly true and
	 * languages and operation slugs are non-empty; then disabled_reason is null.
	 * Otherwise it is forced ineligible with REASON_FILTER_INVALID (unless a
	 * reason is already set).  Capability flags a filter must not grant are
	 * forced to false.
	 *
	 * @param mixed $filtered Filter result.
	 * @param mixed $built    Contracts built by build_contracts() for this call.
	 * @return array<int,array<string,mixed>>
	 */
	public static function validate_contracts( $filtered, $built = array() ) {
		if ( ! is_array( $filtered ) ) {
			return array();
		}
		$built_by_key = array();
		foreach ( is_array( $built ) ? $built : array() as $built_contract ) {
			if ( is_array( $built_contract ) && isset( $built_contract['gateway_model_key'] ) && is_string( $built_contract['gateway_model_key'] ) ) {
				$built_by_key[ $built_contract['gateway_model_key'] ] = $built_contract;
			}
		}
		$valid = array();
		$seen  = array();
		foreach ( $filtered as $contract ) {
			if ( ! is_array( $contract )
				|| ! isset( $contract['gateway_model_key'] ) || ! is_string( $contract['gateway_model_key'] )
				|| self::TRANSPORT !== ( isset( $contract['transport'] ) ? $contract['transport'] : '' )
				|| ! self::is_server_model_key( $contract['gateway_model_key'] )
				|| ! isset( $built_by_key[ $contract['gateway_model_key'] ] )
				|| isset( $seen[ $contract['gateway_model_key'] ] ) ) {
				continue;
			}
			$origin = $built_by_key[ $contract['gateway_model_key'] ];
			$merged = array();
			foreach ( self::CONTRACT_FIELDS as $field ) {
				if ( array_key_exists( $field, $contract ) ) {
					$merged[ $field ] = $contract[ $field ];
				} else {
					$merged[ $field ] = array_key_exists( $field, $origin ) ? $origin[ $field ] : null;
				}
			}
			$merged['gateway_model_key']     = $contract['gateway_model_key'];
			$merged['transport']             = self::TRANSPORT;
			$merged['chat_contract_version'] = self::CONTRACT_VERSION;
			$merged['label']                 = sanitize_text_field( is_string( $merged['label'] ) ? $merged['label'] : '' );
			$merged['provider']              = is_string( $merged['provider'] ) ? $merged['provider'] : '';
			if ( self::is_relay_provider( $merged['provider'] ) ) {
				continue;
			}
			$merged['supported_languages'] = is_array( $merged['supported_languages'] ) ? self::sanitize_languages( $merged['supported_languages'] ) : array();
			$merged['operation_slugs']     = is_array( $merged['operation_slugs'] ) ? self::sanitize_operation_slugs( $merged['operation_slugs'] ) : array();
			$proven = true === $merged['eligible']
				&& true === $merged['admin_enabled']
				&& true === $merged['server_dispatch_evidenced']
				&& true === $merged['dispatch_entry_exclusive']
				&& true === $merged['pricing_evidenced']
				&& ! empty( $merged['supported_languages'] )
				&& ! empty( $merged['operation_slugs'] );
			if ( $proven ) {
				$merged['disabled_reason'] = null;
			} else {
				$merged['eligible'] = false;
				if ( ! is_string( $merged['disabled_reason'] ) || '' === $merged['disabled_reason'] ) {
					$merged['disabled_reason'] = self::REASON_FILTER_INVALID;
				}
			}
			foreach ( array( 'requires_browser_pairing', 'structured_output', 'provider_cancel', 'provider_resume', 'text_cost_estimate' ) as $flag ) {
				$merged[ $flag ] = false;
			}
			foreach ( array( 'admin_enabled', 'server_dispatch_evidenced', 'dispatch_entry_exclusive', 'pricing_evidenced' ) as $flag ) {
				$merged[ $flag ] = true === $merged[ $flag ];
			}
			$seen[ $merged['gateway_model_key'] ] = true;
			$valid[]                              = $merged;
		}
		return array_values( $valid );
	}

	/**
	 * Whether a server chat contract is visible to a plan: its exact compound
	 * key, or its single-colon alias, is in the plan-filtered chat catalog.
	 *
	 * @param string   $contract_key Canonical compound or plain model key.
	 * @param string[] $chat_models  Plan-filtered chat catalog keys.
	 * @return bool
	 */
	public static function is_visible_for_chat_catalog( $contract_key, $chat_models ) {
		$aliases = Cost_Matrix::gateway_model_key_aliases( (string) $contract_key );
		foreach ( (array) $chat_models as $selected_key ) {
			if ( in_array( (string) $selected_key, $aliases, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a provider entry type is a browser or local relay.
	 *
	 * @param mixed $provider Provider type.
	 * @return bool
	 */
	public static function is_relay_provider( $provider ) {
		return in_array( (string) $provider, self::RELAY_PROVIDER_TYPES, true );
	}

	/**
	 * Pure contract builder.  Only models that an administrator configured are
	 * published; everything else stays out of the payload.  Eligibility needs
	 * the administrator switch plus every piece of evidence at the same time.
	 *
	 * @param array<string,string>                                                        $text_models Configured text catalog.
	 * @param array<string,array{enabled:bool,supported_languages:string[],operation_slugs:string[]}> $settings Normalized configuration.
	 * @param callable                                                                    $evidence    fn(string $key): array{dispatch:bool,exclusive?:bool,pricing:bool,provider:string}.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_contracts( $text_models, $settings, $evidence ) {
		$text_models = is_array( $text_models ) ? $text_models : array();
		$settings    = self::sanitize_settings( $settings );
		$contracts   = array();
		foreach ( $settings as $key => $row ) {
			$key = (string) $key; // PHP turns numeric-only array keys into integers.
			if ( ! array_key_exists( $key, $text_models ) ) {
				continue;
			}
			$proof    = call_user_func( $evidence, $key );
			$proof    = is_array( $proof ) ? $proof : array();
			$dispatch  = ! empty( $proof['dispatch'] );
			$exclusive = ! empty( $proof['exclusive'] );
			$pricing   = ! empty( $proof['pricing'] );
			$provider = self::sanitize_model_key( (string) ( $proof['provider'] ?? '' ) );
			if ( self::is_relay_provider( $provider ) ) {
				continue; // Relay-backed models are never published.
			}

			$reason = null;
			if ( empty( $row['enabled'] ) ) {
				$reason = self::REASON_DISABLED_BY_ADMIN;
			} elseif ( ! $dispatch ) {
				$reason = self::REASON_DISPATCH_UNPROVEN;
			} elseif ( ! $exclusive ) {
				$reason = self::REASON_DISPATCH_AMBIGUOUS;
			} elseif ( ! $pricing ) {
				$reason = self::REASON_PRICING_UNPROVEN;
			} elseif ( empty( $row['supported_languages'] ) ) {
				$reason = self::REASON_LANGUAGES_MISSING;
			} elseif ( empty( $row['operation_slugs'] ) ) {
				$reason = self::REASON_OPERATIONS_MISSING;
			}

			$contracts[] = array(
				'gateway_model_key'          => $key,
				'label'                      => sanitize_text_field( (string) $text_models[ $key ] ),
				'provider'                   => $provider,
				'transport'                  => self::TRANSPORT,
				'chat_contract_version'      => self::CONTRACT_VERSION,
				'eligible'                   => null === $reason,
				'disabled_reason'            => $reason,
				'admin_enabled'              => ! empty( $row['enabled'] ),
				'server_dispatch_evidenced'  => $dispatch,
				'dispatch_entry_exclusive'   => $exclusive,
				'pricing_evidenced'          => $pricing,
				'requires_browser_pairing'   => false,
				'supported_languages'        => array_values( $row['supported_languages'] ),
				'operation_slugs'            => array_values( $row['operation_slugs'] ),
				'structured_output'          => false,
				'provider_cancel'            => false,
				'provider_resume'            => false,
				'text_cost_estimate'         => false,
			);
		}
		return $contracts;
	}

	/**
	 * Compound keys of every eligible contract.
	 *
	 * @param array<int,array<string,mixed>> $contracts Published contracts.
	 * @return string[]
	 */
	public static function eligible_keys( $contracts ) {
		$keys = array();
		foreach ( (array) $contracts as $contract ) {
			if ( is_array( $contract ) && self::TRANSPORT === ( $contract['transport'] ?? '' ) && ! empty( $contract['eligible'] ) && is_string( $contract['gateway_model_key'] ?? null ) ) {
				$keys[] = $contract['gateway_model_key'];
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Whether a configured text key can ever be dispatched by the server.
	 * Relay and local keys are rejected by prefix on both the full key and the
	 * plain model part of a compound key ("entry::model-relay:codex:auto").
	 *
	 * @param string $key Compound or plain model key.
	 * @return bool
	 */
	public static function is_server_model_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return false;
		}
		$parsed = Cost_Matrix::parse_model_key( $key );
		if ( in_array( $key, self::RELAY_KEY_EXACT, true ) || in_array( (string) $parsed['model'], self::RELAY_KEY_EXACT, true ) ) {
			return false;
		}
		foreach ( self::RELAY_KEY_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) || 0 === strpos( (string) $parsed['model'], $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Server-capable text models from a configured catalog (relay keys removed).
	 *
	 * @param array<string,string> $text_models Configured text catalog.
	 * @return array<string,string>
	 */
	public static function filter_server_models( $text_models ) {
		$result = array();
		foreach ( (array) $text_models as $key => $label ) {
			if ( self::is_server_model_key( (string) $key ) ) {
				$result[ (string) $key ] = (string) $label;
			}
		}
		return $result;
	}

	/**
	 * Resolve dispatch and pricing evidence for one configured model from the
	 * live site configuration.  Never returns credentials.
	 *
	 * Dispatch is evidenced only when the provider type is registered and
	 * supports chat, and the credentials of the entry that serves the key are
	 * complete.  `POST /chat` reads free_pass_through from the first enabled
	 * entry of the type (keyed or not) while API_Client::chat() walks the enabled
	 * entries that have an API key.  A contract is therefore only exclusive when
	 * exactly one enabled entry of the type exists, keyed or not (and, for a
	 * compound key, that entry is the one named by the key).  Pricing is an
	 * explicit cost-matrix row, or free_pass_through on that sole entry.
	 *
	 * @param string $key Compound or plain model key.
	 * @return array{dispatch:bool,exclusive:bool,pricing:bool,provider:string}
	 */
	public static function resolve_evidence( $key ) {
		$key      = (string) $key;
		$parsed   = Cost_Matrix::parse_model_key( $key );
		$entry_id = (string) $parsed['entry_id'];
		$provider = (string) API_Client::get_provider_for_model( $key );
		$none     = array( 'dispatch' => false, 'exclusive' => false, 'pricing' => false, 'provider' => $provider );

		if ( ! self::is_server_model_key( $key ) || self::is_relay_provider( $provider ) ) {
			return $none;
		}

		// The chat dispatcher must exist and support chat for this provider type.
		$handler = '' !== $provider ? Providers\Provider_Registry::get( $provider ) : null;
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'supports_chat' ) || ! $handler->supports_chat() ) {
			return $none;
		}

		// Every enabled entry of the type counts, keyed or not: get_entry_by_type()
		// (free_pass_through in POST /chat) and get_all_entries_for_type() (dispatch)
		// pick differently, so only a single enabled entry is unambiguous.
		$enabled = array();
		foreach ( (array) API_Keys_Helper::get_entries() as $candidate ) {
			if ( is_array( $candidate ) && (string) ( $candidate['type'] ?? '' ) === $provider && ! empty( $candidate['enabled'] ) ) {
				$enabled[] = $candidate;
			}
		}
		$sole      = 1 === count( $enabled ) ? $enabled[0] : null;
		$sole_id   = is_array( $sole ) ? (string) ( $sole['id'] ?? '' ) : '';
		$exclusive = is_array( $sole ) && '' !== $sole_id && ( '' === $entry_id || $sole_id === $entry_id );

		$dispatch = false;
		$pricing  = false;
		if ( $exclusive ) {
			$credentials = API_Keys_Helper::get_credentials_for_entry( $sole_id );
			$dispatch    = is_array( $credentials ) && self::credentials_allow_dispatch( $provider, $credentials );
			$pricing     = is_array( $credentials ) && ! empty( $credentials['free_pass_through'] );
		} elseif ( '' !== $entry_id ) {
			$entry       = API_Keys_Helper::get_entry_by_id( $entry_id );
			$credentials = is_array( $entry ) ? API_Keys_Helper::get_credentials_for_entry( $entry_id ) : null;
			$dispatch    = is_array( $entry )
				&& ! empty( $entry['enabled'] )
				&& (string) ( $entry['type'] ?? '' ) === $provider
				&& is_array( $credentials )
				&& self::credentials_allow_dispatch( $provider, $credentials );
		} else {
			foreach ( $enabled as $candidate ) {
				$credentials = API_Keys_Helper::get_credentials_for_entry( (string) ( $candidate['id'] ?? '' ) );
				if ( is_array( $credentials ) && self::credentials_allow_dispatch( $provider, $credentials ) ) {
					$dispatch = true;
					break;
				}
			}
		}
		if ( ! $pricing ) {
			$pricing = self::has_explicit_pricing( $key );
		}

		return array( 'dispatch' => $dispatch, 'exclusive' => $exclusive, 'pricing' => $pricing, 'provider' => $provider );
	}

	/**
	 * A provider entry can dispatch when its credential set is complete for its type.
	 *
	 * @param string $provider    Provider type.
	 * @param array  $credentials Credentials from API_Keys_Helper (never exposed).
	 * @return bool
	 */
	private static function credentials_allow_dispatch( $provider, $credentials ) {
		if ( 'codex' === $provider ) {
			// Codex authenticates through OAuth; the entry itself carries no API key.
			return class_exists( __NAMESPACE__ . '\\Codex_OAuth' ) && method_exists( __NAMESPACE__ . '\\Codex_OAuth', 'is_connected' )
				? (bool) Codex_OAuth::is_connected()
				: false;
		}
		if ( '' === trim( (string) ( $credentials['api_key'] ?? '' ) ) ) {
			return false;
		}
		if ( 'azure' === $provider ) {
			return '' !== trim( (string) ( $credentials['endpoint'] ?? '' ) );
		}
		return true;
	}

	/**
	 * Explicit cost-matrix pricing: a row for this model (and, for a compound
	 * key, for exactly this entry) with positive input and output prices.
	 * Catalog defaults are not evidence.  This is one of two accepted pricing
	 * proofs; resolve_evidence() also accepts free_pass_through on the sole
	 * dispatch entry.
	 *
	 * @param string $key Compound or plain model key.
	 * @return bool
	 */
	public static function has_explicit_pricing( $key ) {
		$parsed   = Cost_Matrix::parse_model_key( $key );
		$model_id = (string) $parsed['model'];
		$entry_id = (string) $parsed['entry_id'];
		$matrix   = Cost_Matrix::get_cost_matrix();
		$rows     = isset( $matrix['models'] ) && is_array( $matrix['models'] ) ? $matrix['models'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || (string) ( $row['model'] ?? '' ) !== $model_id ) {
				continue;
			}
			if ( '' !== $entry_id && (string) ( $row['entry_id'] ?? '' ) !== $entry_id ) {
				continue;
			}
			return (int) ( $row['input'] ?? 0 ) > 0 && (int) ( $row['output'] ?? 0 ) > 0;
		}
		return false;
	}

	/**
	 * @param mixed $key Raw key.
	 * @return string
	 */
	private static function sanitize_model_key( $key ) {
		$key = is_string( $key ) || is_int( $key ) ? trim( (string) $key ) : '';
		if ( '' === $key || strlen( $key ) > 255 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\\/-]*$/', $key ) ) {
			return '';
		}
		return $key;
	}

	/**
	 * BCP-47 content locales such as de-DE or en-US (case is normalized, so
	 * "en-us" becomes "en-US"); anything else is dropped.
	 *
	 * @param mixed $languages List or comma/newline separated string.
	 * @return string[]
	 */
	public static function sanitize_languages( $languages ) {
		$result = array();
		foreach ( self::split_list( $languages ) as $language ) {
			if ( 1 === preg_match( '/^([A-Za-z]{2,3})-([A-Za-z]{2})$/', $language, $parts ) ) {
				$language = strtolower( $parts[1] ) . '-' . strtoupper( $parts[2] );
			}
			if ( 1 !== preg_match( '/^[a-z]{2,3}-[A-Z]{2}$/', $language ) || in_array( $language, $result, true ) ) {
				continue;
			}
			$result[] = $language;
			if ( count( $result ) >= self::MAX_LANGUAGES ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Downstream operation slugs: lowercase snake_case, 3 to 80 characters.
	 *
	 * @param mixed $slugs List or comma/newline separated string.
	 * @return string[]
	 */
	public static function sanitize_operation_slugs( $slugs ) {
		$result = array();
		foreach ( self::split_list( $slugs ) as $slug ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,79}$/', $slug ) || in_array( $slug, $result, true ) ) {
				continue;
			}
			$result[] = $slug;
			if ( count( $result ) >= self::MAX_OPERATIONS ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * @param mixed $value List or separated string.
	 * @return string[]
	 */
	private static function split_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$items = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) ) {
				$item = trim( $item );
				if ( '' !== $item ) {
					$items[] = $item;
				}
			}
		}
		return $items;
	}
}
