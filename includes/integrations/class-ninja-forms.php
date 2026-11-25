<?php
/**
 * Ninja Forms.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Ninja Forms submissions.
 */
final class Ninja_Forms extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'ninjaforms';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Ninja Forms';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'Ninja_Forms' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ninja_forms_after_submission', array( $this, 'submitted' ) );
	}

	/**
	 * Forms.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		$forms = array();
		foreach ( (array) Ninja_Forms()->form()->get_forms() as $form ) {
			$forms[ (string) $form->get_id() ] = (string) $form->get_setting( 'title' );
		}
		return $forms;
	}

	/**
	 * A form was submitted.
	 *
	 * @param array<string, mixed> $data Submission.
	 * @return void
	 */
	public function submitted( $data ) {
		$out = array();
		foreach ( isset( $data['fields'] ) ? (array) $data['fields'] : array() as $field ) {
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$label = isset( $field['label'] ) ? (string) $field['label'] : '';
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( 'checkbox' === $type ) {
				$value = ( '1' === (string) $value || 'checked' === $value || true === $value ) ? $label : '';
			}
			$out[] = self::field( isset( $field['key'] ) ? (string) $field['key'] : '', $label, $type, $value );
		}
		$title = isset( $data['settings']['title'] ) ? (string) $data['settings']['title'] : '';
		$this->handle( isset( $data['form_id'] ) ? (string) $data['form_id'] : '', $title, $out );
	}
}
