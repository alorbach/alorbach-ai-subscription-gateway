<?php
/**
 * Safe, bounded diagnostics for upstream image provider errors.
 *
 * @package Alorbach\AIGateway
 */

namespace Alorbach\AIGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts untrusted provider error responses into bounded safe diagnostics.
 * Raw provider response bodies and unsanitized messages are never returned.
 */
class Image_Error_Diagnostics {
	const REASONS = array(
		'invalid_parameter',
		'unsupported_parameter',
		'content_policy_violation',
		'invalid_size',
		'invalid_quality',
		'model_unavailable',
		'rate_limit',
		'provider_rejected',
		'unknown',
	);

	const PARAMS = array(
		'size',
		'quality',
		'prompt',
		'n',
		'model',
		'output_format',
		'response_format',
		'background',
		'moderation',
		'image',
		'mask',
		'output_compression',
	);

	const PROVIDER_CODES = array(
		'bad_request',
		'content_policy_violation',
		'deployment_not_found',
		'insufficient_quota',
		'invalid_api_key',
		'invalid_image_size',
		'invalid_parameter',
		'invalid_quality',
		'invalid_request_error',
		'model_not_found',
		'model_unavailable',
		'rate_limit_exceeded',
		'responsible_ai_policy_violation',
		'server_error',
		'temporarily_unavailable',
		'unsupported_parameter',
		'unsupported_value',
	);

	const MAX_SAFE_MESSAGE_BYTES = 2048;
	const MAX_MESSAGE_SOURCE_BYTES = 16384;
	const MAX_REQUEST_CONTEXT_BYTES = 1048576;

	/**
	 * Extract diagnostics from a parsed HTTP response payload and known headers.
	 *
	 * @param mixed $payload Parsed provider response body.
	 * @param array $headers Selected provider response headers.
	 * @return array
	 */
	public static function from_payload( $payload, $headers = array(), $request_context = null, $plain_body = null ) {
		$error   = self::error_node( $payload );
		$code    = self::normalize_code( self::raw_code( $error, $payload ) );
		$param   = self::normalize_param( self::raw_param( $error ) );
		$message = self::raw_message( $error, $payload );
		if ( ( ! is_string( $message ) || '' === trim( $message ) ) && is_string( $plain_body ) ) {
			$message = $plain_body;
		}
		$message_hash = self::message_hash( $message );
		$safe_message = self::safe_provider_message( $message, $request_context );

		return array(
			'provider_error_code'           => $code,
			'provider_error_reason'         => self::reason_for( $code, $param ),
			'provider_error_param'          => $param,
			'provider_request_id'           => self::request_id( $headers ),
			'provider_error_message_sha256' => $message_hash,
			'provider_error_message'        => $safe_message,
		);
	}

	/**
	 * Normalize already-safe diagnostic fields before persistence or projection.
	 *
	 * @param array $fields Candidate fields.
	 * @return array
	 */
	public static function normalize_fields( $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		$code   = self::normalize_code( $fields['provider_error_code'] ?? ( $fields['error_code'] ?? null ) );
		$param  = self::normalize_param( $fields['provider_error_param'] ?? null );
		$reason = self::normalize_reason( $fields['provider_error_reason'] ?? null );

		if ( null === $reason ) {
			$reason = self::reason_for( $code, $param );
		}

		return array(
			'provider_error_code'           => $code,
			'provider_error_reason'         => $reason,
			'provider_error_param'          => $param,
			'provider_request_id'           => self::normalize_request_id( $fields['provider_request_id'] ?? null ),
			'provider_error_message_sha256' => self::normalize_message_hash( $fields['provider_error_message_sha256'] ?? null ),
			'provider_error_message'        => self::safe_provider_message( $fields['provider_error_message'] ?? null ),
		);
	}

