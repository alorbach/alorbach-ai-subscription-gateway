<?php
/**
 * Downstream integration service.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Integration_Service
 */
class Integration_Service {

	/**
	 * Default plans in normalized format.
	 *
	 * @return array
	 */
	public static function get_default_plans() {
		return array(
			'plan_10' => array(
				'slug'                => 'plan_10',
				'public_name'         => '10 $/Monat',
				'billing_interval'    => 'month',
				'price_usd'           => 10.0,
				'included_credits_uc' => 10000000,
				'display_order'       => 10,
				'is_active'           => true,
			),
			'plan_20' => array(
				'slug'                => 'plan_20',
				'public_name'         => '20 $/Monat',
				'billing_interval'    => 'month',
				'price_usd'           => 20.0,
				'included_credits_uc' => 20000000,
				'display_order'       => 20,
				'is_active'           => true,
			),
			'plan_50' => array(
				'slug'                => 'plan_50',
				'public_name'         => '50 $/Monat',
				'billing_interval'    => 'month',
				'price_usd'           => 50.0,
				'included_credits_uc' => 50000000,
				'display_order'       => 30,
				'is_active'           => true,
			),
		);
	}

	/**
	 * Normalize plan storage into the public contract shape.
	 *
	 * @param array|null $plans Raw plans array. Null reads from option storage.
	 * @return array
	 */
	public static function get_normalized_plans( $plans = null ) {
		if ( null === $plans ) {
			$plans = get_option( 'alorbach_plans', self::get_default_plans() );
		}

		$plans = is_array( $plans ) ? $plans : array();
		if ( empty( $plans ) ) {
			$plans = self::get_default_plans();
		}

		$normalized = array();
		$order      = 10;

		foreach ( $plans as $slug => $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}

			$slug                = sanitize_key( isset( $plan['slug'] ) ? $plan['slug'] : $slug );
			$public_name         = isset( $plan['public_name'] ) ? sanitize_text_field( $plan['public_name'] ) : '';
			$legacy_name         = isset( $plan['name'] ) ? sanitize_text_field( $plan['name'] ) : '';
			$billing_interval    = isset( $plan['billing_interval'] ) ? sanitize_key( $plan['billing_interval'] ) : 'month';
			$price_usd           = isset( $plan['price_usd'] ) ? (float) $plan['price_usd'] : 0.0;
			$included_credits_uc = isset( $plan['included_credits_uc'] ) ? (int) $plan['included_credits_uc'] : ( isset( $plan['credits_per_month'] ) ? (int) $plan['credits_per_month'] : 0 );
			$display_order       = isset( $plan['display_order'] ) ? (int) $plan['display_order'] : $order;
			$is_active           = isset( $plan['is_active'] ) ? (bool) $plan['is_active'] : true;

			if ( '' === $slug ) {
				continue;
			}

			$normalized[ $slug ] = array(
				'slug'                     => $slug,
				'public_name'              => $public_name ?: ( $legacy_name ?: $slug ),
				'billing_interval'         => $billing_interval ?: 'month',
				'price_usd'                => $price_usd,
				'included_credits_uc'      => max( 0, $included_credits_uc ),
				'included_credits_display' => User_Display::uc_to_credits( max( 0, $included_credits_uc ) ),
				'display_order'            => $display_order,
				'is_active'                => $is_active,
			);

			$order += 10;
		}

		uasort(
			$normalized,
			static function ( $left, $right ) {
				$left_order  = isset( $left['display_order'] ) ? (int) $left['display_order'] : 0;
				$right_order = isset( $right['display_order'] ) ? (int) $right['display_order'] : 0;
				if ( $left_order === $right_order ) {
					return strcmp( (string) $left['slug'], (string) $right['slug'] );
				}
				return $left_order <=> $right_order;
			}
		);

