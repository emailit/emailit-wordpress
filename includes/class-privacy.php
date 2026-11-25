<?php
/**
 * Privacy tools.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Suggested privacy policy text, and the email log in WordPress's personal data export and erasure tools.
 */
final class Privacy {

	const PER_PAGE = 100;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggested text for the privacy policy.
	 *
	 * @return void
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . __( 'This site sends its emails, such as order confirmations, password resets and replies to forms, through Emailit, an email delivery service. Emailit receives each email\'s recipients, sender, subject, content and attachments in order to deliver it, and records whether it was delivered, opened or clicked.', 'emailit' ) . '</p>';
		$text .= '<p>' . __( 'If you fill in a form or place an order and the site connects them to Emailit, your email address, name and the details you entered may also be stored in Emailit as a contact, and you may be subscribed to a newsletter if you agreed to it.', 'emailit' ) . '</p>';
		$text .= '<p>' . sprintf(
			/* translators: %s: link to Emailit's privacy policy. */
			__( 'See Emailit\'s privacy policy: %s', 'emailit' ),
			'<a href="https://emailit.com/privacy-policy/">https://emailit.com/privacy-policy/</a>'
		) . '</p>';
		wp_add_privacy_policy_content( 'Emailit', wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( $exporters ) {
		$exporters['emailit'] = array(
			'exporter_friendly_name' => __( 'Emails sent through Emailit', 'emailit' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( $erasers ) {
		$erasers['emailit'] = array(
			'eraser_friendly_name' => __( 'Emails sent through Emailit', 'emailit' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Log rows sent to an address.
	 *
	 * @param string $email  Address.
	 * @param int    $offset Offset.
	 * @return array<int, object>
	 */
	private static function rows( $email, $offset ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$like  = '%' . $wpdb->esc_like( (string) $email ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, status, recipients, subject, emailit_id, created_at FROM {$table} WHERE recipients LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d", $like, self::PER_PAGE, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Export the email log entries for an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ) {
		$rows = self::rows( $email, ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'emailit-emails',
				'group_label' => __( 'Emails sent through Emailit', 'emailit' ),
				'item_id'     => 'emailit-email-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'emailit' ),
						'value' => $row->created_at . ' UTC',
					),
					array(
						'name'  => __( 'To', 'emailit' ),
						'value' => $row->recipients,
					),
					array(
						'name'  => __( 'Subject', 'emailit' ),
						'value' => $row->subject,
					),
					array(
						'name'  => __( 'Status', 'emailit' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'Emailit ID', 'emailit' ),
						'value' => $row->emailit_id,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Delete the email log entries for an address. Emails still in the queue are kept, so they're delivered.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$rows     = self::rows( $email, 0 );
		$delete   = array();
		$retained = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row->status, array( 'queued', 'sending' ), true ) ) {
				++$retained;
			} else {
				$delete[] = (int) $row->id;
			}
		}
		$removed = $delete ? Email_Log::delete( $delete ) : 0;
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Emails still waiting in the queue were kept so they can be delivered.', 'emailit' ) ) : array(),
			'done'           => count( $rows ) < self::PER_PAGE || 0 === $removed,
		);
	}
}
