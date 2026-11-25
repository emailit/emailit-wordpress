<?php
/**
 * Fluent Forms.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Fluent Forms submissions.
 */
final class Fluent_Forms extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'fluentforms';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Fluent Forms';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return defined( 'FLUENTFORM_VERSION' ) || defined( 'FLUENTFORM' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'fluentform/submission_inserted', array( $this, 'submitted' ), 20, 3 );
	}

	/**
	 * Forms.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'fluentform_forms' );
		$forms = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fluent Forms' own table; no public listing function.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", 'published', 100 ) ) as $row ) {
			$forms[ (string) $row->id ] = (string) $row->title;
		}
		return $forms;
	}

	/**
	 * A form was submitted.
	 *
	 * @param int                  $entry_id  Entry ID.
	 * @param array<string, mixed> $form_data Posted data.
	 * @param object               $form      Form.
	 * @return void
	 */
	public function submitted( $entry_id, $form_data, $form ) {
		unset( $entry_id );
		$out = array();
		foreach ( (array) $form_data as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_' ) || in_array( $key, array( '__fluent_form_embded_post_id', '_fluentform_' ), true ) ) {
				continue;
			}
			$type = '';
			if ( is_array( $value ) && ( isset( $value['first_name'] ) || isset( $value['last_name'] ) ) ) {
				$value = array(
					'first' => isset( $value['first_name'] ) ? (string) $value['first_name'] : '',
					'last'  => isset( $value['last_name'] ) ? (string) $value['last_name'] : '',
				);
				$type  = 'name';
			} elseif ( preg_match( '/terms|gdpr|consent|newsletter|subscribe/i', (string) $key ) ) {
				$type = 'checkbox';
				if ( $value && ! is_array( $value ) && in_array( strtolower( (string) $value ), array( 'on', 'yes', '1', 'true' ), true ) ) {
					$value = (string) $key;
				}
			}
			$out[] = self::field( (string) $key, '', $type, $value );
		}
		$this->handle( isset( $form->id ) ? (string) $form->id : '', isset( $form->title ) ? (string) $form->title : '', $out );
	}
}
