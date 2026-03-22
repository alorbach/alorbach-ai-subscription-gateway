<?php
/**
 * Admin: Abonnement plans (10$, 20$, 50$).
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin: Subscription plan management.
 *
 * Provides the UI for defining AI Gateway subscription plans (name, price,
 * monthly UC credit allocation) and mapping WooCommerce products to plans.
 * Plans are stored via the `alorbach_plans` option and can be extended
 * through the `alorbach_plans` filter.
 *
 * @package Alorbach\AIGateway\Admin
 * @since   1.0.0
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
			$plans = isset( $_POST['plans'] ) && is_array( $_POST['plans'] ) ? $_POST['plans'] : array();
			$saved = array();
			$order = 10;
			foreach ( $plans as $slug => $p ) {
				$clean_slug = sanitize_key( $slug );
				if ( '' === $clean_slug ) {
					continue;
				}
				$saved[ $clean_slug ] = \Alorbach\AIGateway\Integration_Service::prepare_plan_for_storage( $clean_slug, $p, $order );
				$order += 10;
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

		$plans = \Alorbach\AIGateway\Integration_Service::get_normalized_plans();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscription Plans', 'alorbach-ai-gateway' ); ?></h1>
			<p><?php esc_html_e( '1000 UC = 1 Credit (user display).', 'alorbach-ai-gateway' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'alorbach_plans', 'alorbach_plans_nonce' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Slug', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Public name', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Billing interval', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Price (USD)', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Included credits (UC)', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Display order', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Active', 'alorbach-ai-gateway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plans as $slug => $plan ) : ?>
							<tr>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><input type="text" name="plans[<?php echo esc_attr( $slug ); ?>][public_name]" value="<?php echo esc_attr( isset( $plan['public_name'] ) ? $plan['public_name'] : '' ); ?>" class="regular-text" /></td>
								<td>
									<select name="plans[<?php echo esc_attr( $slug ); ?>][billing_interval]">
										<option value="month" <?php selected( $plan['billing_interval'], 'month' ); ?>><?php esc_html_e( 'Month', 'alorbach-ai-gateway' ); ?></option>
										<option value="year" <?php selected( $plan['billing_interval'], 'year' ); ?>><?php esc_html_e( 'Year', 'alorbach-ai-gateway' ); ?></option>
										<option value="week" <?php selected( $plan['billing_interval'], 'week' ); ?>><?php esc_html_e( 'Week', 'alorbach-ai-gateway' ); ?></option>
									</select>
								</td>
								<td><input type="number" name="plans[<?php echo esc_attr( $slug ); ?>][price_usd]" value="<?php echo esc_attr( isset( $plan['price_usd'] ) ? $plan['price_usd'] : '' ); ?>" step="0.01" min="0" /></td>
								<td><input type="number" name="plans[<?php echo esc_attr( $slug ); ?>][included_credits_uc]" value="<?php echo esc_attr( isset( $plan['included_credits_uc'] ) ? $plan['included_credits_uc'] : '' ); ?>" min="0" step="1000" /></td>
								<td><input type="number" name="plans[<?php echo esc_attr( $slug ); ?>][display_order]" value="<?php echo esc_attr( isset( $plan['display_order'] ) ? $plan['display_order'] : '' ); ?>" step="1" /></td>
								<td><input type="checkbox" name="plans[<?php echo esc_attr( $slug ); ?>][is_active]" value="1" <?php checked( ! empty( $plan['is_active'] ) ); ?> /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<?php
				$product_mapping = get_option( 'alorbach_product_to_plan', array() );
				$product_mapping = is_array( $product_mapping ) ? $product_mapping : array();
				$products        = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'type' => array( 'subscription', 'variable-subscription', 'simple' ) ) );
				?>
				<h2><?php esc_html_e( 'WooCommerce Product → Plan Mapping', 'alorbach-ai-gateway' ); ?></h2>
				<p><?php esc_html_e( 'Map subscription products to plans. When a renewal completes, the user receives the plan credits.', 'alorbach-ai-gateway' ); ?></p>
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
										<option value=""><?php esc_html_e( '— None —', 'alorbach-ai-gateway' ); ?></option>
										<?php foreach ( $plans as $slug => $plan ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( isset( $product_mapping[ $product->get_id() ] ) ? $product_mapping[ $product->get_id() ] : '', $slug ); ?>>
												<?php echo esc_html( isset( $plan['public_name'] ) ? $plan['public_name'] : $slug ); ?>
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
		</div>
		<?php
	}
}
