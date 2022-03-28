<?php

namespace CirrusSearch\Maintenance;

class NullPrinter implements Printer {
	public function output( $message, $channel = null ) {
	}

	public function outputIndented( $message ) {
	}

	public function error( $err, $die = 0 ) {
	}
}
