<?php
/**
 * Admin: Subscription plan management.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin: Subscription plan management.
 */
class Admin_Plans {

	/**
	 * Render Plans page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}

		if ( Admin_Helper::verify_post_nonce( 'alorbach_plans_nonce', 'alorbach_plans' ) ) {
			$rows  = isset( $_POST['plans'] ) && is_array( $_POST['plans'] ) ? $_POST['plans'] : array();
			$saved = array();
			$order = 10;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$raw_slug   = isset( $row['slug'] ) ? wp_unslash( $row['slug'] ) : '';
				$clean_slug = sanitize_key( $raw_slug );
				if ( '' === $clean_slug ) {
					continue;
				}

				if ( \Alorbach\AIGateway\Integration_Service::BASIC_PLAN_SLUG === $clean_slug ) {
					$row['is_free'] = 1;
				}

				$saved[ $clean_slug ] = \Alorbach\AIGateway\Integration_Service::prepare_plan_for_storage( $clean_slug, $row, $order );
				$order += 10;
			}

			if ( ! isset( $saved[ \Alorbach\AIGateway\Integration_Service::BASIC_PLAN_SLUG ] ) ) {
				$defaults = \Alorbach\AIGateway\Integration_Service::get_default_plans();
				$saved[ \Alorbach\AIGateway\Integration_Service::BASIC_PLAN_SLUG ] = \Alorbach\AIGateway\Integration_Service::prepare_plan_for_storage(
					\Alorbach\AIGateway\Integration_Service::BASIC_PLAN_SLUG,
					$defaults[ \Alorbach\AIGateway\Integration_Service::BASIC_PLAN_SLUG ],
					0
				);
			}

			update_option( 'alorbach_plans', apply_filters( 'alorbach_plans', $saved ) );

			if ( class_exists( 'WooCommerce' ) && isset( $_POST['product_to_plan'] ) && is_array( $_POST['product_to_plan'] ) ) {
				$mapping = array();
				foreach ( $_POST['product_to_plan'] as $product_id => $plan_slug ) {
					$product_id = (int) $product_id;
					$plan_slug  = sanitize_key( wp_unslash( $plan_slug ) );
					if ( $product_id > 0 && $plan_slug ) {
						$mapping[ $product_id ] = $plan_slug;
					}
				}
				update_option( 'alorbach_product_to_plan', $mapping );
			}

			Admin_Helper::render_notice( __( 'Plans saved.', 'alorbach-ai-gateway' ) );
		}

		$plans           = array_values( \Alorbach\AIGateway\Integration_Service::get_normalized_plans() );
		$text_models     = Admin_Demo_Defaults::get_text_models();
		$image_models    = Admin_Demo_Defaults::get_image_models();
		$audio_models    = Admin_Demo_Defaults::get_audio_models();
		$video_models    = Admin_Demo_Defaults::get_video_models();
		$product_mapping = get_option( 'alorbach_product_to_plan', array() );
		$product_mapping = is_array( $product_mapping ) ? $product_mapping : array();
		$products        = class_exists( 'WooCommerce' ) ? wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'type' => array( 'subscription', 'variable-subscription', 'simple' ) ) ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscription Plans', 'alorbach-ai-gateway' ); ?></h1>
			<p><?php esc_html_e( '1000 UC = 1 Credit (user display). Users without an active paid subscription automatically fall back to the Basic plan.', 'alorbach-ai-gateway' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'alorbach_plans', 'alorbach_plans_nonce' ); ?>

				<p>
					<button type="button" class="button" id="alorbach-add-plan"><?php esc_html_e( 'Add plan', 'alorbach-ai-gateway' ); ?></button>
				</p>

				<div id="alorbach-plan-list">
					<?php foreach ( $plans as $index => $plan ) : ?>
						<?php self::render_plan_card( (int) $index, $plan, $text_models, $image_models, $audio_models, $video_models ); ?>
					<?php endforeach; ?>
				</div>

				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<h2><?php esc_html_e( 'WooCommerce Product to Plan Mapping', 'alorbach-ai-gateway' ); ?></h2>
					<p><?php esc_html_e( 'Map subscription products to plans. On successful renewal, the user receives the mapped plan credits. If no paid plan resolves, the user stays on Basic.', 'alorbach-ai-gateway' ); ?></p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'alorbach-ai-gateway' ); ?></th>
								<th><?php esc_html_e( 'Plan', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $products as $product ) : ?>
								<tr>
									<td><?php echo esc_html( $product->get_name() ); ?> (ID: <?php echo (int) $product->get_id(); ?>)</td>
									<td>
										<select name="product_to_plan[<?php echo (int) $product->get_id(); ?>]">
											<option value=""><?php esc_html_e( 'None', 'alorbach-ai-gateway' ); ?></option>
											<?php foreach ( $plans as $plan ) : ?>
												<option value="<?php echo esc_attr( $plan['slug'] ); ?>" <?php selected( $product_mapping[ $product->get_id() ] ?? '', $plan['slug'] ); ?>>
													<?php echo esc_html( $plan['public_name'] ?: $plan['slug'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if ( empty( $products ) ) : ?>
								<tr><td colspan="2"><?php esc_html_e( 'No products found.', 'alorbach-ai-gateway' ); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'alorbach-ai-gateway' ); ?>" /></p>
			</form>

			<template id="alorbach-plan-template">
				<?php self::render_plan_card( '__INDEX__', self::get_empty_plan_template(), $text_models, $image_models, $audio_models, $video_models ); ?>
			</template>
		</div>

		<style>
			.alorbach-plan-card { border: 1px solid #ccd0d4; background: #fff; padding: 16px; margin: 0 0 16px; }
			.alorbach-plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
			.alorbach-plan-field label { display: block; font-weight: 600; margin-bottom: 4px; }
			.alorbach-plan-capabilities { display: flex; gap: 16px; flex-wrap: wrap; margin: 12px 0; }
			.alorbach-plan-models { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
			.alorbach-plan-card select[multiple] { min-height: 120px; width: 100%; }
			.alorbach-plan-actions { margin-top: 12px; }
			.alorbach-plan-card.is-basic { border-color: #2271b1; }
		</style>

		<script>
			(function() {
				var list = document.getElementById('alorbach-plan-list');
				var addButton = document.getElementById('alorbach-add-plan');
				var template = document.getElementById('alorbach-plan-template');

				if (!list || !addButton || !template) {
					return;
				}

				var counter = list.querySelectorAll('.alorbach-plan-card').length;

				addButton.addEventListener('click', function() {
					var html = template.innerHTML.replace(/__INDEX__/g, String(counter++));
					list.insertAdjacentHTML('beforeend', html);
				});

				list.addEventListener('click', function(event) {
					var button = event.target.closest('.alorbach-remove-plan');
					if (!button) {
						return;
					}

					var card = button.closest('.alorbach-plan-card');
					if (card && !card.classList.contains('is-basic')) {
						card.remove();
					}
				});
			}());
		</script>
		<?php
	}

	/**
	 * Render one editable plan card.
	 *
	 * @param int|string $index Row index.
	 * @param array      $plan Plan data.
	 * @param array      $text_models Chat models.
	 * @param array      $image_models Image models.
	 * @param array      $audio_models Audio models.
	 * @param array      $video_models Video models.
	 * @return void
	 */
	private static function render_plan_card( $index, $plan, $text_models, $image_models, $audio_models, $video_models ) {
		$slug     = isset( $plan['slug'] ) ? (string) $plan['slug'] : '';
		$is_basic = $slug === \Alorbach\AIGateway\Integration_Service::BASIC_PLAN_SLUG;
		$name     = 'plans[' . $index . ']';
		?>
		<div class="alorbach-plan-card <?php echo $is_basic ? 'is-basic' : ''; ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[is_free]" value="<?php echo $is_basic || ! empty( $plan['is_free'] ) ? '1' : '0'; ?>" />

			<div class="alorbach-plan-grid">
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Slug', 'alorbach-ai-gateway' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" <?php disabled( $is_basic ); ?> />
					<?php if ( $is_basic ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" />
					<?php endif; ?>
				</div>
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Public name', 'alorbach-ai-gateway' ); ?></label>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[public_name]" value="<?php echo esc_attr( $plan['public_name'] ?? '' ); ?>" />
				</div>
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Billing interval', 'alorbach-ai-gateway' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[billing_interval]">
						<option value="month" <?php selected( $plan['billing_interval'] ?? 'month', 'month' ); ?>><?php esc_html_e( 'Month', 'alorbach-ai-gateway' ); ?></option>
						<option value="year" <?php selected( $plan['billing_interval'] ?? '', 'year' ); ?>><?php esc_html_e( 'Year', 'alorbach-ai-gateway' ); ?></option>
						<option value="week" <?php selected( $plan['billing_interval'] ?? '', 'week' ); ?>><?php esc_html_e( 'Week', 'alorbach-ai-gateway' ); ?></option>
					</select>
				</div>
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Price (USD)', 'alorbach-ai-gateway' ); ?></label>
					<input type="number" name="<?php echo esc_attr( $name ); ?>[price_usd]" value="<?php echo esc_attr( $plan['price_usd'] ?? 0 ); ?>" step="0.01" min="0" />
				</div>
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Included credits (UC)', 'alorbach-ai-gateway' ); ?></label>
					<input type="number" name="<?php echo esc_attr( $name ); ?>[included_credits_uc]" value="<?php echo esc_attr( $plan['included_credits_uc'] ?? 0 ); ?>" min="0" step="1000" />
				</div>
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Display order', 'alorbach-ai-gateway' ); ?></label>
					<input type="number" name="<?php echo esc_attr( $name ); ?>[display_order]" value="<?php echo esc_attr( $plan['display_order'] ?? 10 ); ?>" step="1" />
				</div>
				<div class="alorbach-plan-field">
					<label><?php esc_html_e( 'Active', 'alorbach-ai-gateway' ); ?></label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[is_active]" value="1" <?php checked( ! empty( $plan['is_active'] ) ); ?> />
				</div>
			</div>

			<div class="alorbach-plan-capabilities">
				<?php foreach ( array( 'chat', 'image', 'audio', 'video' ) as $capability ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[capabilities][<?php echo esc_attr( $capability ); ?>]" value="1" <?php checked( ! empty( $plan['capabilities'][ $capability ] ) ); ?> />
						<?php echo esc_html( ucfirst( $capability ) ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="alorbach-plan-models">
				<?php self::render_allowed_models_field( $name, 'chat', $text_models, $plan['allowed_models']['chat'] ?? array() ); ?>
				<?php self::render_allowed_models_field( $name, 'image', $image_models, $plan['allowed_models']['image'] ?? array() ); ?>
				<?php self::render_allowed_models_field( $name, 'audio', $audio_models, $plan['allowed_models']['audio'] ?? array() ); ?>
				<?php self::render_allowed_models_field( $name, 'video', $video_models, $plan['allowed_models']['video'] ?? array() ); ?>
			</div>

			<div class="alorbach-plan-actions">
				<?php if ( $is_basic ) : ?>
					<span class="description"><?php esc_html_e( 'Basic is the protected fallback plan and cannot be removed.', 'alorbach-ai-gateway' ); ?></span>
				<?php else : ?>
					<button type="button" class="button-link-delete alorbach-remove-plan"><?php esc_html_e( 'Remove plan', 'alorbach-ai-gateway' ); ?></button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one allowed-models field.
	 *
	 * @param string $base_name Base form name.
	 * @param string $capability Capability key.
	 * @param array  $options Model options.
	 * @param array  $selected Selected model ids.
	 * @return void
	 */
	private static function render_allowed_models_field( $base_name, $capability, $options, $selected ) {
		?>
		<div class="alorbach-plan-field">
			<label><?php echo esc_html( ucfirst( $capability ) . ' ' . __( 'models', 'alorbach-ai-gateway' ) ); ?></label>
			<select multiple name="<?php echo esc_attr( $base_name ); ?>[allowed_models][<?php echo esc_attr( $capability ); ?>][]">
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( in_array( $option, $selected, true ) ); ?>><?php echo esc_html( $option ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Leave empty to allow all configured models for this capability.', 'alorbach-ai-gateway' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Empty template for new rows.
	 *
	 * @return array
	 */
	private static function get_empty_plan_template() {
		return array(
			'slug'                => '',
			'public_name'         => '',
			'billing_interval'    => 'month',
			'price_usd'           => 0,
			'included_credits_uc' => 0,
			'display_order'       => 10,
			'is_active'           => true,
			'is_free'             => false,
			'capabilities'        => array(
				'chat'  => true,
				'image' => true,
				'audio' => true,
				'video' => true,
			),
			'allowed_models'      => array(
				'chat'  => array(),
				'image' => array(),
				'audio' => array(),
				'video' => array(),
			),
		);
	}
}
