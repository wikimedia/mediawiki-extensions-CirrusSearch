<?php

namespace CirrusSearch;
use \GenderCache;
use \MWNamespace;
use \Title;
use \User;

/**
 * Random utility functions that don't have a better home
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
class Util {
	/**
	 * Get the textual representation of a namespace with underscores stripped, varying
	 * by gender if need be.
	 *
	 * @param Title $title The page title to use
	 * @return string
	 */
	public static function getNamespaceText( Title $title ) {
		global $wgContLang;

		$ns = $title->getNamespace();

		// If we're in NS_USER(_TALK) and we're in a gender-distinct language
		// then vary the namespace on gender like we should.
		$nsText = '';
		if ( MWNamespace::hasGenderDistinction( $ns ) && $wgContLang->needsGenderDistinction() ) {
			$nsText = $wgContLang->getGenderNsText( $ns,
				GenderCache::singleton()->getGenderOf(
					User::newFromName( $title->getText() ),
					__METHOD__
				)
			);
		} elseif ( $nsText !== NS_MAIN ) {
			$nsText = $wgContLang->getNsText( $ns );
		}

		return strtr( $nsText, '_', ' ' );
	}

	/**
	 * Check if too arrays are recursively the same.  Values are compared with != and arrays
	 * are descended into.
	 * @param array $lhs one array
	 * @param array $rhs the other array
	 * @return are they equal
	 */
	public static function recursiveSame( $lhs, $rhs ) {
		if ( array_keys( $lhs ) != array_keys( $rhs ) ) {
			return false;
		}
		foreach ( $lhs as $key => $value ) {
			if ( !isset( $rhs[ $key ] ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( !is_array( $rhs[ $key ] ) ) {
					return false;
				}
				if ( !self::recursiveSame( $value, $rhs[ $key ] ) ) {
					return false;
				}
			} else {
				if ( $value != $rhs[ $key ] ) {
					return false;
				}
			}
		}
		return true;
	}
}
