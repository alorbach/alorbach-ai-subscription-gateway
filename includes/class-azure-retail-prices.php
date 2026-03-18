<?php
/**
 * Fetch Azure OpenAI pricing from Azure Retail Prices API.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Azure_Retail_Prices
 *
 * Uses the public, unauthenticated Azure Retail Prices API:
 * https://prices.azure.com/api/retail/prices
 */
class Azure_Retail_Prices {

	/**
	 * API base URL.
	 */
	const API_URL = 'https://prices.azure.com/api/retail/prices';

	/**
	 * Cache option key. Stores fetched prices for 24 hours.
	 */
	const CACHE_OPTION = 'alorbach_azure_prices_cache';

	/**
	 * Cache duration in seconds (24 hours).
	 */
	const CACHE_TTL = 86400;

	/**
	 * Fetch Azure OpenAI text model costs (input/output/cached) in UC per 1M tokens.
	 *
	 * @param string $region   Optional. Azure region (e.g. westeurope). Empty = all regions (uses first match).
	 * @param string $currency Optional. Currency code (default USD).
	 * @return array Map of model_base => array( 'input' => int, 'output' => int, 'cached' => int ). Empty on error.
	 */
	public static function fetch_text_costs( $region = '', $currency = 'USD' ) {
		$cache_key = 'text_' . $region . '_' . $currency;
		$cached = self::get_cached( $cache_key );
		if ( $cached !== null ) {
			return $cached;
		}

		$items = self::fetch_openai_items( $region, $currency );
		if ( empty( $items ) ) {
			return array();
		}

		$costs = self::parse_text_costs( $items );
		self::set_cached( $cache_key, $costs );
		return $costs;
	}

	/**
	 * Fetch all Azure OpenAI / Foundry items from the API (paginated).
	 *
	 * @param string $region   Optional. armRegionName filter.
	 * @param string $currency Optional. currencyCode filter.
	 * @return array List of item arrays.
	 */
	private static function fetch_openai_items( $region, $currency ) {
		$all   = array();
		$parts = array(
			"(contains(productName,'OpenAI') or contains(serviceName,'Foundry'))",
		);
		if ( ! empty( $region ) ) {
			$region = preg_replace( '/[^\w\-]/', '', $region );
			$parts[] = "armRegionName eq '$region'";
		}
		if ( ! empty( $currency ) ) {
			$currency = preg_replace( '/[^\w]/', '', $currency );
			$parts[] = "currencyCode eq '$currency'";
		}
		$filter = implode( ' and ', $parts );

		$url    = add_query_arg( array( '$filter' => $filter ), self::API_URL );
		$attempts = 0;
		$max_attempts = 50;

		while ( $url && $attempts < $max_attempts ) {
			$attempts++;
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 30,
					'user-agent' => 'Alorbach-AI-Gateway/1.0',
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				return array();
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || ! isset( $body['Items'] ) ) {
				return array();
			}

			$all = array_merge( $all, $body['Items'] );
			$next = isset( $body['NextPageLink'] ) ? $body['NextPageLink'] : null;
			$url  = ( is_string( $next ) && strpos( $next, 'https://prices.azure.com/' ) === 0 ) ? $next : null;
		}

