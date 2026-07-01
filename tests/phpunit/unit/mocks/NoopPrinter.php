<?php

namespace CirrusSearch\Test;

use CirrusSearch\Maintenance\Printer;

class NoopPrinter implements Printer {
	/** @inheritDoc */
	public function output( $message, $channel = null ) {
	}

	/** @inheritDoc */
	public function outputIndented( $message ) {
	}

	/** @inheritDoc */
	public function error( $err ) {
		throw new \RuntimeException();
	}
}
