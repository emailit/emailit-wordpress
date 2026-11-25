<?php
/**
 * Sends, throttles, queues and retries.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what happens to every email: send now, queue, retry or fail.
 */
final class Dispatcher {

	const LOCK = 'emailit_queue_lock';

	/**
	 * Seconds to wait before each retry after an error. The email fails after the last one.
	 *
	 * @return array<int, int>
	 */
	public static function backoff() {
		/**
		 * Filters the retry schedule: seconds before attempt 2, 3, ... The email
		 * is marked failed when the schedule runs out.
		 *
		 * @param array<int, int> $delays Seconds. Default about 30 hours in total.
		 */
		return array_values( (array) apply_filters( 'emailit_retry_delays', array( 60, 120, 300, 600, 1800, 3600, 7200, 14400, 28800, 43200 ) ) );
	}

	/**
	 * Send an email from wp_mail(): now if the limits allow, otherwise queue it.
	 *
	 * @param Message $message The email.
	 * @return Send_Error|null Null when it was sent or queued.
	 */
	public static function send_now( Message $message ) {
		$id    = Email_Log::create( $message, 'sending' );
		$count = count( $message->recipients() );

		$too_big = Throttle::too_big( $count );
		if ( $too_big ) {
			Email_Log::failed( $id, $message, $too_big, 1 );
			return $too_big;
		}

		if ( 'queue' === Throttle::decide( $count ) ) {
			$defer  = Throttle::take_defer();
			$reason = $defer ? Send_Error::DAILY : Throttle::pause_reason();
			return self::queue_or_fail( $id, $message, '' !== $reason ? $reason : 'throttled', $defer );
		}

		$delivery = self::deliver( $message, $id, true, false );
		if ( $delivery->ok ) {
			Email_Log::sent( $id, $message, $delivery, 1 );
			self::sent_action( $id, $message, $delivery );
			return null;
		}

		$error = $delivery->error;
		$delay = (int) $delivery->retry_delay;

		// Over the per-second limit: wait the second out (within the page load's budget) and try once more.
		if ( Send_Error::RATE === $error->type && empty( $delivery->sent ) && 'send' === Throttle::decide( $count ) ) {
			$delivery = self::deliver( $message, $id, true, false );
			if ( $delivery->ok ) {
				Email_Log::sent( $id, $message, $delivery, 1 );
				self::sent_action( $id, $message, $delivery );
				return null;
			}
			$error = $delivery->error;
			$delay = (int) $delivery->retry_delay;
		}

		if ( ! empty( $delivery->sent ) ) {
			self::log_partial( $message, $delivery, 1 );
			Email_Log::update( $id, array( 'recipients' => Email_Log::utf8( $message->to_display() ) ) );
		}

		if ( $error->is_retryable() && Settings::get( 'retry' ) ) {
			return self::queue_or_fail( $id, $message, $error->type, $delay, $error->message, $error->counts_as_attempt() ? 1 : 0 );
		}

		Email_Log::failed( $id, $message, $error, 1 );
		return $error;
	}

	/**
	 * Queue an email; when it can't be stored, report it as failed so wp_mail() returns false.
	 *
	 * @param int     $id       Email log row.
	 * @param Message $message  The email.
	 * @param string  $reason   Why it's queued.
	 * @param int     $delay    Seconds until the next attempt.
	 * @param string  $error    Last error.
	 * @param int     $attempts Attempts so far.
	 * @return Send_Error|null
	 */
	private static function queue_or_fail( $id, Message $message, $reason, $delay = 0, $error = '', $attempts = 0 ) {
		if ( Email_Log::queue( $id, $message, $reason, $delay, $error, $attempts ) ) {
			return null;
		}
		global $wpdb;
		/* translators: %s: database error. */
		return Send_Error::make( sprintf( __( 'The email could not be saved to the queue: %s', 'emailit' ), (string) $wpdb->last_error ) );
	}

