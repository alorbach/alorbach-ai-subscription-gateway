<?php
/**
 * API Keys helper: entries management and migration from legacy format.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Keys_Helper
 */
class API_Keys_Helper {

	/**
	 * Get normalized entries array. Runs migration if legacy format detected.
	 *
	 * @return array<int, array{id: string, type: string, api_key: string, enabled: bool, name?: string, endpoint?: string, org?: string, free_pass_through?: bool}>
	 */
	public static function get_entries() {
		$raw = get_option( 'alorbach_api_keys', array() );
		$raw = is_array( $raw ) ? $raw : array();

		if ( isset( $raw['entries'] ) && is_array( $raw['entries'] ) ) {
			$normalized = self::normalize_entries( $raw['entries'] );
			// Persist generated IDs for entries that had empty id.
			$needs_save = false;
			foreach ( $raw['entries'] as $e ) {
				if ( ! is_array( $e ) ) {
					continue;
				}
				$raw_id = isset( $e['id'] ) ? (string) $e['id'] : '';
				if ( $raw_id === '' ) {
					$needs_save = true;
					break;
				}
			}
			if ( $needs_save ) {
				self::save_entries( $normalized );
			}
			return $normalized;
		}

		// Migrate from legacy format.
		$entries = self::migrate_from_legacy( $raw );
		if ( ! empty( $entries ) ) {
			self::save_entries( $entries );
		}
		return self::normalize_entries( $entries );
	}

	/**
	 * Save entries to option.
	 *
	 * @param array<int, array{id?: string, type: string, api_key: string, enabled?: bool, name?: string, endpoint?: string, org?: string, free_pass_through?: bool}> $entries Entries to save.
	 */
	public static function save_entries( $entries ) {
		$normalized = self::normalize_entries( $entries );
		update_option( 'alorbach_api_keys', array( 'entries' => $normalized ) );
	}

