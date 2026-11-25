<?php
/**
 * Plugin bootstrap.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	const CRON_HOOK  = 'emailit_process_queue';
	const CLEAN_HOOK = 'emailit_cleanup';
	const SCHEDULE   = 'emailit_every_minute';

	/**
	 * Hook everything up. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The queue runs every minute while it has work.
		add_action( self::CRON_HOOK, array( Dispatcher::class, 'run' ) );
		add_action( self::CLEAN_HOOK, array( Email_Log::class, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( Webhooks::class, 'register_route' ) );

		// Last, so the email is final when it reaches the mailer.
		add_filter( 'wp_mail', array( __CLASS__, 'install_mailer' ), PHP_INT_MAX );

		Integrations\Integrations::boot();
		Privacy::boot();

		if ( is_admin() ) {
			Admin\Admin::boot();
		}
	}

	/**
	 * Add the every-minute schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Emailit queue)', 'emailit' ),
		);
		return $schedules;
	}

	/**
	 * Create or update the tables, and keep the cleanup scheduled.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		Email_Log::maybe_install();
		if ( ! wp_next_scheduled( self::CLEAN_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEAN_HOOK );
		}
	}

	/**
	 * Load PHPMailer and the classes that extend it.
	 *
	 * @return void
	 */
	public static function load_phpmailer() {
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}
		require_once EMAILIT_DIR . 'includes/class-mailer.php';
		require_once EMAILIT_DIR . 'includes/class-smtp-client.php';
	}

	/**
	 * Replace the global PHPMailer with ours, just before wp_mail() checks it.
	 *
	 * @param array<string, mixed> $atts wp_mail() arguments, unchanged.
	 * @return array<string, mixed>
	 */
	public static function install_mailer( $atts ) {
		global $phpmailer;
		if ( ! Settings::is_configured() ) {
			return $atts;
		}
		self::load_phpmailer();
		if ( ! ( $phpmailer instanceof Mailer ) ) {
			$phpmailer = new Mailer(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() reuses this instance; replacing it is how email reaches Emailit.
		}
		return $atts;
	}

	/**
	 * Make sure the queue runs every minute, and nudge WP-Cron at the end of this request.
	 *
	 * @return void
	 */
	public static function schedule_queue() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
		}
		static $nudged = false;
		if ( ! $nudged && ! wp_doing_cron() ) {
			$nudged = true;
			add_action( 'shutdown', array( __CLASS__, 'nudge' ) );
		}
	}

	/**
	 * Start WP-Cron in the background.
	 *
	 * @return void
	 */
	public static function nudge() {
		if ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || ! function_exists( 'spawn_cron' ) ) {
			return;
		}
		spawn_cron();
	}

	/**
	 * Other plugins that change how WordPress sends email.
	 *
	 * @return array<int, string>
	 */
	public static function conflicts() {
		$known  = array(
			'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
			'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
			'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
			'post-smtp/postman-smtp.php'        => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
			'wp-smtp/wp-smtp.php'               => 'WP SMTP',
			'smtp-mailer/main.php'              => 'SMTP Mailer',
			'gmail-smtp/main.php'               => 'Gmail SMTP',
			'mailgun/mailgun.php'               => 'Mailgun',
			'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
			'wp-ses/wp-ses.php'                 => 'WP Offload SES',
			'suremails/suremails.php'           => 'SureMail',
			'emailit-smtp/emailit-smtp.php'     => 'Emailit SMTP',
		);
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( $known as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		Email_Log::install();
		if ( ! wp_next_scheduled( self::CLEAN_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEAN_HOOK );
		}
		// Emails or jobs left from before a deactivation.
		if ( Email_Log::count( 'queued' ) || Email_Log::count( 'sending' ) || Jobs::count_queued() ) {
			wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
		}
		add_option( 'emailit_activated', time(), '', false );
	}

	/**
	 * Deactivation: stop the cron jobs. Queued emails and jobs wait until the plugin is active again.
	 *
	 * @param bool $network_wide Deactivated for the whole network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		$sites = $network_wide && is_multisite() ? get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) : array( 0 );
		foreach ( $sites as $site_id ) {
			if ( $site_id ) {
				switch_to_blog( $site_id );
			}
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_clear_scheduled_hook( self::CLEAN_HOOK );
			if ( $site_id ) {
				restore_current_blog();
			}
		}
	}
}
