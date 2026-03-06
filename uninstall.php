<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Alorbach_AI_Subscription_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'alorbach_ledger';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

delete_option( 'alorbach_api_keys' );
delete_option( 'alorbach_cost_matrix' );
delete_option( 'alorbach_plans' );
delete_option( 'alorbach_product_to_plan' );
delete_option( 'alorbach_image_costs' );
delete_option( 'alorbach_stripe_webhook_secret' );
delete_option( 'alorbach_db_version' );
