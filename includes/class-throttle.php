<?php
/**
 * Throttling based on Emailit's rate limit headers and replies.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps sending within the workspace's limits.
 *
 * Every API send returns `ratelimit-limit` (emails per second),
 * `ratelimit-daily-remaining` and `ratelimit-daily-reset`. The throttle keeps
 * the latest values and:
 *
 * - paces bursts to the per-second limit;
 * - queues emails that wouldn't fit in what's left of the daily limit instead
 *   of sending them into a 429, and sends them after 00:00 UTC;
 * - honors `retry-after` on 429s and pauses for a few minutes after login,
 *   credit or connection failures, so page loads don't wait on each one;
 * - lets a page load send a few emails right away and queues the rest, so a
 *   plugin that sends hundreds in a loop doesn't time out.
 *
 * Over SMTP there are no headers, so the limits are learned from the relay's
 * `452 4.4.5 ... (n/limit)` and `452 4.5.3` replies instead.
 */
final class Throttle {

	const OPTION             = 'emailit_throttle';
	const DEFAULT_PER_SECOND = 2;

	/**
	 * Emails sent during this request.
	 *
	 * @var int
	 */
	private static $sent_in_request = 0;

	/**
	 * Recent sends in this process: [microtime, recipients].
	 *
	 * @var array<int, array{0: float, 1: int}>
	 */
	private static $window = array();

	/**
	 * Seconds this request has waited.
	 *
	 * @var float
	 */
	private static $waited = 0.0;

	/**
	 * Cached state.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $state = null;

	/**
	 * Stored state.
	 *
	 * @param bool $fresh Read from the database again.
	 * @return array<string, mixed>
	 */
	public static function state( $fresh = false ) {
		if ( null === self::$state || $fresh ) {
			if ( $fresh ) {
				wp_cache_delete( self::OPTION, 'options' );
			}
			$saved       = get_option( self::OPTION, array() );
			self::$state = wp_parse_args(
				is_array( $saved ) ? $saved : array(),
				array(
					'per_second'      => null,
					'daily_limit'     => null,
					'daily_remaining' => null,
					'daily_reset_at'  => 0,
					'paused_until'    => 0,
					'pause_reason'    => '',
					'last_error'      => '',
					'probed_at'       => 0,
					'updated_at'      => 0,
				)
			);
		}
		return self::$state;
	}

	/**
	 * Save state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function save( array $state ) {
		$state['updated_at'] = time();
		self::$state         = $state;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Emails per second to send at.
	 *
	 * @return int
	 */
	public static function per_second() {
		if ( 'custom' === Settings::get( 'rate_mode' ) ) {
			return max( 1, (int) Settings::get( 'rate_limit' ) );
		}
		$state = self::state();
		return ! empty( $state['per_second'] ) ? (int) $state['per_second'] : self::DEFAULT_PER_SECOND;
	}

	/**
	 * Emails left today, if known.
	 *
	 * @return int|null
	 */
	public static function daily_remaining() {
		$state = self::state();
		if ( null === $state['daily_remaining'] || (int) $state['daily_reset_at'] <= time() ) {
			return null;
		}
		return (int) $state['daily_remaining'];
	}

	/**
	 * Unix time sending is paused until, or 0.
	 *
	 * @return int
	 */
	public static function paused_until() {
		$state = self::state();
		return (int) $state['paused_until'] > time() ? (int) $state['paused_until'] : 0;
	}

	/**
	 * Why sending is paused.
	 *
	 * @return string
	 */
	public static function pause_reason() {
		$state = self::state();
		return self::paused_until() ? (string) $state['pause_reason'] : '';
	}

	/**
	 * Emails a page load sends before queueing the rest.
	 *
	 * @return int
	 */
	public static function inline_limit() {
		/**
		 * Filters how many emails one page load sends before queueing the rest.
		 *
		 * @param int $limit Emails. Default 10.
		 */
		return max( 1, (int) apply_filters( 'emailit_inline_limit', 10 ) );
	}

	/**
	 * Seconds the last email held back by decide() or daily_room() should wait.
	 *
	 * @var int
	 */
	private static $defer = 0;

	/**
	 * The wait for the email decide() or daily_room() just held back, and reset it.
	 *
	 * @return int Seconds.
	 */
	public static function take_defer() {
		$defer       = self::$defer;
		self::$defer = 0;
		return $defer;
	}

	/**
	 * An error when an email has more recipients than the whole daily limit, which no wait fixes.
	 *
	 * @param int $recipients Recipients.
	 * @return Send_Error|null
	 */
	public static function too_big( $recipients ) {
		$state = self::state();
		$limit = (int) $state['daily_limit'];
		if ( $limit > 0 && $recipients > $limit ) {
			/* translators: 1: number of recipients, 2: daily limit. */
			return Send_Error::make( sprintf( __( 'This email has %1$d recipients, more than your workspace\'s daily sending limit of %2$d.', 'emailit' ), $recipients, $limit ) );
		}
		return null;
	}

