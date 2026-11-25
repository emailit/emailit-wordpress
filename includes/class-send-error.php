<?php
/**
 * Why a send failed.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * What went wrong with a send, and whether trying again can help.
 */
final class Send_Error {

	const RATE       = 'rate';
	const DAILY      = 'daily';
	const AUTH       = 'auth';
	const CREDITS    = 'credits';
	const CONNECTION = 'connection';
	const TEMPORARY  = 'temporary';
	const PERMANENT  = 'permanent';

	/**
	 * One of the constants above.
	 *
	 * @var string
	 */
	public $type = self::PERMANENT;

	/**
	 * HTTP status or SMTP reply code, 0 if none.
	 *
	 * @var int
	 */
	public $code = 0;

	/**
	 * Message.
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Seconds the server asked to wait, if it said.
	 *
	 * @var int
	 */
	public $retry_after = 0;

	/**
	 * The limit from a rate limit reply.
	 *
	 * @var int|null
	 */
	public $limit = null;

	/**
	 * Emails left today, from a daily limit reply.
	 *
	 * @var int|null
	 */
	public $remaining = null;

	/**
	 * Classify an API response.
	 *
	 * @param Api_Response $response Response.
	 * @return self
	 */
	public static function from_api( Api_Response $response ) {
		$error          = new self();
		$error->code    = $response->status;
		$error->message = $response->message();
		$retry          = (int) $response->header( 'retry-after' );
		if ( ! $retry && isset( $response->body['retry_after'] ) ) {
			$retry = (int) $response->body['retry_after'];
		}
		$error->retry_after = max( 0, $retry );
		$text               = isset( $response->body['error'] ) && is_string( $response->body['error'] ) ? $response->body['error'] : '';

		if ( $response->error ) {
			$error->type = self::CONNECTION;
		} elseif ( 429 === $response->status ) {
			$is_daily     = false !== stripos( $text, 'daily' ) || $error->retry_after > 60;
			$error->type  = $is_daily ? self::DAILY : self::RATE;
			$error->limit = isset( $response->body['limit'] ) ? (int) $response->body['limit'] : null;
			if ( $is_daily && null !== $error->limit && isset( $response->body['current'] ) ) {
				$error->remaining = max( 0, $error->limit - (int) $response->body['current'] );
			}
			if ( ! $error->retry_after ) {
				$error->retry_after = $is_daily ? Throttle::seconds_until_utc_midnight() : 1;
			}
		} elseif ( 401 === $response->status ) {
			$error->type = self::AUTH;
		} elseif ( 403 === $response->status && false !== stripos( $error->message, 'Permission denied' ) ) {
			// The key's scope doesn't allow sending, e.g. an Events Only key.
			$error->type = self::AUTH;
		} elseif ( 402 === $response->status ) {
			$error->type = self::CREDITS;
		} elseif ( in_array( $response->status, array( 408, 409, 425, 500, 502, 503, 504 ), true ) || $response->status >= 520 ) {
			$error->type = self::TEMPORARY;
		} else {
			$error->type = self::PERMANENT;
		}
		return $error;
	}

	/**
	 * Classify an SMTP failure.
	 *
	 * @param string $text Error text.
	 * @param int    $code SMTP reply code, 0 if unknown.
	 * @return self
	 */
	public static function from_smtp( $text, $code = 0 ) {
		$error          = new self();
		$error->message = $text;
		$error->code    = (int) $code;

		if ( preg_match( '/per second limit|4\.4\.5/i', $text ) ) {
			$error->type        = self::RATE;
			$error->limit       = self::parse_limit( $text );
			$error->retry_after = 1;
		} elseif ( preg_match( '/daily message limit|4\.5\.3/i', $text ) ) {
			$error->type        = self::DAILY;
			$error->limit       = self::parse_limit( $text );
			$error->retry_after = Throttle::seconds_until_utc_midnight();
		} elseif ( preg_match( '/insufficient credits/i', $text ) ) {
			$error->type = self::CREDITS;
		} elseif ( 535 === $error->code || preg_match( '/could not authenticate|authentication failed/i', $text ) ) {
			$error->type = 454 === $error->code ? self::TEMPORARY : self::AUTH;
		} elseif ( preg_match( '/could not connect|failed to connect|connection (?:refused|timed out|reset)|timed out|network is unreachable|getaddrinfo|php_network_getaddresses|stream_socket_client|unable to connect/i', $text ) ) {
			$error->type = self::CONNECTION;
		} elseif ( $error->code >= 400 && $error->code < 500 ) {
			$error->type = self::TEMPORARY;
		} elseif ( $error->code < 500 && preg_match( '/temporary|try again/i', $text ) ) {
			$error->type = self::TEMPORARY;
		} else {
			$error->type = self::PERMANENT;
		}
		return $error;
	}

	/**
	 * A generic error, e.g. a message that couldn't be built.
	 *
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @return self
	 */
	public static function make( $message, $type = self::PERMANENT ) {
		$error          = new self();
		$error->message = $message;
		$error->type    = $type;
		return $error;
	}

	/**
	 * The limit in `(current/limit)`.
	 *
	 * @param string $text Reply text.
	 * @return int|null
	 */
	private static function parse_limit( $text ) {
		if ( preg_match( '/\((\d+)\s*\/\s*(\d+)\)/', $text, $m ) && (int) $m[2] > 0 ) {
			return (int) $m[2];
		}
		return null;
	}

	/**
	 * Whether sending again later may work.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return self::PERMANENT !== $this->type;
	}

	/**
	 * Whether a retry counts as an attempt. Waiting for a limit doesn't.
	 *
	 * @return bool
	 */
	public function counts_as_attempt() {
		return ! in_array( $this->type, array( self::RATE, self::DAILY ), true );
	}
}
