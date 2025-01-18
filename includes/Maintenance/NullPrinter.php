<?php

namespace CirrusSearch\Maintenance;

class NullPrinter implements Printer {
	/** @inheritDoc */
	public function output( $message, $channel = null ) {
	}

	/** @inheritDoc */
	public function outputIndented( $message ) {
	}

	/** @inheritDoc */
	public function error( $err, $die = 0 ) {
	}
}
