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
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name derived from $wpdb->prefix, not user input.

delete_option( 'alorbach_api_keys' );
delete_option( 'alorbach_api_keys_entries' );
delete_option( 'alorbach_cost_matrix' );
delete_option( 'alorbach_plans' );
delete_option( 'alorbach_product_to_plan' );
delete_option( 'alorbach_image_costs' );
delete_option( 'alorbach_image_models' );
delete_option( 'alorbach_image_model_costs' );
delete_option( 'alorbach_image_default_model' );
delete_option( 'alorbach_image_default_quality' );
delete_option( 'alorbach_image_default_output_format' );
delete_option( 'alorbach_demo_allow_image_size_select' );
delete_option( 'alorbach_demo_allow_image_quality_select' );
delete_option( 'alorbach_demo_image_options_migrated' );
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
delete_option( 'alorbach_selling_enabled' );
delete_option( 'alorbach_selling_multiplier' );
delete_option( 'alorbach_debug_enabled' );
delete_option( 'alorbach_google_import_default' );
delete_option( 'alorbach_google_model_whitelist' );
delete_option( 'alorbach_rate_limit_window' );
delete_option( 'alorbach_rate_limit_chat' );
delete_option( 'alorbach_rate_limit_images' );
delete_option( 'alorbach_rate_limit_transcribe' );
delete_option( 'alorbach_rate_limit_video' );
delete_option( 'alorbach_monthly_quota_uc' );
delete_option( 'alorbach_model_max_tokens' );
delete_option( 'alorbach_demo_max_tokens_options' );
delete_option( 'alorbach_demo_default_max_tokens' );
delete_option( 'alorbach_azure_prices_cache' );
