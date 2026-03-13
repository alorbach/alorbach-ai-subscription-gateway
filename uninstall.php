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
delete_option( 'alorbach_image_models' );
delete_option( 'alorbach_image_model_costs' );
delete_option( 'alorbach_image_default_model' );
delete_option( 'alorbach_image_default_quality' );
delete_option( 'alorbach_image_default_output_format' );
delete_option( 'alorbach_demo_allow_image_quality_select' );
delete_option( 'alorbach_video_costs' );
delete_option( 'alorbach_audio_costs' );
delete_option( 'alorbach_whisper_cost_per_second' );
delete_option( 'alorbach_stripe_webhook_secret' );
delete_option( 'alorbach_db_version' );
delete_option( 'alorbach_demo_default_chat_model' );
delete_option( 'alorbach_demo_default_image_model' );
delete_option( 'alorbach_demo_default_audio_model' );
delete_option( 'alorbach_demo_default_video_model' );
delete_option( 'alorbach_demo_allow_chat_model_select' );
delete_option( 'alorbach_demo_allow_image_model_select' );
delete_option( 'alorbach_demo_allow_audio_model_select' );
delete_option( 'alorbach_demo_allow_video_model_select' );
delete_option( 'alorbach_demo_page_ids' );
