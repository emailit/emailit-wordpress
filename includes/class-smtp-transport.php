<?php
/**
 * Sends emails through smtp.emailit.com.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties are CamelCase.

defined( 'ABSPATH' ) || exit;

/**
 * SMTP delivery with a PHPMailer instance of its own.
 */
final class Smtp_Transport {

	/**
	 * Reused client.
	 *
	 * @var Smtp_Client|null
	 */
	private static $client = null;

	/**
	 * The client, configured for Emailit.
	 *
	 * @param bool $keep_alive Keep the connection open between emails (queue runs).
	 * @return Smtp_Client
	 */
	public static function client( $keep_alive = false ) {
		if ( null === self::$client ) {
			Plugin::load_phpmailer();
			self::$client = new Smtp_Client();
		}
		$client = self::$client;
		$client->isSMTP();
		$client->Host          = Settings::smtp_host();
		$client->Port          = Settings::smtp_port();
		$client->SMTPSecure    = Settings::smtp_encryption();
		$client->SMTPAutoTLS   = '' !== $client->SMTPSecure;
		$client->SMTPAuth      = true;
		$client->Username      = Settings::SMTP_USERNAME;
		$client->Password      = Settings::api_key();
		$client->SMTPKeepAlive = $keep_alive;
		/**
		 * Filters the SMTP connection timeout in seconds.
		 *
		 * @param int $timeout Seconds. Default 15.
		 */
		$client->Timeout = (int) apply_filters( 'emailit_smtp_timeout', 15 );
		return $client;
	}

	/**
	 * Send an email.
	 *
	 * @param Message $message    The email.
	 * @param bool    $keep_alive Keep the connection open.
	 * @return Delivery
	 */
	public static function send( Message $message, $keep_alive = false ) {
		$client = self::client( $keep_alive );
		try {
			// Metadata (X-Emailit-Meta-*) was taken out when the email was captured: the relay ignores it.
			$message->apply_to( $client );
		} catch ( \Exception $e ) {
			return Delivery::failure( Send_Error::make( $e->getMessage() ), $message->recipients() );
		}
		return $client->deliver();
	}

	/**
	 * Close the connection after a queue run.
	 *
	 * @return void
	 */
	public static function close() {
		if ( self::$client ) {
			self::$client->smtpClose();
		}
	}
}
