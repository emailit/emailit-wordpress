<?php
/**
 * Emailit API response.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One response from the API.
 */
final class Api_Response {

	/**
	 * HTTP status, 0 when the request failed before a response.
	 *
	 * @var int
	 */
	public $status = 0;

	/**
	 * Decoded JSON body.
	 *
	 * @var array<string, mixed>
	 */
	public $body = array();

	/**
	 * Lowercase response headers.
	 *
	 * @var array<string, string>
	 */
	public $headers = array();

	/**
	 * Network error, if the request failed.
	 *
	 * @var \WP_Error|null
	 */
	public $error = null;

	/**
	 * Whether the status is 2xx.
	 *
	 * @return bool
	 */
	public function ok() {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * A header value.
	 *
	 * @param string $name Lowercase name.
	 * @return string
	 */
	public function header( $name ) {
		return isset( $this->headers[ $name ] ) ? (string) $this->headers[ $name ] : '';
	}

	/**
	 * Readable error message.
	 *
	 * @return string
	 */
	public function message() {
		if ( $this->error ) {
			return $this->error->get_error_message();
		}
		$parts = array();
		foreach ( array( 'error', 'message', 'details' ) as $key ) {
			if ( ! empty( $this->body[ $key ] ) && is_string( $this->body[ $key ] ) && ! in_array( $this->body[ $key ], $parts, true ) ) {
				$parts[] = $this->body[ $key ];
			}
		}
		if ( ! empty( $this->body['validation_errors'] ) && is_array( $this->body['validation_errors'] ) ) {
			$parts[] = implode( '; ', array_map( 'strval', $this->body['validation_errors'] ) );
		}
		if ( ! empty( $this->body['errors'] ) && is_array( $this->body['errors'] ) ) {
			foreach ( $this->body['errors'] as $field => $messages ) {
				$parts[] = $field . ': ' . implode( ', ', array_map( 'strval', (array) $messages ) );
			}
		}
		$text = implode( ': ', $parts );
		return $this->status . ( '' !== $text ? ' ' . $text : '' );
	}
}
