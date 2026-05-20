<?php
/**
 * Admin: Demo defaults and sample page creation.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Demo_Defaults
 */
class Admin_Demo_Defaults {

	/**
	 * Get available max_tokens options for chat demo.
	 *
	 * @return array Token values (integers as strings for option values).
	 */
	public static function get_max_tokens_options() {
		return Admin_Settings::get_max_tokens_options();
	}

	/**
	 * Get available text models from cost matrix as a keyed array.
	 *
	 * Returns an associative array of compound model keys to display labels:
	 *   [ 'entry_id::model_id' => 'model_id (ProviderType / Entry Name)', ... ]
	 * When a row has no entry_id the plain model ID is used as both key and label.
	 * Duplicate compound keys are silently skipped (each entry+model combination is unique).
	 *
	 * @return array<string,string> Compound key => display label, sorted by label.
	 */
	public static function get_text_models() {
		$cost_data    = \Alorbach\AIGateway\Cost_Matrix::get_cost_matrix();
		$models_array = isset( $cost_data['models'] ) && is_array( $cost_data['models'] ) ? $cost_data['models'] : array();
		$models       = array();
		foreach ( $models_array as $row ) {
			if ( empty( $row['model'] ) ) {
				continue;
			}
			$entry_id     = isset( $row['entry_id'] ) ? (string) $row['entry_id'] : '';
			$compound_key = $entry_id ? $entry_id . '::' . $row['model'] : $row['model'];
			if ( isset( $models[ $compound_key ] ) ) {
				continue; // deduplicate by compound key
			}
			$label = $row['model'];
			if ( $entry_id ) {
				$entry = \Alorbach\AIGateway\API_Keys_Helper::get_entry_by_id( $entry_id );
				if ( $entry ) {
					$entry_type = ucfirst( $entry['type'] ?? '' );
					$entry_name = $entry['name'] ?? $entry_id;
					$label      = $row['model'] . ' (' . $entry_type . ' / ' . $entry_name . ')';
				}
			}
			$models[ $compound_key ] = $label;
		}
		if ( empty( $models ) ) {
			$models = array( 'gpt-4.1-mini' => 'gpt-4.1-mini' );
		}
		$models = array_merge( $models, \Alorbach\AIGateway\Local_Codex_Bridge::get_text_models() );
		asort( $models );
		return $models;
	}

	/**
	 * Supported image dimension patterns (WxH).
	 *
	 * @var array
	 */
	private static $image_dimension_sizes = array( '1024x1024', '1024x1536', '1536x1024', '1792x1024', '1024x1792', '2048x2048', '2048x1152', '3840x2160', '2160x3840', 'auto' );

	/**
	 * Get available image sizes (dimensions only, e.g. 1024x1024).
	 * Excludes model names (gpt-image-*, dall-e-*).
	 *
	 * @return array Dimension strings.
	 */
	public static function get_image_sizes() {
		$costs = get_option( 'alorbach_image_costs', array() );
		$costs = is_array( $costs ) ? $costs : array();
		$from_costs = array();
		foreach ( array_keys( $costs ) as $key ) {
			if ( preg_match( '/^\d+x\d+$/', $key ) || 'auto' === $key ) {
				$from_costs[] = $key;
			}
		}
		$sizes = array_unique( array_merge( self::$image_dimension_sizes, $from_costs ) );
		sort( $sizes );
		return ! empty( $sizes ) ? $sizes : array( '1024x1024' );
	}

