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
		$opts = get_option( 'alorbach_demo_max_tokens_options', '' );
		if ( is_string( $opts ) && $opts !== '' ) {
			$parsed = array_filter( array_map( 'absint', explode( ',', $opts ) ) );
			if ( ! empty( $parsed ) ) {
				return array_map( 'strval', array_values( array_unique( array_filter( $parsed ) ) ) );
			}
		}
		return array( '256', '512', '1024', '2048', '4096', '8192', '16384' );
	}

	/**
	 * Get available text models from cost matrix.
	 *
	 * @return array Model IDs (unique, sorted).
	 */
	public static function get_text_models() {
		$cost_data = \Alorbach\AIGateway\Cost_Matrix::get_cost_matrix();
		$models_array = isset( $cost_data['models'] ) && is_array( $cost_data['models'] ) ? $cost_data['models'] : array();
		$models = array_unique( array_filter( array_map( function ( $row ) {
			return isset( $row['model'] ) ? $row['model'] : null;
		}, $models_array ) ) );
		sort( $models );
		return ! empty( $models ) ? $models : array( 'gpt-4.1-mini' );
	}

	/**
	 * Supported image dimension patterns (WxH).
	 *
	 * @var array
	 */
	private static $image_dimension_sizes = array( '1024x1024', '1024x1536', '1536x1024', '1792x1024', '1024x1792' );

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
			if ( preg_match( '/^\d+x\d+$/', $key ) ) {
				$from_costs[] = $key;
			}
		}
		$sizes = array_unique( array_merge( self::$image_dimension_sizes, $from_costs ) );
		sort( $sizes );
		return ! empty( $sizes ) ? $sizes : array( '1024x1024' );
	}

	/**
	 * Get available image models (gpt-image-*, dall-e-*).
	 *
	 * @return array Model IDs.
	 */
	public static function get_image_models() {
		$models = get_option( 'alorbach_image_models', array() );
		$models = is_array( $models ) ? $models : array();
		$models = array_values( array_filter( $models, 'is_string' ) );
		sort( $models );
		return ! empty( $models ) ? $models : array( 'dall-e-3', 'gpt-image-1.5' );
	}

	/**
	 * Get available video models from video costs.
	 *
	 * @return array Model IDs.
	 */
	public static function get_video_models() {
		return self::get_sorted_option_keys( 'alorbach_video_costs', array( 'sora-2' ) );
	}

	/**
	 * Get available audio models from audio costs.
	 *
	 * @return array Model IDs.
	 */
	public static function get_audio_models() {
		return self::get_sorted_option_keys( 'alorbach_audio_costs', array( 'whisper-1' ) );
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
		sort( $models );
		return ! empty( $models ) ? $models : $default_fallback;
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
				'content' => '[alorbach_demo_image]',
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
		$text_models   = self::get_text_models();
		$image_sizes   = self::get_image_sizes();
		$image_models  = self::get_image_models();
		$audio_models  = self::get_audio_models();
		$video_models  = self::get_video_models();

		$default_chat   = get_option( 'alorbach_demo_default_chat_model', $text_models[0] ?? 'gpt-4.1-mini' );
		$default_image  = get_option( 'alorbach_demo_default_image_model', $image_sizes[0] ?? '1024x1024' );
		$max_tokens_opts = self::get_max_tokens_options();
		$default_max_tokens = get_option( 'alorbach_demo_default_max_tokens', '1024' );
		$default_max_tokens = in_array( $default_max_tokens, $max_tokens_opts, true ) ? $default_max_tokens : ( $max_tokens_opts[0] ?? '1024' );
		$default_image_model = get_option( 'alorbach_image_default_model', $image_models[0] ?? 'dall-e-3' );
		$default_audio  = get_option( 'alorbach_demo_default_audio_model', $audio_models[0] ?? 'whisper-1' );
		$default_video  = get_option( 'alorbach_demo_default_video_model', $video_models[0] ?? 'sora-2' );
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
			$default_chat  = isset( $_POST['alorbach_demo_default_chat_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_chat_model'] ) ) : $default_chat;
			$default_image = isset( $_POST['alorbach_demo_default_image_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_image_model'] ) ) : $default_image;
			$default_max_tokens = isset( $_POST['alorbach_demo_default_max_tokens'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_max_tokens'] ) ) : $default_max_tokens;
			$max_tokens_opts_raw = isset( $_POST['alorbach_demo_max_tokens_options'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_max_tokens_options'] ) ) : '';
			$default_image_model = isset( $_POST['alorbach_image_default_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_image_default_model'] ) ) : $default_image_model;
			$default_audio = isset( $_POST['alorbach_demo_default_audio_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_audio_model'] ) ) : $default_audio;
			$default_video = isset( $_POST['alorbach_demo_default_video_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_video_model'] ) ) : $default_video;
			$allow_chat         = ! empty( $_POST['alorbach_demo_allow_chat_model_select'] );
			$allow_image_size   = ! empty( $_POST['alorbach_demo_allow_image_size_select'] );
			$allow_image_model  = ! empty( $_POST['alorbach_demo_allow_image_model_select'] );
			$allow_image_quality = ! empty( $_POST['alorbach_demo_allow_image_quality_select'] );
			$allow_audio        = ! empty( $_POST['alorbach_demo_allow_audio_model_select'] );
			$allow_video        = ! empty( $_POST['alorbach_demo_allow_video_model_select'] );

			update_option( 'alorbach_demo_default_chat_model', $default_chat );
			update_option( 'alorbach_demo_default_image_model', $default_image );
			update_option( 'alorbach_demo_default_max_tokens', $default_max_tokens );
			update_option( 'alorbach_demo_max_tokens_options', $max_tokens_opts_raw );
			update_option( 'alorbach_image_default_model', $default_image_model );
			update_option( 'alorbach_demo_default_audio_model', $default_audio );
			update_option( 'alorbach_demo_default_video_model', $default_video );
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

				<h2><?php esc_html_e( 'Default models', 'alorbach-ai-gateway' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select the default model for each demo. Users will use these unless you allow model selection below.', 'alorbach-ai-gateway' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="alorbach_demo_default_chat_model"><?php esc_html_e( 'Default chat model', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<select name="alorbach_demo_default_chat_model" id="alorbach_demo_default_chat_model">
								<?php foreach ( $text_models as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $default_chat, $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_demo_default_max_tokens"><?php esc_html_e( 'Default max tokens', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<select name="alorbach_demo_default_max_tokens" id="alorbach_demo_default_max_tokens">
								<?php foreach ( $max_tokens_opts as $v ) : ?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $default_max_tokens, $v ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_demo_max_tokens_options"><?php esc_html_e( 'Max tokens options', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<input type="text" name="alorbach_demo_max_tokens_options" id="alorbach_demo_max_tokens_options" value="<?php echo esc_attr( get_option( 'alorbach_demo_max_tokens_options', '' ) ); ?>" class="regular-text" placeholder="256,512,1024,2048,4096,8192">
							<p class="description"><?php esc_html_e( 'Comma-separated values. Leave empty for defaults.', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_image_default_model"><?php esc_html_e( 'Default image model', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<select name="alorbach_image_default_model" id="alorbach_image_default_model">
								<?php foreach ( $image_models as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $default_image_model, $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'e.g. dall-e-3, gpt-image-1.5', 'alorbach-ai-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_demo_default_image_model"><?php esc_html_e( 'Default image size', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<select name="alorbach_demo_default_image_model" id="alorbach_demo_default_image_model">
								<?php foreach ( $image_sizes as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $default_image, $s ); ?>><?php echo esc_html( $s ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_demo_default_audio_model"><?php esc_html_e( 'Default audio model', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<select name="alorbach_demo_default_audio_model" id="alorbach_demo_default_audio_model">
								<?php foreach ( $audio_models as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $default_audio, $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="alorbach_demo_default_video_model"><?php esc_html_e( 'Default video model', 'alorbach-ai-gateway' ); ?></label></th>
						<td>
							<select name="alorbach_demo_default_video_model" id="alorbach_demo_default_video_model">
								<?php foreach ( $video_models as $m ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $default_video, $m ); ?>><?php echo esc_html( $m ); ?></option>
								<?php endforeach; ?>
							</select>
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
