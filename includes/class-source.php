<?php
/**
 * Which plugin sent an email.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the plugin, theme or WordPress feature that called wp_mail(), so the
 * email log and Emailit's metadata (wp_source) can tell emails apart.
 */
final class Source {

	/**
	 * WordPress core functions that send email, and their names.
	 *
	 * @return array<string, string>
	 */
	private static function core_functions() {
		return array(
			'retrieve_password'                          => 'wordpress:password-reset',
			'wp_new_user_notification'                   => 'wordpress:new-user',
			'wp_password_change_notification'            => 'wordpress:password-changed',
			'wp_send_new_user_notifications'             => 'wordpress:new-user',
			'wp_notify_postauthor'                       => 'wordpress:comment',
			'wp_notify_moderator'                        => 'wordpress:comment-moderation',
			'wp_send_user_request'                       => 'wordpress:privacy-request',
			'_wp_privacy_send_request_confirmation_notification' => 'wordpress:privacy-request',
			'wp_privacy_send_personal_data_export_email' => 'wordpress:privacy-export',
			'wp_send_recovery_mode_email'                => 'wordpress:recovery-mode',
			'send_confirmation_on_profile_email'         => 'wordpress:email-change',
			'wp_update_user'                             => 'wordpress:account-change',
			'update_option_new_admin_email'              => 'wordpress:admin-email-change',
			'wp_site_admin_email_change_notification'    => 'wordpress:admin-email-change',
			'wp_send_auto_update_email'                  => 'wordpress:auto-update',
			'send_core_update_notification_email'        => 'wordpress:core-update',
			'send_email'                                 => 'wordpress:auto-update',
			'wpmu_signup_user_notification'              => 'wordpress:signup',
			'wpmu_welcome_user_notification'             => 'wordpress:signup',
		);
	}

	/**
	 * Detect the sender from the call stack.
	 *
	 * @return string e.g. "woocommerce", "contact-form-7", "theme:astra", "WordPress:password-reset".
	 */
	public static function detect() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Finds which plugin called wp_mail() for the email log; arguments aren't collected.
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 );

		$own     = wp_normalize_path( EMAILIT_DIR );
		$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$mu      = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
		$themes  = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$core    = self::core_functions();
		$found   = '';
		$after   = false;

		foreach ( $trace as $frame ) {
			$function = isset( $frame['function'] ) ? $frame['function'] : '';
			// Frames inside wp_mail() and this plugin come first; the wp_mail frame's file is its caller.
			if ( 'wp_mail' === $function ) {
				$after = true;
			}
			if ( ! $after ) {
				continue;
			}
			if ( '' === $found && isset( $core[ $function ] ) && empty( $frame['class'] ) ) {
				$found = $core[ $function ];
			}
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $own ) ) {
				continue;
			}
			if ( 0 === strpos( $file, $plugins ) ) {
				return self::first_segment( substr( $file, strlen( $plugins ) ) );
			}
			if ( 0 === strpos( $file, $mu ) ) {
				return 'mu:' . self::first_segment( substr( $file, strlen( $mu ) ) );
			}
			if ( 0 === strpos( $file, $themes ) ) {
				return 'theme:' . self::first_segment( substr( $file, strlen( $themes ) ) );
			}
		}
		return '' !== $found ? $found : 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- A slug, not the name.
	}

	/**
	 * Plugin folder (or file name for single-file plugins).
	 *
	 * @param string $relative Path below the plugins folder.
	 * @return string
	 */
	private static function first_segment( $relative ) {
		$parts = explode( '/', $relative );
		$first = $parts[0];
		return sanitize_key( 1 === count( $parts ) ? preg_replace( '/\.php$/', '', $first ) : $first );
	}

	/**
	 * A readable name for a source.
	 *
	 * @param string $source Source slug.
	 * @return string
	 */
	public static function label( $source ) {
		static $names = null;
		$source       = (string) $source;
		if ( 0 === strpos( $source, 'wordpress' ) ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- A slug, not the name.
			$labels = array(
				'wordpress'                    => __( 'WordPress', 'emailit' ),
				'wordpress:password-reset'     => __( 'WordPress: password reset', 'emailit' ),
				'wordpress:new-user'           => __( 'WordPress: new user', 'emailit' ),
				'wordpress:password-changed'   => __( 'WordPress: password changed', 'emailit' ),
				'wordpress:comment'            => __( 'WordPress: comment', 'emailit' ),
				'wordpress:comment-moderation' => __( 'WordPress: comment moderation', 'emailit' ),
				'wordpress:privacy-request'    => __( 'WordPress: privacy request', 'emailit' ),
				'wordpress:privacy-export'     => __( 'WordPress: personal data export', 'emailit' ),
				'wordpress:recovery-mode'      => __( 'WordPress: recovery mode', 'emailit' ),
				'wordpress:email-change'       => __( 'WordPress: email change', 'emailit' ),
				'wordpress:account-change'     => __( 'WordPress: account change', 'emailit' ),
				'wordpress:admin-email-change' => __( 'WordPress: admin email change', 'emailit' ),
				'wordpress:auto-update'        => __( 'WordPress: automatic updates', 'emailit' ),
				'wordpress:core-update'        => __( 'WordPress: core update', 'emailit' ),
				'wordpress:signup'             => __( 'WordPress: sign-up', 'emailit' ),
			);
			return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
		}
		if ( 0 === strpos( $source, 'theme:' ) ) {
			/* translators: %s: theme folder. */
			return sprintf( __( 'Theme: %s', 'emailit' ), substr( $source, 6 ) );
		}
		if ( 0 === strpos( $source, 'mu:' ) ) {
			/* translators: %s: must-use plugin file. */
			return sprintf( __( 'Must-use plugin: %s', 'emailit' ), substr( $source, 3 ) );
		}
		if ( null === $names ) {
			$names = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$names[ sanitize_key( dirname( $file ) === '.' ? preg_replace( '/\.php$/', '', $file ) : dirname( $file ) ) ] = $data['Name'];
			}
		}
		return isset( $names[ $source ] ) ? $names[ $source ] : $source;
	}
}
