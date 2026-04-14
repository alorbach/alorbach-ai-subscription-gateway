<?php
/**
 * Hugging Face Inference Providers API provider.
 *
 * @package Alorbach\AIGateway\Providers
 */

namespace Alorbach\AIGateway\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Hugging_Face_Provider
 */
class Hugging_Face_Provider extends Provider_Base {

	/**
	 * Default Hugging Face router base URL.
	 *
	 * @var string
	 */
	const ROUTER_BASE_URL = 'https://router.huggingface.co/v1';

	/**
	 * Hugging Face Hub API base URL.
	 *
	 * @var string
	 */
	const HUB_API_BASE_URL = 'https://huggingface.co/api/models';

	/**
	 * Hugging Face inference API base URL for non-chat tasks.
	 *
	 * @var string
	 */
	const INFERENCE_API_BASE_URL = 'https://router.huggingface.co/hf-inference/models/';

	/**
	 * Hugging Face task provider used by this plugin for non-chat inference.
	 *
	 * @var string
	 */
	const TASK_INFERENCE_PROVIDER = 'hf-inference';

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'huggingface';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_chat() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_images() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_chat_request( $body, $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Hugging Face token not configured.', 'alorbach-ai-gateway' ) );
		}

		$model = $body['model'] ?? '';
		$body  = self::normalize_chat_body( $body, $model );

