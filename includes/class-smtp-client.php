<?php
/**
 * PHPMailer for the Emailit relay.
 *
 * Loaded only after PHPMailer (Plugin::load_phpmailer()).
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- These classes extend PHPMailer and can only load after it.

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties are CamelCase.

defined( 'ABSPATH' ) || exit;

/**
 * SMTP connection that reads the email IDs in `250 2.0.0 OK: queued as em_...`.
 */
class Smtp_Connection extends \PHPMailer\PHPMailer\SMTP {

	/**
	 * Add Emailit's reply format to the transaction ID patterns.
	 */
	public function __construct() {
		$this->smtp_transaction_id_patterns = array( 'emailit' => '/[\d]{3} 2\.0\.0 OK: queued as (em_[A-Za-z0-9]+)/i' ) + $this->smtp_transaction_id_patterns;
	}
}

/**
 * PHPMailer that reports per-recipient results.
 */
class Smtp_Client extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Create with exceptions on.
	 */
	public function __construct() {
		parent::__construct( true );
		$this->setSMTPInstance( new Smtp_Connection() );
		$this->XMailer = ' ';
	}

	/**
	 * Send now and report which recipients were accepted.
	 *
	 * @return Delivery
	 */
	public function deliver() {
		$outcomes   = array();
		$emailit_id = '';
		$previous   = $this->action_function;

		$this->action_function = static function ( $is_sent, $to, $cc, $bcc, $subject, $body, $from, $extra ) use ( &$outcomes, &$emailit_id ) {
			foreach ( array_merge( (array) $to, (array) $cc, (array) $bcc ) as $address ) {
				$outcomes[ strtolower( $address[0] ) ] = (bool) $is_sent;
			}
			if ( '' === $emailit_id && ! empty( $extra['smtp_transaction_id'] ) && is_string( $extra['smtp_transaction_id'] ) ) {
				$emailit_id = $extra['smtp_transaction_id'];
			}
		};

		$recipients = array_keys( $this->getAllRecipientAddresses() );
		try {
			parent::send();
			$delivery = Delivery::success( $recipients, $emailit_id );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$last = $this->getSMTPInstance()->getError();
			$code = isset( $last['smtp_code'] ) ? (int) $last['smtp_code'] : 0;
			$text = $e->getMessage();
			if ( $code && ! empty( $last['detail'] ) ) {
				$text = trim( $code . ' ' . ( isset( $last['smtp_code_ex'] ) ? $last['smtp_code_ex'] : '' ) . ' ' . preg_replace( '/\s+/', ' ', (string) $last['detail'] ) );
			}
			$sent     = array_keys( array_filter( $outcomes ) );
			$delivery = Delivery::failure( Send_Error::from_smtp( $text, $code ), array_values( array_diff( $recipients, $sent ) ), $sent );

			$delivery->emailit_id = $emailit_id;
			$this->smtpClose();
		}
		$this->action_function = $previous;
		return $delivery;
	}
}
