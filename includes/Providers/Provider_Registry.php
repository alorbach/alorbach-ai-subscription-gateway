<?php
/**
 * Provider registry: type string to provider instance.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Provider_Registry
 */
class Provider_Registry {

	/**
	 * Registered providers.
	 *
	 * @var Provider_Interface[]
	 */
	private static $providers = array();

	/**
	 * Initialize registry with default providers.
	 */
	public static function init() {
		if ( ! empty( self::$providers ) ) {
			return;
		}
		self::register( new OpenAI_Provider() );
		self::register( new Azure_Provider() );
		self::register( new Google_Provider() );
		self::register( new GitHub_Models_Provider() );
	}

	/**
	 * Register a provider.
	 *
	 * @param Provider_Interface $provider Provider instance.
	 */
	public static function register( Provider_Interface $provider ) {
		self::$providers[ $provider->get_type() ] = $provider;
	}

	/**
	 * Get provider by type.
	 *
	 * @param string $type openai, azure, google, github_models.
	 * @return Provider_Interface|null
	 */
	public static function get( $type ) {
		self::init();
		return isset( self::$providers[ $type ] ) ? self::$providers[ $type ] : null;
	}

	/**
	 * Get all registered provider types.
	 *
	 * @return string[]
	 */
	public static function get_types() {
		self::init();
		return array_keys( self::$providers );
	}
}
