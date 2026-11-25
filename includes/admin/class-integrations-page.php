<?php
/**
 * Emailit > Integrations.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Admin;

use Emailit\WordPress\Api;
use Emailit\WordPress\Jobs;
use Emailit\WordPress\Settings;
use Emailit\WordPress\Webhooks;
use Emailit\WordPress\Integrations\Integrations;
use Emailit\WordPress\Integrations\Users;
use Emailit\WordPress\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery tracking, WooCommerce, forms and WordPress users.
 */
final class Integrations_Page {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		$configured = Settings::is_configured();
		$account    = $configured ? Api::account() : null;
		$full       = $account && ! empty( $account['full'] );
		$audiences  = $full ? Integrations::audiences() : null;
		?>
		<div class="wrap emailit">
			<?php Settings_Page::header( __( 'Integrations', 'emailit' ) ); ?>
			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Add your API key on the Settings screen first.', 'emailit' ); ?> <a href="<?php echo esc_url( Admin::url() ); ?>"><?php esc_html_e( 'Settings', 'emailit' ); ?></a></p></div>
			<?php elseif ( ! $full ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Your API key is Sending Only. Contacts, audiences, WooCommerce sync and automatic delivery tracking need a Full Access key. Emails send either way.', 'emailit' ); ?></p></div>
			<?php endif; ?>

			<?php self::delivery_tracking( $full ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="emailit_integrations" />
				<?php wp_nonce_field( 'emailit_integrations' ); ?>
				<?php
				if ( WooCommerce::is_available() ) {
					self::woocommerce( $audiences );
				}
				self::forms( $audiences );
				self::users( $audiences );
				submit_button( __( 'Save integrations', 'emailit' ) );
				?>
			</form>
			<?php self::failed_jobs(); ?>
		</div>
		<?php
	}

	/**
	 * An audience picker, or a text field when the audiences can't be listed.
	 *
	 * @param string                     $name      Field name.
	 * @param string                     $value     Selected audience ID.
	 * @param array<string, string>|null $audiences Audiences.
	 * @param string                     $none      Label for no audience.
	 * @return void
	 */
	private static function audience_field( $name, $value, $audiences, $none ) {
		if ( is_array( $audiences ) ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			echo '<option value="">' . esc_html( $none ) . '</option>';
			foreach ( $audiences as $id => $label ) {
				echo '<option value="' . esc_attr( $id ) . '" ' . selected( $value, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			if ( '' !== $value && ! isset( $audiences[ $value ] ) ) {
				echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
			}
			echo '</select>';
			return;
		}
		echo '<input type="text" class="regular-text code" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="aud_…" />';
	}

	/**
	 * Delivery tracking card.
	 *
	 * @param bool $full Full Access key.
	 * @return void
	 */
	private static function delivery_tracking( $full ) {
		$config  = Webhooks::config();
		$enabled = Webhooks::is_enabled();
		?>
		<div class="emailit-card">
			<h2><?php esc_html_e( 'Delivery tracking', 'emailit' ); ?></h2>
			<p><?php esc_html_e( 'Emailit tells this site when an email is delivered, bounces, is marked as spam, opened or clicked, and the email log shows it. Developers can react to every event with the emailit_webhook_event action.', 'emailit' ); ?></p>
			<?php if ( $enabled ) : ?>
				<p class="emailit-state is-on"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'On', 'emailit' ); ?>
					<?php if ( $config['last_event'] ) : ?>
						<span class="description">
							<?php
							/* translators: %s: time since the last event. */
							echo esc_html( sprintf( __( 'Last event %s ago', 'emailit' ), human_time_diff( (int) $config['last_event'] ) ) );
							?>
						</span>
					<?php endif; ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="emailit_webhook" />
					<input type="hidden" name="do" value="disable" />
					<?php wp_nonce_field( 'emailit_webhook' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Turn off', 'emailit' ); ?></button>
				</form>
			<?php else : ?>
				<?php if ( $full ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="emailit_webhook" />
						<input type="hidden" name="do" value="create" />
						<?php wp_nonce_field( 'emailit_webhook' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on delivery tracking', 'emailit' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Creates a webhook in your Emailit workspace that only receives this site\'s emails.', 'emailit' ); ?></p>
				<?php endif; ?>
				<details <?php echo $full ? '' : 'open'; ?>>
					<summary><?php esc_html_e( 'Set up the webhook yourself', 'emailit' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'In Emailit, go to Email API > Webhooks and add a webhook with this URL:', 'emailit' ); ?> <code><?php echo esc_html( Webhooks::url() ); ?></code></li>
						<li>
							<?php esc_html_e( 'Choose these events:', 'emailit' ); ?> <code><?php echo esc_html( implode( ', ', Webhooks::events() ) ); ?></code>.
							<?php
							/* translators: %s: site ID. */
							echo esc_html( sprintf( __( 'To receive only this site\'s emails, add the filter meta.wp_site equals %s.', 'emailit' ), Settings::site_id() ) );
							?>
						</li>
						<li><?php esc_html_e( 'Paste its signing secret here:', 'emailit' ); ?></li>
					</ol>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="emailit-row">
						<input type="hidden" name="action" value="emailit_webhook" />
						<input type="hidden" name="do" value="secret" />
						<?php wp_nonce_field( 'emailit_webhook' ); ?>
						<input type="password" name="secret" class="regular-text code" placeholder="whsec_…" autocomplete="off" />
						<button type="submit" class="button"><?php esc_html_e( 'Save secret', 'emailit' ); ?></button>
					</form>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * WooCommerce card.
	 *
	 * @param array<string, string>|null $audiences Audiences.
	 * @return void
	 */
	private static function woocommerce( $audiences ) {
		$s        = WooCommerce::settings();
		$commerce = WooCommerce::commerce_available();
		$state    = get_option( WooCommerce::COMMERCE_KEY );
		$products = get_option( 'emailit_wc_products' );
		$import   = get_option( 'emailit_wc_import' );
		$n        = 'woocommerce';
		?>
		<div class="emailit-card">
			<h2><?php esc_html_e( 'WooCommerce', 'emailit' ); ?></h2>
			<p><?php esc_html_e( 'Every WooCommerce email is tagged with its type and order (metadata wc_email and wc_order_id), so you can find them in Emailit. Connect the shop to also use orders, products and carts in Emailit campaigns and automations.', 'emailit' ); ?></p>
			<?php if ( false === $commerce ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'Emailit Commerce (orders, products, carts and revenue) is included with the Pro and Business plans. Until then, buyers are saved as contacts tagged "customer", and orders are sent as c:order_placed and c:order_completed events you can use in automations.', 'emailit' ); ?>
					<?php if ( is_array( $state ) && ! empty( $state['message'] ) ) : ?>
						<br /><span class="description"><?php echo esc_html( $state['message'] ); ?></span>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Connect the shop', 'emailit' ); ?></th>
					<td>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <strong><?php esc_html_e( 'Send this shop\'s data to Emailit', 'emailit' ); ?></strong></label>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[orders]" value="1" <?php checked( $s['orders'] ); ?> /> <?php esc_html_e( 'Orders, for purchase history and revenue per campaign and automation', 'emailit' ); ?></label>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[products]" value="1" <?php checked( $s['products'] ); ?> /> <?php esc_html_e( 'Products, for product blocks and recommendations in emails', 'emailit' ); ?></label>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[carts]" value="1" <?php checked( $s['carts'] ); ?> /> <?php esc_html_e( 'Carts of signed-in customers and of visitors who entered their email at checkout, for abandoned cart automations', 'emailit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Abandoned cart emails link back to a page that refills the cart.', 'emailit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Newsletter checkbox', 'emailit' ); ?></th>
					<td>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[optin]" value="1" <?php checked( $s['optin'] ); ?> /> <?php esc_html_e( 'Add a newsletter checkbox to the checkout (unticked by default)', 'emailit' ); ?></label>
						<p><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[optin_label]" value="<?php echo esc_attr( $s['optin_label'] ); ?>" placeholder="<?php echo esc_attr( __( 'Send me news and offers by email', 'emailit' ) ); ?>" aria-label="<?php esc_attr_e( 'Checkbox label', 'emailit' ); ?>" /></p>
						<p><?php self::audience_field( $n . '[audience]', (string) $s['audience'], $audiences, __( '— Choose the audience to subscribe them to —', 'emailit' ) ); ?></p>
						<p class="description"><?php esc_html_e( 'Buyers who tick it are subscribed to this audience. Works with the classic and the block checkout.', 'emailit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tracking script', 'emailit' ); ?></th>
					<td>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[tracking]" value="1" <?php checked( $s['tracking'] ); ?> /> <?php esc_html_e( 'Load the Emailit tracking script from js.emailit.com to record product views and recognize visitors who arrive from your emails', 'emailit' ); ?></label>
						<label class="emailit-block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[identify]" value="1" <?php checked( $s['identify'] ); ?> /> <?php esc_html_e( 'Identify signed-in customers by their email address', 'emailit' ); ?></label>
						<p class="emailit-row">
							<input type="text" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[tracking_key]" value="<?php echo esc_attr( (string) $s['tracking_key'] ); ?>" placeholder="pub_…" aria-label="<?php esc_attr_e( 'Tracking key', 'emailit' ); ?>" />
							<button type="submit" form="emailit-create-tracking-key" class="button"><?php esc_html_e( 'Create a tracking key', 'emailit' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'Off by default. If you turn it on, mention Emailit in your privacy policy and cookie notice.', 'emailit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Existing data', 'emailit' ); ?></th>
					<td>
						<p class="emailit-row">
							<button type="submit" form="emailit-sync-products" class="button" <?php disabled( empty( $s['enabled'] ) ); ?>><?php esc_html_e( 'Send all products', 'emailit' ); ?></button>
							<span class="description">
								<?php
								if ( is_array( $products ) ) {
									/* translators: %s: number of products. */
									echo esc_html( sprintf( $products['finished'] ? __( '%s products sent.', 'emailit' ) : __( '%s products sent so far…', 'emailit' ), number_format_i18n( (int) $products['done'] ) ) );
								}
								?>
							</span>
						</p>
						<p class="emailit-row">
							<button type="submit" form="emailit-sync-orders" class="button" <?php disabled( empty( $s['enabled'] ) ); ?>><?php esc_html_e( 'Import order history', 'emailit' ); ?></button>
							<span class="description">
								<?php
								if ( is_array( $import ) ) {
									/* translators: %s: number of orders. */
									echo esc_html( sprintf( $import['finished'] ? __( '%s orders imported.', 'emailit' ) : __( '%s orders imported so far…', 'emailit' ), number_format_i18n( (int) $import['done'] ) ) );
								}
								?>
							</span>
						</p>
						<p class="description"><?php esc_html_e( 'Imports the last two years. Imported orders fill purchase history and recommendations, but don\'t start automations or count as campaign revenue. Needs Emailit Commerce.', 'emailit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
		add_action( 'admin_footer', array( __CLASS__, 'sync_forms' ) );
	}

	/**
	 * Standalone forms the WooCommerce buttons submit (forms can't be nested).
	 *
	 * @return void
	 */
	public static function sync_forms() {
		foreach ( array(
			'emailit-sync-products'       => 'products',
			'emailit-sync-orders'         => 'orders',
			'emailit-create-tracking-key' => 'tracking_key',
			'emailit-sync-users'          => 'users',
		) as $id => $do ) {
			echo '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
			echo '<input type="hidden" name="action" value="emailit_sync" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />';
			wp_nonce_field( 'emailit_sync' );
			echo '</form>';
		}
	}

	/**
	 * Forms card.
	 *
	 * @param array<string, string>|null $audiences Audiences.
	 * @return void
	 */
	private static function forms( $audiences ) {
		$available = array_filter(
			Integrations::forms(),
			static function ( $integration ) {
				return $integration->is_available();
			}
		);
		?>
		<div class="emailit-card">
			<h2><?php esc_html_e( 'Forms', 'emailit' ); ?></h2>
			<p><?php esc_html_e( 'Save people who fill in your forms as Emailit contacts, subscribe them to an audience, and start automations with the c:form_submitted event. Supports Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Ninja Forms, Fluent Forms, Formidable Forms and Forminator.', 'emailit' ); ?></p>
			<?php if ( ! $available ) : ?>
				<p class="description"><?php esc_html_e( 'None of the supported form plugins is active.', 'emailit' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $available as $integration ) : ?>
				<?php $forms = $integration->forms(); ?>
				<h3><?php echo esc_html( $integration->name() ); ?></h3>
				<?php if ( ! $forms ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							'elementor' === $integration->id()
								? __( 'Elementor forms appear here after their first submission.', 'emailit' )
								: __( 'No forms yet.', 'emailit' )
						);
						?>
					</p>
					<?php continue; ?>
				<?php endif; ?>
				<table class="widefat striped emailit-forms">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Form', 'emailit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connect', 'emailit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subscribe to audience', 'emailit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When', 'emailit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tags', 'emailit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'emailit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $forms as $form_id => $title ) : ?>
							<?php
							$fs   = $integration->form_settings( (string) $form_id );
							$base = 'forms[' . $integration->id() . ':' . $form_id . ']';
							?>
							<tr>
								<td><strong><?php echo esc_html( '' !== $title ? $title : '#' . $form_id ); ?></strong></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( $fs['enabled'] ); ?> aria-label="<?php esc_attr_e( 'Connect', 'emailit' ); ?>" /></td>
								<td><?php self::audience_field( $base . '[audience]', (string) $fs['audience'], $audiences, __( '— Contact only —', 'emailit' ) ); ?></td>
								<td>
									<select name="<?php echo esc_attr( $base ); ?>[consent]" aria-label="<?php esc_attr_e( 'When to subscribe', 'emailit' ); ?>">
										<option value="checkbox" <?php selected( $fs['consent'], 'checkbox' ); ?>><?php esc_html_e( 'If they tick a newsletter box', 'emailit' ); ?></option>
										<option value="always" <?php selected( $fs['consent'], 'always' ); ?>><?php esc_html_e( 'Always (sign-up form)', 'emailit' ); ?></option>
									</select>
								</td>
								<td><input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[tags]" value="<?php echo esc_attr( (string) $fs['tags'] ); ?>" placeholder="contact-form" aria-label="<?php esc_attr_e( 'Tags', 'emailit' ); ?>" /></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[event]" value="1" <?php checked( $fs['event'] ); ?> aria-label="<?php esc_attr_e( 'Send c:form_submitted', 'emailit' ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
			<?php if ( $available ) : ?>
				<p class="description"><?php esc_html_e( 'The plugin finds the email address, first and last name, and newsletter or consent checkboxes by their field type and label.', 'emailit' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * WordPress users card.
	 *
	 * @param array<string, string>|null $audiences Audiences.
	 * @return void
	 */
	private static function users( $audiences ) {
		$s = Users::settings();
		?>
		<div class="emailit-card">
			<h2><?php esc_html_e( 'WordPress users', 'emailit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync users', 'emailit' ); ?></th>
					<td>
						<label class="emailit-block"><input type="checkbox" name="users[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Add new users as Emailit contacts and keep their name and email up to date', 'emailit' ); ?></label>
						<fieldset class="emailit-roles">
							<legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'emailit' ); ?></legend>
							<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
								<label><input type="checkbox" name="users[roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $s['roles'], true ) ); ?> /> <?php echo esc_html( translate_user_role( $label ) ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Only these roles; none ticked means every role. Contacts are tagged with role-<name>.', 'emailit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Audience', 'emailit' ); ?></th>
					<td>
						<?php self::audience_field( 'users[audience]', (string) $s['audience'], $audiences, __( '— Contact only —', 'emailit' ) ); ?>
						<p class="description"><?php esc_html_e( 'Subscribe new users to this audience. Only choose one if your sign-up form asks for consent.', 'emailit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="emailit-user-tags"><?php esc_html_e( 'Tags', 'emailit' ); ?></label></th>
					<td><input type="text" id="emailit-user-tags" class="regular-text" name="users[tags]" value="<?php echo esc_attr( (string) $s['tags'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Existing users', 'emailit' ); ?></th>
					<td>
						<button type="submit" form="emailit-sync-users" class="button" <?php disabled( empty( $s['enabled'] ) ); ?>><?php esc_html_e( 'Add existing users as contacts', 'emailit' ); ?></button>
						<p class="description"><?php esc_html_e( 'They\'re added as contacts, not subscribed to the audience.', 'emailit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
		if ( ! WooCommerce::is_available() ) {
			add_action( 'admin_footer', array( __CLASS__, 'sync_forms' ) );
		}
	}

	/**
	 * Recent failed background jobs.
	 *
	 * @return void
	 */
	private static function failed_jobs() {
		$failures = Jobs::failures( 10 );
		if ( ! $failures ) {
			return;
		}
		?>
		<div class="emailit-card">
			<h2><?php esc_html_e( 'Sync problems', 'emailit' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'When', 'emailit' ); ?></th><th><?php esc_html_e( 'What', 'emailit' ); ?></th><th><?php esc_html_e( 'Error', 'emailit' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $failures as $job ) : ?>
						<tr><td><?php echo esc_html( Log_Page::date( $job->updated_at ) ); ?></td><td><code><?php echo esc_html( $job->type ); ?></code></td><td class="emailit-error"><?php echo esc_html( (string) $job->last_error ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="emailit_sync" />
				<input type="hidden" name="do" value="clear_jobs" />
				<?php wp_nonce_field( 'emailit_sync' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Clear', 'emailit' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
