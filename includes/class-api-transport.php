<?php
/**
 * Sends emails with POST /v2/emails.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a captured email into an Emailit API request.
 */
final class Api_Transport {

	/**
	 * Headers the API sets itself or that describe the MIME structure WordPress built.
	 *
	 * @var array<int, string>
	 */
	const SKIP_HEADERS = array( 'content-type', 'content-transfer-encoding', 'mime-version', 'x-mailer', 'date', 'message-id', 'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'return-path' );

	/**
	 * Send an email.
	 *
	 * @param Message $message The email.
	 * @param int     $log_id  Email log row, used for metadata and the idempotency key.
	 * @param int     $timeout Seconds to wait for the API.
	 * @return array{0: Delivery, 1: Api_Response|null}
	 */
	public static function send( Message $message, $log_id, $timeout = 30 ) {
		$recipients = $message->recipients();
		try {
			$payload = self::payload( $message, $log_id );
		} catch ( \RuntimeException $e ) {
			return array( Delivery::failure( Send_Error::make( $e->getMessage() ), $recipients ), null );
		}

		$response = Api::post(
			'emails',
			$payload,
			array(
				'timeout'         => (int) $timeout,
				'idempotency_key' => self::idempotency_key( $message, $log_id ),
			)
		);

		if ( $response->ok() ) {
			$delivery = Delivery::success( $recipients, isset( $response->body['id'] ) ? (string) $response->body['id'] : '' );
			if ( ! empty( $response->body['ids'] ) && is_array( $response->body['ids'] ) ) {
				$delivery->ids = array_map( 'strval', $response->body['ids'] );
			}
			return array( $delivery, $response );
		}
		return array( Delivery::failure( Send_Error::from_api( $response ), $recipients ), $response );
	}

	/**
	 * Stable per email, so a retried request is never delivered twice (Emailit keeps keys 24 hours).
	 * The random part keeps keys unique when log row IDs start over, e.g. after a reinstall.
	 *
	 * @param Message $message The email; the key is stored in it and travels with it into the queue.
	 * @param int     $log_id  Email log row.
	 * @return string
	 */
	public static function idempotency_key( Message $message, $log_id ) {
		if ( empty( $message->data['idempotency_key'] ) ) {
			$message->data['idempotency_key'] = 'wp-' . substr( md5( Settings::site_id() ), 0, 8 ) . '-' . (int) $log_id . '-' . strtolower( wp_generate_password( 12, false ) );
		}
		return (string) $message->data['idempotency_key'];
	}

	/**
	 * Build the request body.
	 *
	 * @param Message $message The email.
	 * @param int     $log_id  Email log row.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When an attachment can't be read.
	 */
	public static function payload( Message $message, $log_id ) {
		$charset = (string) $message->get( 'charset', 'UTF-8' );
		$text    = static function ( $value ) use ( $charset ) {
			$value = (string) $value;
			if ( '' !== $value && 'UTF-8' !== strtoupper( $charset ) && function_exists( 'mb_convert_encoding' ) ) {
				$converted = mb_convert_encoding( $value, 'UTF-8', $charset );
				if ( false !== $converted ) {
					$value = $converted;
				}
			}
			return $value;
		};

		$payload = array(
			'from'    => self::address( (string) $message->get( 'from' ), $text( $message->get( 'from_name' ) ) ),
			'to'      => self::addresses( (array) $message->get( 'to', array() ), $text ),
			'subject' => $text( $message->get( 'subject' ) ),
		);
		foreach ( array( 'cc', 'bcc', 'reply_to' ) as $field ) {
			$list = self::addresses( (array) $message->get( $field, array() ), $text );
			if ( $list ) {
				$payload[ $field ] = $list;
			}
		}
		if ( empty( $payload['to'] ) ) {
			// wp_mail() allows Cc/Bcc-only emails; the API needs a To. A Cc address is visible
			// anyway; Bcc recipients must stay hidden, so the sender becomes the To.
			if ( ! empty( $payload['cc'] ) ) {
				$payload['to'] = array( array_shift( $payload['cc'] ) );
				if ( empty( $payload['cc'] ) ) {
					unset( $payload['cc'] );
				}
			} else {
				$payload['to'] = array( $payload['from'] );
			}
		}

		if ( $message->is_html() ) {
			$payload['html'] = $text( $message->get( 'body' ) );
			if ( '' !== trim( (string) $message->get( 'alt_body' ) ) ) {
				$payload['text'] = $text( $message->get( 'alt_body' ) );
			}
		} else {
			$payload['text'] = $text( $message->get( 'body' ) );
		}

		$headers = array();
		foreach ( (array) $message->get( 'headers', array() ) as $header ) {
			$name = trim( (string) $header[0] );
			if ( '' === $name || in_array( strtolower( $name ), self::SKIP_HEADERS, true ) ) {
				continue;
			}
			$value            = trim( str_replace( array( "\r", "\n" ), ' ', (string) $header[1] ) );
			$headers[ $name ] = isset( $headers[ $name ] ) ? $headers[ $name ] . ', ' . $value : $value;
		}
		if ( $headers ) {
			$payload['headers'] = $headers;
		}

		$attachments = array();
		foreach ( (array) $message->get( 'attachments', array() ) as $a ) {
			// A logged email keeps only attachment names; it's sent again without them.
			if ( isset( $a['content'] ) || ! empty( $a['path'] ) ) {
				$attachments[] = self::attachment( $a );
			}
		}
		if ( $attachments ) {
			$payload['attachments'] = $attachments;
		}

		$meta = array();
		foreach ( (array) $message->get( 'meta', array() ) as $key => $value ) {
			$meta[ (string) $key ] = substr( (string) $value, 0, 500 );
		}
		$meta['wp_site']   = Settings::site_id();
		$meta['wp_log_id'] = (string) (int) $log_id;
		if ( '' !== (string) $message->get( 'source' ) ) {
			$meta['wp_source'] = (string) $message->get( 'source' );
		}
		$payload['meta'] = $meta;

		$tracking = (string) Settings::get( 'tracking' );
		if ( 'on' === $tracking ) {
			$payload['tracking'] = true;
		} elseif ( 'off' === $tracking ) {
			$payload['tracking'] = false;
		} elseif ( 'opens' === $tracking ) {
			$payload['tracking'] = array(
				'loads'  => true,
				'clicks' => false,
			);
		} elseif ( 'clicks' === $tracking ) {
			$payload['tracking'] = array(
				'loads'  => false,
				'clicks' => true,
			);
		}

		/**
		 * Filters the body of the API request for one email.
		 *
		 * @param array<string, mixed> $payload POST /v2/emails body.
		 * @param Message              $message The email.
		 */
		return (array) apply_filters( 'emailit_email_payload', $payload, $message );
	}

	/**
	 * One attachment.
	 *
	 * @param array<string, string> $a Captured attachment.
	 * @return array<string, string>
	 * @throws \RuntimeException When the file can't be read.
	 */
	private static function attachment( array $a ) {
		if ( isset( $a['content'] ) ) {
			$content = (string) $a['content'];
		} else {
			$path = isset( $a['path'] ) ? (string) $a['path'] : '';
			if ( '' === $path || ! is_readable( $path ) ) {
				/* translators: %s: file name. */
				throw new \RuntimeException( esc_html( sprintf( __( 'The attachment %s no longer exists.', 'emailit' ), wp_basename( $path ) ) ) );
			}
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file.
			if ( false === $raw ) {
				/* translators: %s: file name. */
				throw new \RuntimeException( esc_html( sprintf( __( 'The attachment %s could not be read.', 'emailit' ), wp_basename( $path ) ) ) );
			}
			$content = base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes attachments base64 encoded.
		}

		$type = '' !== $a['type'] ? (string) $a['type'] : 'application/octet-stream';
		$name = '' !== $a['name'] ? (string) $a['name'] : 'attachment';
		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) && function_exists( 'wp_get_default_extension_for_mime_type' ) ) {
			$ext = wp_get_default_extension_for_mime_type( $type );
			if ( $ext ) {
				$name .= '.' . $ext;
			}
		}

