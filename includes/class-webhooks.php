<?php
/**
 * Delivery status from Emailit webhooks.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Receives Emailit webhooks at /wp-json/emailit/v1/webhook and updates the
 * email log with delivered, bounced, complained, opened and clicked.
 *
 * With a Full Access key the plugin creates the webhook itself, filtered to
 * this site's emails (meta.wp_site), and stores its signing secret.
 */
final class Webhooks {

	const OPTION         = 'emailit_webhook';
	const REST_NAMESPACE = 'emailit/v1';
	const ROUTE          = '/webhook';
	const TOLERANCE      = 300;

	/**
	 * Email events the webhook subscribes to.
	 *
	 * @return array<int, string>
	 */
	public static function events() {
		return array( 'email.delivered', 'email.bounced', 'email.complained', 'email.rejected', 'email.failed', 'email.suppressed', 'email.held', 'email.loaded', 'email.clicked' );
	}

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				// Public on purpose: Emailit calls it. Every request is checked against the HMAC signature in receive().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The webhook URL.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Stored webhook: id, secret, created_at.
	 *
	 * @return array<string, mixed>
	 */
	public static function config() {
		$config = get_option( self::OPTION, array() );
		$config = is_array( $config ) ? $config : array();
		if ( isset( $config['secret'] ) ) {
			$config['secret'] = Settings::decrypt( (string) $config['secret'] );
		}
		return wp_parse_args(
			$config,
			array(
				'id'         => '',
				'secret'     => '',
				'created_at' => 0,
				'last_event' => 0,
				'last_error' => '',
			)
		);
	}

	/**
	 * Save the webhook.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return void
	 */
	private static function save( array $config ) {
		if ( isset( $config['secret'] ) ) {
			$config['secret'] = Settings::encrypt( (string) $config['secret'] );
		}
		update_option( self::OPTION, $config, false );
	}

	/**
	 * Whether a signing secret is set.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$config = self::config();
		return '' !== $config['secret'];
	}

	/**
	 * Create the webhook in Emailit (Full Access key needed).
	 *
	 * @return true|\WP_Error
	 */
	public static function create() {
		$url = self::url();
		if ( 0 !== strpos( $url, 'https://' ) && ! defined( 'EMAILIT_ALLOW_HTTP_WEBHOOK' ) ) {
			return new \WP_Error( 'emailit_https', __( 'Emailit can only send webhooks to an https:// address. This site doesn\'t use HTTPS.', 'emailit' ) );
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || preg_match( '/\.(local|test|localhost)$/', $host ) ) {
			if ( ! defined( 'EMAILIT_ALLOW_HTTP_WEBHOOK' ) ) {
				return new \WP_Error( 'emailit_local', __( 'Emailit can\'t reach a local site. Set up delivery tracking on a site that\'s online.', 'emailit' ) );
			}
		}

		self::delete_remote();
		$response = Api::post(
			'webhooks',
			array(
				/* translators: %s: site address. */
				'name'    => substr( sprintf( __( 'WordPress: %s', 'emailit' ), Settings::site_id() ), 0, 100 ),
				'url'     => $url,
				'events'  => self::events(),
				'enabled' => true,
				'filter'  => array(
					'match' => 'all',
					'rules' => array(
						array(
							'field'    => 'meta.wp_site',
							'operator' => 'equals',
							'value'    => Settings::site_id(),
						),
					),
				),
			)
		);
		if ( ! $response->ok() || empty( $response->body['secret'] ) ) {
			$message = 403 === $response->status ? __( 'Your API key can\'t create webhooks. Use a Full Access key, or add the webhook in Emailit yourself and paste its signing secret.', 'emailit' ) : $response->message();
			return new \WP_Error( 'emailit_webhook', $message );
		}
		self::save(
			array(
				'id'         => isset( $response->body['id'] ) ? (string) $response->body['id'] : '',
				'secret'     => (string) $response->body['secret'],
				'created_at' => time(),
			)
		);
		return true;
	}

	/**
	 * Use a webhook created by hand in Emailit.
	 *
	 * @param string $secret Signing secret (whsec_...).
	 * @return void
	 */
	public static function set_secret( $secret ) {
		$config           = self::config();
		$config['secret'] = $secret;
		$config['id']     = '';
		self::save( $config );
	}

	/**
	 * Delete the webhook this plugin created, if it can.
	 *
	 * @return void
	 */
	public static function delete_remote() {
		$config = self::config();
		if ( '' !== $config['id'] && Settings::is_configured() ) {
			Api::delete( 'webhooks/' . rawurlencode( $config['id'] ), array( 'timeout' => 10 ) );
		}
	}

	/**
	 * Turn delivery tracking off.
	 *
	 * @return void
	 */
	public static function disable() {
		self::delete_remote();
		delete_option( self::OPTION );
	}

	/**
	 * Verify the signature: HMAC-SHA256 of "{timestamp}.{raw body}" with the secret.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature X-Emailit-Signature.
	 * @param string $timestamp X-Emailit-Timestamp.
	 * @param string $secret    Signing secret.
	 * @return bool
	 */
	public static function verify( $body, $signature, $timestamp, $secret ) {
		if ( '' === $secret || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		return hash_equals( $expected, strtolower( $signature ) );
	}

	/**
	 * Handle a webhook request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive( \WP_REST_Request $request ) {
		$config = self::config();
		$body   = (string) $request->get_body();
		if ( ! self::verify( $body, (string) $request->get_header( 'x_emailit_signature' ), (string) $request->get_header( 'x_emailit_timestamp' ), $config['secret'] ) ) {
			return new \WP_Error( 'emailit_signature', 'Invalid signature', array( 'status' => 401 ) );
		}

		$events = json_decode( $body, true );
		if ( ! is_array( $events ) ) {
			return new \WP_Error( 'emailit_payload', 'Invalid payload', array( 'status' => 400 ) );
		}
		if ( isset( $events['type'] ) ) {
			$events = array( $events );
		}

		$seen = get_transient( 'emailit_webhook_seen' );
		$seen = is_array( $seen ) ? $seen : array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$event_id = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
			if ( '' !== $event_id && isset( $seen[ $event_id ] ) ) {
				continue;
			}
			if ( '' !== $event_id ) {
				$seen[ $event_id ] = 1;
			}
			Email_Log::apply_event( $event );
			/**
			 * Fires for every webhook event Emailit sends this site, after its signature was checked.
			 *
			 * @param array<string, mixed> $event The event: event_id, type, data.object.
			 */
			do_action( 'emailit_webhook_event', $event );
			if ( isset( $event['type'] ) && is_string( $event['type'] ) ) {
				/**
				 * Fires for one webhook event type, e.g. emailit_webhook_email.bounced.
				 *
				 * @param array<string, mixed> $event The event.
				 */
				do_action( 'emailit_webhook_' . $event['type'], $event );
			}
		}
		set_transient( 'emailit_webhook_seen', array_slice( $seen, -500, null, true ), HOUR_IN_SECONDS );

		$config['last_event'] = time();
		self::save( $config );
		return new \WP_REST_Response( array( 'received' => count( $events ) ), 200 );
	}
}
