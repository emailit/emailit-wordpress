<?php
/**
 * From address rules.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties are CamelCase.

defined( 'ABSPATH' ) || exit;

/**
 * Makes sure emails go out from an address Emailit can send from.
 *
 * - The WordPress default (wordpress@yoursite) always becomes your From email.
 * - "domain" (default): From addresses on domains that aren't verified in
 *   Emailit (often a contact form visitor's address) are replaced, and the
 *   original goes to Reply-To. With a Full Access key the plugin knows all your
 *   verified domains; otherwise only your From email's domain counts.
 * - "always": every email uses your From email.
 * - "never": only the WordPress default is replaced.
 */
final class Sender {

	/**
	 * Apply the rules to an email that's about to be sent.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 * @return void
	 */
	public static function apply( $mailer ) {
		$from_email = (string) Settings::get( 'from_email' );
		$from_name  = (string) Settings::get( 'from_name' );
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return;
		}

		$current    = strtolower( (string) $mailer->From );
		$is_default = '' === $current || 0 === strpos( $current, 'wordpress@' );
		$mode       = (string) Settings::get( 'sender_mode' );
		$replace    = $is_default || 'always' === $mode || ( 'domain' === $mode && ! self::is_allowed_domain( $current, $from_email ) );

		if ( ! $replace || strtolower( $from_email ) === $current ) {
			if ( '' !== $from_name && ( Settings::get( 'force_from_name' ) || 'WordPress' === $mailer->FromName ) ) {
				$mailer->FromName = $from_name;
			}
			return;
		}

		if ( ! $is_default && empty( $mailer->getReplyToAddresses() ) ) {
			$mailer->addReplyTo( $mailer->From, $mailer->FromName );
		}

		$name = $mailer->FromName;
		if ( '' !== $from_name && ( $is_default || Settings::get( 'force_from_name' ) || 'WordPress' === $name || '' === $name ) ) {
			$name = $from_name;
		}
		$mailer->setFrom( $from_email, $name, false );
		$mailer->Sender = '';
	}

	/**
	 * Whether an address is on a domain Emailit can send from.
	 *
	 * @param string $address    Address.
	 * @param string $from_email Configured From email.
	 * @return bool
	 */
	public static function is_allowed_domain( $address, $from_email ) {
		$domain = strtolower( (string) substr( (string) strrchr( $address, '@' ), 1 ) );
		if ( '' === $domain ) {
			return false;
		}
		$allowed = array( strtolower( (string) substr( (string) strrchr( $from_email, '@' ), 1 ) ) );
		$account = get_option( 'emailit_account' );
		if ( is_array( $account ) && ! empty( $account['domains'] ) ) {
			$allowed = array_merge( $allowed, (array) $account['domains'] );
		}
		return in_array( $domain, $allowed, true );
	}
}
