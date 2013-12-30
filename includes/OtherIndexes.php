<?php

namespace CirrusSearch;
use \Title;

/**
 * Tracks whether a Title is known on other indexes.
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
class OtherIndexes {
	/**
	 * Get the external index identifiers for title.
	 * @param $title Title
	 * @return array(string) of index identifiers.  empty means none.
	 */
	public static function getExternalIndexes( Title $title ) {
		global $wgCirrusSearchExtraIndexes;
		$ns = $title->getNamespace();
		return isset( $wgCirrusSearchExtraIndexes[ $ns ] )
			? $wgCirrusSearchExtraIndexes[ $ns ] : array();
	}

	/**
	 * Get any extra indexes to query, if any, based on namespaces
	 * @param array $namespaces An array of namespace ids
	 * @return array of indexes
	 */
	public static function getExtraIndexesForNamespaces( $namespaces ) {
		global $wgCirrusSearchExtraIndexes;
		$extraIndexes = array();
		if ( $wgCirrusSearchExtraIndexes ) {
			foreach( $wgCirrusSearchExtraIndexes as $ns => $indexes ) {
				if ( in_array( $ns, $namespaces ) ) {
					$extraIndexes = array_merge( $extraIndexes, $indexes );
				}
			}
		}
		return $extraIndexes;
	}
}