	/**
	 * Get first enabled entry of given type.
	 *
	 * @param string $type Provider type: openai, azure, google, github_models.
	 * @return array|null Entry or null.
	 */
	public static function get_entry_by_type( $type ) {
		$entries = self::get_entries();
		foreach ( $entries as $entry ) {
			if ( ( $entry['type'] ?? '' ) === $type && ! empty( $entry['enabled'] ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Get entry by ID.
	 *
	 * @param string $id Entry ID (UUID).
	 * @return array|null Entry or null.
	 */
	public static function get_entry_by_id( $id ) {
		if ( empty( $id ) ) {
			return null;
		}
		$entries = self::get_entries();
		foreach ( $entries as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Get all enabled entries for a given provider type.
	 *
	 * @param string $type Provider type: openai, azure, google, github_models.
	 * @return array<int, array> All enabled entries with a non-empty api_key.
	 */
	public static function get_all_entries_for_type( $type ) {
		$entries = self::get_entries();
		$result  = array();
		foreach ( $entries as $entry ) {
			if ( ( $entry['type'] ?? '' ) === $type && ! empty( $entry['enabled'] ) && ! empty( $entry['api_key'] ) ) {
				$result[] = $entry;
			}
		}
		return $result;
	}

	/**
	 * Get credentials for a provider (first enabled entry of that type).
	 *
	 * @param string $type Provider type: openai, azure, google, github_models.
	 * @return array{api_key: string, endpoint?: string, org?: string, free_pass_through?: bool}|null Credentials or null if not configured.
	 */
	public static function get_credentials_for_provider( $type ) {
		$entry = self::get_entry_by_type( $type );
		return $entry ? self::entry_to_credentials( $entry ) : null;
	}

	/**
	 * Get credentials for a specific entry by ID.
	 *
	 * @param string $entry_id Entry ID (UUID).
	 * @return array{api_key: string, endpoint?: string, org?: string, free_pass_through?: bool}|null Credentials or null.
	 */
	public static function get_credentials_for_entry( $entry_id ) {
		$entry = self::get_entry_by_id( $entry_id );
		if ( ! $entry || empty( $entry['api_key'] ) ) {
			return null;
		}
		return self::entry_to_credentials( $entry );
	}

	/**
	 * Convert entry array to credentials array.
	 *
	 * @param array $entry Entry with api_key, endpoint, org, free_pass_through.
	 * @return array{api_key: string, endpoint?: string, org?: string, free_pass_through?: bool}|null
	 */
	private static function entry_to_credentials( $entry ) {
		if ( empty( $entry['api_key'] ) ) {
			return null;
		}
		$creds = array( 'api_key' => $entry['api_key'] );
		if ( ! empty( $entry['endpoint'] ) ) {
			$creds['endpoint'] = $entry['endpoint'];
		}
		if ( isset( $entry['org'] ) ) {
			$creds['org'] = $entry['org'];
		}
		if ( ! empty( $entry['free_pass_through'] ) ) {
			$creds['free_pass_through'] = true;
		}
		return $creds;
	}

	/**
	 * Check if a provider type has at least one enabled entry with valid key.
	 *
	 * @param string $type Provider type.
	 * @return bool
	 */
	public static function has_provider( $type ) {
		$creds = self::get_credentials_for_provider( $type );
		if ( ! $creds ) {
			return false;
		}
		if ( $type === 'azure' ) {
			return ! empty( $creds['endpoint'] );
		}
		return true;
	}

	/**
	 * Migrate legacy flat array to entries format.
	 *
	 * @param array<string, string> $legacy Legacy keys (openai, azure, azure_endpoint, google).
	 * @return array<int, array>
	 */
	private static function migrate_from_legacy( $legacy ) {
		$entries = array();

		if ( ! empty( $legacy['openai'] ) ) {
			$entries[] = array(
				'id'      => self::generate_id(),
				'type'    => 'openai',
				'api_key' => $legacy['openai'],
				'enabled'  => true,
			);
		}
		if ( ! empty( $legacy['azure'] ) && ! empty( $legacy['azure_endpoint'] ) ) {
			$entries[] = array(
				'id'       => self::generate_id(),
				'type'     => 'azure',
				'api_key'  => $legacy['azure'],
				'endpoint' => $legacy['azure_endpoint'],
				'enabled'  => true,
			);
		}
		if ( ! empty( $legacy['google'] ) ) {
			$entries[] = array(
				'id'      => self::generate_id(),
				'type'    => 'google',
				'api_key' => $legacy['google'],
				'enabled' => true,
			);
		}
		return $entries;
	}

	/**
	 * Normalize entries: ensure id, type, api_key, enabled; filter invalid.
	 *
	 * @param array $entries Raw entries.
	 * @return array<int, array>
	 */
	private static function normalize_entries( $entries ) {
		$out = array();
		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) || empty( $e['type'] ) ) {
				continue;
			}
			$id = isset( $e['id'] ) && (string) $e['id'] !== '' ? (string) $e['id'] : self::generate_id();
			$entry = array(
				'id'      => $id,
				'type'    => sanitize_text_field( $e['type'] ),
				'api_key' => isset( $e['api_key'] ) ? (string) $e['api_key'] : '',
				'enabled' => ! empty( $e['enabled'] ),
			);
			if ( isset( $e['name'] ) ) {
				$entry['name'] = sanitize_text_field( $e['name'] );
			}
			if ( isset( $e['endpoint'] ) ) {
				$entry['endpoint'] = esc_url_raw( $e['endpoint'] );
			}
			if ( isset( $e['org'] ) ) {
				$entry['org'] = sanitize_text_field( $e['org'] );
			}
			if ( isset( $e['free_pass_through'] ) ) {
				$entry['free_pass_through'] = ! empty( $e['free_pass_through'] );
			}
			$out[] = $entry;
		}
		return array_values( $out );
	}

	/**
	 * Generate a unique ID for an entry.
	 *
	 * @return string
	 */
	private static function generate_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0x0fff ) | 0x4000, wp_rand( 0, 0x3fff ) | 0x8000, wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ) );
	}
}
