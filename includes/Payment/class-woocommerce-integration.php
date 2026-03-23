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
	 * Process subscription renewal and add credits.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param \WC_Order        $last_order Last order.
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
		if ( $credits <= 0 ) {
			return;
		}

		$signature = self::get_renewal_signature( $subscription, $last_order, $user_id, $credits );
		$result    = \Alorbach\AIGateway\Ledger::insert_transaction( $user_id, 'subscription_credit', null, $credits, null, null, null, $signature );
		if ( $result ) {
			do_action( 'alorbach_credits_added', $user_id, $credits, 'woocommerce' );
			do_action( 'alorbach_subscription_renewal_completed', $user_id, $credits, 'woocommerce', $subscription, $last_order );
			return;
		}

		if ( \Alorbach\AIGateway\Ledger::signature_exists( $signature ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'alorbach_retry_wc_renewal', array( $user_id, $credits, $signature ) ) ) {
			wp_schedule_single_event( time() + 300, 'alorbach_retry_wc_renewal', array( $user_id, $credits, $signature ) );
		}
	}

	/**
	 * Handle payment failure.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param \WC_Order        $last_order Last order.
	 */
	public static function payment_failed( $subscription, $last_order ) {
		do_action( 'alorbach_subscription_payment_failed', $subscription, $last_order );
	}

	/**
	 * Get credits for subscription based on plan mapping.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @return int
	 */
	private static function get_credits_for_subscription( $subscription ) {
		$product_mapping = get_option( 'alorbach_product_to_plan', array() );
		$product_mapping = is_array( $product_mapping ) ? $product_mapping : array();

		$items = $subscription->get_items();
		foreach ( $items as $item ) {
			$product_id = $item->get_product_id();
			$plan_slug  = isset( $product_mapping[ $product_id ] ) ? $product_mapping[ $product_id ] : '';
			if ( ! $plan_slug ) {
				continue;
			}

			$credits = \Alorbach\AIGateway\Integration_Service::get_plan_included_credits( $plan_slug );
			if ( $credits > 0 ) {
				return $credits;
			}
		}

		return 0;
	}

	/**
	 * Build an idempotent renewal signature from the subscription/order pair.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param \WC_Order|null   $last_order   Last renewal order.
	 * @param int              $user_id      User ID.
	 * @param int              $credits      Granted credits.
	 * @return string
	 */
	private static function get_renewal_signature( $subscription, $last_order, $user_id, $credits ) {
		$subscription_id = method_exists( $subscription, 'get_id' ) ? (int) $subscription->get_id() : 0;
		$order_id        = ( is_object( $last_order ) && method_exists( $last_order, 'get_id' ) ) ? (int) $last_order->get_id() : 0;

		return 'wc_renewal:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'subscription_id' => $subscription_id,
					'order_id'        => $order_id,
					'user_id'         => (int) $user_id,
					'credits'         => (int) $credits,
				)
			)
		);
	}
}
