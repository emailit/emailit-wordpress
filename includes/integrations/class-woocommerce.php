<?php
/**
 * WooCommerce.
 *
 * @package Emailit
 */

namespace Emailit\WordPress\Integrations;

use Emailit\WordPress\Api;
use Emailit\WordPress\Jobs;
use Emailit\WordPress\Send_Error;
use Emailit\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connects WooCommerce to Emailit:
 *
 * - tags every WooCommerce email with its type and order (metadata wc_email, wc_order_id);
 * - sends orders, products and carts to Emailit Commerce, for revenue
 *   attribution, purchase history, product feeds and abandoned cart
 *   automations (Commerce is on the Pro and Business plans; on other plans
 *   buyers are saved as contacts and orders as custom events instead);
 * - adds a newsletter checkbox to the checkout (classic and block);
 * - optionally adds the Emailit tracking script for product views.
 */
final class WooCommerce {

	const OPTIN_FIELD  = 'emailit/newsletter';
	const CART_PARAM   = 'emailit_cart';
	const COMMERCE_KEY = 'emailit_commerce';

	/**
	 * Settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings() {
		return wp_parse_args(
			Integrations::settings( 'woocommerce' ),
			array(
				'enabled'      => false,
				'orders'       => true,
				'products'     => true,
				'carts'        => true,
				'optin'        => false,
				'optin_label'  => '',
				'audience'     => '',
				'tracking'     => false,
				'tracking_key' => '',
				'identify'     => false,
			)
		);
	}

	/**
	 * The checkbox label.
	 *
	 * @return string
	 */
	public static function optin_label() {
		$label = (string) self::settings()['optin_label'];
		return '' !== $label ? $label : __( 'Send me news and offers by email', 'emailit' );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\WooCommerce' );
	}

	/**
	 * Register job handlers and hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		Jobs::register( 'wc_order', array( __CLASS__, 'job_order' ) );
		Jobs::register( 'wc_product', array( __CLASS__, 'job_product' ) );
		Jobs::register( 'wc_cart', array( __CLASS__, 'job_cart' ) );
		Jobs::register( 'wc_products_sync', array( __CLASS__, 'job_products_sync' ) );
		Jobs::register( 'wc_orders_import', array( __CLASS__, 'job_orders_import' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'init' ) );
	}

	/**
	 * Hook into WooCommerce.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Settings::is_configured() ) {
			return;
		}
		// Email metadata works on its own, even when the rest is off.
		add_filter( 'woocommerce_email_headers', array( __CLASS__, 'email_headers' ), 20, 4 );

		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return;
		}

		add_action( 'woocommerce_new_order', array( __CLASS__, 'order_created' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'order_created' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_created' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_changed' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'order_changed' ), 20, 1 );

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_checkout_meta' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_checkout_meta' ), 10, 2 );

		if ( ! empty( $s['optin'] ) ) {
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_optin_field' ) );
			if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
				woocommerce_register_additional_checkout_field(
					array(
						'id'       => self::OPTIN_FIELD,
						'label'    => self::optin_label(),
						'location' => 'order',
						'type'     => 'checkbox',
					)
				);
			}
		}

		if ( ! empty( $s['products'] ) ) {
			add_action( 'woocommerce_new_product', array( __CLASS__, 'product_changed' ), 20, 1 );
			add_action( 'woocommerce_update_product', array( __CLASS__, 'product_changed' ), 20, 1 );
			add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'product_object_changed' ), 20, 1 );
			add_action( 'wp_trash_post', array( __CLASS__, 'product_changed' ), 20, 1 );
			add_action( 'untrashed_post', array( __CLASS__, 'product_changed' ), 20, 1 );
		}

		if ( ! empty( $s['carts'] ) ) {
			add_action( 'woocommerce_cart_updated', array( __CLASS__, 'cart_touched' ) );
			add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'cart_touched' ) );
			add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'cart_touched' ) );
			add_action( 'wp_loaded', array( __CLASS__, 'restore_cart' ), 30 );
		}

		if ( ! empty( $s['tracking'] ) && '' !== (string) $s['tracking_key'] ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'tracking_script' ) );
		}
	}

	/**
	 * Add metadata headers to WooCommerce emails. They become Emailit metadata and never reach recipients.
	 *
	 * @param string $headers  Headers.
	 * @param string $email_id Email type, e.g. customer_completed_order.
	 * @param mixed  $wc_object Order, user or product.
	 * @param mixed  $email    WC_Email.
	 * @return string
	 */
	public static function email_headers( $headers, $email_id = '', $wc_object = null, $email = null ) {
		unset( $email );
		$extra = array( 'X-Emailit-Meta-Wc-Email: ' . sanitize_key( (string) $email_id ) );
		if ( $wc_object instanceof \WC_Order ) {
			$extra[] = 'X-Emailit-Meta-Wc-Order-Id: ' . (int) $wc_object->get_id();
		}
		return rtrim( (string) $headers, "\r\n" ) . "\r\n" . implode( "\r\n", $extra ) . "\r\n";
	}