		$item = array(
			'filename'     => $name,
			'content'      => $content,
			'content_type' => $type,
		);
		if ( 'inline' === $a['disposition'] && '' !== $a['cid'] ) {
			$item['content_id'] = (string) $a['cid'];
		}
		return $item;
	}

	/**
	 * Format a list of addresses.
	 *
	 * @param array<int, array{0: string, 1: string}> $addresses Addresses.
	 * @param callable                                $text      Charset converter.
	 * @return array<int, string>
	 */
	private static function addresses( array $addresses, $text ) {
		$out = array();
		foreach ( $addresses as $a ) {
			$out[] = self::address( (string) $a[0], $text( isset( $a[1] ) ? $a[1] : '' ) );
		}
		return $out;
	}

	/**
	 * "Name <email>", quoting the name when it needs it.
	 *
	 * @param string $email Address.
	 * @param string $name  Display name.
	 * @return string
	 */
	public static function address( $email, $name ) {
		$name = trim( str_replace( array( "\r", "\n", '<', '>' ), ' ', (string) $name ) );
		if ( '' === $name ) {
			return $email;
		}
		if ( preg_match( '/[",;:@()\[\]\\\\.]/', $name ) ) {
			$name = '"' . addcslashes( $name, '"\\' ) . '"';
		}
		return $name . ' <' . $email . '>';
	}
}
