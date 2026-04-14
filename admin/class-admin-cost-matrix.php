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
	 * Sanitize a model identifier while preserving provider/model and provider suffix forms.
	 *
	 * @param string $model Raw model ID.
	 * @return string
	 */
	private static function sanitize_model_id( $model ) {
		$model = sanitize_text_field( (string) $model );
		return preg_replace( '/[^a-zA-Z0-9\-_.:\/]/', '', $model );
	}

	/**
	 * Get the provider to use when testing a model.
	 *
	 * @param string $model    Model ID.
	 * @param string $entry_id Optional. When set, get provider from that entry.
	 * @return string Provider: openai, azure, google, or github_models.
	 */
	public static function get_test_provider_for_model( $model, $entry_id = '' ) {
		if ( ! empty( $entry_id ) ) {
			$entry = \Alorbach\AIGateway\API_Keys_Helper::get_entry_by_id( $entry_id );
			return $entry ? ( $entry['type'] ?? \Alorbach\AIGateway\API_Client::get_provider_for_model( $model ) ) : \Alorbach\AIGateway\API_Client::get_provider_for_model( $model );
		}
		return \Alorbach\AIGateway\API_Client::get_provider_for_model( $model );
	}

	/**
	 * Determine whether the resolved provider supports testing for a capability.
	 *
	 * @param string $model      Model ID.
	 * @param string $capability Capability name: chat, image, audio, video.
	 * @param string $entry_id   Optional. When set, get provider from that entry.
	 * @return bool
	 */
	public static function model_supports_test_capability( $model, $capability, $entry_id = '' ) {
		$provider = self::get_test_provider_for_model( $model, $entry_id );
		$prov     = \Alorbach\AIGateway\Providers\Provider_Registry::get( $provider );
		if ( ! $prov ) {
			return false;
		}

		switch ( $capability ) {
			case 'video':
				return (bool) $prov->supports_video();
			case 'audio':
				return (bool) $prov->supports_audio();
			case 'image':
				return (bool) $prov->supports_images();
			case 'chat':
			default:
				return (bool) $prov->supports_chat();
		}
	}

	/**
	 * Handle GET actions before any output (hooked to admin_init).
	 */
	public static function handle_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ( $_GET['page'] ?? '' ) !== 'alorbach-cost-matrix' ) {
			return;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		// Add custom text model.
		if ( isset( $_GET['alorbach_add_model'] ) && isset( $_GET['model'] ) ) {
			$new_model = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			$entry_id  = isset( $_GET['entry_id'] ) ? sanitize_text_field( wp_unslash( $_GET['entry_id'] ) ) : '';
			if ( ! empty( $new_model ) && wp_verify_nonce( $nonce, 'alorbach_add_model' ) && $new_model !== 'default' ) {
				$cost_matrix = \Alorbach\AIGateway\Cost_Matrix::get_cost_matrix();
				$models = isset( $cost_matrix['models'] ) && is_array( $cost_matrix['models'] ) ? $cost_matrix['models'] : array();
				if ( empty( $entry_id ) ) {
					$provider = \Alorbach\AIGateway\API_Client::get_provider_for_model( $new_model );
					$entry    = \Alorbach\AIGateway\API_Keys_Helper::get_entry_by_type( $provider );
					$entry_id = $entry ? ( $entry['id'] ?? 'legacy' ) : 'legacy';
				}
				$models[] = array( 'model' => $new_model, 'entry_id' => $entry_id, 'input' => '', 'output' => '', 'cached' => '' );
				\Alorbach\AIGateway\Cost_Matrix::save_cost_matrix( array( 'default' => $cost_matrix['default'] ?? array(), 'models' => $models ) );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_add_model', 'model', 'entry_id', '_wpnonce' ) ) );
				exit;
			}
		}

		// Remove custom text model.
		if ( isset( $_GET['alorbach_remove_model'] ) && isset( $_GET['model'] ) ) {
			$remove_model = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			$entry_id     = isset( $_GET['entry_id'] ) ? sanitize_text_field( wp_unslash( $_GET['entry_id'] ) ) : '';
			if ( ! empty( $remove_model ) && wp_verify_nonce( $nonce, 'alorbach_remove_model' ) && $remove_model !== 'default' ) {
				$cost_matrix = \Alorbach\AIGateway\Cost_Matrix::get_cost_matrix();
				$models = isset( $cost_matrix['models'] ) && is_array( $cost_matrix['models'] ) ? $cost_matrix['models'] : array();
				$models = array_values( array_filter( $models, function ( $row ) use ( $remove_model, $entry_id ) {
					$match_model = ( $row['model'] ?? '' ) === $remove_model;
					$match_entry = empty( $entry_id ) || ( $row['entry_id'] ?? '' ) === $entry_id;
					return ! ( $match_model && $match_entry );
				} ) );
				\Alorbach\AIGateway\Cost_Matrix::save_cost_matrix( array( 'default' => $cost_matrix['default'] ?? array(), 'models' => $models ) );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_remove_model', 'model', 'entry_id', '_wpnonce' ) ) );
				exit;
			}
		}

		// Add custom image size.
		if ( isset( $_GET['alorbach_add_image'] ) && isset( $_GET['size'] ) ) {
			$new_size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( wp_unslash( $_GET['size'] ) ) );
			if ( ! empty( $new_size ) && wp_verify_nonce( $nonce, 'alorbach_add_image' ) ) {
				self::add_option_entry( 'alorbach_image_costs', $new_size, 40000, array( 'alorbach_add_image', 'size', '_wpnonce' ), true );
			}
		}

		// Remove custom image size.
		if ( isset( $_GET['alorbach_remove_image'] ) && isset( $_GET['size'] ) ) {
			$remove_size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( wp_unslash( $_GET['size'] ) ) );
			if ( ! empty( $remove_size ) && wp_verify_nonce( $nonce, 'alorbach_remove_image' ) ) {
				self::remove_option_entry( 'alorbach_image_costs', $remove_size, array( 'alorbach_remove_image', 'size', '_wpnonce' ) );
			}
		}

		// Add custom audio model.
		if ( isset( $_GET['alorbach_add_audio'] ) && isset( $_GET['model'] ) ) {
			$new_audio = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			if ( ! empty( $new_audio ) && wp_verify_nonce( $nonce, 'alorbach_add_audio' ) ) {
				self::add_option_entry( 'alorbach_audio_costs', $new_audio, 100, array( 'alorbach_add_audio', 'model', '_wpnonce' ) );
			}
		}

		// Remove custom audio model.
		if ( isset( $_GET['alorbach_remove_audio'] ) && isset( $_GET['model'] ) ) {
			$remove_audio = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			if ( ! empty( $remove_audio ) && wp_verify_nonce( $nonce, 'alorbach_remove_audio' ) ) {
				self::remove_option_entry( 'alorbach_audio_costs', $remove_audio, array( 'alorbach_remove_audio', 'model', '_wpnonce' ) );
			}
		}

		// Add custom video model.
		if ( isset( $_GET['alorbach_add_video'] ) && isset( $_GET['model'] ) ) {
			$new_video = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			if ( ! empty( $new_video ) && wp_verify_nonce( $nonce, 'alorbach_add_video' ) ) {
				self::add_option_entry( 'alorbach_video_costs', $new_video, 400000, array( 'alorbach_add_video', 'model', '_wpnonce' ) );
			}
		}

		// Remove custom video model.
		if ( isset( $_GET['alorbach_remove_video'] ) && isset( $_GET['model'] ) ) {
			$remove_video = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			if ( ! empty( $remove_video ) && wp_verify_nonce( $nonce, 'alorbach_remove_video' ) ) {
				self::remove_option_entry( 'alorbach_video_costs', $remove_video, array( 'alorbach_remove_video', 'model', '_wpnonce' ) );
			}
		}

		// Remove imported image model.
		if ( isset( $_GET['alorbach_remove_image_model'] ) && isset( $_GET['model'] ) ) {
			$remove_image_model = self::sanitize_model_id( wp_unslash( $_GET['model'] ) );
			if ( ! empty( $remove_image_model ) && wp_verify_nonce( $nonce, 'alorbach_remove_image_model' ) ) {
				$image_models = get_option( 'alorbach_image_models', array() );
				$image_models = is_array( $image_models ) ? $image_models : array();
				$image_models = array_values( array_filter( $image_models, function ( $model ) use ( $remove_image_model ) {
					return (string) $model !== $remove_image_model;
				} ) );

				$image_model_costs = get_option( 'alorbach_image_model_costs', array() );
				$image_model_costs = is_array( $image_model_costs ) ? $image_model_costs : array();
				unset( $image_model_costs[ $remove_image_model ] );

				update_option( 'alorbach_image_models', $image_models );
				update_option( 'alorbach_image_model_costs', $image_model_costs );
				wp_safe_redirect( remove_query_arg( array( 'alorbach_remove_image_model', 'model', '_wpnonce' ) ) );
				exit;
			}
		}
	}

	/**
	 * Add an entry to an option array and redirect.
	 *
	 * @param string $option_key   WP option name.
	 * @param string $entry_key    Key to add.
	 * @param mixed  $default_value Value to store.
	 * @param array  $redirect_args Query args to remove on redirect.
	 * @param bool   $overwrite     Whether to overwrite an existing entry.
	 */
	private static function add_option_entry( $option_key, $entry_key, $default_value, $redirect_args, $overwrite = false ) {
		$data = get_option( $option_key, array() );
		$data = is_array( $data ) ? $data : array();
		if ( $overwrite || ! isset( $data[ $entry_key ] ) ) {
			$data[ $entry_key ] = $default_value;
			update_option( $option_key, $data );
		}
		wp_safe_redirect( remove_query_arg( $redirect_args ) );
		exit;
	}

	/**
	 * Remove an entry from an option array and redirect.
	 *
	 * @param string $option_key   WP option name.
	 * @param string $entry_key    Key to remove.
	 * @param array  $redirect_args Query args to remove on redirect.
	 */
	private static function remove_option_entry( $option_key, $entry_key, $redirect_args ) {
		$data = get_option( $option_key, array() );
		$data = is_array( $data ) ? $data : array();
		unset( $data[ $entry_key ] );
		update_option( $option_key, $data );
		wp_safe_redirect( remove_query_arg( $redirect_args ) );
		exit;
	}

	/**
	 * Render Cost Matrix page.
	 */
	public static function render() {
		$cost_data   = \Alorbach\AIGateway\Cost_Matrix::get_cost_matrix();
		$cost_matrix = $cost_data;
		$image_costs      = get_option( 'alorbach_image_costs', array() );
		$image_costs      = is_array( $image_costs ) ? $image_costs : array();
		$image_models     = get_option( 'alorbach_image_models', array() );
		$image_models     = is_array( $image_models ) ? $image_models : array( 'dall-e-3', 'gpt-image-1.5' );
		$image_model_costs = get_option( 'alorbach_image_model_costs', array() );
		$image_model_costs = is_array( $image_model_costs ) ? $image_model_costs : array();
		$has_openai_style_image_provider = \Alorbach\AIGateway\API_Keys_Helper::has_provider( 'openai' ) || \Alorbach\AIGateway\API_Keys_Helper::has_provider( 'azure' );
		$has_dalle_family_models = false;
		foreach ( $image_models as $img_model_id ) {
			if ( strpos( (string) $img_model_id, 'dall-e' ) === 0 || strpos( (string) $img_model_id, 'gpt-image' ) === 0 ) {
				$has_dalle_family_models = true;
				break;
			}
		}
		$show_dalle_section = $has_openai_style_image_provider || $has_dalle_family_models || ! empty( $image_costs );
		$video_costs = get_option( 'alorbach_video_costs', array() );
		$video_costs = is_array( $video_costs ) ? $video_costs : array();
		$audio_costs = get_option( 'alorbach_audio_costs', array() );
		$audio_costs = is_array( $audio_costs ) ? $audio_costs : array();
		$stored_max_tokens = get_option( 'alorbach_model_max_tokens', array() );
		$stored_max_tokens = is_array( $stored_max_tokens ) ? $stored_max_tokens : array();

		if ( Admin_Helper::verify_post_nonce( 'alorbach_cost_matrix_nonce', 'alorbach_cost_matrix' ) ) {
			$default_tier = array(
				'input'  => isset( $_POST['text_default_input'] ) ? absint( $_POST['text_default_input'] ) : 400000,
				'output' => isset( $_POST['text_default_output'] ) ? absint( $_POST['text_default_output'] ) : 1600000,
				'cached' => isset( $_POST['text_default_cached'] ) ? absint( $_POST['text_default_cached'] ) : 40000,
			);
			$models = array();
			if ( isset( $_POST['cost_matrix'] ) && is_array( $_POST['cost_matrix'] ) ) {
				foreach ( wp_unslash( $_POST['cost_matrix'] ) as $key => $costs ) {
					$parts = explode( '::', $key, 2 );
					$entry_id = isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
					$model    = isset( $parts[1] ) ? self::sanitize_model_id( $parts[1] ) : '';
					if ( empty( $model ) || $model === 'default' ) {
						continue;
					}
					$models[] = array(
						'model'    => $model,
						'entry_id' => $entry_id,
						'input'    => isset( $costs['input'] ) && $costs['input'] !== '' ? absint( $costs['input'] ) : '',
						'output'   => isset( $costs['output'] ) && $costs['output'] !== '' ? absint( $costs['output'] ) : '',
						'cached'   => isset( $costs['cached'] ) && $costs['cached'] !== '' ? absint( $costs['cached'] ) : '',
					);
				}
			}
			$cost_matrix = array( 'default' => $default_tier, 'models' => $models );
			if ( isset( $_POST['image_costs'] ) && is_array( $_POST['image_costs'] ) ) {
				$image_costs = array();
				foreach ( wp_unslash( $_POST['image_costs'] ) as $size => $cost ) {
					$size = preg_replace( '/[^a-zA-Z0-9\-_x]/', '', sanitize_text_field( $size ) );
					if ( ! empty( $size ) ) {
						$image_costs[ $size ] = absint( $cost );
					}
				}
			}
			if ( isset( $_POST['image_model_costs'] ) && is_array( $_POST['image_model_costs'] ) ) {
				$existing = get_option( 'alorbach_image_model_costs', array() );
				$existing = is_array( $existing ) ? $existing : array();
				$image_model_costs = $existing;
				foreach ( wp_unslash( $_POST['image_model_costs'] ) as $model => $qualities ) {
					$model = self::sanitize_model_id( $model );
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
					$model = self::sanitize_model_id( $model );
					if ( ! empty( $model ) ) {
						$video_costs[ $model ] = absint( $cost );
					}
				}
			}
			if ( isset( $_POST['audio_costs'] ) && is_array( $_POST['audio_costs'] ) ) {
				$audio_costs = array();
				foreach ( wp_unslash( $_POST['audio_costs'] ) as $model => $rate ) {
					$model = self::sanitize_model_id( $model );
					if ( ! empty( $model ) ) {
						$audio_costs[ $model ] = absint( $rate );
					}
				}
			}

			\Alorbach\AIGateway\Cost_Matrix::save_cost_matrix( $cost_matrix );
			update_option( 'alorbach_image_costs', $image_costs );
			update_option( 'alorbach_video_costs', $video_costs );
			update_option( 'alorbach_audio_costs', $audio_costs );
			Admin_Helper::render_notice( __( 'Costs saved.', 'alorbach-ai-gateway' ) );
		}

		$default        = isset( $cost_matrix['default'] ) ? $cost_matrix['default'] : array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		$models_by_entry = array();
		$models_array    = isset( $cost_matrix['models'] ) && is_array( $cost_matrix['models'] ) ? $cost_matrix['models'] : array();
		$entries         = \Alorbach\AIGateway\API_Keys_Helper::get_entries();
		$type_labels     = array( 'openai' => 'OpenAI', 'azure' => 'Azure OpenAI / Foundry', 'google' => 'Google (Gemini)', 'huggingface' => 'Hugging Face', 'huggingface_spaces' => 'Hugging Face Spaces', 'github_models' => 'GitHub Models', 'codex' => 'OpenAI Codex (ChatGPT)' );
		$import_entry_choices = array();
		foreach ( $entries as $entry ) {
			if ( empty( $entry['enabled'] ) ) {
				continue;
			}
			$type = $entry['type'] ?? '';
			if ( ! in_array( $type, array( 'codex', 'huggingface_spaces' ), true ) && empty( $entry['api_key'] ) ) {
				continue;
			}
			if ( 'azure' === $type && empty( $entry['endpoint'] ) ) {
				continue;
			}
			if ( 'huggingface_spaces' === $type && empty( $entry['space_id'] ) ) {
				continue;
			}
			$entry_id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
			if ( '' === $entry_id ) {
				continue;
			}
			$import_entry_choices[] = array(
				'id'    => $entry_id,
				'type'  => $type,
				'label' => ( $type_labels[ $type ] ?? $type ) . ( ! empty( $entry['name'] ) ? ' / ' . $entry['name'] : '' ),
			);
		}
		foreach ( $models_array as $row ) {
			$eid = $row['entry_id'] ?? 'legacy';
			if ( ! isset( $models_by_entry[ $eid ] ) ) {
				$entry = \Alorbach\AIGateway\API_Keys_Helper::get_entry_by_id( $eid );
				$type  = $entry ? ( $entry['type'] ?? '' ) : '';
				$name  = $entry ? ( $entry['name'] ?? '' ) : '';
				$label = ( $type_labels[ $type ] ?? $type ) ? ( ( $type_labels[ $type ] ?? $type ) . ( $name ? ' / ' . $name : '' ) ) : __( 'Legacy / Unknown', 'alorbach-ai-gateway' );
				$models_by_entry[ $eid ] = array(
					'label'  => $label,
					'models' => array(),
				);
			}
			$models_by_entry[ $eid ]['models'][] = $row;
		}
		foreach ( array_keys( $models_by_entry ) as $eid ) {
			usort( $models_by_entry[ $eid ]['models'], function ( $a, $b ) {
				return strcmp( $a['model'] ?? '', $b['model'] ?? '' );
			} );
		}
		$rest_verify_text  = rest_url( 'alorbach/v1/admin/verify-text' );
		$rest_verify_image = rest_url( 'alorbach/v1/admin/verify-image' );
		$rest_verify_audio = rest_url( 'alorbach/v1/admin/verify-audio' );
		$rest_verify_video = rest_url( 'alorbach/v1/admin/verify-video' );
		$rest_fetch        = rest_url( 'alorbach/v1/admin/fetch-importable-models' );
		$rest_import       = rest_url( 'alorbach/v1/admin/import-models' );
		$rest_reset        = rest_url( 'alorbach/v1/admin/reset-models' );
		$rest_save_google_whitelist = rest_url( 'alorbach/v1/admin/save-google-whitelist' );
		$nonce             = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Models', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Import enabled models from your API providers. Azure text model costs are fetched from the Azure Retail Prices API when available. Costs in UC. 1 UC = 0.000001 USD.', 'alorbach-ai-gateway' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="alorbach_import_models_btn"><?php esc_html_e( 'Import models', 'alorbach-ai-gateway' ); ?></button>
				<button type="button" class="button" id="alorbach_reset_models_btn"><?php esc_html_e( 'Reset models', 'alorbach-ai-gateway' ); ?></button>
				<span id="alorbach_import_result"></span>
			</p>

			<div id="alorbach_import_modal" class="alorbach-modal" style="display:none;">
				<div class="alorbach-modal-content">
					<h2 id="alorbach_import_modal_title"><?php esc_html_e( 'Select models to import', 'alorbach-ai-gateway' ); ?></h2>
					<p id="alorbach_import_modal_errors" class="notice notice-error" style="display:none;"></p>
					<div id="alorbach_import_account_filters_step" class="alorbach-import-entry-step">
						<p class="description"><?php esc_html_e( 'Choose which enabled API keys to query before fetching models.', 'alorbach-ai-gateway' ); ?></p>
						<div id="alorbach_import_account_filters"></div>
					</div>
					<nav id="alorbach_import_tab_nav" class="alorbach-import-tabs" role="tablist"></nav>
					<div class="alorbach-import-toolbar">
						<input type="text" id="alorbach_import_filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter models...', 'alorbach-ai-gateway' ); ?>" />
						<select id="alorbach_import_sort" aria-label="<?php esc_attr_e( 'Sort models', 'alorbach-ai-gateway' ); ?>">
							<option value="alpha"><?php esc_html_e( 'Sort: Alphabetical', 'alorbach-ai-gateway' ); ?></option>
							<option value="usage"><?php esc_html_e( 'Sort: Most used', 'alorbach-ai-gateway' ); ?></option>
							<option value="date"><?php esc_html_e( 'Sort: Newest first', 'alorbach-ai-gateway' ); ?></option>
							<option value="provider"><?php esc_html_e( 'Sort: Provider order', 'alorbach-ai-gateway' ); ?></option>
						</select>
					</div>
					<div id="alorbach_import_summary" class="alorbach-import-summary" aria-live="polite"></div>
					<div id="alorbach_import_modal_body"></div>
					<p class="alorbach-modal-actions">
						<button type="button" class="button button-primary" id="alorbach_import_modal_confirm"><?php esc_html_e( 'Import selected', 'alorbach-ai-gateway' ); ?></button>
						<button type="button" class="button" id="alorbach_save_google_whitelist_btn" style="display:none;"><?php esc_html_e( 'Save selected Google models as whitelist', 'alorbach-ai-gateway' ); ?></button>
						<button type="button" class="button" id="alorbach_import_modal_cancel"><?php esc_html_e( 'Cancel', 'alorbach-ai-gateway' ); ?></button>
					</p>
				</div>
			</div>
			<style>
			.alorbach-modal { position: fixed; z-index: 100000; inset: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); overflow: auto; padding: 16px; box-sizing: border-box; }
			.alorbach-modal-content { background: #fff; margin: 0 auto; padding: 20px; width: min(1100px, calc(100vw - 32px)); max-height: calc(100vh - 32px); overflow: hidden; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); display: flex; flex-direction: column; box-sizing: border-box; }
			#alorbach_import_modal_body { flex: 1 1 auto; min-height: 0; max-height: none; overflow-y: auto; }
			.alorbach-import-tabs { display: flex; flex-wrap: wrap; gap: 0; margin: 8px 0 0 0; border-bottom: 2px solid #c3c4c7; position: sticky; top: 0; background: #fff; z-index: 2; }
			.alorbach-import-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; position: sticky; top: 40px; background: #fff; z-index: 2; padding: 8px 0; }
			.alorbach-import-summary { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 10px 0; position: sticky; top: 96px; background: #fff; z-index: 2; padding-bottom: 8px; }
			.alorbach-import-summary span { display: inline-flex; align-items: center; min-height: 28px; padding: 0 10px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 999px; font-size: 12px; color: #1d2327; }
			.alorbach-import-toolbar input { flex: 1 1 320px; min-width: 180px; }
			.alorbach-import-toolbar select { flex: 0 0 auto; min-width: 190px; }
			.alorbach-import-tab { background: #f0f0f1; border: 1px solid #c3c4c7; border-bottom: none; padding: 7px 14px; cursor: pointer; font-size: 13px; border-radius: 3px 3px 0 0; margin-bottom: -2px; color: #3c434a; }
			.alorbach-import-tab.alorbach-tab-active { background: #fff; border-bottom-color: #fff; font-weight: 600; color: #1d2327; }
			.alorbach-import-tab:hover:not(.alorbach-tab-active) { background: #e2e4e7; }
			.alorbach-import-entry { display: none; padding-top: 12px; }
			.alorbach-import-entry.alorbach-tab-visible { display: block; }
			.alorbach-import-entry-label { margin: 0 0 8px 0; color: #1d2327; }
			.alorbach-import-section { margin-bottom: 16px; }
			.alorbach-import-section h4 { margin: 0 0 6px 0; font-size: 13px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
			.alorbach-import-section-count { color: #646970; font-weight: 400; }
			.alorbach-import-list { max-height: min(28vh, 260px); overflow-y: auto; border: 1px solid #ccc; padding: 10px; display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 8px 12px; align-content: start; }
			.alorbach-import-item { margin: 0; min-width: 0; }
			.alorbach-import-item label { display: grid; grid-template-columns: 18px minmax(0, 1fr); gap: 8px; align-items: center; cursor: pointer; min-width: 0; padding: 6px 8px; border: 1px solid #e2e4e7; border-radius: 4px; background: #fff; }
			.alorbach-import-item-copy { min-width: 0; display: flex; align-items: center; gap: 6px; overflow: hidden; }
			.alorbach-import-item-main { display: flex; align-items: center; gap: 6px; min-width: 0; overflow: hidden; }
			.alorbach-import-item-name { font-weight: 500; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.alorbach-capabilities { font-size: 11px; color: #666; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.alorbach-modal-actions { margin-top: 20px; flex: 0 0 auto; display: flex; flex-wrap: wrap; gap: 8px; }
			.alorbach-import-provider-hint { margin: 0 0 12px 0; }
			.alorbach-import-entry-step { margin: 8px 0 12px 0; }
			#alorbach_import_account_filters { margin: 8px 0 12px 0; max-height: 120px; overflow-y: auto; }
			#alorbach_import_account_filters label { display: block; margin-bottom: 4px; cursor: pointer; }
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
			.alorbach-cost-grid .alorbach-actions { white-space: normal; }
			.alorbach-cost-grid .alorbach-test-result { display: block; white-space: normal; }
			.alorbach-cost-grid .alorbach-test-result:not(:empty) { margin-top: 4px; }
			.alorbach-cost-grid .alorbach-test-result.alorbach-has-tooltip { cursor: help; position: relative; }
			.alorbach-cost-grid .alorbach-test-result .alorbach-tooltip-content { display: none; position: absolute; bottom: 100%; left: 0; margin-bottom: 6px; background: #fff; border: 1px solid #c00; color: #c00; padding: 8px 12px; white-space: normal; word-break: break-word; max-width: 420px; max-height: 200px; overflow-y: auto; z-index: 10000; box-shadow: 0 2px 12px rgba(0,0,0,0.2); font-size: 12px; }
			.alorbach-cost-grid .alorbach-test-result:hover .alorbach-tooltip-content { display: block; }
			.alorbach-cost-grid-wrapper { overflow-x: auto; }
			body.alorbach-admin-loading { cursor: wait !important; }
			@media (max-width: 782px) {
				.alorbach-modal { padding: 8px; }
				.alorbach-modal-content { width: calc(100vw - 16px); max-height: calc(100vh - 16px); padding: 16px; }
				.alorbach-import-toolbar { align-items: stretch; }
				.alorbach-import-toolbar select { width: 100%; }
				.alorbach-import-list { grid-template-columns: 1fr; }
				.alorbach-import-item label { align-items: center; }
				.alorbach-modal-actions { position: sticky; bottom: 0; background: #fff; padding-top: 12px; }
			}
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
				<p class="description"><?php esc_html_e( 'Costs per 1M tokens. Unknown models use the default rates. Models are grouped by API account.', 'alorbach-ai-gateway' ); ?></p>
				<?php if ( empty( $models_by_entry ) ) : ?>
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
								<th class="alorbach-cost-num"><?php esc_html_e( 'Max output (tokens)', 'alorbach-ai-gateway' ); ?></th>
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
								<td class="alorbach-cost-num">—</td>
								<td class="alorbach-actions">—</td>
							</tr>
							<?php foreach ( $models_by_entry as $entry_id => $group ) : ?>
								<?php foreach ( $group['models'] as $idx => $row ) :
									$model = $row['model'] ?? '';
									$costs = $row;
									$provider = Admin_Cost_Matrix::get_test_provider_for_model( $model, $entry_id );
									list( $model_base, $model_version ) = \Alorbach\AIGateway\Model_Importer::parse_model_display( $model );
									$model_display = $model_version ? $model_base . ' (' . $model_version . ')' : $model;
									$input_uc  = isset( $costs['input'] ) && $costs['input'] !== '' ? (int) $costs['input'] : 0;
									$output_uc = isset( $costs['output'] ) && $costs['output'] !== '' ? (int) $costs['output'] : 0;
									$cached_uc = isset( $costs['cached'] ) && $costs['cached'] !== '' ? (int) $costs['cached'] : 0;
									$cost_key = $entry_id . '::' . $model;
								?>
									<tr>
										<td>
											<?php echo esc_html( $model_display ); ?>
											<br><span class="description"><?php echo esc_html( $group['label'] ); ?></span>
										</td>
										<td class="alorbach-cost-num">
											<div class="alorbach-cost-cell">
												<input type="number" name="cost_matrix[<?php echo esc_attr( $cost_key ); ?>][input]" class="alorbach-uc-input" value="<?php echo esc_attr( $costs['input'] ?? '' ); ?>" placeholder="Input UC/1M" data-alorbach-usd />
												<span class="alorbach-usd"><?php echo esc_html( $format_usd( $input_uc ) ); ?></span>
											</div>
										</td>
										<td class="alorbach-cost-num">
											<div class="alorbach-cost-cell">
												<input type="number" name="cost_matrix[<?php echo esc_attr( $cost_key ); ?>][output]" class="alorbach-uc-input" value="<?php echo esc_attr( $costs['output'] ?? '' ); ?>" placeholder="Output UC/1M" data-alorbach-usd />
												<span class="alorbach-usd"><?php echo esc_html( $format_usd( $output_uc ) ); ?></span>
											</div>
										</td>
										<td class="alorbach-cost-num">
											<div class="alorbach-cost-cell">
												<input type="number" name="cost_matrix[<?php echo esc_attr( $cost_key ); ?>][cached]" class="alorbach-uc-input" value="<?php echo esc_attr( $costs['cached'] ?? '' ); ?>" placeholder="Cached UC/1M" data-alorbach-usd />
												<span class="alorbach-usd"><?php echo esc_html( $format_usd( $cached_uc ) ); ?></span>
											</div>
										</td>
										<?php
											$cap          = \Alorbach\AIGateway\Cost_Matrix::get_max_tokens( $model );
											$cap_from_api = isset( $stored_max_tokens[ $model ] ) && (int) $stored_max_tokens[ $model ] > 0;
										?>
										<td class="alorbach-cost-num">
											<span title="<?php echo $cap_from_api ? esc_attr__( 'Fetched from provider API', 'alorbach-ai-gateway' ) : esc_attr__( 'Static fallback table', 'alorbach-ai-gateway' ); ?>">
												<?php echo esc_html( number_format( $cap ) ); ?>
												<?php if ( $cap_from_api ) : ?><span style="color:#2271b1;font-size:10px;"> &#9679;</span><?php endif; ?>
											</span>
										</td>
										<td class="alorbach-actions">
											<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'alorbach_remove_model' => '1', 'model' => $model, 'entry_id' => $entry_id ), admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_remove_model', '_wpnonce' ) ); ?>" class="button button-small"><?php esc_html_e( 'Remove', 'alorbach-ai-gateway' ); ?></a>
											<button type="button" class="button alorbach-test-text" data-provider="<?php echo esc_attr( $provider ); ?>" data-model="<?php echo esc_attr( $model ); ?>" data-entry-id="<?php echo esc_attr( $entry_id ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
											<span class="alorbach-test-result" data-type="text" data-model="<?php echo esc_attr( $model ); ?>" data-entry-id="<?php echo esc_attr( $entry_id ); ?>"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endforeach; ?>
							<tr>
								<td colspan="6">
									<label for="alorbach_new_model" class="screen-reader-text"><?php esc_html_e( 'Add custom model', 'alorbach-ai-gateway' ); ?></label>
									<input type="text" id="alorbach_new_model" placeholder="<?php esc_attr_e( 'e.g. gpt-4o, o1-mini, gpt-5-mini', 'alorbach-ai-gateway' ); ?>" style="width: 220px;" />
									<?php
									$enabled_entries = array_filter( $entries, function ( $e ) { return ! empty( $e['enabled'] ); } );
									if ( ! empty( $enabled_entries ) ) :
									?>
									<select id="alorbach_add_model_entry" style="width: 200px;">
										<?php foreach ( $enabled_entries as $e ) :
											$lbl = ( $type_labels[ $e['type'] ?? '' ] ?? $e['type'] ) . ( ! empty( $e['name'] ) ? ' / ' . $e['name'] : '' );
										?>
											<option value="<?php echo esc_attr( $e['id'] ?? '' ); ?>"><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php endif; ?>
									<button type="button" class="button" id="alorbach_add_model_btn"><?php esc_html_e( 'Add', 'alorbach-ai-gateway' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<h2><?php esc_html_e( 'Image', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Gateway-wide image defaults now live in AI Gateway -> Settings -> General Defaults. Manage image model costs here.', 'alorbach-ai-gateway' ); ?></p>

				<?php if ( $show_dalle_section ) : ?>
				<h3><?php esc_html_e( 'DALL-E / GPT Image: Cost per image by size', 'alorbach-ai-gateway' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Legacy size-based costs for OpenAI image models. Add custom sizes as needed.', 'alorbach-ai-gateway' ); ?> <?php esc_html_e( 'Test generates 1 image and uses your OpenAI-style image provider.', 'alorbach-ai-gateway' ); ?></p>
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
										<button type="button" class="button alorbach-test-image" data-size="<?php echo esc_attr( $size ); ?>" data-model="dall-e-3"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
										<span class="alorbach-test-result" data-type="image" data-size="<?php echo esc_attr( $size ); ?>" data-model="dall-e-3"></span>
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
				<?php endif; ?>

				<?php
				$gpt_sizes = array( '1024x1024', '1024x1536', '1536x1024' );
				$gpt_qualities = array( 'low', 'medium', 'high' );
				if ( ! empty( $image_model_costs ) ) :
					?>
				<h3><?php esc_html_e( 'Image models: Cost per image by quality and size', 'alorbach-ai-gateway' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Cost varies by quality (low/medium/high) and resolution. Values in UC; USD shown next to each field.', 'alorbach-ai-gateway' ); ?></p>
				<?php foreach ( $image_model_costs as $img_model => $qualities ) :
					$is_gpt = ( strpos( $img_model, 'gpt-image' ) === 0 );
					$sizes_for_model = $is_gpt ? $gpt_sizes : array( '1024x1024' );
					$remove_image_model_url = wp_nonce_url( add_query_arg( array( 'alorbach_remove_image_model' => '1', 'model' => $img_model ), admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_remove_image_model', '_wpnonce' );
					?>
				<div class="alorbach-cost-grid-wrapper" style="margin-bottom: 1.5rem;">
					<h4><?php echo esc_html( $img_model ); ?></h4>
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Quality', 'alorbach-ai-gateway' ); ?></th>
								<?php foreach ( $sizes_for_model as $s ) : ?>
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
									<?php foreach ( $sizes_for_model as $s ) :
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
					<p class="description" style="margin-top: 8px;">
						<a href="<?php echo esc_url( $remove_image_model_url ); ?>" class="button button-small"><?php esc_html_e( 'Remove', 'alorbach-ai-gateway' ); ?></a>
						<button type="button" class="button button-small alorbach-test-image-model" data-model="<?php echo esc_attr( $img_model ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?> <?php echo esc_html( $img_model ); ?></button>
						<span class="alorbach-test-result" data-type="image-model" data-model="<?php echo esc_attr( $img_model ); ?>"></span>
					</p>
				</div>
				<?php endforeach; ?>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Video', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Cost for 8 seconds per model (scales by duration: 4s = ½, 12s = 1.5×). Add custom models as needed.', 'alorbach-ai-gateway' ); ?></p>
				<div class="alorbach-cost-grid-wrapper">
					<table class="alorbach-cost-grid form-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
								<th class="alorbach-cost-num"><?php esc_html_e( 'UC for 8s (USD)', 'alorbach-ai-gateway' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'alorbach-ai-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $video_costs as $model => $cost ) :
								list( $video_base, $video_version ) = \Alorbach\AIGateway\Model_Importer::parse_model_display( $model );
								$video_display = $video_version ? $video_base . ' (' . $video_version . ')' : $model;
								$cost_int = (int) $cost;
								$can_test_video = self::model_supports_test_capability( $model, 'video' );
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
										<?php if ( $can_test_video ) : ?>
											<button type="button" class="button alorbach-test-video" data-model="<?php echo esc_attr( $model ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
										<?php else : ?>
											<button type="button" class="button" disabled title="<?php esc_attr_e( 'Testing is not available for this provider yet.', 'alorbach-ai-gateway' ); ?>"><?php esc_html_e( 'Unsupported', 'alorbach-ai-gateway' ); ?></button>
										<?php endif; ?>
										<span class="alorbach-test-result" data-type="video" data-model="<?php echo esc_attr( $model ); ?>"></span>
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
				<p class="description"><?php esc_html_e( 'Cost per second of audio by model. Add custom models as needed. Test works for whisper-1, gpt-4o-transcribe, gpt-4o-mini-transcribe, gpt-audio-1.5. TTS models (*-tts) use a different API.', 'alorbach-ai-gateway' ); ?></p>
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
								$can_test_audio = self::model_supports_test_capability( $model, 'audio' );
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
										<?php if ( $can_test_audio ) : ?>
											<button type="button" class="button alorbach-test-audio" data-model="<?php echo esc_attr( $model ); ?>"><?php esc_html_e( 'Test', 'alorbach-ai-gateway' ); ?></button>
										<?php else : ?>
											<button type="button" class="button" disabled title="<?php esc_attr_e( 'Testing is not available for this provider yet.', 'alorbach-ai-gateway' ); ?>"><?php esc_html_e( 'Unsupported', 'alorbach-ai-gateway' ); ?></button>
										<?php endif; ?>
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
				var sel = document.getElementById('alorbach_add_model_entry');
				var model = (input.value || '').trim().replace(/[^a-z0-9\-_.]/gi, '-');
				if (!model) return;
				var base = '<?php echo esc_js( wp_nonce_url( add_query_arg( 'alorbach_add_model', '1', admin_url( 'admin.php?page=alorbach-cost-matrix' ) ), 'alorbach_add_model', '_wpnonce' ) ); ?>';
				var url = base + '&model=' + encodeURIComponent(model);
				if (sel && sel.value) url += '&entry_id=' + encodeURIComponent(sel.value);
				window.location.href = url;
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
				var restVerifyVideo = <?php echo wp_json_encode( $rest_verify_video ); ?>;
				var restFetch = <?php echo wp_json_encode( $rest_fetch ); ?>;
				var restImport = <?php echo wp_json_encode( $rest_import ); ?>;
				var restReset = <?php echo wp_json_encode( $rest_reset ); ?>;
				var restSaveGoogleWhitelist = <?php echo wp_json_encode( $rest_save_google_whitelist ); ?>;
				var okText = <?php echo wp_json_encode( __( 'OK', 'alorbach-ai-gateway' ) ); ?>;
				var errText = <?php echo wp_json_encode( __( 'Error', 'alorbach-ai-gateway' ) ); ?>;
				var debugEnabled = <?php echo wp_json_encode( (bool) get_option( 'alorbach_debug_enabled', false ) ); ?>;
				var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };

				var loadingCount = 0;
				function setLoading(inc) {
					loadingCount += inc;
					document.body.classList.toggle('alorbach-admin-loading', loadingCount > 0);
				}

				function setResult(el, success, msg) {
					var display = msg || (success ? okText : errText);
					if (success) {
						el.textContent = display;
						el.style.color = 'green';
						el.classList.remove('alorbach-has-tooltip');
					} else {
						var safeMsg = (display || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/\n/g, '<br>');
						el.innerHTML = '<span class="alorbach-grid-error">' + (errText.replace(/</g, '&lt;')) + '</span><span class="alorbach-tooltip-content">' + safeMsg + '</span>';
						el.style.color = 'red';
						el.classList.add('alorbach-has-tooltip');
					}
				}

				function showTestResultPopup(type, label, data) {
					var modal = document.getElementById('alorbach_test_result_modal');
					var titleEl = document.getElementById('alorbach_test_result_title');
					var bodyEl = document.getElementById('alorbach_test_result_body');
					var typeLabel = type === 'text' ? '<?php echo esc_js( __( 'Chat test result', 'alorbach-ai-gateway' ) ); ?>' : type === 'image' ? '<?php echo esc_js( __( 'Image test result', 'alorbach-ai-gateway' ) ); ?>' : type === 'audio' ? '<?php echo esc_js( __( 'Audio test result', 'alorbach-ai-gateway' ) ); ?>' : type === 'video' ? '<?php echo esc_js( __( 'Video test result', 'alorbach-ai-gateway' ) ); ?>' : '<?php echo esc_js( __( 'Test result', 'alorbach-ai-gateway' ) ); ?>';
					titleEl.textContent = typeLabel + ': ' + label;
					if (data.success) {
						if (type === 'image' && data.result) {
							bodyEl.innerHTML = '<img src="' + data.result.replace(/"/g, '&quot;') + '" alt="Generated" />';
						} else if (type === 'text' || type === 'audio' || type === 'video') {
							var txt = (data.result || '').toString();
							bodyEl.innerHTML = txt ? '<pre>' + txt.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>' : '<p><?php echo esc_js( __( '(empty response)', 'alorbach-ai-gateway' ) ); ?></p>';
						} else if (data.result) {
							bodyEl.innerHTML = '<p>' + (data.result || '').toString().replace(/</g, '&lt;') + '</p>';
						} else {
							bodyEl.innerHTML = '<p><?php echo esc_js( __( '(empty response)', 'alorbach-ai-gateway' ) ); ?></p>';
						}
					} else {
						var msg = (data.message || errText).replace(/</g, '&lt;').replace(/\n/g, '<br>');
						var isQuota = /quota|exceeded|rate limit|limit.*reached/i.test(msg);
						var hint = isQuota ? '<p class="alorbach-error-hint" style="margin-top:12px;padding:10px;background:#f8f4e8;border-left:4px solid #d4a017;color:#5c4a00;font-size:13px;"><?php echo esc_js( __( 'Free tier limits: ~20 requests/day. Quota resets at midnight UTC. For higher limits, set up billing in your provider\'s console (e.g. Google AI Studio).', 'alorbach-ai-gateway' ) ); ?></p>' : '';
						bodyEl.innerHTML = '<div class="alorbach-test-error"><p style="color:#c00;font-weight:500;margin:0 0 8px 0;">' + msg + '</p>' + hint + '</div>';
					}
					modal.style.display = 'block';
				}

				var testResultModal = document.getElementById('alorbach_test_result_modal');
				function closeTestResultModal() { testResultModal.style.display = 'none'; }
				document.getElementById('alorbach_test_result_close').addEventListener('click', closeTestResultModal);
				testResultModal.addEventListener('click', function(e) { if (e.target === testResultModal) closeTestResultModal(); });
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape' && testResultModal.style.display !== 'none') closeTestResultModal();
				});

				document.querySelectorAll('.alorbach-test-text').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var provider = this.getAttribute('data-provider');
						var model = this.getAttribute('data-model');
						var entryId = this.getAttribute('data-entry-id') || '';
						var resultEl = this.closest('tr') && this.closest('tr').querySelector('.alorbach-test-result[data-type="text"]') || document.querySelector('.alorbach-test-result[data-type="text"][data-model="' + model + '"][data-entry-id="' + entryId + '"]') || document.querySelector('.alorbach-test-result[data-type="text"][data-model="' + model + '"]');
						if (resultEl) resultEl.textContent = '...';
						setLoading(1);
						var body = { provider: provider, model: model };
						if (entryId) body.entry_id = entryId;
						fetch(restVerifyText, { method: 'POST', headers: headers, body: JSON.stringify(body) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								if (resultEl) setResult(resultEl, data.success, data.message);
								showTestResultPopup('text', model, data);
							})
							.catch(function(err) {
								if (resultEl) setResult(resultEl, false, err.message);
								showTestResultPopup('text', model, { success: false, message: err.message });
							})
							.finally(function() { setLoading(-1); });
					});
				});
				document.querySelectorAll('.alorbach-test-image').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var size = this.getAttribute('data-size');
						var model = this.getAttribute('data-model') || '';
						var resultEl = document.querySelector('.alorbach-test-result[data-type="image"][data-size="' + size + '"][data-model="' + (model || '') + '"]');
						if (!resultEl) resultEl = document.querySelector('.alorbach-test-result[data-type="image"][data-size="' + size + '"]');
						if (resultEl) resultEl.textContent = '...';
						setLoading(1);
						var body = { size: size };
						if (model) body.model = model;
						fetch(restVerifyImage, { method: 'POST', headers: headers, body: JSON.stringify(body) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								if (resultEl) { setResult(resultEl, data.success, data.message); }
								showTestResultPopup('image', size, data);
							})
							.catch(function(err) {
								if (resultEl) setResult(resultEl, false, err.message);
								showTestResultPopup('image', size, { success: false, message: err.message });
							})
							.finally(function() { setLoading(-1); });
					});
				});
				document.querySelectorAll('.alorbach-test-image-model').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var model = this.getAttribute('data-model') || '';
						var resultEl = document.querySelector('.alorbach-test-result[data-type="image-model"][data-model="' + model + '"]');
						if (resultEl) resultEl.textContent = '...';
						setLoading(1);
						var size = (model.indexOf('imagen') === 0 || model.indexOf('dall-e') === 0) ? '1024x1024' : '1024x1024';
						fetch(restVerifyImage, { method: 'POST', headers: headers, body: JSON.stringify({ size: size, model: model }) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								if (resultEl) { setResult(resultEl, data.success, data.message); }
								showTestResultPopup('image', model, data);
							})
							.catch(function(err) {
								if (resultEl) setResult(resultEl, false, err.message);
								showTestResultPopup('image', model, { success: false, message: err.message });
							})
							.finally(function() { setLoading(-1); });
					});
				});
				document.querySelectorAll('.alorbach-test-audio').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var model = this.getAttribute('data-model');
						var resultEl = this.closest('tr') && this.closest('tr').querySelector('.alorbach-test-result[data-type="audio"]') || document.querySelector('.alorbach-test-result[data-type="audio"][data-model="' + model + '"]');
						if (resultEl) resultEl.textContent = '...';
						setLoading(1);
						fetch(restVerifyAudio, { method: 'POST', headers: headers, body: JSON.stringify({ model: model }) })
							.then(function(r) {
								return r.text().then(function(txt) {
									var data;
									try { data = txt ? JSON.parse(txt) : {}; } catch (e) {
										var msg = r.ok ? errText : (r.status + ': ' + (txt ? txt.replace(/<[^>]+>/g, ' ').substring(0, 200).trim() : errText));
										return { success: false, message: msg };
									}
									if (!r.ok && !data.message) data.message = r.status + (txt ? ': ' + txt.replace(/<[^>]+>/g, ' ').substring(0, 150).trim() : '');
									return data;
								});
							})
							.then(function(data) {
								if (resultEl) setResult(resultEl, data.success, data.message);
								showTestResultPopup('audio', model, data);
							})
							.catch(function(err) {
								if (resultEl) setResult(resultEl, false, err.message);
								showTestResultPopup('audio', model, { success: false, message: err.message });
							})
							.finally(function() { setLoading(-1); });
					});
				});
				document.querySelectorAll('.alorbach-test-video').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var model = this.getAttribute('data-model');
						var resultEl = this.closest('tr') && this.closest('tr').querySelector('.alorbach-test-result[data-type="video"]') || document.querySelector('.alorbach-test-result[data-type="video"][data-model="' + model + '"]');
						if (resultEl) resultEl.textContent = '...';
						setLoading(1);
						fetch(restVerifyVideo, { method: 'POST', headers: headers, body: JSON.stringify({ model: model }) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								if (resultEl) setResult(resultEl, data.success, data.message);
								showTestResultPopup('video', model, data);
							})
							.catch(function(err) {
								if (resultEl) setResult(resultEl, false, err.message);
								showTestResultPopup('video', model, { success: false, message: err.message });
							})
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
					} else if (data.skipped) {
						var s = data.skipped;
						var skippedParts = [];
						if (s.text && s.text.length) skippedParts.push(s.text.length + ' text');
						if (s.image && s.image.length) skippedParts.push(s.image.length + ' image');
						if (s.video && s.video.length) skippedParts.push(s.video.length + ' video');
						if (s.audio && s.audio.length) skippedParts.push(s.audio.length + ' audio');
						resultEl.textContent = skippedParts.length ? ('Already present: ' + skippedParts.join(', ')) : 'Nothing new to add.';
						resultEl.style.color = '';
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
				var modalEntryStep = document.getElementById('alorbach_import_account_filters_step');
				var pendingAction = null; // 'import' | 'reset'
				var modalStage = 'entries';
				var importEntryChoices = <?php echo wp_json_encode( $import_entry_choices ); ?>;

				function getSelectedEntryIds() {
					return Array.prototype.slice.call(document.querySelectorAll('.alorbach-include-entry:checked')).map(function(cb) {
						return cb.getAttribute('data-entry-id') || '';
					}).filter(Boolean);
				}

				function renderEntryPicker() {
					var wrap = document.getElementById('alorbach_import_account_filters');
					if (!wrap) return;
					wrap.innerHTML = '';
					if (!importEntryChoices.length) {
						wrap.innerHTML = '<p class="description"><?php echo esc_js( __( 'No enabled API key entries are ready for model import.', 'alorbach-ai-gateway' ) ); ?></p>';
						return;
					}
					importEntryChoices.forEach(function(entry) {
						var row = document.createElement('label');
						var cb = document.createElement('input');
						cb.type = 'checkbox';
						cb.className = 'alorbach-include-entry';
						cb.checked = true;
						cb.setAttribute('data-entry-id', entry.id || '');
						row.appendChild(cb);
						row.appendChild(document.createTextNode(' ' + (entry.label || entry.id || '')));
						wrap.appendChild(row);
					});
				}

				function setImportModalStage(stage) {
					var tabNav = document.getElementById('alorbach_import_tab_nav');
					var toolbar = modal.querySelector('.alorbach-import-toolbar');
					var summary = document.getElementById('alorbach_import_summary');
					var body = document.getElementById('alorbach_import_modal_body');
					var saveWhitelistBtn = document.getElementById('alorbach_save_google_whitelist_btn');
					var isEntryStage = stage === 'entries';
					modalStage = stage;
					if (modalEntryStep) modalEntryStep.style.display = isEntryStage ? '' : 'none';
					if (tabNav) tabNav.style.display = isEntryStage ? 'none' : '';
					if (toolbar) toolbar.style.display = isEntryStage ? 'none' : '';
					if (summary) summary.style.display = isEntryStage ? 'none' : '';
					if (body) body.style.display = isEntryStage ? 'none' : '';
					if (saveWhitelistBtn && isEntryStage) saveWhitelistBtn.style.display = 'none';
					modalConfirm.textContent = isEntryStage
						? '<?php echo esc_js( __( 'Load models', 'alorbach-ai-gateway' ) ); ?>'
						: (pendingAction === 'reset'
							? '<?php echo esc_js( __( 'Reset and import selected', 'alorbach-ai-gateway' ) ); ?>'
							: '<?php echo esc_js( __( 'Import selected', 'alorbach-ai-gateway' ) ); ?>');
				}

				function renderModal(data) {
					var labels = data.capability_labels || {};
					var googleImportDefault = data.google_import_default || 'all';
					var filterInput = document.getElementById('alorbach_import_filter');
					var sortInput = document.getElementById('alorbach_import_sort');
					var summaryEl = document.getElementById('alorbach_import_summary');
					if (filterInput) filterInput.value = '';
					if (sortInput) sortInput.value = 'alpha';
					if (summaryEl) summaryEl.innerHTML = '';
					var body = document.getElementById('alorbach_import_modal_body');
					body.innerHTML = '';
					var tabNav = document.getElementById('alorbach_import_tab_nav');
					tabNav.innerHTML = '';
					var entries = data.entries || [];
					var typeLabels = { text: '<?php echo esc_js( __( 'Text (chat)', 'alorbach-ai-gateway' ) ); ?>', image: '<?php echo esc_js( __( 'Image', 'alorbach-ai-gateway' ) ); ?>', video: '<?php echo esc_js( __( 'Video', 'alorbach-ai-gateway' ) ); ?>', audio: '<?php echo esc_js( __( 'Audio', 'alorbach-ai-gateway' ) ); ?>' };
					entries.forEach(function(entry) {
						var entryId = entry.entry_id || '';
						var isGoogle = (entry.type || '') === 'google';
						var isHuggingFace = (entry.type || '') === 'huggingface';
						var defaultChecked = !(isGoogle && googleImportDefault === 'none');

						// Build tab button
						var tab = document.createElement('button');
						tab.type = 'button';
						tab.className = 'alorbach-import-tab';
						tab.setAttribute('data-entry-id', entryId);
						tab.setAttribute('role', 'tab');
						tab.textContent = entry.label || entryId;
						tabNav.appendChild(tab);

						// Build entry panel
						var section = document.createElement('div');
						section.className = 'alorbach-import-entry';
						section.dataset.entryId = entryId;
						section.dataset.entryType = entry.type || '';
						if (isGoogle) {
							var hint = document.createElement('p');
							hint.className = 'alorbach-import-provider-hint description';
							hint.innerHTML = '<?php echo esc_js( __( 'Google lists all catalog models. Check', 'alorbach-ai-gateway' ) ); ?> <a href="https://aistudio.google.com/app/rate_limit" target="_blank" rel="noopener"><?php echo esc_js( __( 'AI Studio Rate Limit', 'alorbach-ai-gateway' ) ); ?></a> <?php echo esc_js( __( 'to see which models you have access to. Only select models with non-zero limits.', 'alorbach-ai-gateway' ) ); ?>';
							section.appendChild(hint);
						} else if (isHuggingFace) {
							var hfHint = document.createElement('p');
							hfHint.className = 'alorbach-import-provider-hint description';
							hfHint.textContent = '<?php echo esc_js( __( 'Hugging Face image imports are filtered to models exposed through hf-inference, which matches this plugin\'s current runtime support. Text/chat models still come from the router catalog. Audio and video remain intentionally unsupported for this provider today.', 'alorbach-ai-gateway' ) ); ?>';
							section.appendChild(hfHint);
						}
						['text','image','video','audio'].forEach(function(type) {
							var items = entry[type] || [];
							if (items.length === 0) return;
							var typeDiv = document.createElement('div');
							typeDiv.className = 'alorbach-import-section';
							typeDiv.dataset.type = type;
							typeDiv.dataset.entryId = entryId;
							var btnSel = '<button type="button" class="button button-small alorbach-select-all" data-type="' + type + '" data-entry-id="' + entryId + '"><?php echo esc_js( __( 'Select all', 'alorbach-ai-gateway' ) ); ?></button>';
							var btnUnsel = '<button type="button" class="button button-small alorbach-unselect-all" data-type="' + type + '" data-entry-id="' + entryId + '"><?php echo esc_js( __( 'Unselect all', 'alorbach-ai-gateway' ) ); ?></button>';
							typeDiv.innerHTML = '<h4><span>' + (typeLabels[type] || type) + '</span><span class="alorbach-import-section-count">(' + items.length + ')</span> ' + btnSel + ' ' + btnUnsel + '</h4><div class="alorbach-import-list" data-type="' + type + '" data-entry-id="' + entryId + '"></div>';
							var list = typeDiv.querySelector('.alorbach-import-list');
							items.forEach(function(item, index) {
								var caps = (item.capabilities || []).map(function(c) { return labels[c] || c; }).join(', ');
								var display = item.version ? (item.base || item.id) + ' (' + item.version + ')' : (item.id || '');
								var usageScore = Number(item.downloads || item.usage || item.popularity || item.likes || 0);
								var dateScore = Date.parse(item.last_modified || item.created_at || item.released_at || '') || 0;
								var safeDisplay = display.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
								var safeCaps = caps.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
								var safeTitle = ((item.id || '') + (caps ? ' [' + caps + ']' : '')).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
								var div = document.createElement('div');
								div.className = 'alorbach-import-item';
								div.dataset.modelId = (item.id || '').toLowerCase();
								div.dataset.modelDisplay = (display || '').toLowerCase();
								div.dataset.sortUsage = String(usageScore);
								div.dataset.sortDate = String(dateScore);
								div.dataset.sortProvider = String(index);
								var checkedAttr = defaultChecked ? ' checked' : '';
								div.innerHTML = '<label title="' + safeTitle + '"><input type="checkbox" class="alorbach-import-cb" data-type="' + type + '" data-entry-id="' + entryId + '" data-id="' + (item.id || '').replace(/"/g, '&quot;') + '"' + checkedAttr + '> <span class="alorbach-import-item-copy"><span class="alorbach-import-item-main"><span class="alorbach-import-item-name">' + safeDisplay + '</span></span> <span class="alorbach-capabilities">(' + safeCaps + ')</span></span></label>';
								list.appendChild(div);
							});
							section.appendChild(typeDiv);
						});
						body.appendChild(section);
					});

					// Activate first tab
					var firstTab = tabNav.querySelector('.alorbach-import-tab');
					var firstEntry = body.querySelector('.alorbach-import-entry');
					if (firstTab) firstTab.classList.add('alorbach-tab-active');
					if (firstEntry) firstEntry.classList.add('alorbach-tab-visible');

					// Tab click switching
					tabNav.querySelectorAll('.alorbach-import-tab').forEach(function(tab) {
						tab.addEventListener('click', function() {
							var eid = this.getAttribute('data-entry-id');
							tabNav.querySelectorAll('.alorbach-import-tab').forEach(function(t) { t.classList.remove('alorbach-tab-active'); });
							this.classList.add('alorbach-tab-active');
							body.querySelectorAll('.alorbach-import-entry').forEach(function(e) { e.classList.remove('alorbach-tab-visible'); });
							var target = body.querySelector('.alorbach-import-entry[data-entry-id="' + eid + '"]');
							if (target) target.classList.add('alorbach-tab-visible');
							if (filterInput) filterInput.value = '';
							applyImportFilter();
							updateImportSummary();
						});
					});

					document.querySelectorAll('.alorbach-select-all').forEach(function(btn) {
						btn.onclick = function() {
							var t = this.getAttribute('data-type');
							var eid = this.getAttribute('data-entry-id') || '';
							document.querySelectorAll('.alorbach-import-cb[data-type="' + t + '"][data-entry-id="' + eid + '"]').forEach(function(cb) { cb.checked = true; });
							updateImportSummary();
						};
					});
					document.querySelectorAll('.alorbach-unselect-all').forEach(function(btn) {
						btn.onclick = function() {
							var t = this.getAttribute('data-type');
							var eid = this.getAttribute('data-entry-id') || '';
							document.querySelectorAll('.alorbach-import-cb[data-type="' + t + '"][data-entry-id="' + eid + '"]').forEach(function(cb) { cb.checked = false; });
							updateImportSummary();
						};
					});
					var hasGoogle = entries.some(function(e) { return (e.type || '') === 'google'; });
					var saveWhitelistBtn = document.getElementById('alorbach_save_google_whitelist_btn');
					if (saveWhitelistBtn) saveWhitelistBtn.style.display = hasGoogle ? '' : 'none';
					bindImportCheckboxes();
					sortImportLists();
					applyImportFilter();
					updateImportSummary();
					setImportModalStage('models');
				}

				function updateImportSummary() {
					var summaryEl = document.getElementById('alorbach_import_summary');
					if (!summaryEl) return;
					var selected = { total: 0, text: 0, image: 0, video: 0, audio: 0 };
					document.querySelectorAll('.alorbach-import-cb:checked').forEach(function(cb) {
						var type = cb.getAttribute('data-type') || 'text';
						selected.total++;
						if (selected[type] !== undefined) selected[type]++;
					});
					var activeEntry = document.querySelector('.alorbach-import-entry.alorbach-tab-visible');
					var visibleParts = [];
					if (activeEntry) {
						['text', 'image', 'video', 'audio'].forEach(function(type) {
							var visible = 0;
							activeEntry.querySelectorAll('.alorbach-import-list[data-type="' + type + '"] .alorbach-import-item').forEach(function(item) {
								if (item.style.display !== 'none') visible++;
							});
							if (visible) visibleParts.push(type + ': ' + visible);
						});
					}
					summaryEl.innerHTML = '';
					[
						'Selected: ' + selected.total,
						'Text: ' + selected.text,
						'Image: ' + selected.image,
						'Video: ' + selected.video,
						'Audio: ' + selected.audio,
						visibleParts.length ? ('Visible in tab: ' + visibleParts.join(', ')) : ''
					].filter(Boolean).forEach(function(text) {
						var chip = document.createElement('span');
						chip.textContent = text;
						summaryEl.appendChild(chip);
					});
				}

				function bindImportCheckboxes() {
					document.querySelectorAll('.alorbach-import-cb').forEach(function(cb) {
						cb.addEventListener('change', updateImportSummary);
					});
				}

				function compareImportItems(a, b) {
					var sortInput = document.getElementById('alorbach_import_sort');
					var mode = sortInput ? sortInput.value : 'alpha';
					var alphaA = a.dataset.modelDisplay || a.dataset.modelId || '';
					var alphaB = b.dataset.modelDisplay || b.dataset.modelId || '';

					if (mode === 'usage') {
						var usageA = Number(a.dataset.sortUsage || 0);
						var usageB = Number(b.dataset.sortUsage || 0);
						if (usageA !== usageB) return usageB - usageA;
					}

					if (mode === 'date') {
						var dateA = Number(a.dataset.sortDate || 0);
						var dateB = Number(b.dataset.sortDate || 0);
						if (dateA !== dateB) return dateB - dateA;
					}

					if (mode === 'provider') {
						var providerA = Number(a.dataset.sortProvider || 0);
						var providerB = Number(b.dataset.sortProvider || 0);
						if (providerA !== providerB) return providerA - providerB;
					}

					return alphaA.localeCompare(alphaB);
				}

				function sortImportLists() {
					document.querySelectorAll('.alorbach-import-list').forEach(function(list) {
						var items = Array.prototype.slice.call(list.querySelectorAll('.alorbach-import-item'));
						items.sort(compareImportItems);
						items.forEach(function(item) { list.appendChild(item); });
					});
				}

				function applyImportFilter() {
					var q = (document.getElementById('alorbach_import_filter').value || '').trim().toLowerCase();
					document.querySelectorAll('.alorbach-import-entry.alorbach-tab-visible').forEach(function(entry) {
						entry.querySelectorAll('.alorbach-import-section').forEach(function(section) {
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
					});
						updateImportSummary();
				}

				function getSelected() {
					var sel = { entries: {} };
					document.querySelectorAll('.alorbach-import-cb:checked').forEach(function(cb) {
						var eid = cb.getAttribute('data-entry-id') || '';
						var t = cb.getAttribute('data-type');
						var id = cb.getAttribute('data-id');
						if (!eid || !id) return;
						if (!sel.entries[eid]) sel.entries[eid] = { text: [], image: [], video: [], audio: [] };
						if (sel.entries[eid][t]) sel.entries[eid][t].push(id);
					});
					return sel;
				}

				document.getElementById('alorbach_import_filter').addEventListener('input', applyImportFilter);
				document.getElementById('alorbach_import_sort').addEventListener('change', function() {
					sortImportLists();
					applyImportFilter();
					updateImportSummary();
				});

				modalCancel.addEventListener('click', function() {
					modal.style.display = 'none';
					pendingAction = null;
					modalStage = 'entries';
				});

				document.getElementById('alorbach_save_google_whitelist_btn').addEventListener('click', function() {
					var googleEntryIds = {};
					document.querySelectorAll('.alorbach-import-entry[data-entry-type="google"]').forEach(function(el) {
						var eid = el.getAttribute('data-entry-id') || '';
						if (eid) googleEntryIds[eid] = true;
					});
					var modelIds = [];
					document.querySelectorAll('.alorbach-import-cb:checked').forEach(function(cb) {
						var eid = cb.getAttribute('data-entry-id') || '';
						if (!googleEntryIds[eid]) return;
						var includeCb = document.querySelector('.alorbach-include-entry[data-entry-id="' + eid + '"]');
						if (includeCb && !includeCb.checked) return;
						var id = cb.getAttribute('data-id');
						if (id) modelIds.push(id);
					});
					if (modelIds.length === 0) {
						var resultEl = document.getElementById('alorbach_import_result');
						setResult(resultEl, false, '<?php echo esc_js( __( 'Select at least one Google model first.', 'alorbach-ai-gateway' ) ); ?>');
						return;
					}
					var btn = this;
					var origText = btn.textContent;
					btn.disabled = true;
					btn.textContent = '...';
					setLoading(1);
					fetch(restSaveGoogleWhitelist, { method: 'POST', headers: headers, body: JSON.stringify({ model_ids: modelIds }) })
						.then(function(r) { return r.json(); })
						.then(function(data) {
							var resultEl = document.getElementById('alorbach_import_result');
							setResult(resultEl, data.success, data.message || '');
						})
						.catch(function(err) {
							var resultEl = document.getElementById('alorbach_import_result');
							setResult(resultEl, false, err.message);
						})
						.finally(function() { btn.disabled = false; btn.textContent = origText; setLoading(-1); });
				});

				modalConfirm.addEventListener('click', function() {
					if (!pendingAction) return;
					if (modalStage === 'entries') {
						var selectedEntryIds = getSelectedEntryIds();
						if (!selectedEntryIds.length) {
							modalErrors.textContent = '<?php echo esc_js( __( 'Select at least one API key entry first.', 'alorbach-ai-gateway' ) ); ?>';
							modalErrors.style.display = 'block';
							return;
						}
						modalErrors.style.display = 'none';
						setLoading(1);
						fetch(restFetch, { method: 'POST', headers: headers, body: JSON.stringify({ entry_ids: selectedEntryIds }) })
							.then(function(r) { return r.json(); })
							.then(function(data) {
								var entries = Array.isArray(data.entries) ? data.entries : [];
								var hasModels = entries.some(function(entry) {
									return ['text', 'image', 'video', 'audio'].some(function(type) {
										return Array.isArray(entry[type]) && entry[type].length > 0;
									});
								});
								if (!hasModels) {
									var messages = [];
									if (data.errors && data.errors.length) {
										messages = messages.concat(data.errors);
									}
									messages.push('<?php echo esc_js( __( 'No importable models were found for the selected API key entries.', 'alorbach-ai-gateway' ) ); ?>');
									modalErrors.textContent = messages.join('; ');
									modalErrors.style.display = 'block';
									setImportModalStage('entries');
									return;
								}
								if (data.errors && data.errors.length) {
									modalErrors.textContent = data.errors.join('; ');
									modalErrors.style.display = 'block';
								} else {
									modalErrors.style.display = 'none';
								}
								renderModal(data);
							})
							.catch(function(err) {
								modalErrors.textContent = err.message || errText;
								modalErrors.style.display = 'block';
								setImportModalStage('entries');
							})
							.finally(function() { setLoading(-1); });
						return;
					}
					var resultEl = document.getElementById('alorbach_import_result');
					var selected = getSelected();
					if (debugEnabled) { console.log('[alorbach-import] payload sent:', JSON.stringify({ selected: selected })); }
					modal.style.display = 'none';
					resultEl.textContent = '...';
					var url = pendingAction === 'reset' ? restReset : restImport;
					setLoading(1);
					fetch(url, { method: 'POST', headers: headers, body: JSON.stringify({ selected: selected }) })
						.then(function(r) { return r.json(); })
						.then(function(data) {
							if (debugEnabled && data._debug) { console.log('[alorbach-import] backend debug:', data._debug); }
							handleImportResult(data, resultEl, errText);
						})
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
					renderEntryPicker();
					setImportModalStage('entries');
					modal.style.display = 'block';
				}

				document.getElementById('alorbach_import_models_btn').addEventListener('click', function() {
					openImportModal('import');
				});
				document.getElementById('alorbach_reset_models_btn').addEventListener('click', function() {
					openImportModal('reset');
				});
			})();
			</script>
		</div>
		<?php
	}
}
