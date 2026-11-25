<?php
/**
 * Formidable Forms.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Formidable Forms submissions.
 */
final class Formidable extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'formidable';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Formidable Forms';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\FrmForm' ) && class_exists( '\FrmField' ) && class_exists( '\FrmEntryMeta' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'frm_after_create_entry', array( $this, 'submitted' ), 30, 2 );
	}

	/**
	 * Forms.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		$forms = array();
		foreach ( (array) \FrmForm::getAll(
			array(
				'is_template' => 0,
				'status'      => 'published',
			)
		) as $form ) {
			if ( empty( $form->parent_form_id ) ) {
				$forms[ (string) $form->id ] = (string) $form->name;
			}
		}
		return $forms;
	}

	/**
	 * An entry was created.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 * @return void
	 */
	public function submitted( $entry_id, $form_id ) {
		$form = \FrmForm::getOne( $form_id );
		if ( ! $form || ! empty( $form->parent_form_id ) ) {
			return;
		}
		$out = array();
		foreach ( (array) \FrmField::get_all_for_form( $form_id ) as $field ) {
			$value = \FrmEntryMeta::get_entry_meta_by_field( $entry_id, $field->id );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$type = (string) $field->type;
			if ( 'name' === $type && is_array( $value ) ) {
				$value = array(
					'first' => isset( $value['first'] ) ? (string) $value['first'] : '',
					'last'  => isset( $value['last'] ) ? (string) $value['last'] : '',
				);
			}
			$out[] = self::field( (string) $field->field_key, (string) $field->name, $type, $value );
		}
		$this->handle( (string) $form_id, (string) $form->name, $out );
	}
}
