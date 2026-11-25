<?php
/**
 * Emailit API client.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to https://api.emailit.com/v2 with the site's API key.
 */
final class Api {

	/**
	 * Send a request.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path below /v2/, e.g. 'emails'.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param array<string, mixed>      $options timeout, idempotency_key, api_key.
	 * @return Api_Response
	 */
	public static function request( $method, $path, $body = null, array $options = array() ) {
		$key     = isset( $options['api_key'] ) ? (string) $options['api_key'] : Settings::api_key();
		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'emailit-wordpress/' . EMAILIT_VERSION . ' WordPress/' . get_bloginfo( 'version' ) . ' PHP/' . PHP_VERSION,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( ! empty( $options['idempotency_key'] ) ) {
			$headers['Idempotency-Key'] = (string) $options['idempotency_key'];
		}

		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => isset( $options['timeout'] ) ? (int) $options['timeout'] : 15,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES );
		}

		$url = Settings::api_base() . ltrim( $path, '/' );
		if ( ! empty( $options['query'] ) && is_array( $options['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $options['query'] ), $url );
		}

		/**
		 * Filters the arguments of a request to the Emailit API.
		 *
		 * @param array<string, mixed> $args wp_remote_request() arguments.
		 * @param string               $url  Request URL.
		 */
		$args = apply_filters( 'emailit_api_request_args', $args, $url );

		$raw      = wp_remote_request( $url, $args );
		$response = new Api_Response();
		if ( is_wp_error( $raw ) ) {
			$response->error = $raw;
			return $response;
		}

		$response->status = (int) wp_remote_retrieve_response_code( $raw );
		$decoded          = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		$response->body   = is_array( $decoded ) ? $decoded : array();
		$headers          = wp_remote_retrieve_headers( $raw );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		foreach ( (array) $headers as $name => $value ) {
			$response->headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return $response;
	}

	/**
	 * GET.
	 *
	 * @param string               $path    Path.
	 * @param array<string, mixed> $query   Query arguments.
	 * @param array<string, mixed> $options Options.
	 * @return Api_Response
	 */
	public static function get( $path, array $query = array(), array $options = array() ) {
		$options['query'] = $query;
		return self::request( 'GET', $path, null, $options );
	}

	/**
	 * POST.
	 *
	 * @param string               $path    Path.
	 * @param array<string, mixed> $body    JSON body.
	 * @param array<string, mixed> $options Options.
	 * @return Api_Response
	 */
	public static function post( $path, array $body, array $options = array() ) {
		return self::request( 'POST', $path, $body, $options );
	}

	/**
	 * DELETE.
	 *
	 * @param string               $path    Path.
	 * @param array<string, mixed> $options Options.
	 * @return Api_Response
	 */
	public static function delete( $path, array $options = array() ) {
		return self::request( 'DELETE', $path, null, $options );
	}

	/**
	 * Every item of a paginated list, up to a limit.
	 *
	 * @param string $path Path.
	 * @param int    $max  Most items to fetch.
	 * @return array<int, array<string, mixed>>|null Null when the request failed.
	 */
	public static function all( $path, $max = 500 ) {
		$items = array();
		$page  = 1;
		do {
			$response = self::get(
				$path,
				array(
					'page'  => $page,
					'limit' => 100,
				)
			);
			if ( ! $response->ok() ) {
				return 1 === $page ? null : $items;
			}
			$data  = isset( $response->body['data'] ) && is_array( $response->body['data'] ) ? $response->body['data'] : array();
			$items = array_merge( $items, $data );
			$more  = ! empty( $response->body['next_page_url'] ) && $data && count( $items ) < $max;
			++$page;
		} while ( $more );
		return $items;
	}

	/**
	 * What the API key can do, cached for 12 hours.
	 *
	 * Full access keys can read domains; Sending Only keys get 403. A 401 means
	 * the key is wrong.
	 *
	 * @param bool $refresh Check again now.
	 * @return array{valid: bool|null, full: bool, domains: array<int, string>, checked_at: int, error: string}
	 */
	public static function account( $refresh = false ) {
		$cached = get_option( 'emailit_account' );
		if ( ! $refresh && is_array( $cached ) && isset( $cached['checked_at'] ) && $cached['checked_at'] > time() - 12 * HOUR_IN_SECONDS ) {
			return $cached;
		}
		$info = array(
			'valid'      => null,
			'full'       => false,
			'domains'    => array(),
			'checked_at' => time(),
			'error'      => '',
		);
		if ( ! Settings::is_configured() ) {
			return $info;
		}
		$response = self::get( 'domains', array( 'limit' => 100 ), array( 'timeout' => 10 ) );
		if ( $response->ok() ) {
			$info['valid'] = true;
			$info['full']  = true;
			foreach ( isset( $response->body['data'] ) ? (array) $response->body['data'] : array() as $domain ) {
				if ( ! empty( $domain['name'] ) && ! empty( $domain['verified_at'] ) ) {
					$info['domains'][] = strtolower( (string) $domain['name'] );
				}
			}
		} elseif ( 403 === $response->status ) {
			$info['valid'] = true;
		} elseif ( 401 === $response->status ) {
			$info['valid'] = false;
			$info['error'] = $response->message();
		} else {
			$info['error'] = $response->message();
			$info['valid'] = null;
		}
		update_option( 'emailit_account', $info, false );
		return $info;
	}
}
