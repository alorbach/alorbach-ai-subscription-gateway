<?php
/**
 * User-facing display: shortcodes, template tags, formatting.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class User_Display
 */
class User_Display {

	/**
	 * Convert UC to display Credits (1000 UC = 1 Credit).
	 *
	 * @param int $uc_amount Amount in UC.
	 * @return float Credits for display.
	 */
	public static function uc_to_credits( $uc_amount ) {
		$ratio = apply_filters( 'alorbach_uc_to_credit_ratio', ALORBACH_UC_TO_CREDIT );
		return $uc_amount / max( 1, (int) $ratio );
	}

	/**
	 * Format UC as Credits string.
	 *
	 * @param int $uc_amount Amount in UC.
	 * @return string Formatted string (e.g. "10.000 Credits").
	 */
	public static function format_credits( $uc_amount ) {
		$credits = self::uc_to_credits( $uc_amount );
		$label   = apply_filters( 'alorbach_credits_label', __( 'Credits', 'alorbach-ai-gateway' ), $uc_amount );
		return number_format_i18n( $credits, 2 ) . ' ' . $label;
	}

	/**
	 * Render balance shortcode output.
	 *
	 * @return string
	 */
	public static function render_balance() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user_id = get_current_user_id();
		$balance = Ledger::get_balance( $user_id );
		$html    = '<span class="alorbach-balance">' . esc_html( self::format_credits( $balance ) ) . '</span>';
		return apply_filters( 'alorbach_balance_display', $html, $user_id, $balance );
	}

	/**
	 * Render usage month shortcode output.
	 *
	 * @return string
	 */
	public static function render_usage_month() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user_id = get_current_user_id();
		$usage   = Ledger::get_usage_this_month( $user_id );
		$html    = '<span class="alorbach-usage">' . esc_html( self::format_credits( $usage ) ) . '</span>';
		return apply_filters( 'alorbach_usage_display', $html, $user_id, $usage, 'month' );
	}
}
