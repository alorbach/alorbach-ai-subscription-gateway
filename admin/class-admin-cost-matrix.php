<?php
/**
 * Admin: Cost matrix and limits.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Cost_Matrix
 */
class Admin_Cost_Matrix {

	/**
	 * Render Cost Matrix page.
	 */
	public static function render() {
		if ( isset( $_POST['alorbach_cost_matrix_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_cost_matrix_nonce'] ) ), 'alorbach_cost_matrix' ) ) {
			$matrix = isset( $_POST['cost_matrix'] ) && is_array( $_POST['cost_matrix'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['cost_matrix'] ) ) : array();
			update_option( 'alorbach_cost_matrix', $matrix );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Cost matrix saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$matrix = get_option( 'alorbach_cost_matrix', array() );
		$matrix = is_array( $matrix ) ? $matrix : array();
		$models = array( 'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Limits & API Costs', 'alorbach-ai-gateway' ); ?></h1>
			<p><?php esc_html_e( 'Costs per 1M tokens (in UC). 1 UC = 0.000001 USD.', 'alorbach-ai-gateway' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'alorbach_cost_matrix', 'alorbach_cost_matrix_nonce' ); ?>
				<table class="form-table">
					<?php foreach ( $models as $model ) : ?>
						<tr>
							<th><?php echo esc_html( $model ); ?></th>
							<td>
								<input type="number" name="cost_matrix[<?php echo esc_attr( $model ); ?>][input]" value="<?php echo esc_attr( isset( $matrix[ $model ]['input'] ) ? $matrix[ $model ]['input'] : '' ); ?>" placeholder="Input UC/1M" /> Input
								<input type="number" name="cost_matrix[<?php echo esc_attr( $model ); ?>][output]" value="<?php echo esc_attr( isset( $matrix[ $model ]['output'] ) ? $matrix[ $model ]['output'] : '' ); ?>" placeholder="Output UC/1M" /> Output
								<input type="number" name="cost_matrix[<?php echo esc_attr( $model ); ?>][cached]" value="<?php echo esc_attr( isset( $matrix[ $model ]['cached'] ) ? $matrix[ $model ]['cached'] : '' ); ?>" placeholder="Cached UC/1M" /> Cached
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'alorbach-ai-gateway' ); ?>" /></p>
			</form>
		</div>
		<?php
	}
}
