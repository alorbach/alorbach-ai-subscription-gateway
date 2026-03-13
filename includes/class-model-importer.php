<?php
/**
 * Import available models from API providers into Models.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Model_Importer
 */
class Model_Importer {

	/**
	 * Chat model ID prefixes per provider.
	 *
	 * @var array
	 */
	private static $text_prefixes = array(
		'openai' => array( 'gpt-', 'o1', 'o3', 'o4' ),
		'google' => array( 'gemini-' ),
		'azure'  => array(), // Azure returns base model IDs; we accept all from the list.
	);

	/**
	 * Known DALL-E 3 image sizes.
	 *
	 * @var array
	 */
	private static $image_sizes = array( '1024x1024', '1792x1024', '1024x1792' );

	/**
	 * Known video models with default UC per video.
	 *
	 * @var array
	 */
	private static $video_models = array(
		'sora-2' => 400000,
	);

	/**
	 * Known audio models with default UC/sec.
	 *
	 * @var array
	 */
	private static $audio_models = array(
		'whisper-1'         => 100,
		'gpt-4o-transcribe' => 600,
	);

	/**
	 * Known text model costs (UC per 1M tokens). Used when importing.
	 * APIs do not return pricing; this maps common model IDs to public rates.
	 *
	 * @var array
	 */
	private static $known_costs = array(
		'gpt-4o'          => array( 'input' => 2500000, 'output' => 10000000, 'cached' => 250000 ),
		'gpt-4o-mini'     => array( 'input' => 150000, 'output' => 600000, 'cached' => 15000 ),
		'gpt-4.1'         => array( 'input' => 2000000, 'output' => 8000000, 'cached' => 200000 ),
		'gpt-4.1-mini'    => array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 ),
		'gpt-4.1-nano'    => array( 'input' => 100000, 'output' => 400000, 'cached' => 10000 ),
		'gpt-5'           => array( 'input' => 1250000, 'output' => 10000000, 'cached' => 125000 ),
		'gpt-5-mini'      => array( 'input' => 250000, 'output' => 2000000, 'cached' => 25000 ),
		'gpt-5-nano'      => array( 'input' => 50000, 'output' => 400000, 'cached' => 5000 ),
		'o1'              => array( 'input' => 15000000, 'output' => 60000000, 'cached' => 1500000 ),
		'o1-mini'         => array( 'input' => 3000000, 'output' => 12000000, 'cached' => 300000 ),
		'o3-pro'          => array( 'input' => 20000000, 'output' => 80000000, 'cached' => 2000000 ),
		'o4-mini'         => array( 'input' => 1100000, 'output' => 4400000, 'cached' => 110000 ),
		'gemini-2.0-flash' => array( 'input' => 75000, 'output' => 300000, 'cached' => 7500 ),
		'gemini-2.5-flash' => array( 'input' => 75000, 'output' => 300000, 'cached' => 7500 ),
		'gemini-2.5-pro'   => array( 'input' => 1250000, 'output' => 5000000, 'cached' => 125000 ),
		'gemini-1.5-flash' => array( 'input' => 75000, 'output' => 300000, 'cached' => 7500 ),
		'gemini-1.5-pro'   => array( 'input' => 1250000, 'output' => 5000000, 'cached' => 125000 ),
	);

	/**
	 * Capability labels for display.
	 *
	 * @var array
	 */
	public static $capability_labels = array(
		'text_to_text'   => 'Text → Text',
		'image_to_text'  => 'Image → Text',
		'text_to_image'  => 'Text → Image',
		'image_to_image' => 'Image → Image',
		'audio_to_text'  => 'Audio → Text',
		'text_to_audio'  => 'Text → Audio',
		'text_to_video'  => 'Text → Video',
	);

	/**
	 * OpenAI models with vision (image-to-text) capability.
	 *
	 * @var array
	 */
	private static $openai_vision_models = array(
		'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano',
		'gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'o1-mini', 'o4-mini',
	);

	/**
	 * Fetch importable models from all configured providers with capabilities.
	 * Does not import; returns a list for the user to select from.
	 *
	 * @return array{text: array, image: array, audio: array, errors: array}
	 */
	public static function fetch_importable_models() {
		$result = array(
			'text'   => array(),
			'image'  => array(),
			'video'  => array(),
			'audio'  => array(),
			'errors' => array(),
		);

		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();

		// OpenAI: text models + infer capabilities.
		if ( ! empty( $keys['openai'] ) ) {
			$openai = self::fetch_openai_models_detailed( $keys['openai'] );
			if ( is_wp_error( $openai ) ) {
				$result['errors'][] = 'OpenAI: ' . $openai->get_error_message();
			} elseif ( is_array( $openai ) ) {
				foreach ( $openai as $item ) {
					if ( $item['type'] === 'text' ) {
						$result['text'][] = $item;
					} elseif ( $item['type'] === 'image' ) {
						$result['image'][] = $item;
					} elseif ( $item['type'] === 'video' ) {
						$result['video'][] = $item;
					} elseif ( $item['type'] === 'audio' ) {
						$result['audio'][] = $item;
					}
				}
			}
		}

		// Azure: text models with capabilities from API.
		if ( ! empty( $keys['azure_endpoint'] ) && ! empty( $keys['azure'] ) ) {
			$azure = self::fetch_azure_models_detailed( $keys['azure_endpoint'], $keys['azure'] );
			if ( is_wp_error( $azure ) ) {
				$result['errors'][] = 'Azure: ' . $azure->get_error_message();
			} elseif ( is_array( $azure ) ) {
				foreach ( $azure as $item ) {
					if ( $item['type'] === 'text' ) {
						$result['text'][] = $item;
					} elseif ( $item['type'] === 'image' ) {
						$result['image'][] = $item;
					} elseif ( $item['type'] === 'video' ) {
						$result['video'][] = $item;
					} elseif ( $item['type'] === 'audio' ) {
						$result['audio'][] = $item;
					}
				}
			}
		}

		// Google: text models with supportedGenerationMethods.
		if ( ! empty( $keys['google'] ) ) {
			$google = self::fetch_google_models_detailed( $keys['google'] );
			if ( is_wp_error( $google ) ) {
				$result['errors'][] = 'Google: ' . $google->get_error_message();
			} elseif ( is_array( $google ) ) {
				foreach ( $google as $item ) {
					$result['text'][] = $item;
				}
			}
		}

		// Add OpenAI DALL-E sizes if we have OpenAI key (API returns dall-e-3, we use sizes).
		$image_ids = array_column( $result['image'], 'id' );
		if ( ! empty( $keys['openai'] ) ) {
			foreach ( self::$image_sizes as $size ) {
				if ( ! in_array( $size, $image_ids, true ) ) {
					$result['image'][] = array(
						'id'           => $size,
						'provider'     => 'openai',
						'type'         => 'image',
						'capabilities' => array( 'text_to_image' ),
					);
				}
			}
		}

		// Add known OpenAI video models not returned by API (fallback).
		$video_ids = array_column( $result['video'], 'id' );
		if ( ! empty( $keys['openai'] ) ) {
			foreach ( array_keys( self::$video_models ) as $model ) {
				if ( ! in_array( $model, $video_ids, true ) ) {
					$result['video'][] = array(
						'id'           => $model,
						'provider'     => 'openai',
						'type'         => 'video',
						'capabilities' => array( 'text_to_video' ),
					);
				}
			}
		}

		// Add known OpenAI audio models not returned by API (fallback).
		$audio_ids = array_column( $result['audio'], 'id' );
		if ( ! empty( $keys['openai'] ) ) {
			foreach ( self::$audio_models as $model => $rate ) {
				if ( ! in_array( $model, $audio_ids, true ) ) {
					$result['audio'][] = array(
						'id'           => $model,
						'provider'     => 'openai',
						'type'         => 'audio',
						'capabilities' => array( 'audio_to_text' ),
					);
				}
			}
		}

		// Add base and version for display (model name vs version separated).
		foreach ( array( 'text', 'image', 'video', 'audio' ) as $type ) {
			foreach ( $result[ $type ] as $i => $item ) {
				$id = isset( $item['id'] ) ? $item['id'] : '';
				list( $base, $version ) = self::parse_model_display( $id );
				$result[ $type ][ $i ]['base']    = $base;
				$result[ $type ][ $i ]['version'] = $version;
			}
		}

		return $result;
	}

	/**
	 * Reset models and re-import from configured API providers.
	 *
	 * @param array|null $selected Optional. Keys: text, image, audio. Each value is array of IDs to import.
	 *                             If null, imports all available.
	 *
	 * @return array{added: array, skipped: array, errors: array}
	 */
	public static function reset_and_import( $selected = null ) {
		$default_tier = array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		update_option( 'alorbach_cost_matrix', array( 'default' => $default_tier ) );
		update_option( 'alorbach_image_costs', array() );
		update_option( 'alorbach_image_models', array() );
		update_option( 'alorbach_image_model_costs', array() );
		update_option( 'alorbach_video_costs', array() );
		update_option( 'alorbach_audio_costs', array() );
		return self::import_from_providers( true, $selected );
	}

	/**
	 * Fetch models from configured providers and merge into options.
	 *
	 * @param bool        $overwrite If true, overwrite existing models (used by reset).
	 * @param array|null  $selected  Optional. Keys: text, image, audio. Each value is array of IDs to import.
	 *
	 * @return array{added: array, skipped: array, errors: array}
	 */
	public static function import_from_providers( $overwrite = false, $selected = null ) {
		$result = array(
			'added'   => array( 'text' => array(), 'image' => array(), 'video' => array(), 'audio' => array() ),
			'skipped' => array( 'text' => array(), 'image' => array(), 'video' => array(), 'audio' => array() ),
			'errors'  => array(),
		);

		$keys = get_option( 'alorbach_api_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();

		$cost_matrix = get_option( 'alorbach_cost_matrix', array() );
		$cost_matrix = is_array( $cost_matrix ) ? $cost_matrix : array();
		$image_costs      = get_option( 'alorbach_image_costs', array() );
		$image_costs      = is_array( $image_costs ) ? $image_costs : array();
		$image_models     = get_option( 'alorbach_image_models', array() );
		$image_models     = is_array( $image_models ) ? $image_models : array();
		$image_model_costs = get_option( 'alorbach_image_model_costs', array() );
		$image_model_costs = is_array( $image_model_costs ) ? $image_model_costs : array();
		$video_costs = get_option( 'alorbach_video_costs', array() );
		$video_costs = is_array( $video_costs ) ? $video_costs : array();
		$audio_costs = get_option( 'alorbach_audio_costs', array() );
		$audio_costs = is_array( $audio_costs ) ? $audio_costs : array();

		$text_models = array();

		$selected_text  = ( is_array( $selected ) && isset( $selected['text'] ) ) ? $selected['text'] : null;
		$selected_image = ( is_array( $selected ) && isset( $selected['image'] ) ) ? $selected['image'] : null;
		$selected_video = ( is_array( $selected ) && isset( $selected['video'] ) ) ? $selected['video'] : null;
		$selected_audio = ( is_array( $selected ) && isset( $selected['audio'] ) ) ? $selected['audio'] : null;

		// OpenAI: fetches enabled models for the API key.
		if ( ! empty( $keys['openai'] ) ) {
			$openai = self::fetch_openai_models( $keys['openai'] );
			if ( is_wp_error( $openai ) ) {
				$result['errors'][] = 'OpenAI: ' . $openai->get_error_message();
			} elseif ( is_array( $openai ) ) {
				foreach ( $openai as $id ) {
					if ( self::matches_prefix( $id, self::$text_prefixes['openai'] ) ) {
						$text_models[ $id ] = 'openai';
					}
				}
			}
		}

		// Azure: fetches enabled models (filtered by inference capability).
		if ( ! empty( $keys['azure_endpoint'] ) && ! empty( $keys['azure'] ) ) {
			$azure = self::fetch_azure_models( $keys['azure_endpoint'], $keys['azure'] );
			if ( is_wp_error( $azure ) ) {
				$result['errors'][] = 'Azure: ' . $azure->get_error_message();
			} elseif ( is_array( $azure ) ) {
				foreach ( $azure as $id ) {
					$text_models[ $id ] = 'azure';
				}
			}
		}

		// Google: fetches enabled models for the API key.
		if ( ! empty( $keys['google'] ) ) {
			$google = self::fetch_google_models( $keys['google'] );
			if ( is_wp_error( $google ) ) {
				$result['errors'][] = 'Google: ' . $google->get_error_message();
			} elseif ( is_array( $google ) ) {
				foreach ( $google as $id ) {
					if ( self::matches_prefix( $id, self::$text_prefixes['google'] ) ) {
						$text_models[ $id ] = 'google';
					}
				}
			}
		}

		// Azure: fetch live pricing from Retail Prices API when available.
		$azure_prices = array();
		$has_azure = ! empty( $keys['azure_endpoint'] ) && ! empty( $keys['azure'] );
		if ( $has_azure ) {
			$azure_prices = Azure_Retail_Prices::fetch_text_costs( '', 'USD' );
		}

		// Merge text models into cost matrix. Use Azure API or known costs when available.
		$default_tier = array( 'input' => 400000, 'output' => 1600000, 'cached' => 40000 );
		foreach ( $text_models as $model => $provider ) {
			if ( $model === 'default' ) {
				$result['skipped']['text'][] = $model;
				continue;
			}
			if ( $selected_text !== null && ! in_array( $model, $selected_text, true ) ) {
				continue;
			}
			if ( ! $overwrite && isset( $cost_matrix[ $model ] ) ) {
				$result['skipped']['text'][] = $model;
				continue;
			}
			$costs = $default_tier;
			if ( $provider === 'azure' && ! empty( $azure_prices ) ) {
				$base = self::get_model_base_for_pricing( $model );
				if ( isset( $azure_prices[ $base ] ) && self::tier_valid( $azure_prices[ $base ] ) ) {
					$costs = $azure_prices[ $base ];
				} elseif ( isset( self::$known_costs[ $base ] ) ) {
					$costs = self::$known_costs[ $base ];
				} elseif ( isset( self::$known_costs[ $model ] ) ) {
					$costs = self::$known_costs[ $model ];
				}
			} elseif ( isset( self::$known_costs[ $model ] ) ) {
				$costs = self::$known_costs[ $model ];
			} else {
				$base = self::get_model_base_for_pricing( $model );
				if ( isset( self::$known_costs[ $base ] ) ) {
					$costs = self::$known_costs[ $base ];
				}
			}
			$cost_matrix[ $model ] = $costs;
			$result['added']['text'][] = $model;
		}

		// Add image models and sizes. Models (gpt-image-*, dall-e-*) go to alorbach_image_models.
		// Sizes (\d+x\d+) go to alorbach_image_costs. GPT-image models also get alorbach_image_model_costs.
		$image_to_add = ( $selected_image !== null )
			? $selected_image
			: array_merge( array( 'dall-e-3', 'gpt-image-1.5' ), self::$image_sizes );
		$gpt_image_default_costs = array(
			'low'    => array( '1024x1024' => 9000, '1024x1536' => 13000, '1536x1024' => 13000 ),
			'medium' => array( '1024x1024' => 34000, '1024x1536' => 50000, '1536x1024' => 50000 ),
			'high'   => array( '1024x1024' => 133000, '1024x1536' => 200000, '1536x1024' => 200000 ),
		);
		foreach ( $image_to_add as $item ) {
			$item = is_string( $item ) ? $item : (string) $item;
			if ( empty( $item ) ) {
				continue;
			}
			$is_model = ( strpos( $item, 'gpt-image' ) === 0 || strpos( $item, 'dall-e' ) === 0 );
			$is_size  = (bool) preg_match( '/^\d+x\d+$/', $item );
			if ( $is_model ) {
				if ( ! $overwrite && in_array( $item, $image_models, true ) ) {
					$result['skipped']['image'][] = $item;
					continue;
				}
				$image_models[] = $item;
				$result['added']['image'][] = $item;
				if ( strpos( $item, 'gpt-image' ) === 0 ) {
					if ( ! isset( $image_model_costs[ $item ] ) ) {
						$image_model_costs[ $item ] = $gpt_image_default_costs;
					}
				}
			} elseif ( $is_size ) {
				if ( ! $overwrite && isset( $image_costs[ $item ] ) ) {
					$result['skipped']['image'][] = $item;
					continue;
				}
				$image_costs[ $item ] = 40000;
				$result['added']['image'][] = $item;
			}
		}
		$image_models = array_unique( array_values( $image_models ) );
		sort( $image_models );

		// Add video models. When selected provided, use it; else use static models.
		$video_to_add = ( $selected_video !== null )
			? $selected_video
			: array_keys( self::$video_models );
		foreach ( $video_to_add as $model ) {
			$model = is_string( $model ) ? $model : (string) $model;
			if ( empty( $model ) ) {
				continue;
			}
			if ( ! $overwrite && isset( $video_costs[ $model ] ) ) {
				$result['skipped']['video'][] = $model;
				continue;
			}
			$cost = isset( self::$video_models[ $model ] ) ? self::$video_models[ $model ] : 400000;
			$video_costs[ $model ] = $cost;
			$result['added']['video'][] = $model;
		}

		// Add audio models. When selected provided, use it; else use static models.
		$audio_to_add = ( $selected_audio !== null )
			? $selected_audio
			: array_keys( self::$audio_models );
		foreach ( $audio_to_add as $model ) {
			$model = is_string( $model ) ? $model : (string) $model;
			if ( empty( $model ) ) {
				continue;
			}
			if ( ! $overwrite && isset( $audio_costs[ $model ] ) ) {
				$result['skipped']['audio'][] = $model;
				continue;
			}
			$rate = isset( self::$audio_models[ $model ] ) ? self::$audio_models[ $model ] : 100;
			$audio_costs[ $model ] = $rate;
			$result['added']['audio'][] = $model;
		}

		update_option( 'alorbach_cost_matrix', $cost_matrix );
		update_option( 'alorbach_image_costs', $image_costs );
		update_option( 'alorbach_image_models', $image_models );
		update_option( 'alorbach_image_model_costs', $image_model_costs );
		update_option( 'alorbach_video_costs', $video_costs );
		update_option( 'alorbach_audio_costs', $audio_costs );

		return $result;
	}

	/**
	 * Infer capabilities for an OpenAI model ID.
	 *
	 * @param string $model_id Model ID.
	 * @return array Capability keys.
	 */
	private static function infer_openai_capabilities( $model_id ) {
		$caps = array( 'text_to_text' );
		// Vision models can accept images in chat.
		foreach ( self::$openai_vision_models as $v ) {
			if ( $model_id === $v || strpos( $model_id, $v . '-' ) === 0 ) {
				$caps[] = 'image_to_text';
				break;
			}
		}
		// Fallback: gpt-4o*, gpt-4.1*, gpt-5*, o1-mini, o4-mini typically have vision.
		if ( ! in_array( 'image_to_text', $caps, true ) ) {
			if ( preg_match( '/^gpt-4[o1.]|^gpt-5|^o1-mini|^o4-mini/', $model_id ) ) {
				$caps[] = 'image_to_text';
			}
		}
		return $caps;
	}

	/**
	 * Parse model ID into base name and version for display.
	 * e.g. gpt-image-1.5-2025-12-16 → ['gpt-image-1.5', '2025-12-16']
	 * e.g. dall-e-2-2.0 → ['dall-e-2', '2.0']
	 *
	 * @param string $model_id Model ID from API.
	 * @return array{0: string, 1: string} [base_name, version]. Version empty if none detected.
	 */
	public static function parse_model_display( $model_id ) {
		if ( ! is_string( $model_id ) || $model_id === '' ) {
			return array( $model_id, '' );
		}
		// Date suffix: -YYYY-MM-DD
		if ( preg_match( '/^(.+)-(\d{4}-\d{2}-\d{2})$/', $model_id, $m ) ) {
			return array( $m[1], $m[2] );
		}
		// Version suffix: dall-e-2-2.0 → dall-e-2, 2.0 (only for dall-e to avoid splitting gpt-4.1)
		if ( strpos( $model_id, 'dall-e' ) !== false && preg_match( '/^(.+-\d+)-(\d+\.\d+)$/', $model_id, $m ) ) {
			return array( $m[1], $m[2] );
		}
		return array( $model_id, '' );
	}

	/**
	 * Classify OpenAI model ID as text, image, audio, or video.
	 *
	 * @param string $model_id Model ID.
	 * @return string 'text'|'image'|'audio'|'video'
	 */
	private static function classify_openai_model( $model_id ) {
		// Image generation: gpt-image-*, dall-e-*
		if ( strpos( $model_id, 'gpt-image' ) === 0 || strpos( $model_id, 'dall-e' ) === 0 ) {
			return 'image';
		}
		// Video generation: sora-* (case-insensitive; API may return Sora-2)
		if ( strpos( strtolower( $model_id ), 'sora' ) === 0 ) {
			return 'video';
		}
		// Audio: transcription, TTS, speech
		if ( strpos( $model_id, 'whisper' ) === 0 ) {
			return 'audio';
		}
		if ( strpos( $model_id, 'gpt-audio' ) === 0 ) {
			return 'audio';
		}
		if ( strpos( $model_id, '-transcribe' ) !== false || strpos( $model_id, '-tts' ) !== false ) {
			return 'audio';
		}
		if ( strpos( $model_id, 'realtime' ) !== false ) {
			return 'audio';
		}
		return 'text';
	}

	/**
	 * Fetch OpenAI models with capability detection.
	 *
	 * @param string $api_key API key.
	 * @return array|WP_Error List of model items (id, provider, type, capabilities).
	 */
	private static function fetch_openai_models_detailed( $api_key ) {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return array();
		}
		$items = array();
		foreach ( $body['data'] as $m ) {
			$id = isset( $m['id'] ) ? $m['id'] : '';
			if ( ! $id ) {
				continue;
			}
			$type = self::classify_openai_model( $id );

			if ( $type === 'text' ) {
				if ( ! self::matches_prefix( $id, self::$text_prefixes['openai'] ) ) {
					continue;
				}
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'text',
					'capabilities' => self::infer_openai_capabilities( $id ),
				);
			} elseif ( $type === 'image' ) {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'image',
					'capabilities' => array( 'text_to_image' ),
				);
			} elseif ( $type === 'audio' ) {
				$caps = strpos( $id, '-tts' ) !== false
					? array( 'text_to_audio' )
					: array( 'audio_to_text' );
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'audio',
					'capabilities' => $caps,
				);
			} elseif ( $type === 'video' ) {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'openai',
					'type'         => 'video',
					'capabilities' => array( 'text_to_video' ),
				);
			}
		}
		return $items;
	}

	/**
	 * Fetch model IDs from OpenAI.
	 *
	 * @param string $api_key API key.
	 * @return array|WP_Error List of model IDs.
	 */
	private static function fetch_openai_models( $api_key ) {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $body['data'] as $m ) {
			if ( isset( $m['id'] ) ) {
				$ids[] = $m['id'];
			}
		}
		return $ids;
	}

	/**
	 * Map Azure capabilities to our capability keys.
	 *
	 * @param array $caps Azure capabilities object.
	 * @return array Capability keys.
	 */
	private static function map_azure_capabilities( $caps ) {
		$out = array( 'text_to_text' );
		if ( empty( $caps ) ) {
			return $out;
		}
		// Azure may expose vision, image_generation, etc. in capabilities.
		if ( ! empty( $caps['vision'] ) || ! empty( $caps['image_understanding'] ) ) {
			$out[] = 'image_to_text';
		}
		if ( ! empty( $caps['image_generation'] ) ) {
			$out[] = 'text_to_image';
		}
		return $out;
	}

	/**
	 * Fetch Azure Foundry deployments (deployed models only) via legacy endpoint.
	 * Returns deployment names as model IDs. Falls back to null if endpoint unavailable.
	 *
	 * @param string $endpoint Azure endpoint URL.
	 * @param string $api_key  API key.
	 * @return array|null List of model items, or null if not available.
	 */
	private static function fetch_azure_foundry_deployments( $endpoint, $api_key ) {
		$url = $endpoint . '/openai/deployments?api-version=2023-03-15-preview';
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array( 'api-key' => $api_key ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return null;
		}
		$items = array();
		foreach ( $body['data'] as $d ) {
			$id = isset( $d['id'] ) ? $d['id'] : ( isset( $d['name'] ) ? $d['name'] : '' );
			if ( empty( $id ) ) {
				continue;
			}
			$type = self::classify_openai_model( $id );
			if ( $type === 'image' ) {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'azure',
					'type'         => 'image',
					'capabilities' => array( 'text_to_image' ),
				);
			} elseif ( $type === 'audio' ) {
				$audio_caps = strpos( $id, '-tts' ) !== false
					? array( 'text_to_audio' )
					: array( 'audio_to_text' );
				$items[] = array(
					'id'           => $id,
					'provider'     => 'azure',
					'type'         => 'audio',
					'capabilities' => $audio_caps,
				);
			} elseif ( $type === 'video' ) {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'azure',
					'type'         => 'video',
					'capabilities' => array( 'text_to_video' ),
				);
			} else {
				$items[] = array(
					'id'           => $id,
					'provider'     => 'azure',
					'type'         => 'text',
					'capabilities' => self::infer_openai_capabilities( $id ),
				);
			}
		}
		return $items;
	}

	/**
	 * Fetch Azure models with capability detection.
	 * For Foundry, prefers deployments endpoint (deployed-only) when available.
	 *
	 * @param string $endpoint Azure endpoint URL.
	 * @param string $api_key  API key.
	 * @return array|WP_Error List of model items.
	 */
	private static function fetch_azure_models_detailed( $endpoint, $api_key ) {
		$endpoint = rtrim( trim( $endpoint ), '/' );
		$is_foundry = ( strpos( $endpoint, 'services.ai.azure.com' ) !== false );

		// For Foundry: try deployments first (deployed-only), then fall back to models catalog.
		if ( $is_foundry ) {
			$deployments = self::fetch_azure_foundry_deployments( $endpoint, $api_key );
			if ( ! empty( $deployments ) ) {
				return $deployments;
			}
		}

		$urls = $is_foundry
			? array(
				$endpoint . '/openai/v1/models',
				$endpoint . '/openai/models?api-version=2024-10-21',
			)
			: array( $endpoint . '/openai/models?api-version=2024-10-21' );

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array( 'api-key' => $api_key ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				continue;
			}
			$items = array();
			foreach ( $body['data'] as $m ) {
				if ( ! isset( $m['id'] ) ) {
					continue;
				}
				$id   = $m['id'];
				$caps = isset( $m['capabilities'] ) ? $m['capabilities'] : array();
				$inference = ! empty( $caps['inference'] );
				$chat      = ! empty( $caps['chat_completion'] );

				// Classify by model ID (Azure deploys OpenAI-style models: gpt-image-*, gpt-audio-*, etc.).
				$type = self::classify_openai_model( $id );

				if ( $type === 'image' ) {
					$items[] = array(
						'id'           => $id,
						'provider'     => 'azure',
						'type'         => 'image',
						'capabilities' => array( 'text_to_image' ),
					);
				} elseif ( $type === 'video' ) {
					$items[] = array(
						'id'           => $id,
						'provider'     => 'azure',
						'type'         => 'video',
						'capabilities' => array( 'text_to_video' ),
					);
				} elseif ( $type === 'audio' ) {
					$audio_caps = strpos( $id, '-tts' ) !== false
						? array( 'text_to_audio' )
						: array( 'audio_to_text' );
					$items[] = array(
						'id'           => $id,
						'provider'     => 'azure',
						'type'         => 'audio',
						'capabilities' => $audio_caps,
					);
				} else {
					if ( ! $inference && ! $chat && ! empty( $caps ) ) {
						continue;
					}
					$items[] = array(
						'id'           => $id,
						'provider'     => 'azure',
						'type'         => 'text',
						'capabilities' => self::map_azure_capabilities( $caps ),
					);
				}
			}
			return $items;
		}
		return new \WP_Error( 'api_error', __( 'Could not fetch Azure models.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * Map Google supportedGenerationMethods to our capability keys.
	 *
	 * @param array $methods supportedGenerationMethods from API.
	 * @return array Capability keys.
	 */
	private static function map_google_capabilities( $methods ) {
		$out = array();
		if ( ! is_array( $methods ) ) {
			return array( 'text_to_text' );
		}
		$has_content = in_array( 'generateContent', $methods, true );
		$has_image   = in_array( 'generateImages', $methods, true ) || in_array( 'generateImage', $methods, true );
		if ( $has_content ) {
			$out[] = 'text_to_text';
			// Gemini generateContent is multimodal (accepts images).
			$out[] = 'image_to_text';
		}
		if ( $has_image ) {
			$out[] = 'text_to_image';
		}
		return ! empty( $out ) ? $out : array( 'text_to_text' );
	}

	/**
	 * Fetch Google models with supportedGenerationMethods.
	 *
	 * @param string $api_key API key.
	 * @return array|WP_Error List of model items.
	 */
	private static function fetch_google_models_detailed( $api_key ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['models'] ) || ! is_array( $body['models'] ) ) {
			return array();
		}
		$items = array();
		foreach ( $body['models'] as $m ) {
			$name = isset( $m['name'] ) ? $m['name'] : '';
			if ( strpos( $name, 'models/' ) === 0 ) {
				$name = substr( $name, 7 );
			}
			if ( empty( $name ) || ! self::matches_prefix( $name, self::$text_prefixes['google'] ) ) {
				continue;
			}
			$methods = isset( $m['supportedGenerationMethods'] ) ? $m['supportedGenerationMethods'] : array();
			$items[] = array(
				'id'           => $name,
				'provider'     => 'google',
				'type'         => 'text',
				'capabilities' => self::map_google_capabilities( $methods ),
			);
		}
		return $items;
	}

	/**
	 * Fetch model IDs from Azure.
	 * Supports both traditional (*.openai.azure.com) and Foundry (*.services.ai.azure.com) endpoints.
	 * For Foundry, prefers deployments (deployed-only) when available.
	 *
	 * @param string $endpoint Azure endpoint URL.
	 * @param string $api_key  API key.
	 * @return array|WP_Error List of model IDs.
	 */
	private static function fetch_azure_models( $endpoint, $api_key ) {
		$endpoint = rtrim( trim( $endpoint ), '/' );
		$is_foundry = ( strpos( $endpoint, 'services.ai.azure.com' ) !== false );

		// For Foundry: try deployments first (deployed-only).
		if ( $is_foundry ) {
			$deployments = self::fetch_azure_foundry_deployments( $endpoint, $api_key );
			if ( ! empty( $deployments ) ) {
				return array_column( $deployments, 'id' );
			}
		}

		$urls = $is_foundry
			? array(
				$endpoint . '/openai/v1/models',
				$endpoint . '/openai/models?api-version=2024-10-21',
			)
			: array( $endpoint . '/openai/models?api-version=2024-10-21' );

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array( 'api-key' => $api_key ),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				continue;
			}
			$ids = array();
			foreach ( $body['data'] as $m ) {
				if ( ! isset( $m['id'] ) ) {
					continue;
				}
				// Filter by capability: include models that support inference or chat_completion.
				$caps     = isset( $m['capabilities'] ) ? $m['capabilities'] : array();
				$inference = ! empty( $caps['inference'] );
				$chat      = ! empty( $caps['chat_completion'] );
				if ( $inference || $chat || empty( $caps ) ) {
					$ids[] = $m['id'];
				}
			}
			return $ids;
		}
		return new \WP_Error( 'api_error', __( 'Could not fetch Azure models.', 'alorbach-ai-gateway' ) );
	}

	/**
	 * Fetch model IDs from Google.
	 *
	 * @param string $api_key API key.
	 * @return array|WP_Error List of model IDs.
	 */
	private static function fetch_google_models( $api_key ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'api_error', wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['models'] ) || ! is_array( $body['models'] ) ) {
			return array();
		}
		$ids = array();
		foreach ( $body['models'] as $m ) {
			if ( isset( $m['name'] ) ) {
				$name = $m['name'];
				if ( strpos( $name, 'models/' ) === 0 ) {
					$name = substr( $name, 7 );
				}
				$ids[] = $name;
			}
		}
		return $ids;
	}

	/**
	 * Get base model ID for pricing lookup (strips version/date suffix).
	 *
	 * @param string $model_id Full model ID (e.g. gpt-4o-2024-08-06).
	 * @return string Base ID (e.g. gpt-4o).
	 */
	private static function get_model_base_for_pricing( $model_id ) {
		list( $base, ) = self::parse_model_display( $model_id );
		return $base ?: $model_id;
	}

	/**
	 * Check if a cost tier has valid input/output values.
	 *
	 * @param array $tier Array with input, output, cached keys.
	 * @return bool
	 */
	private static function tier_valid( $tier ) {
		if ( ! is_array( $tier ) ) {
			return false;
		}
		$input  = isset( $tier['input'] ) ? (int) $tier['input'] : 0;
		$output = isset( $tier['output'] ) ? (int) $tier['output'] : 0;
		return $input > 0 && $output > 0;
	}

	/**
	 * Check if model ID matches any prefix.
	 *
	 * @param string $model_id Model ID.
	 * @param array  $prefixes Prefixes to match.
	 * @return bool
	 */
	private static function matches_prefix( $model_id, $prefixes ) {
		foreach ( $prefixes as $p ) {
			if ( strpos( $model_id, $p ) === 0 ) {
				return true;
			}
		}
		return empty( $prefixes );
	}
}
