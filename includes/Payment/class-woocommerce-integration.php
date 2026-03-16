<?php
/**
 * WooCommerce Subscriptions integration.
 *
 * @package Alorbach\AIGateway\Payment
 */

namespace Alorbach\AIGateway\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCommerce_Integration
 */
class WooCommerce_Integration {

	/**
	 * Process subscription renewal - add credits.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param \WC_Order        $last_order   Last order.
	 */
	public static function process_renewal( $subscription, $last_order ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_user_id' ) ) {
			return;
		}
		$user_id = $subscription->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$credits = self::get_credits_for_subscription( $subscription );
		if ( $credits > 0 ) {
			$result = \Alorbach\AIGateway\Ledger::insert_transaction( $user_id, 'subscription_credit', null, $credits );
			if ( $result ) {
				do_action( 'alorbach_credits_added', $user_id, $credits, 'woocommerce' );
			} else {
				// DB write failed — schedule a single retry in 5 minutes.
				wp_schedule_single_event( time() + 300, 'alorbach_retry_wc_renewal', array( $user_id, $credits ) );
			}
		}
	}

	/**
	 * Handle payment failure.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param \WC_Order        $last_order   Last order.
	 */
	public static function payment_failed( $subscription, $last_order ) {
		do_action( 'alorbach_subscription_payment_failed', $subscription, $last_order );
	}

	/**
	 * Get credits for subscription based on plan mapping.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @return int Credits in UC.
	 */
	private static function get_credits_for_subscription( $subscription ) {
		$plans = get_option( 'alorbach_plans', array() );
		$plans = is_array( $plans ) ? $plans : array();

		$product_mapping = get_option( 'alorbach_product_to_plan', array() );
		$product_mapping = is_array( $product_mapping ) ? $product_mapping : array();

		$items = $subscription->get_items();
		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			$plan_slug  = isset( $product_mapping[ $product_id ] ) ? $product_mapping[ $product_id ] : '';
			if ( $plan_slug && isset( $plans[ $plan_slug ]['credits_per_month'] ) ) {
				return (int) $plans[ $plan_slug ]['credits_per_month'];
			}
		}

		return 0;
	}
}
