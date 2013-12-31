<?php

namespace CirrusSearch;
use \ForkController;

/**
 * Extensions to ForeController to prepare Elastica and to tell the child
 * process which one it is.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class ReindexForkController extends ForkController {
	/**
	 * @var integer number of this child or null if this is the parent
	 */
	var $childNumber;
	/**
	 * Fork a number of worker processes.  Have to hack ForkController to store
	 * the child number.
	 *
	 * @return string
	 */
	protected function forkWorkers( $numProcs ) {
		$this->prepareEnvironment();

		// Create the child processes
		for ( $i = 0; $i < $numProcs; $i++ ) {
			// Do the fork
			$pid = pcntl_fork();
			if ( $pid === -1 || $pid === false ) {
				echo "Error creating child processes\n";
				exit( 1 );
			}

			if ( !$pid ) {
				$this->initChild();
				$this->childNumber = $i; // Hack right here.
				return 'child';
			} else {
				// This is the parent process
				$this->children[$pid] = true;
			}
		}

		return 'parent';
	}

	protected function prepareEnvironment() {
		parent::prepareEnvironment();
		Connection::destroySingleton();
	}
}
