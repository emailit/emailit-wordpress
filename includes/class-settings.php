<?php
/**
 * Plugin settings.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and stores the settings.
 *
 * The API key can come from EMAILIT_API_KEY in wp-config.php, which keeps it
 * out of the database. A key saved from the settings screen is encrypted with
 * a key derived from the site's auth salts.
 */
final class Settings {

	const OPTION        = 'emailit_settings';
	const API_BASE      = 'https://api.emailit.com/v2/';
	const SMTP_HOST     = 'smtp.emailit.com';
	const SMTP_USERNAME = 'emailit';

	/**
	 * Cached settings for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cache = null;

	/**
	 * SMTP ports and their encryption.
	 *
	 * @return array<int, string>
	 */
	public static function smtp_ports() {
		return array(
			587  => 'tls',
			465  => 'ssl',
			2525 => 'tls',
			2587 => 'tls',
			25   => 'tls',
		);
	}

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'api_key'         => '',
			'mailer'          => 'api',
			'smtp_port'       => 587,
			'from_email'      => '',
			'from_name'       => '',
			'sender_mode'     => 'domain',
			'force_from_name' => false,
			'tracking'        => '',
			'rate_mode'       => 'auto',
			'rate_limit'      => 2,
			'retry'           => true,
			'log'             => true,
			'log_content'     => true,
			'log_days'        => 30,
		);
	}

	/**
	 * All settings merged with the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Forget cached settings (after an update).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Change settings in code.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 * @return void
	 */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( is_array( $saved ) ? $saved : array(), $values ) );
		self::flush();
	}

	/**
	 * Whether the API key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function api_key_from_constant() {
		return defined( 'EMAILIT_API_KEY' ) && '' !== (string) EMAILIT_API_KEY;
	}

	/**
	 * The API key.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::api_key_from_constant() ) {
			return trim( (string) EMAILIT_API_KEY );
		}
		return self::decrypt( (string) self::get( 'api_key' ) );
	}

	/**
	 * Whether a stored key can't be decrypted any more (the salts changed).
	 *
	 * @return bool
	 */
	public static function api_key_unreadable() {
		$stored = (string) self::get( 'api_key' );
		return ! self::api_key_from_constant() && '' !== $stored && '' === self::decrypt( $stored );
	}

	/**
	 * Whether the plugin can send.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key();
	}

	/**
	 * How email is sent: 'api' or 'smtp'.
	 *
	 * @return string
	 */
	public static function mailer() {
		if ( defined( 'EMAILIT_MAILER' ) && in_array( EMAILIT_MAILER, array( 'api', 'smtp' ), true ) ) {
			return EMAILIT_MAILER;
		}
		return 'smtp' === self::get( 'mailer' ) ? 'smtp' : 'api';
	}

	/**
	 * API base URL. EMAILIT_API_BASE overrides it for testing.
	 *
	 * @return string
	 */
	public static function api_base() {
		$base = defined( 'EMAILIT_API_BASE' ) ? (string) EMAILIT_API_BASE : self::API_BASE;
		return trailingslashit( $base );
	}

	/**
	 * SMTP host. EMAILIT_SMTP_HOST overrides it for testing.
	 *
	 * @return string
	 */
	public static function smtp_host() {
		return defined( 'EMAILIT_SMTP_HOST' ) ? (string) EMAILIT_SMTP_HOST : self::SMTP_HOST;
	}

	/**
	 * SMTP port.
	 *
	 * @return int
	 */
	public static function smtp_port() {
		if ( defined( 'EMAILIT_SMTP_PORT' ) ) {
			return (int) EMAILIT_SMTP_PORT;
		}
		$port = (int) self::get( 'smtp_port' );
		return array_key_exists( $port, self::smtp_ports() ) ? $port : 587;
	}

	/**
	 * SMTP encryption: tls, ssl, or '' (testing only).
	 *
	 * @return string
	 */
	public static function smtp_encryption() {
		if ( defined( 'EMAILIT_SMTP_ENCRYPTION' ) ) {
			return (string) EMAILIT_SMTP_ENCRYPTION;
		}
		$ports = self::smtp_ports();
		$port  = self::smtp_port();
		return isset( $ports[ $port ] ) ? $ports[ $port ] : 'tls';
	}

	/**
	 * This site's ID in Emailit metadata and webhook filters: its host name.
	 *
	 * @return string
	 */
	public static function site_id() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		return strtolower( $host . rtrim( $path, '/' ) );
	}

	/**
	 * Sanitize the settings form.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$clean   = $current;

		if ( isset( $input['api_key'] ) ) {
			$key = trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) );
			if ( ! empty( $input['api_key_clear'] ) ) {
				$clean['api_key'] = '';
			} elseif ( 0 === strpos( $key, 'enc:v1:' ) ) {
				// WordPress sanitizes a new option twice: the second pass gets the encrypted key.
				$clean['api_key'] = $key;
			} elseif ( '' !== $key && false === strpos( $key, '•' ) ) {
				$clean['api_key'] = self::encrypt( $key );
				delete_option( 'emailit_account' );
			}
		}

		$clean['mailer']    = isset( $input['mailer'] ) && 'smtp' === $input['mailer'] ? 'smtp' : 'api';
		$port               = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : 587;
		$clean['smtp_port'] = array_key_exists( $port, self::smtp_ports() ) ? $port : 587;

		$clean['from_email']      = isset( $input['from_email'] ) ? sanitize_email( wp_unslash( $input['from_email'] ) ) : '';
		$clean['from_name']       = isset( $input['from_name'] ) ? sanitize_text_field( wp_unslash( $input['from_name'] ) ) : '';
		$mode                     = isset( $input['sender_mode'] ) ? sanitize_key( $input['sender_mode'] ) : 'domain';
		$clean['sender_mode']     = in_array( $mode, array( 'domain', 'always', 'never' ), true ) ? $mode : 'domain';
		$clean['force_from_name'] = ! empty( $input['force_from_name'] );

		$tracking          = isset( $input['tracking'] ) ? sanitize_key( $input['tracking'] ) : '';
		$clean['tracking'] = in_array( $tracking, array( '', 'on', 'off', 'opens', 'clicks' ), true ) ? $tracking : '';

		$clean['rate_mode']  = isset( $input['rate_mode'] ) && 'custom' === $input['rate_mode'] ? 'custom' : 'auto';
		$clean['rate_limit'] = isset( $input['rate_limit'] ) ? max( 1, min( 1000, absint( $input['rate_limit'] ) ) ) : 2;

		$clean['retry']       = ! empty( $input['retry'] );
		$clean['log']         = ! empty( $input['log'] );
		$clean['log_content'] = ! empty( $input['log_content'] );
		$clean['log_days']    = isset( $input['log_days'] ) ? max( 1, min( 365, absint( $input['log_days'] ) ) ) : 30;

		Throttle::clear_pause();
		self::flush();
		return $clean;
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === $plain || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plain;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret() );
			return 'enc:v1:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
		} catch ( \Exception $e ) {
			return $plain;
		}
	}

	/**
	 * Decrypt a stored secret. Plain values pass through.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( 0 !== strpos( $stored, 'enc:v1:' ) ) {
			return $stored;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 7 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext stored as text.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::secret()
			);
		} catch ( \Exception $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}

	/**
	 * Encryption key derived from the site's auth key and salt.
	 *
	 * @return string
	 */
	private static function secret() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|emailit', true );
	}

	/**
	 * A key with everything but its prefix and last four characters hidden.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function mask( $key ) {
		if ( '' === $key ) {
			return '';
		}
		$prefix = '';
		if ( preg_match( '/^(secret_|whsec_)/', $key, $m ) ) {
			$prefix = $m[1];
		}
		return $prefix . str_repeat( '•', 12 ) . substr( $key, -4 );
	}
}