	/**
	 * Whether Commerce can be used. Unknown (null) until the first call.
	 *
	 * @return bool|null
	 */
	public static function commerce_available() {
		$state = get_option( self::COMMERCE_KEY );
		if ( ! is_array( $state ) || ! isset( $state['available'] ) ) {
			return null;
		}
		// Look again after a day, in case the plan changed.
		if ( false === $state['available'] && (int) $state['checked_at'] < time() - DAY_IN_SECONDS ) {
			return null;
		}
		return $state['available'];
	}

	/**
	 * Remember whether Commerce is available.
	 *
	 * @param bool   $available Available.
	 * @param string $message   Why not.
	 * @return void
	 */
	public static function set_commerce( $available, $message = '' ) {
		update_option(
			self::COMMERCE_KEY,
			array(
				'available'  => (bool) $available,
				'checked_at' => time(),
				'message'    => (string) $message,
			),
			false
		);
	}

	/**
	 * Check Commerce access now.
	 *
	 * @return bool|null
	 */
	public static function check_commerce() {
		$response = Api::get( 'products', array( 'limit' => 1 ), array( 'timeout' => 10 ) );
		if ( $response->ok() ) {
			self::set_commerce( true );
			return true;
		}
		if ( 403 === $response->status || 402 === $response->status ) {
			self::set_commerce( false, $response->message() );
			return false;
		}
		return null;
	}

	/**
	 * Whether a response says the plan doesn't include Commerce.
	 *
	 * @param \Emailit\WordPress\Api_Response $response Response.
	 * @return bool
	 */
	private static function is_plan_error( $response ) {
		return in_array( $response->status, array( 402, 403 ), true ) && false === stripos( $response->message(), 'Permission denied' );
	}

	/**
	 * Drop nulls and empty strings, which the API's schema rejects.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	private static function compact( array $data ) {
		return array_filter(
			$data,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	// Orders.

	/**
	 * A new order.
	 *
	 * @param int|\WC_Order $order Order or ID.
	 * @return void
	 */
	public static function order_created( $order ) {
		$id = $order instanceof \WC_Order ? $order->get_id() : (int) $order;
		if ( $id ) {
			Jobs::add(
				'wc_order',
				array(
					'order_id' => $id,
					'new'      => true,
				),
				'order:' . $id,
				5
			);
		}
	}

	/**
	 * An order's status changed or it was refunded.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function order_changed( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id ) {
			Jobs::add( 'wc_order', array( 'order_id' => $order_id ), 'order:' . $order_id, 5 );
		}
	}

	/**
	 * Map a WooCommerce status to an Emailit order status.
	 *
	 * @param \WC_Order $order Order.
	 * @return string|null Null for orders that shouldn't be sent yet.
	 */
	public static function order_status( \WC_Order $order ) {
		$map    = array(
			'pending'        => 'placed',
			'on-hold'        => 'placed',
			'processing'     => 'paid',
			'completed'      => 'fulfilled',
			'cancelled'      => 'canceled',
			'failed'         => 'canceled',
			'refunded'       => 'refunded',
			'checkout-draft' => null,
			'trash'          => 'canceled',
		);
		$status = $order->get_status();
		if ( array_key_exists( $status, $map ) ) {
			return $map[ $status ];
		}
		return $order->is_paid() ? 'paid' : 'placed';
	}