	/**
	 * Get available image models as a keyed array.
	 *
	 * Returns [ 'entry_id::model_id' => 'model_id (ProviderType / Entry Name)', ... ].
	 * Models without an entry_id mapping use the plain model ID as both key and label.
	 *
	 * @return array<string,string> Compound key => display label, sorted by label.
	 */
	public static function get_image_models() {
		$model_ids = get_option( 'alorbach_image_models', array() );
		$model_ids = is_array( $model_ids ) ? array_values( array_filter( $model_ids, 'is_string' ) ) : array();
		$entry_map = get_option( 'alorbach_image_model_entries', array() );
		$entry_map = is_array( $entry_map ) ? $entry_map : array();
		$model_ids = self::filter_models_by_capability( $model_ids, 'images' );
		$models    = array();
		foreach ( $model_ids as $model_id ) {
			// Handle compound keys already stored in the list (new storage format).
			if ( strpos( $model_id, '::' ) !== false ) {
				$parts        = explode( '::', $model_id, 2 );
				$entry_id     = $parts[0];
				$plain_name   = $parts[1];
				$compound_key = $model_id;
			} else {
				$plain_name   = $model_id;
				$entry_id     = isset( $entry_map[ $model_id ] ) ? (string) $entry_map[ $model_id ] : '';
				$compound_key = $entry_id ? $entry_id . '::' . $model_id : $model_id;
			}
			if ( isset( $models[ $compound_key ] ) ) {
				continue;
			}
			$label = $plain_name;
			if ( $entry_id ) {
				$entry = \Alorbach\AIGateway\API_Keys_Helper::get_entry_by_id( $entry_id );
				if ( $entry ) {
					$entry_type = ucfirst( $entry['type'] ?? '' );
					$entry_name = $entry['name'] ?? $entry_id;
					$label      = $plain_name . ' (' . $entry_type . ' / ' . $entry_name . ')';
				}
			}
			$models[ $compound_key ] = $label;
		}
		if ( empty( $models ) ) {
			$models = array( 'dall-e-3' => 'dall-e-3', 'gpt-image-1.5' => 'gpt-image-1.5' );
		}
		$models = array_merge( $models, \Alorbach\AIGateway\Local_Codex_Bridge::get_image_models() );
		asort( $models );
		return $models;
	}

	/**
	 * Get available video models from imported video model entries.
	 *
	 * @return array<string,string> Model key => display label.
	 */
	public static function get_video_models() {
		return self::get_entry_scoped_models( 'alorbach_video_models', 'alorbach_video_costs', array( 'sora-2' ), 'video' );
	}

	/**
	 * Get available audio models from audio costs.
	 *
	 * @return array Model IDs.
	 */
	public static function get_audio_models() {
		return self::get_sorted_option_keys( 'alorbach_audio_costs', array( 'whisper-1', 'azure-speech' ) );
	}

	/**
	 * Get sorted array_keys from a WP option, with a fallback array.
	 *
	 * @param string $option_key       WP option name.
	 * @param array  $default_fallback Returned when option is empty.
	 * @return array
	 */
	private static function get_sorted_option_keys( $option_key, $default_fallback ) {
		$costs  = get_option( $option_key, array() );
		$costs  = is_array( $costs ) ? $costs : array();
		$models = array_keys( $costs );
		if ( $option_key === 'alorbach_audio_costs' ) {
			$models[] = 'azure-speech';
			$models = array_unique( $models );
			$models = self::filter_models_by_capability( $models, 'audio' );
		} elseif ( $option_key === 'alorbach_video_costs' ) {
			$models = self::filter_models_by_capability( $models, 'video' );
		}
		sort( $models );
		return ! empty( $models ) ? $models : $default_fallback;
	}

	/**
	 * Get entry-aware models from a model list option, falling back to cost keys.
	 *
	 * @param string $model_list_option Option containing plain or compound model IDs.
	 * @param string $cost_option       Cost option keyed by plain model ID.
	 * @param array  $default_fallback  Default plain model IDs.
	 * @param string $capability_group  images, audio, or video.
	 * @return array<string,string> Model key => display label.
	 */
	private static function get_entry_scoped_models( $model_list_option, $cost_option, $default_fallback, $capability_group ) {
		$model_ids = get_option( $model_list_option, array() );
		$model_ids = is_array( $model_ids ) ? array_values( array_filter( $model_ids, 'is_string' ) ) : array();
		if ( empty( $model_ids ) ) {
			$model_ids = self::get_sorted_option_keys( $cost_option, $default_fallback );
		}
		$model_ids = self::filter_models_by_capability( $model_ids, $capability_group );

		$models = array();
		foreach ( $model_ids as $model_id ) {
			$parsed = \Alorbach\AIGateway\Cost_Matrix::parse_model_key( $model_id );
			$entry_id = $parsed['entry_id'];
			$plain_name = $parsed['model'];

			if ( '' === $entry_id ) {
				$entries = self::get_entries_for_model_capability( $plain_name, $capability_group );
				if ( count( $entries ) > 1 ) {
					foreach ( $entries as $entry ) {
						$entry_key = $entry['id'] . '::' . $plain_name;
						if ( ! isset( $models[ $entry_key ] ) ) {
							$models[ $entry_key ] = self::format_entry_model_label( $plain_name, $entry['id'] );
						}
					}
					continue;
				}
			}

			$key = $entry_id ? $entry_id . '::' . $plain_name : $plain_name;
			if ( ! isset( $models[ $key ] ) ) {
				$models[ $key ] = $entry_id ? self::format_entry_model_label( $plain_name, $entry_id ) : $plain_name;
			}
		}

		if ( empty( $models ) ) {
			foreach ( $default_fallback as $fallback ) {
				$models[ $fallback ] = $fallback;
			}
		}
		asort( $models );
		return $models;
	}

