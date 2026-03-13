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
	 * Get available text models from cost matrix.
	 *
	 * @return array Model IDs.
	 */
	public static function get_text_models() {
		$cost_matrix = get_option( 'alorbach_cost_matrix', array() );
		$cost_matrix = is_array( $cost_matrix ) ? $cost_matrix : array();
		$models     = array_diff_key( $cost_matrix, array( 'default' => 1 ) );
		$models     = array_keys( $models );
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
		$costs  = get_option( 'alorbach_video_costs', array() );
		$costs  = is_array( $costs ) ? $costs : array();
		$models = array_keys( $costs );
		sort( $models );
		return ! empty( $models ) ? $models : array( 'sora-2' );
	}

	/**
	 * Get available audio models from audio costs.
	 *
	 * @return array Model IDs.
	 */
	public static function get_audio_models() {
		$costs  = get_option( 'alorbach_audio_costs', array() );
		$costs  = is_array( $costs ) ? $costs : array();
		$models = array_keys( $costs );
		sort( $models );
		return ! empty( $models ) ? $models : array( 'whisper-1' );
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
		$text_models  = self::get_text_models();
		$image_sizes  = self::get_image_sizes();
		$audio_models = self::get_audio_models();
		$video_models = self::get_video_models();

		$default_chat   = get_option( 'alorbach_demo_default_chat_model', $text_models[0] ?? 'gpt-4.1-mini' );
		$default_image  = get_option( 'alorbach_demo_default_image_model', $image_sizes[0] ?? '1024x1024' );
		$default_audio  = get_option( 'alorbach_demo_default_audio_model', $audio_models[0] ?? 'whisper-1' );
		$default_video  = get_option( 'alorbach_demo_default_video_model', $video_models[0] ?? 'sora-2' );
		$allow_chat     = (bool) get_option( 'alorbach_demo_allow_chat_model_select', false );
		$allow_image    = (bool) get_option( 'alorbach_demo_allow_image_model_select', false );
		$allow_image_quality = (bool) get_option( 'alorbach_demo_allow_image_quality_select', false );
		$allow_audio    = (bool) get_option( 'alorbach_demo_allow_audio_model_select', false );
		$allow_video    = (bool) get_option( 'alorbach_demo_allow_video_model_select', false );

		// Handle form submission.
		if ( isset( $_POST['alorbach_demo_defaults_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['alorbach_demo_defaults_nonce'] ) ), 'alorbach_demo_defaults' ) ) {
			$default_chat  = isset( $_POST['alorbach_demo_default_chat_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_chat_model'] ) ) : $default_chat;
			$default_image = isset( $_POST['alorbach_demo_default_image_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_image_model'] ) ) : $default_image;
			$default_audio = isset( $_POST['alorbach_demo_default_audio_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_audio_model'] ) ) : $default_audio;
			$default_video = isset( $_POST['alorbach_demo_default_video_model'] ) ? sanitize_text_field( wp_unslash( $_POST['alorbach_demo_default_video_model'] ) ) : $default_video;
			$allow_chat         = ! empty( $_POST['alorbach_demo_allow_chat_model_select'] );
			$allow_image        = ! empty( $_POST['alorbach_demo_allow_image_model_select'] );
			$allow_image_quality = ! empty( $_POST['alorbach_demo_allow_image_quality_select'] );
			$allow_audio        = ! empty( $_POST['alorbach_demo_allow_audio_model_select'] );
			$allow_video        = ! empty( $_POST['alorbach_demo_allow_video_model_select'] );

			update_option( 'alorbach_demo_default_chat_model', $default_chat );
			update_option( 'alorbach_demo_default_image_model', $default_image );
			update_option( 'alorbach_demo_default_audio_model', $default_audio );
			update_option( 'alorbach_demo_default_video_model', $default_video );
			update_option( 'alorbach_demo_allow_chat_model_select', $allow_chat );
			update_option( 'alorbach_demo_allow_image_model_select', $allow_image );
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
								<input type="checkbox" name="alorbach_demo_allow_image_model_select" value="1" <?php checked( $allow_image ); ?> />
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
