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
require_once ALORBACH_PLUGIN_DIR . 'admin/class-admin-image-queue.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/class-api-keys-helper.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/class-image-jobs.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/class-integration-service.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Provider_Interface.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Provider_Base.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/OpenAI_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Azure_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Google_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/GitHub_Models_Provider.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/class-codex-oauth.php';
require_once ALORBACH_PLUGIN_DIR . 'includes/Providers/Codex_Provider.php';
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
	wp_clear_scheduled_hook( 'alorbach_retry_wc_renewal' );
}

// Register Codex OAuth provider after the registry is initialised.
add_action( 'alorbach_register_providers', function() {
	Alorbach\AIGateway\Providers\Provider_Registry::register(
		new Alorbach\AIGateway\Providers\Codex_Provider()
	);
} );

add_action( 'rest_api_init', 'alorbach_register_rest_routes' );
function alorbach_register_rest_routes() {
	Alorbach\AIGateway\REST_Proxy::register_routes();
}

// Codex OAuth – Step 1: generate authorization URL and store it for display.
add_action( 'admin_post_alorbach_codex_authorize', 'alorbach_codex_authorize_handler' );
function alorbach_codex_authorize_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
	}
	check_admin_referer( 'alorbach_codex_authorize' );

	$auth_url = Alorbach\AIGateway\Codex_OAuth::get_authorization_url();
	// Store the URL for display on the next page load (5-minute TTL).
	set_transient( 'alorbach_codex_pending_auth_url', $auth_url, 5 * MINUTE_IN_SECONDS );

	wp_safe_redirect( admin_url( 'admin.php?page=alorbach-ai-gateway' ) );
	exit;
}