	/**
	 * The Orders API body for an order.
	 *
	 * @param \WC_Order $order      Order.
	 * @param bool      $historical For a history import.
	 * @return array<string, mixed>|null
	 */
	public static function order_payload( \WC_Order $order, $historical = false ) {
		$status = self::order_status( $order );
		if ( null === $status ) {
			return null;
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$quantity   = max( 1, (int) $item->get_quantity() );
			$product_id = (int) $item->get_product_id();
			$product    = $item->get_product();
			$items[]    = self::compact(
				array(
					'product_id' => $product_id ? (string) $product_id : null,
					'variant_id' => $item->get_variation_id() ? (string) $item->get_variation_id() : null,
					'sku'        => $product ? (string) $product->get_sku() : null,
					'title'      => (string) $item->get_name(),
					'quantity'   => $quantity,
					'price'      => round( (float) $item->get_subtotal() / $quantity, 4 ),
					'total'      => round( (float) $item->get_total(), 4 ),
					'url'        => $product_id ? (string) get_permalink( $product_id ) : null,
					'image_url'  => $product && $product->get_image_id() ? (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : null,
					'categories' => $product_id ? self::term_names( $product_id, 'product_cat' ) : array(),
				)
			);
		}

		$created = $order->get_date_created();
		$email   = (string) $order->get_billing_email();
		$payload = self::compact(
			array(
				'id'              => (string) $order->get_id(),
				'email'           => is_email( $email ) ? $email : null,
				'first_name'      => (string) $order->get_billing_first_name(),
				'last_name'       => (string) $order->get_billing_last_name(),
				'status'          => $status,
				'currency'        => (string) $order->get_currency(),
				'subtotal'        => (float) $order->get_subtotal(),
				'discount'        => (float) $order->get_total_discount(),
				'shipping'        => (float) $order->get_shipping_total(),
				'tax'             => (float) $order->get_total_tax(),
				'total'           => (float) $order->get_total(),
				'refunded_amount' => (float) $order->get_total_refunded(),
				'coupon_codes'    => array_values( $order->get_coupon_codes() ),
				'cart_id'         => (string) $order->get_meta( '_emailit_cart_id' ),
				'placed_at'       => $created ? $created->format( 'c' ) : null,
				'items'           => $items,
				'metadata'        => array(
					'order_number'   => (string) $order->get_order_number(),
					'payment_method' => (string) $order->get_payment_method_title(),
					'site'           => Settings::site_id(),
				),
			)
		);
		if ( ! is_email( $email ) ) {
			$payload['create_contact'] = false;
		}
		$audience = (string) self::settings()['audience'];
		if ( ! $historical && '' !== $audience && self::opted_in( $order ) ) {
			$payload['accepts_marketing'] = true;
			$payload['audience_id']       = $audience;
		}
		/**
		 * Filters the order sent to Emailit Commerce.
		 *
		 * @param array<string, mixed> $payload Orders API body.
		 * @param \WC_Order            $order   Order.
		 */
		return apply_filters( 'emailit_woocommerce_order', $payload, $order );
	}

	/**
	 * Whether the buyer ticked the newsletter box.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function opted_in( \WC_Order $order ) {
		if ( 'yes' === $order->get_meta( '_emailit_optin' ) ) {
			return true;
		}
		$block = $order->get_meta( '_wc_other/' . self::OPTIN_FIELD );
		return ! empty( $block ) && 'no' !== $block && '0' !== (string) $block;
	}

	/**
	 * Job: send an order.
	 *
	 * @param array<string, mixed> $payload order_id, new.
	 * @return Send_Error|null
	 */
	public static function job_order( array $payload ) {
		$order = wc_get_order( (int) $payload['order_id'] );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$body = self::order_payload( $order );
		if ( null === $body ) {
			return null;
		}

		if ( false !== self::commerce_available() && ! empty( self::settings()['orders'] ) ) {
			$response = Api::post( 'orders', $body );
			if ( $response->ok() ) {
				self::set_commerce( true );
				$order->update_meta_data( '_emailit_synced', time() );
				$order->save_meta_data();
				return null;
			}
			if ( ! self::is_plan_error( $response ) ) {
				return Send_Error::from_api( $response );
			}
			self::set_commerce( false, $response->message() );
		}
		return self::order_fallback( $order, $body, ! empty( $payload['new'] ) );
	}

	/**
	 * Without Commerce: save the buyer as a contact and send order events for automations.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $body  Orders API body.
	 * @param bool                 $is_new New order.
	 * @return Send_Error|null
	 */
	private static function order_fallback( \WC_Order $order, array $body, $is_new ) {
		if ( empty( $body['email'] ) ) {
			return null;
		}
		$audience = (string) self::settings()['audience'];
		Integrations::queue_contact(
			array(
				'email'       => $body['email'],
				'first_name'  => isset( $body['first_name'] ) ? $body['first_name'] : '',
				'last_name'   => isset( $body['last_name'] ) ? $body['last_name'] : '',
				'tags'        => array( 'customer', 'woocommerce' ),
				'audience_id' => $audience,
				'subscribe'   => '' !== $audience && self::opted_in( $order ),
				'source'      => 'woocommerce',
			)
		);
		$properties = array(
			'order_id'     => (string) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'status'       => (string) $order->get_status(),
			'total'        => (float) $order->get_total(),
			'currency'     => (string) $order->get_currency(),
			'items'        => count( $order->get_items() ),
			'site'         => Settings::site_id(),
		);
		$sent       = (array) $order->get_meta( '_emailit_events' );
		if ( $is_new && ! in_array( 'placed', $sent, true ) ) {
			Integrations::queue_event( 'order_placed', $body['email'], $properties );
			$sent[] = 'placed';
		}
		if ( 'completed' === $order->get_status() && ! in_array( 'completed', $sent, true ) ) {
			Integrations::queue_event( 'order_completed', $body['email'], $properties );
			$sent[] = 'completed';
		}
		$order->update_meta_data( '_emailit_events', $sent );
		$order->save_meta_data();
		return null;
	}

	/**
	 * Job: import past orders, 100 per request. They fill purchase history without starting automations.
	 *
	 * @param array<string, mixed> $payload page.
	 * @return Send_Error|null
	 */
	public static function job_orders_import( array $payload ) {
		$page = max( 1, (int) $payload['page'] );
		/**
		 * Filters how far back the order import goes.
		 *
		 * @param string $after Orders created after this date (strtotime format). Default 2 years ago.
		 */
		$after  = (string) apply_filters( 'emailit_woocommerce_import_after', '-2 years' );
		$orders = wc_get_orders(
			array(
				'limit'        => 100,
				'page'         => $page,
				'type'         => 'shop_order',
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . gmdate( 'Y-m-d', (int) strtotime( $after ) ),
			)
		);
		$batch  = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$body = self::order_payload( $order, true );
				if ( $body ) {
					$batch[] = $body;
				}
			}
		}
		if ( $batch ) {
			$response = Api::post( 'orders/batch', array( 'orders' => $batch ), array( 'timeout' => 60 ) );
			if ( ! $response->ok() ) {
				if ( self::is_plan_error( $response ) ) {
					self::set_commerce( false, $response->message() );
				}
				return Send_Error::from_api( $response );
			}
		}
		$progress = array(
			'done'     => ( $page - 1 ) * 100 + count( $orders ),
			'finished' => count( $orders ) < 100,
			'at'       => time(),
		);
		update_option( 'emailit_wc_import', $progress, false );
		if ( ! $progress['finished'] ) {
			Jobs::add( 'wc_orders_import', array( 'page' => $page + 1 ), 'orders-import' );
		}
		return null;
	}

	// Products.

	/**
	 * A product was created, updated, trashed or restored.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public static function product_changed( $post_id ) {
		$type = get_post_type( $post_id );
		if ( 'product_variation' === $type ) {
			$post_id = (int) wp_get_post_parent_id( $post_id );
			$type    = 'product';
		}
		if ( 'product' === $type && $post_id ) {
			Jobs::add( 'wc_product', array( 'product_id' => (int) $post_id ), 'product:' . $post_id, 10 );
		}
	}

	/**
	 * Stock changed.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	public static function product_object_changed( $product ) {
		if ( $product instanceof \WC_Product ) {
			self::product_changed( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() );
		}
	}

	/**
	 * The Products API body for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function product_payload( \WC_Product $product ) {
		$id          = $product->get_id();
		$description = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
		$description = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $description ) ) ) );
		$active      = 'publish' === get_post_status( $id ) && 'hidden' !== $product->get_catalog_visibility();
		$stock       = null;
		if ( $product->managing_stock() ) {
			$stock = max( 0, (int) $product->get_stock_quantity() );
		} elseif ( ! $product->is_in_stock() ) {
			$stock = 0;
		}
		$brands  = taxonomy_exists( 'product_brand' ) ? self::term_names( $id, 'product_brand' ) : array();
		$price   = $product->get_price();
		$regular = $product->is_type( 'variable' ) ? $product->get_variation_regular_price( 'min' ) : $product->get_regular_price();

		$payload = self::compact(
			array(
				'id'                 => (string) $id,
				'title'              => (string) $product->get_name(),
				'sku'                => (string) $product->get_sku(),
				'description'        => function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 1000 ) : substr( $description, 0, 1000 ),
				'url'                => (string) get_permalink( $id ),
				'image_url'          => $product->get_image_id() ? (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_single' ) : null,
				'price'              => '' !== (string) $price ? (float) $price : null,
				'compare_at_price'   => $product->is_on_sale() && '' !== (string) $regular ? (float) $regular : null,
				'currency'           => get_woocommerce_currency(),
				'categories'         => self::term_names( $id, 'product_cat' ),
				'brand'              => $brands ? $brands[0] : null,
				'inventory_quantity' => $stock,
				'status'             => $active ? 'active' : 'archived',
			)
		);
		/**
		 * Filters the product sent to Emailit Commerce.
		 *
		 * @param array<string, mixed> $payload Products API body.
		 * @param \WC_Product          $product Product.
		 */
		return apply_filters( 'emailit_woocommerce_product', $payload, $product );
	}

	/**
	 * Term names.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, string>
	 */
	private static function term_names( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( 'html_entity_decode', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * Job: send a product.
	 *
	 * @param array<string, mixed> $payload product_id.
	 * @return Send_Error|null
	 */
	public static function job_product( array $payload ) {
		if ( false === self::commerce_available() ) {
			return null;
		}
		$product = wc_get_product( (int) $payload['product_id'] );
		if ( ! $product instanceof \WC_Product ) {
			$response = Api::post(
				'products/' . (int) $payload['product_id'],
				array( 'status' => 'archived' )
			);
			return $response->ok() || 404 === $response->status ? null : Send_Error::from_api( $response );
		}
		$response = Api::post( 'products', self::product_payload( $product ) );
		if ( self::is_plan_error( $response ) ) {
			self::set_commerce( false, $response->message() );
			return null;
		}
		return Jobs::result( $response );
	}

	/**
	 * Job: send the whole catalog, 100 products per request.
	 *
	 * @param array<string, mixed> $payload page.
	 * @return Send_Error|null
	 */
	public static function job_products_sync( array $payload ) {
		$page     = max( 1, (int) $payload['page'] );
		$products = wc_get_products(
			array(
				'limit'   => 100,
				'page'    => $page,
				'status'  => array( 'publish', 'private' ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$batch    = array();
		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product && ! $product->is_type( 'variation' ) ) {
				$batch[] = self::product_payload( $product );
			}
		}
		if ( $batch ) {
			$response = Api::post( 'products/batch', array( 'products' => $batch ), array( 'timeout' => 60 ) );
			if ( ! $response->ok() ) {
				if ( self::is_plan_error( $response ) ) {
					self::set_commerce( false, $response->message() );
				}
				return Send_Error::from_api( $response );
			}
			self::set_commerce( true );
		}
		$progress = array(
			'done'     => ( $page - 1 ) * 100 + count( $products ),
			'finished' => count( $products ) < 100,
			'at'       => time(),
		);
		update_option( 'emailit_wc_products', $progress, false );
		if ( ! $progress['finished'] ) {
			Jobs::add( 'wc_products_sync', array( 'page' => $page + 1 ), 'products-sync' );
		}
		return null;
	}

	// Checkout.

	/**
	 * The newsletter checkbox on the classic checkout.
	 *
	 * @return void
	 */
	public static function classic_optin_field() {
		woocommerce_form_field(
			'emailit_optin',
			array(
				'type'  => 'checkbox',
				'class' => array( 'form-row-wide', 'emailit-optin' ),
				'label' => esc_html( self::optin_label() ),
			),
			0
		);
	}

	/**
	 * Classic checkout: save the opt-in and the cart ID on the order.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $data  Posted data.
	 * @return void
	 */
	public static function classic_checkout_meta( $order, $data ) {
		unset( $data );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this hook.
		$optin = ! empty( $_POST['emailit_optin'] );
		$order->update_meta_data( '_emailit_optin', $optin ? 'yes' : 'no' );
		self::stamp_cart( $order );
	}

	/**
	 * Block checkout: save the cart ID on the order. The opt-in is an additional checkout field WooCommerce saves itself.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public static function block_checkout_meta( $order, $request ) {
		unset( $request );
		self::stamp_cart( $order );
	}

	/**
	 * Link the order to the visitor's cart so Emailit marks the cart converted.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private static function stamp_cart( $order ) {
		$cart_id = self::cart_id();
		if ( '' !== $cart_id ) {
			$order->update_meta_data( '_emailit_cart_id', $cart_id );
			WC()->session->set( 'emailit_cart_converted', $cart_id );
		}
	}

	// Carts.

	/**
	 * The visitor's cart ID: WooCommerce's session customer ID.
	 *
	 * @return string
	 */
	public static function cart_id() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		$id = (string) WC()->session->get_customer_id();
		return '' !== $id ? 'wc_' . $id : '';
	}

	/**
	 * Mark the cart for a sync at the end of the request.
	 *
	 * @return void
	 */
	public static function cart_touched() {
		static $hooked = false;
		if ( ! $hooked ) {
			$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'sync_cart' ), 5 );
		}
	}

	/**
	 * Queue the cart when it changed and we know whose it is.
	 *
	 * @return void
	 */
	public static function sync_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}
		$cart_id = self::cart_id();
		$email   = '';
		if ( is_user_logged_in() ) {
			$email = (string) wp_get_current_user()->user_email;
		}
		if ( WC()->customer && is_email( WC()->customer->get_billing_email() ) ) {
			$email = (string) WC()->customer->get_billing_email();
		}
		if ( '' === $cart_id || ! is_email( $email ) ) {
			return;
		}

		$items    = array();
		$snapshot = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$parent     = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$quantity   = (int) $line['quantity'];
			$items[]    = self::compact(
				array(
					'product_id' => (string) $parent,
					'variant_id' => $product->get_parent_id() ? (string) $product->get_id() : null,
					'sku'        => (string) $product->get_sku(),
					'title'      => (string) $product->get_name(),
					'quantity'   => $quantity,
					'price'      => (float) wc_get_price_to_display( $product ),
					'url'        => (string) $product->get_permalink(),
					'image_url'  => $product->get_image_id() ? (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : null,
				)
			);
			$snapshot[] = array(
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) $line['variation_id'],
				'quantity'     => $quantity,
				'variation'    => isset( $line['variation'] ) ? (array) $line['variation'] : array(),
			);
		}

		// After checkout WooCommerce empties the cart; the order already converted it.
		if ( ! $items && WC()->session->get( 'emailit_cart_converted' ) === $cart_id ) {
			return;
		}

		$hash = md5( (string) wp_json_encode( array( $email, $items ) ) );
		if ( WC()->session->get( 'emailit_cart_hash' ) === $hash ) {
			return;
		}
		WC()->session->set( 'emailit_cart_hash', $hash );
		if ( $items ) {
			WC()->session->set( 'emailit_cart_converted', null );
			set_transient( 'emailit_cart_' . md5( $cart_id ), $snapshot, 14 * DAY_IN_SECONDS );
		}

		Jobs::add(
			'wc_cart',
			self::compact(
				array(
					'id'           => $cart_id,
					'email'        => $email,
					'currency'     => get_woocommerce_currency(),
					'total'        => (float) WC()->cart->get_total( 'edit' ),
					'items'        => $items,
					'checkout_url' => $items ? add_query_arg( self::CART_PARAM, rawurlencode( self::cart_token( $cart_id ) ), wc_get_cart_url() ) : null,
				)
			),
			'cart:' . $cart_id,
			30
		);
	}

	/**
	 * A signed token for the cart recovery link.
	 *
	 * @param string $cart_id Cart ID.
	 * @return string
	 */
	public static function cart_token( $cart_id ) {
		return $cart_id . '.' . substr( hash_hmac( 'sha256', $cart_id, wp_salt( 'nonce' ) . '|emailit-cart' ), 0, 20 );
	}

	/**
	 * Job: send a cart.
	 *
	 * @param array<string, mixed> $payload Carts API body.
	 * @return Send_Error|null
	 */
	public static function job_cart( array $payload ) {
		if ( false === self::commerce_available() ) {
			return null;
		}
		if ( ! isset( $payload['items'] ) ) {
			$payload['items'] = array();
		}
		$response = Api::post( 'carts', $payload );
		if ( self::is_plan_error( $response ) ) {
			self::set_commerce( false, $response->message() );
			return null;
		}
		return Jobs::result( $response );
	}

	/**
	 * Refill the cart from an abandoned cart email's link.
	 *
	 * @return void
	 */
	public static function restore_cart() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The link is signed with an HMAC instead of a nonce, which would expire.
		$token = isset( $_GET[ self::CART_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::CART_PARAM ] ) ) : '';
		if ( '' === $token || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cart_id = (string) strtok( $token, '.' );
		if ( ! hash_equals( self::cart_token( $cart_id ), $token ) ) {
			return;
		}
		$snapshot = get_transient( 'emailit_cart_' . md5( $cart_id ) );
		// Never replace a cart the visitor is filling right now with someone else's link.
		$own = self::cart_id() === $cart_id || WC()->cart->is_empty();
		if ( $own && is_array( $snapshot ) && $snapshot ) {
			WC()->cart->empty_cart();
			foreach ( $snapshot as $line ) {
				WC()->cart->add_to_cart( (int) $line['product_id'], max( 1, (int) $line['quantity'] ), (int) $line['variation_id'], (array) $line['variation'] );
			}
		}
		wp_safe_redirect( remove_query_arg( self::CART_PARAM ) );
		exit;
	}

	// Tracking script.

	/**
	 * Add the Emailit tracking script, record product views and identify signed-in customers.
	 *
	 * @return void
	 */
	public static function tracking_script() {
		$s   = self::settings();
		$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $s['tracking_key'] );
		if ( '' === $key ) {
			return;
		}
		wp_register_script( 'emailit-tracking', 'https://js.emailit.com/track/' . $key . '/events.js', array(), EMAILIT_VERSION, array( 'strategy' => 'async' ) );
		wp_add_inline_script( 'emailit-tracking', 'window.emailit=window.emailit||function(){(window._emailitq=window._emailitq||[]).push(arguments)};', 'before' );

		$calls = array();
		if ( ! empty( $s['identify'] ) && is_user_logged_in() ) {
			$calls[] = 'emailit("identify",' . wp_json_encode( array( 'email' => wp_get_current_user()->user_email ) ) . ');';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof \WC_Product ) {
				$data    = self::product_payload( $product );
				$calls[] = 'emailit("product",' . wp_json_encode( array_intersect_key( $data, array_flip( array( 'id', 'title', 'url', 'image_url', 'price', 'compare_at_price', 'currency', 'categories' ) ) ) ) . ');';
			}
		}
		if ( $calls ) {
			wp_add_inline_script( 'emailit-tracking', implode( "\n", $calls ), 'before' );
		}
		wp_enqueue_script( 'emailit-tracking' );
	}

	/**
	 * Create a tracking key for this site (Full Access key needed).
	 *
	 * @return string|\WP_Error The publishable key.
	 */
	public static function create_tracking_key() {
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$response = Api::post(
			'tracking-keys',
			array(
				/* translators: %s: site address. */
				'name'            => sprintf( __( 'WordPress: %s', 'emailit' ), $host ),
				'allowed_origins' => array( $host ),
			)
		);
		if ( ! $response->ok() || empty( $response->body['key'] ) ) {
			return new \WP_Error( 'emailit_tracking_key', $response->message() );
		}
		return (string) $response->body['key'];
	}
}
