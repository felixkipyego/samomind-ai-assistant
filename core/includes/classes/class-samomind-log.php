<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Log
 *
 * A capped list of outbound requests: time, endpoint, HTTP status and
 * error code. Never a body, a header or the secret.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Log
 * @since       0.1.0
 */
class SAMOMIND_Log {

	/**
	 * Option holding the rows, newest first.
	 *
	 * @var string
	 */
	const OPTION = 'samomind_log';

	/**
	 * Rows kept.
	 *
	 * @var int
	 */
	const MAX_ROWS = 200;

	/**
	 * Add a row.
	 *
	 * @since   0.1.0
	 * @param   string $endpoint  For example "/documents".
	 * @param   int    $status    HTTP status, 0 for a network failure.
	 * @param   string $code      Error code, empty on success.
	 * @return  void
	 */
	public function add( $endpoint, $status, $code ) {
		$rows = $this->get_rows();
		array_unshift(
			$rows,
			array(
				'time'     => time(),
				'endpoint' => (string) $endpoint,
				'status'   => (int) $status,
				'code'     => (string) $code,
			)
		);
		update_option( self::OPTION, array_slice( $rows, 0, self::MAX_ROWS ), false );
	}

	/**
	 * Get all rows, newest first.
	 *
	 * @since   0.1.0
	 * @return  array
	 */
	public function get_rows() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove every row.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function clear() {
		update_option( self::OPTION, array(), false );
	}
}
