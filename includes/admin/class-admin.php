<?php
/**
 * Admin screens and actions.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Admin;

use Emailit\WordPress\Api;
use Emailit\WordPress\Dispatcher;
use Emailit\WordPress\Email_Log;
use Emailit\WordPress\Jobs;
use Emailit\WordPress\Message;
use Emailit\WordPress\Plugin;
use Emailit\WordPress\Settings;
use Emailit\WordPress\Throttle;
use Emailit\WordPress\Webhooks;
use Emailit\WordPress\Integrations\Integrations;
use Emailit\WordPress\Integrations\Users;
use Emailit\WordPress\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The Emailit menu: Settings, Email log and Integrations.
 */
final class Admin {

	const CAP = 'manage_options';

	/**
	 * Hook up the admin.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EMAILIT_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'test', 'queue', 'email', 'integrations', 'webhook', 'sync', 'refresh' ) as $action ) {
			add_action( 'admin_post_emailit_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_saved' ), 10, 2 );
	}

	/**
	 * URL of a screen.
	 *
	 * @param string                $page Page slug.
	 * @param array<string, string> $args Query arguments.
	 * @return string
	 */
	public static function url( $page = 'emailit', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The Emailit mark for the menu.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 67.8 53.2"><path fill="#a7aaad" d="M33.82 26.46c7.45.24 14.34-6.45 32.62-19.58C64.48 2.82 60.31 0 55.52 0H12.12C7.33 0 3.17 2.82 1.2 6.88c18.29 13.12 25.18 19.82 32.62 19.58Z"/><path fill="#a7aaad" d="M18.11 25.91c4.71 3.17 9.84 6.13 15.71 5.99 5.87.13 11-2.82 15.71-5.99 6.14-4.13 12.1-8.78 18.11-13.18v28.39c0 6.66-5.45 12.12-12.12 12.12H12.12C5.46 53.24 0 47.79 0 41.12V12.73c6.02 4.39 11.97 9.05 18.11 13.18Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI.
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page( __( 'Emailit', 'emailit' ), __( 'Emailit', 'emailit' ), self::CAP, 'emailit', array( Settings_Page::class, 'render' ), self::menu_icon(), 81 );
		add_submenu_page( 'emailit', __( 'Emailit settings', 'emailit' ), __( 'Settings', 'emailit' ), self::CAP, 'emailit', array( Settings_Page::class, 'render' ) );
		add_submenu_page( 'emailit', __( 'Email log', 'emailit' ), __( 'Email log', 'emailit' ), self::CAP, 'emailit-log', array( Log_Page::class, 'render' ) );
		add_submenu_page( 'emailit', __( 'Integrations', 'emailit' ), __( 'Integrations', 'emailit' ), self::CAP, 'emailit-integrations', array( Integrations_Page::class, 'render' ) );
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'emailit',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * After the settings are saved: check the key again.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function settings_saved( $old_value, $value ) {
		Settings::flush();
		if ( ! is_array( $old_value ) || ! is_array( $value ) || ( isset( $old_value['api_key'] ) ? $old_value['api_key'] : '' ) !== ( isset( $value['api_key'] ) ? $value['api_key'] : '' ) ) {
			delete_option( 'emailit_account' );
			delete_transient( 'emailit_audiences' );
			delete_option( WooCommerce::COMMERCE_KEY );
		}
	}

	/**
	 * Styles on our screens.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'emailit' ) ) {
			return;
		}
		wp_enqueue_style( 'emailit-admin', EMAILIT_URL . 'assets/admin.css', array(), EMAILIT_VERSION );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'emailit' ) . '</a>' );
		return $links;
	}

	/**
	 * Notices about sending problems on the Dashboard, Plugins and Emailit screens.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) && false === strpos( $screen->id, 'emailit' ) ) ) {
			return;
		}
		$ours = false !== strpos( $screen->id, 'emailit' );

		if ( ! Settings::is_configured() ) {
			if ( ! $ours ) {
				self::notice( 'warning', sprintf( '%s <a href="%s">%s</a>', esc_html__( 'Emailit isn\'t sending your email yet.', 'emailit' ), esc_url( self::url() ), esc_html__( 'Add your API key', 'emailit' ) ), true );
			}
			return;
		}

		$conflicts = Plugin::conflicts();
		if ( $conflicts ) {
			/* translators: %s: plugin names. */
			self::notice( 'warning', esc_html( sprintf( __( '%s also changes how WordPress sends email. Deactivate it so all email goes through Emailit.', 'emailit' ), implode( ', ', $conflicts ) ) ) );
		}