		return $normalized;
	}

	/**
	 * Prepare a plan for storage while keeping legacy keys intact.
	 *
	 * @param string $slug Plan slug.
	 * @param array  $plan Raw plan values.
	 * @param int    $fallback_order Fallback display order.
	 * @return array
	 */
	public static function prepare_plan_for_storage( $slug, $plan, $fallback_order = 10 ) {
		$slug                = sanitize_key( $slug );
		$public_name         = isset( $plan['public_name'] ) ? sanitize_text_field( wp_unslash( $plan['public_name'] ) ) : '';
		$billing_interval    = isset( $plan['billing_interval'] ) ? sanitize_key( wp_unslash( $plan['billing_interval'] ) ) : 'month';
		$price_usd           = isset( $plan['price_usd'] ) ? (float) $plan['price_usd'] : 0.0;
		$included_credits_uc = isset( $plan['included_credits_uc'] ) ? (int) $plan['included_credits_uc'] : 0;
		$display_order       = isset( $plan['display_order'] ) ? (int) $plan['display_order'] : $fallback_order;
		$is_active           = ! empty( $plan['is_active'] );

		return array(
			'slug'                => $slug,
			'public_name'         => $public_name ?: $slug,
			'name'                => $public_name ?: $slug,
			'billing_interval'    => $billing_interval ?: 'month',
			'price_usd'           => max( 0, $price_usd ),
			'included_credits_uc' => max( 0, $included_credits_uc ),
			'credits_per_month'   => max( 0, $included_credits_uc ),
			'display_order'       => $display_order,
			'is_active'           => $is_active,
		);
	}

	/**
	 * Get public plans for downstream consumers.
	 *
	 * @param array $args Supported keys: include_inactive.
	 * @return array
	 */
	public static function get_public_plans( $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'include_inactive' => false,
			)
		);
		$plans = self::get_normalized_plans();

		if ( empty( $args['include_inactive'] ) ) {
			$plans = array_filter(
				$plans,
				static function ( $plan ) {
					return ! empty( $plan['is_active'] );
				}
			);
		}

		$plans = apply_filters( 'alorbach_integration_plans', array_values( $plans ), $args );

		return array_values( $plans );
	}

	/**
	 * Get billing/account URLs.
	 *
	 * @return array
	 */
	public static function get_billing_urls() {
		$urls = array(
			'subscribe'        => esc_url_raw( (string) get_option( 'alorbach_billing_url_subscribe', '' ) ),
			'top_up'           => esc_url_raw( (string) get_option( 'alorbach_billing_url_top_up', '' ) ),
			'manage_account'   => esc_url_raw( (string) get_option( 'alorbach_billing_url_manage_account', '' ) ),
			'account_overview' => esc_url_raw( (string) get_option( 'alorbach_billing_url_account_overview', '' ) ),
		);

		return apply_filters( 'alorbach_integration_billing_urls', $urls );
	}

	/**
	 * Get downstream config surface.
	 *
	 * @return array
	 */
	public static function get_integration_config() {
		$admin = \Alorbach\AIGateway\Admin\Admin_Demo_Defaults::class;

		$text_models   = $admin::get_text_models();
		$image_models  = $admin::get_image_models();
		$image_sizes   = $admin::get_image_sizes();
		$audio_models  = $admin::get_audio_models();
		$video_models  = $admin::get_video_models();
		$qualities     = array( 'low', 'medium', 'high' );
		$video_sizes   = array( '1280x720', '720x1280', '1920x1080', '1080x1920', '1024x1792', '1792x1024' );
		$video_lengths = array( '4', '8', '12' );

		$config = array(
			'defaults'     => array(
				'chat_model'    => get_option( 'alorbach_demo_default_chat_model', $text_models[0] ?? 'gpt-4.1-mini' ),
				'image_model'   => get_option( 'alorbach_image_default_model', $image_models[0] ?? 'dall-e-3' ),
				'image_size'    => get_option( 'alorbach_demo_default_image_model', $image_sizes[0] ?? '1024x1024' ),
				'image_quality' => get_option( 'alorbach_image_default_quality', 'medium' ),
				'audio_model'   => get_option( 'alorbach_demo_default_audio_model', $audio_models[0] ?? 'whisper-1' ),
				'video_model'   => get_option( 'alorbach_demo_default_video_model', $video_models[0] ?? 'sora-2' ),
			),
			'capabilities' => array(
				'chat_models'     => array_values( $text_models ),
				'image_models'    => array_values( $image_models ),
				'image_sizes'     => array_values( $image_sizes ),
				'image_qualities' => $qualities,
				'audio_models'    => array_values( $audio_models ),
				'video_models'    => array_values( $video_models ),
				'video_sizes'     => $video_sizes,
				'video_durations' => $video_lengths,
			),
			'billing_urls' => self::get_billing_urls(),
		);

		return apply_filters( 'alorbach_integration_config', $config );
	}

	/**
	 * Get account summary for a user.
	 *
	 * @param int|null $user_id User ID or current user when null.
	 * @return array
	 */
	public static function get_account_summary( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$user_id = (int) $user_id;

		$balance = $user_id ? Ledger::get_balance( $user_id ) : 0;
		$usage   = $user_id ? Ledger::get_usage_this_month( $user_id ) : 0;
		$summary = array(
			'user_id'      => $user_id,
			'balance_uc'   => $balance,
			'balance'      => array(
				'uc'      => $balance,
				'credits' => User_Display::uc_to_credits( $balance ),
				'display' => User_Display::format_credits( $balance ),
				'usd'     => User_Display::uc_to_usd( $balance ),
			),
			'usage_month'  => array(
				'uc'      => $usage,
				'credits' => User_Display::uc_to_credits( $usage ),
				'display' => User_Display::format_credits( $usage ),
				'usd'     => User_Display::uc_to_usd( $usage ),
			),
			'billing_urls' => self::get_billing_urls(),
			'renewal'      => self::get_renewal_summary( $user_id ),
		);

		return apply_filters( 'alorbach_integration_account_summary', $summary, $user_id );
	}

	/**
	 * Get account history for a user in a frontend-safe shape.
	 *
	 * @param int|null $user_id User ID or current user when null.
	 * @param array    $args History query args.
	 * @return array
	 */
	public static function get_account_history( $user_id = null, $args = array() ) {
		$user_id = $user_id ?: get_current_user_id();
		$user_id = (int) $user_id;
		$args    = wp_parse_args(
			$args,
			array(
				'per_page' => 10,
				'page'     => 1,
			)
		);

		$result = Ledger::get_transactions(
			array(
				'user_id'  => $user_id,
				'per_page' => max( 1, (int) $args['per_page'] ),
				'page'     => max( 1, (int) $args['page'] ),
			)
		);

		$rows = array_map(
			static function ( $row ) {
				$amount_uc = (int) $row['uc_amount'];
				return array(
					'transaction_id'   => (int) $row['transaction_id'],
					'created_at'       => $row['created_at'],
					'transaction_type' => $row['transaction_type'],
					'transaction_label'=> \Alorbach\AIGateway\Admin\Admin_Helper::get_transaction_type_label( $row['transaction_type'] ),
					'model'            => isset( $row['model_used'] ) ? (string) $row['model_used'] : '',
					'amount'           => array(
						'uc'      => $amount_uc,
						'credits' => User_Display::uc_to_credits( $amount_uc ),
						'display' => User_Display::format_credits( $amount_uc ),
						'usd'     => User_Display::uc_to_usd( $amount_uc ),
					),
				);
			},
			$result['rows']
		);

		$history = array(
			'items'    => $rows,
			'total'    => (int) $result['total'],
			'page'     => max( 1, (int) $args['page'] ),
			'per_page' => max( 1, (int) $args['per_page'] ),
		);

		return apply_filters( 'alorbach_integration_account_history', $history, $user_id, $args );
	}

	/**
	 * Render embeddable account widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_account_widget( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$atts    = shortcode_atts(
			array(
				'history_items' => 5,
				'show_history'  => 'true',
			),
			(array) $atts,
			'alorbach_account_widget'
		);
		$user_id = get_current_user_id();
		$summary = self::get_account_summary( $user_id );
		$history = self::get_account_history(
			$user_id,
			array(
				'per_page' => max( 1, (int) $atts['history_items'] ),
				'page'     => 1,
			)
		);

		ob_start();
		?>
		<div class="alorbach-account-widget">
			<div class="alorbach-account-widget__summary">
				<div class="alorbach-account-widget__metric">
					<strong><?php esc_html_e( 'Balance', 'alorbach-ai-gateway' ); ?></strong>
					<span><?php echo esc_html( $summary['balance']['display'] ); ?></span>
				</div>
				<div class="alorbach-account-widget__metric">
					<strong><?php esc_html_e( 'Usage this month', 'alorbach-ai-gateway' ); ?></strong>
					<span><?php echo esc_html( $summary['usage_month']['display'] ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $summary['renewal'] ) && ! empty( $summary['renewal']['status_label'] ) ) : ?>
				<p class="alorbach-account-widget__renewal"><?php echo esc_html( $summary['renewal']['status_label'] ); ?></p>
			<?php endif; ?>

			<div class="alorbach-account-widget__actions">
				<?php if ( ! empty( $summary['billing_urls']['top_up'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( $summary['billing_urls']['top_up'] ); ?>"><?php esc_html_e( 'Top up credits', 'alorbach-ai-gateway' ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $summary['billing_urls']['manage_account'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( $summary['billing_urls']['manage_account'] ); ?>"><?php esc_html_e( 'Manage account', 'alorbach-ai-gateway' ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $summary['billing_urls']['account_overview'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( $summary['billing_urls']['account_overview'] ); ?>"><?php esc_html_e( 'View account', 'alorbach-ai-gateway' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( 'false' !== strtolower( (string) $atts['show_history'] ) ) : ?>
				<div class="alorbach-account-widget__history">
					<h3><?php esc_html_e( 'Recent activity', 'alorbach-ai-gateway' ); ?></h3>
					<?php if ( empty( $history['items'] ) ) : ?>
						<p><?php esc_html_e( 'No transactions yet.', 'alorbach-ai-gateway' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $history['items'] as $item ) : ?>
								<li>
									<strong><?php echo esc_html( $item['transaction_label'] ); ?></strong>
									<span><?php echo esc_html( $item['amount']['display'] ); ?></span>
									<?php if ( ! empty( $item['model'] ) ) : ?>
										<em><?php echo esc_html( $item['model'] ); ?></em>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		$html = (string) ob_get_clean();

		return apply_filters( 'alorbach_account_widget_html', $html, $summary, $history, $atts );
	}

	/**
	 * Get included credits for a plan slug.
	 *
	 * @param string $plan_slug Plan slug.
	 * @return int
	 */
	public static function get_plan_included_credits( $plan_slug ) {
		$plans = self::get_normalized_plans();
		return isset( $plans[ $plan_slug ]['included_credits_uc'] ) ? (int) $plans[ $plan_slug ]['included_credits_uc'] : 0;
	}

	/**
	 * Resolve a basic renewal summary from WooCommerce subscriptions.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null
	 */
	private static function get_renewal_summary( $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return null;
		}

		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
			return null;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_date' ) ) {
				continue;
			}

			$status       = method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : '';
			$next_payment = $subscription->get_date( 'next_payment' );
			$label        = '';

			if ( $next_payment ) {
				$label = sprintf(
					/* translators: 1: subscription status, 2: next renewal date */
					__( 'Subscription %1$s. Next renewal: %2$s', 'alorbach-ai-gateway' ),
					$status ?: __( 'active', 'alorbach-ai-gateway' ),
					wp_date( get_option( 'date_format' ), strtotime( $next_payment ) )
				);
			} elseif ( $status ) {
				$label = sprintf(
					/* translators: %s: subscription status */
					__( 'Subscription status: %s', 'alorbach-ai-gateway' ),
					$status
				);
			}

			return array(
				'status'        => $status,
				'next_payment'  => $next_payment ?: null,
				'status_label'  => $label,
			);
		}

		return null;
	}
}
