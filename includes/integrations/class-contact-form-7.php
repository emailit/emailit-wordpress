<?php
/**
 * Contact Form 7.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Form 7 submissions.
 */
final class Contact_Form_7 extends Form_Integration {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'cf7';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Contact Form 7';
	}

	/**
	 * Whether it's active.
	 *
	 * @return bool
	 */
	public function is_available() {
		return defined( 'WPCF7_VERSION' );
	}

	/**
	 * Hook submissions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wpcf7_submit', array( $this, 'submitted' ), 10, 2 );
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
				'post_type'   => 'wpcf7_contact_form',
				'numberposts' => 100,
				'post_status' => 'publish',
			)
		) as $post ) {
			$forms[ (string) $post->ID ] = $post->post_title;
		}
		return $forms;
	}

	/**
	 * A submission was processed.
	 *
	 * @param \WPCF7_ContactForm   $form   Form.
	 * @param array<string, mixed> $result Result.
	 * @return void
	 */
	public function submitted( $form, $result ) {
		$status = isset( $result['status'] ) ? $result['status'] : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) || ! class_exists( '\WPCF7_Submission' ) ) {
			return;
		}
		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$posted = (array) $submission->get_posted_data();
		$fields = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( empty( $tag->name ) || ! array_key_exists( $tag->name, $posted ) ) {
				continue;
			}
			$label = isset( $tag->labels[0] ) ? (string) $tag->labels[0] : '';
			$value = $posted[ $tag->name ];
			if ( 'acceptance' === $tag->basetype ) {
				$value = $value ? ( '' !== $label ? $label : 'yes' ) : '';
			}
			$fields[] = self::field( $tag->name, $label, $tag->basetype, $value );
		}
		$this->handle( (string) $form->id(), $form->title(), $fields );
	}
}