	/**
	 * Log the recipients the SMTP relay accepted and keep only the others in the message.
	 *
	 * @param Message  $message  The email; left with the recipients that failed.
	 * @param Delivery $delivery Result.
	 * @param int      $attempts Attempts.
	 * @return void
	 */
	private static function log_partial( Message $message, Delivery $delivery, $attempts ) {
		// The copy shares the attachment files with the rest of the email, so it mustn't delete them.
		$done = $message->without_attachments();
		$done->only( $delivery->sent );
		Email_Log::sent( Email_Log::create( $done, 'sent' ), $done, $delivery, $attempts );
		$message->only( $delivery->failed );
		unset( $message->data['idempotency_key'] );
	}


	/**
	 * Send through the API or SMTP and let the throttle learn from the result.
	 *
	 * @param Message $message    The email.
	 * @param int     $id         Email log row.
	 * @param bool    $inline     Sent during a page load.
	 * @param bool    $keep_alive Keep the SMTP connection open.
	 * @return Delivery Has retry_delay set when it failed.
	 */
	public static function deliver( Message $message, $id, $inline, $keep_alive ) {
		$count = count( $message->recipients() );
		if ( 'smtp' === Settings::mailer() ) {
			$delivery = Smtp_Transport::send( $message, $keep_alive );
			$response = null;
		} else {
			list( $delivery, $response ) = Api_Transport::send( $message, $id, $inline ? 15 : 30 );
		}

		if ( ! $delivery->ok && Send_Error::DAILY === $delivery->error->type && $delivery->error->limit && $count > $delivery->error->limit ) {
			// More recipients than the whole daily limit: waiting won't help.
			$delivery->error->type = Send_Error::PERMANENT;
			/* translators: 1: number of recipients, 2: daily limit. */
			$delivery->error->message = sprintf( __( 'This email has %1$d recipients, more than your workspace\'s daily sending limit of %2$d.', 'emailit' ), $count, $delivery->error->limit );
		}

		if ( $delivery->ok ) {
			Throttle::record_sent( $count, $inline, $response );
		} else {
			if ( ! empty( $delivery->sent ) ) {
				Throttle::record_sent( count( $delivery->sent ), $inline );
			}
			$delivery->retry_delay = Throttle::record_error( $delivery->error, $response );
		}
		return $delivery;
	}

	/**
	 * Fire the sent action.
	 *
	 * @param int      $id       Email log row.
	 * @param Message  $message  The email.
	 * @param Delivery $delivery Result.
	 * @return void
	 */
	private static function sent_action( $id, Message $message, Delivery $delivery ) {
		/**
		 * Fires when Emailit accepted an email.
		 *
		 * @param int      $id       Email log row.
		 * @param Message  $message  The email.
		 * @param Delivery $delivery Result, with the Emailit email ID.
		 */
		do_action( 'emailit_email_sent', $id, $message, $delivery );
	}

