<?php
/**
 * Forminator.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Forminator form submissions.
 */
final class Forminator extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'forminator';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Forminator';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\Forminator_API' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'forminator_custom_form_submit_before_set_fields', array( $this, 'submitted' ), 20, 3 );
	}

	/**
	 * Forms.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		$forms = array();
		$list  = \Forminator_API::get_forms( null, 1, 200, 'publish' );
		foreach ( is_array( $list ) ? $list : array() as $form ) {
			$title                       = isset( $form->settings['formName'] ) ? (string) $form->settings['formName'] : (string) $form->name;
			$forms[ (string) $form->id ] = $title;
		}
		return $forms;
	}

	/**
	 * A form was submitted.
	 *
	 * @param object                           $entry  Entry model.
	 * @param int                              $form_id Form ID.
	 * @param array<int, array<string, mixed>> $data   Field data.
	 * @return void
	 */
	public function submitted( $entry, $form_id, $data ) {
		unset( $entry );
		$out = array();
		foreach ( (array) $data as $field ) {
			$name  = isset( $field['name'] ) ? (string) $field['name'] : '';
			$type  = isset( $field['field_type'] ) ? (string) $field['field_type'] : preg_replace( '/-\d+$/', '', $name );
			$label = isset( $field['field_array']['field_label'] ) ? (string) $field['field_array']['field_label'] : '';
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( 'name' === $type && is_array( $value ) ) {
				$value = array(
					'first' => isset( $value['first-name'] ) ? (string) $value['first-name'] : '',
					'last'  => isset( $value['last-name'] ) ? (string) $value['last-name'] : '',
				);
			}
			if ( 'consent' === $type || 'gdprcheckbox' === $type ) {
				$value = $value ? ( '' !== $label ? $label : 'consent' ) : '';
				$type  = 'consent';
			}
			$out[] = self::field( $name, $label, $type, $value );
		}
		$title = '';
		if ( class_exists( '\Forminator_API' ) ) {
			$form = \Forminator_API::get_form( $form_id );
			if ( $form && ! is_wp_error( $form ) ) {
				$title = isset( $form->settings['formName'] ) ? (string) $form->settings['formName'] : (string) $form->name;
			}
		}
		$this->handle( (string) $form_id, $title, $out );
	}
}
