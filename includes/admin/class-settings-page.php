<?php
/**
 * Emailit > Settings.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Admin;

use Emailit\WordPress\Api;
use Emailit\WordPress\Email_Log;
use Emailit\WordPress\Jobs;
use Emailit\WordPress\Plugin;
use Emailit\WordPress\Settings;
use Emailit\WordPress\Throttle;
use Emailit\WordPress\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Status, connection, sender and delivery settings, and a test email.
 */
final class Settings_Page {

	/**
	 * The page header.
	 *
	 * @param string $title Title.
	 * @return void
	 */
	public static function header( $title ) {
		?>
		<h1 class="emailit-title">
			<svg class="emailit-mark" viewBox="0 0 67.8 53.2" aria-hidden="true" focusable="false"><path fill="#007b5e" fill-rule="evenodd" d="M33.82 26.46c7.45.24 14.34-6.45 32.62-19.58C64.48 2.82 60.31 0 55.52 0H12.12C7.33 0 3.17 2.82 1.2 6.88c18.29 13.12 25.18 19.82 32.62 19.58Z"/><path fill="#15c182" fill-rule="evenodd" d="M18.11 25.91c4.71 3.17 9.84 6.13 15.71 5.99 5.87.13 11-2.82 15.71-5.99 6.14-4.13 12.1-8.78 18.11-13.18v28.39c0 6.66-5.45 12.12-12.12 12.12H12.12C5.46 53.24 0 47.79 0 41.12V12.73c6.02 4.39 11.97 9.05 18.11 13.18Z"/></svg>
			<?php echo esc_html( $title ); ?>
		</h1>
		<?php
		Admin::show_flash();
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		Email_Log::maybe_install();
		$s        = Settings::all();
		$name     = Settings::OPTION;
		$key      = Settings::api_key();
		$constant = Settings::api_key_from_constant();
		?>
		<div class="wrap emailit">
			<?php self::header( __( 'Emailit', 'emailit' ) ); ?>
			<div class="emailit-layout">
				<div class="emailit-main">
					<?php self::status(); ?>
					<form method="post" action="options.php" class="emailit-card">
						<?php settings_fields( 'emailit' ); ?>
						<h2><?php esc_html_e( 'Connection', 'emailit' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="emailit-key"><?php esc_html_e( 'API key', 'emailit' ); ?></label></th>
								<td>
									<?php if ( $constant ) : ?>
										<code><?php echo esc_html( Settings::mask( $key ) ); ?></code>
										<p class="description"><?php esc_html_e( 'Set with EMAILIT_API_KEY in wp-config.php.', 'emailit' ); ?></p>
									<?php else : ?>
										<input type="password" id="emailit-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="<?php echo esc_attr( Settings::mask( $key ) ); ?>" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="secret_…" />
										<?php if ( '' !== $key ) : ?>
											<label class="emailit-inline"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[api_key_clear]" value="1" /> <?php esc_html_e( 'Remove', 'emailit' ); ?></label>
										<?php endif; ?>
										<?php if ( Settings::api_key_unreadable() ) : ?>
											<p class="description emailit-error"><?php esc_html_e( 'The saved key can\'t be read because the site\'s security keys changed. Paste the key again.', 'emailit' ); ?></p>
										<?php endif; ?>
										<p class="description">
											<?php
											printf(
												/* translators: 1: dashboard path, 2: constant example. */
												esc_html__( 'Create a key in Emailit under %1$s. A Full Access key enables contacts, audiences, WooCommerce sync and delivery tracking; a Sending Only key only sends email. To keep the key out of the database, add %2$s to wp-config.php instead.', 'emailit' ),
												'<strong>' . esc_html__( 'Email API > API Keys', 'emailit' ) . '</strong>',
												"<code>define( 'EMAILIT_API_KEY', 'secret_…' );</code>"
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Send with', 'emailit' ); ?></th>
								<td>
									<fieldset>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[mailer]" value="api" <?php checked( $s['mailer'], 'api' ); ?> /> <?php esc_html_e( 'Emailit API (recommended)', 'emailit' ); ?></label>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[mailer]" value="smtp" <?php checked( $s['mailer'], 'smtp' ); ?> /> <?php esc_html_e( 'SMTP relay', 'emailit' ); ?>
											<select name="<?php echo esc_attr( $name ); ?>[smtp_port]" aria-label="<?php esc_attr_e( 'SMTP port', 'emailit' ); ?>">
												<?php
												foreach ( array( 587, 465, 2525, 2587, 25 ) as $port ) {
													printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $port, selected( (int) $s['smtp_port'], $port, false ), esc_html( 465 === $port ? sprintf( '%d (TLS)', $port ) : sprintf( '%d (STARTTLS)', $port ) ) );
												}
												?>
											</select>
										</label>
										<p class="description"><?php esc_html_e( 'The API is faster, reports your limits so throttling is exact, and adds metadata so you can filter WordPress email in Emailit. Use SMTP only if your host blocks outgoing HTTPS requests.', 'emailit' ); ?></p>
									</fieldset>
								</td>
							</tr>
						</table>

						<h2><?php esc_html_e( 'Sender', 'emailit' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="emailit-from"><?php esc_html_e( 'From email', 'emailit' ); ?></label></th>
								<td>
									<input type="email" id="emailit-from" name="<?php echo esc_attr( $name ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" class="regular-text" placeholder="hello@example.com" />
									<p class="description"><?php esc_html_e( 'An address on a domain you verified in Emailit.', 'emailit' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="emailit-from-name"><?php esc_html_e( 'From name', 'emailit' ); ?></label></th>
								<td>
									<input type="text" id="emailit-from-name" name="<?php echo esc_attr( $name ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
									<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[force_from_name]" value="1" <?php checked( $s['force_from_name'] ); ?> /> <?php esc_html_e( 'Use this name for every email, even when a plugin sets its own', 'emailit' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Other From addresses', 'emailit' ); ?></th>
								<td>
									<fieldset>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[sender_mode]" value="domain" <?php checked( $s['sender_mode'], 'domain' ); ?> /> <?php esc_html_e( 'Replace addresses on domains that aren\'t verified in Emailit (recommended)', 'emailit' ); ?></label>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[sender_mode]" value="always" <?php checked( $s['sender_mode'], 'always' ); ?> /> <?php esc_html_e( 'Always send from my From email', 'emailit' ); ?></label>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[sender_mode]" value="never" <?php checked( $s['sender_mode'], 'never' ); ?> /> <?php esc_html_e( 'Keep what plugins set (only replace the WordPress default)', 'emailit' ); ?></label>
										<p class="description"><?php esc_html_e( 'When a plugin sends from another domain, for example a contact form using the visitor\'s address, the email goes out from your From email and replies still reach the original address.', 'emailit' ); ?></p>
									</fieldset>
								</td>
							</tr>
						</table>

						<h2><?php esc_html_e( 'Delivery', 'emailit' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Sending speed', 'emailit' ); ?></th>
								<td>
									<fieldset>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[rate_mode]" value="auto" <?php checked( $s['rate_mode'], 'auto' ); ?> /> <?php esc_html_e( 'Automatic: follow my workspace\'s limits (recommended)', 'emailit' ); ?></label>
										<label class="emailit-block"><input type="radio" name="<?php echo esc_attr( $name ); ?>[rate_mode]" value="custom" <?php checked( $s['rate_mode'], 'custom' ); ?> />
											<?php
											printf(
												/* translators: %s: number input. */
												esc_html__( 'At most %s emails per second', 'emailit' ),
												'<input type="number" min="1" max="1000" class="small-text" name="' . esc_attr( $name ) . '[rate_limit]" value="' . esc_attr( (string) $s['rate_limit'] ) . '" />'
											);
											?>
										</label>
										<p class="description"><?php esc_html_e( 'Emailit reports your per-second and daily limits with every email. Bursts are paced to the per-second limit, a page load sends up to 10 emails right away and queues the rest, and emails that wouldn\'t fit in today\'s limit wait in the queue until 00:00 UTC.', 'emailit' ); ?></p>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Retries', 'emailit' ); ?></th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[retry]" value="1" <?php checked( $s['retry'] ); ?> /> <?php esc_html_e( 'Queue emails that fail for a temporary reason and retry them for up to 30 hours', 'emailit' ); ?></label>
									<p class="description"><?php esc_html_e( 'Covers timeouts, network errors, 429 and 5xx responses, a rejected key and running out of credits. Retries reuse the same idempotency key, so an email is never delivered twice.', 'emailit' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="emailit-tracking"><?php esc_html_e( 'Open and click tracking', 'emailit' ); ?></label></th>
								<td>
									<select id="emailit-tracking" name="<?php echo esc_attr( $name ); ?>[tracking]">
										<option value="" <?php selected( $s['tracking'], '' ); ?>><?php esc_html_e( 'Use the sending domain\'s settings', 'emailit' ); ?></option>
										<option value="off" <?php selected( $s['tracking'], 'off' ); ?>><?php esc_html_e( 'Off for WordPress email', 'emailit' ); ?></option>
										<option value="opens" <?php selected( $s['tracking'], 'opens' ); ?>><?php esc_html_e( 'Opens only', 'emailit' ); ?></option>
										<option value="clicks" <?php selected( $s['tracking'], 'clicks' ); ?>><?php esc_html_e( 'Clicks only', 'emailit' ); ?></option>
										<option value="on" <?php selected( $s['tracking'], 'on' ); ?>><?php esc_html_e( 'Opens and clicks', 'emailit' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'API only. Click tracking rewrites links, including password reset links.', 'emailit' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Email log', 'emailit' ); ?></th>
								<td>
									<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[log]" value="1" <?php checked( $s['log'] ); ?> /> <?php esc_html_e( 'Log sent emails', 'emailit' ); ?></label>
									<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[log_content]" value="1" <?php checked( $s['log_content'] ); ?> /> <?php esc_html_e( 'Save their content, so you can read and resend them (attachments aren\'t kept)', 'emailit' ); ?></label>
									<label class="emailit-block">
										<?php
										printf(
											/* translators: %s: number input. */
											esc_html__( 'Delete log entries after %s days', 'emailit' ),
											'<input type="number" min="1" max="365" class="small-text" name="' . esc_attr( $name ) . '[log_days]" value="' . esc_attr( (string) $s['log_days'] ) . '" />'
										);
										?>
									</label>
									<p class="description"><?php esc_html_e( 'Queued and failed emails are always kept until they\'re sent or you delete them.', 'emailit' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button(); ?>
					</form>
				</div>
				<aside class="emailit-side">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="emailit-card">
						<h2><?php esc_html_e( 'Send a test email', 'emailit' ); ?></h2>
						<input type="hidden" name="action" value="emailit_test" />
						<?php wp_nonce_field( 'emailit_test' ); ?>
						<p><label for="emailit-test-to"><?php esc_html_e( 'Send to', 'emailit' ); ?></label><br />
						<input type="email" id="emailit-test-to" name="to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" required /></p>
						<p class="description"><?php esc_html_e( 'Until your workspace has production access, Emailit only delivers to the account emails of workspace members.', 'emailit' ); ?></p>
						<?php submit_button( __( 'Send test email', 'emailit' ), 'secondary', 'submit', false, Settings::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
					<div class="emailit-card">
						<h2><?php esc_html_e( 'Next steps', 'emailit' ); ?></h2>
						<ul class="emailit-list">
							<li><a href="<?php echo esc_url( Admin::url( 'emailit-integrations' ) ); ?>"><?php esc_html_e( 'Turn on delivery tracking', 'emailit' ); ?></a></li>
							<li><a href="<?php echo esc_url( Admin::url( 'emailit-integrations' ) ); ?>"><?php esc_html_e( 'Connect WooCommerce and your forms', 'emailit' ); ?></a></li>
							<li><a href="https://emailit.com/docs/frameworks/wordpress/" target="_blank" rel="noopener"><?php esc_html_e( 'WordPress guide', 'emailit' ); ?></a></li>
							<li><a href="https://emailit.com/docs/api-reference/rate-limits/" target="_blank" rel="noopener"><?php esc_html_e( 'Sending limits', 'emailit' ); ?></a></li>
						</ul>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * The status card.
	 *
	 * @return void
	 */
	private static function status() {
		$configured = Settings::is_configured();
		$account    = $configured ? Api::account() : null;
		$state      = Throttle::state();
		$paused     = Throttle::paused_until();
		$counts     = Email_Log::counts();
		$queued     = isset( $counts['queued'] ) ? $counts['queued'] : 0;
		$failed     = isset( $counts['failed'] ) ? $counts['failed'] : 0;
		$conflicts  = Plugin::conflicts();
		?>
		<div class="emailit-card emailit-status">
			<?php if ( ! $configured ) : ?>
				<p class="emailit-state is-off"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'Not sending yet', 'emailit' ); ?></p>
				<p>
					<?php
					printf(
						/* translators: 1: sign-up link, 2: docs link. */
						esc_html__( 'Paste an Emailit API key below. No account yet? %1$s, add and verify your domain, then %2$s.', 'emailit' ),
						'<a href="https://dash.emailit.com/register" target="_blank" rel="noopener">' . esc_html__( 'Create one for free', 'emailit' ) . '</a>',
						'<a href="https://emailit.com/docs/developers/api-keys/" target="_blank" rel="noopener">' . esc_html__( 'create an API key', 'emailit' ) . '</a>'
					);
					?>
				</p>
			<?php elseif ( $account && false === $account['valid'] ) : ?>
				<p class="emailit-state is-off"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Emailit rejected the API key', 'emailit' ); ?></p>
				<p><?php esc_html_e( 'Paste a current key below. Emails are kept in the queue until the key works.', 'emailit' ); ?></p>
			<?php elseif ( $paused ) : ?>
				<p class="emailit-state is-paused"><span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<?php
					/* translators: %s: time until sending resumes. */
					echo esc_html( sprintf( __( 'Paused, resumes in %s', 'emailit' ), human_time_diff( time(), $paused ) ) );
					?>
				</p>
				<p><?php echo esc_html( self::pause_text( Throttle::pause_reason() ) ); ?></p>
			<?php else : ?>
				<p class="emailit-state is-on"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php echo esc_html( 'smtp' === Settings::mailer() ? __( 'Sending through the Emailit SMTP relay', 'emailit' ) : __( 'Sending through the Emailit API', 'emailit' ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $conflicts ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %s: plugin names. */
					echo esc_html( sprintf( __( '%s is active too. Only one plugin can send WordPress email; deactivate the others.', 'emailit' ), implode( ', ', $conflicts ) ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $configured ) : ?>
				<dl class="emailit-facts">
					<div><dt><?php esc_html_e( 'API key', 'emailit' ); ?></dt><dd>
						<?php
						if ( $account && true === $account['valid'] ) {
							echo esc_html( $account['full'] ? __( 'Full Access', 'emailit' ) : __( 'Sending Only', 'emailit' ) );
						} elseif ( $account && null === $account['valid'] ) {
							esc_html_e( 'Couldn\'t check', 'emailit' );
						} else {
							esc_html_e( 'Invalid', 'emailit' );
						}
						?>
					</dd></div>
					<div><dt><?php esc_html_e( 'Speed', 'emailit' ); ?></dt><dd>
						<?php
						/* translators: %s: emails per second. */
						echo esc_html( sprintf( __( '%s emails per second', 'emailit' ), number_format_i18n( Throttle::per_second() ) ) );
						?>
					</dd></div>
					<div><dt><?php esc_html_e( 'Left today', 'emailit' ); ?></dt><dd>
						<?php
						$remaining = Throttle::daily_remaining();
						if ( null === $remaining ) {
							esc_html_e( 'Shown after the next email', 'emailit' );
						} elseif ( ! empty( $state['daily_limit'] ) ) {
							/* translators: 1: emails left, 2: daily limit. */
							echo esc_html( sprintf( __( '%1$s of %2$s', 'emailit' ), number_format_i18n( $remaining ), number_format_i18n( (int) $state['daily_limit'] ) ) );
						} else {
							echo esc_html( number_format_i18n( $remaining ) );
						}
						?>
					</dd></div>
					<div><dt><?php esc_html_e( 'Queue', 'emailit' ); ?></dt><dd>
						<?php
						printf(
							'<a href="%1$s">%2$s</a>, <a href="%3$s">%4$s</a>',
							esc_url( Admin::url( 'emailit-log', array( 'status' => 'queued' ) ) ),
							/* translators: %s: number of emails. */
							esc_html( sprintf( _n( '%s waiting', '%s waiting', $queued, 'emailit' ), number_format_i18n( $queued ) ) ),
							esc_url( Admin::url( 'emailit-log', array( 'status' => 'failed' ) ) ),
							/* translators: %s: number of emails. */
							esc_html( sprintf( _n( '%s failed', '%s failed', $failed, 'emailit' ), number_format_i18n( $failed ) ) )
						);
						?>
					</dd></div>
					<div><dt><?php esc_html_e( 'Delivery tracking', 'emailit' ); ?></dt><dd>
						<a href="<?php echo esc_url( Admin::url( 'emailit-integrations' ) ); ?>"><?php echo esc_html( Webhooks::is_enabled() ? __( 'On', 'emailit' ) : __( 'Off', 'emailit' ) ); ?></a>
					</dd></div>
					<div><dt><?php esc_html_e( 'Background jobs', 'emailit' ); ?></dt><dd>
						<?php
						/* translators: %s: number of jobs. */
						echo esc_html( sprintf( _n( '%s waiting', '%s waiting', Jobs::count_queued(), 'emailit' ), number_format_i18n( Jobs::count_queued() ) ) );
						?>
					</dd></div>
				</dl>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="emailit-refresh">
					<input type="hidden" name="action" value="emailit_refresh" />
					<input type="hidden" name="page" value="emailit" />
					<?php wp_nonce_field( 'emailit_refresh' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Check again', 'emailit' ); ?></button>
				</form>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
					<p class="description"><?php esc_html_e( 'WP-Cron is disabled on this site. Make sure a system cron job runs wp-cron.php every minute, or queued emails wait until you send them from the Email log.', 'emailit' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Why sending is paused.
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	public static function pause_text( $reason ) {
		switch ( $reason ) {
			case 'daily':
				return __( 'Your workspace reached its daily sending limit. New emails are queued and sent after the limit resets at 00:00 UTC.', 'emailit' );
			case 'auth':
				return __( 'Emailit rejected the API key. New emails are queued. Save a valid key to resume.', 'emailit' );
			case 'credits':
				return __( 'Your workspace is out of credits. New emails are queued and sent once you add credits.', 'emailit' );
			case 'connection':
				return __( 'WordPress couldn\'t reach Emailit. New emails are queued and sent when the connection works again.', 'emailit' );
		}
		return __( 'New emails are queued and sent automatically.', 'emailit' );
	}
}