	/**
	 * Whether today's limit has room for an email.
	 *
	 * @param int $recipients Recipients.
	 * @return string 'ok'; 'defer' when only this email doesn't fit (see take_defer()); 'pause' when the limit is used up.
	 */
	public static function daily_room( $recipients ) {
		$remaining = self::daily_remaining();
		if ( null === $remaining || $remaining >= $recipients ) {
			return 'ok';
		}
		$wait = self::seconds_until_reset() + wp_rand( 30, 300 );
		if ( $remaining <= 0 ) {
			self::pause( $wait, 'daily' );
			return 'pause';
		}
		self::$defer = $wait;
		return 'defer';
	}

	/**
	 * Seconds until the daily limit resets, from the last response or 00:00 UTC.
	 *
	 * @return int
	 */
	private static function seconds_until_reset() {
		$state = self::state();
		$reset = (int) $state['daily_reset_at'] - time();
		return $reset > 0 ? $reset : self::seconds_until_utc_midnight();
	}

	/**
	 * Decide whether an email is sent during this page load or queued.
	 *
	 * @param int $recipients Recipients.
	 * @return string 'send' or 'queue'.
	 */
	public static function decide( $recipients ) {
		if ( self::paused_until() ) {
			return 'queue';
		}
		if ( 'ok' !== self::daily_room( $recipients ) ) {
			return 'queue';
		}
		if ( self::$sent_in_request >= self::inline_limit() ) {
			return 'queue';
		}
		/**
		 * Filters how long one page load may wait in total for the per-second limit.
		 *
		 * @param float $seconds Seconds. Default 2.
		 */
		$budget = (float) apply_filters( 'emailit_inline_wait', 2.0 ) - self::$waited;
		return self::pace( $recipients, max( 0.0, $budget ) ) ? 'send' : 'queue';
	}

	/**
	 * Wait, if needed and allowed, until the email fits in the per-second limit.
	 *
	 * @param int   $recipients Recipients.
	 * @param float $max_wait   Longest wait in seconds.
	 * @return bool Whether it can be sent now.
	 */
	public static function pace( $recipients, $max_wait ) {
		$limit = self::per_second();
		$now   = microtime( true );
		self::prune( $now );
		$used = self::used();
		if ( 0 === $used || $used + $recipients <= $limit ) {
			return true;
		}
		if ( self::may_probe() ) {
			return true;
		}
		$wait = ( self::$window[0][0] + 1.0 ) - $now + 0.02;
		if ( $wait > $max_wait ) {
			return false;
		}
		usleep( (int) round( $wait * 1000000 ) );
		self::$waited += $wait;
		return self::pace( $recipients, $max_wait - $wait );
	}

	/**
	 * Over SMTP, try one email over the learned limit once an hour to find out whether it was raised.
	 * The API reports the limit in every response, so it never needs to.
	 *
	 * @return bool
	 */
	private static function may_probe() {
		if ( 'smtp' !== Settings::mailer() || 'custom' === Settings::get( 'rate_mode' ) ) {
			return false;
		}
		$state = self::state();
		if ( (int) $state['probed_at'] > time() - HOUR_IN_SECONDS ) {
			return false;
		}
		$state['probed_at'] = time();
		self::save( $state );
		return true;
	}

	/**
	 * Recipients sent in the last second by this process.
	 *
	 * @return int
	 */
	private static function used() {
		$used = 0;
		foreach ( self::$window as $entry ) {
			$used += $entry[1];
		}
		return $used;
	}

	/**
	 * Drop window entries older than a second.
	 *
	 * @param float $now Microtime.
	 * @return void
	 */
	private static function prune( $now ) {
		while ( ! empty( self::$window ) && self::$window[0][0] <= $now - 1.0 ) {
			array_shift( self::$window );
		}
	}

	/**
	 * Record a successful send.
	 *
	 * @param int               $recipients Recipients.
	 * @param bool              $inline     Sent during a page load.
	 * @param Api_Response|null $response   API response with rate limit headers.
	 * @return void
	 */
	public static function record_sent( $recipients, $inline, $response = null ) {
		$now = microtime( true );
		self::prune( $now );
		$before         = self::used();
		self::$window[] = array( $now, max( 1, (int) $recipients ) );
		if ( $inline ) {
			++self::$sent_in_request;
		}

		$state   = self::state();
		$changed = false;
		if ( '' !== $state['pause_reason'] || '' !== $state['last_error'] ) {
			$state['pause_reason'] = '';
			$state['paused_until'] = 0;
			$state['last_error']   = '';
			$changed               = true;
		}
		if ( $response ) {
			$changed = self::read_headers( $state, $response ) || $changed;
		} elseif ( 'custom' !== Settings::get( 'rate_mode' ) && $before + 1 > self::per_second() ) {
			// SMTP: accepted although the learned limit was used up, so it was raised.
			$state['per_second'] = $before + 1;
			$changed             = true;
		}
		if ( $changed ) {
			self::save( $state );
		}
	}