		return $all;
	}

	/**
	 * Parse API items into model base => costs map for text models.
	 *
	 * @param array $items Raw API items.
	 * @return array Map of model_base => array( input, output, cached ) in UC per 1M tokens.
	 */
	private static function parse_text_costs( $items ) {
		$by_model = array();

		foreach ( $items as $item ) {
			$meter = isset( $item['meterName'] ) ? $item['meterName'] : '';
			$price = isset( $item['retailPrice'] ) ? (float) $item['retailPrice'] : 0;
			$unit  = isset( $item['unitOfMeasure'] ) ? $item['unitOfMeasure'] : '1M';

			// Skip non-token meters (e.g. hourly PTU, image per-image).
			if ( strpos( strtolower( $meter ), 'token' ) === false && strpos( strtolower( $meter ), '1m' ) === false ) {
				continue;
			}

			// Skip image/audio/media meters - we only want text chat token pricing.
			$meter_lower = strtolower( $meter );
			if ( strpos( $meter_lower, 'img' ) !== false || strpos( $meter_lower, 'out img' ) !== false ) {
				continue;
			}
			if ( strpos( $meter_lower, 'aud' ) !== false || strpos( $meter_lower, 'transcribe' ) !== false || strpos( $meter_lower, 'tts' ) !== false ) {
				continue;
			}

			$uc_per_1m = self::price_to_uc_per_1m( $price, $unit );
			if ( $uc_per_1m <= 0 ) {
				continue;
			}

			$model_base = self::meter_to_model_base( $meter );
			$cost_type  = self::meter_to_cost_type( $meter );

			if ( empty( $model_base ) || empty( $cost_type ) ) {
				continue;
			}

			if ( ! isset( $by_model[ $model_base ] ) ) {
				$by_model[ $model_base ] = array( 'input' => 0, 'output' => 0, 'cached' => 0 );
			}

			// Prefer lowest price if multiple SKUs (e.g. different regions).
			$current = $by_model[ $model_base ][ $cost_type ];
			if ( $current === 0 || $uc_per_1m < $current ) {
				$by_model[ $model_base ][ $cost_type ] = (int) round( $uc_per_1m );
			}
		}

		// Fill missing cached with 10% of input (common pattern).
		foreach ( $by_model as $base => $costs ) {
			if ( $costs['cached'] === 0 && $costs['input'] > 0 ) {
				$by_model[ $base ]['cached'] = (int) round( $costs['input'] * 0.1 );
			}
		}

		return $by_model;
	}

	/**
	 * Convert retail price to UC per 1M tokens.
	 * 1 UC = 0.000001 USD.
	 *
	 * @param float  $price USD per unit.
	 * @param string $unit  e.g. "1K", "1M".
	 * @return float UC per 1M tokens.
	 */
	private static function price_to_uc_per_1m( $price, $unit ) {
		$usd_per_1m = $price;
		if ( stripos( $unit, '1K' ) !== false ) {
			$usd_per_1m = $price * 1000; // 1K -> 1M
		}
		return $usd_per_1m * 1000000; // USD to UC (1 USD = 1M UC)
	}

	/**
	 * Extract model base ID from meter name.
	 * e.g. "gpt 4o inp Gl 1M Tokens" -> "gpt-4o", "gpt 4.1 mini out" -> "gpt-4.1-mini".
	 *
	 * @param string $meter Meter name.
	 * @return string Model base ID or empty.
	 */
	private static function meter_to_model_base( $meter ) {
		// Remove suffix: inp/out/cache/opt, region (Gl/DZ/Reg), unit (1M/1K), Tokens.
		$m = preg_replace( '/\s+(inp|out|cache|opt|Gl|DZ|Reg|1M|1K|Tokens?)\b.*$/i', '', $meter );
		$m = trim( $m );
		// Normalize: "gpt 4o" -> "gpt-4o", "gpt4o" -> "gpt-4o".
		$m = preg_replace( '/\s+/', '-', strtolower( $m ) );
		$m = preg_replace( '/([a-z])(\d)/', '$1-$2', $m ); // gpt4o -> gpt-4o
		$m = preg_replace( '/-+/', '-', $m );
		$m = trim( $m, '-' );

		// Map common Azure meter abbreviations to OpenAI-style IDs.
		$map = array(
			'gpt-4o'       => 'gpt-4o',
			'gpt-4o-mini'  => 'gpt-4o-mini',
			'gpt-4.1'      => 'gpt-4.1',
			'gpt-4.1-mini' => 'gpt-4.1-mini',
			'gpt-4.1-nano' => 'gpt-4.1-nano',
			'gpt-5'        => 'gpt-5',
			'gpt-5-mini'   => 'gpt-5-mini',
			'gpt-5-nano'   => 'gpt-5-nano',
			'o1'           => 'o1',
			'o1-mini'      => 'o1-mini',
			'o3-pro'       => 'o3-pro',
			'o4-mini'      => 'o4-mini',
		);

		foreach ( $map as $key => $val ) {
			if ( $m === $key || strpos( $m, $key . '-' ) === 0 ) {
				return $val;
			}
		}

		// Generic: if it looks like gpt-X or oN, use as-is.
		if ( preg_match( '/^gpt-[\w.-]+$/', $m ) ) {
			return $m;
		}
		if ( preg_match( '/^o\d/', $m ) ) {
			return $m;
		}

		return $m ?: '';
	}

	/**
	 * Determine cost type from meter name.
	 *
	 * @param string $meter Meter name.
	 * @return string 'input'|'output'|'cached' or empty.
	 */
	private static function meter_to_cost_type( $meter ) {
		$m = strtolower( $meter );
		if ( strpos( $m, 'cache' ) !== false || strpos( $m, 'cached' ) !== false ) {
			return 'cached';
		}
		if ( strpos( $m, ' inp ' ) !== false || strpos( $m, ' input' ) !== false ) {
			return 'input';
		}
		if ( strpos( $m, ' out ' ) !== false || strpos( $m, ' opt ' ) !== false || strpos( $m, ' output' ) !== false ) {
			return 'output';
		}
		return '';
	}

	/**
	 * Get cached result.
	 *
	 * @param string $key Cache key.
	 * @return array|null Cached data or null.
	 */
	private static function get_cached( $key ) {
		$data = get_option( self::CACHE_OPTION, array() );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( ! isset( $data[ $key ] ) || ! isset( $data[ $key ]['expires'] ) || time() > $data[ $key ]['expires'] ) {
			return null;
		}
		return isset( $data[ $key ]['value'] ) ? $data[ $key ]['value'] : null;
	}

	/**
	 * Set cached result.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Data to cache.
	 */
	private static function set_cached( $key, $value ) {
		$data = get_option( self::CACHE_OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data[ $key ] = array(
			'value'   => $value,
			'expires' => time() + self::CACHE_TTL,
		);
		update_option( self::CACHE_OPTION, $data );
	}

	/**
	 * Clear the pricing cache (e.g. for manual refresh).
	 */
	public static function clear_cache() {
		delete_option( self::CACHE_OPTION );
	}
}
