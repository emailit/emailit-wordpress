<?php
/**
 * An email as WordPress built it.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties are CamelCase.

defined( 'ABSPATH' ) || exit;

/**
 * A finished email, captured from PHPMailer after wp_mail() and every
 * filter and phpmailer_init hook had their say.
 *
 * Plugins often add filters such as wp_mail_content_type only for the moment
 * they call wp_mail() (WooCommerce does), so a queued email keeps this finished
 * result, not wp_mail()'s arguments. Attachments are copied when the email is
 * queued, because plugins often delete the files right after wp_mail() returns.
 */
final class Message {

	const META_HEADER = 'x-emailit-meta-';

	/**
	 * Serializable data.
	 *
	 * @var array<string, mixed>
	 */
	public $data = array();

	/**
	 * Capture the email a PHPMailer instance is about to send.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 * @return self
	 */
	public static function capture( $mailer ) {
		$list = static function ( array $addresses ) {
			$out = array();
			foreach ( $addresses as $address ) {
				$out[] = array( (string) $address[0], isset( $address[1] ) ? (string) $address[1] : '' );
			}
			return $out;
		};

		$headers = array();
		$meta    = array();
		foreach ( $mailer->getCustomHeaders() as $header ) {
			$name  = (string) $header[0];
			$value = trim( (string) $header[1] );
			// X-Emailit-Meta-<Key>: value becomes metadata on the Emailit email.
			if ( 0 === stripos( $name, self::META_HEADER ) ) {
				$key = self::meta_key( substr( $name, strlen( self::META_HEADER ) ) );
				if ( '' !== $key ) {
					$meta[ $key ] = $value;
				}
				continue;
			}
			$headers[] = array( $name, $value );
		}

		$attachments = array();
		foreach ( $mailer->getAttachments() as $a ) {
			// PHPMailer: 0 path or content, 1 filename, 2 name, 3 encoding, 4 type, 5 is string, 6 disposition, 7 cid.
			$item = array(
				'name'        => (string) $a[2],
				'encoding'    => (string) $a[3],
				'type'        => (string) $a[4],
				'disposition' => (string) $a[6],
				'cid'         => (string) $a[7],
			);
			if ( ! empty( $a[5] ) ) {
				$item['content'] = base64_encode( (string) $a[0] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary attachment stored in JSON.
			} else {
				$item['path'] = (string) $a[0];
			}
			$attachments[] = $item;
		}

		$message       = new self();
		$message->data = array(
			'from'         => (string) $mailer->From,
			'from_name'    => (string) $mailer->FromName,
			'to'           => $list( $mailer->getToAddresses() ),
			'cc'           => $list( $mailer->getCcAddresses() ),
			'bcc'          => $list( $mailer->getBccAddresses() ),
			'reply_to'     => $list( array_values( $mailer->getReplyToAddresses() ) ),
			'subject'      => (string) $mailer->Subject,
			'body'         => (string) $mailer->Body,
			'alt_body'     => (string) $mailer->AltBody,
			'content_type' => (string) $mailer->ContentType,
			'charset'      => (string) $mailer->CharSet,
			'headers'      => $headers,
			'attachments'  => $attachments,
			'meta'         => $meta,
			'source'       => '',
		);
		return $message;
	}

	/**
	 * Normalize a metadata key: lowercase letters, digits and underscores.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function meta_key( $key ) {
		return trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $key ) ), '_' );
	}

	/**
	 * A field with a default.
	 *
	 * @param string $key     Field.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function get( $key, $fallback = '' ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $fallback;
	}

	/**
	 * Whether the body is HTML.
	 *
	 * @return bool
	 */
	public function is_html() {
		$type = strtolower( (string) $this->get( 'content_type', 'text/plain' ) );
		if ( 'text/html' === $type ) {
			return true;
		}
		if ( 0 === strpos( $type, 'multipart/' ) ) {
			return (bool) preg_match( '/<(html|body|p|div|table|br|a)\b/i', (string) $this->get( 'body' ) );
		}
		return false;
	}

	/**
	 * Copy attachment files to the plugin's private folder so they survive until the email is sent.
	 *
	 * @return void
	 */
	public function persist_files() {
		$dir = self::storage_dir();
		foreach ( (array) $this->get( 'attachments', array() ) as $i => $a ) {
			if ( empty( $a['path'] ) || ( '' !== $dir && 0 === strpos( $a['path'], $dir ) ) ) {
				continue;
			}
			$copy = '';
			if ( '' !== $dir && is_readable( $a['path'] ) ) {
				$target = trailingslashit( $dir ) . wp_generate_password( 20, false ) . '-' . sanitize_file_name( wp_basename( $a['path'] ) );
				$copy   = copy( $a['path'], $target ) ? $target : '';
			}
			if ( '' === $copy ) {
				unset( $this->data['attachments'][ $i ] );
				continue;
			}
			$this->data['attachments'][ $i ]['path'] = $copy;
		}
		$this->data['attachments'] = array_values( (array) $this->get( 'attachments', array() ) );
	}

	/**
	 * Delete attachment copies.
	 *
	 * @return void
	 */
	public function delete_files() {
		$dir = self::storage_dir();
		if ( '' === $dir ) {
			return;
		}
		foreach ( (array) $this->get( 'attachments', array() ) as $a ) {
			if ( ! empty( $a['path'] ) && 0 === strpos( $a['path'], $dir ) && file_exists( $a['path'] ) ) {
				wp_delete_file( $a['path'] );
			}
		}
	}

	/**
	 * A copy without attachment contents, for the log.
	 *
	 * @return self
	 */
	public function without_attachments() {
		$copy = new self();

		$copy->data = $this->data;
		foreach ( (array) $copy->get( 'attachments', array() ) as $i => $a ) {
			$copy->data['attachments'][ $i ] = array(
				'name' => isset( $a['name'] ) ? $a['name'] : '',
				'type' => isset( $a['type'] ) ? $a['type'] : '',
			);
		}
		return $copy;
	}

	/**
	 * The folder for attachment copies, with a deny-all .htaccess and an index file.
	 *
	 * @return string
	 */
	public static function storage_dir() {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			$dir = '';
			return $dir;
		}
		$path = trailingslashit( $uploads['basedir'] ) . 'emailit-queue';
		if ( ! is_dir( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				$dir = '';
				return $dir;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small static files written once.
			file_put_contents( $path . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$dir = $path;
		return $dir;
	}

	/**
	 * Load the email into a PHPMailer instance (for SMTP).
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 * @return void
	 * @throws \PHPMailer\PHPMailer\Exception When an address or attachment is invalid.
	 */
	public function apply_to( $mailer ) {
		$mailer->clearAllRecipients();
		$mailer->clearAttachments();
		$mailer->clearCustomHeaders();
		$mailer->clearReplyTos();

		$mailer->setFrom( (string) $this->get( 'from' ), (string) $this->get( 'from_name' ), false );
		$mailer->Sender = '';
		foreach ( (array) $this->get( 'to', array() ) as $a ) {
			$mailer->addAddress( $a[0], $a[1] );
		}
		foreach ( (array) $this->get( 'cc', array() ) as $a ) {
			$mailer->addCC( $a[0], $a[1] );
		}
		foreach ( (array) $this->get( 'bcc', array() ) as $a ) {
			$mailer->addBCC( $a[0], $a[1] );
		}
		foreach ( (array) $this->get( 'reply_to', array() ) as $a ) {
			$mailer->addReplyTo( $a[0], $a[1] );
		}
		$mailer->Subject     = (string) $this->get( 'subject' );
		$mailer->Body        = (string) $this->get( 'body' );
		$mailer->AltBody     = (string) $this->get( 'alt_body' );
		$mailer->ContentType = (string) $this->get( 'content_type', 'text/plain' );
		$mailer->CharSet     = (string) $this->get( 'charset', 'UTF-8' );
		foreach ( (array) $this->get( 'headers', array() ) as $h ) {
			$mailer->addCustomHeader( $h[0], $h[1] );
		}
		foreach ( (array) $this->get( 'attachments', array() ) as $a ) {
			$inline = 'inline' === $a['disposition'] && '' !== $a['cid'];
			if ( isset( $a['content'] ) ) {
				$content = (string) base64_decode( $a['content'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary attachment stored in JSON.
				if ( $inline ) {
					$mailer->addStringEmbeddedImage( $content, $a['cid'], $a['name'], $a['encoding'], $a['type'], $a['disposition'] );
				} else {
					$mailer->addStringAttachment( $content, $a['name'], $a['encoding'], $a['type'], $a['disposition'] );
				}
			} elseif ( ! empty( $a['path'] ) && is_readable( $a['path'] ) ) {
				if ( $inline ) {
					$mailer->addEmbeddedImage( $a['path'], $a['cid'], $a['name'], $a['encoding'], $a['type'], $a['disposition'] );
				} else {
					$mailer->addAttachment( $a['path'], $a['name'], $a['encoding'], $a['type'], $a['disposition'] );
				}
			}
		}
	}

	/**
	 * All recipients, lowercase.
	 *
	 * @return array<int, string>
	 */
	public function recipients() {
		$out = array();
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			foreach ( (array) $this->get( $field, array() ) as $a ) {
				$out[] = strtolower( $a[0] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Keep only some recipients.
	 *
	 * @param array<int, string> $keep Lowercase addresses.
	 * @return void
	 */
	public function only( array $keep ) {
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			$this->data[ $field ] = array_values(
				array_filter(
					(array) $this->get( $field, array() ),
					static function ( $a ) use ( $keep ) {
						return in_array( strtolower( $a[0] ), $keep, true );
					}
				)
			);
		}
	}

	/**
	 * Recipients for display.
	 *
	 * @return string
	 */
	public function to_display() {
		$list = array();
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			foreach ( (array) $this->get( $field, array() ) as $a ) {
				$list[] = $a[0];
			}
		}
		return implode( ', ', $list );
	}

	/**
	 * JSON for storage. Text in a legacy charset is stored base64 encoded.
	 *
	 * @return string
	 */
	public function to_json() {
		$data = $this->data;
		foreach ( array( 'subject', 'body', 'alt_body', 'from_name' ) as $field ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] && ! preg_match( '//u', $data[ $field ] ) ) {
				$data[ $field . '_b64' ] = base64_encode( $data[ $field ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Non-UTF-8 text stored in JSON.
				unset( $data[ $field ] );
			}
		}
		return (string) wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/**
	 * Restore from storage.
	 *
	 * @param string|null $json Stored JSON.
	 * @return self|null
	 */
	public static function from_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'subject', 'body', 'alt_body', 'from_name' ) as $field ) {
			if ( isset( $data[ $field . '_b64' ] ) ) {
				$data[ $field ] = (string) base64_decode( $data[ $field . '_b64' ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Non-UTF-8 text stored in JSON.
				unset( $data[ $field . '_b64' ] );
			}
		}
		$message       = new self();
		$message->data = $data;
		return $message;
	}
}