	/**
	 * Take the limits from an API response's headers.
	 *
	 * @param array<string, mixed> $state    State, changed in place.
	 * @param Api_Response         $response Response.
	 * @return bool Whether anything changed.
	 */
	private static function read_headers( array &$state, Api_Response $response ) {
		$before = $state;
		if ( '' !== $response->header( 'ratelimit-limit' ) && (int) $response->header( 'ratelimit-limit' ) > 0 ) {
			$state['per_second'] = (int) $response->header( 'ratelimit-limit' );
		}
		if ( '' !== $response->header( 'ratelimit-daily-limit' ) ) {
			$state['daily_limit'] = (int) $response->header( 'ratelimit-daily-limit' );
		}
		if ( '' !== $response->header( 'ratelimit-daily-remaining' ) ) {
			$state['daily_remaining'] = (int) $response->header( 'ratelimit-daily-remaining' );
			$reset                    = (int) $response->header( 'ratelimit-daily-reset' );
			$state['daily_reset_at']  = time() + ( $reset > 0 ? $reset : self::seconds_until_utc_midnight() );
		}
		return $before !== $state;
	}

	/**
	 * Learn from a failed send and pause when that helps.
	 *
	 * @param Send_Error        $error    What went wrong.
	 * @param Api_Response|null $response API response, if any.
	 * @return int Seconds to wait before trying this email again.
	 */
	public static function record_error( Send_Error $error, $response = null ) {
		$state               = self::state();
		$state['last_error'] = $error->message;
		if ( $response ) {
			self::read_headers( $state, $response );
		}
		$delay = 0;

		switch ( $error->type ) {
			case Send_Error::RATE:
				if ( $error->limit ) {
					$state['per_second'] = $error->limit;
				}
				$state['probed_at'] = time();
				self::$window[]     = array( microtime( true ), self::per_second() );
				$delay              = max( 1, $error->retry_after );
				break;

			case Send_Error::DAILY:
				if ( $error->limit ) {
					$state['daily_limit'] = $error->limit;
				}
				$delay                    = max( 60, $error->retry_after ) + wp_rand( 30, 300 );
				$state['daily_reset_at']  = time() + $delay;
				$state['daily_remaining'] = null !== $error->remaining ? max( 0, (int) $error->remaining ) : 0;
				// Only a used-up limit stops everything; an email too big for what's left waits alone.
				if ( 0 === $state['daily_remaining'] ) {
					$state['paused_until'] = time() + $delay;
					$state['pause_reason'] = 'daily';
				}
				break;

			case Send_Error::AUTH:
				$delay                 = 5 * MINUTE_IN_SECONDS;
				$state['paused_until'] = time() + $delay;
				$state['pause_reason'] = 'auth';
				break;

			case Send_Error::CREDITS:
				$delay                 = 15 * MINUTE_IN_SECONDS;
				$state['paused_until'] = time() + $delay;
				$state['pause_reason'] = 'credits';
				break;

			case Send_Error::CONNECTION:
				$delay                 = MINUTE_IN_SECONDS;
				$state['paused_until'] = time() + $delay;
				$state['pause_reason'] = 'connection';
				break;

			case Send_Error::TEMPORARY:
				$delay = max( MINUTE_IN_SECONDS, $error->retry_after );
				break;
		}

		self::save( $state );
		return $delay;
	}

	/**
	 * Pause sending.
	 *
	 * @param int    $seconds Seconds.
	 * @param string $reason  Reason.
	 * @return void
	 */
	public static function pause( $seconds, $reason ) {
		$state                 = self::state();
		$state['paused_until'] = time() + (int) $seconds;
		$state['pause_reason'] = $reason;
		self::save( $state );
	}

	/**
	 * Resume sending now.
	 *
	 * @return void
	 */
	public static function clear_pause() {
		$state                    = self::state( true );
		$state['paused_until']    = 0;
		$state['pause_reason']    = '';
		$state['daily_remaining'] = null;
		self::save( $state );
	}

	/**
	 * Seconds until the daily limit resets at 00:00 UTC.
	 *
	 * @return int
	 */
	public static function seconds_until_utc_midnight() {
		$now      = time();
		$midnight = (int) gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ) + 1, (int) gmdate( 'Y', $now ) );
		return max( 1, $midnight - $now );
	}

	/**
	 * Start counting a new request (queue runs and tests).
	 *
	 * @return void
	 */
	public static function reset_request() {
		self::$sent_in_request = 0;
		self::$waited          = 0.0;
		self::$state           = null;
	}
}
