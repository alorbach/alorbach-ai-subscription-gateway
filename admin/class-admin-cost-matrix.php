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
	 * Get the provider to use when testing a model.
	 * Uses configured API keys: if OpenAI is not set but Azure is, use Azure for GPT models.
	 *
	 * @param string $model Model ID (e.g. gpt-5.2-chat, gemini-1.5-pro).
	 * @return string Provider: openai, azure, or google.
	 */
	public static function get_test_provider_for_model( $model ) {
		return \Alorbach\AIGateway\API_Client::get_provider_for_model( $model );
	}

	/**
	 * Render Cost Matrix page.
	 */
	public static function render() {
		$cost_matrix = get_option( 'alorbach_cost_matrix', array() );
		$cost_matrix = is_array( $cost_matrix ) ? $cost_matrix : array();
		$image_costs      = get_option( 'alorbach_image_costs', array() );
		$image_costs      = is_array( $image_costs ) ? $image_costs : array();
		$image_models     = get_option( 'alorbach_image_models', array() );
		$image_models     = is_array( $image_models ) ? $image_models : array( 'dall-e-3', 'gpt-image-1.5' );
		$image_model_costs = get_option( 'alorbach_image_model_costs', array() );
		$image_model_costs = is_array( $image_model_costs ) ? $image_model_costs : array();
		$image_default_model   = get_option( 'alorbach_image_default_model', 'dall-e-3' );
		$image_default_quality = get_option( 'alorbach_image_default_quality', 'medium' );
		$image_default_format  = get_option( 'alorbach_image_default_output_format', 'png' );
		$video_costs = get_option( 'alorbach_video_costs', array() );
		$video_costs = is_array( $video_costs ) ? $video_costs : array();
		$audio_costs = get_option( 'alorbach_audio_costs', array() );
		$audio_costs = is_array( $audio_costs ) ? $audio_costs : array();

		// Add custom text model (GET).
		if ( isset( $_GET['alorbach_add_model'] ) && isset( $_GET['model'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$new_model = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( wp_unslash( $_GET['model'] ) ) );
			if ( ! empty( $new_model ) && wp_verify_nonce( $nonce, 'alorbach_add_model' ) && $new_model !== 'default' ) {
				$cost_matrix[ $new_model ] = array( 'input' => '', 'output' => '', 'cached' => '' );
				update_option( 'alorbach_cost_matrix', $cost_matrix );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_add_model', 'model', '_wpnonce' ) ) );
				exit;
			}
		}

		// Remove custom text model (GET).
		if ( isset( $_GET['alorbach_remove_model'] ) && isset( $_GET['model'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$remove_model = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( wp_unslash( $_GET['model'] ) ) );
			if ( ! empty( $remove_model ) && wp_verify_nonce( $nonce, 'alorbach_remove_model' ) && $remove_model !== 'default' ) {
				unset( $cost_matrix[ $remove_model ] );
				update_option( 'alorbach_cost_matrix', $cost_matrix );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_remove_model', 'model', '_wpnonce' ) ) );
				exit;
			}
		}

		// Add custom image size (GET).
		if ( isset( $_GET['alorbach_add_image'] ) && isset( $_GET['size'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$new_size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( wp_unslash( $_GET['size'] ) ) );
			if ( ! empty( $new_size ) && wp_verify_nonce( $nonce, 'alorbach_add_image' ) ) {
				$image_costs[ $new_size ] = 40000;
				update_option( 'alorbach_image_costs', $image_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_add_image', 'size', '_wpnonce' ) ) );
				exit;
			}
		}

		// Remove custom image size (GET).
		if ( isset( $_GET['alorbach_remove_image'] ) && isset( $_GET['size'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$remove_size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( wp_unslash( $_GET['size'] ) ) );
			if ( ! empty( $remove_size ) && wp_verify_nonce( $nonce, 'alorbach_remove_image' ) ) {
				unset( $image_costs[ $remove_size ] );
				update_option( 'alorbach_image_costs', $image_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_remove_image', 'size', '_wpnonce' ) ) );
				exit;
			}
		}

		// Add custom audio model (GET).
		if ( isset( $_GET['alorbach_add_audio'] ) && isset( $_GET['model'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$new_audio = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( wp_unslash( $_GET['model'] ) ) );
			if ( ! empty( $new_audio ) && wp_verify_nonce( $nonce, 'alorbach_add_audio' ) && ! isset( $audio_costs[ $new_audio ] ) ) {
				$audio_costs[ $new_audio ] = 100;
				update_option( 'alorbach_audio_costs', $audio_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_add_audio', 'model', '_wpnonce' ) ) );
				exit;
			}
		}

		// Remove custom audio model (GET).
		if ( isset( $_GET['alorbach_remove_audio'] ) && isset( $_GET['model'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$remove_audio = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( wp_unslash( $_GET['model'] ) ) );
			if ( ! empty( $remove_audio ) && wp_verify_nonce( $nonce, 'alorbach_remove_audio' ) ) {
				unset( $audio_costs[ $remove_audio ] );
				update_option( 'alorbach_audio_costs', $audio_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_remove_audio', 'model', '_wpnonce' ) ) );
				exit;
			}
		}

		// Add custom video model (GET).
		if ( isset( $_GET['alorbach_add_video'] ) && isset( $_GET['model'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$new_video = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( wp_unslash( $_GET['model'] ) ) );
			if ( ! empty( $new_video ) && wp_verify_nonce( $nonce, 'alorbach_add_video' ) && ! isset( $video_costs[ $new_video ] ) ) {
				$video_costs[ $new_video ] = 400000;
				update_option( 'alorbach_video_costs', $video_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_add_video', 'model', '_wpnonce' ) ) );
				exit;
			}
		}

		// Remove custom video model (GET).
		if ( isset( $_GET['alorbach_remove_video'] ) && isset( $_GET['model'] ) ) {
			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$remove_video = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( wp_unslash( $_GET['model'] ) ) );
			if ( ! empty( $remove_video ) && wp_verify_nonce( $nonce, 'alorbach_remove_video' ) ) {
				unset( $video_costs[ $remove_video ] );
				update_option( 'alorbach_video_costs', $video_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_remove_video', 'model', '_wpnonce' ) ) );
				exit;
			}
		}

		if ( isset( $_POST['alorbach_cost_matrix_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_cost_matrix_nonce'] ) ), 'alorbach_cost_matrix' ) ) {
			$cost_matrix = array(
				'default' => array(
					'input'  => isset( $_POST['text_default_input'] ) ? absint( $_POST['text_default_input'] ) : 400000,
					'output' => isset( $_POST['text_default_output'] ) ? absint( $_POST['text_default_output'] ) : 1600000,
					'cached' => isset( $_POST['text_default_cached'] ) ? absint( $_POST['text_default_cached'] ) : 40000,
				),
			);
			if ( isset( $_POST['cost_matrix'] ) && is_array( $_POST['cost_matrix'] ) ) {
				foreach ( wp_unslash( $_POST['cost_matrix'] ) as $model => $costs ) {
					$model = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( $model ) );
					if ( empty( $model ) || $model === 'default' ) {
						continue;
					}
					$cost_matrix[ $model ] = array(
						'input'  => isset( $costs['input'] ) && $costs['input'] !== '' ? absint( $costs['input'] ) : '',
						'output' => isset( $costs['output'] ) && $costs['output'] !== '' ? absint( $costs['output'] ) : '',
						'cached' => isset( $costs['cached'] ) && $costs['cached'] !== '' ? absint( $costs['cached'] ) : '',
					);
				}
			}
			if ( isset( $_POST['image_costs'] ) && is_array( $_POST['image_costs'] ) ) {
				$image_costs = array();
				foreach ( wp_unslash( $_POST['image_costs'] ) as $size => $cost ) {
					$size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( $size ) );
					if ( ! empty( $size ) ) {
						$image_costs[ $size ] = absint( $cost );
					}
				}
			}
			if ( isset( $_POST['alorbach_image_default_model'] ) ) {
				$image_default_model = sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_model'] ) );
				update_option( 'alorbach_image_default_model', $image_default_model );
			}
			if ( isset( $_POST['alorbach_image_default_quality'] ) ) {
				$image_default_quality = sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_quality'] ) );
				update_option( 'alorbach_image_default_quality', $image_default_quality );
			}
			if ( isset( $_POST['alorbach_image_default_output_format'] ) ) {
				$image_default_format = sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_output_format'] ) );
				update_option( 'alorbach_image_default_output_format', $image_default_format );
			}
			if ( isset( $_POST['image_model_costs'] ) && is_array( $_POST['image_model_costs'] ) ) {
				$image_model_costs = array();
				foreach ( wp_unslash( $_POST['image_model_costs'] ) as $model => $qualities ) {
					$model = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( $model ) );
					if ( empty( $model ) || ! is_array( $qualities ) ) {
						continue;
					}
					$image_model_costs[ $model ] = array();
					foreach ( $qualities as $quality => $sizes ) {
						$quality = sanitize_text_field( $quality );
						if ( ! in_array( $quality, array( 'low', 'medium', 'high' ), true ) || ! is_array( $sizes ) ) {
							continue;
						}
						$image_model_costs[ $model ][ $quality ] = array();
						foreach ( $sizes as $size => $cost ) {
							$size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( $size ) );
							if ( ! empty( $size ) ) {
								$image_model_costs[ $model ][ $quality ][ $size ] = absint( $cost );
							}
						}
					}
				}
				update_option( 'alorbach_image_model_costs', $image_model_costs );
			}
			if ( isset( $_POST['video_costs'] ) && is_array( $_POST['video_costs'] ) ) {
				$video_costs = array();
				foreach ( wp_unslash( $_POST['video_costs'] ) as $model => $cost ) {
					$model = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( $model ) );
					if ( ! empty( $model ) ) {
						$video_costs[ $model ] = absint( $cost );
					}
				}
			}
			if ( isset( $_POST['audio_costs'] ) && is_array( $_POST['audio_costs'] ) ) {
				$audio_costs = array();
				foreach ( wp_unslash( $_POST['audio_costs'] ) as $model => $rate ) {
					$model = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', sanitize_text_field( $model ) );
					if ( ! empty( $model ) ) {
						$audio_costs[ $model ] = absint( $rate );
					}
				}
			}

			update_option( 'alorbach_cost_matrix', $cost_matrix );
			update_option( 'alorbach_image_costs', $image_costs );
			update_option( 'alorbach_video_costs', $video_costs );
			update_option( 'alorbach_audio_costs', $audio_costs );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Costs saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		$default       = isset( $cost_matrix['default'] ) ? $cost_matrix['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		$custom_models = array_diff_key( $cost_matrix, array( 'default' => 1 ) );
		ksort( $custom_models );
		$rest_verify_text  = rest_url( 'alorbach/v1/admin/verify-text' );
		$rest_verify_image = rest_url( 'alorbach/v1/admin/verify-image' );
		$rest_verify_audio = rest_url( 'alorbach/v1/admin/verify-audio' );
		$rest_fetch        = rest_url( 'alorbach/v1/admin/fetch-importable-models' );
		$rest_import       = rest_url( 'alorbach/v1/admin/import-models' );
		$rest_reset        = rest_url( 'alorbach/v1/admin/reset-models' );
		$rest_refresh_azure = rest_url( 'alorbach/v1/admin/refresh-azure-prices' );
		$nonce             = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Models', 'alorbach-ai-gateway' ); ?></h1>
			<p>
				<?php esc_html_e( 'Import enabled models from your API providers. Azure text model costs are fetched from the Azure Retail Prices API when available. Costs in UC. 1 UC = 0.000001 USD.', 'alorbach-ai-gateway' ); ?>
				<button type="button" class="button" id="alorbach_import_models_btn"><?php esc_html_e( 'Import models', 'alorbach-ai-gateway' ); ?></button>
				<button type="button" class="button" id="alorbach_reset_models_btn"><?php esc_html_e( 'Reset models', 'alorbach-ai-gateway' ); ?></button>
				<button type="button" class="button button-small" id="alorbach_refresh_azure_btn" title="<?php esc_attr_e( 'Clear Azure prices cache so next import fetches fresh data', 'alorbach-ai-gateway' ); ?>"><?php esc_html_e( 'Refresh Azure prices', 'alorbach-ai-gateway' ); ?></button>
				<span id="alorbach_import_result"></span>
			</p>

			<div id="alorbach_import_modal" class="alorbach-modal" style="display:none;">
				<div class="alorbach-modal-content">
					<h2 id="alorbach_import_modal_title"><?php esc_html_e( 'Select models to import', 'alorbach-ai-gateway' ); ?></h2>
					<p id="alorbach_import_modal_errors" class="notice notice-error" style="display:none;"></p>
					<p><button type="button" class="button button-small" id="alorbach_select_all_global"><?php esc_html_e( 'Select all', 'alorbach-ai-gateway' ); ?></button> <button type="button" class="button button-small" id="alorbach_unselect_all_global"><?php esc_html_e( 'Unselect all', 'alorbach-ai-gateway' ); ?></button></p>
					<p><input type="text" id="alorbach_import_filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter models...', 'alorbach-ai-gateway' ); ?>" /></p>
					<div id="alorbach_import_modal_body">
						<div class="alorbach-import-section" data-type="text">
							<h3><?php esc_html_e( 'Text (chat)', 'alorbach-ai-gateway' ); ?> <button type="button" class="button button-small alorbach-select-all" data-type="text"><?php esc_html_e( 'Select all', 'alorbach-ai-gateway' ); ?></button> <button type="button" class="button button-small alorbach-unselect-all" data-type="text"><?php esc_html_e( 'Unselect all', 'alorbach-ai-gateway' ); ?></button></h3>
							<div class="alorbach-import-list" data-type="text"></div>
						</div>
						<div class="alorbach-import-section" data-type="image">
							<h3><?php esc_html_e( 'Image', 'alorbach-ai-gateway' ); ?> <button type="button" class="button button-small alorbach-select-all" data-type="image"><?php esc_html_e( 'Select all', 'alorbach-ai-gateway' ); ?></button> <button type="button" class="button button-small alorbach-unselect-all" data-type="image"><?php esc_html_e( 'Unselect all', 'alorbach-ai-gateway' ); ?></button></h3>
							<div class="alorbach-import-list" data-type="image"></div>
						</div>
						<div class="alorbach-import-section" data-type="video">
							<h3><?php esc_html_e( 'Video (Sora)', 'alorbach-ai-gateway' ); ?> <button type="button" class="button button-small alorbach-select-all" data-type="video"><?php esc_html_e( 'Select all', 'alorbach-ai-gateway' ); ?></button> <button type="button" class="button button-small alorbach-unselect-all" data-type="video"><?php esc_html_e( 'Unselect all', 'alorbach-ai-gateway' ); ?></button></h3>
							<div class="alorbach-import-list" data-type="video"></div>
						</div>
						<div class="alorbach-import-section" data-type="audio">
							<h3><?php esc_html_e( 'Audio', 'alorbach-ai-gateway' ); ?> <button type="button" class="button button-small alorbach-select-all" data-type="audio"><?php esc_html_e( 'Select all', 'alorbach-ai-gateway' ); ?></button> <button type="button" class="button button-small alorbach-unselect-all" data-type="audio"><?php esc_html_e( 'Unselect all', 'alorbach-ai-gateway' ); ?></button></h3>
							<div class="alorbach-import-list" data-type="audio"></div>
						</div>
					</div>
					<p class="alorbach-modal-actions">
						<button type="button" class="button button-primary" id="alorbach_import_modal_confirm"><?php esc_html_e( 'Import selected', 'alorbach-ai-gateway' ); ?></button>
						<button type="button" class="button" id="alorbach_import_modal_cancel"><?php esc_html_e( 'Cancel', 'alorbach-ai-gateway' ); ?></button>
					</p>
				</div>
			</div>
			<style>
			.alorbach-modal { position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); overflow: auto; }
			.alorbach-modal-content { background: #fff; margin: 5% auto; padding: 20px; max-width: 600px; max-height: 80vh; overflow-y: auto; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
			.alorbach-import-section { margin-bottom: 20px; }
			.alorbach-import-section h3 { margin-top: 0; }
			.alorbach-import-list { max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; }
			.alorbach-import-item { margin: 6px 0; }
			.alorbach-import-item label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
			.alorbach-capabilities { font-size: 11px; color: #666; }
			.alorbach-modal-actions { margin-top: 20px; }
			.alorbach-test-result-modal .alorbach-modal-content { max-width: 560px; }
			.alorbach-test-result-modal .alorbach-result-body { margin: 1rem 0; max-height: 60vh; overflow: auto; }
			.alorbach-test-result-modal .alorbach-result-body img { max-width: 100%; height: auto; display: block; }
			.alorbach-test-result-modal .alorbach-result-body pre { white-space: pre-wrap; word-wrap: break-word; background: #f6f7f7; padding: 1rem; border-radius: 4px; }
			.alorbach-cost-grid { border-collapse: collapse; width: 100%; margin-bottom: 1.5rem; }
			.alorbach-cost-grid th, .alorbach-cost-grid td { border: 1px solid #c3c4c7; padding: 8px 12px; text-align: left; }
			.alorbach-cost-grid th { background: #f0f0f1; font-weight: 600; }
			.alorbach-cost-grid td.alorbach-cost-num { text-align: right; }
			.alorbach-cost-grid .alorbach-usd { font-size: 0.9em; color: #646970; margin-left: 6px; }
			.alorbach-cost-grid .alorbach-cost-cell { display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
			.alorbach-cost-grid .alorbach-cost-cell input[type="number"] { width: 100px; }
			.alorbach-cost-grid .alorbach-cost-cell input[type="number"].alorbach-uc-input { width: 110px; }
			.alorbach-cost-grid .alorbach-actions { white-space: nowrap; }
			.alorbach-cost-grid-wrapper { overflow-x: auto; }
			body.alorbach-admin-loading { cursor: wait !important; }
			</style>

			<div id="alorbach_test_result_modal" class="alorbach-modal alorbach-test-result-modal" style="display:none;">
				<div class="alorbach-modal-content">
					<h2 id="alorbach_test_result_title"><?php esc_html_e( 'Test result', 'alorbach-ai-gateway' ); ?></h2>
					<div id="alorbach_test_result_body" class="alorbach-result-body"></div>
					<p class="alorbach-modal-actions"><button type="button" class="button button-primary" id="alorbach_test_result_close"><?php esc_html_e( 'Close', 'alorbach-ai-gateway' ); ?></button></p>
				</div>
			</div>

			<?php
			$format_usd = function ( $uc ) {
				$usd = \Alorbach\AIGateway\User_Display::uc_to_usd( (int) $uc );
				$decimals = $usd >= 0.01 ? 2 : 4;
				return '$' . number_format_i18n( $usd, $decimals );
			};
			?>
			<form method="post">
				<?php wp_nonce_field( 'alorbach_cost_matrix', 'alorbach_cost_matrix_nonce' ); ?>

				<h2><?php esc_html_e( 'Text (chat)', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Costs per 1M tokens. Unknown models use the default rates.', 'alorbach-ai-gateway' ); ?></p>
				<?php if ( empty( $custom_models ) ) : ?>
					<p class="description"><?php esc_html_e( 'No models yet. Use Import models to fetch from your API providers, or add a custom model below.', 'alorbach-ai-gateway' ); ?></p>
				<?php endif; ?>
				<div class="alorbach-cost-grid-wrapper">
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'Input (per 1M tokens)', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'Output (per 1M tokens)', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'Cached (per 1M tokens)', 'alorbach-ai-gateway' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Default (unknown models)', 'alorbach-ai-gateway' ); ?></td>
								<td class="alorbach-cost-num">
									<div class="alorbach-cost-cell">
										<input type="number" name="text_default_input" class="alorbach-uc-input" value="<?php echo esc_attr( $default['input'] ?? '' ); ?>" placeholder="400000" data-alorbach-usd />
										<span class="alorbach-usd"><?php echo esc_html( $format_usd( $default['input'] ?? 0 ) ); ?></span>
									</div>
								</td>
								<td class="alorbach-cost-num">
									<div class="alorbach-cost-cell">
										<input type="number" name="text_default_output" class="alorbach-uc-input" value="<?php echo esc_attr( $default['output'] ?? '' ); ?>" placeholder="1600000" data-alorbach-usd />
										<span class="alorbach-usd"><?php echo esc_html( $format_usd( $default['output'] ?? 0 ) ); ?></span>
									</div>
								</td>
								<td class="alorbach-cost-num">
									<div class="alorbach-cost-cell">
										<input type="number" name="text_default_cached" class="alorbach-uc-input" value="<?php echo esc_attr( $default['cached'] ?? '' ); ?>" placeholder="40000" data-alorbach-usd />
										<span class="alorbach-usd"><?php echo esc_html( $format_usd( $default['cached'] ?? 0 ) ); ?></span>
									</div>
								</td>
								<td class="alorbach-actions">—</td>
							</tr>
							<?php foreach ( $custom_models as $model => $costs ) :
								$provider = Admin_Cost_Matrix::get_test_provider_for_model( $model );
								list( $model_base, $model_version ) = \Alorbach\AIGateway\Model_Importer::parse_model_display( $model );
								$model_display = $model_version ? $model_base . ' (' . $model_version . ')' : $model;
								$input_uc  = isset( $costs['input'] ) && $costs['input'] !== '' ? (int) $costs['input'] : 0;
								$output_uc = isset( $costs['output'] ) && $costs['output'] !== '' ? (int) $costs['output'] : 0;
								$cached_uc = isset( $costs['cached'] ) && $costs['cached'] !== '' ? (int) $costs['cached'] : 0;
							?>
								<tr>
									<td>
										<?php echo esc_html( $model_display ); ?>
									</td>
									<td class="alorbach-cost-num">
										<div class="alorbach-cost-cell">
											<input type="number" name="cost_matrix[<?php echo esc_attr( $model ); ?>][input]" class="alorbach-uc-input" value="<?php echo esc_attr( $costs['input'] ?? '' ); ?>" placeholder="Input UC/1M" data-alorbach-usd />
											<span class="alorbach-usd"><?php echo esc_html( $format_usd( $input_uc ) ); ?></span>
										</div>
									</td>
									<td class="alorbach-cost-num">
										<div class="alorbach-cost-cell">
											<input type="number" name="cost_matrix[<?php echo esc_attr( $model ); ?>][output]" class="alorbach-uc-input" value="<?php echo esc_attr( $costs['output'] ?? '' ); ?>" placeholder="Output UC/1M" data-alorbach-usd />
											<span class="alorbach-usd"><?php echo esc_html( $format_usd( $output_uc ) ); ?></span>
										</div>
									</td>
									<td class="alorbach-cost-num">
										<div class="alorbach-cost-cell">
											<input type="number" name="cost_matrix[<?php echo esc_attr( $model ); ?>][cached]" class="alorbach-uc-input" value="<?php echo esc_attr( $costs['cached'] ?? '' ); ?>" placeholder="Cached UC/1M" data-alorbach-usd />
											<span class="alorbach-usd"><?php echo esc_html( $format_usd( $cached_uc ) ); ?></span>
										</div>
									</td>
									<td class="alorbach-actions">
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'alorbach_remove_model' => '1', 'model' => $model ), admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_remove_model', '_wpnonce' ) ); ?>" class="button button-small"><?php esc_html_e( 'Remove', 'alorbach-ai-gateway' ); ?></a>
										<button type="button" class="button alorbach-test-text" data-provider="<?php echo esc_attr( $provider ); ?>" data-model="<?php echo esc_attr( $model ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
										<span class="alorbach-test-result" data-type="text" data-model="<?php echo esc_attr( $model ); ?>"></span>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td colspan="5">
									<label for="alorbach_new_model" class="screen-reader-text"><?php esc_html_e( 'Add custom model', 'alorbach-ai-gateway' ); ?></label>
									<input type="text" id="alorbach_new_model" placeholder="<?php esc_attr_e( 'e.g. gpt-4o, o1-mini, gpt-5-mini', 'alorbach-ai-gateway' ); ?>" style="width: 220px;" />
									<button type="button" class="button" id="alorbach_add_model_btn"><?php esc_html_e( 'Add', 'alorbach-ai-gateway' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<h2><?php esc_html_e( 'Image', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Default model, quality, and output format (admin only). Quality and format apply to GPT Image models.', 'alorbach-ai-gateway' ); ?></p>
				<table class="form-table" style="max-width: 500px;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default image model', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<select name="alorbach_image_default_model" id="alorbach_image_default_model">
								<?php foreach ( array_unique( array_merge( $image_models, array( 'dall-e-3', 'gpt-image-1.5' ) ) ) as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $image_default_model, $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default quality', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<select name="alorbach_image_default_quality" id="alorbach_image_default_quality">
								<option value="low" <?php selected( $image_default_quality, 'low' ); ?>><?php esc_html_e( 'Low', 'alorbach-ai-gateway' ); ?></option>
								<option value="medium" <?php selected( $image_default_quality, 'medium' ); ?>><?php esc_html_e( 'Medium', 'alorbach-ai-gateway' ); ?></option>
								<option value="high" <?php selected( $image_default_quality, 'high' ); ?>><?php esc_html_e( 'High', 'alorbach-ai-gateway' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Output format', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<select name="alorbach_image_default_output_format" id="alorbach_image_default_output_format">
								<option value="png" <?php selected( $image_default_format, 'png' ); ?>><?php esc_html_e( 'PNG', 'alorbach-ai-gateway' ); ?></option>
								<option value="jpeg" <?php selected( $image_default_format, 'jpeg' ); ?>><?php esc_html_e( 'JPEG', 'alorbach-ai-gateway' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'DALL-E: Cost per image by size', 'alorbach-ai-gateway' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Flat cost per size for DALL-E. Add custom sizes as needed.', 'alorbach-ai-gateway' ); ?> <?php esc_html_e( 'Test generates 1 image (costs credits on OpenAI).', 'alorbach-ai-gateway' ); ?></p>
				<div class="alorbach-cost-grid-wrapper">
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model / Size', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'UC per image (USD)', 'alorbach-ai-gateway' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $image_costs as $size => $cost ) :
								list( $size_base, $size_version ) = \Alorbach\AIGateway\Model_Importer::parse_model_display( $size );
								$size_display = $size_version ? $size_base . ' (' . $size_version . ')' : $size;
								$cost_int = (int) $cost;
							?>
								<tr>
									<td><?php echo esc_html( $size_display ); ?></td>
									<td class="alorbach-cost-num">
										<div class="alorbach-cost-cell">
											<input type="number" name="image_costs[<?php echo esc_attr( $size ); ?>]" class="alorbach-uc-input" value="<?php echo esc_attr( $cost ); ?>" placeholder="40000" data-alorbach-usd />
											<span class="alorbach-usd"><?php echo esc_html( $format_usd( $cost_int ) ); ?></span>
										</div>
									</td>
									<td class="alorbach-actions">
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'alorbach_remove_image' => '1', 'size' => $size ), admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_remove_image', '_wpnonce' ) ); ?>" class="button button-small"><?php esc_html_e( 'Remove', 'alorbach-ai-gateway' ); ?></a>
										<button type="button" class="button alorbach-test-image" data-size="<?php echo esc_attr( $size ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
										<span class="alorbach-test-result" data-type="image" data-size="<?php echo esc_attr( $size ); ?>"></span>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td colspan="3">
									<label for="alorbach_new_image" class="screen-reader-text"><?php esc_html_e( 'Add custom size', 'alorbach-ai-gateway' ); ?></label>
									<input type="text" id="alorbach_new_image" placeholder="<?php esc_attr_e( 'e.g. 1792x1024, 1024x1792', 'alorbach-ai-gateway' ); ?>" style="width: 180px;" />
									<button type="button" class="button" id="alorbach_add_image_btn"><?php esc_html_e( 'Add', 'alorbach-ai-gateway' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php
				$gpt_sizes = array( '1024x1024', '1024x1536', '1536x1024' );
				$gpt_qualities = array( 'low', 'medium', 'high' );
				if ( ! empty( $image_model_costs ) ) :
					?>
				<h3><?php esc_html_e( 'GPT Image: Cost per image by quality and size', 'alorbach-ai-gateway' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Cost varies by quality (low/medium/high) and resolution. Values in UC; USD shown next to each field.', 'alorbach-ai-gateway' ); ?></p>
				<?php foreach ( $image_model_costs as $img_model => $qualities ) :
					if ( strpos( $img_model, 'gpt-image' ) !== 0 ) {
						continue;
					}
					?>
				<div class="alorbach-cost-grid-wrapper" style="margin-bottom: 1.5rem;">
					<h4><?php echo esc_html( $img_model ); ?></h4>
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Quality', 'alorbach-ai-gateway' ); ?></th>
								<?php foreach ( $gpt_sizes as $s ) : ?>
									<th class="alorbach-cost-num"><?php echo esc_html( $s ); ?> (UC / USD)</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $gpt_qualities as $q ) :
								$row = isset( $qualities[ $q ] ) ? $qualities[ $q ] : array();
								?>
								<tr>
									<td><?php echo esc_html( ucfirst( $q ) ); ?></td>
									<?php foreach ( $gpt_sizes as $s ) :
										$val = isset( $row[ $s ] ) ? (int) $row[ $s ] : '';
										$val_int = (int) $val;
										?>
										<td class="alorbach-cost-num">
											<div class="alorbach-cost-cell">
												<input type="number" name="image_model_costs[<?php echo esc_attr( $img_model ); ?>][<?php echo esc_attr( $q ); ?>][<?php echo esc_attr( $s ); ?>]" class="alorbach-uc-input" value="<?php echo esc_attr( $val ); ?>" placeholder="<?php echo esc_attr( $q === 'medium' && $s === '1024x1024' ? '34000' : '' ); ?>" style="width: 90px;" data-alorbach-usd />
												<span class="alorbach-usd"><?php echo esc_html( $format_usd( $val_int ) ); ?></span>
											</div>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endforeach; ?>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Video (Sora)', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Cost per video by model. Add custom models as needed.', 'alorbach-ai-gateway' ); ?></p>
				<div class="alorbach-cost-grid-wrapper">
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'UC per video (USD)', 'alorbach-ai-gateway' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $video_costs as $model => $cost ) :
								list( $video_base, $video_version ) = \Alorbach\AIGateway\Model_Importer::parse_model_display( $model );
								$video_display = $video_version ? $video_base . ' (' . $video_version . ')' : $model;
								$cost_int = (int) $cost;
							?>
								<tr>
									<td><?php echo esc_html( $video_display ); ?></td>
									<td class="alorbach-cost-num">
										<div class="alorbach-cost-cell">
											<input type="number" name="video_costs[<?php echo esc_attr( $model ); ?>]" class="alorbach-uc-input" value="<?php echo esc_attr( $cost ); ?>" placeholder="400000" data-alorbach-usd />
											<span class="alorbach-usd"><?php echo esc_html( $format_usd( $cost_int ) ); ?></span>
										</div>
									</td>
									<td class="alorbach-actions">
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'alorbach_remove_video' => '1', 'model' => $model ), admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_remove_video', '_wpnonce' ) ); ?>" class="button button-small"><?php esc_html_e( 'Remove', 'alorbach-ai-gateway' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td colspan="3">
									<label for="alorbach_new_video" class="screen-reader-text"><?php esc_html_e( 'Add custom model', 'alorbach-ai-gateway' ); ?></label>
									<input type="text" id="alorbach_new_video" placeholder="<?php esc_attr_e( 'e.g. sora-2', 'alorbach-ai-gateway' ); ?>" style="width: 180px;" />
									<button type="button" class="button" id="alorbach_add_video_btn"><?php esc_html_e( 'Add', 'alorbach-ai-gateway' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<h2><?php esc_html_e( 'Audio (transcription)', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Cost per second of audio by model. Add custom models as needed.', 'alorbach-ai-gateway' ); ?></p>
				<div class="alorbach-cost-grid-wrapper">
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'UC per second (USD)', 'alorbach-ai-gateway' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $audio_costs as $model => $rate ) :
								list( $audio_base, $audio_version ) = \Alorbach\AIGateway\Model_Importer::parse_model_display( $model );
								$audio_display = $audio_version ? $audio_base . ' (' . $audio_version . ')' : $model;
								$rate_int = (int) $rate;
							?>
								<tr>
									<td><?php echo esc_html( $audio_display ); ?></td>
									<td class="alorbach-cost-num">
										<div class="alorbach-cost-cell">
											<input type="number" name="audio_costs[<?php echo esc_attr( $model ); ?>]" class="alorbach-uc-input" value="<?php echo esc_attr( $rate ); ?>" placeholder="100" data-alorbach-usd />
											<span class="alorbach-usd"><?php echo esc_html( $format_usd( $rate_int ) ); ?></span>
										</div>
									</td>
									<td class="alorbach-actions">
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'alorbach_remove_audio' => '1', 'model' => $model ), admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_remove_audio', '_wpnonce' ) ); ?>" class="button button-small"><?php esc_html_e( 'Remove', 'alorbach-ai-gateway' ); ?></a>
										<button type="button" class="button alorbach-test-audio" data-model="<?php echo esc_attr( $model ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
										<span class="alorbach-test-result" data-type="audio" data-model="<?php echo esc_attr( $model ); ?>"></span>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td colspan="3">
									<label for="alorbach_new_audio" class="screen-reader-text"><?php esc_html_e( 'Add custom model', 'alorbach-ai-gateway' ); ?></label>
									<input type="text" id="alorbach_new_audio" placeholder="<?php esc_attr_e( 'e.g. gpt-4o-transcribe', 'alorbach-ai-gateway' ); ?>" style="width: 200px;" />
									<button type="button" class="button" id="alorbach_add_audio_btn"><?php esc_html_e( 'Add', 'alorbach-ai-gateway' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save', 'alorbach-ai-gateway' ); ?>" /></p>
			</form>
			<script>
			document.getElementById('alorbach_add_model_btn').onclick = function() {
				var input = document.getElementById('alorbach_new_model');
				var model = (input.value || '').trim().replace(/[^a-z0-9\-_.]/gi, '-');
				if (!model) return;
				var base = '<?php echo esc_js( wp_nonce_url( add_query_arg( 'alorbach_add_model', '1', admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_add_model', '_wpnonce' ) ); ?>';
				window.location.href = base + '&model=' + encodeURIComponent(model);
			};
			document.getElementById('alorbach_add_image_btn').onclick = function() {
				var input = document.getElementById('alorbach_new_image');
				var size = (input.value || '').trim().replace(/[^a-z0-9\-_x]/gi, '');
				if (!size) return;
				var base = '<?php echo esc_js( wp_nonce_url( add_query_arg( 'alorbach_add_image', '1', admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_add_image', '_wpnonce' ) ); ?>';
				window.location.href = base + '&size=' + encodeURIComponent(size);
			};
			document.getElementById('alorbach_add_audio_btn').onclick = function() {
				var input = document.getElementById('alorbach_new_audio');
				var model = (input.value || '').trim().replace(/[^a-z0-9\-_.]/gi, '-');
				if (!model) return;
				var base = '<?php echo esc_js( wp_nonce_url( add_query_arg( 'alorbach_add_audio', '1', admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_add_audio', '_wpnonce' ) ); ?>';
				window.location.href = base + '&model=' + encodeURIComponent(model);
			};
			document.getElementById('alorbach_add_video_btn').onclick = function() {
				var input = document.getElementById('alorbach_new_video');
				var model = (input.value || '').trim().replace(/[^a-z0-9\-_.]/gi, '-');
				if (!model) return;
				var base = '<?php echo esc_js( wp_nonce_url( add_query_arg( 'alorbach_add_video', '1', admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_add_video', '_wpnonce' ) ); ?>';
				window.location.href = base + '&model=' + encodeURIComponent(model);
			};

			(function() {
				function formatUcAsUsd(uc) {
					var val = parseInt(uc, 10) || 0;
					var usd = val * 0.000001;
					var decimals = usd >= 0.01 ? 2 : 4;
					return '$' + usd.toFixed(decimals);
				}
				document.querySelectorAll('input[data-alorbach-usd]').forEach(function(inp) {
					inp.addEventListener('input', function() {
						var cell = this.closest('.alorbach-cost-cell');
						if (cell) {
							var span = cell.querySelector('.alorbach-usd');
							if (span) span.textContent = formatUcAsUsd(this.value);
						}
					});
				});

				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var restVerifyText = <?php echo wp_json_encode( $rest_verify_text ); ?>;
				var restVerifyImage = <?php echo wp_json_encode( $rest_verify_image ); ?>;
				var restVerifyAudio = <?php echo wp_json_encode( $rest_verify_audio ); ?>;
				var restFetch = <?php echo wp_json_encode( $rest_fetch ); ?>;
				var restImport = <?php echo wp_json_encode( $rest_import ); ?>;
				var restReset = <?php echo wp_json_encode( $rest_reset ); ?>;
				var restRefreshAzure = <?php echo wp_json_encode( $rest_refresh_azure ); ?>;
				var okText = <?php echo wp_json_encode( __( 'OK', 'alorbach-ai-gateway' ) ); ?>;
				var errText = <?php echo wp_json_encode( __( 'Error', 'alorbach-ai-gateway' ) ); ?>;
				var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };

				var loadingCount = 0;
				function setLoading(inc) {
					loadingCount += inc;
					document.body.classList.toggle('alorbach-admin-loading', loadingCount > 0);
				}

				function setResult(el, success, msg) {
					el.textContent = msg || (success ? okText : errText);
					el.style.color = success ? 'green' : 'red';
				}

				function showTestResultPopup(type, label, data) {
					var modal = document.getElementById('alorbach_test_result_modal');
					var titleEl = document.getElementById('alorbach_test_result_title');
					var bodyEl = document.getElementById('alorbach_test_result_body');
					titleEl.textContent = (type === 'text' ? '<?php echo esc_js( __( 'Chat test result', 'alorbach-ai-gateway' ) ); ?>' : type === 'image' ? '<?php echo esc_js( __( 'Image test result', 'alorbach-ai-gateway' ) ); ?>' : '<?php echo esc_js( __( 'Audio test result', 'alorbach-ai-gateway' ) ); ?>') + ': ' + label;
					if (data.success && data.result) {
						if (type === 'image' && data.result) {
							bodyEl.innerHTML = '<img src="' + data.result.replace(/"/g, '&quot;') + '" alt="Generated" />';
						} else if (type === 'text' || type === 'audio') {
							var txt = (data.result || '').toString();
							bodyEl.innerHTML = txt ? '<pre>' + txt.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>' : '<p><?php echo esc_js( __( '(empty response)', 'alorbach-ai-gateway' ) ); ?></p>';
						} else {
							bodyEl.innerHTML = '<p>' + (data.result || '').toString().replace(/</g, '&lt;') + '</p>';
						}
					} else {
						bodyEl.innerHTML = '<p style="color:red;">' + (data.message || errText).replace(/</g, '&lt;') + '</p>';
					}
					modal.style.display = 'block';
				}

				var testResultModal = document.getElementById('alorbach_test_result_modal');
				function closeTestResultModal() { testResultModal.style.display = 'none'; }
				document.getElementById('alorbach_test_result_close').addEventListener('click', closeTestResultModal);
				testResultModal.addEventListener('click', function(e) { if (e.target === testResultModal) closeTestResultModal(); });

				document.querySelectorAll('.alorbach-test-text').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var provider = this.getAttribute('data-provider');
						var model = this.getAttribute('data-model');
						var resultEl = document.querySelector('.alorbach-test-result[data-type="text"][data-model="' + model + '"]');
						resultEl.textContent = '...';
						setLoading(1);
						fetch(restVerifyText, { method: 'POST', headers: headers, body: JSON.stringify({ provider: provider, model: model }) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								setResult(resultEl, data.success, data.message);
								if (data.success) showTestResultPopup('text', model, data);
							})
							.catch(function(err) { setResult(resultEl, false, err.message); })
							.finally(function() { setLoading(-1); });
					});
				});
				document.querySelectorAll('.alorbach-test-image').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var size = this.getAttribute('data-size');
						var resultEl = document.querySelector('.alorbach-test-result[data-type="image"][data-size="' + size + '"]');
						resultEl.textContent = '...';
						setLoading(1);
						fetch(restVerifyImage, { method: 'POST', headers: headers, body: JSON.stringify({ size: size }) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								setResult(resultEl, data.success, data.message);
								if (data.success) showTestResultPopup('image', size, data);
							})
							.catch(function(err) { setResult(resultEl, false, err.message); })
							.finally(function() { setLoading(-1); });
					});
				});
				document.querySelectorAll('.alorbach-test-audio').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var model = this.getAttribute('data-model');
						var resultEl = document.querySelector('.alorbach-test-result[data-type="audio"][data-model="' + model + '"]');
						resultEl.textContent = '...';
						setLoading(1);
						fetch(restVerifyAudio, { method: 'POST', headers: headers, body: JSON.stringify({ model: model }) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								setResult(resultEl, data.success, data.message);
								if (data.success) showTestResultPopup('audio', model, data);
							})
							.catch(function(err) { setResult(resultEl, false, err.message); })
							.finally(function() { setLoading(-1); });
					});
				});

				function handleImportResult(data, resultEl, errText) {
					var parts = [];
					if (data.added) {
						var a = data.added;
						if (a.text && a.text.length) parts.push(a.text.length + ' text');
						if (a.image && a.image.length) parts.push(a.image.length + ' image');
						if (a.video && a.video.length) parts.push(a.video.length + ' video');
						if (a.audio && a.audio.length) parts.push(a.audio.length + ' audio');
					}
					if (parts.length) {
						resultEl.textContent = 'Added: ' + parts.join(', ');
						resultEl.style.color = 'green';
						window.location.reload();
					} else if (data.errors && data.errors.length) {
						resultEl.textContent = data.errors.join('; ');
						resultEl.style.color = 'red';
					} else {
						resultEl.textContent = 'Nothing new to add.';
						resultEl.style.color = '';
					}
				}

				var modal = document.getElementById('alorbach_import_modal');
				var modalTitle = document.getElementById('alorbach_import_modal_title');
				var modalErrors = document.getElementById('alorbach_import_modal_errors');
				var modalConfirm = document.getElementById('alorbach_import_modal_confirm');
				var modalCancel = document.getElementById('alorbach_import_modal_cancel');
				var pendingAction = null; // 'import' | 'reset'

				function renderModal(data) {
					var labels = data.capability_labels || {};
					var filterInput = document.getElementById('alorbach_import_filter');
					if (filterInput) filterInput.value = '';
					['text','image','video','audio'].forEach(function(type) {
						var list = document.querySelector('.alorbach-import-list[data-type="' + type + '"]');
						list.innerHTML = '';
						var items = data[type] || [];
						items.forEach(function(item) {
							var caps = (item.capabilities || []).map(function(c) { return labels[c] || c; }).join(', ');
							var display = item.version ? (item.base || item.id) + ' (' + item.version + ')' : (item.id || '');
							var div = document.createElement('div');
							div.className = 'alorbach-import-item';
							div.dataset.modelId = (item.id || '').toLowerCase();
							div.innerHTML = '<label><input type="checkbox" class="alorbach-import-cb" data-type="' + type + '" data-id="' + (item.id || '').replace(/"/g, '&quot;') + '" checked> <span>' + display.replace(/</g, '&lt;') + '</span> <span class="alorbach-capabilities">(' + caps.replace(/</g, '&lt;') + ')</span></label>';
							list.appendChild(div);
						});
					});
					applyImportFilter();
				}

				function applyImportFilter() {
					var q = (document.getElementById('alorbach_import_filter').value || '').trim().toLowerCase();
					document.querySelectorAll('.alorbach-import-section').forEach(function(section) {
						var list = section.querySelector('.alorbach-import-list');
						if (!list) return;
						var visible = 0;
						list.querySelectorAll('.alorbach-import-item').forEach(function(item) {
							var match = !q || (item.dataset.modelId || '').indexOf(q) !== -1;
							item.style.display = match ? '' : 'none';
							if (match) visible++;
						});
						section.style.display = visible > 0 || !q ? '' : 'none';
					});
				}

				function getSelected() {
					var sel = { text: [], image: [], video: [], audio: [] };
					document.querySelectorAll('.alorbach-import-cb:checked').forEach(function(cb) {
						var t = cb.getAttribute('data-type');
						var id = cb.getAttribute('data-id');
						if (sel[t] && id) sel[t].push(id);
					});
					return sel;
				}

				document.querySelectorAll('.alorbach-select-all').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var t = this.getAttribute('data-type');
						document.querySelectorAll('.alorbach-import-cb[data-type="' + t + '"]').forEach(function(cb) { cb.checked = true; });
					});
				});
				document.querySelectorAll('.alorbach-unselect-all').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var t = this.getAttribute('data-type');
						document.querySelectorAll('.alorbach-import-cb[data-type="' + t + '"]').forEach(function(cb) { cb.checked = false; });
					});
				});
				document.getElementById('alorbach_select_all_global').addEventListener('click', function() {
					document.querySelectorAll('.alorbach-import-cb').forEach(function(cb) { cb.checked = true; });
				});
				document.getElementById('alorbach_unselect_all_global').addEventListener('click', function() {
					document.querySelectorAll('.alorbach-import-cb').forEach(function(cb) { cb.checked = false; });
				});
				document.getElementById('alorbach_import_filter').addEventListener('input', applyImportFilter);

				modalCancel.addEventListener('click', function() { modal.style.display = 'none'; pendingAction = null; });

				modalConfirm.addEventListener('click', function() {
					if (!pendingAction) return;
					var resultEl = document.getElementById('alorbach_import_result');
					var selected = getSelected();
					modal.style.display = 'none';
					resultEl.textContent = '...';
					var url = pendingAction === 'reset' ? restReset : restImport;
					setLoading(1);
					fetch(url, { method: 'POST', headers: headers, body: JSON.stringify({ selected: selected }) })
						.then(function(r) { return r.json(); })
						.then(function(data) { handleImportResult(data, resultEl, errText); })
						.catch(function(err) {
							resultEl.textContent = err.message || errText;
							resultEl.style.color = 'red';
						})
						.finally(function() { setLoading(-1); });
					pendingAction = null;
				});

				function openImportModal(action) {
					pendingAction = action;
					modalErrors.style.display = 'none';
					modalTitle.textContent = action === 'reset' ? '<?php echo esc_js( __( 'Select models to import (reset will clear existing first)', 'alorbach-ai-gateway' ) ); ?>' : '<?php echo esc_js( __( 'Select models to import', 'alorbach-ai-gateway' ) ); ?>';
					modalConfirm.textContent = action === 'reset' ? '<?php echo esc_js( __( 'Reset and import selected', 'alorbach-ai-gateway' ) ); ?>' : '<?php echo esc_js( __( 'Import selected', 'alorbach-ai-gateway' ) ); ?>';
					setLoading(1);
					fetch(restFetch, { headers: { 'X-WP-Nonce': nonce } })
						.then(function(r) { return r.json(); })
						.then(function(data) {
							if (data.errors && data.errors.length) {
								modalErrors.textContent = data.errors.join('; ');
								modalErrors.style.display = 'block';
							}
							renderModal(data);
							modal.style.display = 'block';
						})
						.catch(function(err) {
							modalErrors.textContent = err.message || errText;
							modalErrors.style.display = 'block';
							renderModal({ text: [], image: [], video: [], audio: [], capability_labels: {} });
							modal.style.display = 'block';
						})
						.finally(function() { setLoading(-1); });
				}

				document.getElementById('alorbach_import_models_btn').addEventListener('click', function() {
					openImportModal('import');
				});
				document.getElementById('alorbach_reset_models_btn').addEventListener('click', function() {
					openImportModal('reset');
				});
				document.getElementById('alorbach_refresh_azure_btn').addEventListener('click', function() {
					var btn = this;
					var orig = btn.textContent;
					btn.disabled = true;
					btn.textContent = '...';
					setLoading(1);
					fetch(restRefreshAzure, { method: 'POST', headers: headers })
						.then(function(r) { return r.json(); })
						.then(function(data) {
							var resultEl = document.getElementById('alorbach_import_result');
							setResult(resultEl, data.success, data.message || (data.success ? '<?php echo esc_js( __( 'Azure prices cache cleared.', 'alorbach-ai-gateway' ) ); ?>' : ''));
						})
						.catch(function(err) {
							var resultEl = document.getElementById('alorbach_import_result');
							setResult(resultEl, false, err.message);
						})
						.finally(function() { btn.disabled = false; btn.textContent = orig; setLoading(-1); });
				});
			})();
			</script>
		</div>
		<?php
	}
}