// Codex OAuth – Step 2: exchange the pasted redirect URL for tokens.
add_action( 'admin_post_alorbach_codex_exchange', 'alorbach_codex_exchange_handler' );
function alorbach_codex_exchange_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
	}
	check_admin_referer( 'alorbach_codex_exchange' );

	$pasted = isset( $_POST['codex_redirect_url'] ) ? sanitize_text_field( wp_unslash( $_POST['codex_redirect_url'] ) ) : '';
	$result = Alorbach\AIGateway\Codex_OAuth::exchange_code_from_input( $pasted );

	if ( is_wp_error( $result ) ) {
		// Keep the pending auth URL alive so Step 1 still shows on the next page load.
		// (extend TTL by another 5 minutes)
		$pending = get_transient( 'alorbach_codex_pending_auth_url' );
		if ( $pending ) {
			set_transient( 'alorbach_codex_pending_auth_url', $pending, 5 * MINUTE_IN_SECONDS );
		}
		set_transient(
			'alorbach_codex_oauth_notice',
			array( 'type' => 'error', 'message' => '[' . $result->get_error_code() . '] ' . $result->get_error_message() ),
			60
		);
	} else {
		delete_transient( 'alorbach_codex_pending_auth_url' );
		set_transient(
			'alorbach_codex_oauth_notice',
			array( 'type' => 'success', 'message' => __( 'Codex OAuth connected successfully.', 'alorbach-ai-gateway' ) ),
			60
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=alorbach-ai-gateway' ) );
	exit;
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

add_shortcode( 'alorbach_account_widget', 'alorbach_account_widget_shortcode' );
function alorbach_account_widget_shortcode( $atts = array() ) {
	return Alorbach\AIGateway\Integration_Service::render_account_widget( $atts );
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

/**
 * Template tag: Get downstream integration config.
 *
 * @return array
 */
function alorbach_get_integration_config() {
	return Alorbach\AIGateway\Integration_Service::get_integration_config();
}

/**
 * Template tag: Get public plan catalog.
 *
 * @param array $args Optional arguments.
 * @return array
 */
function alorbach_get_public_plans( $args = array() ) {
	return Alorbach\AIGateway\Integration_Service::get_public_plans( $args );
}

/**
 * Template tag: Get billing/account URLs.
 *
 * @return array
 */
function alorbach_get_billing_urls() {
	return Alorbach\AIGateway\Integration_Service::get_billing_urls();
}

/**
 * Template tag: Get normalized account summary.
 *
 * @param int|null $user_id Optional user ID.
 * @return array
 */
function alorbach_get_account_summary( $user_id = null ) {
	return Alorbach\AIGateway\Integration_Service::get_account_summary( $user_id );
}

/**
 * Template tag: Get normalized account history.
 *
 * @param int|null $user_id Optional user ID.
 * @param array    $args Optional history query args.
 * @return array
 */
function alorbach_get_account_history( $user_id = null, $args = array() ) {
	return Alorbach\AIGateway\Integration_Service::get_account_history( $user_id, $args );
}

// ---------------------------------------------------------------------------
// GDPR: Personal data export
// ---------------------------------------------------------------------------
add_filter( 'wp_privacy_personal_data_exporters', 'alorbach_register_personal_data_exporters' );
function alorbach_register_personal_data_exporters( $exporters ) {
	$exporters['alorbach-ai-gateway'] = array(
		'exporter_friendly_name' => __( 'AI Gateway Usage', 'alorbach-ai-gateway' ),
		'callback'               => 'alorbach_export_personal_data',
	);
	return $exporters;
}
function alorbach_export_personal_data( $email, $page = 1 ) {
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return array( 'data' => array(), 'done' => true );
	}
	$per_page = 100;
	$result   = Alorbach\AIGateway\Ledger::get_transactions( array(
		'user_id'  => $user->ID,
		'per_page' => $per_page,
		'page'     => (int) $page,
	) );
	$data = array();
	foreach ( $result['rows'] as $row ) {
		$data[] = array(
			'group_id'    => 'alorbach_ledger',
			'group_label' => __( 'AI Usage Ledger', 'alorbach-ai-gateway' ),
			'item_id'     => 'ledger-' . $row['transaction_id'],
			'data'        => array(
				array( 'name' => __( 'Date', 'alorbach-ai-gateway' ),       'value' => $row['created_at'] ),
				array( 'name' => __( 'Type', 'alorbach-ai-gateway' ),       'value' => $row['transaction_type'] ),
				array( 'name' => __( 'Model', 'alorbach-ai-gateway' ),      'value' => $row['model_used'] ?? '' ),
				array( 'name' => __( 'Amount (UC)', 'alorbach-ai-gateway' ), 'value' => $row['uc_amount'] ),
			),
		);
	}
	return array(
		'data' => $data,
		'done' => count( $result['rows'] ) < $per_page,
	);
}

// ---------------------------------------------------------------------------
// GDPR: Personal data erasure (anonymise ledger rows — records are retained
// for accounting integrity but user identity is removed).
// ---------------------------------------------------------------------------
add_filter( 'wp_privacy_personal_data_erasers', 'alorbach_register_personal_data_erasers' );
function alorbach_register_personal_data_erasers( $erasers ) {
	$erasers['alorbach-ai-gateway'] = array(
		'eraser_friendly_name' => __( 'AI Gateway Usage', 'alorbach-ai-gateway' ),
		'callback'             => 'alorbach_erase_personal_data',
	);
	return $erasers;
}
function alorbach_erase_personal_data( $email, $page = 1 ) {
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
	global $wpdb;
	$table   = Alorbach\AIGateway\Ledger::get_table_name();
	$removed = (int) $wpdb->update(
		$table,
		array( 'user_id' => 0 ),
		array( 'user_id' => $user->ID ),
		array( '%d' ),
		array( '%d' )
	);
	return array(
		'items_removed'  => $removed > 0,
		'items_retained' => false,
		'messages'       => array( __( 'AI usage records anonymized.', 'alorbach-ai-gateway' ) ),
		'done'           => true,
	);
}

// ---------------------------------------------------------------------------
// Admin notification: WooCommerce payment failure
// ---------------------------------------------------------------------------
add_action( 'alorbach_subscription_payment_failed', 'alorbach_admin_notify_payment_failed', 10, 2 );
function alorbach_admin_notify_payment_failed( $subscription, $last_order ) {
	$admin_email = get_option( 'admin_email' );
	$user_id     = is_object( $subscription ) && method_exists( $subscription, 'get_user_id' ) ? $subscription->get_user_id() : 0;
	$user        = $user_id ? get_user_by( 'id', $user_id ) : null;
	$user_info   = $user ? $user->user_login . ' <' . $user->user_email . '>' : __( 'Unknown user', 'alorbach-ai-gateway' );
	$site_name   = get_bloginfo( 'name' );
	wp_mail(
		$admin_email,
		sprintf( __( '[%s] Subscription payment failed', 'alorbach-ai-gateway' ), $site_name ),
		sprintf(
			__( "A WooCommerce subscription payment has failed.\n\nUser: %s\n\nPlease review WooCommerce subscriptions for details: %s", 'alorbach-ai-gateway' ),
			$user_info,
			admin_url( 'admin.php?page=wc-subscriptions' )
		)
	);
}

// ---------------------------------------------------------------------------
// WooCommerce renewal retry: scheduled event handler
// ---------------------------------------------------------------------------
add_action( 'alorbach_retry_wc_renewal', 'alorbach_handle_retry_wc_renewal', 10, 2 );
function alorbach_handle_retry_wc_renewal( $user_id, $credits_uc ) {
	$result = Alorbach\AIGateway\Ledger::insert_transaction( (int) $user_id, 'subscription_credit', null, (int) $credits_uc );
	if ( $result ) {
		do_action( 'alorbach_credits_added', (int) $user_id, (int) $credits_uc, 'woocommerce_retry' );
		do_action( 'alorbach_subscription_renewal_completed', (int) $user_id, (int) $credits_uc, 'woocommerce_retry', null, null );
	} else {
		error_log( sprintf(
			'[alorbach-ai-gateway] WC renewal retry failed permanently for user %d (%d UC). Manual credit grant required.',
			(int) $user_id,
			(int) $credits_uc
		) );
		do_action( 'alorbach_wc_renewal_retry_failed', (int) $user_id, (int) $credits_uc );
	}
}