	/**
	 * Take the queue lock. Expired locks (a run that died) are taken over.
	 *
	 * @param int $ttl Seconds.
	 * @return bool
	 */
	private static function lock( $ttl ) {
		global $wpdb;
		$now = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock needs direct queries.
		if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) ( $now + $ttl ) ) ) ) {
			return true;
		}
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) ( $now + $ttl ), self::LOCK, $now ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $taken;
	}

	/**
	 * Release the queue lock.
	 *
	 * @return void
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row, see lock().
	}

	/**
	 * Send due emails, then run due jobs, for about 20 seconds. Runs on WP-Cron every minute while there's work.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! Settings::is_configured() ) {
			return;
		}
		/**
		 * Filters how long one queue run may take, in seconds.
		 *
		 * @param int $seconds Seconds. Default 20.
		 */
		$budget = max( 5, (int) apply_filters( 'emailit_run_seconds', 20 ) );
		if ( ! self::lock( $budget + 60 ) ) {
			return;
		}
		Throttle::reset_request();
		$deadline = microtime( true ) + $budget;

		try {
			Email_Log::release_stuck();
			while ( microtime( true ) < $deadline && ! Throttle::paused_until() ) {
				$rows = Email_Log::due( 20 );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline || Throttle::paused_until() ) {
						break 2;
					}
					if ( ! self::process( $row, $deadline ) ) {
						break 2;
					}
				}
			}
			Jobs::run( $deadline );
		} finally {
			Smtp_Transport::close();
			self::unlock();
		}

		if ( 0 === Email_Log::count( 'queued' ) && 0 === Email_Log::count( 'sending' ) && 0 === Jobs::count_queued() ) {
			wp_clear_scheduled_hook( Plugin::CRON_HOOK );
			// Something may have been queued while the schedule was being cleared.
			if ( Email_Log::count( 'queued' ) || Jobs::count_queued() ) {
				Plugin::schedule_queue();
			}
		}
	}

	/**
	 * Send one queued email.
	 *
	 * @param object $row      Email log row.
	 * @param float  $deadline Microtime the run must end by.
	 * @return bool False to stop this run.
	 */
	private static function process( $row, $deadline ) {
		$message = Message::from_json( $row->message );
		if ( ! $message || ! $message->recipients() ) {
			Email_Log::update(
				(int) $row->id,
				array(
					'status'     => 'failed',
					'last_error' => __( 'The queued email could not be read.', 'emailit' ),
				)
			);
			return true;
		}

		$count   = count( $message->recipients() );
		$too_big = Throttle::too_big( $count );
		if ( $too_big ) {
			Email_Log::failed( (int) $row->id, $message, $too_big, max( 1, (int) $row->attempts ) );
			return true;
		}
		$room = Throttle::daily_room( $count );
		if ( 'pause' === $room ) {
			return false;
		}
		if ( 'defer' === $room ) {
			// Too big for what's left today, but smaller emails still fit: only this one waits.
			Email_Log::queue( (int) $row->id, $message, Send_Error::DAILY, Throttle::take_defer(), (string) $row->last_error, (int) $row->attempts );
			return true;
		}
		if ( ! Throttle::pace( $count, max( 0.0, $deadline - microtime( true ) ) ) ) {
			return false;
		}
		if ( ! Email_Log::claim( (int) $row->id ) ) {
			return true;
		}

		$delivery = self::deliver( $message, (int) $row->id, false, true );
		$attempts = (int) $row->attempts;

		if ( $delivery->ok ) {
			Email_Log::sent( (int) $row->id, $message, $delivery, $attempts + 1 );
			self::sent_action( (int) $row->id, $message, $delivery );
			return true;
		}

		$error = $delivery->error;
		if ( ! empty( $delivery->sent ) ) {
			self::log_partial( $message, $delivery, $attempts + 1 );
		}

		if ( $error->counts_as_attempt() ) {
			++$attempts;
		}
		$delays = self::backoff();
		if ( $error->is_retryable() && $attempts <= count( $delays ) ) {
			$wait = $error->counts_as_attempt() ? max( (int) $delivery->retry_delay, (int) $delays[ max( 0, $attempts - 1 ) ] ) : max( 1, (int) $delivery->retry_delay );
			Email_Log::queue( (int) $row->id, $message, $error->type, $wait, $error->message, $attempts );
			return true;
		}

		Email_Log::failed( (int) $row->id, $message, $error, max( 1, $attempts ) );
		/** This action is documented in wp-includes/pluggable.php */
		do_action(
			'wp_mail_failed', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired when a queued email fails for good.
			new \WP_Error(
				'wp_mail_failed',
				$error->message,
				array(
					'to'          => wp_list_pluck( (array) $message->get( 'to', array() ), 0 ),
					'subject'     => (string) $message->get( 'subject' ),
					'message'     => (string) $message->get( 'body' ),
					'headers'     => array(),
					'attachments' => array(),
				)
			)
		);
		return true;
	}
}