		$reason = Throttle::pause_reason();
		$paused = Throttle::paused_until();
		if ( 'daily' === $reason ) {
			self::notice(
				'warning',
				sprintf(
					/* translators: 1: number of emails, 2: time until the limit resets. */
					esc_html__( 'Your workspace reached its daily sending limit. %1$s queued emails will be sent in about %2$s, after the limit resets at 00:00 UTC.', 'emailit' ),
					'<strong>' . esc_html( number_format_i18n( Email_Log::count( 'queued' ) ) ) . '</strong>',
					esc_html( human_time_diff( time(), $paused ) )
				)
			);
		} elseif ( 'auth' === $reason ) {
			self::notice( 'error', sprintf( '%s <a href="%s">%s</a>', esc_html__( 'Emailit rejected the API key, so emails are being queued.', 'emailit' ), esc_url( self::url() ), esc_html__( 'Check the key', 'emailit' ) ) );
		} elseif ( 'credits' === $reason ) {
			self::notice( 'error', sprintf( '%s <a href="https://dash.emailit.com/" target="_blank" rel="noopener">%s</a>', esc_html__( 'Your Emailit workspace is out of credits, so emails are being queued.', 'emailit' ), esc_html__( 'Add credits or upgrade', 'emailit' ) ) );
		}

