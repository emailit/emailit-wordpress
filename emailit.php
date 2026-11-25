<?php
/**
 * Plugin Name:       Emailit
 * Plugin URI:        https://emailit.com/docs/frameworks/wordpress/
 * Description:       Sends WordPress email through the Emailit API with throttling, retries and an email log, and connects WooCommerce and form plugins to your Emailit contacts, audiences and automations.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Emailit
 * Author URI:        https://emailit.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       emailit
 *
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 *
 * @package Emailit
 */

defined( 'ABSPATH' ) || exit;

define( 'EMAILIT_VERSION', '1.0.0' );
define( 'EMAILIT_FILE', __FILE__ );
define( 'EMAILIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMAILIT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Emailit\\WordPress\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$file  = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path  = EMAILIT_DIR . 'includes/' . $dir . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Emailit\\WordPress\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Emailit\\WordPress\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Emailit\\WordPress\\Plugin', 'boot' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
