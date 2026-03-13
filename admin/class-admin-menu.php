<?php
/**
 * Admin menu registration.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Menu
 */
class Admin_Menu {

	/**
	 * Register admin menu and submenus.
	 */
	public static function register() {
		add_menu_page(
			__( 'Alorbach AI Gateway', 'alorbach-ai-gateway' ),
			__( 'AI Gateway', 'alorbach-ai-gateway' ),
			'manage_options',
			'alorbach-ai-gateway',
			array( Admin_API_Keys::class, 'render' ),
			'dashicons-admin-generic',
			30
		);

		add_submenu_page( 'alorbach-ai-gateway', __( 'API Keys', 'alorbach-ai-gateway' ), __( 'API Keys', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-ai-gateway', array( Admin_API_Keys::class, 'render' ) );
		add_submenu_page( 'alorbach-ai-gateway', __( 'Models', 'alorbach-ai-gateway' ), __( 'Models', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-cost-matrix', array( Admin_Cost_Matrix::class, 'render' ) );
		add_submenu_page( 'alorbach-ai-gateway', __( 'Demo Defaults', 'alorbach-ai-gateway' ), __( 'Demo Defaults', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-demo-defaults', array( Admin_Demo_Defaults::class, 'render' ) );
		add_submenu_page( 'alorbach-ai-gateway', __( 'Plans', 'alorbach-ai-gateway' ), __( 'Plans', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-plans', array( Admin_Plans::class, 'render' ) );
		add_submenu_page( 'alorbach-ai-gateway', __( 'User Balance', 'alorbach-ai-gateway' ), __( 'User Balance', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-user-balance', array( Admin_User_Balance::class, 'render' ) );
		add_submenu_page( 'alorbach-ai-gateway', __( 'Usage', 'alorbach-ai-gateway' ), __( 'Usage', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-usage', array( Admin_Usage::class, 'render' ) );
		add_submenu_page( 'alorbach-ai-gateway', __( 'Stripe Webhook', 'alorbach-ai-gateway' ), __( 'Stripe Webhook', 'alorbach-ai-gateway' ), 'manage_options', 'alorbach-stripe-webhook', array( Admin_Stripe_Webhook::class, 'render' ) );
	}
}
