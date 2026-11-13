<?php
/**
 * Request validation helpers for direct image requests (image contract 1.1.0).
 *
 * Pure functions without database or provider access: prompt text rules and the
 * inspection of base64 image payloads (PNG, JPEG, WebP headers only; pixels are
 * never decoded).
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Image_Validation
 */
class Image_Validation {

	/** Maximum prompt length in characters (UTF-8 code points). */
	const MAX_PROMPT_CHARS = 32000;

	/** Maximum decoded size of one mask PNG in bytes. */
	const MAX_MASK_BYTES = 20971520;

	/**
	 * Validate a prompt without altering it. Newlines, tabs and all printable
	 * Unicode survive unchanged; the request hash binds this exact text.
	 *
	 * @param mixed $prompt Raw prompt.
	 * @return true|\WP_Error
	 */
	public static function validate_prompt( $prompt ) {
		if ( ! is_string( $prompt ) ) {
			return new \WP_Error( 'invalid_prompt', __( 'prompt must be a string.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( '' === trim( $prompt ) ) {
			return new \WP_Error( 'invalid_prompt', __( 'prompt must not be empty.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( 1 !== preg_match( '//u', $prompt ) ) {
			return new \WP_Error( 'invalid_prompt', __( 'prompt must be valid UTF-8.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		// Tab, LF and CR are allowed; every other C0 control character and DEL is not.
		if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $prompt ) ) {
			return new \WP_Error( 'invalid_prompt', __( 'prompt contains control characters.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $prompt, 'UTF-8' ) : strlen( $prompt );
		if ( $length > self::MAX_PROMPT_CHARS ) {
			return new \WP_Error( 'prompt_too_long', __( 'prompt exceeds the maximum length.', 'alorbach-ai-gateway' ), array( 'status' => 422, 'max_prompt_chars' => self::MAX_PROMPT_CHARS ) );
		}
		return true;
	}

	/**
	 * Accept raw base64, a data URL, or {b64_json|b64_image, mime_type} and return the
	 * raw base64 string, or null when the value carries no image payload.
	 *
	 * @param mixed $value Raw request value.
	 * @return string|null
	 */
	public static function extract_base64( $value ) {
		if ( is_array( $value ) ) {
			$value = $value['b64_json'] ?? $value['b64_image'] ?? $value['data_url'] ?? '';
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( preg_match( '#^data:[^;,]*;base64,#i', $value, $match ) ) {
			$value = substr( $value, strlen( $match[0] ) );
		}
		$value = preg_replace( '/\s+/', '', $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Inspect an image binary by its header only.
	 *
	 * @param string $binary Decoded bytes.
	 * @return array{mime:string,width:int,height:int,has_alpha:bool}|null
	 */
	public static function inspect_image( $binary ) {
		$binary = (string) $binary;
		if ( strlen( $binary ) >= 33 && "\x89PNG\r\n\x1a\n" === substr( $binary, 0, 8 ) && 'IHDR' === substr( $binary, 12, 4 ) ) {
			$dims  = unpack( 'Nwidth/Nheight', substr( $binary, 16, 8 ) );
			$ctype = ord( $binary[25] );
			$alpha = in_array( $ctype, array( 4, 6 ), true );
			if ( ! $alpha && 3 === $ctype ) {
				$alpha = self::png_has_transparency_chunk( $binary, $ctype );
			}
			return array( 'mime' => 'image/png', 'width' => (int) $dims['width'], 'height' => (int) $dims['height'], 'has_alpha' => $alpha, 'color_type' => $ctype );
		}
		if ( strlen( $binary ) > 4 && "\xFF\xD8" === substr( $binary, 0, 2 ) ) {
			$offset = 2;
			$length = strlen( $binary );
			while ( $offset + 9 < $length ) {
				if ( "\xFF" !== $binary[ $offset ] ) {
					$offset++;
					continue;
				}
				$marker = ord( $binary[ $offset + 1 ] );
				if ( 0xFF === $marker ) {
					$offset++;
					continue;
				}
				if ( 0xD8 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
					$offset += 2;
					continue;
				}
				$segment = unpack( 'n', substr( $binary, $offset + 2, 2 ) )[1];
				if ( in_array( $marker, array( 0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF ), true ) ) {
					$dims = unpack( 'nheight/nwidth', substr( $binary, $offset + 5, 4 ) );
					return array( 'mime' => 'image/jpeg', 'width' => (int) $dims['width'], 'height' => (int) $dims['height'], 'has_alpha' => false, 'color_type' => -1 );
				}
				$offset += 2 + max( 2, (int) $segment );
			}
			return null;
		}
		if ( strlen( $binary ) >= 30 && 'RIFF' === substr( $binary, 0, 4 ) && 'WEBP' === substr( $binary, 8, 4 ) ) {
			$kind = substr( $binary, 12, 4 );
			if ( 'VP8X' === $kind ) {
				$w = unpack( 'V', substr( $binary, 24, 3 ) . "\x00" )[1] + 1;
				$h = unpack( 'V', substr( $binary, 27, 3 ) . "\x00" )[1] + 1;
				return array( 'mime' => 'image/webp', 'width' => (int) $w, 'height' => (int) $h, 'has_alpha' => 0 !== ( ord( $binary[20] ) & 0x10 ), 'color_type' => -1 );
			}
			if ( 'VP8 ' === $kind ) {
				$dims = unpack( 'vwidth/vheight', substr( $binary, 26, 4 ) );
				return array( 'mime' => 'image/webp', 'width' => (int) ( $dims['width'] & 0x3FFF ), 'height' => (int) ( $dims['height'] & 0x3FFF ), 'has_alpha' => false, 'color_type' => -1 );
			}
			if ( 'VP8L' === $kind && "\x2F" === $binary[20] ) {
				$bits = unpack( 'V', substr( $binary, 21, 4 ) )[1];
				return array( 'mime' => 'image/webp', 'width' => (int) ( ( $bits & 0x3FFF ) + 1 ), 'height' => (int) ( ( ( $bits >> 14 ) & 0x3FFF ) + 1 ), 'has_alpha' => true, 'color_type' => -1 );
			}
		}
		return null;
	}

	/**
	 * Validate the edit mask against the primary input image (the first reference).
	 *
	 * @param mixed $mask_value      Raw `mask_image` request value.
	 * @param array $reference_images Normalized reference images (first one is edited).
	 * @return array{b64:string,binary:string,width:int,height:int}|\WP_Error
	 */
	public static function validate_mask( $mask_value, $reference_images ) {
		$b64 = self::extract_base64( $mask_value );
		if ( null === $b64 ) {
			return new \WP_Error( 'invalid_mask_image', __( 'mask_image must be a base64 PNG with an alpha channel.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		$primary = is_array( $reference_images ) && ! empty( $reference_images ) ? reset( $reference_images ) : null;
		if ( ! is_array( $primary ) ) {
			return new \WP_Error( 'mask_requires_input_image', __( 'mask_image requires at least one reference image to edit.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( strlen( $b64 ) > (int) ceil( self::MAX_MASK_BYTES * 4 / 3 ) + 8 ) {
			return new \WP_Error( 'mask_too_large', __( 'mask_image exceeds the maximum size.', 'alorbach-ai-gateway' ), array( 'status' => 422, 'max_mask_bytes' => self::MAX_MASK_BYTES ) );
		}
		$binary = base64_decode( $b64, true );
		if ( false === $binary || '' === $binary ) {
			return new \WP_Error( 'invalid_mask_image', __( 'mask_image is not valid base64.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( strlen( $binary ) > self::MAX_MASK_BYTES ) {
			return new \WP_Error( 'mask_too_large', __( 'mask_image exceeds the maximum size.', 'alorbach-ai-gateway' ), array( 'status' => 422, 'max_mask_bytes' => self::MAX_MASK_BYTES ) );
		}
		$mask = self::inspect_image( $binary );
		if ( ! is_array( $mask ) || 'image/png' !== $mask['mime'] ) {
			return new \WP_Error( 'invalid_mask_image', __( 'mask_image must be a PNG.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( empty( $mask['has_alpha'] ) ) {
			return new \WP_Error( 'mask_alpha_required', __( 'mask_image must contain an alpha channel (transparent pixels mark the area to edit).', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		$primary_b64 = self::extract_base64( $primary );
		$primary_bin = null !== $primary_b64 ? base64_decode( $primary_b64, true ) : false;
		$input       = false === $primary_bin ? null : self::inspect_image( $primary_bin );
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'invalid_reference_image', __( 'The first reference image could not be read as PNG, JPEG or WebP.', 'alorbach-ai-gateway' ), array( 'status' => 422 ) );
		}
		if ( $input['width'] !== $mask['width'] || $input['height'] !== $mask['height'] ) {
			return new \WP_Error(
				'mask_dimension_mismatch',
				__( 'mask_image must have exactly the same pixel dimensions as the first reference image.', 'alorbach-ai-gateway' ),
				array( 'status' => 422, 'mask_width' => $mask['width'], 'mask_height' => $mask['height'], 'image_width' => $input['width'], 'image_height' => $input['height'] )
			);
		}
		return array( 'b64' => $b64, 'binary' => $binary, 'width' => $mask['width'], 'height' => $mask['height'] );
	}

	/**
	 * Whether a PNG without an alpha colour type declares transparency via a tRNS chunk.
	 *
	 * @param string $binary Decoded PNG.
	 * @param int    $ctype  PNG colour type.
	 * @return bool
	 */
	private static function png_has_transparency_chunk( $binary, $ctype ) {
		$offset = 8;
		$length = strlen( $binary );
		while ( $offset + 12 <= $length ) {
			$size = unpack( 'N', substr( $binary, $offset, 4 ) )[1];
			$type = substr( $binary, $offset + 4, 4 );
			if ( 'tRNS' === $type ) {
				return true;
			}
			if ( 'IDAT' === $type || 'IEND' === $type ) {
				return false;
			}
			$offset += 12 + $size;
		}
		return false;
	}
}
