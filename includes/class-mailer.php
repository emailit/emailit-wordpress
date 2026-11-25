<?php
/**
 * Takes over WordPress's PHPMailer.
 *
 * Loaded only after PHPMailer (Plugin::load_phpmailer()).
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties are CamelCase.

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the global $phpmailer.
 *
 * WordPress's wp_mail() reuses the global $phpmailer when it's a PHPMailer, so it builds
 * every email into this object as usual (headers, filters, phpmailer_init)
 * and then calls send(). Instead of talking SMTP, send() captures the finished
 * email and hands it to the Dispatcher, which sends it through the Emailit API
 * (or SMTP), throttles and queues.
 */
class Mailer extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Create with exceptions on, like wp_mail() does.
	 */
	public function __construct() {
		parent::__construct( true );
		static::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};
	}

	/**
	 * Send the email wp_mail() built.
	 *
	 * @return bool True when Emailit accepted the email or it was queued.
	 * @throws \PHPMailer\PHPMailer\Exception When it can't be sent and won't be retried; wp_mail() turns this into wp_mail_failed.
	 */
	public function send() {
		if ( ! Settings::is_configured() ) {
			return parent::send();
		}

		Sender::apply( $this );
		$this->emailit_dequeue_idn();
		$message                 = Message::capture( $this );
		$message->data['source'] = Source::detect();

		if ( empty( $message->recipients() ) ) {
			throw new \PHPMailer\PHPMailer\Exception( esc_html__( 'The email has no recipients.', 'emailit' ) );
		}

		$error = Dispatcher::send_now( $message );
		if ( null === $error ) {
			return true;
		}
		throw new \PHPMailer\PHPMailer\Exception( esc_html( $error->message ) );
	}

	/**
	 * PHPMailer holds addresses on internationalized domains (info@bücher.de) back until it
	 * builds the message. Add them now, with punycode domains, so the captured email has them.
	 *
	 * @return void
	 */
	private function emailit_dequeue_idn() {
		foreach ( array_merge( $this->RecipientsQueue, $this->ReplyToQueue ) as $params ) {
			$params[1] = $this->punyencodeAddress( $params[1] );
			call_user_func_array( array( $this, 'addAnAddress' ), $params );
		}
		$this->RecipientsQueue = array();
		$this->ReplyToQueue    = array();
	}
}
