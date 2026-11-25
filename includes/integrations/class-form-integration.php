<?php
/**
 * Base for form plugin integrations.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

use Emailit\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a form submission into an Emailit contact, an optional audience
 * subscription and a `c:form_submitted` event for automations.
 *
 * Each form is set up on Emailit > Integrations: connect it, pick an audience,
 * and choose whether to subscribe everyone or only people who tick a consent
 * checkbox. Subclasses hook into their plugin and pass the fields to handle().
 */
abstract class Form_Integration {

	/**
	 * Short ID, used in settings keys.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Plugin name.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Whether the form plugin is active.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Hook into submissions.
	 *
	 * @return void
	 */
	abstract public function register();

	/**
	 * The plugin's forms.
	 *
	 * @return array<string, string> Form ID => title.
	 */
	abstract public function forms();

	/**
	 * Settings for one form.
	 *
	 * @param string $form_id Form ID.
	 * @return array<string, mixed>
	 */
	public function form_settings( $form_id ) {
		$all = Integrations::settings( 'forms' );
		$key = $this->id() . ':' . $form_id;
		return wp_parse_args(
			isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array(),
			array(
				'enabled'  => false,
				'audience' => '',
				'consent'  => 'checkbox',
				'tags'     => '',
				'event'    => true,
			)
		);
	}

	/**
	 * Handle a submission.
	 *
	 * @param string                           $form_id    Form ID.
	 * @param string                           $form_title Form title.
	 * @param array<int, array<string, mixed>> $fields     Fields: key, label, type, value.
	 * @return void
	 */
	public function handle( $form_id, $form_title, array $fields ) {
		$form_id  = (string) $form_id;
		$settings = $this->form_settings( $form_id );
		$this->remember( $form_id, $form_title );
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$contact = self::map( $fields );
		if ( '' === $contact['email'] ) {
			return;
		}

		$subscribe = false;
		if ( '' !== (string) $settings['audience'] ) {
			$subscribe = 'always' === $settings['consent'] ? true : $contact['consent'];
		}

		$tags = array_filter( array_map( 'trim', explode( ',', (string) $settings['tags'] ) ) );
		Integrations::queue_contact(
			array(
				'email'       => $contact['email'],
				'first_name'  => $contact['first_name'],
				'last_name'   => $contact['last_name'],
				'tags'        => array_values( $tags ),
				'audience_id' => (string) $settings['audience'],
				'subscribe'   => $subscribe,
				'source'      => $this->id(),
			)
		);

		if ( ! empty( $settings['event'] ) ) {
			$properties = array(
				'form'       => $form_title,
				'form_id'    => $form_id,
				'plugin'     => $this->id(),
				'site'       => Settings::site_id(),
				'subscribed' => $subscribe,
			);
			foreach ( $fields as $field ) {
				if ( in_array( $field['type'], array( 'password', 'file', 'upload', 'signature', 'credit_card', 'stripe', 'paypal', 'captcha', 'hidden' ), true ) ) {
					continue;
				}
				$key = '' !== (string) $field['key'] ? (string) $field['key'] : (string) $field['label'];

				$properties[ 'field_' . $key ] = $field['value'];
			}
			Integrations::queue_event( 'form_submitted', $contact['email'], $properties );
		}
	}

	/**
	 * Remember forms we've seen submitted, for plugins that can't list their forms.
	 *
	 * @param string $form_id    Form ID.
	 * @param string $form_title Title.
	 * @return void
	 */
	protected function remember( $form_id, $form_title ) {
		$seen = get_option( 'emailit_seen_forms', array() );
		$seen = is_array( $seen ) ? $seen : array();
		$key  = $this->id() . ':' . $form_id;
		if ( ! isset( $seen[ $key ] ) || $seen[ $key ] !== $form_title ) {
			$seen[ $key ] = (string) $form_title;
			update_option( 'emailit_seen_forms', $seen, false );
		}
	}

	/**
	 * Forms seen in submissions.
	 *
	 * @return array<string, string>
	 */
	protected function seen_forms() {
		$seen = get_option( 'emailit_seen_forms', array() );
		$out  = array();
		foreach ( is_array( $seen ) ? $seen : array() as $key => $title ) {
			if ( 0 === strpos( $key, $this->id() . ':' ) ) {
				$out[ substr( $key, strlen( $this->id() ) + 1 ) ] = $title;
			}
		}
		return $out;
	}

