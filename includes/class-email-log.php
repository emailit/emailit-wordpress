<?php
/**
 * Email log and queue table.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One row per email: the queue (queued, sending) and the log (sent, failed,
 * and then delivered, bounced and so on from webhooks).
 *
 * The message column holds the email while it may still be sent. After it's
 * sent it keeps the content without attachments if "Save email content" is on.
 */
final class Email_Log {

	const DB_VERSION = '1';

	/**
	 * Statuses in the order an email moves through them. Later webhook events don't move an email back.
	 *
	 * @var array<string, int>
	 */
	const RANK = array(
		'queued'     => 0,
		'sending'    => 1,
		'failed'     => 2,
		'sent'       => 3,
		'held'       => 4,
		'delivered'  => 5,
		'opened'     => 6,
		'clicked'    => 7,
		'bounced'    => 8,
		'rejected'   => 8,
		'complained' => 9,
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'emailit_emails';
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$emails  = self::table();
		$jobs    = Jobs::table();
		dbDelta(
			"CREATE TABLE {$emails} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(16) NOT NULL DEFAULT 'queued',
				mailer varchar(8) NOT NULL DEFAULT 'api',
				recipients text NOT NULL,
				subject text NOT NULL,
				from_address varchar(255) NOT NULL DEFAULT '',
				source varchar(100) NOT NULL DEFAULT '',
				emailit_id varchar(64) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				reason varchar(32) NOT NULL DEFAULT '',
				last_error text NULL,
				message longtext NULL,
				events text NULL,
				next_attempt_at datetime NULL,
				sent_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_next (status,next_attempt_at),
				KEY emailit_id (emailit_id),
				KEY created_at (created_at),
				KEY source (source)
			) {$charset};
			CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(40) NOT NULL,
				dedupe varchar(191) NULL,
				payload longtext NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'queued',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				next_attempt_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_next (status,next_attempt_at),
				KEY dedupe (dedupe)
			) {$charset};"
		);
		update_option( 'emailit_db_version', self::DB_VERSION, true );
	}

	/**
	 * Install when the tables are missing or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( 'emailit_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * UTC time for the database.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function time( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * Text the database accepts: invalid UTF-8 replaced.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function utf8( $text ) {
		$text = (string) $text;
		if ( '' === $text || preg_match( '//u', $text ) ) {
			return $text;
		}
		return function_exists( 'mb_scrub' ) ? mb_scrub( $text, 'UTF-8' ) : (string) wp_check_invalid_utf8( $text, true );
	}

	/**
	 * WordPress emails that carry login, reset or confirmation links. Their content is never kept
	 * after sending and can't be read or sent to another address from the log.
	 *
	 * @param string $source Source slug.
	 * @return bool
	 */
	public static function is_sensitive( $source ) {
		return in_array(
			(string) $source,
			array( 'wordpress:password-reset', 'wordpress:new-user', 'wordpress:email-change', 'wordpress:admin-email-change', 'wordpress:recovery-mode', 'wordpress:privacy-request', 'wordpress:privacy-export', 'wordpress:signup' ),
			true
		);
	}

	/**
	 * Whether the current user may read an email's content and send it again.
	 * On multisite only super admins may, because a site's log can hold emails to network users.
	 *
	 * @param object $row Email log row.
	 * @return bool
	 */
	public static function can_view_content( $row ) {
		if ( self::is_sensitive( isset( $row->source ) ? $row->source : '' ) ) {
			return false;
		}
		return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Add a row for a new email.
	 *
	 * @param Message $message The email.
	 * @param string  $status  Status.
	 * @return int Row ID, 0 on failure.
	 */
	public static function create( Message $message, $status ) {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			self::table(),
			array(
				'status'       => $status,
				'mailer'       => Settings::mailer(),
				'recipients'   => self::utf8( $message->to_display() ),
				'subject'      => self::utf8( (string) $message->get( 'subject' ) ),
				'from_address' => substr( (string) $message->get( 'from' ), 0, 255 ),
				'source'       => substr( (string) $message->get( 'source' ), 0, 100 ),
				'created_at'   => self::time(),
				'updated_at'   => self::time(),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row.
	 *
	 * @param int                  $id     Row ID.
	 * @param array<string, mixed> $fields Columns.
	 * @return void
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		if ( ! $id ) {
			return;
		}
		$fields['updated_at'] = self::time();
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Put an email in the queue.
	 *
	 * @param int     $id       Row ID (0 to create one).
	 * @param Message $message  The email.
	 * @param string  $reason   Why: throttled, daily, rate, connection, auth, credits, temporary.
	 * @param int     $delay    Seconds until the next attempt.
	 * @param string  $error    Last error.
	 * @param int     $attempts Attempts so far.
	 * @return int Row ID.
	 */
	public static function queue( $id, Message $message, $reason, $delay = 0, $error = '', $attempts = 0 ) {
		global $wpdb;
		if ( ! $id ) {
			$id = self::create( $message, 'queued' );
		}
		if ( ! $id ) {
			return 0;
		}
		$message->persist_files();
		$at      = max( time() + (int) $delay, Throttle::paused_until() );
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			self::table(),
			array(
				'status'          => 'queued',
				'recipients'      => self::utf8( $message->to_display() ),
				'reason'          => substr( (string) $reason, 0, 32 ),
				'last_error'      => self::utf8( (string) $error ),
				'attempts'        => (int) $attempts,
				'message'         => $message->to_json(),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $at ),
				'updated_at'      => self::time(),
			),
			array( 'id' => (int) $id )
		);
		if ( false === $updated ) {
			$message->delete_files();
			return 0;
		}
		Plugin::schedule_queue();
		/**
		 * Fires when an email is queued.
		 *
		 * @param int     $id      Email log row.
		 * @param Message $message The email.
		 * @param string  $reason  Why.
		 */
		do_action( 'emailit_email_queued', $id, $message, $reason );
		return $id;
	}

	/**
	 * Mark an email sent.
	 *
	 * @param int      $id       Row ID.
	 * @param Message  $message  The email.
	 * @param Delivery $delivery Result.
	 * @param int      $attempts Attempts.
	 * @return void
	 */
	public static function sent( $id, Message $message, Delivery $delivery, $attempts ) {
		$message->delete_files();
		if ( ! Settings::get( 'log' ) ) {
			self::delete( array( $id ) );
			return;
		}
		$fields = array(
			'status'          => 'sent',
			'emailit_id'      => $delivery->emailit_id,
			'attempts'        => (int) $attempts,
			'reason'          => '',
			'last_error'      => '',
			'next_attempt_at' => null,
			'sent_at'         => self::time(),
			'message'         => Settings::get( 'log_content' ) && ! self::is_sensitive( (string) $message->get( 'source' ) ) ? $message->without_attachments()->to_json() : null,
		);
		self::update( $id, $fields );
	}

	/**
	 * Mark an email failed. Its content stays so it can be retried from the log.
	 *
	 * @param int        $id       Row ID.
	 * @param Message    $message  The email.
	 * @param Send_Error $error    Error.
	 * @param int        $attempts Attempts.
	 * @return void
	 */
	public static function failed( $id, Message $message, Send_Error $error, $attempts ) {
		$message->persist_files();
		self::update(
			$id,
			array(
				'status'          => 'failed',
				'reason'          => $error->type,
				'last_error'      => self::utf8( $error->message ),
				'attempts'        => (int) $attempts,
				'next_attempt_at' => null,
				'message'         => $message->to_json(),
			)
		);
	}

	/**
	 * A row.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Rows due for sending.
	 *
	 * @param int $limit Rows.
	 * @return array<int, object>
	 */
	public static function due( $limit ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'queued' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d", self::time(), $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Claim a queued row for sending.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function claim( $id ) {
		global $wpdb;
		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			self::table(),
			array(
				'status'     => 'sending',
				'updated_at' => self::time(),
			),
			array(
				'id'     => (int) $id,
				'status' => 'queued',
			)
		);
	}

	/**
	 * Put rows a crashed run left in "sending" back in the queue.
	 *
	 * @return void
	 */
	public static function release_stuck() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$old   = self::time( -10 * MINUTE_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued' WHERE status = 'sending' AND message IS NOT NULL AND updated_at < %s", $old ) );
		// A page load that died while sending leaves a row without content: nobody can tell whether it went out.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'failed', last_error = %s WHERE status = 'sending' AND message IS NULL AND updated_at < %s", __( 'The page load stopped while this email was being sent, so it may not have gone out.', 'emailit' ), $old ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}


	/**
	 * Count rows with a status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count( $status ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Counts for every status.
	 *
	 * @return array<string, int>
	 */
	public static function counts() {
		global $wpdb;
		$table  = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$counts[ $row->status ] = (int) $row->n;
		}
		return $counts;
	}

	/**
	 * Rows for the log screen.
	 *
	 * @param array<string, mixed> $args status, source, search, per_page, page.
	 * @return array{rows: array<int, object>, total: int}
	 */
	public static function search( array $args ) {
		global $wpdb;
		$table    = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$source   = isset( $args['source'] ) ? (string) $args['source'] : '';
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$problems = 'problems' === $status ? 1 : 0;
		$exact    = $problems ? '' : $status;
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		// Every filter is in the query; an empty value switches it off.
		$filters = array( $exact, $exact, $problems, $source, $source, $search, $like, $like, $like );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; the values are passed as an array.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ( %s = '' OR status = %s ) AND ( %d = 0 OR status IN ('failed','bounced','rejected','complained') ) AND ( %s = '' OR source = %s ) AND ( %s = '' OR recipients LIKE %s OR subject LIKE %s OR emailit_id LIKE %s )",
				$filters
			)
		);
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, mailer, recipients, subject, from_address, source, emailit_id, attempts, reason, last_error, next_attempt_at, sent_at, created_at, updated_at, (message IS NOT NULL) AS has_message FROM {$table} WHERE ( %s = '' OR status = %s ) AND ( %d = 0 OR status IN ('failed','bounced','rejected','complained') ) AND ( %s = '' OR source = %s ) AND ( %s = '' OR recipients LIKE %s OR subject LIKE %s OR emailit_id LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $filters, array( $per_page, $offset ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Sources that sent email, for the log filter.
	 *
	 * @return array<int, string>
	 */
	public static function sources() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		return array_filter( (array) $wpdb->get_col( "SELECT DISTINCT source FROM {$table} ORDER BY source" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
	}

	/**
	 * Queue rows again.
	 *
	 * @param array<int, int>|null $ids Row IDs, or null for every failed row.
	 * @return int Rows changed.
	 */
	public static function retry( $ids = null ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, placeholders built from the ID count.
		if ( null === $ids ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', attempts = 0, next_attempt_at = %s, updated_at = %s WHERE status = 'failed' AND message IS NOT NULL", self::time(), self::time() ) );
		} else {
			$ids = array_filter( array_map( 'absint', $ids ) );
			if ( ! $ids ) {
				return 0;
			}
			$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued', attempts = 0, next_attempt_at = %s, updated_at = %s WHERE id IN ({$in}) AND status IN ('queued','failed') AND message IS NOT NULL", array_merge( array( self::time(), self::time() ), $ids ) ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		if ( $changed ) {
			Throttle::clear_pause();
			Plugin::schedule_queue();
		}
		return (int) $changed;
	}

	/**
	 * Make every queued email due now.
	 *
	 * @return void
	 */
	public static function send_queued_now() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_attempt_at = %s WHERE status = 'queued'", self::time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		Throttle::clear_pause();
		Plugin::schedule_queue();
	}

	/**
	 * Delete rows and their attachment copies.
	 *
	 * @param array<int, int>|string $which Row IDs, or a status.
	 * @return int Rows deleted.
	 */
	public static function delete( $which ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; $where is prepared.
		if ( is_string( $which ) ) {
			$where = $wpdb->prepare( 'status = %s', $which );
		} else {
			$ids = array_filter( array_map( 'absint', (array) $which ) );
			if ( ! $ids ) {
				return 0;
			}
			$where = $wpdb->prepare( 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids );
		}
		foreach ( (array) $wpdb->get_col( "SELECT message FROM {$table} WHERE {$where} AND message LIKE '%emailit-queue%'" ) as $json ) {
			$message = Message::from_json( (string) $json );
			if ( $message ) {
				$message->delete_files();
			}
		}
		$deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return $deleted;
	}

	/**
	 * Apply a webhook event to the email it belongs to.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return bool Whether an email was found.
	 */
	public static function apply_event( array $event ) {
		global $wpdb;
		$type   = isset( $event['type'] ) ? (string) $event['type'] : '';
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : ( isset( $event['object'] ) && is_array( $event['object'] ) ? $event['object'] : array() );
		$status = array(
			'email.delivered'  => 'delivered',
			'email.bounced'    => 'bounced',
			'email.complained' => 'complained',
			'email.rejected'   => 'rejected',
			'email.failed'     => 'failed',
			'email.suppressed' => 'rejected',
			'email.held'       => 'held',
			'email.loaded'     => 'opened',
			'email.clicked'    => 'clicked',
		);
		if ( ! isset( $status[ $type ] ) ) {
			return false;
		}

		$meta  = isset( $object['meta'] ) && is_array( $object['meta'] ) ? $object['meta'] : array();
		$table = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$row   = null;
		if ( isset( $meta['wp_log_id'], $meta['wp_site'] ) && Settings::site_id() === (string) $meta['wp_site'] ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, events FROM {$table} WHERE id = %d", (int) $meta['wp_log_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		}
		if ( ! $row && ! empty( $object['id'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, events FROM {$table} WHERE emailit_id = %s", (string) $object['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		}
		if ( ! $row ) {
			return false;
		}

		$events   = json_decode( (string) $row->events, true );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'type' => $type,
			'at'   => isset( $event['created_at'] ) ? (string) $event['created_at'] : ( isset( $object['updated_at'] ) ? (string) $object['updated_at'] : gmdate( 'c' ) ),
			'to'   => isset( $object['to'] ) ? ( is_array( $object['to'] ) ? implode( ', ', $object['to'] ) : (string) $object['to'] ) : '',
			'id'   => isset( $object['id'] ) ? (string) $object['id'] : '',
		);
		$events   = array_slice( $events, -30 );

		$fields = array( 'events' => wp_json_encode( $events ) );
		$new    = $status[ $type ];
		$old    = (string) $row->status;
		if ( ! isset( self::RANK[ $old ] ) || self::RANK[ $new ] >= self::RANK[ $old ] || in_array( $old, array( 'sending', 'queued' ), true ) ) {
			$fields['status'] = $new;
		}
		self::update( (int) $row->id, $fields );
		return true;
	}

	/**
	 * Daily cleanup of old log rows.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		$table    = esc_sql( $wpdb->prefix . 'emailit_emails' );
		$days     = max( 1, (int) Settings::get( 'log_days' ) );
		$cutoff   = self::time( -$days * DAY_IN_SECONDS );
		$deadline = microtime( true ) + 20;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status NOT IN ('queued','sending') AND created_at < %s LIMIT 500", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			if ( $ids ) {
				self::delete( array_map( 'intval', $ids ) );
			}
			$more = 500 === count( $ids );
		} while ( $more && microtime( true ) < $deadline );
		Jobs::cleanup();
		if ( self::count( 'queued' ) || self::count( 'sending' ) || Jobs::count_queued() ) {
			Plugin::schedule_queue();
		}
	}
}
