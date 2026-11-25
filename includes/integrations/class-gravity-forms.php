<?php
/**
 * Gravity Forms.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms submissions.
 */
final class Gravity_Forms extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'gravityforms';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Gravity Forms';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\GFAPI' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'gform_after_submission', array( $this, 'submitted' ), 10, 2 );
	}

	/**
	 * Forms.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		$forms = array();
		foreach ( (array) \GFAPI::get_forms() as $form ) {
			$forms[ (string) $form['id'] ] = (string) $form['title'];
		}
		return $forms;
	}

	/**
	 * A form was submitted.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param array<string, mixed> $form  Form.
	 * @return void
	 */
	public function submitted( $entry, $form ) {
		if ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
			return;
		}
		$out = array();
		foreach ( (array) $form['fields'] as $field ) {
			$id    = (string) $field->id;
			$type  = (string) $field->type;
			$label = (string) $field->label;
			if ( 'name' === $type ) {
				$value = array(
					'first' => isset( $entry[ $id . '.3' ] ) ? (string) $entry[ $id . '.3' ] : '',
					'last'  => isset( $entry[ $id . '.6' ] ) ? (string) $entry[ $id . '.6' ] : '',
				);
			} elseif ( in_array( $type, array( 'checkbox', 'consent' ), true ) && is_array( $field->inputs ) ) {
				$value = array();
				foreach ( $field->inputs as $input ) {
					if ( ! empty( $entry[ (string) $input['id'] ] ) ) {
						$value[] = (string) $entry[ (string) $input['id'] ];
					}
				}
				if ( 'consent' === $type && $value ) {
					$value = array( $label );
				}
			} else {
				$value = isset( $entry[ $id ] ) ? $entry[ $id ] : '';
			}
			$out[] = self::field( isset( $field->inputName ) && '' !== (string) $field->inputName ? (string) $field->inputName : 'field_' . $id, $label, $type, $value ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms property.
		}
		$this->handle( (string) $form['id'], (string) $form['title'], $out );
	}
}
