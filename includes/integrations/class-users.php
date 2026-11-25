<?php
/**
 * WordPress users.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds new WordPress users as Emailit contacts, tagged with their role, and
 * keeps names and email addresses up to date.
 */
final class Users {

	/**
	 * Settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings() {
		return wp_parse_args(
			Integrations::settings( 'users' ),
			array(
				'enabled'  => false,
				'roles'    => array(),
				'audience' => '',
				'tags'     => 'wordpress',
			)
		);
	}

	/**
	 * Hook registrations and profile updates.
	 *
	 * @return void
	 */
	public static function register() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		add_action( 'user_register', array( __CLASS__, 'user_register' ), 20 );
		add_action( 'profile_update', array( __CLASS__, 'profile_update' ), 20, 2 );
	}

	/**
	 * Whether a user's role is synced.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	private static function included( \WP_User $user ) {
		$roles = (array) self::settings()['roles'];
		return empty( $roles ) || (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * A user registered.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && self::included( $user ) ) {
			self::queue( $user, true );
		}
	}

	/**
	 * A user's profile changed.
	 *
	 * @param int      $user_id  User ID.
	 * @param \WP_User $old_user User before the change.
	 * @return void
	 */
	public static function profile_update( $user_id, $old_user ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::included( $user ) || ! ( $old_user instanceof \WP_User ) ) {
			return;
		}
		if ( $old_user->user_email === $user->user_email && $old_user->first_name === $user->first_name && $old_user->last_name === $user->last_name ) {
			return;
		}
		self::queue( $user, false, $old_user->user_email );
	}

	/**
	 * Queue the contact.
	 *
	 * @param \WP_User $user           User.
	 * @param bool     $is_new         Just registered.
	 * @param string   $previous_email Address before a change.
	 * @return void
	 */
	public static function queue( \WP_User $user, $is_new, $previous_email = '' ) {
		$settings = self::settings();
		$tags     = array_filter( array_map( 'trim', explode( ',', (string) $settings['tags'] ) ) );
		foreach ( (array) $user->roles as $role ) {
			$tags[] = 'role-' . $role;
		}
		Integrations::queue_contact(
			array(
				'email'          => $user->user_email,
				'first_name'     => (string) $user->first_name,
				'last_name'      => (string) $user->last_name,
				'tags'           => array_values( array_unique( $tags ) ),
				'audience_id'    => $is_new ? (string) $settings['audience'] : '',
				'subscribe'      => $is_new && '' !== (string) $settings['audience'],
				'source'         => 'wordpress',
				'previous_email' => $previous_email,
			)
		);
	}

	/**
	 * Queue every existing user, in pages, for a first sync.
	 *
	 * @param int $page Page.
	 * @return int Users queued.
	 */
	public static function sync_existing( $page = 1 ) {
		$settings = self::settings();
		$args     = array(
			'number' => 200,
			'paged'  => max( 1, (int) $page ),
			'fields' => 'all',
		);
		if ( ! empty( $settings['roles'] ) ) {
			$args['role__in'] = (array) $settings['roles'];
		}
		$users = get_users( $args );
		foreach ( $users as $user ) {
			self::queue( $user, false );
		}
		return count( $users );
	}
}
