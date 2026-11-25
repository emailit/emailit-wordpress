<?php
/**
 * Elementor Pro forms.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro form widget submissions. Elementor can't list its forms, so
 * each form appears on the Integrations screen after its first submission.
 */
final class Elementor_Forms extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'elementor';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Elementor Pro';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'submitted' ), 10, 2 );
	}

	/**
	 * Forms seen so far.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		return $this->seen_forms();
	}

	/**
	 * A form was submitted.
	 *
	 * @param object $record  Form record.
	 * @param object $handler Ajax handler.
	 * @return void
	 */
	public function submitted( $record, $handler ) {
		unset( $handler );
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$out = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$out[] = self::field(
				(string) $id,
				isset( $field['title'] ) ? (string) $field['title'] : '',
				isset( $field['type'] ) ? (string) $field['type'] : '',
				isset( $field['value'] ) ? $field['value'] : ''
			);
		}
		$form_id = (string) $record->get_form_settings( 'id' );
		$name    = (string) $record->get_form_settings( 'form_name' );
		$this->handle( '' !== $form_id ? $form_id : sanitize_key( $name ), '' !== $name ? $name : $form_id, $out );
	}
}
