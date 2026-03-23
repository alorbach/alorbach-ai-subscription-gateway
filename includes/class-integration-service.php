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
	 * Canonical slug for the implicit free plan.
	 *
	 * @var string
	 */
	const BASIC_PLAN_SLUG = 'basic';

	/**
	 * Supported plan capability keys.
	 *
	 * @var string[]
	 */
	const PLAN_CAPABILITIES = array( 'chat', 'image', 'audio', 'video' );

	/**
	 * User meta key for a manual plan override.
	 *
	 * @var string
	 */
	const USER_PLAN_OVERRIDE_META_KEY = 'alorbach_manual_plan_slug';

	/**
	 * Default plans in normalized format.
	 *
	 * @return array
	 */
	public static function get_default_plans() {
		return array(
			self::BASIC_PLAN_SLUG => array(
				'slug'                => self::BASIC_PLAN_SLUG,
				'public_name'         => 'Basic',
				'billing_interval'    => 'month',
				'price_usd'           => 0.0,
				'included_credits_uc' => 0,
				'display_order'       => 0,
				'is_active'           => true,
				'is_free'             => true,
				'capabilities'        => array(
					'chat'  => true,
					'image' => false,
					'audio' => false,
					'video' => false,
				),
				'allowed_models'      => self::get_empty_allowed_models(),
			),
			'plan_10' => array(
				'slug'                => 'plan_10',
				'public_name'         => '10 $/Monat',
				'billing_interval'    => 'month',
				'price_usd'           => 10.0,
				'included_credits_uc' => 10000000,
				'display_order'       => 10,
				'is_active'           => true,
				'is_free'             => false,
				'capabilities'        => self::get_default_capabilities( true ),
				'allowed_models'      => self::get_empty_allowed_models(),
			),
			'plan_20' => array(
				'slug'                => 'plan_20',
				'public_name'         => '20 $/Monat',
				'billing_interval'    => 'month',
				'price_usd'           => 20.0,
				'included_credits_uc' => 20000000,
				'display_order'       => 20,
				'is_active'           => true,
				'is_free'             => false,
				'capabilities'        => self::get_default_capabilities( true ),
				'allowed_models'      => self::get_empty_allowed_models(),
			),
			'plan_50' => array(
				'slug'                => 'plan_50',
				'public_name'         => '50 $/Monat',
				'billing_interval'    => 'month',
				'price_usd'           => 50.0,
				'included_credits_uc' => 50000000,
				'display_order'       => 30,
				'is_active'           => true,
				'is_free'             => false,
				'capabilities'        => self::get_default_capabilities( true ),
				'allowed_models'      => self::get_empty_allowed_models(),
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

		$model_catalog = self::get_plan_model_catalog();
		$normalized    = array();
		$order         = 10;

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
			$is_free             = isset( $plan['is_free'] ) ? (bool) $plan['is_free'] : ( $slug === self::BASIC_PLAN_SLUG || $price_usd <= 0.0 );
			$has_entitlements    = isset( $plan['capabilities'] ) || isset( $plan['allowed_models'] ) || isset( $plan['is_free'] );

			if ( '' === $slug ) {
				continue;
			}

			$normalized[ $slug ] = array(
				'slug'                     => $slug,
				'public_name'              => $public_name ?: ( $legacy_name ?: $slug ),
				'billing_interval'         => in_array( $billing_interval, array( 'month', 'year', 'week' ), true ) ? $billing_interval : 'month',
				'price_usd'                => max( 0, $price_usd ),
				'included_credits_uc'      => max( 0, $included_credits_uc ),
				'included_credits_display' => User_Display::uc_to_credits( max( 0, $included_credits_uc ) ),
				'display_order'            => $display_order,
				'is_active'                => $is_active,
				'is_free'                  => $is_free,
				'capabilities'             => self::normalize_capabilities(
					$has_entitlements ? ( $plan['capabilities'] ?? array() ) : self::get_default_capabilities( true ),
					$slug === self::BASIC_PLAN_SLUG ? self::get_default_capabilities( false ) : self::get_default_capabilities( true )
				),
				'allowed_models'           => self::normalize_allowed_models(
					$plan['allowed_models'] ?? array(),
					$model_catalog
				),
			);

			$order += 10;
		}

		if ( ! isset( $normalized[ self::BASIC_PLAN_SLUG ] ) ) {
			$basic = self::get_default_plans()[ self::BASIC_PLAN_SLUG ];
			$normalized[ self::BASIC_PLAN_SLUG ] = array(
				'slug'                     => $basic['slug'],
				'public_name'              => $basic['public_name'],
				'billing_interval'         => $basic['billing_interval'],
				'price_usd'                => $basic['price_usd'],
				'included_credits_uc'      => $basic['included_credits_uc'],
				'included_credits_display' => User_Display::uc_to_credits( $basic['included_credits_uc'] ),
				'display_order'            => $basic['display_order'],
				'is_active'                => $basic['is_active'],
				'is_free'                  => true,
				'capabilities'             => $basic['capabilities'],
				'allowed_models'           => $basic['allowed_models'],
			);
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
		$is_free             = isset( $plan['is_free'] ) ? (bool) $plan['is_free'] : ( $slug === self::BASIC_PLAN_SLUG || $price_usd <= 0.0 );
		$default_caps        = $slug === self::BASIC_PLAN_SLUG ? self::get_default_capabilities( false ) : self::get_default_capabilities( true );

		return array(
			'slug'                => $slug,
			'public_name'         => $public_name ?: $slug,
			'name'                => $public_name ?: $slug,
			'billing_interval'    => in_array( $billing_interval, array( 'month', 'year', 'week' ), true ) ? $billing_interval : 'month',
			'price_usd'           => max( 0, $price_usd ),
			'included_credits_uc' => max( 0, $included_credits_uc ),
			'credits_per_month'   => max( 0, $included_credits_uc ),
			'display_order'       => $display_order,
			'is_active'           => $is_active,
			'is_free'             => $is_free,
			'capabilities'        => self::normalize_capabilities(
				$plan['capabilities'] ?? array(),
				$default_caps
			),
			'allowed_models'      => self::normalize_allowed_models(
				$plan['allowed_models'] ?? array(),
				self::get_plan_model_catalog()
			),
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
	public static function get_integration_config( $user_id = null ) {
		$admin          = \Alorbach\AIGateway\Admin\Admin_Demo_Defaults::class;
		$settings_admin = \Alorbach\AIGateway\Admin\Admin_Settings::class;

		$text_models   = $admin::get_text_models();
		$image_models  = $admin::get_image_models();
		$image_sizes   = $admin::get_image_sizes();
		$audio_models  = $admin::get_audio_models();
		$video_models  = $admin::get_video_models();
		$qualities     = array( 'low', 'medium', 'high' );
		$video_sizes   = array( '1280x720', '720x1280', '1920x1080', '1080x1920', '1024x1792', '1792x1024' );
		$video_lengths = array( '4', '8', '12' );

		$config = array(
			'defaults'          => array(
				'chat_model'    => $settings_admin::get_default_chat_model( $text_models ),
				'image_model'   => get_option( 'alorbach_image_default_model', $image_models[0] ?? 'dall-e-3' ),
				'image_size'    => $settings_admin::get_default_image_size( $image_sizes ),
				'image_quality' => get_option( 'alorbach_image_default_quality', 'medium' ),
				'audio_model'   => $settings_admin::get_default_audio_model( $audio_models ),
				'video_model'   => $settings_admin::get_default_video_model( $video_models ),
			),
			'capabilities'      => array(
				'chat_models'     => array_values( $text_models ),
				'image_models'    => array_values( $image_models ),
				'image_sizes'     => array_values( $image_sizes ),
				'image_qualities' => $qualities,
				'audio_models'    => array_values( $audio_models ),
				'video_models'    => array_values( $video_models ),
				'video_sizes'     => $video_sizes,
				'video_durations' => $video_lengths,
			),
			'plan_capabilities' => self::get_default_capabilities( true ),
			'billing_urls'      => self::get_billing_urls(),
		);

		$user_id = null !== $user_id ? (int) $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		if ( $user_id > 0 ) {
			$plan                  = self::get_user_active_plan( $user_id );
			$config                = self::filter_config_for_plan( $config, $plan, $user_id );
			$config['active_plan'] = self::get_plan_summary( $plan );
		}

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
		$resolution = self::get_user_plan_resolution( $user_id );
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
			'active_plan'  => array_merge(
				self::get_plan_summary( $resolution['plan'] ),
				array(
					'source' => $resolution['source'],
				)
			),
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

			<?php if ( ! empty( $summary['active_plan']['public_name'] ) ) : ?>
				<p class="alorbach-account-widget__renewal">
					<?php
					printf(
						/* translators: %s: plan name */
						esc_html__( 'Active plan: %s', 'alorbach-ai-gateway' ),
						esc_html( $summary['active_plan']['public_name'] )
					);
					?>
				</p>
			<?php endif; ?>

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
		$plan = self::get_plan( $plan_slug );
		return isset( $plan['included_credits_uc'] ) ? (int) $plan['included_credits_uc'] : 0;
	}

	/**
	 * Get one plan by slug.
	 *
	 * @param string $plan_slug Plan slug.
	 * @return array
	 */
	public static function get_plan( $plan_slug ) {
		$plans     = self::get_normalized_plans();
		$plan_slug = sanitize_key( (string) $plan_slug );

		if ( $plan_slug && isset( $plans[ $plan_slug ] ) ) {
			return $plans[ $plan_slug ];
		}

		return $plans[ self::BASIC_PLAN_SLUG ] ?? self::get_default_plans()[ self::BASIC_PLAN_SLUG ];
	}

	/**
	 * Get the active plan for a user.
	 *
	 * @param int|null $user_id User ID or current user.
	 * @return array
	 */
	public static function get_user_active_plan( $user_id = null ) {
		$resolution = self::get_user_plan_resolution( $user_id );
		return $resolution['plan'];
	}

	/**
	 * Get the resolved plan plus its source for a user.
	 *
	 * @param int|null $user_id User ID or current user.
	 * @return array
	 */
	public static function get_user_plan_resolution( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return array(
				'plan'             => self::get_plan( self::BASIC_PLAN_SLUG ),
				'source'           => 'basic',
				'manual_plan_slug' => '',
				'paid_plan_slug'   => '',
			);
		}

		$manual_plan_slug = self::get_user_manual_plan_slug( $user_id );
		if ( $manual_plan_slug ) {
			return array(
				'plan'             => self::get_plan( $manual_plan_slug ),
				'source'           => 'manual',
				'manual_plan_slug' => $manual_plan_slug,
				'paid_plan_slug'   => '',
			);
		}

		$paid_plan_slug = self::get_active_paid_plan_slug_for_user( $user_id );
		if ( $paid_plan_slug ) {
			return array(
				'plan'             => self::get_plan( $paid_plan_slug ),
				'source'           => 'subscription',
				'manual_plan_slug' => '',
				'paid_plan_slug'   => $paid_plan_slug,
			);
		}

		return array(
			'plan'             => self::get_plan( self::BASIC_PLAN_SLUG ),
			'source'           => 'basic',
			'manual_plan_slug' => '',
			'paid_plan_slug'   => '',
		);
	}

	/**
	 * Get the resolution source for a user's active plan.
	 *
	 * @param int|null $user_id User ID or current user.
	 * @return string
	 */
	public static function get_user_active_plan_source( $user_id = null ) {
		$resolution = self::get_user_plan_resolution( $user_id );
		return (string) $resolution['source'];
	}

	/**
	 * Read the manual plan override for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_user_manual_plan_slug( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}

		$plan_slug = sanitize_key( (string) get_user_meta( $user_id, self::USER_PLAN_OVERRIDE_META_KEY, true ) );
		if ( '' === $plan_slug ) {
			return '';
		}

		$plan = self::get_plan( $plan_slug );
		return $plan['slug'] === $plan_slug ? $plan_slug : '';
	}

	/**
	 * Set or clear the manual plan override for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $plan_slug Plan slug or empty to clear.
	 * @return void
	 */
	public static function set_user_manual_plan_slug( $user_id, $plan_slug ) {
		$user_id  = (int) $user_id;
		$plan_slug = sanitize_key( (string) $plan_slug );

		if ( $user_id <= 0 ) {
			return;
		}

		if ( '' === $plan_slug ) {
			delete_user_meta( $user_id, self::USER_PLAN_OVERRIDE_META_KEY );
			return;
		}

		$plan = self::get_plan( $plan_slug );
		if ( $plan['slug'] !== $plan_slug ) {
			return;
		}

		update_user_meta( $user_id, self::USER_PLAN_OVERRIDE_META_KEY, $plan_slug );
	}

	/**
	 * Check whether a capability is enabled for a user.
	 *
	 * @param int|null $user_id User ID or current user.
	 * @param string   $capability Capability key.
	 * @param string   $model Optional model ID.
	 * @return bool
	 */
	public static function user_can_access_capability( $user_id, $capability, $model = '' ) {
		if ( self::has_balance_access_override( $user_id ) ) {
			return true;
		}

		return self::plan_allows_capability( self::get_user_active_plan( $user_id ), $capability, $model );
	}

	/**
	 * Check whether a capability is enabled for a plan.
	 *
	 * @param array  $plan Plan array.
	 * @param string $capability Capability key.
	 * @param string $model Optional model ID.
	 * @return bool
	 */
	public static function plan_allows_capability( $plan, $capability, $model = '' ) {
		$capability = sanitize_key( (string) $capability );
		if ( ! in_array( $capability, self::PLAN_CAPABILITIES, true ) ) {
			return false;
		}

		if ( empty( $plan['capabilities'][ $capability ] ) ) {
			return false;
		}

		return self::is_model_allowed_for_plan( $plan, $capability, $model );
	}

	/**
	 * Get models allowed for a plan and capability.
	 *
	 * @param array  $plan Plan array.
	 * @param string $capability Capability key.
	 * @param array  $available_models Source model list.
	 * @return array
	 */
	public static function get_allowed_models_for_plan( $plan, $capability, $available_models ) {
		$available_models = is_array( $available_models ) ? array_values( array_filter( $available_models, 'is_string' ) ) : array();
		$capability       = sanitize_key( (string) $capability );
		$allowed          = isset( $plan['allowed_models'][ $capability ] ) && is_array( $plan['allowed_models'][ $capability ] ) ? $plan['allowed_models'][ $capability ] : array();

		if ( empty( $allowed ) ) {
			return $available_models;
		}

		return array_values( array_intersect( $available_models, $allowed ) );
	}

	/**
	 * Get a frontend-safe plan summary.
	 *
	 * @param array $plan Plan array.
	 * @return array
	 */
	public static function get_plan_summary( $plan ) {
		return array(
			'slug'                => (string) ( $plan['slug'] ?? '' ),
			'public_name'         => (string) ( $plan['public_name'] ?? '' ),
			'is_free'             => ! empty( $plan['is_free'] ),
			'is_active'           => ! empty( $plan['is_active'] ),
			'billing_interval'    => (string) ( $plan['billing_interval'] ?? 'month' ),
			'price_usd'           => (float) ( $plan['price_usd'] ?? 0 ),
			'included_credits_uc' => (int) ( $plan['included_credits_uc'] ?? 0 ),
			'capabilities'        => self::normalize_capabilities( $plan['capabilities'] ?? array() ),
			'allowed_models'      => self::normalize_allowed_models( $plan['allowed_models'] ?? array(), self::get_plan_model_catalog() ),
		);
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

	/**
	 * Get default capability map.
	 *
	 * @param bool $enabled Default enabled state.
	 * @return array
	 */
	private static function get_default_capabilities( $enabled = true ) {
		return array(
			'chat'  => (bool) $enabled,
			'image' => (bool) $enabled,
			'audio' => (bool) $enabled,
			'video' => (bool) $enabled,
		);
	}

	/**
	 * Get an empty model allowlist map.
	 *
	 * @return array
	 */
	private static function get_empty_allowed_models() {
		return array(
			'chat'  => array(),
			'image' => array(),
			'audio' => array(),
			'video' => array(),
		);
	}

	/**
	 * Normalize capability booleans.
	 *
	 * @param array      $capabilities Raw capability values.
	 * @param array|null $defaults Default values when keys are missing.
	 * @return array
	 */
	private static function normalize_capabilities( $capabilities, $defaults = null ) {
		$defaults     = is_array( $defaults ) ? $defaults : self::get_default_capabilities( true );
		$capabilities = is_array( $capabilities ) ? $capabilities : array();
		$normalized   = array();

		foreach ( self::PLAN_CAPABILITIES as $capability ) {
			$normalized[ $capability ] = array_key_exists( $capability, $capabilities )
				? (bool) $capabilities[ $capability ]
				: ! empty( $defaults[ $capability ] );
		}

		return $normalized;
	}

	/**
	 * Normalize allowed model lists by capability.
	 *
	 * @param array $allowed_models Raw allowlists.
	 * @param array $model_catalog Catalog keyed by capability.
	 * @return array
	 */
	private static function normalize_allowed_models( $allowed_models, $model_catalog ) {
		$allowed_models = is_array( $allowed_models ) ? $allowed_models : array();
		$model_catalog  = is_array( $model_catalog ) ? $model_catalog : self::get_plan_model_catalog();
		$normalized     = self::get_empty_allowed_models();

		foreach ( self::PLAN_CAPABILITIES as $capability ) {
			$rows = isset( $allowed_models[ $capability ] ) ? $allowed_models[ $capability ] : array();
			$rows = is_array( $rows ) ? $rows : array();
			$rows = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_text_field', $rows ),
						function ( $model ) use ( $model_catalog, $capability ) {
							return in_array( $model, $model_catalog[ $capability ] ?? array(), true );
						}
					)
				)
			);
			$normalized[ $capability ] = $rows;
		}

		return $normalized;
	}

	/**
	 * Get configured model catalogs by capability.
	 *
	 * @return array
	 */
	private static function get_plan_model_catalog() {
		$admin = \Alorbach\AIGateway\Admin\Admin_Demo_Defaults::class;

		return array(
			'chat'  => array_values( $admin::get_text_models() ),
			'image' => array_values( $admin::get_image_models() ),
			'audio' => array_values( $admin::get_audio_models() ),
			'video' => array_values( $admin::get_video_models() ),
		);
	}

	/**
	 * Filter config defaults and capabilities for a plan.
	 *
	 * @param array $config Base config.
	 * @param array $plan Active plan.
	 * @return array
	 */
	private static function filter_config_for_plan( $config, $plan, $user_id = 0 ) {
		if ( self::has_balance_access_override( $user_id ) ) {
			$config['plan_capabilities'] = self::get_default_capabilities( true );
			return $config;
		}

		$capability_map = self::normalize_capabilities( $plan['capabilities'] ?? array(), self::get_default_capabilities( true ) );

		$config['plan_capabilities']            = $capability_map;
		$config['capabilities']['chat_models']  = ! empty( $capability_map['chat'] ) ? self::get_allowed_models_for_plan( $plan, 'chat', $config['capabilities']['chat_models'] ?? array() ) : array();
		$config['capabilities']['image_models'] = ! empty( $capability_map['image'] ) ? self::get_allowed_models_for_plan( $plan, 'image', $config['capabilities']['image_models'] ?? array() ) : array();
		$config['capabilities']['audio_models'] = ! empty( $capability_map['audio'] ) ? self::get_allowed_models_for_plan( $plan, 'audio', $config['capabilities']['audio_models'] ?? array() ) : array();
		$config['capabilities']['video_models'] = ! empty( $capability_map['video'] ) ? self::get_allowed_models_for_plan( $plan, 'video', $config['capabilities']['video_models'] ?? array() ) : array();

		$config['defaults']['chat_model']  = self::pick_allowed_default( $config['defaults']['chat_model'] ?? '', $config['capabilities']['chat_models'] );
		$config['defaults']['image_model'] = self::pick_allowed_default( $config['defaults']['image_model'] ?? '', $config['capabilities']['image_models'] );
		$config['defaults']['audio_model'] = self::pick_allowed_default( $config['defaults']['audio_model'] ?? '', $config['capabilities']['audio_models'] );
		$config['defaults']['video_model'] = self::pick_allowed_default( $config['defaults']['video_model'] ?? '', $config['capabilities']['video_models'] );

		if ( empty( $capability_map['image'] ) ) {
			$config['defaults']['image_model']         = '';
			$config['capabilities']['image_sizes']     = array();
			$config['capabilities']['image_qualities'] = array();
			$config['defaults']['image_size']          = '';
			$config['defaults']['image_quality']       = '';
		}

		if ( empty( $capability_map['video'] ) ) {
			$config['defaults']['video_model']         = '';
			$config['capabilities']['video_sizes']     = array();
			$config['capabilities']['video_durations'] = array();
		}

		return $config;
	}

	/**
	 * Basic users with a positive balance can spend that balance on all configured models.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function has_balance_access_override( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$resolution = self::get_user_plan_resolution( $user_id );
		if ( (string) $resolution['source'] !== 'basic' ) {
			return false;
		}

		return Ledger::get_balance( $user_id ) > 0;
	}

	/**
	 * Pick a default value that exists in the allowed list.
	 *
	 * @param string $current Current default.
	 * @param array  $options Allowed options.
	 * @return string
	 */
	private static function pick_allowed_default( $current, $options ) {
		$options = is_array( $options ) ? array_values( $options ) : array();
		if ( empty( $options ) ) {
			return '';
		}

		return in_array( $current, $options, true ) ? $current : (string) $options[0];
	}

	/**
	 * Check whether a specific model is allowed for a plan.
	 *
	 * @param array  $plan Plan array.
	 * @param string $capability Capability key.
	 * @param string $model Optional model.
	 * @return bool
	 */
	private static function is_model_allowed_for_plan( $plan, $capability, $model = '' ) {
		$model = (string) $model;
		if ( '' === $model ) {
			return true;
		}

		$allowed_models = isset( $plan['allowed_models'][ $capability ] ) && is_array( $plan['allowed_models'][ $capability ] ) ? $plan['allowed_models'][ $capability ] : array();
		if ( empty( $allowed_models ) ) {
			return true;
		}

		return in_array( $model, $allowed_models, true );
	}

	/**
	 * Resolve the active paid plan slug from WooCommerce subscriptions.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function get_active_paid_plan_slug_for_user( $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return '';
		}

		$product_mapping = get_option( 'alorbach_product_to_plan', array() );
		$product_mapping = is_array( $product_mapping ) ? $product_mapping : array();
		if ( empty( $product_mapping ) ) {
			return '';
		}

		$plans         = self::get_normalized_plans();
		$subscriptions = wcs_get_users_subscriptions( $user_id );
		if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
			return '';
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) ) {
				continue;
			}

			$status = method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : '';
			if ( ! in_array( $status, array( 'active', 'pending-cancel' ), true ) ) {
				continue;
			}

			foreach ( $subscription->get_items() as $item ) {
				$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
				$plan_slug  = isset( $product_mapping[ $product_id ] ) ? sanitize_key( (string) $product_mapping[ $product_id ] ) : '';

				if ( ! $plan_slug || self::BASIC_PLAN_SLUG === $plan_slug ) {
					continue;
				}

				if ( isset( $plans[ $plan_slug ] ) && ! empty( $plans[ $plan_slug ]['is_active'] ) ) {
					return $plan_slug;
				}
			}
		}

		return '';
	}
}
