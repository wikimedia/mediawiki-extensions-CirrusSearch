<?php

namespace CirrusSearch\Maintenance;

interface Printer {
	function output( $message, $channel = null );
	function outputIndented( $message );
	function error( $err, $die = 0 );
}