		$failed = Email_Log::count( 'failed' );
		if ( $failed && 'emailit_page_emailit-log' !== $screen->id ) {
			self::notice(
				'warning',
				sprintf(
					/* translators: 1: number of emails, 2: link. */
					_n( '%1$s email could not be delivered. %2$s', '%1$s emails could not be delivered. %2$s', $failed, 'emailit' ),
					esc_html( number_format_i18n( $failed ) ),
					'<a href="' . esc_url( self::url( 'emailit-log', array( 'status' => 'failed' ) ) ) . '">' . esc_html__( 'See why', 'emailit' ) . '</a>'
				),
				true
			);
		}
	}

	/**
	 * Print a notice.
	 *
	 * @param string $type        Type.
	 * @param string $html        Escaped HTML.
	 * @param bool   $dismissible Dismissible.
	 * @return void
	 */
	public static function notice( $type, $html, $dismissible = false ) {
		printf(
			'<div class="notice notice-%1$s%2$s"><p><strong>%3$s</strong> %4$s</p></div>',
			esc_attr( $type ),
			$dismissible ? ' is-dismissible' : '',
			esc_html__( 'Emailit:', 'emailit' ),
			wp_kses_post( $html )
		);
	}

	/**
	 * Store a message for the next screen.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Escaped HTML.
	 * @param string $details Optional details.
	 * @return void
	 */
	public static function flash( $type, $message, $details = '' ) {
		set_transient(
			'emailit_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => $details,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Show the stored message.
	 *
	 * @return void
	 */
	public static function show_flash() {
		$key   = 'emailit_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-' . esc_attr( $flash['type'] ) . ' is-dismissible"><p>' . wp_kses_post( $flash['message'] ) . '</p>';
		if ( ! empty( $flash['details'] ) ) {
			echo '<details><summary>' . esc_html__( 'Details', 'emailit' ) . '</summary><pre class="emailit-pre">' . esc_html( $flash['details'] ) . '</pre></details>';
		}
		echo '</div>';
	}

	/**
	 * Stop unless the user may manage Emailit.
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'emailit' ), 403 );
		}
	}

	/**
	 * Redirect back.
	 *
	 * @param string                $page Page.
	 * @param array<string, string> $args Arguments.
	 * @return void
	 */
	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	/**
	 * Send a test email through wp_mail(), so it takes the same path as every other email.
	 *
	 * @return void
	 */
	public static function handle_test() {
		self::require_cap();
		check_admin_referer( 'emailit_test' );
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			self::flash( 'error', esc_html__( 'Enter a valid email address.', 'emailit' ) );
			self::back( 'emailit' );
		}
		if ( ! Settings::is_configured() ) {
			self::flash( 'error', esc_html__( 'Save your API key first.', 'emailit' ) );
			self::back( 'emailit' );
		}

		Throttle::clear_pause();
		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$sent = wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( 'Emailit test from %s', 'emailit' ), $site ),
			'<p>' . esc_html__( 'This test email was sent by the Emailit plugin. WordPress can now send email through Emailit.', 'emailit' ) . '</p><p style="color:#5f6b66">' . esc_html( home_url() ) . '</p>',
			array( 'Content-Type: text/html; charset=UTF-8', 'X-Emailit-Meta-Wp-Test: 1' )
		);
		remove_action( 'wp_mail_failed', $catch );

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$row   = $wpdb->get_row( "SELECT id, status, emailit_id, last_error FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.

		if ( $sent && ! Settings::get( 'log' ) ) {
			/* translators: %s: recipient. */
			self::flash( 'success', sprintf( esc_html__( 'Test email sent to %s.', 'emailit' ), '<strong>' . esc_html( $to ) . '</strong>' ) );
		} elseif ( $sent && $row && 'sent' === $row->status ) {
			/* translators: 1: recipient, 2: Emailit email ID. */
			self::flash( 'success', sprintf( esc_html__( 'Test email sent to %1$s. Emailit ID: %2$s', 'emailit' ), '<strong>' . esc_html( $to ) . '</strong>', '<code>' . esc_html( $row->emailit_id ) . '</code>' ) );
		} elseif ( $sent && $row && 'queued' === $row->status ) {
			/* translators: %s: error. */
			self::flash( 'warning', sprintf( esc_html__( 'The test email was queued and will be retried: %s', 'emailit' ), esc_html( (string) $row->last_error ) ) );
		} else {
			self::flash( 'error', esc_html__( 'The test email wasn\'t sent:', 'emailit' ) . ' ' . esc_html( '' !== $error ? $error : ( $row ? (string) $row->last_error : '' ) ) . ' ' . self::hint( '' !== $error ? $error : ( $row ? (string) $row->last_error : '' ) ) );
		}
		self::back( 'emailit' );
	}

	/**
	 * A hint for common errors.
	 *
	 * @param string $error Error.
	 * @return string Escaped HTML.
	 */
	public static function hint( $error ) {
		if ( preg_match( '/^401|Unauthorized|invalid api key|authenticat/i', $error ) ) {
			return esc_html__( 'Check that the API key is complete and still exists in Emailit.', 'emailit' );
		}
		if ( false !== stripos( $error, 'Permission denied' ) ) {
			return esc_html__( 'This API key can\'t send email. Use a Sending Only or Full Access key.', 'emailit' );
		}
		if ( false !== stripos( $error, 'not verified' ) || false !== stripos( $error, 'Domain' ) ) {
			return esc_html__( 'Set a From email on a domain you verified in Emailit.', 'emailit' );
		}
		if ( false !== stripos( $error, 'Unverified workspace' ) || false !== stripos( $error, 'members' ) ) {
			return esc_html__( 'Your workspace doesn\'t have production access yet, so you can only send to workspace members.', 'emailit' );
		}
		if ( false !== stripos( $error, 'credits' ) ) {
			return esc_html__( 'Add credits or upgrade your Emailit plan.', 'emailit' );
		}
		return '';
	}

	/**
	 * Queue actions from the log: send queued now, retry failed, delete.
	 *
	 * @return void
	 */
	public static function handle_queue() {
		self::require_cap();
		check_admin_referer( 'emailit_queue' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each ID goes through absint.
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();

		switch ( $do ) {
			case 'run':
				Email_Log::send_queued_now();
				Dispatcher::run();
				/* translators: %s: number of emails. */
				self::flash( 'success', esc_html( sprintf( __( 'Queue processed. %s emails are still waiting.', 'emailit' ), number_format_i18n( Email_Log::count( 'queued' ) ) ) ) );
				break;
			case 'retry_failed':
				$count = Email_Log::retry( null );
				Dispatcher::run();
				/* translators: %s: number of emails. */
				self::flash( 'success', esc_html( sprintf( _n( '%s email sent again.', '%s emails sent again.', $count, 'emailit' ), number_format_i18n( $count ) ) ) );
				break;
			case 'retry':
				$count = Email_Log::retry( $ids );
				Dispatcher::run();
				/* translators: %s: number of emails. */
				self::flash( 'success', esc_html( sprintf( _n( '%s email sent again.', '%s emails sent again.', $count, 'emailit' ), number_format_i18n( $count ) ) ) );
				break;
			case 'delete':
				$count = Email_Log::delete( $ids );
				/* translators: %s: number of emails. */
				self::flash( 'success', esc_html( sprintf( _n( '%s email deleted.', '%s emails deleted.', $count, 'emailit' ), number_format_i18n( $count ) ) ) );
				break;
			case 'delete_failed':
				Email_Log::delete( 'failed' );
				self::flash( 'success', esc_html__( 'Failed emails deleted.', 'emailit' ) );
				break;
		}
		self::back( 'emailit-log' );
	}

	/**
	 * Actions on one email: resend or delete.
	 *
	 * @return void
	 */
	public static function handle_email() {
		self::require_cap();
		check_admin_referer( 'emailit_email' );
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$row = Email_Log::find( $id );
		if ( ! $row ) {
			self::back( 'emailit-log' );
		}
		if ( 'delete' === $do ) {
			Email_Log::delete( array( $id ) );
			self::flash( 'success', esc_html__( 'Email deleted.', 'emailit' ) );
			self::back( 'emailit-log' );
		}
		if ( 'resend' === $do ) {
			$message = Message::from_json( $row->message );
			if ( ! Email_Log::can_view_content( $row ) ) {
				wp_die( esc_html__( 'You are not allowed to send this email again.', 'emailit' ), 403 );
			}
			if ( ! $message ) {
				self::flash( 'error', esc_html__( 'This email\'s content wasn\'t saved, so it can\'t be sent again.', 'emailit' ) );
				self::back( 'emailit-log', array( 'email' => (string) $id ) );
			}
			$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
			if ( '' !== $to ) {
				$message->data['to']  = array( array( $to, '' ) );
				$message->data['cc']  = array();
				$message->data['bcc'] = array();
			}
			$message->data['source'] = 'resend';
			unset( $message->data['idempotency_key'] );
			$dropped = 0;
			foreach ( (array) $message->get( 'attachments', array() ) as $i => $a ) {
				if ( ! isset( $a['content'] ) && empty( $a['path'] ) ) {
					unset( $message->data['attachments'][ $i ] );
					++$dropped;
				}
			}
			Throttle::clear_pause();
			$error = Dispatcher::send_now( $message );
			if ( null === $error ) {
				self::flash( 'success', $dropped ? esc_html__( 'The email was sent again, without its attachments, which aren\'t kept in the log.', 'emailit' ) : esc_html__( 'The email was sent again.', 'emailit' ) );
			} else {
				self::flash( 'error', esc_html__( 'The email wasn\'t sent:', 'emailit' ) . ' ' . esc_html( $error->message ) );
			}
		}
		self::back( 'emailit-log' );
	}

	/**
	 * Re-check the API key and limits.
	 *
	 * @return void
	 */
	public static function handle_refresh() {
		self::require_cap();
		check_admin_referer( 'emailit_refresh' );
		Api::account( true );
		delete_transient( 'emailit_audiences' );
		if ( WooCommerce::is_available() ) {
			WooCommerce::check_commerce();
		}
		self::flash( 'success', esc_html__( 'Checked your Emailit account again.', 'emailit' ) );
		$page = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : 'emailit';
		self::back( in_array( $page, array( 'emailit', 'emailit-integrations' ), true ) ? $page : 'emailit' );
	}

	/**
	 * Save the Integrations screen.
	 *
	 * @return void
	 */
	public static function handle_integrations() {
		self::require_cap();
		check_admin_referer( 'emailit_integrations' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is sanitized below.
		$forms_in = isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ? wp_unslash( $_POST['forms'] ) : array();
		$wc_in    = isset( $_POST['woocommerce'] ) && is_array( $_POST['woocommerce'] ) ? wp_unslash( $_POST['woocommerce'] ) : array();
		$users_in = isset( $_POST['users'] ) && is_array( $_POST['users'] ) ? wp_unslash( $_POST['users'] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$forms = array();
		foreach ( $forms_in as $key => $form ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! preg_match( '/^[a-z0-9]+:[A-Za-z0-9_-]+$/', $key ) || ! is_array( $form ) ) {
				continue;
			}
			$forms[ $key ] = array(
				'enabled'  => ! empty( $form['enabled'] ),
				'audience' => isset( $form['audience'] ) ? sanitize_text_field( $form['audience'] ) : '',
				'consent'  => isset( $form['consent'] ) && 'always' === $form['consent'] ? 'always' : 'checkbox',
				'tags'     => isset( $form['tags'] ) ? sanitize_text_field( $form['tags'] ) : '',
				'event'    => ! empty( $form['event'] ),
			);
		}
		Integrations::save( 'forms', $forms );

		if ( isset( $_POST['woocommerce'] ) ) {
			$old = WooCommerce::settings();
			$key = isset( $wc_in['tracking_key'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wc_in['tracking_key'] ) : '';
			Integrations::save(
				'woocommerce',
				array(
					'enabled'      => ! empty( $wc_in['enabled'] ),
					'orders'       => ! empty( $wc_in['orders'] ),
					'products'     => ! empty( $wc_in['products'] ),
					'carts'        => ! empty( $wc_in['carts'] ),
					'optin'        => ! empty( $wc_in['optin'] ),
					'optin_label'  => isset( $wc_in['optin_label'] ) ? sanitize_text_field( $wc_in['optin_label'] ) : '',
					'audience'     => isset( $wc_in['audience'] ) ? sanitize_text_field( $wc_in['audience'] ) : '',
					'tracking'     => ! empty( $wc_in['tracking'] ),
					'tracking_key' => '' !== $key ? $key : (string) $old['tracking_key'],
					'identify'     => ! empty( $wc_in['identify'] ),
				)
			);
		}

		if ( isset( $_POST['users'] ) ) {
			$roles = isset( $users_in['roles'] ) ? array_map( 'sanitize_key', (array) $users_in['roles'] ) : array();
			Integrations::save(
				'users',
				array(
					'enabled'  => ! empty( $users_in['enabled'] ),
					'roles'    => array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) ),
					'audience' => isset( $users_in['audience'] ) ? sanitize_text_field( $users_in['audience'] ) : '',
					'tags'     => isset( $users_in['tags'] ) ? sanitize_text_field( $users_in['tags'] ) : '',
				)
			);
		}

		self::flash( 'success', esc_html__( 'Integrations saved.', 'emailit' ) );
		self::back( 'emailit-integrations' );
	}

	/**
	 * Delivery tracking: create, set or remove the webhook.
	 *
	 * @return void
	 */
	public static function handle_webhook() {
		self::require_cap();
		check_admin_referer( 'emailit_webhook' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'create' === $do ) {
			$result = Webhooks::create();
			if ( is_wp_error( $result ) ) {
				self::flash( 'error', esc_html( $result->get_error_message() ) );
			} else {
				self::flash( 'success', esc_html__( 'Delivery tracking is on. The email log now shows delivered, bounced, opened and clicked.', 'emailit' ) );
			}
		} elseif ( 'secret' === $do ) {
			$secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
			if ( ! preg_match( '/^whsec_[A-Za-z0-9]+$/', $secret ) ) {
				self::flash( 'error', esc_html__( 'Paste the webhook\'s signing secret. It starts with whsec_.', 'emailit' ) );
			} else {
				Webhooks::set_secret( $secret );
				self::flash( 'success', esc_html__( 'Signing secret saved.', 'emailit' ) );
			}
		} elseif ( 'disable' === $do ) {
			Webhooks::disable();
			self::flash( 'success', esc_html__( 'Delivery tracking is off.', 'emailit' ) );
		}
		self::back( 'emailit-integrations' );
	}

	/**
	 * Start a sync: products, order history, existing users, or create a tracking key.
	 *
	 * @return void
	 */
	public static function handle_sync() {
		self::require_cap();
		check_admin_referer( 'emailit_sync' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		switch ( $do ) {
			case 'products':
				delete_option( 'emailit_wc_products' );
				Jobs::add( 'wc_products_sync', array( 'page' => 1 ), 'products-sync' );
				self::flash( 'success', esc_html__( 'Your products are being sent to Emailit in the background.', 'emailit' ) );
				break;
			case 'orders':
				delete_option( 'emailit_wc_import' );
				Jobs::add( 'wc_orders_import', array( 'page' => 1 ), 'orders-import' );
				self::flash( 'success', esc_html__( 'Your order history is being imported in the background.', 'emailit' ) );
				break;
			case 'users':
				$page  = 1;
				$count = 0;
				do {
					$batch  = Users::sync_existing( $page );
					$count += $batch;
					++$page;
				} while ( 200 === $batch && $page <= 50 );
				/* translators: %s: number of users. */
				self::flash( 'success', esc_html( sprintf( __( '%s users are being added to Emailit in the background.', 'emailit' ), number_format_i18n( $count ) ) ) );
				break;
			case 'tracking_key':
				$key = WooCommerce::create_tracking_key();
				if ( is_wp_error( $key ) ) {
					self::flash( 'error', esc_html( $key->get_error_message() ) );
				} else {
					$settings                 = WooCommerce::settings();
					$settings['tracking_key'] = $key;
					Integrations::save( 'woocommerce', $settings );
					self::flash( 'success', esc_html__( 'Tracking key created.', 'emailit' ) );
				}
				break;
			case 'clear_jobs':
				Jobs::clear_failed();
				self::flash( 'success', esc_html__( 'Failed sync jobs cleared.', 'emailit' ) );
				break;
		}
		Plugin::schedule_queue();
		self::back( 'emailit-integrations' );
	}
}
