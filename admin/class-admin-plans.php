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
 * Class Admin_Plans
 */
class Admin_Plans {

	/**
	 * Default plans.
	 *
	 * @return array
	 */
	private static function get_defaults() {
		return array(
			'plan_10'  => array( 'name' => '10 $/Monat', 'price_usd' => 10, 'credits_per_month' => 10000000, 'slug' => 'plan_10' ),
			'plan_20'  => array( 'name' => '20 $/Monat', 'price_usd' => 20, 'credits_per_month' => 20000000, 'slug' => 'plan_20' ),
			'plan_50'  => array( 'name' => '50 $/Monat', 'price_usd' => 50, 'credits_per_month' => 50000000, 'slug' => 'plan_50' ),
		);
	}

	/**
	 * Render Plans page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_plans_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_plans_nonce'] ) ), 'alorbach_plans' ) ) {
			$plans = isset( $_POST['plans'] ) && is_array( $_POST['plans'] ) ? $_POST['plans'] : array();
			$saved = array();
			foreach ( $plans as $slug => $p ) {
				$saved[ sanitize_key( $slug ) ] = array(
					'name'              => isset( $p['name'] ) ? sanitize_text_field( wp_unslash( $p['name'] ) ) : '',
					'price_usd'         => isset( $p['price_usd'] ) ? (float) $p['price_usd'] : 0,
					'credits_per_month' => isset( $p['credits_per_month'] ) ? (int) $p['credits_per_month'] : 0,
					'slug'              => sanitize_key( $slug ),
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

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Plans saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$plans = get_option( 'alorbach_plans', self::get_defaults() );
		$plans = is_array( $plans ) ? $plans : self::get_defaults();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Abonnement Plans', 'alorbach-ai-gateway' ); ?></h1>
			<p><?php esc_html_e( '1000 UC = 1 Credit (user display).', 'alorbach-ai-gateway' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'alorbach_plans', 'alorbach_plans_nonce' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Slug', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Name', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Price (USD/month)', 'alorbach-ai-gateway' ); ?></th>
							<th><?php esc_html_e( 'Credits (UC/month)', 'alorbach-ai-gateway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plans as $slug => $plan ) : ?>
							<tr>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><input type="text" name="plans[<?php echo esc_attr( $slug ); ?>][name]" value="<?php echo esc_attr( isset( $plan['name'] ) ? $plan['name'] : '' ); ?>" class="regular-text" /></td>
								<td><input type="number" name="plans[<?php echo esc_attr( $slug ); ?>][price_usd]" value="<?php echo esc_attr( isset( $plan['price_usd'] ) ? $plan['price_usd'] : '' ); ?>" step="0.01" min="0" /></td>
								<td><input type="number" name="plans[<?php echo esc_attr( $slug ); ?>][credits_per_month]" value="<?php echo esc_attr( isset( $plan['credits_per_month'] ) ? $plan['credits_per_month'] : '' ); ?>" min="0" /></td>
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
												<?php echo esc_html( isset( $plan['name'] ) ? $plan['name'] : $slug ); ?>
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
