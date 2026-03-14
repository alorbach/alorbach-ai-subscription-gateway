<?php
/**
 * Plugin Name: Alorbach AI Subscription Gateway
 * Plugin URI: https://github.com/alorbach/alorbach-ai-subscription-gateway
 * Description: Precise credit-based AI API billing layer for WordPress. Unified Credit system, BPE tokenization, immutable ledger.
 * Version: 1.0.0
 * Author: Andre Lorbach
 * Author URI: https://alorbach.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alorbach-ai-gateway
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALORBACH_VERSION', '1.0.0' );
define( 'ALORBACH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALORBACH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALORBACH_UC_TO_CREDIT', 1000 );

require_once ALORBACH_PLUGIN_DIR . 'vendor/autoload.php';

// Explicitly load Provider classes (in case autoload classmap is stale).
require_once ALORBACH_PLUGIN_DIR . 'includes/class-api-keys-helper.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Provider_Interface.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Provider_Base.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/OpenAI_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Azure_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Google_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/GitHub_Models_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Provider_Registry.php';

add_action( 'init', 'alorbach_load_textdomain' );
function alorbach_load_textdomain() {
	load_plugin_textdomain( 'alorbach-ai-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', array( 'Alorbach\AIGateway\Ledger', 'maybe_upgrade' ), 5 );

register_activation_hook( __FILE__, 'alorbach_activate' );
function alorbach_activate() {
	Alorbach\AIGateway\Ledger::create_table();
	if ( apply_filters( 'alorbach_create_sample_pages_on_activation', false ) ) {
		Alorbach\AIGateway\Admin\Admin_Demo_Defaults::create_sample_pages();
	}
}

register_deactivation_hook( __FILE__, 'alorbach_deactivate' );
function alorbach_deactivate() {
	// No data cleanup on deactivation
}

add_action( 'rest_api_init', 'alorbach_register_rest_routes' );
function alorbach_register_rest_routes() {
	Alorbach\AIGateway\REST_Proxy::register_routes();
}

add_action( 'admin_menu', 'alorbach_admin_menu' );
function alorbach_admin_menu() {
	Alorbach\AIGateway\Admin\Admin_Menu::register();
}

add_action( 'show_user_profile', 'alorbach_user_profile_credits_section' );
add_action( 'edit_user_profile', 'alorbach_user_profile_credits_section' );
function alorbach_user_profile_credits_section( $user ) {
	Alorbach\AIGateway\Admin\Admin_User_Credits::render_profile_section( $user );
}

add_action( 'admin_menu', 'alorbach_user_credits_menu', 20 );
function alorbach_user_credits_menu() {
	Alorbach\AIGateway\Admin\Admin_User_Credits::register_menu();
}

add_shortcode( 'alorbach_balance', 'alorbach_balance_shortcode' );
function alorbach_balance_shortcode() {
	return Alorbach\AIGateway\User_Display::render_balance();
}

add_shortcode( 'alorbach_usage_month', 'alorbach_usage_month_shortcode' );
function alorbach_usage_month_shortcode() {
	return Alorbach\AIGateway\User_Display::render_usage_month();
}

add_shortcode( 'alorbach_demo_chat', array( Alorbach\AIGateway\Demo_Shortcodes::class, 'render_chat' ) );
add_shortcode( 'alorbach_demo_image', array( Alorbach\AIGateway\Demo_Shortcodes::class, 'render_image' ) );
add_shortcode( 'alorbach_demo_transcribe', array( Alorbach\AIGateway\Demo_Shortcodes::class, 'render_transcribe' ) );
add_shortcode( 'alorbach_demo_video', array( Alorbach\AIGateway\Demo_Shortcodes::class, 'render_video' ) );

add_action( 'plugins_loaded', 'alorbach_register_woocommerce_hooks' );
function alorbach_register_woocommerce_hooks() {
	if ( ! class_exists( 'WC_Subscriptions' ) ) {
		return;
	}
	add_action( 'woocommerce_subscription_renewal_payment_complete', 'alorbach_process_renewal', 10, 2 );
	add_action( 'woocommerce_subscription_payment_failed', 'alorbach_payment_failed', 10, 2 );
}
function alorbach_process_renewal( $subscription, $last_order ) {
	Alorbach\AIGateway\Payment\WooCommerce_Integration::process_renewal( $subscription, $last_order );
}
function alorbach_payment_failed( $subscription, $last_order ) {
	Alorbach\AIGateway\Payment\WooCommerce_Integration::payment_failed( $subscription, $last_order );
}

/**
 * Template tag: Get user balance in UC.
 *
 * @param int|null $user_id User ID, null for current user.
 * @return int Balance in UC.
 */
function alorbach_get_user_balance( $user_id = null ) {
	$user_id = $user_id ?: get_current_user_id();
	return $user_id ? Alorbach\AIGateway\Ledger::get_balance( $user_id ) : 0;
}

/**
 * Template tag: Get user usage this month in UC.
 *
 * @param int|null $user_id User ID, null for current user.
 * @return int Usage in UC.
 */
function alorbach_get_user_usage_this_month( $user_id = null ) {
	$user_id = $user_id ?: get_current_user_id();
	return $user_id ? Alorbach\AIGateway\Ledger::get_usage_this_month( $user_id ) : 0;
}

/**
 * Template tag: Format UC as Credits string.
 *
 * @param int $uc_amount Amount in UC.
 * @return string Formatted string.
 */
function alorbach_format_credits( $uc_amount ) {
	return Alorbach\AIGateway\User_Display::format_credits( $uc_amount );
}
