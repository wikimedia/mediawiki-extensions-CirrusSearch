<?php

namespace CirrusSearch\Maintenance;

use StatusValue;

interface Printer {
	/**
	 * @param string $message
	 * @param mixed|null $channel
	 */
	public function output( $message, $channel = null );

	/**
	 * @param string $message
	 */
	public function outputIndented( $message );

	/**
	 * @param string|StatusValue $err
	 * @param int $die
	 */
	public function error( $err, $die = 0 );
}