	/**
	 * Find the email, names and consent in a submission.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields: key, label, type, value.
	 * @return array{email: string, first_name: string, last_name: string, consent: bool}
	 */
	public static function map( array $fields ) {
		$out       = array(
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
			'consent'    => false,
		);
		$full_name = '';

		foreach ( $fields as $field ) {
			$type  = strtolower( (string) $field['type'] );
			$value = $field['value'];
			$text  = is_array( $value ) ? implode( ' ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : trim( (string) $value );
			$name  = strtolower( $field['key'] . ' ' . $field['label'] );

			if ( '' === $out['email'] && ( 'email' === $type || is_email( $text ) ) && is_email( $text ) ) {
				$out['email'] = sanitize_email( $text );
				continue;
			}
			if ( is_array( $value ) && isset( $value['first'] ) ) {
				$out['first_name'] = $out['first_name'] ? $out['first_name'] : sanitize_text_field( (string) $value['first'] );
				$out['last_name']  = $out['last_name'] ? $out['last_name'] : sanitize_text_field( isset( $value['last'] ) ? (string) $value['last'] : '' );
				continue;
			}
			if ( preg_match( '/first.?name|given.?name|fname|vorname|prénom|prenom|nombre(?!.*apellido)|jméno|jmeno/u', $name ) ) {
				$out['first_name'] = $out['first_name'] ? $out['first_name'] : sanitize_text_field( $text );
			} elseif ( preg_match( '/last.?name|surname|family.?name|lname|nachname|apellido|příjmení|prijmeni|\bnom\b/u', $name ) ) {
				$out['last_name'] = $out['last_name'] ? $out['last_name'] : sanitize_text_field( $text );
			} elseif ( '' === $full_name && ( 'name' === $type || preg_match( '/(^|[\s_-])(your[\s_-]?)?name([\s_-]|$)|full.?name|^name/u', $name ) ) ) {
				$full_name = sanitize_text_field( $text );
			}

			// Consent to marketing: a ticked checkbox (or acceptance/consent field) that talks about
			// newsletters or marketing. Privacy-policy and terms boxes ("I consent to this site
			// storing my data") don't count, and radio answers are ignored.
			if ( ! $out['consent'] && in_array( $type, array( 'checkbox', 'acceptance', 'consent', 'gdpr-checkbox', 'gdprcheckbox', 'toggle' ), true ) ) {
				$said = $name . ' ' . strtolower( $text );
				if ( '' !== $text && ! in_array( strtolower( $text ), array( '0', 'no', 'false', 'off' ), true ) && preg_match( self::marketing_pattern(), $said ) && ! preg_match( '/privacy|terms|datenschutz|agb|confidentialit|privacidad|privacit|ochran[ay] osobn|podmínk/u', $said ) ) {
					$out['consent'] = true;
				}
			}
		}

		if ( '' !== $full_name && '' === $out['first_name'] && '' === $out['last_name'] ) {
			$parts             = preg_split( '/\s+/', $full_name, 2 );
			$out['first_name'] = $parts[0];
			$out['last_name']  = isset( $parts[1] ) ? $parts[1] : '';
		}
		return $out;
	}

	/**
	 * Words that mean "send me marketing email", in the site languages Emailit supports.
	 *
	 * @return string Regular expression.
	 */
	private static function marketing_pattern() {
		/**
		 * Filters the pattern that recognizes a newsletter or marketing consent checkbox by its name, label or value.
		 *
		 * @param string $pattern PCRE pattern.
		 */
		return (string) apply_filters( 'emailit_consent_pattern', '/newsletter|subscribe|subscription|marketing|opt.?in|mailing list|e-?mail updates|offers|promotion|novinky|odběr|werbung|abonn|infolettre|boletín|boletin|iscriviti|iscrizione|promoç|promoc/u' );
	}

	/**
	 * A field in the common shape.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $type  Type.
	 * @param mixed  $value Value.
	 * @return array<string, mixed>
	 */
	protected static function field( $key, $label, $type, $value ) {
		return array(
			'key'   => (string) $key,
			'label' => (string) $label,
			'type'  => (string) $type,
			'value' => $value,
		);
	}
}