	/**
	 * Find enabled API key entries that can run a model for a capability.
	 *
	 * @param string $model            Plain model ID.
	 * @param string $capability_group images, audio, or video.
	 * @return array<int,array>
	 */
	private static function get_entries_for_model_capability( $model, $capability_group ) {
		$entries = \Alorbach\AIGateway\API_Keys_Helper::get_entries();
		$result  = array();
		foreach ( $entries as $entry ) {
			if ( empty( $entry['enabled'] ) || empty( $entry['id'] ) || empty( $entry['type'] ) ) {
				continue;
			}
			if ( ! self::entry_type_matches_model( $entry['type'], $model ) ) {
				continue;
			}
			$provider = \Alorbach\AIGateway\Providers\Provider_Registry::get( $entry['type'] );
			if ( ! $provider ) {
				continue;
			}
			if ( 'video' === $capability_group && ! $provider->supports_video() ) {
				continue;
			}
			if ( 'audio' === $capability_group && ! $provider->supports_audio() ) {
				continue;
			}
			if ( 'images' === $capability_group && ! $provider->supports_images() ) {
				continue;
			}
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * Check whether a provider type is a plausible owner for a plain model ID.
	 *
	 * @param string $entry_type Provider type.
	 * @param string $model      Plain model ID.
	 * @return bool
	 */
	private static function entry_type_matches_model( $entry_type, $model ) {
		$model_lower = strtolower( (string) $model );
		if ( strpos( $model_lower, 'sora' ) === 0 ) {
			return in_array( $entry_type, array( 'openai', 'azure' ), true );
		}
		if ( strpos( $model_lower, 'veo-' ) === 0 || strpos( $model_lower, 'gemini' ) === 0 ) {
			return 'google' === $entry_type;
		}
		if ( strpos( $model_lower, 'hf-space:' ) === 0 ) {
			return 'huggingface_spaces' === $entry_type;
		}
		if ( strpos( $model_lower, '/' ) !== false ) {
			return in_array( $entry_type, array( 'huggingface', 'github_models' ), true );
		}
		return true;
	}

	/**
	 * Format a model label with its API key entry name.
	 *
	 * @param string $model    Plain model ID.
	 * @param string $entry_id API key entry ID.
	 * @return string
	 */
	private static function format_entry_model_label( $model, $entry_id ) {
		$entry = \Alorbach\AIGateway\API_Keys_Helper::get_entry_by_id( $entry_id );
		if ( ! $entry ) {
			return $model;
		}
		$entry_type = ucfirst( $entry['type'] ?? '' );
		$entry_name = $entry['name'] ?? $entry_id;
		return $model . ' (' . $entry_type . ' / ' . $entry_name . ')';
	}

	/**
	 * Filter model IDs to only those supported by the currently resolved provider capability.
	 *
	 * @param array  $models           Model IDs.
	 * @param string $capability_group images, audio, or video.
	 * @return array
	 */
	private static function filter_models_by_capability( $models, $capability_group ) {
		$models = is_array( $models ) ? $models : array();
		return array_values( array_filter( $models, function ( $model ) use ( $capability_group ) {
			if ( ! is_string( $model ) || $model === '' ) {
				return false;
			}

			$provider_id = \Alorbach\AIGateway\API_Client::get_provider_for_model( $model );
			$provider    = \Alorbach\AIGateway\Providers\Provider_Registry::get( $provider_id );
			if ( ! $provider ) {
				return true;
			}

			if ( $capability_group === 'images' ) {
				return (bool) $provider->supports_images();
			}
			if ( $capability_group === 'audio' ) {
				return (bool) $provider->supports_audio();
			}
			if ( $capability_group === 'video' ) {
				return (bool) $provider->supports_video();
			}

			return true;
		} ) );
	}

	/**
	 * Create sample demo pages.
	 *
	 * @return array{success: bool, message: string, page_ids: array, links: array}
	 */
	public static function create_sample_pages() {
		$pages = array(
			array(
				'title'   => __( 'AI Chat Demo', 'alorbach-ai-gateway' ),
				'slug'    => 'ai-chat-demo',
				'content' => '[alorbach_demo_chat]',
			),
			array(
				'title'   => __( 'Image Generator', 'alorbach-ai-gateway' ),
				'slug'    => 'image-generator',
				'content' => '<p>' . esc_html__( 'This demo shows generation progress while your image is being created. It uses estimated progress by default and can surface live previews when the provider supports them.', 'alorbach-ai-gateway' ) . '</p>' . "\n\n" . '[alorbach_demo_image]',
			),
			array(
				'title'   => __( 'Audio Transcription', 'alorbach-ai-gateway' ),
				'slug'    => 'audio-transcription',
				'content' => '[alorbach_demo_transcribe]',
			),
			array(
				'title'   => __( 'Video Generator', 'alorbach-ai-gateway' ),
				'slug'    => 'video-generator',
				'content' => '[alorbach_demo_video]',
			),
		);

		$created_ids = array();
		$links       = array();

		foreach ( $pages as $page ) {
			$existing_pages = get_posts( array(
				'name'           => $page['slug'],
				'post_type'      => 'page',
				'posts_per_page' => 1,
				'post_status'    => 'any',
			) );
			$existing       = ! empty( $existing_pages ) ? $existing_pages[0] : null;
			if ( $existing ) {
				wp_update_post( array(
					'ID'           => $existing->ID,
					'post_content' => $page['content'],
					'post_status'  => 'publish',
				) );
				$created_ids[] = $existing->ID;
				$links[]       = array(
					'title' => $page['title'],
					'url'   => get_permalink( $existing ),
				);
			} else {
				$id = wp_insert_post( array(
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => get_current_user_id(),
				) );
				if ( ! is_wp_error( $id ) && $id ) {
					$created_ids[] = $id;
					$links[]       = array(
						'title' => $page['title'],
						'url'   => get_permalink( $id ),
					);
				}
			}
		}

		update_option( 'alorbach_demo_page_ids', $created_ids );

		return array(
			'success'  => ! empty( $created_ids ),
			'message'  => sprintf(
				/* translators: %d: number of pages created */
				_n( '%d sample page created.', '%d sample pages created.', count( $created_ids ), 'alorbach-ai-gateway' ),
				count( $created_ids )
			),
			'page_ids' => $created_ids,
			'links'   => $links,
		);
	}

	/**
	 * Render Demo Defaults page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}
		// One-time migration: alorbach_demo_allow_image_model_select used to control size.
		if ( ! get_option( 'alorbach_demo_image_options_migrated', false ) ) {
			$old_model_opt = get_option( 'alorbach_demo_allow_image_model_select', false );
			update_option( 'alorbach_demo_allow_image_size_select', (bool) $old_model_opt );
			update_option( 'alorbach_demo_allow_image_model_select', false );
			update_option( 'alorbach_demo_image_options_migrated', true );
		}

		$allow_chat     = (bool) get_option( 'alorbach_demo_allow_chat_model_select', false );
		$allow_image_size = (bool) get_option( 'alorbach_demo_allow_image_size_select', false );
		$allow_image_model = (bool) get_option( 'alorbach_demo_allow_image_model_select', false );
		$allow_image_quality = (bool) get_option( 'alorbach_demo_allow_image_quality_select', false );
		$allow_audio    = (bool) get_option( 'alorbach_demo_allow_audio_model_select', false );
		$allow_video    = (bool) get_option( 'alorbach_demo_allow_video_model_select', false );

		// Handle form submission.
		if ( isset( $_POST['alorbach_demo_defaults_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_demo_defaults_nonce'] ) ), 'alorbach_demo_defaults' ) ) {
			$allow_chat         = ! empty( $_POST['alorbach_demo_allow_chat_model_select'] );
			$allow_image_size   = ! empty( $_POST['alorbach_demo_allow_image_size_select'] );
			$allow_image_model  = ! empty( $_POST['alorbach_demo_allow_image_model_select'] );
			$allow_image_quality = ! empty( $_POST['alorbach_demo_allow_image_quality_select'] );
			$allow_audio        = ! empty( $_POST['alorbach_demo_allow_audio_model_select'] );
			$allow_video        = ! empty( $_POST['alorbach_demo_allow_video_model_select'] );

			update_option( 'alorbach_demo_allow_chat_model_select', $allow_chat );
			update_option( 'alorbach_demo_allow_image_size_select', $allow_image_size );
			update_option( 'alorbach_demo_allow_image_model_select', $allow_image_model );
			update_option( 'alorbach_demo_allow_image_quality_select', $allow_image_quality );
			update_option( 'alorbach_demo_allow_audio_model_select', $allow_audio );
			update_option( 'alorbach_demo_allow_video_model_select', $allow_video );

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Demo defaults saved.', 'alorbach-ai-gateway' ) . '</p></div>';
		}

		// Handle Create sample pages.
		$create_message = '';
		if ( isset( $_POST['alorbach_create_sample_pages_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_create_sample_pages_nonce'] ) ), 'alorbach_create_sample_pages' ) ) {
			$result = self::create_sample_pages();
			if ( $result['success'] ) {
				$create_message = '<div class="notice notice-success"><p>' . esc_html( $result['message'] ) . ' ';
				foreach ( $result['links'] as $link ) {
					$create_message .= '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['title'] ) . '</a> ';
				}
				$create_message .= '</p></div>';
			} else {
				$create_message = '<div class="notice notice-error"><p>' . esc_html__( 'Could not create sample pages.', 'alorbach-ai-gateway' ) . '</p></div>';
			}
		}

		$existing_ids = get_option( 'alorbach_demo_page_ids', array() );
		$existing_ids = is_array( $existing_ids ) ? $existing_ids : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Demo Defaults', 'alorbach-ai-gateway' ); ?></h1>

			<?php echo $create_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<form method="post" style="max-width: 600px; margin-top: 20px;">
				<?php wp_nonce_field( 'alorbach_demo_defaults', 'alorbach_demo_defaults_nonce' ); ?>

				<h2><?php esc_html_e( 'Demo behavior', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Demo pages inherit the gateway-wide defaults from AI Gateway -> Settings. Use this page only for demo-specific UI behavior and sample page creation.', 'alorbach-ai-gateway' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Gateway defaults', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<p style="margin-top: 0;"><?php esc_html_e( 'Manage the default chat, image, audio, and video settings in AI Gateway -> Settings -> General Defaults.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Allow user model selection', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When enabled, users can choose a different model from the dropdown. Disabled by default.', 'alorbach-ai-gateway' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Chat demo', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="alorbach_demo_allow_chat_model_select" value="1" <?php checked( $allow_chat ); ?> />
								<?php esc_html_e( 'Allow users to select chat model', 'alorbach-ai-gateway' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Image demo', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="alorbach_demo_allow_image_model_select" value="1" <?php checked( $allow_image_model ); ?> />
								<?php esc_html_e( 'Allow users to select image model', 'alorbach-ai-gateway' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="alorbach_demo_allow_image_size_select" value="1" <?php checked( $allow_image_size ); ?> />
								<?php esc_html_e( 'Allow users to select image size', 'alorbach-ai-gateway' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="alorbach_demo_allow_image_quality_select" value="1" <?php checked( $allow_image_quality ); ?> />
								<?php esc_html_e( 'Allow users to select image quality', 'alorbach-ai-gateway' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Audio demo', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="alorbach_demo_allow_audio_model_select" value="1" <?php checked( $allow_audio ); ?> />
								<?php esc_html_e( 'Allow users to select audio model', 'alorbach-ai-gateway' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Video demo', 'alorbach-ai-gateway' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="alorbach_demo_allow_video_model_select" value="1" <?php checked( $allow_video ); ?> />
								<?php esc_html_e( 'Allow users to select video model', 'alorbach-ai-gateway' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save defaults', 'alorbach-ai-gateway' ); ?>" />
				</p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Sample pages', 'alorbach-ai-gateway' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Create demo pages with the shortcodes. Existing pages with the same slug will be updated.', 'alorbach-ai-gateway' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'alorbach_create_sample_pages', 'alorbach_create_sample_pages_nonce' ); ?>
				<button type="submit" class="button button-primary" name="alorbach_create_sample_pages" value="1"><?php esc_html_e( 'Create sample pages', 'alorbach-ai-gateway' ); ?></button>
			</form>

			<?php if ( ! empty( $existing_ids ) ) : ?>
				<p style="margin-top: 12px;">
					<?php esc_html_e( 'Existing demo pages:', 'alorbach-ai-gateway' ); ?>
					<?php
					foreach ( $existing_ids as $pid ) {
						$post = get_post( $pid );
						if ( $post ) {
							echo ' <a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $post->post_title ) . '</a>';
						}
					}
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
