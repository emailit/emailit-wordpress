<?php
/**
 * Integrations registry.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

use Emailit\WordPress\Api;
use Emailit\WordPress\Jobs;
use Emailit\WordPress\Send_Error;
use Emailit\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the integrations for active plugins and runs their background jobs.
 */
final class Integrations {

	const OPTION = 'emailit_integrations';

	/**
	 * Form integrations by ID.
	 *
	 * @var array<string, Form_Integration>|null
	 */
	private static $forms = null;

	/**
	 * Boot: register job handlers, then hook the integrations once all plugins are loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		Jobs::register( 'contact', array( __CLASS__, 'job_contact' ) );
		Jobs::register( 'event', array( __CLASS__, 'job_event' ) );
		add_action( 'init', array( __CLASS__, 'init' ), 20 );
		WooCommerce::boot();
	}

	/**
	 * Hook the form and user integrations.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Settings::is_configured() ) {
			return;
		}
		foreach ( self::forms() as $integration ) {
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}
		Users::register();
	}

	/**
	 * All form integrations.
	 *
	 * @return array<string, Form_Integration>
	 */
	public static function forms() {
		if ( null === self::$forms ) {
			self::$forms = array();
			foreach ( array( new Contact_Form_7(), new WPForms(), new Gravity_Forms(), new Elementor_Forms(), new Ninja_Forms(), new Fluent_Forms(), new Formidable(), new Forminator() ) as $integration ) {
				self::$forms[ $integration->id() ] = $integration;
			}
		}
		return self::$forms;
	}

	/**
	 * Saved integration settings.
	 *
	 * @param string $section forms, woocommerce or users.
	 * @return array<string, mixed>
	 */
	public static function settings( $section ) {
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $section ] ) && is_array( $all[ $section ] ) ? $all[ $section ] : array();
	}

	/**
	 * Save one section.
	 *
	 * @param string               $section Section.
	 * @param array<string, mixed> $values  Values.
	 * @return void
	 */
	public static function save( $section, array $values ) {
		$all             = get_option( self::OPTION, array() );
		$all             = is_array( $all ) ? $all : array();
		$all[ $section ] = $values;
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Audiences in the workspace, cached for 10 minutes. Needs a Full Access key.
	 *
	 * @param bool $refresh Fetch again.
	 * @return array<string, string>|null ID => name, or null when they can't be read.
	 */
	public static function audiences( $refresh = false ) {
		$cached = get_transient( 'emailit_audiences' );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$items = Api::all( 'audiences', 500 );
		if ( null === $items ) {
			return null;
		}
		$audiences = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['id'] ) ) {
				$audiences[ (string) $item['id'] ] = isset( $item['name'] ) ? (string) $item['name'] : (string) $item['id'];
			}
		}
		set_transient( 'emailit_audiences', $audiences, 10 * MINUTE_IN_SECONDS );
		return $audiences;
	}

	/**
	 * Queue a contact update.
	 *
	 * @param array<string, mixed> $contact email, first_name, last_name, tags, custom_fields, audience_id, subscribe.
	 * @return void
	 */
	public static function queue_contact( array $contact ) {
		if ( empty( $contact['email'] ) || ! is_email( $contact['email'] ) ) {
			return;
		}
		/**
		 * Filters a contact before it's sent to Emailit.
		 *
		 * @param array<string, mixed> $contact email, first_name, last_name, tags, custom_fields, audience_id, subscribe, source.
		 */
		$contact = apply_filters( 'emailit_contact', $contact );
		if ( ! is_array( $contact ) || empty( $contact['email'] ) ) {
			return;
		}
		Jobs::add( 'contact', $contact );
	}

	/**
	 * Queue a custom event.
	 *
	 * @param string               $name       Event name without the c: prefix.
	 * @param string               $email      Contact email.
	 * @param array<string, mixed> $properties Properties.
	 * @return void
	 */
	public static function queue_event( $name, $email, array $properties ) {
		if ( ! is_email( $email ) ) {
			return;
		}
		Jobs::add(
			'event',
			array(
				'type'        => 'c:' . $name,
				'email'       => $email,
				'properties'  => self::clean_properties( $properties ),
				'occurred_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Property names may only use letters, digits, _ and -; values at most 1,000 characters.
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @return array<string, mixed>
	 */
	public static function clean_properties( array $properties ) {
		$out = array();
		foreach ( $properties as $key => $value ) {
			$key = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '_', (string) $key ), '_-' );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, 1000 ) : substr( (string) $value, 0, 1000 );
			}
		}
		return $out;
	}

	/**
	 * Job: create or update a contact, and subscribe it to an audience.
	 *
	 * @param array<string, mixed> $c Contact.
	 * @return Send_Error|null
	 */
	public static function job_contact( array $c ) {
		$email = strtolower( (string) $c['email'] );
		$names = array();
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( ! empty( $c[ $field ] ) ) {
				$names[ $field ] = (string) $c[ $field ];
			}
		}
		// The address changed in WordPress: move the existing contact to the new address.
		if ( ! empty( $c['previous_email'] ) && strtolower( (string) $c['previous_email'] ) !== $email ) {
			$moved = Api::post( 'contacts/' . rawurlencode( strtolower( (string) $c['previous_email'] ) ), array( 'email' => $email ) );
			if ( ! $moved->ok() && ! in_array( $moved->status, array( 404, 409 ), true ) ) {
				return Send_Error::from_api( $moved );
			}
		}
		$tags   = array_values( array_filter( array_map( 'sanitize_text_field', isset( $c['tags'] ) ? (array) $c['tags'] : array() ) ) );
		$fields = isset( $c['custom_fields'] ) && is_array( $c['custom_fields'] ) ? array_filter( $c['custom_fields'], 'is_scalar' ) : array();

		if ( ! empty( $c['audience_id'] ) && ! empty( $c['subscribe'] ) ) {
			$body = array_merge( array( 'email' => $email ), $names );
			if ( $fields ) {
				$body['custom_fields'] = $fields;
			}
			$response = Api::post( 'audiences/' . rawurlencode( (string) $c['audience_id'] ) . '/subscribers', $body );
			if ( ! $response->ok() && 409 !== $response->status ) {
				return Send_Error::from_api( $response );
			}
			if ( ! $tags ) {
				return null;
			}
			return Jobs::result( Api::post( 'contacts/' . rawurlencode( $email ), array( 'add_tags' => $tags ) ) );
		}

		$update = $names;
		if ( $tags ) {
			$update['add_tags'] = $tags;
		}
		if ( $fields ) {
			$update['custom_fields'] = $fields;
		}
		if ( $update ) {
			$response = Api::post( 'contacts/' . rawurlencode( $email ), $update );
			if ( $response->ok() ) {
				return null;
			}
			if ( 404 !== $response->status ) {
				return Send_Error::from_api( $response );
			}
		}
		$create = array_merge( array( 'email' => $email ), $names );
		if ( $tags ) {
			$create['tags'] = $tags;
		}
		if ( $fields ) {
			$create['custom_fields'] = $fields;
		}
		$response = Api::post( 'contacts', $create );
		return $response->ok() || 409 === $response->status ? null : Send_Error::from_api( $response );
	}

	/**
	 * Job: send a custom event.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return Send_Error|null
	 */
	public static function job_event( array $event ) {
		return Jobs::result( Api::post( 'events', $event ) );
	}
}
