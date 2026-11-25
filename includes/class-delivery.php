<?php
/**
 * The outcome of one send attempt.
 *
 * @package Emailit
 */

namespace Emailit\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one send attempt.
 */
final class Delivery {

	/**
	 * Whether every recipient was accepted.
	 *
	 * @var bool
	 */
	public $ok = false;

	/**
	 * Emailit email ID (the first recipient's).
	 *
	 * @var string
	 */
	public $emailit_id = '';

	/**
	 * Emailit email IDs by recipient, when there were several.
	 *
	 * @var array<string, string>
	 */
	public $ids = array();

	/**
	 * What went wrong.
	 *
	 * @var Send_Error|null
	 */
	public $error = null;

	/**
	 * Recipients that were accepted (lowercase).
	 *
	 * @var array<int, string>
	 */
	public $sent = array();

	/**
	 * Recipients that weren't (lowercase).
	 *
	 * @var array<int, string>
	 */
	public $failed = array();

	/**
	 * Seconds to wait before retrying, set by the throttle after a failure.
	 *
	 * @var int
	 */
	public $retry_delay = 0;

	/**
	 * A successful delivery.
	 *
	 * @param array<int, string> $recipients Recipients.
	 * @param string             $emailit_id Emailit ID.
	 * @return self
	 */
	public static function success( array $recipients, $emailit_id = '' ) {
		$delivery             = new self();
		$delivery->ok         = true;
		$delivery->sent       = $recipients;
		$delivery->emailit_id = (string) $emailit_id;
		return $delivery;
	}

	/**
	 * A failed delivery.
	 *
	 * @param Send_Error         $error  Error.
	 * @param array<int, string> $failed Recipients that weren't accepted.
	 * @param array<int, string> $sent   Recipients that were.
	 * @return self
	 */
	public static function failure( Send_Error $error, array $failed, array $sent = array() ) {
		$delivery         = new self();
		$delivery->error  = $error;
		$delivery->failed = $failed;
		$delivery->sent   = $sent;
		return $delivery;
	}
}
