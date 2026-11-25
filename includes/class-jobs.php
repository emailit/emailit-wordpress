<?php
/**
 * Background API jobs.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * API calls that don't need to happen during the page load: saving contacts,
 * subscribing to audiences, sending events, syncing orders and products.
 *
 * Jobs run on the same WP-Cron queue as emails and are retried on 429, 5xx
 * and network errors. A job with a dedupe key replaces a queued job with the
 * same key, so an order saved five times is sent once.
 */
final class Jobs {

	/**
	 * Handlers by job type.
	 *
	 * @var array<string, callable>
	 */
	private static $handlers = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'emailit_jobs';
	}

	/**
	 * Register a handler. It gets the payload and returns null when done or a Send_Error.
	 *
	 * @param string   $type    Job type.
	 * @param callable $handler Handler.
	 * @return void
	 */
	public static function register( $type, callable $handler ) {
		self::$handlers[ $type ] = $handler;
	}

	/**
	 * Queue a job.
	 *
	 * @param string               $type    Job type.
	 * @param array<string, mixed> $payload Data for the handler.
	 * @param string|null          $dedupe  Replaces a queued job with the same key.
	 * @param int                  $delay   Seconds before it runs.
	 * @return int Job ID.
	 */
	public static function add( $type, array $payload, $dedupe = null, $delay = 0 ) {
		global $wpdb;
		if ( ! Settings::is_configured() ) {
			return 0;
		}
		$table = esc_sql( $wpdb->prefix . 'emailit_jobs' );
		$now   = Email_Log::time();
		$at    = Email_Log::time( $delay );
		$json  = (string) wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );

		if ( null !== $dedupe ) {
			$dedupe   = substr( $type . ':' . $dedupe, 0, 191 );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, payload FROM {$table} WHERE dedupe = %s AND status = 'queued' LIMIT 1", $dedupe ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			if ( $existing ) {
				// Newer values win, earlier flags (like "new order") stay.
				$old  = json_decode( (string) $existing->payload, true );
				$json = (string) wp_json_encode( array_merge( is_array( $old ) ? $old : array(), $payload ), JSON_INVALID_UTF8_SUBSTITUTE );
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
					$table,
					array(
						'payload'         => $json,
						'next_attempt_at' => $at,
						'updated_at'      => $now,
					),
					array( 'id' => $existing->id )
				);
				Plugin::schedule_queue();
				return (int) $existing->id;
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			$table,
			array(
				'type'            => $type,
				'dedupe'          => $dedupe,
				'payload'         => $json,
				'status'          => 'queued',
				'next_attempt_at' => $at,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		Plugin::schedule_queue();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Run due jobs until the deadline.
	 *
	 * @param float $deadline Microtime.
	 * @return void
	 */
	public static function run( $deadline ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_jobs' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued' WHERE status = 'running' AND updated_at < %s", Email_Log::time( -10 * MINUTE_IN_SECONDS ) ) );
		while ( microtime( true ) < $deadline ) {
			$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'queued' AND next_attempt_at <= %s ORDER BY id ASC LIMIT 25", Email_Log::time() ) );
			if ( ! $jobs ) {
				break;
			}
			foreach ( $jobs as $job ) {
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
				$claimed = $wpdb->update(
					$table,
					array(
						'status'     => 'running',
						'updated_at' => Email_Log::time(),
					),
					array(
						'id'     => $job->id,
						'status' => 'queued',
					)
				);
				if ( $claimed ) {
					self::execute( $job );
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Run one job.
	 *
	 * @param object $job Job row.
	 * @return void
	 */
	private static function execute( $job ) {
		global $wpdb;
		$table   = esc_sql( $wpdb->prefix . 'emailit_jobs' );
		$payload = json_decode( (string) $job->payload, true );
		$error   = null;

		if ( ! isset( self::$handlers[ $job->type ] ) ) {
			$error = Send_Error::make( 'No handler for job type ' . $job->type );
		} else {
			try {
				$error = call_user_func( self::$handlers[ $job->type ], is_array( $payload ) ? $payload : array() );
			} catch ( \Throwable $e ) {
				$error = Send_Error::make( $e->getMessage() );
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		if ( ! $error instanceof Send_Error ) {
			$wpdb->delete( $table, array( 'id' => $job->id ) );
			return;
		}

		$attempts = (int) $job->attempts + 1;
		$delays   = Dispatcher::backoff();
		if ( $error->is_retryable() && Send_Error::AUTH !== $error->type && $attempts <= count( $delays ) ) {
			$wait = max( $error->retry_after, (int) $delays[ $attempts - 1 ] );
			$wpdb->update(
				$table,
				array(
					'status'          => 'queued',
					'attempts'        => $attempts,
					'last_error'      => $error->message,
					'next_attempt_at' => Email_Log::time( $wait ),
					'updated_at'      => Email_Log::time(),
				),
				array( 'id' => $job->id )
			);
			return;
		}
		$wpdb->update(
			$table,
			array(
				'status'     => 'failed',
				'attempts'   => $attempts,
				'last_error' => $error->message,
				'updated_at' => Email_Log::time(),
			),
			array( 'id' => $job->id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Turn an API response into a job result.
	 *
	 * @param Api_Response $response Response.
	 * @return Send_Error|null
	 */
	public static function result( Api_Response $response ) {
		return $response->ok() ? null : Send_Error::from_api( $response );
	}

	/**
	 * Queued jobs.
	 *
	 * @return int
	 */
	public static function count_queued() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_jobs' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','running')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Recent failed jobs, for the Integrations screen.
	 *
	 * @param int $limit Rows.
	 * @return array<int, object>
	 */
	public static function failures( $limit = 10 ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_jobs' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, attempts, last_error, updated_at FROM {$table} WHERE status = 'failed' ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Delete failed jobs older than a week.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_jobs' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'failed' AND updated_at < %s", Email_Log::time( -7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Delete all failed jobs.
	 *
	 * @return void
	 */
	public static function clear_failed() {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'status' => 'failed' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}
}