	/** Sanitize an untrusted provider message into bounded owner-visible text. */
	public static function safe_provider_message( $message, $request_context = null ) {
		if ( ! is_string( $message ) || '' === trim( $message ) ) {
			return null;
		}

		$request_texts = array();
		if ( null !== $request_context ) {
			$request_texts = self::request_echoes( $request_context );
			if ( false === $request_texts ) {
				return null;
			}
		}

		$message = substr( $message, 0, self::MAX_MESSAGE_SOURCE_BYTES );
		$message = self::valid_utf8( $message );
		if ( null === $message ) {
			return null;
		}
		$message = html_entity_decode( $message, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$message = preg_replace( '~<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>~is', ' ', $message );
		$message = strip_tags( $message );
		$message = preg_replace( '/\s+/u', ' ', $message );

		// Remove exact prompts and short overlapping fragments before any
		// other cleanup so HTML-escaped or quoted provider echoes are covered.
		$echo_fragments = array();
		foreach ( $request_texts as $request_text ) {
			$request_text = html_entity_decode( $request_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$request_text = self::valid_utf8( $request_text );
			if ( null === $request_text || '' === trim( $request_text ) ) {
				continue;
			}
			$request_text = trim( preg_replace( '/\s+/u', ' ', $request_text ) );
			if ( '' === $request_text ) {
				continue;
			}
			$echo_fragments[] = $request_text;
			$words = preg_split( '/\s+/u', $request_text, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $words ) && count( $words ) >= 2 ) {
				for ( $index = 0; $index < count( $words ) - 1; $index++ ) {
					$echo_fragments[] = $words[ $index ] . ' ' . $words[ $index + 1 ];
				}
			}
			$tokens = preg_split( '/[^\p{L}\p{N}_-]+/u', $request_text, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $tokens ) ) {
				foreach ( $tokens as $token ) {
					if ( strlen( $token ) >= 8 ) {
						$echo_fragments[] = $token;
					}
				}
			}
		}
		usort( $echo_fragments, static function ( $left, $right ) { return strlen( $right ) - strlen( $left ); } );
		foreach ( array_unique( $echo_fragments ) as $fragment ) {
			$message = str_ireplace( $fragment, '[request text redacted]', $message );
		}

		$message = preg_replace( '/\b(?:authorization|proxy-authorization)\s*[:=]\s*(?:(?:bearer|basic)\s+[^\s,;&<>]+|[^\s,;&<>]+)/i', '[credential redacted]', $message );
		$message = preg_replace( '/(?:api[_ -]?key|access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|subscription[_ -]?key|password|credential|secret|token|\bkey)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;&<>]+)/i', '[credential redacted]', $message );
		$message = preg_replace( '/\b(?:bearer|basic)\s+[A-Za-z0-9+\/_=-]{8,}/i', '[credential redacted]', $message );
		$message = preg_replace( '/\b(?:sk|rk|pk)[-_](?:proj[-_])?[A-Za-z0-9_-]{8,}\b/i', '[credential redacted]', $message );
		$message = preg_replace( '/\b(?:gh[pousr]_[A-Za-z0-9_]{12,}|github_pat_[A-Za-z0-9_]{12,}|AIza[A-Za-z0-9_-]{20,}|ya29\.[A-Za-z0-9._-]{12,}|(?:AKIA|ASIA)[A-Z0-9]{16})\b/i', '[credential redacted]', $message );
		$message = preg_replace( '/\b[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', '[token redacted]', $message );
		$message = preg_replace_callback(
			'~\b(?:https?|ftp)://[^\s<>"\']+~i',
			static function ( $match ) {
				return '[URL redacted]';
			},
			$message
		);
		$message = preg_replace( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email redacted]', $message );
		$message = preg_replace( '/(?<![A-Za-z0-9])[A-Za-z0-9_-]{48,}(?![A-Za-z0-9])/', '[token redacted]', $message );
		$message = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $message );
		$message = trim( preg_replace( '/\s+/u', ' ', $message ) );
		$message = self::valid_utf8( $message );
		if ( null === $message ) {
			return null;
		}
		if ( strlen( $message ) > self::MAX_SAFE_MESSAGE_BYTES ) {
			$message = substr( $message, 0, self::MAX_SAFE_MESSAGE_BYTES );
			while ( '' !== $message && 1 !== preg_match( '//u', $message ) ) {
				$message = substr( $message, 0, -1 );
			}
			$message = rtrim( $message );
		}
		return '' === $message ? null : $message;
	}

	/** Extract only request text fields needed to suppress exact provider echoes. */
	private static function request_echoes( $context ) {
		if ( is_string( $context ) ) {
			if ( strlen( $context ) > self::MAX_REQUEST_CONTEXT_BYTES ) {
				return false;
			}
			$context = json_decode( $context, true );
			if ( ! is_array( $context ) ) {
				return false;
			}
		}
		if ( ! is_array( $context ) ) {
			return false;
		}
		$texts = array();
		self::collect_request_texts( $context, $texts, false );
		if ( in_array( "\0unsafe-request-context", $texts, true ) ) {
			return false;
		}
		if ( count( $texts ) > 128 ) {
			return false;
		}
		if ( count( $texts ) > 1 ) {
			$combined = implode( ' ', $texts );
			if ( strlen( $combined ) <= 8192 ) {
				$texts[] = $combined;
			}
		}
		return array_values( array_unique( $texts ) );
	}

	private static function collect_request_texts( $value, array &$texts, $text_field, $depth = 0 ) {
		if ( $depth > 16 ) {
			$texts[] = "\0unsafe-request-context";
			return;
		}
		if ( is_string( $value ) ) {
			if ( $text_field ) {
				if ( strlen( $value ) > 8192 ) {
					$texts[] = "\0unsafe-request-context";
				} elseif ( '' !== trim( $value ) ) {
					$texts[] = $value;
				}
			}
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		$text_keys = array( 'prompt', 'input', 'instructions', 'context', 'text', 'negative_prompt', 'content' );
		$unsafe_keys = array( 'image', 'image_url', 'image_bytes', 'mask', 'file', 'file_id', 'data', 'b64_json', 'b64_image', 'url', 'uri', 'headers', 'authorization', 'api_key', 'api-key', 'token', 'secret', 'password', 'credential', 'key' );
		foreach ( $value as $key => $child ) {
			$key = is_string( $key ) ? strtolower( $key ) : '';
			$wanted = in_array( $key, $text_keys, true );
			if ( is_array( $child ) ) {
				$nested_text_field = $wanted || ( $text_field && ! in_array( $key, $unsafe_keys, true ) );
				self::collect_request_texts( $child, $texts, $nested_text_field, $depth + 1 );
			} elseif ( $wanted || ( $text_field && ! in_array( $key, $unsafe_keys, true ) ) ) {
				self::collect_request_texts( $child, $texts, true );
			}
		}
	}

	private static function valid_utf8( $value ) {
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value, true );
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return null;
		}
		return $value;
	}

	/** Return a canonical provider code only when it belongs to the fixed catalog. */
	public static function normalize_code( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 96 ) {
			return null;
		}

		$code = trim( preg_replace( '/[^a-z0-9]+/', '_', $value ), '_' );
		$compact_aliases = array(
			'badrequest'                   => 'bad_request',
			'deploymentnotfound'           => 'deployment_not_found',
			'responsibleaipolicyviolation' => 'responsible_ai_policy_violation',
			'invalidargument'              => 'invalid_parameter',
			'invalid_argument'             => 'invalid_parameter',
			'unsupportedparameter'         => 'unsupported_parameter',
			'unsupportedvalue'             => 'unsupported_value',
			'rate_limit'                   => 'rate_limit_exceeded',
			'toomanyrequests'              => 'rate_limit_exceeded',
			'resource_exhausted'           => 'rate_limit_exceeded',
			'quota_exceeded'               => 'insufficient_quota',
			'modelnotfound'                => 'model_not_found',
			'content_filter'               => 'content_policy_violation',
			'content_filtered'             => 'content_policy_violation',
			'invalid_request'              => 'invalid_request_error',
			'servererror'                  => 'server_error',
			'temporarilyunavailable'       => 'temporarily_unavailable',
		);
		if ( isset( $compact_aliases[ $code ] ) ) {
			$code = $compact_aliases[ $code ];
		}
		return in_array( $code, self::PROVIDER_CODES, true ) ? $code : null;
	}

	/** Return an allowlisted request field path, never a provider-supplied value. */
	public static function normalize_param( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::PARAMS, true ) ? $value : null;
	}

	/** Validate an opaque provider request ID and reject credential-shaped values. */
	public static function normalize_request_id( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 128 || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $value ) ) {
			return null;
		}
		if ( preg_match( '/(?:bearer\b|sk[-_]|rk[-_]|pk[-_]|gh[pousr]_|github_pat_|AIza|ya29\.|eyJ)/i', $value ) ) {
			return null;
		}
		if ( preg_match( '/[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', $value ) ) {
			return null;
		}
		if ( preg_match( '/[A-Za-z0-9_-]{48,}/', $value ) ) {
			return null;
		}
		return $value;
	}

	private static function normalize_message_hash( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : null;
	}

	private static function normalize_reason( $value ) {
		return is_string( $value ) && in_array( $value, self::REASONS, true ) ? $value : null;
	}

	private static function error_node( $payload ) {
		if ( is_string( $payload ) ) {
			return array( 'message' => $payload );
		}
		if ( ! is_array( $payload ) ) {
			return array();
		}
		if ( isset( $payload['error'] ) && is_array( $payload['error'] ) ) {
			return $payload['error'];
		}
		if ( isset( $payload['error'] ) && is_string( $payload['error'] ) ) {
			return array( 'message' => $payload['error'] );
		}
		return $payload;
	}

	private static function raw_code( array $error, $payload ) {
		foreach ( array( 'code', 'status', 'type' ) as $key ) {
			if ( isset( $error[ $key ] ) && is_string( $error[ $key ] ) ) {
				return $error[ $key ];
			}
		}
		if ( is_array( $payload ) && isset( $payload['code'] ) && is_string( $payload['code'] ) ) {
			return $payload['code'];
		}
		$details = $error['details'] ?? array();
		if ( is_array( $details ) ) {
			foreach ( $details as $detail ) {
				if ( is_array( $detail ) ) {
					foreach ( array( 'reason', 'code' ) as $key ) {
						if ( isset( $detail[ $key ] ) && is_string( $detail[ $key ] ) ) {
							return $detail[ $key ];
						}
					}
				}
			}
		}
		return null;
	}

	private static function raw_param( array $error ) {
		foreach ( array( 'param', 'parameter', 'field' ) as $key ) {
			if ( isset( $error[ $key ] ) && is_string( $error[ $key ] ) ) {
				return $error[ $key ];
			}
		}
		$details = $error['details'] ?? array();
		if ( is_array( $details ) ) {
			foreach ( $details as $detail ) {
				if ( ! is_array( $detail ) ) {
					continue;
				}
				foreach ( array( 'field', 'param', 'parameter' ) as $key ) {
					if ( isset( $detail[ $key ] ) && is_string( $detail[ $key ] ) ) {
						return $detail[ $key ];
					}
				}
				$violations = $detail['fieldViolations'] ?? array();
				if ( is_array( $violations ) && isset( $violations[0]['field'] ) && is_string( $violations[0]['field'] ) ) {
					return $violations[0]['field'];
				}
			}
		}
		return null;
	}

	private static function raw_message( array $error, $payload ) {
		foreach ( array( 'message', 'detail', 'error_description' ) as $key ) {
			if ( isset( $error[ $key ] ) && is_string( $error[ $key ] ) ) {
				return $error[ $key ];
			}
		}
		if ( is_array( $payload ) && isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
			return $payload['message'];
		}
		return null;
	}

	private static function message_hash( $message ) {
		if ( ! is_string( $message ) || '' === trim( $message ) || strlen( $message ) > 8192 ) {
			return null;
		}
		return hash( 'sha256', $message );
	}

	private static function request_id( $headers ) {
		if ( ! is_array( $headers ) ) {
			return null;
		}
		foreach ( array( 'x-request-id', 'apim-request-id', 'x-ms-request-id' ) as $wanted ) {
			foreach ( $headers as $name => $value ) {
				if ( strtolower( (string) $name ) === $wanted ) {
					$id = self::normalize_request_id( $value );
					if ( null !== $id ) {
						return $id;
					}
				}
			}
		}
		return null;
	}

	private static function reason_for( $code, $param ) {
		// Provider message text is untrusted and can echo prompt contents,
		// negation, or misleading quoted examples. Only structured codes and
		// allowlisted field paths determine a reason.
		if ( in_array( $code, array( 'responsible_ai_policy_violation', 'content_policy_violation' ), true ) ) {
			return 'content_policy_violation';
		}
		if ( in_array( $code, array( 'rate_limit_exceeded', 'insufficient_quota' ), true ) ) {
			return 'rate_limit';
		}
		if ( in_array( $code, array( 'deployment_not_found', 'model_not_found', 'model_unavailable' ), true ) ) {
			return 'model_unavailable';
		}
		if ( in_array( $code, array( 'unsupported_parameter', 'unsupported_value' ), true ) ) {
			return 'unsupported_parameter';
		}
		if ( in_array( $code, array( 'invalid_image_size', 'invalid_size' ), true ) ) {
			return 'invalid_size';
		}
		if ( 'invalid_quality' === $code ) {
			return 'invalid_quality';
		}
		if ( in_array( $code, array( 'invalid_parameter', 'invalid_request_error', 'bad_request' ), true ) ) {
			if ( 'size' === $param ) {
				return 'invalid_size';
			}
			if ( 'quality' === $param ) {
				return 'invalid_quality';
			}
			if ( in_array( $code, array( 'invalid_request_error', 'bad_request' ), true ) ) {
				return 'provider_rejected';
			}
			return 'invalid_parameter';
		}
		return 'unknown';
	}
}
