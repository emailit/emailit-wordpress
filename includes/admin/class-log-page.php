<?php
/**
 * Emailit > Email log.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Admin;

use Emailit\WordPress\Email_Log;
use Emailit\WordPress\Message;
use Emailit\WordPress\Settings;
use Emailit\WordPress\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Every email WordPress sent, with its status, and the queue.
 */
final class Log_Page {

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'queued'     => __( 'Queued', 'emailit' ),
			'sending'    => __( 'Sending', 'emailit' ),
			'sent'       => __( 'Sent', 'emailit' ),
			'failed'     => __( 'Failed', 'emailit' ),
			'held'       => __( 'Held', 'emailit' ),
			'delivered'  => __( 'Delivered', 'emailit' ),
			'opened'     => __( 'Opened', 'emailit' ),
			'clicked'    => __( 'Clicked', 'emailit' ),
			'bounced'    => __( 'Bounced', 'emailit' ),
			'rejected'   => __( 'Rejected', 'emailit' ),
			'complained' => __( 'Complained', 'emailit' ),
		);
	}

	/**
	 * Render the list or one email.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		Email_Log::maybe_install();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$email = isset( $_GET['email'] ) ? absint( $_GET['email'] ) : 0;
		echo '<div class="wrap emailit">';
		if ( $email ) {
			self::detail( $email );
		} else {
			self::table();
		}
		echo '</div>';
	}

	/**
	 * The list.
	 *
	 * @return void
	 */
	private static function table() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters and paging only.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$labels = self::statuses();
		if ( '' !== $status && 'problems' !== $status && ! isset( $labels[ $status ] ) ) {
			$status = '';
		}
		$per_page = 50;
		$result   = Email_Log::search(
			array(
				'status'   => $status,
				'source'   => $source,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);
		$counts   = Email_Log::counts();
		$pages    = max( 1, (int) ceil( $result['total'] / $per_page ) );

		Settings_Page::header( __( 'Email log', 'emailit' ) );
		?>
		<ul class="subsubsub">
			<?php
			$views = array( '' => __( 'All', 'emailit' ) ) + array_intersect_key( $labels, $counts );
			if ( ! empty( $counts['failed'] ) || ! empty( $counts['bounced'] ) || ! empty( $counts['complained'] ) || ! empty( $counts['rejected'] ) ) {
				$views['problems'] = __( 'Problems', 'emailit' );
			}
			$links = array();
			foreach ( $views as $key => $label ) {
				$count   = '' === $key ? array_sum( $counts ) : ( 'problems' === $key ? ( ( isset( $counts['failed'] ) ? $counts['failed'] : 0 ) + ( isset( $counts['bounced'] ) ? $counts['bounced'] : 0 ) + ( isset( $counts['complained'] ) ? $counts['complained'] : 0 ) + ( isset( $counts['rejected'] ) ? $counts['rejected'] : 0 ) ) : $counts[ $key ] );
				$links[] = sprintf(
					'<li><a href="%1$s" class="%2$s">%3$s <span class="count">(%4$s)</span></a>',
					esc_url( Admin::url( 'emailit-log', array_filter( array( 'status' => (string) $key ) ) ) ),
					$status === $key ? 'current' : '',
					esc_html( $label ),
					esc_html( number_format_i18n( $count ) )
				);
			}
			echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			?>
		</ul>

		<form method="get" class="emailit-filters">
			<input type="hidden" name="page" value="emailit-log" />
			<?php if ( '' !== $status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
			<?php endif; ?>
			<select name="source" aria-label="<?php esc_attr_e( 'Sent by', 'emailit' ); ?>">
				<option value=""><?php esc_html_e( 'Sent by anything', 'emailit' ); ?></option>
				<?php foreach ( Email_Log::sources() as $slug ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $source, $slug ); ?>><?php echo esc_html( Source::label( $slug ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Recipient, subject or Emailit ID', 'emailit' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'emailit' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="emailit-log-form">
			<input type="hidden" name="action" value="emailit_queue" />
			<?php wp_nonce_field( 'emailit_queue' ); ?>
			<div class="emailit-bulk">
				<button type="submit" name="do" value="retry" class="button"><?php esc_html_e( 'Send selected again', 'emailit' ); ?></button>
				<button type="submit" name="do" value="delete" class="button"><?php esc_html_e( 'Delete selected', 'emailit' ); ?></button>
				<?php if ( ! empty( $counts['queued'] ) ) : ?>
					<button type="submit" name="do" value="run" class="button button-primary"><?php esc_html_e( 'Send queued emails now', 'emailit' ); ?></button>
				<?php endif; ?>
				<?php if ( ! empty( $counts['failed'] ) ) : ?>
					<button type="submit" name="do" value="retry_failed" class="button"><?php esc_html_e( 'Retry all failed', 'emailit' ); ?></button>
				<?php endif; ?>
			</div>
			<table class="wp-list-table widefat striped emailit-table">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'emailit' ); ?>" /></td>
						<th scope="col"><?php esc_html_e( 'Date', 'emailit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'emailit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subject', 'emailit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sent by', 'emailit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'emailit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $result['rows'] ) : ?>
						<tr><td colspan="6"><?php echo esc_html( Settings::get( 'log' ) ? __( 'No emails yet.', 'emailit' ) : __( 'The log is off, so only queued and failed emails are listed.', 'emailit' ) ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row->id ); ?>" aria-label="<?php esc_attr_e( 'Select', 'emailit' ); ?>" /></th>
							<td class="emailit-nowrap"><?php echo esc_html( self::date( $row->created_at ) ); ?></td>
							<td class="emailit-break"><?php echo esc_html( $row->recipients ); ?></td>
							<td><a href="<?php echo esc_url( Admin::url( 'emailit-log', array( 'email' => (string) $row->id ) ) ); ?>"><strong><?php echo esc_html( '' !== $row->subject ? $row->subject : __( '(no subject)', 'emailit' ) ); ?></strong></a></td>
							<td><?php echo esc_html( Source::label( $row->source ) ); ?></td>
							<td><?php self::badge( $row ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Status badge with what happens next.
	 *
	 * @param object $row Row.
	 * @return void
	 */
	public static function badge( $row ) {
		$labels = self::statuses();
		echo '<span class="emailit-badge is-' . esc_attr( $row->status ) . '">' . esc_html( isset( $labels[ $row->status ] ) ? $labels[ $row->status ] : $row->status ) . '</span>';
		if ( 'queued' === $row->status && $row->next_attempt_at ) {
			$at = strtotime( $row->next_attempt_at . ' UTC' );
			echo '<br /><span class="description">';
			if ( $at > time() ) {
				/* translators: %s: time until the next attempt. */
				echo esc_html( sprintf( __( 'next try in %s', 'emailit' ), human_time_diff( time(), $at ) ) );
			} else {
				esc_html_e( 'sending shortly', 'emailit' );
			}
			echo '</span>';
		}
		if ( in_array( $row->status, array( 'failed', 'queued' ), true ) && '' !== (string) $row->last_error ) {
			echo '<br /><span class="emailit-error">' . esc_html( wp_trim_words( (string) $row->last_error, 14 ) ) . '</span>';
		}
	}

	/**
	 * A UTC database date in the site's time zone.
	 *
	 * @param string|null $mysql_utc Date.
	 * @return string
	 */
	public static function date( $mysql_utc ) {
		$ts = $mysql_utc ? strtotime( $mysql_utc . ' UTC' ) : 0;
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
	}

	/**
	 * One email.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private static function detail( $id ) {
		$row = Email_Log::find( $id );
		Settings_Page::header( __( 'Email', 'emailit' ) );
		echo '<p><a href="' . esc_url( Admin::url( 'emailit-log' ) ) . '">&larr; ' . esc_html__( 'Email log', 'emailit' ) . '</a></p>';
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'This email isn\'t in the log any more.', 'emailit' ) . '</p>';
			return;
		}
		$message  = Message::from_json( $row->message );
		$events   = json_decode( (string) $row->events, true );
		$can_view = Email_Log::can_view_content( $row );
		?>
		<div class="emailit-layout">
			<div class="emailit-main">
				<div class="emailit-card">
					<h2><?php echo esc_html( '' !== $row->subject ? $row->subject : __( '(no subject)', 'emailit' ) ); ?></h2>
					<table class="form-table emailit-detail" role="presentation">
						<tr><th><?php esc_html_e( 'Status', 'emailit' ); ?></th><td><?php self::badge( $row ); ?></td></tr>
						<tr><th><?php esc_html_e( 'To', 'emailit' ); ?></th><td class="emailit-break"><?php echo esc_html( $row->recipients ); ?></td></tr>
						<tr><th><?php esc_html_e( 'From', 'emailit' ); ?></th><td><?php echo esc_html( $row->from_address ); ?></td></tr>
						<?php if ( $message && $message->get( 'reply_to', array() ) ) : ?>
							<tr><th><?php esc_html_e( 'Reply-To', 'emailit' ); ?></th><td><?php echo esc_html( implode( ', ', wp_list_pluck( (array) $message->get( 'reply_to', array() ), 0 ) ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Sent by', 'emailit' ); ?></th><td><?php echo esc_html( Source::label( $row->source ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Created', 'emailit' ); ?></th><td><?php echo esc_html( self::date( $row->created_at ) ); ?></td></tr>
						<?php if ( $row->sent_at ) : ?>
							<tr><th><?php esc_html_e( 'Accepted by Emailit', 'emailit' ); ?></th><td><?php echo esc_html( self::date( $row->sent_at ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Sent with', 'emailit' ); ?></th><td><?php echo esc_html( 'smtp' === $row->mailer ? __( 'SMTP relay', 'emailit' ) : __( 'API', 'emailit' ) ); ?></td></tr>
						<?php if ( '' !== $row->emailit_id ) : ?>
							<tr><th><?php esc_html_e( 'Emailit ID', 'emailit' ); ?></th><td><code><?php echo esc_html( $row->emailit_id ); ?></code></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Attempts', 'emailit' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $row->attempts ) ); ?></td></tr>
						<?php if ( '' !== (string) $row->last_error ) : ?>
							<tr><th><?php esc_html_e( 'Last error', 'emailit' ); ?></th><td class="emailit-error"><?php echo esc_html( $row->last_error ); ?> <?php echo wp_kses_post( Admin::hint( (string) $row->last_error ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( $message && $message->get( 'attachments', array() ) ) : ?>
							<tr><th><?php esc_html_e( 'Attachments', 'emailit' ); ?></th><td><?php echo esc_html( implode( ', ', wp_list_pluck( (array) $message->get( 'attachments', array() ), 'name' ) ) ); ?></td></tr>
						<?php endif; ?>
					</table>
				</div>

				<?php if ( is_array( $events ) && $events ) : ?>
					<div class="emailit-card">
						<h2><?php esc_html_e( 'Delivery events', 'emailit' ); ?></h2>
						<ul class="emailit-timeline">
							<?php foreach ( array_reverse( $events ) as $event ) : ?>
								<li><strong><?php echo esc_html( (string) $event['type'] ); ?></strong> <span class="description"><?php echo esc_html( self::date( gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $event['at'] ) ) ) ); ?></span> <?php echo esc_html( isset( $event['to'] ) ? (string) $event['to'] : '' ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $message && ! $can_view ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							Email_Log::is_sensitive( $row->source )
								? __( 'This email holds a login, reset or confirmation link, so its content isn\'t shown and it can\'t be sent to another address.', 'emailit' )
								: __( 'Only network administrators can read emails and send them again.', 'emailit' )
						);
						?>
					</p>
				<?php elseif ( $message ) : ?>
					<div class="emailit-card">
						<h2><?php esc_html_e( 'Content', 'emailit' ); ?></h2>
						<?php if ( $message->is_html() ) : ?>
							<?php // The sandbox blocks scripts, forms and navigation; double-encoding keeps the body's own entities as text. ?>
							<iframe class="emailit-preview" sandbox="" title="<?php esc_attr_e( 'Email preview', 'emailit' ); ?>" srcdoc="<?php echo htmlspecialchars( (string) $message->get( 'body' ), ENT_QUOTES, 'UTF-8', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped for the attribute with htmlspecialchars(); esc_attr() would decode existing entities. ?>"></iframe>
						<?php else : ?>
							<pre class="emailit-pre"><?php echo esc_html( (string) $message->get( 'body' ) ); ?></pre>
						<?php endif; ?>
					</div>
				<?php elseif ( ! in_array( $row->status, array( 'queued', 'sending', 'failed' ), true ) ) : ?>
					<p class="description"><?php esc_html_e( 'The content of sent emails isn\'t saved. Turn on "Save their content" in the settings to keep it.', 'emailit' ); ?></p>
				<?php endif; ?>
			</div>
			<aside class="emailit-side">
				<?php if ( $message && $can_view ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="emailit-card">
						<h2><?php esc_html_e( 'Send again', 'emailit' ); ?></h2>
						<input type="hidden" name="action" value="emailit_email" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
						<input type="hidden" name="do" value="resend" />
						<?php wp_nonce_field( 'emailit_email' ); ?>
						<p><label for="emailit-resend-to"><?php esc_html_e( 'To another address (optional)', 'emailit' ); ?></label><br />
						<input type="email" id="emailit-resend-to" name="to" class="regular-text" placeholder="<?php echo esc_attr( $row->recipients ); ?>" /></p>
						<?php submit_button( __( 'Send again', 'emailit' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="emailit-card">
					<input type="hidden" name="action" value="emailit_email" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
					<input type="hidden" name="do" value="delete" />
					<?php wp_nonce_field( 'emailit_email' ); ?>
					<p><button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete from the log', 'emailit' ); ?></button></p>
				</form>
			</aside>
		</div>
		<?php
	}
}
