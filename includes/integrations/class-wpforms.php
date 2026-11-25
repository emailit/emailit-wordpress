<?php
/**
 * WPForms.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * WPForms (Lite and Pro) submissions.
 */
final class WPForms extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpforms';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'WPForms';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return defined( 'WPFORMS_VERSION' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wpforms_process_complete', array( $this, 'submitted' ), 10, 4 );
	}

	/**
	 * Forms.
	 *
	 * @return array<string, string>
	 */
	public function forms() {
		$forms = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wpforms',
				'numberposts' => 100,
				'post_status' => 'publish',
			)
		) as $post ) {
			$forms[ (string) $post->ID ] = $post->post_title;
		}
		return $forms;
	}

	/**
	 * A form was submitted.
	 *
	 * @param array<int, array<string, mixed>> $fields    Processed fields.
	 * @param array<string, mixed>             $entry     Raw entry.
	 * @param array<string, mixed>             $form_data Form settings.
	 * @param int                              $entry_id  Entry ID.
	 * @return void
	 */
	public function submitted( $fields, $entry, $form_data, $entry_id ) {
		unset( $entry, $entry_id );
		$out = array();
		foreach ( (array) $fields as $id => $field ) {
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( 'name' === $type && ( ! empty( $field['first'] ) || ! empty( $field['last'] ) ) ) {
				$value = array(
					'first' => isset( $field['first'] ) ? $field['first'] : '',
					'last'  => isset( $field['last'] ) ? $field['last'] : '',
				);
			}
			$out[] = self::field( (string) $id, isset( $field['name'] ) ? (string) $field['name'] : '', $type, $value );
		}
		$title = isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '';
		$this->handle( isset( $form_data['id'] ) ? (string) $form_data['id'] : '', $title, $out );
	}
}