		return array(
			'url'     => self::build_endpoint_url( $credentials, '/chat/completions' ),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function build_images_request( $prompt, $size, $n, $model, $quality, $output_format, $credentials, $reference_images = array() ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'Hugging Face token not configured.', 'alorbach-ai-gateway' ) );
		}
		if ( ! empty( $reference_images ) ) {
			return new \WP_Error( 'reference_images_unsupported', __( 'Reference-image generation is not supported for Hugging Face image models.', 'alorbach-ai-gateway' ), array( 'status' => 400 ) );
		}

		list( $width, $height ) = self::parse_image_size( $size );
		$steps = 'high' === $quality ? 40 : ( 'low' === $quality ? 20 : 30 );

		$body = array(
			'inputs'     => (string) $prompt,
			'parameters' => array(
				'width'               => $width,
				'height'              => $height,
				'num_inference_steps' => $steps,
			),
			'options'    => array(
				'wait_for_model' => true,
				'use_cache'      => false,
			),
		);

		return array(
			'url'     => self::INFERENCE_API_BASE_URL . self::encode_model_id_for_path( $model ),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_key( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return array( 'success' => false, 'message' => __( 'Hugging Face token not configured.', 'alorbach-ai-gateway' ) );
		}

		return self::make_verified_get(
			self::build_endpoint_url( $credentials, '/models' ),
			array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			__( 'Invalid Hugging Face token.', 'alorbach-ai-gateway' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch_models( $credentials ) {
		$api_key = $credentials['api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return array();
		}

		$text_metadata = self::fetch_hub_metadata_map(
			$api_key,
			array(
				'text-generation',
				'text2text-generation',
				'conversational',
				'image-text-to-text',
			)
		);

		$response = wp_remote_get(
			self::build_endpoint_url( $credentials, '/models' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', $raw );
		}
		if ( ! is_array( $body ) ) {
			return array();
		}

		$models = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
		$items  = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}

			$id = isset( $model['id'] ) ? (string) $model['id'] : '';
			if ( '' === $id ) {
				continue;
			}

			$hub_meta = isset( $text_metadata[ strtolower( $id ) ] ) ? $text_metadata[ strtolower( $id ) ] : array();

			$capabilities = array( 'text_to_text' );
			if ( self::looks_like_vision_model( $model, $id ) ) {
				$capabilities[] = 'image_to_text';
			}

			$items[] = array(
				'id'           => $id,
				'provider'     => 'huggingface',
				'type'         => 'text',
				'capabilities' => $capabilities,
				'max_tokens'   => self::extract_max_tokens( $model ),
				'downloads'    => isset( $hub_meta['downloads'] ) ? (int) $hub_meta['downloads'] : ( isset( $model['downloads'] ) ? (int) $model['downloads'] : 0 ),
				'likes'        => isset( $hub_meta['likes'] ) ? (int) $hub_meta['likes'] : ( isset( $model['likes'] ) ? (int) $model['likes'] : 0 ),
				'created_at'   => isset( $hub_meta['created_at'] ) ? (string) $hub_meta['created_at'] : ( isset( $model['createdAt'] ) ? (string) $model['createdAt'] : '' ),
				'last_modified' => isset( $hub_meta['last_modified'] ) ? (string) $hub_meta['last_modified'] : ( isset( $model['lastModified'] ) ? (string) $model['lastModified'] : '' ),
			);
		}

		$task_map = array(
			'text-to-image' => array( 'type' => 'image', 'capabilities' => array( 'text_to_image' ) ),
		);

		foreach ( $task_map as $pipeline_tag => $meta ) {
			$task_items = self::fetch_hub_models_for_task( $api_key, $pipeline_tag, $meta['type'], $meta['capabilities'] );
			if ( is_wp_error( $task_items ) ) {
				return $task_items;
			}
			$items = array_merge( $items, $task_items );
		}

		return self::dedupe_items( $items );
	}

	/**
	 * Fetch importable models for a specific Hugging Face task.
	 *
	 * @param string   $api_key      Hugging Face token.
	 * @param string   $pipeline_tag Hub pipeline tag.
	 * @param string   $type         Normalized plugin model type.
	 * @param string[] $capabilities Normalized capabilities.
	 * @return array|\WP_Error
	 */
	private static function fetch_hub_models_for_task( $api_key, $pipeline_tag, $type, $capabilities ) {
		$url = add_query_arg(
			array(
				'inference_provider' => self::TASK_INFERENCE_PROVIDER,
				'pipeline_tag'       => $pipeline_tag,
				'limit'              => 200,
			),
			self::HUB_API_BASE_URL
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', $raw );
		}
		if ( ! is_array( $body ) ) {
			return array();
		}

		$items = array();
		foreach ( $body as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) ) {
				continue;
			}
			$items[] = array(
				'id'           => (string) $model['id'],
				'provider'     => 'huggingface',
				'type'         => $type,
				'capabilities' => $capabilities,
				'inference_provider' => self::TASK_INFERENCE_PROVIDER,
				'downloads'    => isset( $model['downloads'] ) ? (int) $model['downloads'] : 0,
				'likes'        => isset( $model['likes'] ) ? (int) $model['likes'] : 0,
				'created_at'   => isset( $model['createdAt'] ) ? (string) $model['createdAt'] : '',
				'last_modified' => isset( $model['lastModified'] ) ? (string) $model['lastModified'] : '',
			);
		}

		return $items;
	}

	/**
	 * Fetch Hub metadata keyed by model ID for one or more pipeline tags.
	 *
	 * @param string   $api_key       Hugging Face token.
	 * @param string[] $pipeline_tags Hub pipeline tags.
	 * @return array
	 */
	private static function fetch_hub_metadata_map( $api_key, $pipeline_tags ) {
		$metadata = array();

		foreach ( (array) $pipeline_tags as $pipeline_tag ) {
			$url = add_query_arg(
				array(
					'inference_provider' => 'all',
					'pipeline_tag'       => $pipeline_tag,
					'limit'              => 250,
				),
				self::HUB_API_BASE_URL
			);

			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
					),
					'timeout' => 20,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}

			if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				continue;
			}

			foreach ( $body as $model ) {
				if ( ! is_array( $model ) || empty( $model['id'] ) ) {
					continue;
				}

				$id = strtolower( (string) $model['id'] );
				if ( '' === $id ) {
					continue;
				}

				$metadata[ $id ] = array(
					'downloads'     => isset( $model['downloads'] ) ? (int) $model['downloads'] : 0,
					'likes'         => isset( $model['likes'] ) ? (int) $model['likes'] : 0,
					'created_at'    => isset( $model['createdAt'] ) ? (string) $model['createdAt'] : '',
					'last_modified' => isset( $model['lastModified'] ) ? (string) $model['lastModified'] : '',
				);
			}
		}

		return $metadata;
	}

	/**
	 * Build an endpoint URL from the optional configured base endpoint.
	 *
	 * @param array  $credentials Credentials array.
	 * @param string $suffix      Endpoint suffix beginning with '/'.
	 * @return string
	 */
	private static function build_endpoint_url( $credentials, $suffix ) {
		$endpoint = isset( $credentials['endpoint'] ) ? trim( (string) $credentials['endpoint'] ) : '';
		if ( '' === $endpoint ) {
			return self::ROUTER_BASE_URL . $suffix;
		}

		$endpoint = rtrim( $endpoint, '/' );
		if ( substr( $endpoint, - strlen( $suffix ) ) === $suffix ) {
			return $endpoint;
		}
		if ( substr( $endpoint, -3 ) === '/v1' ) {
			return $endpoint . $suffix;
		}

		return $endpoint . '/v1' . $suffix;
	}

	/**
	 * Extract a useful max-token value when present.
	 *
	 * @param array $model Model payload.
	 * @return int
	 */
	private static function extract_max_tokens( $model ) {
		$limits = isset( $model['limits'] ) && is_array( $model['limits'] ) ? $model['limits'] : array();

		return (int) (
			$limits['max_output_tokens'] ??
			$model['max_output_tokens'] ??
			$model['output_token_limit'] ??
			$model['max_tokens'] ??
			0
		);
	}

	/**
	 * Infer whether a listed chat model also accepts image input.
	 *
	 * @param array  $model Model payload.
	 * @param string $id    Model ID.
	 * @return bool
	 */
	private static function looks_like_vision_model( $model, $id ) {
		$modalities = array();
		foreach ( array( 'input_modalities', 'supported_input_modalities', 'modalities' ) as $key ) {
			if ( isset( $model[ $key ] ) && is_array( $model[ $key ] ) ) {
				$modalities = array_merge( $modalities, $model[ $key ] );
			}
		}
		foreach ( $modalities as $modality ) {
			if ( is_string( $modality ) && strtolower( $modality ) === 'image' ) {
				return true;
			}
		}

		$id = strtolower( $id );
		return strpos( $id, 'vision' ) !== false || strpos( $id, '-vl' ) !== false || strpos( $id, '-vl-' ) !== false;
	}

	/**
	 * Remove duplicate item/type pairs while preserving order.
	 *
	 * @param array $items Model items.
	 * @return array
	 */
	private static function dedupe_items( $items ) {
		$seen   = array();
		$result = array();
		foreach ( (array) $items as $item ) {
			$id   = isset( $item['id'] ) ? (string) $item['id'] : '';
			$type = isset( $item['type'] ) ? (string) $item['type'] : 'text';
			if ( '' === $id ) {
				continue;
			}
			$key = $type . ':' . strtolower( $id );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Encode a Hub model ID for the inference API path.
	 *
	 * @param string $model Model ID.
	 * @return string
	 */
	private static function encode_model_id_for_path( $model ) {
		return str_replace( '%2F', '/', rawurlencode( (string) $model ) );
	}

	/**
	 * Parse WxH image size into integers with sane defaults.
	 *
	 * @param string $size Requested size.
	 * @return int[]
	 */
	private static function parse_image_size( $size ) {
		if ( preg_match( '/^(\d+)x(\d+)$/', (string) $size, $matches ) ) {
			return array( max( 64, (int) $matches[1] ), max( 64, (int) $matches[2] ) );
		}

		return array( 1024, 1024 );
	}
}