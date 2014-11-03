<?php

namespace CirrusSearch\Maintenance;

/**
 * Cirrus helpful extensions to Maintenance.
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
abstract class Maintenance extends \Maintenance {
	// How much should this script indent output?
	protected static $indent = null;

	/**
	 * Execute a callback function at the end of initialisation
	 */
	public function loadSpecialVars() {
		parent::loadSpecialVars();
		if ( Maintenance::$indent === null ) {
			// First script gets no indentation
			Maintenance::$indent = '';
		} else {
			// Others get one tab beyond the last
			Maintenance::$indent = Maintenance::$indent . "\t";
		}
	}

	/**
	 * Call to signal that execution of this maintenance script is complete so
	 * the next one gets the right indentation.
	 */
	public function done() {
		Maintenance::$indent = substr( Maintenance::$indent, 1 );
	}

	public function output( $message, $channel = null ) {
		parent::output( $message );
	}

	public function outputIndented( $message ) {
		$this->output( Maintenance::$indent . $message );
	}

	public function error( $err, $die = 0 ) {
		parent::error( $err, $die );
	}
}
