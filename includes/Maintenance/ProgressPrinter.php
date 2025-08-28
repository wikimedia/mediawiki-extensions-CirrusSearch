<?php

namespace CirrusSearch\Maintenance;

/**
 * Simple trait to help printing progress in a maintenance script.
 */
trait ProgressPrinter {
	private int $lastProgressPrinted = 0;

	/**
	 * @param string $message
	 * @return void
	 */
	abstract public function outputIndented( $message );

	/**
	 * public because php 5.3 does not support accessing private
	 * methods in a closure.
	 * @param int $docsDumped
	 * @param int $limit
	 */
	public function outputProgress( int $docsDumped, int $limit ): void {
		if ( $docsDumped <= 0 ) {
			return;
		}
		$pctDone = (int)( ( $docsDumped / $limit ) * 100 );
		if ( $this->lastProgressPrinted == $pctDone ) {
			return;
		}
		$this->lastProgressPrinted = $pctDone;
		if ( ( $pctDone % 2 ) == 0 ) {
			$this->outputIndented( "    $pctDone% done...\n" );
		}
	}

}
